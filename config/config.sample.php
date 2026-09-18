<?php
/**
 * Copy this file to config.php (same folder) and fill in the real values.
 * config.php holds credentials and is never committed or uploaded to public_html.
 */
return [
    // 'local' (XAMPP, http://localhost) or 'production' (Hostinger, HTTPS only).
    'APP_ENV' => 'local',

    // Database. Local XAMPP MariaDB runs on port 3307 (MySQL80 keeps 3306).
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => 3307,
    'DB_NAME' => 'aomess',
    'DB_USER' => 'root',
    'DB_PASS' => '',

    // Secret used to sign login device cookies. Use a long random string:
    //   php -r "echo bin2hex(random_bytes(32));"
    'DEVICE_COOKIE_SECRET' => 'change-me',

    // Only for accounts that can't place app/ above public_html (then protect app/, config/,
    // database/ and storage/ with "Require all denied" .htaccess files).
    'ALLOW_APP_IN_WEBROOT' => false,

    // Amendments of a signed revision require its signed scan to be uploaded first.
    'REQUIRE_SIGNED_COPY_FOR_AMENDMENT' => true,

    // Letterhead / document footer (open question 5).
    'ORG_NAME'    => 'AO Mess / ASK Organizers',
    'ORG_ADDRESS' => 'Karachi',
    'ORG_PHONE'   => '',
    'ORG_EMAIL'   => '',
];
