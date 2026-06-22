<?php

declare(strict_types=1);

function validate_inputs(
    string $rawName,
    string $rawEmail,
    string $rawIntro,
    array $rawPlan,
    array &$fieldErrors,
    array &$summaryErrors
): void {
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

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
}
