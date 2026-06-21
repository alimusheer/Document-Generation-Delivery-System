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

        <div class="result-container<?php echo $uiState === 'operational_error' ? ' alert-operational' : ''; ?>">
            <?php echo $outputMessage; ?>
        </div>
    </div>
</body>
</html>
