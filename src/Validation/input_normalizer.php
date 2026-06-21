<?php

declare(strict_types=1);

function normalize_inputs(array $post, array &$formData): array
{
    $rawName    = trim($post['recipient_name']  ?? '');
    $rawEmail   = trim($post['recipient_email'] ?? '');
    $rawIntro   = trim($post['intro_message']   ?? '');
    $days       = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $rawPlan    = [];
    foreach ($days as $day) {
        $key = strtolower($day);
        $rawPlan[$day] = [
            'focus'   => trim($post[$key . '_focus']   ?? ''),
            'details' => trim($post[$key . '_details'] ?? ''),
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

    return [
        'recipient_name'  => $rawName,
        'recipient_email' => $rawEmail,
        'intro_message'   => $rawIntro,
        'plan'            => $rawPlan,
    ];
}
