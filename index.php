<?php
require_once __DIR__ . '/src/Security/headers.php';
require_once __DIR__ . '/src/Security/session.php';
require_once __DIR__ . '/src/Security/csrf.php';
require_once __DIR__ . '/src/Security/turnstile.php';
require_once __DIR__ . '/src/Security/rate_limiter.php';
require_once __DIR__ . '/src/Support/paths.php';
require_once __DIR__ . '/src/Logging/logger.php';
require_once __DIR__ . '/src/Quota/smtp_quota.php';
require_once __DIR__ . '/src/Validation/errors.php';
require_once __DIR__ . '/src/Validation/input_normalizer.php';
require_once __DIR__ . '/src/Validation/rules.php';
require_once __DIR__ . '/src/PDF/plan_template.php';
require_once __DIR__ . '/src/PDF/pdf_generator.php';
require_once __DIR__ . '/src/Mail/mailer.php';
require_once __DIR__ . '/src/Http/request_handler.php';

// --- SETUP & AUTOLOADING ---
// Load Composer's autoloader for PHPMailer and mPDF
require 'vendor/autoload.php';

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
$configResult = require_once __DIR__ . '/src/Config/bootstrap.php';
if (!$configResult['success']) {
    write_log('CONFIG', $configResult['exception_message']);
    $uiState = 'operational_error';
    $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Application Configuration Error</h2>
                      <p style='color: #FF6B6B; text-align: center;'>The application is not configured correctly. Please contact the administrator.</p>";
}

// Generate a CSRF token for this session if one does not exist
csrf_generate();

// --- PROCESS THE FORM WHEN SUBMITTED ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $clientIp = get_client_ip_address();
    $result = handle_post_request($_POST, $formData, $clientIp);
    $uiState       = $result['uiState'];
    $outputMessage = $result['outputMessage'];
    $summaryErrors = $result['summaryErrors'];
    $fieldErrors   = $result['fieldErrors'];
}
if ($uiState === 'success' || $uiState === 'operational_error') {
    require __DIR__ . '/views/result.php';
} else {
    require __DIR__ . '/views/form.php';
}
