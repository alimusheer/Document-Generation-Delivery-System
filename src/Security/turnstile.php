<?php

declare(strict_types=1);

// --- CLOUDFLARE TURNSTILE VERIFICATION ---
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
