<?php

declare(strict_types=1);

$projectRoot  = dirname(__DIR__, 2);
$requiredVars = require __DIR__ . '/env.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable($projectRoot);
    $dotenv->load();
    $dotenv->required($requiredVars);

    return ['success' => true, 'exception_message' => null];
} catch (\Dotenv\Exception\InvalidPathException | \Dotenv\Exception\ValidationException $e) {
    return ['success' => false, 'exception_message' => $e->getMessage()];
}
