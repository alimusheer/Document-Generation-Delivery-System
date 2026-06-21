<?php

declare(strict_types=1);

function get_client_ip_address(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function enforce_rate_limit(string $clientIp): array {
    $allowResult = [
        'allowed' => true,
        'triggered_limit' => null,
        'retry_after' => 0,
    ];

    $storageDir = dirname(RATE_LIMIT_FILE);
    if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
        write_log('RATE_LIMIT_ERROR', 'Storage directory unavailable: ' . $storageDir . ' | IP: ' . $clientIp);
        return $allowResult;
    }

    $handle = @fopen(RATE_LIMIT_FILE, 'c+');
    if ($handle === false) {
        write_log('RATE_LIMIT_ERROR', 'Unable to open rate limit file: ' . RATE_LIMIT_FILE . ' | IP: ' . $clientIp);
        return $allowResult;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        write_log('RATE_LIMIT_ERROR', 'Unable to acquire lock for rate limit file | IP: ' . $clientIp);
        return $allowResult;
    }

    try {
        rewind($handle);
        $rawJson = stream_get_contents($handle);
        if ($rawJson === false) {
            write_log('RATE_LIMIT_ERROR', 'Unable to read rate limit file | IP: ' . $clientIp);
            return $allowResult;
        }

        $rateData = [];
        if (trim($rawJson) !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                $rateData = $decoded;
            } else {
                write_log('RATE_LIMIT_ERROR', 'Invalid JSON in rate limit file. Resetting structure.');
            }
        }

        $now = time();
        $minuteCutoff = $now - 60;
        $hourCutoff = $now - 3600;

        foreach ($rateData as $ip => $entry) {
            if (!is_array($entry)) {
                unset($rateData[$ip]);
                continue;
            }

            $minuteEntries = $entry['minute'] ?? [];
            $hourEntries = $entry['hour'] ?? [];

            if (!is_array($minuteEntries)) {
                $minuteEntries = [];
            }
            if (!is_array($hourEntries)) {
                $hourEntries = [];
            }

            $minuteEntries = array_values(array_filter($minuteEntries, static function ($timestamp) use ($minuteCutoff): bool {
                return is_numeric($timestamp) && (int) $timestamp >= $minuteCutoff;
            }));
            $hourEntries = array_values(array_filter($hourEntries, static function ($timestamp) use ($hourCutoff): bool {
                return is_numeric($timestamp) && (int) $timestamp >= $hourCutoff;
            }));

            $minuteEntries = array_map('intval', $minuteEntries);
            $hourEntries = array_map('intval', $hourEntries);

            if ($minuteEntries === [] && $hourEntries === []) {
                unset($rateData[$ip]);
                continue;
            }

            $rateData[$ip] = [
                'minute' => $minuteEntries,
                'hour' => $hourEntries,
            ];
        }

        if (!isset($rateData[$clientIp])) {
            $rateData[$clientIp] = [
                'minute' => [],
                'hour' => [],
            ];
        }

        $currentMinute = $rateData[$clientIp]['minute'];
        $currentHour = $rateData[$clientIp]['hour'];

        $isBlocked = false;
        $triggeredLimit = null;
        $retryAfter = 0;

        if (count($currentMinute) >= RATE_LIMIT_PER_MINUTE) {
            $isBlocked = true;
            $triggeredLimit = 'minute';
            $retryAfter = 60;
        } elseif (count($currentHour) >= RATE_LIMIT_PER_HOUR) {
            $isBlocked = true;
            $triggeredLimit = 'hour';
            $retryAfter = 3600;
        } else {
            $rateData[$clientIp]['minute'][] = $now;
            $rateData[$clientIp]['hour'][] = $now;
        }

        $encoded = json_encode($rateData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            write_log('RATE_LIMIT_ERROR', 'Unable to encode rate limit JSON | IP: ' . $clientIp);
            return $allowResult;
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            write_log('RATE_LIMIT_ERROR', 'Unable to truncate rate limit file | IP: ' . $clientIp);
            return $allowResult;
        }

        if (fwrite($handle, $encoded) === false) {
            write_log('RATE_LIMIT_ERROR', 'Unable to write rate limit file | IP: ' . $clientIp);
            return $allowResult;
        }
        fflush($handle);

        return [
            'allowed' => !$isBlocked,
            'triggered_limit' => $triggeredLimit,
            'retry_after' => $retryAfter,
        ];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
