<?php
/**
 * Authentication (plan Section 10): sessions, login with throttling, device cookies,
 * password rules, and page guards.
 */
declare(strict_types=1);

const SESSION_NAME             = 'AOMESSID';
const SESSION_IDLE_SECONDS     = 30 * 60;
const SESSION_ABSOLUTE_SECONDS = 12 * 3600;

const DEVICE_COOKIE_NAME = 'aom_device';
const DEVICE_COOKIE_DAYS = 90;

const PASSWORD_MIN_CHARS = 10;
const PASSWORD_MAX_BYTES = 72; // bcrypt ignores anything longer, so longer passwords are refused
const TEMP_PASSWORD_CHARS = 12;

// Throttling: all counted over the last 15 minutes.
const THROTTLE_WINDOW_MINUTES      = 15;
const THROTTLE_PAIR_FAILURES       = 5;  // limit 1: username + IP → lockout
const THROTTLE_IP_FAILURES         = 20; // limit 2: one IP, any usernames → lockout
const THROTTLE_USERNAME_FAILURES   = 10; // limit 3: one username, all IPs → slowdown
const THROTTLE_SLOWDOWN_SECONDS    = 30; // limit 3: one accepted attempt per 30 s from unknown IPs/devices
const KNOWN_IP_DAYS                = 30;
const LOGIN_ATTEMPTS_KEEP_DAYS     = 90;

/** bcrypt hash of a random string: verified against when the username doesn't exist, so timing doesn't reveal it. */
const DUMMY_PASSWORD_HASH = '$2y$10$iv3PQD4UQdVPPd5Xgj9svuKafcrsWvH72XLPDN/1.lM4kTSpLFEcu';

// ---------------------------------------------------------------------------
// Transport and cookies
// ---------------------------------------------------------------------------

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Cookies are Secure on HTTPS and always in production; plain http://localhost needs them without it. */
function cookie_secure(): bool
{
    return is_https() || is_production();
}

function cookie_path(): string
{
    return rtrim((string) cfg('BASE_URL', ''), '/') . '/';
}

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

function start_app_session(): void
{
    ini_set('session.save_path', APP_ROOT . '/storage/sessions');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.gc_maxlifetime', (string) SESSION_ABSOLUTE_SECONDS);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => cookie_path(),
        'secure'   => cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['user_id'])) {
        $idle = $now - (int) ($_SESSION['last_seen'] ?? 0);
        $age = $now - (int) ($_SESSION['login_at'] ?? 0);
        if ($idle > SESSION_IDLE_SECONDS || $age > SESSION_ABSOLUTE_SECONDS) {
            end_login_session();
            flash('info', 'Your session expired. Please sign in again.');
        }
    }
    $_SESSION['last_seen'] = $now;
}

/** Log the user out but keep a fresh, empty session (so a flash message can still be shown). */
function end_login_session(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Start a logged-in session for $user (new session id: prevents session fixation). */
function begin_login_session(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['login_at'] = time();
    $_SESSION['last_seen'] = time();
}

// ---------------------------------------------------------------------------
// Current user (re-read from the database on every request)
// ---------------------------------------------------------------------------

/** The logged-in user's row as currently stored, or null. Set by load_current_user(). */
function current_user(): ?array
{
    return $GLOBALS['CURRENT_USER'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? null) === 'admin';
}

/**
 * Re-read the session's user from the database. A user who no longer exists, or whose status
 * is no longer 'active' (disabled by the admin), is logged out immediately.
 */
function load_current_user(PDO $pdo): ?array
{
    $GLOBALS['CURRENT_USER'] = null;
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $st = $pdo->prepare('SELECT id, username, role, name, firm_name, rep_name, contact, status, must_change_password, password_hash
                           FROM users WHERE id = ?');
    $st->execute([(int) $_SESSION['user_id']]);
    $user = $st->fetch() ?: null;
    if ($user === null || $user['status'] !== 'active') {
        end_login_session();
        flash('error', 'Your account is no longer active. Contact AO Mess if you think this is a mistake.');
        return null;
    }
    $GLOBALS['CURRENT_USER'] = $user;
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('auth/login.php');
    }
    return $user;
}

/** Admin-only page. Anyone else gets the plan's uniform 404. */
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        not_found();
    }
    return $user;
}

/** The request is for one of the pages a user with must_change_password = 1 may still open. */
function is_password_change_allowlisted(string $scriptName): bool
{
    $script = str_replace('\\', '/', $scriptName);
    foreach (['/auth/change_password.php', '/auth/logout.php'] as $allowed) {
        if (substr($script, -strlen($allowed)) === $allowed) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Usernames and passwords
// ---------------------------------------------------------------------------

function normalize_username(string $username): string
{
    return strtolower(trim($username));
}

/** 3–50 characters: lowercase letters, digits, dot, underscore, hyphen. */
function is_valid_username(string $username): bool
{
    return preg_match('/^[a-z0-9._-]{3,50}$/', $username) === 1;
}

/**
 * Rules for a new password. Returns a list of problems (empty = acceptable).
 *
 * @param string|null $current the current/temporary password, which the new one must differ from
 */
function password_problems(string $new, string $confirm, string $username, ?string $current = null): array
{
    $problems = [];
    if (mb_strlen($new, 'UTF-8') < PASSWORD_MIN_CHARS) {
        $problems[] = 'The password must be at least ' . PASSWORD_MIN_CHARS . ' characters.';
    }
    if (strlen($new) > PASSWORD_MAX_BYTES) {
        $problems[] = 'The password is too long (at most ' . PASSWORD_MAX_BYTES . ' characters).';
    }
    if ($new !== $confirm) {
        $problems[] = 'The two passwords don\'t match.';
    }
    if ($current !== null && $new === $current) {
        $problems[] = 'The new password must be different from the current one.';
    }
    if ($username !== '' && strtolower($new) === strtolower($username)) {
        $problems[] = 'The password can\'t be the same as the username.';
    }
    return $problems;
}

/** Random temporary password for admin resets (no look-alike characters such as 0/O, 1/l/I). */
function generate_temp_password(int $length = TEMP_PASSWORD_CHARS): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Device cookie: "a browser this user has logged in from before" (exempt from limit 3)
// ---------------------------------------------------------------------------

/**
 * userId.issuedAt.HMAC. The current password hash is part of the signed data, so changing or
 * resetting the password invalidates every device cookie for that user.
 */
function device_cookie_value(int $userId, string $passwordHash, string $secret, int $issuedAt): string
{
    $payload = $userId . '.' . $issuedAt;
    return $payload . '.' . hash_hmac('sha256', $payload . '|' . $passwordHash, $secret);
}

function device_cookie_is_valid(?string $cookie, int $userId, string $passwordHash, string $secret, int $now): bool
{
    if ($cookie === null || preg_match('/^(\d{1,10})\.(\d{1,12})\.([a-f0-9]{64})$/', $cookie, $m) !== 1) {
        return false;
    }
    $issuedAt = (int) $m[2];
    if ((int) $m[1] !== $userId || $issuedAt > $now + 300 || $now - $issuedAt > DEVICE_COOKIE_DAYS * 86400) {
        return false;
    }
    $expected = hash_hmac('sha256', $m[1] . '.' . $m[2] . '|' . $passwordHash, $secret);
    return hash_equals($expected, $m[3]);
}

function set_device_cookie(array $user): void
{
    $now = time();
    setcookie(DEVICE_COOKIE_NAME, device_cookie_value((int) $user['id'], $user['password_hash'], (string) cfg('DEVICE_COOKIE_SECRET'), $now), [
        'expires'  => $now + DEVICE_COOKIE_DAYS * 86400,
        'path'     => cookie_path(),
        'secure'   => cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ---------------------------------------------------------------------------
// Throttling (limits 1–3)
// ---------------------------------------------------------------------------

/**
 * Seconds until the Nth most recent failure (for the given filter) leaves the 15-minute window,
 * or 0 when fewer than $limit failures are in the window.
 */
function throttle_wait(PDO $pdo, string $where, array $params, int $limit): int
{
    $sql = "SELECT TIMESTAMPDIFF(SECOND, NOW(), attempted_at + INTERVAL " . THROTTLE_WINDOW_MINUTES . " MINUTE)
              FROM login_attempts
             WHERE $where AND success = 0 AND attempted_at > NOW() - INTERVAL " . THROTTLE_WINDOW_MINUTES . " MINUTE
             ORDER BY attempted_at DESC LIMIT 1 OFFSET " . ($limit - 1);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $wait = $st->fetchColumn();
    return $wait === false ? 0 : max(1, (int) $wait);
}

function is_known_ip(PDO $pdo, string $username, string $ip): bool
{
    $st = $pdo->prepare('SELECT 1 FROM login_attempts WHERE username = ? AND ip = ? AND success = 1
                            AND attempted_at > NOW() - INTERVAL ' . KNOWN_IP_DAYS . ' DAY LIMIT 1');
    $st->execute([$username, $ip]);
    return (bool) $st->fetchColumn();
}

/**
 * Check the three limits BEFORE the password is verified.
 *
 * @return array{limit: int, wait: int, message: string}|null null = the attempt may proceed
 */
function login_throttle(PDO $pdo, string $username, string $ip, bool $knownDevice): ?array
{
    $minutes = static fn(int $s) => max(1, (int) ceil($s / 60));

    // Limit 2: one IP, any usernames.
    if ($wait = throttle_wait($pdo, 'ip = ?', [$ip], THROTTLE_IP_FAILURES)) {
        return ['limit' => 2, 'wait' => $wait,
            'message' => 'Too many failed sign-ins from this network. Try again in ' . $minutes($wait) . ' minute(s).'];
    }
    // Limit 1: this username from this IP.
    if ($wait = throttle_wait($pdo, 'username = ? AND ip = ?', [$username, $ip], THROTTLE_PAIR_FAILURES)) {
        return ['limit' => 1, 'wait' => $wait,
            'message' => 'Too many failed sign-ins. Try again in ' . $minutes($wait) . ' minute(s).'];
    }
    // Limit 3: this username from many IPs → slow down unknown IPs and devices (never a lockout).
    if (!$knownDevice && throttle_wait($pdo, 'username = ?', [$username], THROTTLE_USERNAME_FAILURES) && !is_known_ip($pdo, $username, $ip)) {
        $st = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(attempted_at) + INTERVAL ' . THROTTLE_SLOWDOWN_SECONDS . ' SECOND)
                               FROM login_attempts la
                              WHERE la.username = ? AND la.attempted_at > NOW() - INTERVAL ' . THROTTLE_SLOWDOWN_SECONDS . ' SECOND
                                AND NOT EXISTS (SELECT 1 FROM login_attempts k
                                                 WHERE k.username = la.username AND k.ip = la.ip AND k.success = 1
                                                   AND k.attempted_at > NOW() - INTERVAL ' . KNOWN_IP_DAYS . ' DAY)');
        $st->execute([$username]);
        $wait = (int) $st->fetchColumn();
        if ($wait > 0) {
            return ['limit' => 3, 'wait' => $wait,
                'message' => "Too many attempts, try again in $wait seconds."];
        }
    }
    return null;
}

/** Usernames currently under limit 3 (for the admin banner). */
function login_attack_alerts(PDO $pdo): array
{
    return $pdo->query('SELECT username, COUNT(*) AS failures, COUNT(DISTINCT ip) AS ips
                          FROM login_attempts
                         WHERE success = 0 AND attempted_at > NOW() - INTERVAL ' . THROTTLE_WINDOW_MINUTES . ' MINUTE
                         GROUP BY username HAVING COUNT(*) >= ' . THROTTLE_USERNAME_FAILURES . '
                         ORDER BY failures DESC')->fetchAll();
}

function record_login_attempt(PDO $pdo, string $username, string $ip, bool $success): void
{
    $pdo->prepare('INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)')
        ->execute([substr($username, 0, 50), $ip, $success ? 1 : 0]);
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------

/**
 * Check a sign-in. Does not touch the session; the caller starts it on success.
 *
 * @return array{user: ?array, error: ?string}
 */
function attempt_login(PDO $pdo, string $rawUsername, string $password, string $ip, ?string $deviceCookie): array
{
    $username = normalize_username($rawUsername);
    if ($username === '' || $password === '') {
        return ['user' => null, 'error' => 'Enter your username and password.'];
    }

    $st = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $user = $st->fetch() ?: null;

    $knownDevice = $user !== null
        && device_cookie_is_valid($deviceCookie, (int) $user['id'], $user['password_hash'], (string) cfg('DEVICE_COOKIE_SECRET'), time());

    if ($block = login_throttle($pdo, $username, $ip, $knownDevice)) {
        // Logged in the audit log only: a throttled attempt must not extend any throttle window.
        audit($pdo, 'login_fail', $user ? (int) $user['id'] : null, null,
            ['username' => $username, 'reason' => 'throttled', 'limit' => $block['limit']]);
        return ['user' => null, 'error' => $block['message']];
    }

    $passwordOk = password_verify($password, $user['password_hash'] ?? DUMMY_PASSWORD_HASH) && $user !== null;
    if (!$passwordOk) {
        record_login_attempt($pdo, $username, $ip, false);
        audit($pdo, 'login_fail', $user ? (int) $user['id'] : null, null, ['username' => $username, 'reason' => 'wrong password']);
        return ['user' => null, 'error' => 'Wrong username or password.'];
    }

    if ($user['status'] !== 'active') {
        audit($pdo, 'login_fail', (int) $user['id'], null, ['username' => $username, 'reason' => 'account ' . $user['status']]);
        return ['user' => null, 'error' => $user['status'] === 'pending'
            ? 'Your account is waiting for approval by AO Mess. You can sign in once it has been approved.'
            : 'This account has been disabled. Contact AO Mess if you think this is a mistake.'];
    }

    record_login_attempt($pdo, $username, $ip, true);
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL ' . LOGIN_ATTEMPTS_KEEP_DAYS . ' DAY');

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$user['password_hash'], $user['id']]);
    }
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    audit($pdo, 'login_ok', (int) $user['id'], null, ['username' => $username, 'known_device' => $knownDevice]);

    return ['user' => $user, 'error' => null];
}
