<?php

function check_smtp_quota(string $ip): array {
    // Fail-closed default: any storage failure blocks sending to protect SMTP reputation.
    $blockResult = [
        'allowed' => false,
        'quota_type' => 'error',
        'retry_after' => SMTP_QUOTA_WINDOW_SECONDS,
    ];

    $storageDir = dirname(SMTP_QUOTA_FILE);
    if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
        write_log('SMTP_QUOTA_ERROR', 'Storage directory unavailable: ' . $storageDir . ' | IP: ' . $ip);
        return $blockResult;
    }

    $handle = @fopen(SMTP_QUOTA_FILE, 'c+');
    if ($handle === false) {
        write_log('SMTP_QUOTA_ERROR', 'Unable to open quota file: ' . SMTP_QUOTA_FILE . ' | IP: ' . $ip);
        return $blockResult;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        write_log('SMTP_QUOTA_ERROR', 'Unable to acquire lock for quota file | IP: ' . $ip);
        return $blockResult;
    }

    try {
        rewind($handle);
        $rawJson = stream_get_contents($handle);
        if ($rawJson === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to read quota file | IP: ' . $ip);
            return $blockResult;
        }

        $globalEntries = [];
        $perIpEntries = [];
        if (trim($rawJson) !== '') {
            $decoded = json_decode($rawJson, true);
            if (!is_array($decoded)) {
                write_log('SMTP_QUOTA_ERROR', 'Invalid JSON in quota file. Blocking send (fail-closed) | IP: ' . $ip);
                return $blockResult;
            }
            $globalEntries = $decoded['global'] ?? [];
            $perIpEntries = $decoded['per_ip'] ?? [];
            if (!is_array($globalEntries) || !is_array($perIpEntries)) {
                write_log('SMTP_QUOTA_ERROR', 'Invalid quota structure. Blocking send (fail-closed) | IP: ' . $ip);
                return $blockResult;
            }
        }

        $now = time();
        $cutoff = $now - SMTP_QUOTA_WINDOW_SECONDS;

        // Prune expired entries and persist the cleaned structure while the lock is held.
        $cleanGlobal = array_map('intval', array_values(array_filter($globalEntries, static function ($timestamp) use ($cutoff): bool {
            return is_numeric($timestamp) && (int) $timestamp >= $cutoff;
        })));

        $cleanPerIp = [];
        foreach ($perIpEntries as $existingIp => $timestamps) {
            if (!is_array($timestamps)) {
                continue;
            }
            $filtered = array_values(array_filter($timestamps, static function ($timestamp) use ($cutoff): bool {
                return is_numeric($timestamp) && (int) $timestamp >= $cutoff;
            }));
            if ($filtered === []) {
                continue;
            }
            $cleanPerIp[$existingIp] = array_map('intval', $filtered);
        }

        $cleanedData = ['global' => $cleanGlobal, 'per_ip' => $cleanPerIp];
        $encoded = json_encode($cleanedData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to encode pruned quota JSON. Blocking send (fail-closed) | IP: ' . $ip);
            return $blockResult;
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to truncate quota file during prune. Blocking send (fail-closed) | IP: ' . $ip);
            return $blockResult;
        }
        if (fwrite($handle, $encoded) === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to write pruned quota file. Blocking send (fail-closed) | IP: ' . $ip);
            return $blockResult;
        }
        fflush($handle);

        // Continue quota evaluation using the cleaned data.
        $globalCount = count($cleanGlobal);
        $perIpCount = isset($cleanPerIp[$ip]) ? count($cleanPerIp[$ip]) : 0;

        if ($globalCount >= SMTP_QUOTA_GLOBAL_PER_DAY) {
            return ['allowed' => false, 'quota_type' => 'global', 'retry_after' => SMTP_QUOTA_WINDOW_SECONDS];
        }
        if ($perIpCount >= SMTP_QUOTA_PER_IP_PER_DAY) {
            return ['allowed' => false, 'quota_type' => 'per_ip', 'retry_after' => SMTP_QUOTA_WINDOW_SECONDS];
        }

        return ['allowed' => true, 'quota_type' => null, 'retry_after' => 0];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function record_smtp_success(string $ip): bool {
    $storageDir = dirname(SMTP_QUOTA_FILE);
    if (!is_dir($storageDir) && !mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
        write_log('SMTP_QUOTA_ERROR', 'Storage directory unavailable during record: ' . $storageDir . ' | IP: ' . $ip);
        return false;
    }

    $handle = @fopen(SMTP_QUOTA_FILE, 'c+');
    if ($handle === false) {
        write_log('SMTP_QUOTA_ERROR', 'Unable to open quota file during record | IP: ' . $ip);
        return false;
    }

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        write_log('SMTP_QUOTA_ERROR', 'Unable to acquire lock during record | IP: ' . $ip);
        return false;
    }

    try {
        rewind($handle);
        $rawJson = stream_get_contents($handle);
        if ($rawJson === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to read quota file during record | IP: ' . $ip);
            return false;
        }

        $quotaData = ['global' => [], 'per_ip' => []];
        if (trim($rawJson) !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                $globalEntries = $decoded['global'] ?? [];
                $perIpEntries = $decoded['per_ip'] ?? [];
                if (is_array($globalEntries)) {
                    $quotaData['global'] = $globalEntries;
                }
                if (is_array($perIpEntries)) {
                    $quotaData['per_ip'] = $perIpEntries;
                }
            } else {
                write_log('SMTP_QUOTA_ERROR', 'Invalid JSON during record. Resetting quota structure | IP: ' . $ip);
            }
        }

        $now = time();
        $cutoff = $now - SMTP_QUOTA_WINDOW_SECONDS;

        $quotaData['global'] = array_map('intval', array_values(array_filter($quotaData['global'], static function ($timestamp) use ($cutoff): bool {
            return is_numeric($timestamp) && (int) $timestamp >= $cutoff;
        })));

        foreach ($quotaData['per_ip'] as $existingIp => $timestamps) {
            if (!is_array($timestamps)) {
                unset($quotaData['per_ip'][$existingIp]);
                continue;
            }
            $filtered = array_values(array_filter($timestamps, static function ($timestamp) use ($cutoff): bool {
                return is_numeric($timestamp) && (int) $timestamp >= $cutoff;
            }));
            if ($filtered === []) {
                unset($quotaData['per_ip'][$existingIp]);
                continue;
            }
            $quotaData['per_ip'][$existingIp] = array_map('intval', $filtered);
        }

        $quotaData['global'][] = $now;
        if (!isset($quotaData['per_ip'][$ip]) || !is_array($quotaData['per_ip'][$ip])) {
            $quotaData['per_ip'][$ip] = [];
        }
        $quotaData['per_ip'][$ip][] = $now;

        $encoded = json_encode($quotaData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to encode quota JSON during record | IP: ' . $ip);
            return false;
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to truncate quota file during record | IP: ' . $ip);
            return false;
        }
        if (fwrite($handle, $encoded) === false) {
            write_log('SMTP_QUOTA_ERROR', 'Unable to write quota file during record | IP: ' . $ip);
            return false;
        }
        fflush($handle);

        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
