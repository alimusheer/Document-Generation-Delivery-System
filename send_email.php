<?php
// --- HTTPS DETECTION ---
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

// --- SECURITY HEADERS ---
// Cloudflare Turnstile (Issue #8) requires script-src and frame-src to allow
// https://challenges.cloudflare.com for widget rendering and the challenge iframe.
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://challenges.cloudflare.com; style-src 'self' 'unsafe-inline'; frame-src https://challenges.cloudflare.com; object-src 'none'; base-uri 'self'; form-action 'self'");
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// --- SESSION ---
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure'   => $isHttps,
    'use_strict_mode' => true,
]);

require_once __DIR__ . '/src/Support/paths.php';
require_once __DIR__ . '/src/Logging/logger.php';

// --- SETUP & AUTOLOADING ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader for PHPMailer and mPDF
require 'vendor/autoload.php';


function get_client_ip_address(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function enforce_rate_limit(string $clientIp): array {
    $allowResult = [
        'allowed' => true,
        'triggered_limit' => null,
        'retry_after' => 0,
    ];

    $storageDir = dirname(RATE_LIMIT_FILE);
    if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
        write_log('RATE_LIMIT_ERROR', 'Storage directory unavailable: ' . $storageDir . ' | IP: ' . $clientIp);
        return $allowResult;
    }

    $handle = @fopen(RATE_LIMIT_FILE, 'c+');
    if ($handle === false) {
        write_log('RATE_LIMIT_ERROR', 'Unable to open rate limit file: ' . RATE_LIMIT_FILE . ' | IP: ' . $clientIp);
        return $allowResult;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        write_log('RATE_LIMIT_ERROR', 'Unable to acquire lock for rate limit file | IP: ' . $clientIp);
        return $allowResult;
    }

    try {
        rewind($handle);
        $rawJson = stream_get_contents($handle);
        if ($rawJson === false) {
            write_log('RATE_LIMIT_ERROR', 'Unable to read rate limit file | IP: ' . $clientIp);
            return $allowResult;
        }

        $rateData = [];
        if (trim($rawJson) !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                $rateData = $decoded;
            } else {
                write_log('RATE_LIMIT_ERROR', 'Invalid JSON in rate limit file. Resetting structure.');
            }
        }

        $now = time();
        $minuteCutoff = $now - 60;
        $hourCutoff = $now - 3600;

        foreach ($rateData as $ip => $entry) {
            if (!is_array($entry)) {
                unset($rateData[$ip]);
                continue;
            }

            $minuteEntries = $entry['minute'] ?? [];
            $hourEntries = $entry['hour'] ?? [];

            if (!is_array($minuteEntries)) {
                $minuteEntries = [];
            }
            if (!is_array($hourEntries)) {
                $hourEntries = [];
            }

            $minuteEntries = array_values(array_filter($minuteEntries, static function ($timestamp) use ($minuteCutoff): bool {
                return is_numeric($timestamp) && (int) $timestamp >= $minuteCutoff;
            }));
            $hourEntries = array_values(array_filter($hourEntries, static function ($timestamp) use ($hourCutoff): bool {
                return is_numeric($timestamp) && (int) $timestamp >= $hourCutoff;
            }));

            $minuteEntries = array_map('intval', $minuteEntries);
            $hourEntries = array_map('intval', $hourEntries);

            if ($minuteEntries === [] && $hourEntries === []) {
                unset($rateData[$ip]);
                continue;
            }

            $rateData[$ip] = [
                'minute' => $minuteEntries,
                'hour' => $hourEntries,
            ];
        }

        if (!isset($rateData[$clientIp])) {
            $rateData[$clientIp] = [
                'minute' => [],
                'hour' => [],
            ];
        }

        $currentMinute = $rateData[$clientIp]['minute'];
        $currentHour = $rateData[$clientIp]['hour'];

        $isBlocked = false;
        $triggeredLimit = null;
        $retryAfter = 0;

        if (count($currentMinute) >= RATE_LIMIT_PER_MINUTE) {
            $isBlocked = true;
            $triggeredLimit = 'minute';
            $retryAfter = 60;
        } elseif (count($currentHour) >= RATE_LIMIT_PER_HOUR) {
            $isBlocked = true;
            $triggeredLimit = 'hour';
            $retryAfter = 3600;
        } else {
            $rateData[$clientIp]['minute'][] = $now;
            $rateData[$clientIp]['hour'][] = $now;
        }

        $encoded = json_encode($rateData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            write_log('RATE_LIMIT_ERROR', 'Unable to encode rate limit JSON | IP: ' . $clientIp);
            return $allowResult;
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            write_log('RATE_LIMIT_ERROR', 'Unable to truncate rate limit file | IP: ' . $clientIp);
            return $allowResult;
        }

        if (fwrite($handle, $encoded) === false) {
            write_log('RATE_LIMIT_ERROR', 'Unable to write rate limit file | IP: ' . $clientIp);
            return $allowResult;
        }
        fflush($handle);

        return [
            'allowed' => !$isBlocked,
            'triggered_limit' => $triggeredLimit,
            'retry_after' => $retryAfter,
        ];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function check_smtp_quota(string $ip): array {
    // Fail-closed default: any storage failure blocks sending to protect SMTP reputation.
    $blockResult = [
        'allowed' => false,
        'quota_type' => 'error',
        'retry_after' => SMTP_QUOTA_WINDOW_SECONDS,
    ];

    $storageDir = dirname(SMTP_QUOTA_FILE);
    if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
        write_log('SMTP_QUOTA_ERROR', 'Storage directory unavailable: ' . $storageDir . ' | IP: ' . $ip);
        return $blockResult;
    }

    $handle = @fopen(SMTP_QUOTA_FILE, 'c+');
    if ($handle === false) {
        write_log('SMTP_QUOTA_ERROR', 'Unable to open quota file: ' . SMTP_QUOTA_FILE . ' | IP: ' . $ip);
        return $blockResult;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        write_log('SMTP_QUOTA_ERROR', 'Unable to acquire lock for quota file | IP: ' . $ip);
        return $blockResult;
    }

    try {
        rewind($handle);
        $rawJson = stream_get_contents($handle);
        if ($rawJson === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to read quota file | IP: ' . $ip);
            return $blockResult;
        }

        $globalEntries = [];
        $perIpEntries = [];
        if (trim($rawJson) !== '') {
            $decoded = json_decode($rawJson, true);
            if (!is_array($decoded)) {
                write_log('SMTP_QUOTA_ERROR', 'Invalid JSON in quota file. Blocking send (fail-closed) | IP: ' . $ip);
                return $blockResult;
            }
            $globalEntries = $decoded['global'] ?? [];
            $perIpEntries = $decoded['per_ip'] ?? [];
            if (!is_array($globalEntries) || !is_array($perIpEntries)) {
                write_log('SMTP_QUOTA_ERROR', 'Invalid quota structure. Blocking send (fail-closed) | IP: ' . $ip);
                return $blockResult;
            }
        }

        $now = time();
        $cutoff = $now - SMTP_QUOTA_WINDOW_SECONDS;

        // Prune expired entries and persist the cleaned structure while the lock is held.
        $cleanGlobal = array_map('intval', array_values(array_filter($globalEntries, static function ($timestamp) use ($cutoff): bool {
            return is_numeric($timestamp) && (int) $timestamp >= $cutoff;
        })));

        $cleanPerIp = [];
        foreach ($perIpEntries as $existingIp => $timestamps) {
            if (!is_array($timestamps)) {
                continue;
            }
            $filtered = array_values(array_filter($timestamps, static function ($timestamp) use ($cutoff): bool {
                return is_numeric($timestamp) && (int) $timestamp >= $cutoff;
            }));
            if ($filtered === []) {
                continue;
            }
            $cleanPerIp[$existingIp] = array_map('intval', $filtered);
        }

        $cleanedData = ['global' => $cleanGlobal, 'per_ip' => $cleanPerIp];
        $encoded = json_encode($cleanedData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to encode pruned quota JSON. Blocking send (fail-closed) | IP: ' . $ip);
            return $blockResult;
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to truncate quota file during prune. Blocking send (fail-closed) | IP: ' . $ip);
            return $blockResult;
        }
        if (fwrite($handle, $encoded) === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to write pruned quota file. Blocking send (fail-closed) | IP: ' . $ip);
            return $blockResult;
        }
        fflush($handle);

        // Continue quota evaluation using the cleaned data.
        $globalCount = count($cleanGlobal);
        $perIpCount = isset($cleanPerIp[$ip]) ? count($cleanPerIp[$ip]) : 0;

        if ($globalCount >= SMTP_QUOTA_GLOBAL_PER_DAY) {
            return ['allowed' => false, 'quota_type' => 'global', 'retry_after' => SMTP_QUOTA_WINDOW_SECONDS];
        }
        if ($perIpCount >= SMTP_QUOTA_PER_IP_PER_DAY) {
            return ['allowed' => false, 'quota_type' => 'per_ip', 'retry_after' => SMTP_QUOTA_WINDOW_SECONDS];
        }

        return ['allowed' => true, 'quota_type' => null, 'retry_after' => 0];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function record_smtp_success(string $ip): bool {
    $storageDir = dirname(SMTP_QUOTA_FILE);
    if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
        write_log('SMTP_QUOTA_ERROR', 'Storage directory unavailable during record: ' . $storageDir . ' | IP: ' . $ip);
        return false;
    }

    $handle = @fopen(SMTP_QUOTA_FILE, 'c+');
    if ($handle === false) {
        write_log('SMTP_QUOTA_ERROR', 'Unable to open quota file during record | IP: ' . $ip);
        return false;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        write_log('SMTP_QUOTA_ERROR', 'Unable to acquire lock during record | IP: ' . $ip);
        return false;
    }

    try {
        rewind($handle);
        $rawJson = stream_get_contents($handle);
        if ($rawJson === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to read quota file during record | IP: ' . $ip);
            return false;
        }

        $quotaData = ['global' => [], 'per_ip' => []];
        if (trim($rawJson) !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                $globalEntries = $decoded['global'] ?? [];
                $perIpEntries = $decoded['per_ip'] ?? [];
                if (is_array($globalEntries)) {
                    $quotaData['global'] = $globalEntries;
                }
                if (is_array($perIpEntries)) {
                    $quotaData['per_ip'] = $perIpEntries;
                }
            } else {
                write_log('SMTP_QUOTA_ERROR', 'Invalid JSON during record. Resetting quota structure | IP: ' . $ip);
            }
        }

        $now = time();
        $cutoff = $now - SMTP_QUOTA_WINDOW_SECONDS;

        $quotaData['global'] = array_map('intval', array_values(array_filter($quotaData['global'], static function ($timestamp) use ($cutoff): bool {
            return is_numeric($timestamp) && (int) $timestamp >= $cutoff;
        })));

        foreach ($quotaData['per_ip'] as $existingIp => $timestamps) {
            if (!is_array($timestamps)) {
                unset($quotaData['per_ip'][$existingIp]);
                continue;
            }
            $filtered = array_values(array_filter($timestamps, static function ($timestamp) use ($cutoff): bool {
                return is_numeric($timestamp) && (int) $timestamp >= $cutoff;
            }));
            if ($filtered === []) {
                unset($quotaData['per_ip'][$existingIp]);
                continue;
            }
            $quotaData['per_ip'][$existingIp] = array_map('intval', $filtered);
        }

        $quotaData['global'][] = $now;
        if (!isset($quotaData['per_ip'][$ip]) || !is_array($quotaData['per_ip'][$ip])) {
            $quotaData['per_ip'][$ip] = [];
        }
        $quotaData['per_ip'][$ip][] = $now;

        $encoded = json_encode($quotaData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to encode quota JSON during record | IP: ' . $ip);
            return false;
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to truncate quota file during record | IP: ' . $ip);
            return false;
        }
        if (fwrite($handle, $encoded) === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to write quota file during record | IP: ' . $ip);
            return false;
        }
        fflush($handle);

        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

// --- ISSUE #8 HELPER: CLOUDFLARE TURNSTILE VERIFICATION ---
// Verifies the Turnstile token server-side via cURL. Fail-closed: any missing
// token, rejected verification, or transport failure blocks the request.
function verify_turnstile(string $token, string $clientIp): array {
    $blockResult = ['allowed' => false];

    if ($token === '') {
        write_log('TURNSTILE_VERIFY_FAIL', 'Missing Turnstile token | IP: ' . $clientIp);
        return $blockResult;
    }

    $secret = $_ENV['TURNSTILE_SECRET_KEY'] ?? '';
    if ($secret === '') {
        write_log('TURNSTILE_VERIFY_ERROR', 'Missing Turnstile secret key in configuration | IP: ' . $clientIp);
        return $blockResult;
    }

    if (!function_exists('curl_init')) {
        write_log('TURNSTILE_VERIFY_ERROR', 'cURL extension unavailable for Turnstile verification | IP: ' . $clientIp);
        return $blockResult;
    }

    $postFields = http_build_query([
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => $clientIp,
    ]);

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    if ($ch === false) {
        write_log('TURNSTILE_VERIFY_ERROR', 'Unable to initialize cURL for Turnstile verification | IP: ' . $clientIp);
        return $blockResult;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        write_log('TURNSTILE_VERIFY_ERROR', 'Transport failure during Turnstile verification: ' . curl_error($ch) . ' | IP: ' . $clientIp);
        curl_close($ch);
        return $blockResult;
    }

    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        write_log('TURNSTILE_VERIFY_ERROR', 'Unexpected HTTP status from Turnstile verification: ' . $httpCode . ' | IP: ' . $clientIp);
        return $blockResult;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        write_log('TURNSTILE_VERIFY_ERROR', 'Invalid JSON response from Turnstile verification | IP: ' . $clientIp);
        return $blockResult;
    }

    if (($decoded['success'] ?? null) !== true) {
        $errorCodes = isset($decoded['error-codes']) && is_array($decoded['error-codes'])
            ? implode(',', $decoded['error-codes'])
            : 'none';
        write_log('TURNSTILE_VERIFY_FAIL', 'Turnstile verification rejected | codes: ' . $errorCodes . ' | IP: ' . $clientIp);
        return $blockResult;
    }

    return ['allowed' => true];
}

// --- ISSUE #10 HELPERS: USER-FRIENDLY ERROR MESSAGES ---
// Record a validation failure in both the grouped summary and the field map.
function add_field_error(array &$fieldErrors, array &$summaryErrors, string $field, string $message): void {
    $fieldErrors[$field][] = $message;
    $summaryErrors[] = $message;
}

// Return the CSS class fragment for an invalid input.
function field_error_class(array $fieldErrors, string $field): string {
    return isset($fieldErrors[$field]) ? ' input-error' : '';
}

// Return escaped inline error text for a field (empty string when valid).
function render_field_error(array $fieldErrors, string $field): string {
    if (empty($fieldErrors[$field])) {
        return '';
    }
    $html = '';
    foreach ($fieldErrors[$field] as $message) {
        $html .= '<div class="field-error-text">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return $html;
}

// This variable will hold the HTML for the success/error message
$outputMessage = '';

// --- ISSUE #10 UI STATE ---
// $uiState drives rendering: 'form' | 'validation_error' | 'operational_error' | 'success'.
$uiState       = 'form';
$summaryErrors = [];
$fieldErrors   = [];

$weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Default form values. Day fields are generated dynamically to avoid manual duplication.
$defaultIntro = 'Here is the personalized fitness plan we discussed. We have designed this schedule to align perfectly with your goals. Consistency is key, and we are here to support you every step of the way.';
$formData = [
    'recipient_name'  => '',
    'recipient_email' => '',
    'intro_message'   => $defaultIntro,
];
foreach ($weekDays as $weekDay) {
    $weekDayKey = strtolower($weekDay);
    $formData[$weekDayKey . '_focus']   = '';
    $formData[$weekDayKey . '_details'] = '';
}

// Load environment variables from .env
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    $dotenv->required(['MAIL_HOST', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_PORT', 'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET_KEY']);
} catch (\Dotenv\Exception\InvalidPathException | \Dotenv\Exception\ValidationException $e) {
    write_log('CONFIG', $e->getMessage());
    $uiState = 'operational_error';
    $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Application Configuration Error</h2>
                      <p style='color: #FF6B6B; text-align: center;'>The application is not configured correctly. Please contact the administrator.</p>";
}

// Generate a CSRF token for this session if one does not exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- PROCESS THE FORM WHEN SUBMITTED ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- CSRF Validation ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        $uiState = 'operational_error';
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Security Validation Failed</h2>
                          <p style='color: #FF6B6B; text-align: center;'>Your request could not be verified. Please refresh the page and try again.</p>";
    } else {

    // Consume the used token and immediately issue a fresh one
    unset($_SESSION['csrf_token']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $clientIp = get_client_ip_address();
    $rateLimit = enforce_rate_limit($clientIp);
    if (!$rateLimit['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . (string) $rateLimit['retry_after']);
        write_log(
            'RATE_LIMIT',
            'Blocked request | IP: ' . $clientIp
            . ' | limit: ' . $rateLimit['triggered_limit']
            . ' | timestamp: ' . date('Y-m-d H:i:s')
            . ' | session_id: ' . session_id()
        );

        $uiState = 'operational_error';
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Too Many Requests</h2>
                          <p style='color: #FF6B6B; text-align: center;'>You have submitted too many requests. Please wait and try again.</p>";
    } else {

    // --- Turnstile Verification (fail-closed) ---
    $turnstileToken  = isset($_POST['cf-turnstile-response']) && is_string($_POST['cf-turnstile-response'])
        ? trim($_POST['cf-turnstile-response'])
        : '';
    $turnstileResult = verify_turnstile($turnstileToken, $clientIp);
    if (!$turnstileResult['allowed']) {
        http_response_code(403);
        $uiState = 'operational_error';
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Security Verification Failed</h2>
                          <p style='color: #FF6B6B; text-align: center;'>We could not verify that you are human. Please refresh the page and try again.</p>";
    } else {

    // --- 1. Trim Raw Inputs ---
    $rawName    = trim($_POST['recipient_name']  ?? '');
    $rawEmail   = trim($_POST['recipient_email'] ?? '');
    $rawIntro   = trim($_POST['intro_message']   ?? '');
    $days       = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $rawPlan    = [];
    foreach ($days as $day) {
        $key = strtolower($day);
        $rawPlan[$day] = [
            'focus'   => trim($_POST[$key . '_focus']   ?? ''),
            'details' => trim($_POST[$key . '_details'] ?? ''),
        ];
    }

    // Repopulate the form with submitted values (used when validation fails).
    $formData['recipient_name']  = $rawName;
    $formData['recipient_email'] = $rawEmail;
    $formData['intro_message']   = $rawIntro;
    foreach ($days as $day) {
        $key = strtolower($day);
        $formData[$key . '_focus']   = $rawPlan[$day]['focus'];
        $formData[$key . '_details'] = $rawPlan[$day]['details'];
    }

    // --- 2. Validate Inputs ---
    // recipient_name
    if ($rawName === '') {
        add_field_error($fieldErrors, $summaryErrors, 'recipient_name', 'Client name is required.');
    } elseif (mb_strlen($rawName) < 2) {
        add_field_error($fieldErrors, $summaryErrors, 'recipient_name', 'Client name must be at least 2 characters.');
    } elseif (mb_strlen($rawName) > 100) {
        add_field_error($fieldErrors, $summaryErrors, 'recipient_name', 'Client name must not exceed 100 characters.');
    } elseif (preg_match('/[\/\\\:*?"<>|]/', $rawName)) {
        add_field_error($fieldErrors, $summaryErrors, 'recipient_name', 'Client name contains invalid characters.');
    }

    // recipient_email
    if ($rawEmail === '') {
        add_field_error($fieldErrors, $summaryErrors, 'recipient_email', 'Email address is required.');
    } elseif (mb_strlen($rawEmail) > 254) {
        add_field_error($fieldErrors, $summaryErrors, 'recipient_email', 'Email address must not exceed 254 characters.');
    } elseif (!filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
        add_field_error($fieldErrors, $summaryErrors, 'recipient_email', 'A valid email address is required.');
    }

    // intro_message
    if ($rawIntro === '') {
        add_field_error($fieldErrors, $summaryErrors, 'intro_message', 'Introductory message is required.');
    } elseif (mb_strlen($rawIntro) < 10) {
        add_field_error($fieldErrors, $summaryErrors, 'intro_message', 'Introductory message must be at least 10 characters.');
    } elseif (mb_strlen($rawIntro) > 2000) {
        add_field_error($fieldErrors, $summaryErrors, 'intro_message', 'Introductory message must not exceed 2000 characters.');
    }

    // day_focus and day_details (optional, length limits only)
    foreach ($days as $day) {
        $key = strtolower($day);
        if (mb_strlen($rawPlan[$day]['focus']) > 150) {
            add_field_error($fieldErrors, $summaryErrors, $key . '_focus', $day . ' workout focus must not exceed 150 characters.');
        }
        if (mb_strlen($rawPlan[$day]['details']) > 500) {
            add_field_error($fieldErrors, $summaryErrors, $key . '_details', $day . ' details must not exceed 500 characters.');
        }
    }

    // Business rule: at least one day must have a focus or details entry
    $hasAnyPlan = false;
    foreach ($days as $day) {
        if ($rawPlan[$day]['focus'] !== '' || $rawPlan[$day]['details'] !== '') {
            $hasAnyPlan = true;
            break;
        }
    }
    if (!$hasAnyPlan) {
        add_field_error($fieldErrors, $summaryErrors, 'plan', 'At least one day must have a workout focus or details.');
    }

    // --- 3. Stop and display errors if validation failed ---
    if (!empty($summaryErrors)) {
        $uiState = 'validation_error';
    } else {

    // --- 4. Sanitize and Capture Form Data ---
    $recipientName = htmlspecialchars($_POST["recipient_name"]);
    $recipientEmail = filter_var($_POST["recipient_email"], FILTER_SANITIZE_EMAIL);
    $introMessage = nl2br(htmlspecialchars($_POST["intro_message"])); // Convert newlines to <br> for HTML
    $planData = [];

    // Loop through each day of the week to get the plan
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    foreach ($days as $day) {
        $planData[$day] = [
            'focus' => htmlspecialchars($_POST[strtolower($day) . '_focus'] ?? ''),
            'details' => htmlspecialchars($_POST[strtolower($day) . '_details'] ?? '')
        ];
    }

    // --- 2. Build the Premium HTML for the PDF ---
    // This is the HTML structure from your premium email design
    $htmlForPdf = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: 'Segoe UI', sans-serif; background-color: #0a0e27; color: #e8dcc8; padding: 20px; }
            .email-container { background: #1a1f3a; padding: 40px; border-radius: 8px; max-width: 650px; margin: auto; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid rgba(212, 175, 55, 0.3); padding-bottom: 20px; }
            .badge { display: inline-block; background: #c9a961; color: #0a0e27; padding: 8px 18px; border-radius: 20px; font-size: 10px; font-weight: bold; letter-spacing: 2px; margin-bottom: 12px; text-transform: uppercase; }
            h2 { color: #d4af37; font-size: 38px; font-weight: 300; letter-spacing: 3px; margin: 0; }
            p { font-size: 14px; color: #b8a89f; line-height: 1.6; }
            h3 { color: #e8dcc8; font-size: 16px; margin-bottom: 5px; }
            table { width: 100%; border-collapse: collapse; margin: 30px 0; }
            th, td { padding: 14px 12px; text-align: left; }
            th { background: rgba(212, 175, 55, 0.1); border-bottom: 2px solid rgba(212, 175, 55, 0.3); color: #c9a961; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
            td { border-bottom: 1px solid rgba(212, 175, 55, 0.1); }
            .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(212, 175, 55, 0.15); font-size: 11px; color: #6b5d4f; letter-spacing: 1px; }
        </style>
    </head>
    <body>
        <div class='email-container'>
            <div class='header'>
                <div class='badge'>✦ PREMIUM SERVICE ✦</div>
                <h2>ELITE PLAN</h2>
            </div>
            <h3>Hello {$recipientName},</h3>
            <p>{$introMessage}</p>
            <table>
                <tr><th>Day</th><th>Workout Focus</th><th>Details / Exercises</th></tr>";
    
    // Add the weekly plan to the table
    foreach ($days as $day) {
        $htmlForPdf .= "<tr>
            <td>{$day}</td>
            <td>{$planData[$day]['focus']}</td>
            <td>{$planData[$day]['details']}</td>
        </tr>";
    }

    $htmlForPdf .= "
            </table>
            <p style='text-align:center; font-style:italic;'>Consistency is key. Execute with precision and witness transformation.</p>
            <div class='footer'>© " . date('Y') . " ELITE FITNESS COLLECTIVE | PREMIUM SERVICES</div>
        </div>
    </body>
    </html>";
    
    // --- 3. Generate PDF using mPDF ---
    $pdfMailName = 'Elite_Fitness_Plan_' . str_replace(' ', '_', $recipientName) . '.pdf';
    $pdfTmpPath  = __DIR__ . '/tmp/' . bin2hex(random_bytes(8)) . '.pdf';
    $pdfGenerated = false;
    try {
        $mpdf = new \Mpdf\Mpdf(['tempDir' => __DIR__ . '/tmp']);
        $mpdf->WriteHTML($htmlForPdf);
        $mpdf->Output($pdfTmpPath, 'F'); // 'F' saves the file to the server
        $pdfGenerated = true;
    } catch (\Throwable $e) {
        write_log('PDF', get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
        $uiState = 'operational_error';
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>PDF Generation Failed</h2>
                          <p style='color: #FF6B6B; text-align: center;'>Your plan could not be generated at this time. Please try again later.</p>";
        if (file_exists($pdfTmpPath)) {
            unlink($pdfTmpPath);
        }
    }

    // --- 4. Send Email with PDF Attachment using PHPMailer ---
    if ($pdfGenerated):

    // --- SMTP abuse prevention: daily quota check (fail-closed) ---
    $smtpQuota = check_smtp_quota($clientIp);
    if (!$smtpQuota['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . (string) $smtpQuota['retry_after']);
        if ($smtpQuota['quota_type'] === 'global' || $smtpQuota['quota_type'] === 'per_ip') {
            write_log(
                'SMTP_QUOTA_BLOCK',
                'Blocked SMTP quota | IP: ' . $clientIp
                . ' | quota: ' . $smtpQuota['quota_type']
                . ' | timestamp: ' . date('Y-m-d H:i:s')
            );
        }
        $uiState = 'operational_error';
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Daily Sending Limit Reached</h2>
                          <p style='color: #FF6B6B; text-align: center;'>This service has reached its sending limit. Please try again later.</p>";
        if (file_exists($pdfTmpPath)) {
            $deleted = unlink($pdfTmpPath);
            if ($deleted === false) {
                write_log('CLEANUP', 'Failed to delete temporary PDF: ' . basename($pdfTmpPath));
            }
        }
    } else {
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $_ENV['MAIL_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $_ENV['MAIL_USERNAME'];
        $mail->Password   = $_ENV['MAIL_PASSWORD'];
        $mail->SMTPSecure = $_ENV['MAIL_ENCRYPTION'];
        $mail->Port       = (int) $_ENV['MAIL_PORT'];
        $mail->CharSet    = 'UTF-8';

        // Recipients
        $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
        $mail->addAddress($recipientEmail, $recipientName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Your Personalized Elite Fitness Plan is Here!';
        $mail->Body    = "Hello {$recipientName},<br><br>Your personalized fitness plan is ready. Please find the attached PDF, which contains your full weekly schedule.<br><br>We are excited to be part of your fitness journey.<br><br>To your health,<br>The Elite Fitness Team";
        
        // Attach the generated PDF
        $mail->addAttachment($pdfTmpPath, $pdfMailName);

        $mail->send();

        // Record only successful sends toward quota. The send already succeeded,
        // so a recording failure is logged internally and does not change the response.
        record_smtp_success($clientIp);
        
        $uiState = 'success';
        $outputMessage = "<h2 style='color: #d4af37; margin-bottom: 20px; text-align: center;'>Plan Sent Successfully!</h2>
                          <p style='color: #90EE90; text-align: center;'>The personalized PDF plan has been generated and sent to {$recipientEmail}.</p>";

    } catch (Exception $e) {
        write_log('MAIL', $e->getMessage() . ' | ErrorInfo: ' . $mail->ErrorInfo);
        $uiState = 'operational_error';
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Email Could Not Be Sent</h2>
                          <p style='color: #FF6B6B; text-align: center;'>Your plan could not be delivered at this time. Please try again later.</p>";
    } finally {
        // Delete the temporary PDF file from the server
        if (file_exists($pdfTmpPath)) {
            $deleted = unlink($pdfTmpPath);
            if ($deleted === false) {
                write_log('CLEANUP', 'Failed to delete temporary PDF: ' . basename($pdfTmpPath));
            }
        }
    }

    } // end smtp-quota-allowed block

    endif; // end pdfGenerated block

    } // end turnstile-allowed block

    } // end rate-limit-allowed block

    } // end validation-passed block

    } // end CSRF-valid block
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Premium Workout Plan</title>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <!-- Your existing styles are perfect, no changes needed here -->
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 50%, #0f1729 100%); color: #e8dcc8; display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; padding: 50px 20px; }
        .container { width: 100%; max-width: 800px; }
        .header { text-align: center; margin-bottom: 40px; }
        .luxury-badge { display: inline-block; background: linear-gradient(135deg, #c9a961, #d4af37); color: #0a0e27; padding: 6px 16px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 2px; margin-bottom: 15px; text-transform: uppercase; }
        h2 { font-size: 42px; font-weight: 300; letter-spacing: 3px; background: linear-gradient(135deg, #d4af37, #e8dcc8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin-bottom: 8px; }
        .subtitle { font-size: 13px; color: #a89f8f; letter-spacing: 1px; text-transform: uppercase; }
        form, .result-container { background: linear-gradient(135deg, rgba(30, 35, 60, 0.6), rgba(20, 25, 45, 0.8)); backdrop-filter: blur(20px); padding: 45px; border-radius: 8px; border: 1px solid rgba(212, 175, 55, 0.2); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3); }
        .form-section { margin-bottom: 32px; }
        label { display: block; font-weight: 500; font-size: 12px; letter-spacing: 1px; text-transform: uppercase; color: #c9a961; margin-bottom: 10px; }
        input[type="text"], input[type="email"], textarea { width: 100%; padding: 14px 16px; border: 1px solid rgba(212, 175, 55, 0.25); border-radius: 4px; background: rgba(10, 14, 39, 0.7); color: #e8dcc8; font-size: 14px; transition: all 0.3s ease; }
        textarea { resize: vertical; min-height: 80px; }
        input[type="text"]:focus, input[type="email"]:focus, textarea:focus { outline: none; border-color: #d4af37; box-shadow: 0 0 15px rgba(212, 175, 55, 0.2); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .section-title { font-size: 13px; font-weight: 600; letter-spacing: 2px; text-transform: uppercase; color: #d4af37; margin-bottom: 18px; padding-bottom: 10px; border-bottom: 1px solid rgba(212, 175, 55, 0.15); }
        .plan-table { width: 100%; border-collapse: collapse; }
        .plan-table th, .plan-table td { text-align: left; padding: 10px; }
        .plan-table th { color: #c9a961; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; }
        .plan-table td input { background: rgba(10, 14, 39, 0.9); }
        input[type="submit"] { width: 100%; background: linear-gradient(135deg, #d4af37, #c9a961); color: #0a0e27; font-weight: 700; padding: 16px; border: none; border-radius: 4px; cursor: pointer; transition: all 0.3s ease; letter-spacing: 1px; font-size: 14px; }
        input[type="submit"]:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(212, 175, 55, 0.4); }
        .alert-validation { background: rgba(255, 107, 107, 0.08); border: 1px solid rgba(255, 107, 107, 0.4); border-radius: 8px; padding: 24px 28px; margin-bottom: 28px; }
        .validation-summary h2 { font-size: 18px; color: #FF6B6B; font-weight: 500; letter-spacing: 1px; margin-bottom: 12px; }
        .validation-summary ul { color: #FF6B6B; padding-left: 20px; line-height: 1.9; }
        .alert-operational { border-color: rgba(255, 107, 107, 0.35); }
        .input-error { border-color: #FF6B6B !important; box-shadow: 0 0 12px rgba(255, 107, 107, 0.25) !important; }
        .field-error-text { color: #FF6B6B; font-size: 12px; margin-top: 6px; letter-spacing: 0.3px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="luxury-badge">✦ PLAN GENERATOR ✦</div>
            <h2>ELITE PLAN CREATOR</h2>
            <p class="subtitle">For Premium Clients</p>
        </div>

        <?php if ($uiState === 'success' || $uiState === 'operational_error'): ?>
            <div class="result-container<?php echo $uiState === 'operational_error' ? ' alert-operational' : ''; ?>">
                <?php echo $outputMessage; ?>
            </div>
        <?php else: ?>
            <?php if ($uiState === 'validation_error'): ?>
                <div class="alert-validation validation-summary">
                    <h2>Please correct the following errors:</h2>
                    <ul>
                        <?php foreach ($summaryErrors as $summaryError): ?>
                            <li><?php echo htmlspecialchars($summaryError, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="section-title">Client Details</div>
                <div class="form-section grid-2">
                    <div>
                        <label for="recipient_name">Client Name</label>
                        <input type="text" id="recipient_name" name="recipient_name" class="<?php echo trim(field_error_class($fieldErrors, 'recipient_name')); ?>" value="<?php echo htmlspecialchars($formData['recipient_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        <?php echo render_field_error($fieldErrors, 'recipient_name'); ?>
                    </div>
                    <div>
                        <label for="recipient_email">Client Email</label>
                        <input type="email" id="recipient_email" name="recipient_email" class="<?php echo trim(field_error_class($fieldErrors, 'recipient_email')); ?>" value="<?php echo htmlspecialchars($formData['recipient_email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        <?php echo render_field_error($fieldErrors, 'recipient_email'); ?>
                    </div>
                </div>
                <div class="form-section">
                    <label for="intro_message">Introductory Message (for PDF)</label>
                    <textarea id="intro_message" name="intro_message" class="<?php echo trim(field_error_class($fieldErrors, 'intro_message')); ?>" required><?php echo htmlspecialchars($formData['intro_message'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                    <?php echo render_field_error($fieldErrors, 'intro_message'); ?>
                </div>

                <div class="section-title">Weekly Workout & Diet Plan</div>
                <div class="form-section">
                    <?php echo render_field_error($fieldErrors, 'plan'); ?>
                    <table class="plan-table">
                        <tr><th>Day</th><th>Workout Focus</th><th>Details / Exercises</th></tr>
                        <?php foreach ($weekDays as $day):
                            $day_lower = strtolower($day);
                        ?>
                        <tr>
                            <td><strong><?php echo $day; ?></strong></td>
                            <td>
                                <input type="text" name="<?php echo $day_lower; ?>_focus" class="<?php echo trim(field_error_class($fieldErrors, $day_lower . '_focus')); ?>" value="<?php echo htmlspecialchars($formData[$day_lower . '_focus'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., Chest & Triceps">
                                <?php echo render_field_error($fieldErrors, $day_lower . '_focus'); ?>
                            </td>
                            <td>
                                <input type="text" name="<?php echo $day_lower; ?>_details" class="<?php echo trim(field_error_class($fieldErrors, $day_lower . '_details')); ?>" value="<?php echo htmlspecialchars($formData[$day_lower . '_details'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g., Bench Press 4x10, Dips 3x12...">
                                <?php echo render_field_error($fieldErrors, $day_lower . '_details'); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>

                <div class="form-section">
                    <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars($_ENV['TURNSTILE_SITE_KEY'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></div>
                </div>

                <input type="submit" value="Generate PDF & Send Email">
            </form>
        <?php endif; ?>
    </div>
</body>
</html>