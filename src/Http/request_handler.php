<?php

declare(strict_types=1);

/**
 * Handle the POST request flow coordination.
 *
 * @param array $postData Raw POST data
 * @param array $formData Form data template (passed by reference to repopulate)
 * @param string $clientIp Client IP address
 * @return array Array containing state and output message
 */
function handle_post_request(array $postData, array &$formData, string $clientIp): array
{
    $uiState       = 'form';
    $outputMessage = '';
    $summaryErrors = [];
    $fieldErrors   = [];

    // --- CSRF Validation ---
    if (!csrf_validate()) {
        http_response_code(403);
        $uiState = 'operational_error';
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Security Validation Failed</h2>
                          <p style='color: #FF6B6B; text-align: center;'>Your request could not be verified. Please refresh the page and try again.</p>";
        return compact('uiState', 'outputMessage', 'summaryErrors', 'fieldErrors');
    }

    // Consume the used token and immediately issue a fresh one
    csrf_rotate();

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
        return compact('uiState', 'outputMessage', 'summaryErrors', 'fieldErrors');
    }

    // --- Turnstile Verification (fail-closed) ---
    $turnstileToken  = isset($postData['cf-turnstile-response']) && is_string($postData['cf-turnstile-response'])
        ? trim($postData['cf-turnstile-response'])
        : '';
    $turnstileResult = verify_turnstile($turnstileToken, $clientIp);
    if (!$turnstileResult['allowed']) {
        http_response_code(403);
        $uiState = 'operational_error';
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Security Verification Failed</h2>
                          <p style='color: #FF6B6B; text-align: center;'>We could not verify that you are human. Please refresh the page and try again.</p>";
        return compact('uiState', 'outputMessage', 'summaryErrors', 'fieldErrors');
    }

    // --- 1. Trim Raw Inputs ---
    $normalized = normalize_inputs($postData, $formData);
    $rawName    = $normalized['recipient_name'];
    $rawEmail   = $normalized['recipient_email'];
    $rawIntro   = $normalized['intro_message'];
    $rawPlan    = $normalized['plan'];

    // --- 2. Validate Inputs ---
    validate_inputs($rawName, $rawEmail, $rawIntro, $rawPlan, $fieldErrors, $summaryErrors);

    // --- 3. Stop and display errors if validation failed ---
    if (!empty($summaryErrors)) {
        $uiState = 'validation_error';
        return compact('uiState', 'outputMessage', 'summaryErrors', 'fieldErrors');
    }

    // --- 4. Sanitize and Capture Form Data ---
    $recipientName = htmlspecialchars($postData["recipient_name"]);
    $recipientEmail = filter_var($postData["recipient_email"], FILTER_SANITIZE_EMAIL);
    $introMessage = nl2br(htmlspecialchars($postData["intro_message"])); // Convert newlines to <br> for HTML
    $planData = [];

    // Loop through each day of the week to get the plan
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    foreach ($days as $day) {
        $planData[$day] = [
            'focus' => htmlspecialchars($postData[strtolower($day) . '_focus'] ?? ''),
            'details' => htmlspecialchars($postData[strtolower($day) . '_details'] ?? '')
        ];
    }

    // --- 5. Build the Premium HTML for the PDF ---
    $htmlForPdf = render_plan_template($recipientName, $introMessage, $planData);

    // --- 6. Generate PDF using mPDF ---
    $pdfMailName = 'Elite_Fitness_Plan_' . str_replace(' ', '_', $recipientName) . '.pdf';
    $pdfTmpPath  = dirname(__DIR__, 2) . '/tmp/' . bin2hex(random_bytes(8)) . '.pdf';
    
    $pdfGenerated = generate_pdf($htmlForPdf, $pdfTmpPath);
    if (!$pdfGenerated) {
        $uiState = 'operational_error';
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>PDF Generation Failed</h2>
                          <p style='color: #FF6B6B; text-align: center;'>Your plan could not be generated at this time. Please try again later.</p>";
        return compact('uiState', 'outputMessage', 'summaryErrors', 'fieldErrors');
    }

    // --- 7. SMTP abuse prevention: daily quota check (fail-closed) ---
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
        return compact('uiState', 'outputMessage', 'summaryErrors', 'fieldErrors');
    }

    // --- 8. Send Email with PDF Attachment using PHPMailer ---
    $mailSent = send_plan_email($recipientEmail, $recipientName, $pdfTmpPath, $pdfMailName);
    if ($mailSent) {
        // Record only successful sends toward quota. The send already succeeded,
        // so a recording failure is logged internally and does not change the response.
        record_smtp_success($clientIp);
        
        $uiState = 'success';
        $outputMessage = "<h2 style='color: #d4af37; margin-bottom: 20px; text-align: center;'>Plan Sent Successfully!</h2>
                          <p style='color: #90EE90; text-align: center;'>The personalized PDF plan has been generated and sent to {$recipientEmail}.</p>";
    } else {
        $uiState = 'operational_error';
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Email Could Not Be Sent</h2>
                          <p style='color: #FF6B6B; text-align: center;'>Your plan could not be delivered at this time. Please try again later.</p>";
    }

    return compact('uiState', 'outputMessage', 'summaryErrors', 'fieldErrors');
}
