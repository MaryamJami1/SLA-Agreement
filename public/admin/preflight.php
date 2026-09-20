<?php
/**
 * Deployment pre-flight (plan Section 15). Admin-only page that checks the server the app is actually
 * running on: PHP, configuration, folders, database and the seeded admin password.
 *
 * Run it right after deploying, and again after any hosting change.
 */
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/attachments.php';

$admin = require_admin();
$pdo = db();

$checks = [];
/** @param string $state 'pass' | 'warn' | 'fail' */
$add = static function (string $group, string $name, string $state, string $detail) use (&$checks): void {
    $checks[$group][] = ['name' => $name, 'state' => $state, 'detail' => $detail];
};
$yesNo = static fn(bool $ok, string $group, string $name, string $whenOk, string $whenNot, string $state = 'fail') =>
    $add($group, $name, $ok ? 'pass' : $state, $ok ? $whenOk : $whenNot);

// --- PHP -------------------------------------------------------------------
$yesNo(PHP_VERSION_ID >= 80100, 'PHP', 'Version 8.1 or newer', 'PHP ' . PHP_VERSION, 'PHP ' . PHP_VERSION . ' is too old; set 8.2 in hPanel');
foreach (['pdo_mysql', 'fileinfo', 'mbstring', 'session', 'json'] as $ext) {
    $yesNo(extension_loaded($ext), 'PHP', "Extension $ext", 'loaded', 'missing — enable it in hPanel');
}
$upload = (int) ini_get('upload_max_filesize');
$post = (int) ini_get('post_max_size');
$yesNo($upload >= 6, 'PHP', 'upload_max_filesize ≥ 6M', ini_get('upload_max_filesize'), ini_get('upload_max_filesize') . ' — .user.ini may not be applied', 'warn');
$yesNo($post >= 8, 'PHP', 'post_max_size ≥ 8M', ini_get('post_max_size'), ini_get('post_max_size') . ' — .user.ini may not be applied', 'warn');
// Showing errors on screen is intended locally; in production it must be off.
$yesNo(!filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOL), 'PHP', 'display_errors off', 'off',
    'on — fine locally, but errors must never be shown on the live site', is_production() ? 'fail' : 'warn');

// --- Configuration ---------------------------------------------------------
$isProd = is_production();
$add('Configuration', 'APP_ENV', $isProd ? 'pass' : 'warn', $isProd ? 'production' : (string) cfg('APP_ENV') . ' — set to production on the live site');
$secret = (string) cfg('DEVICE_COOKIE_SECRET', '');
$yesNo(strlen($secret) >= 32 && $secret !== 'change-me', 'Configuration', 'DEVICE_COOKIE_SECRET', strlen($secret) . ' characters', 'too short or still the sample value');
$canonical = trim((string) cfg('CANONICAL_HOST', ''));
$yesNo(!$isProd || $canonical !== '', 'Configuration', 'CANONICAL_HOST',
    $canonical !== '' ? $canonical : 'not set (only needed in production)',
    'empty — set it so the HTTPS redirect can\'t be pointed elsewhere', 'warn');
$yesNo(!cfg('ALLOW_APP_IN_WEBROOT', false), 'Configuration', 'App code above the web root', 'app/ is outside the web root', 'ALLOW_APP_IN_WEBROOT is on — only use this with the deny-all .htaccess files', 'warn');
$yesNo((bool) cfg('REQUIRE_SIGNED_COPY_FOR_AMENDMENT', true), 'Configuration', 'Signed copy required before amending', 'on', 'off — amendments of signed agreements need no scan', 'warn');
foreach (['ORG_ADDRESS', 'ORG_PHONE', 'ORG_EMAIL'] as $field) {
    $yesNo(trim((string) cfg($field, '')) !== '', 'Configuration', "Letterhead $field", (string) cfg($field), 'empty — documents print without it', 'warn');
}

// --- Transport and session -------------------------------------------------
$yesNo(is_https(), 'Security', 'HTTPS', 'this page was served over HTTPS', 'this page is not HTTPS — enable SSL in hPanel', $isProd ? 'fail' : 'warn');
$yesNo(cookie_secure() || !$isProd, 'Security', 'Secure cookies', 'session cookie is marked Secure', 'cookies are not Secure');
$savePath = ini_get('session.save_path');
$yesNo(str_contains((string) $savePath, 'storage'), 'Security', 'Private session folder', (string) $savePath, 'sessions are in the server default folder', 'warn');

// --- Folders ---------------------------------------------------------------
foreach (['storage/uploads', 'storage/logs', 'storage/sessions'] as $dir) {
    $path = APP_ROOT . '/' . $dir;
    $yesNo(is_dir($path) && is_writable($path), 'Folders', $dir, 'exists and is writable', 'missing or not writable — create it and allow writing');
}
$docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
$yesNo($docRoot !== '' && !path_is_inside(APP_ROOT . '/app', $docRoot), 'Folders', 'app/ outside the document root',
    'app/ is not reachable from the web', 'app/ sits inside ' . $docRoot . ' — move it above public_html');
$yesNo(is_file($docRoot . '/.htaccess'), 'Folders', '.htaccess in the web root', 'present', 'missing — upload public/.htaccess', 'warn');
$yesNo(is_file($docRoot . '/.user.ini'), 'Folders', '.user.ini in the web root', 'present', 'missing — upload public/.user.ini', 'warn');

// --- Database --------------------------------------------------------------
$tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
$yesNo($tables >= 11, 'Database', 'All tables imported', "$tables tables", "$tables tables — import database/schema.sql");
$notInnoDb = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB'")->fetchColumn();
$yesNo($notInnoDb === 0, 'Database', 'InnoDB everywhere', 'all tables use InnoDB', "$notInnoDb table(s) are not InnoDB — transactions would not work");
$version = (int) $pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_version')->fetchColumn();
$yesNo($version >= 1, 'Database', 'Schema version recorded', "version $version", 'no schema_version row — re-import schema.sql');
$tz = (string) $pdo->query('SELECT @@session.time_zone')->fetchColumn();
$yesNo($tz === '+05:00', 'Database', 'Connection time zone', $tz, "$tz — expected +05:00");
$sqlMode = (string) $pdo->query('SELECT @@session.sql_mode')->fetchColumn();
$yesNo(str_contains($sqlMode, 'STRICT'), 'Database', 'Strict mode', 'strict mode on', 'not strict — out-of-range values could be silently changed');
$yesNo(date_default_timezone_get() === 'Asia/Karachi', 'Database', 'PHP time zone', date_default_timezone_get(), date_default_timezone_get() . ' — expected Asia/Karachi');

// --- Accounts --------------------------------------------------------------
$seed = $pdo->query("SELECT password_hash, must_change_password FROM users WHERE username = 'admin'")->fetch();
if ($seed) {
    $yesNo(!password_verify('ChangeMe-2026', $seed['password_hash']), 'Accounts', 'Default admin password changed',
        'the seeded password is no longer in use', 'the seeded password ChangeMe-2026 still works — sign in and change it now');
}
$admins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
$yesNo($admins >= 1, 'Accounts', 'An active admin exists', "$admins active admin(s)", 'no active admin — you would be locked out');
$pending = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'vendor' AND status = 'pending'")->fetchColumn();
$add('Accounts', 'Vendors waiting for approval', $pending > 0 ? 'warn' : 'pass', $pending > 0 ? "$pending waiting" : 'none waiting');

$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
foreach ($checks as $group) {
    foreach ($group as $check) {
        $counts[$check['state']]++;
    }
}

$pageTitle = 'Deployment checks';
$activeTab = '';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Deployment checks</h2>
  <span class="muted"><?= $counts['pass'] ?> passed, <?= $counts['warn'] ?> warning(s), <?= $counts['fail'] ?> problem(s)</span>
</div>
<p class="muted">Checks the server this page is running on. Run it after deploying and after any hosting change.
  Things it can't check from here — that <code>/app/</code>, <code>/config/</code> and <code>/storage/</code> are unreachable
  from a browser, and that printing looks right — are in the go-live checklist (<code>docs/GO_LIVE_CHECKLIST.md</code>).</p>

<?php foreach ($checks as $group => $items): ?>
<div class="card pad">
  <h3><?= h(strtoupper($group)) ?></h3>
  <table class="lines-table">
    <tbody>
<?php foreach ($items as $check): ?>
      <tr>
        <td class="preflight-state"><span class="badge state-<?= h($check['state']) ?>"><?= h(strtoupper($check['state'])) ?></span></td>
        <td><?= h($check['name']) ?></td>
        <td class="muted"><?= h($check['detail']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
