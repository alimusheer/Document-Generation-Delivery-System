<?php
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax'
]);

// --- SETUP & AUTOLOADING ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader for PHPMailer and mPDF
require 'vendor/autoload.php';

// Load environment variables from .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// This variable will hold the HTML for the success/error message
$outputMessage = '';

// Generate a CSRF token for this session if one does not exist
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- PROCESS THE FORM WHEN SUBMITTED ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // --- CSRF Validation ---
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Security Validation Failed</h2>
                          <p style='color: #FF6B6B; text-align: center;'>Your request could not be verified. Please refresh the page and try again.</p>";
    } else {

    // Consume the used token and immediately issue a fresh one
    unset($_SESSION['csrf_token']);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

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

    // --- 2. Validate Inputs ---
    $errors = [];

    // --- 3. Stop and display errors if validation failed ---
    if (!empty($errors)) {
        $errorItems = '';
        foreach ($errors as $error) {
            $errorItems .= "<li>" . htmlspecialchars($error) . "</li>";
        }
        $outputMessage = "<h2 style='color: #FF6B6B; text-align: center;'>Please correct the following errors:</h2>
                          <ul style='color: #FF6B6B; margin-top: 12px; padding-left: 20px; line-height: 2;'>{$errorItems}</ul>";
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
    $mpdf = new \Mpdf\Mpdf(['tempDir' => __DIR__ . '/tmp']);
    $mpdf->WriteHTML($htmlForPdf);
    $pdfFileName = "Elite_Fitness_Plan_" . str_replace(' ', '_', $recipientName) . ".pdf";
    $mpdf->Output($pdfFileName, 'F'); // 'F' saves the file to the server

    // --- 4. Send Email with PDF Attachment using PHPMailer ---
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
        $mail->addAttachment($pdfFileName);

        $mail->send();
        
        $outputMessage = "<h2 style='color: #d4af37; margin-bottom: 20px; text-align: center;'>Plan Sent Successfully!</h2>
                          <p style='color: #90EE90; text-align: center;'>The personalized PDF plan has been generated and sent to {$recipientEmail}.</p>";

    } catch (Exception $e) {
        $outputMessage = "<h2 style='color: #FF6B6B;'>Email Could Not Be Sent</h2>
                          <p style='color: #FF6B6B;'>Mailer Error: {$mail->ErrorInfo}</p>";
    } finally {
        // Delete the temporary PDF file from the server
        if (file_exists($pdfFileName)) {
            unlink($pdfFileName);
        }
    }

    } // end validation-passed block

    } // end CSRF-valid block
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Premium Workout Plan</title>
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="luxury-badge">✦ PLAN GENERATOR ✦</div>
            <h2>ELITE PLAN CREATOR</h2>
            <p class="subtitle">For Premium Clients</p>
        </div>

        <?php if (!empty($outputMessage)): ?>
            <div class="result-container">
                <?php echo $outputMessage; ?>
            </div>
        <?php else: ?>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="section-title">Client Details</div>
                <div class="form-section grid-2">
                    <div>
                        <label for="recipient_name">Client Name</label>
                        <input type="text" id="recipient_name" name="recipient_name" required>
                    </div>
                    <div>
                        <label for="recipient_email">Client Email</label>
                        <input type="email" id="recipient_email" name="recipient_email" required>
                    </div>
                </div>
                <div class="form-section">
                    <label for="intro_message">Introductory Message (for PDF)</label>
                    <textarea id="intro_message" name="intro_message" required>Here is the personalized fitness plan we discussed. We have designed this schedule to align perfectly with your goals. Consistency is key, and we are here to support you every step of the way.</textarea>
                </div>

                <div class="section-title">Weekly Workout & Diet Plan</div>
                <div class="form-section">
                    <table class="plan-table">
                        <tr><th>Day</th><th>Workout Focus</th><th>Details / Exercises</th></tr>
                        <?php 
                        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                        foreach ($days as $day): 
                            $day_lower = strtolower($day);
                        ?>
                        <tr>
                            <td><strong><?php echo $day; ?></strong></td>
                            <td><input type="text" name="<?php echo $day_lower; ?>_focus" placeholder="e.g., Chest & Triceps"></td>
                            <td><input type="text" name="<?php echo $day_lower; ?>_details" placeholder="e.g., Bench Press 4x10, Dips 3x12..."></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                
                <input type="submit" value="Generate PDF & Send Email">
            </form>
        <?php endif; ?>
    </div>
</body>
</html>