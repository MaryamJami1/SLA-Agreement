<?php
/**
 * Small shared helpers: escaping, config, logging, input validation, exceptions.
 * Pure functions here are covered by tests/run.php.
 */
declare(strict_types=1);

/** A user-supplied value failed validation. The message is safe to show next to the field. */
class InvalidInput extends InvalidArgumentException {}

/** A deadlock persisted after one retry; the user is asked to try again. */
class TryAgainException extends RuntimeException {}

/** Largest accepted guests / qty / furniture and manpower count (Section 6). */
const WHOLE_NUMBER_MAX = 100000;

/** Escape a value for HTML output. Every value printed into a page goes through this. */
function h($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Read a setting from config/config.php (loaded once by bootstrap.php). */
function cfg(string $key, $default = null)
{
    return $GLOBALS['APP_CONFIG'][$key] ?? $default;
}

function is_production(): bool
{
    return cfg('APP_ENV') === 'production';
}

/** Append a line to storage/logs/app-YYYY-MM.log. Never throws. */
function app_log(string $message): void
{
    $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
    $file = $root . '/storage/logs/app-' . date('Y-m') . '.log';
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * True when $path is $dir itself or anywhere below it. Both must already be resolved with
 * realpath(). A trailing separator is compared, so /public_html2 is not inside /public_html.
 * Case-insensitive on Windows.
 */
function path_is_inside(string $path, string $dir): bool
{
    $norm = static function (string $p): string {
        $p = rtrim(str_replace('\\', '/', $p), '/') . '/';
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($p) : $p;
    };
    return strncmp($norm($path), $norm($dir), strlen($norm($dir))) === 0;
}

/** CNIC in the form #####-#######-# (e.g. 42101-1234567-1). */
function is_valid_cnic(string $cnic): bool
{
    return preg_match('/^\d{5}-\d{7}-\d$/', $cnic) === 1;
}

/**
 * Parse a whole number such as guests, qty or a furniture count.
 * Returns null for an empty value (the caller decides whether it is required).
 *
 * @throws InvalidInput for anything that is not a whole number from 0 to $max
 */
function parse_whole_number($raw, int $max = WHOLE_NUMBER_MAX): ?int
{
    $s = str_replace(',', '', trim((string) ($raw ?? '')));
    if ($s === '') {
        return null;
    }
    if (preg_match('/^\d+$/', $s) !== 1) {
        throw new InvalidInput('Enter a whole number (0 or more).');
    }
    if (strlen(ltrim($s, '0')) > strlen((string) $max) || (int) $s > $max) {
        throw new InvalidInput('Must be at most ' . group_digits_south_asian((string) $max) . '.');
    }
    return (int) $s;
}

/** Group a string of digits the South Asian way: 1234567 → 12,34,567. */
function group_digits_south_asian(string $digits): string
{
    if (strlen($digits) <= 3) {
        return $digits;
    }
    $last3 = substr($digits, -3);
    $rest = substr($digits, 0, -3);
    $rest = preg_replace('/\B(?=(\d{2})+$)/', ',', $rest);
    return $rest . ',' . $last3;
}
