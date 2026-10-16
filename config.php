<?php
declare(strict_types=1);

// Load per-environment overrides (keep this file out of git)
$localConfig = __DIR__ . DIRECTORY_SEPARATOR . 'config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

// Database
if (!defined('DB_HOST')) {
    define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
}
if (!defined('DB_PORT')) {
    $port = getenv('DB_PORT');
    define('DB_PORT', ($port !== false && $port !== '') ? (int)$port : 3306);
}
if (!defined('DB_USER')) {
    define('DB_USER', getenv('DB_USER') ?: 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', getenv('DB_PASS') ?: '');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', getenv('DB_NAME') ?: '');
}

// Password encryption key (for admin-only display)
// Recommended: set env var PASSWORD_ENC_KEY, or define it in config.local.php
if (!defined('PASSWORD_ENC_KEY')) {
    define('PASSWORD_ENC_KEY', getenv('PASSWORD_ENC_KEY') ?: '');
}

// JWT signing secret for auth.php (HS256, at least 32 characters).
// Set env var JWT_SECRET, or define it in config.local.php. Empty = login disabled.
if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', getenv('JWT_SECRET') ?: '');
}

// Admin login: user name and password_hash() of the password. Empty = admin login disabled.
if (!defined('ADMIN_USERNAME')) {
    define('ADMIN_USERNAME', getenv('ADMIN_USERNAME') ?: '');
}
if (!defined('ADMIN_PASSWORD_HASH')) {
    define('ADMIN_PASSWORD_HASH', getenv('ADMIN_PASSWORD_HASH') ?: '');
}

// Notifications (notify.php): SMTP mail and WhatsApp. Empty = channel disabled.
foreach ([
    'SITE_URL', 'NOTIFY_EMAIL',
    'MAIL_HOST', 'MAIL_PORT', 'MAIL_ENCRYPTION', 'MAIL_USER', 'MAIL_PASS', 'MAIL_FROM', 'MAIL_FROM_NAME',
    'WHATSAPP_PROVIDER', 'CALLMEBOT_PHONE', 'CALLMEBOT_APIKEY',
    'WA_CLOUD_TOKEN', 'WA_CLOUD_PHONE_ID', 'WA_CLOUD_TO', 'WA_CLOUD_TEMPLATE', 'WA_CLOUD_LANG',
] as $notifySetting) {
    if (!defined($notifySetting)) {
        define($notifySetting, getenv($notifySetting) ?: '');
    }
}
unset($notifySetting);
