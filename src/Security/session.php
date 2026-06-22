<?php

declare(strict_types=1);

// --- SESSION ---
// $isHttps is set by src/Security/headers.php, which must be included before this file.
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure'   => $isHttps ?? false,
    'use_strict_mode' => true,
]);
