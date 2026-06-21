<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Premium Workout Plan</title>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
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
    </div>
</body>
</html>
