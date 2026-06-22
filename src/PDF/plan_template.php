<?php

declare(strict_types=1);

function render_plan_template(string $recipientName, string $introMessage, array $planData): string
{
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
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

    return $htmlForPdf;
}
