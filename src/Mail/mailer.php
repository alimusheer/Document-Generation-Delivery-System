<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_plan_email(
    string $recipientEmail,
    string $recipientName,
    string $attachmentPath,
    string $attachmentName
): bool {
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
        $mail->addAttachment($attachmentPath, $attachmentName);

        $mail->send();
        return true;
    } catch (Exception $e) {
        write_log('MAIL', $e->getMessage() . ' | ErrorInfo: ' . $mail->ErrorInfo);
        return false;
    } finally {
        // Delete the temporary PDF file from the server
        if (file_exists($attachmentPath)) {
            $deleted = unlink($attachmentPath);
            if ($deleted === false) {
                write_log('CLEANUP', 'Failed to delete temporary PDF: ' . basename($attachmentPath));
            }
        }
    }
}
