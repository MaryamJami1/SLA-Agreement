<?php
/**
 * Loaded first by every page in public/:
 *   public/*.php:          require __DIR__ . '/../app/bootstrap.php';
 *   public/<folder>/*.php: require __DIR__ . '/../../app/bootstrap.php';
 *
 * Order: config → error handling → web-root guard → HTTPS redirect → security headers →
 * session → CSRF check (every POST) → current user re-read → forced-password-change lockdown.
 * After this, a page calls require_login() or require_admin() (login/register pages call neither).
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set('Asia/Karachi');

require_once APP_ROOT . '/app/helpers.php';
require_once APP_ROOT . '/app/db.php';
require_once APP_ROOT . '/app/money.php';
require_once APP_ROOT . '/app/counters.php';
require_once APP_ROOT . '/app/audit.php';
require_once APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/csrf.php';

// ---------------------------------------------------------------------------
// Errors: always logged to storage/logs/; shown on screen only outside production.
// ---------------------------------------------------------------------------
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-errors.log');
ini_set('display_errors', '0');

function bootstrap_fail(string $logMessage): void
{
    app_log('BOOTSTRAP: ' . $logMessage);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "The application is not configured correctly. The details have been logged.\n";
    exit;
}

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    bootstrap_fail('config/config.php is missing (copy config.sample.php).');
}
$GLOBALS['APP_CONFIG'] = require $configFile;
if (!is_array($GLOBALS['APP_CONFIG'])) {
    bootstrap_fail('config/config.php must return an array.');
}
if (!is_production()) {
    ini_set('display_errors', '1');
    // Local-only: run the app against another database (used by the end-to-end checks).
    if (is_string(getenv('AOMESS_DB_NAME')) && preg_match('/^[a-z0-9_]+$/', getenv('AOMESS_DB_NAME'))) {
        $GLOBALS['APP_CONFIG']['DB_NAME'] = getenv('AOMESS_DB_NAME');
    }
}
$secret = (string) cfg('DEVICE_COOKIE_SECRET', '');
if (strlen($secret) < 32 || $secret === 'change-me') {
    bootstrap_fail('DEVICE_COOKIE_SECRET in config.php must be a long random string (see config.sample.php).');
}

set_exception_handler(static function (Throwable $e): void {
    app_log('UNCAUGHT ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo is_production()
        ? "Something went wrong. The error has been logged; please try again."
        : get_class($e) . ': ' . $e->getMessage() . "\n\n" . $e->getTraceAsString();
});

// ---------------------------------------------------------------------------
// Web-root guard (plan Section 4): app/ must never be inside the public document root.
// ---------------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && !cfg('ALLOW_APP_IN_WEBROOT', false)) {
    $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $appDir = realpath(__DIR__);
    if ($docRoot === false || $appDir === false || ($_SERVER['DOCUMENT_ROOT'] ?? '') === '') {
        bootstrap_fail('Web-root guard could not resolve DOCUMENT_ROOT or the app folder.');
    }
    if (path_is_inside($appDir, $docRoot)) {
        bootstrap_fail("app/ ($appDir) is inside the document root ($docRoot). Move app/, config/, database/ "
            . 'and storage/ above the web root, or set ALLOW_APP_IN_WEBROOT with .htaccess protection.');
    }
}

if (PHP_SAPI === 'cli') {
    return; // command-line tools only need the libraries and config
}

// ---------------------------------------------------------------------------
// HTTPS (production) and security headers
// ---------------------------------------------------------------------------
if (is_production() && !is_https()) {
    $target = https_redirect_target($_SERVER, (string) cfg('CANONICAL_HOST', ''));
    if ($target === null) {
        bootstrap_fail('Cannot redirect to HTTPS: no CANONICAL_HOST in config.php and no usable Host header.');
    }
    header('Location: ' . $target, true, 301);
    exit;
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; "
    . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
if (is_production()) {
    header('Strict-Transport-Security: max-age=31536000');
}

// ---------------------------------------------------------------------------
// Session, CSRF, current user
// ---------------------------------------------------------------------------
start_app_session();
csrf_check();

$user = load_current_user(db());

// Forced password change: only the change-password and logout pages are reachable.
if ($user !== null && (int) $user['must_change_password'] === 1
    && !is_password_change_allowlisted((string) ($_SERVER['SCRIPT_NAME'] ?? ''))) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        http_response_code(403);
        exit('You must change your password before doing anything else.');
    }
    redirect('auth/change_password.php');
}
unset($user, $secret);
