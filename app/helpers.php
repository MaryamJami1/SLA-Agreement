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

/** The client's IP address (REMOTE_ADDR), or null on the command line. */
function client_ip(): ?string
{
    return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null;
}

/** Site-relative URL, honouring an optional BASE_URL prefix (e.g. '/portal'). */
function url(string $path = ''): string
{
    return rtrim((string) cfg('BASE_URL', ''), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path), true, 303);
    exit;
}

/** Queue a one-time message for the next page ('ok', 'error' or 'info'). */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Take (and clear) the queued messages. */
function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** The plan's uniform "not found" response: used for missing records AND for access denied. */
function not_found(): void
{
    http_response_code(404);
    $pageTitle = 'Not found';
    $bare = true;
    require APP_ROOT . '/app/views/layout_top.php';
    echo '<div class="auth-card"><h2>Page not found</h2><p class="muted">The page you asked for doesn\'t exist.</p>'
        . '<p><a class="btn primary" href="' . h(url('index.php')) . '">Go to the home page</a></p></div>';
    require APP_ROOT . '/app/views/layout_bottom.php';
    exit;
}

/** Only POST is accepted (state-changing endpoints such as logout). */
function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed.');
    }
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

/** Escape % _ and \ so user text is matched literally inside a LIKE pattern. */
function like_escape(string $s): string
{
    return addcslashes($s, '%_\\');
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
