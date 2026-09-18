<?php
/**
 * Loaded first by every page in public/:
 *   public/*.php:          require __DIR__ . '/../app/bootstrap.php';
 *   public/<folder>/*.php: require __DIR__ . '/../../app/bootstrap.php';
 *
 * Phase 1: config, timezone, error logging, web-root guard, core libraries.
 * Phase 2 adds: HTTPS redirect, session, CSRF check on every POST, account-status and
 * forced-password-change checks.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

date_default_timezone_set('Asia/Karachi');

require_once APP_ROOT . '/app/helpers.php';
require_once APP_ROOT . '/app/db.php';
require_once APP_ROOT . '/app/money.php';
require_once APP_ROOT . '/app/counters.php';

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
