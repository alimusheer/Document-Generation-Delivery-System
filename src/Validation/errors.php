<?php

declare(strict_types=1);

// Record a validation failure in both the grouped summary and the field map.
if (!function_exists('add_field_error')) {
    function add_field_error(array &$fieldErrors, array &$summaryErrors, string $field, string $message): void {
        $fieldErrors[$field][] = $message;
        $summaryErrors[] = $message;
    }
}

// Return the CSS class fragment for an invalid input.
if (!function_exists('field_error_class')) {
    function field_error_class(array $fieldErrors, string $field): string {
        return isset($fieldErrors[$field]) ? ' input-error' : '';
    }
}

// Return escaped inline error text for a field (empty string when valid).
if (!function_exists('render_field_error')) {
    function render_field_error(array $fieldErrors, string $field): string {
        if (empty($fieldErrors[$field])) {
            return '';
        }
        $html = '';
        foreach ($fieldErrors[$field] as $message) {
            $html .= '<div class="field-error-text">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        return $html;
    }
}
