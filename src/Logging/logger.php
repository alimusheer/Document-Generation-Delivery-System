<?php

declare(strict_types=1);

if (!function_exists('write_log')) {
    function write_log(string $context, string $message): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $logDir      = $projectRoot . '/logs';
        $logFile     = $logDir . '/app.log';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        if (file_exists($logFile) && filesize($logFile) > 1048576) {
            rename($logFile, $logDir . '/app.log.bak');
        }

        $entry = '[' . date('Y-m-d H:i:s') . '] [ERROR] [' . $context . '] ' . $message . PHP_EOL;
        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
