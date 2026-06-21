<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);

if (!defined('RATE_LIMIT_PER_MINUTE')) {
    define('RATE_LIMIT_PER_MINUTE', 5);
}

if (!defined('RATE_LIMIT_PER_HOUR')) {
    define('RATE_LIMIT_PER_HOUR', 20);
}

if (!defined('RATE_LIMIT_FILE')) {
    define('RATE_LIMIT_FILE', $projectRoot . '/tmp/rate_limits.json');
}

if (!defined('SMTP_QUOTA_GLOBAL_PER_DAY')) {
    define('SMTP_QUOTA_GLOBAL_PER_DAY', 100);
}

if (!defined('SMTP_QUOTA_PER_IP_PER_DAY')) {
    define('SMTP_QUOTA_PER_IP_PER_DAY', 10);
}

if (!defined('SMTP_QUOTA_WINDOW_SECONDS')) {
    define('SMTP_QUOTA_WINDOW_SECONDS', 86400);
}

if (!defined('SMTP_QUOTA_FILE')) {
    define('SMTP_QUOTA_FILE', $projectRoot . '/tmp/smtp_quotas.json');
}
