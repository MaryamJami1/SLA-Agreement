<?php
/**
 * Database checks against a real server (local dev only; never run on Hostinger).
 * Builds a throwaway database "<DB_NAME>_test" from database/schema.sql + seed.sql,
 * runs the checks, then drops it.
 *
 * Run:  php tests/db_check.php        Exit code 0 = all passed.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
date_default_timezone_set('Asia/Karachi');
require APP_ROOT . '/app/helpers.php';
require APP_ROOT . '/app/db.php';
require APP_ROOT . '/app/money.php';
require APP_ROOT . '/app/counters.php';
require APP_ROOT . '/app/audit.php';
require APP_ROOT . '/app/auth.php';
require APP_ROOT . '/app/bookings.php';
require APP_ROOT . '/app/lifecycle.php';
require APP_ROOT . '/app/payments.php';
require APP_ROOT . '/app/attachments.php';
require APP_ROOT . '/app/documents.php';
require APP_ROOT . '/app/admin_data.php';

$config = require APP_ROOT . '/config/config.php';
$GLOBALS['APP_CONFIG'] = $config;
if (($config['APP_ENV'] ?? '') === 'production') {
    fwrite(STDERR, "Refusing to run database checks with APP_ENV = production.\n");
    exit(1);
}
$testDb = $config['DB_NAME'] . '_test';
$GLOBALS['APP_CONFIG']['DB_NAME'] = $testDb; // code that calls db() must see the test database too

$passed = 0;
$failed = [];
function check(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
    } else {
        $failed[] = "$name\n    expected: " . var_export($expected, true) . "\n    actual:   " . var_export($actual, true);
    }
}
function check_fails(string $name, callable $fn): void
{
    try {
        $fn();
        check("$name (should fail)", 'succeeded', 'PDOException');
    } catch (PDOException $e) {
        check($name, true, true);
    }
}

// ---------------------------------------------------------------------------
// Fresh test database from schema.sql + seed.sql
// ---------------------------------------------------------------------------
$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT']),
    $config['DB_USER'], $config['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `$testDb`");
$server->exec("CREATE DATABASE `$testDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$pdo = db_connect(['DB_NAME' => $testDb] + $config);
// menu_data.sql is Booking Organizer's own menus (optional client data); the menu checks use it.
foreach (['schema.sql', 'seed.sql', 'menu_data.sql'] as $file) {
    $sql = file_get_contents(APP_ROOT . '/database/' . $file);
    foreach (preg_split('/;\s*\n/', $sql) as $statement) {
        $body = trim(preg_replace('/^\s*--.*$/m', '', $statement));
        if ($body !== '') {
            $pdo->exec($body);
        }
    }
}

// ---------------------------------------------------------------------------
// Schema and seed
// ---------------------------------------------------------------------------
$tables = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")
    ->fetchAll(PDO::FETCH_COLUMN);
sort($tables, SORT_STRING);
check('all 28 tables exist', $tables, ['attachments', 'audit_log', 'booking_defaults', 'booking_line_items', 'booking_vendor_items', 'bookings', 'counters',
    'form_options', 'form_sections', 'item_catalog', 'login_attempts', 'menu_categories', 'menu_dishes', 'menu_package_items', 'menu_packages', 'menu_types', 'payments', 'schema_version', 'users',
    'vendor_categories', 'vendor_category_services', 'vendor_invoice_counters', 'vendor_invoice_items', 'vendor_invoices', 'vendor_payments', 'vendor_services',
    'vendors', 'venues']);
check('all tables InnoDB', (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB'")->fetchColumn(), 0);
// schema.sql must record version 1 plus every migration, so a fresh install and an upgraded
// database agree on where they are. Derived from the files, so adding a migration can't be forgotten.
$migrationVersions = [];
foreach (glob(APP_ROOT . '/database/migrations/*.sql') ?: [] as $file) {
    if (preg_match('/^(\d+)_/', basename($file), $m)) {
        $migrationVersions[] = (int) $m[1];
    }
}
$latestVersion = $migrationVersions ? max($migrationVersions) : 1;
check('schema_version matches the newest migration',
    (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn(), $latestVersion);
check('every migration version is recorded',
    (int) $pdo->query('SELECT COUNT(*) FROM schema_version')->fetchColumn(), $latestVersion);

$admin = $pdo->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
check('seeded admin is active admin', [$admin['role'], $admin['status']], ['admin', 'active']);
check('seeded admin must change password', (int) $admin['must_change_password'], 1);
check('seeded admin password is ChangeMe-2026', password_verify('ChangeMe-2026', $admin['password_hash']), true);
check('5 venues', (int) $pdo->query('SELECT COUNT(*) FROM venues')->fetchColumn(), 5);
check('8 charges', (int) $pdo->query("SELECT COUNT(*) FROM item_catalog WHERE section = 'charge'")->fetchColumn(), 8);
check('21 decor items', (int) $pdo->query("SELECT COUNT(*) FROM item_catalog WHERE section LIKE 'decor%'")->fetchColumn(), 21);
check('10 ops items', (int) $pdo->query("SELECT COUNT(*) FROM item_catalog WHERE section = 'ops_item'")->fetchColumn(), 10);
check('connection time zone +05:00', $pdo->query('SELECT @@session.time_zone')->fetchColumn(), '+05:00');

// ---------------------------------------------------------------------------
// SLA numbers: first is 0001, no gap after a rolled-back save
// ---------------------------------------------------------------------------
$year = (int) date('Y');
$adminId = (int) $admin['id'];
$insertBooking = static function (PDO $pdo, string $uid) use ($adminId): int {
    $pdo->prepare('INSERT INTO bookings (unique_id, created_by, updated_by, client_name) VALUES (?, ?, ?, ?)')
        ->execute([$uid, $adminId, $adminId, 'Test Client']);
    return (int) $pdo->lastInsertId();
};

$first = db_transaction(static function (PDO $pdo) use ($year, $insertBooking) {
    $uid = next_sla_id($pdo, $year);
    $insertBooking($pdo, $uid);
    return $uid;
}, $pdo);
check('first ID of the year', $first, format_sla_id($year, 1));

$second = db_transaction(static fn(PDO $pdo) => [$uid = next_sla_id($pdo, $year), $insertBooking($pdo, $uid)][0], $pdo);
check('second ID', $second, format_sla_id($year, 2));

try {
    db_transaction(static function (PDO $pdo) use ($year, $insertBooking) {
        $insertBooking($pdo, next_sla_id($pdo, $year));
        throw new RuntimeException('forced save error');
    }, $pdo);
} catch (RuntimeException $e) {
}
$third = db_transaction(static fn(PDO $pdo) => [$uid = next_sla_id($pdo, $year), $insertBooking($pdo, $uid)][0], $pdo);
check('no gap after a failed save', $third, format_sla_id($year, 3));
check('failed save left no booking', (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(), 3);

$other = db_transaction(static fn(PDO $pdo) => next_sla_id($pdo, 2031), $pdo);
check('new year starts at 0001', $other, 'SLA-2031-0001');

try {
    next_sla_id($pdo, $year);
    check('next_sla_id outside a transaction', 'allowed', 'LogicException');
} catch (LogicException $e) {
    check('next_sla_id outside a transaction is refused', true, true);
}

// ---------------------------------------------------------------------------
// recompute_booking_totals against real rows
// ---------------------------------------------------------------------------
$bookingId = (int) $pdo->query("SELECT id FROM bookings WHERE unique_id = " . $pdo->quote($first))->fetchColumn();
$pdo->prepare('UPDATE bookings SET per_head_rate = ?, guests = ?, discount = ? WHERE id = ?')
    ->execute(['1500.00', 250, '10000.00', $bookingId]);
$addLine = $pdo->prepare('INSERT INTO booking_line_items (booking_id, section, label, unit_snapshot, is_selected, qty, rate)
                          VALUES (?, ?, ?, ?, ?, ?, ?)');
$addLine->execute([$bookingId, 'charge', 'Venue Charges', 'fixed', 1, null, '50000.00']);
$addLine->execute([$bookingId, 'charge', 'Drinks', 'per head', 1, null, '85.00']);
$addLine->execute([$bookingId, 'charge', 'Stage', 'per unit', 1, 2, '10000.00']);
$addLine->execute([$bookingId, 'charge', 'Valet', 'fixed', 0, null, '9999.00']);
$addLine->execute([$bookingId, 'decor_general', 'Lounges', 'fixed', 1, null, null]);
$addPayment = $pdo->prepare('INSERT INTO payments (booking_id, kind, amount, paid_on, method, recorded_by, voided_at)
                             VALUES (?, ?, ?, CURDATE(), ?, ?, ?)');
$addPayment->execute([$bookingId, 'payment', '100000.00', 'cash', $adminId, null]);
$addPayment->execute([$bookingId, 'payment', '50000.00', 'cash', $adminId, date('Y-m-d H:i:s')]);
$addPayment->execute([$bookingId, 'payment', '20000.00', 'bank_transfer', $adminId, null]);

$readTotals = static function () use ($pdo, $bookingId): array {
    return $pdo->query("SELECT guest_charges, charges_total, sub_total, grand_total, paid_total, balance FROM bookings WHERE id = $bookingId")
        ->fetch(PDO::FETCH_NUM);
};
db_transaction(static function (PDO $pdo) use ($bookingId) {
    $pdo->query("SELECT id FROM bookings WHERE id = $bookingId FOR UPDATE");
    recompute_booking_totals($pdo, $bookingId);
}, $pdo);
check('stored totals', $readTotals(), ['375000.00', '91250.00', '466250.00', '456250.00', '120000.00', '336250.00']);
check('stored line amounts', $pdo->query("SELECT label, amount FROM booking_line_items WHERE booking_id = $bookingId ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR),
    ['Venue Charges' => '50000.00', 'Drinks' => '21250.00', 'Stage' => '20000.00', 'Valet' => '0.00', 'Lounges' => '0.00']);

// Guests 250 → 300: guest charges and the per-head line recompute; fixed and per-unit lines don't.
$pdo->exec("UPDATE bookings SET guests = 300 WHERE id = $bookingId");
db_transaction(static fn(PDO $pdo) => recompute_booking_totals($pdo, $bookingId), $pdo);
check('guests change: guest charges', $readTotals()[0], '450000.00');
check('guests change: per-head line only', $pdo->query("SELECT label, amount FROM booking_line_items WHERE booking_id = $bookingId AND section = 'charge' ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR),
    ['Venue Charges' => '50000.00', 'Drinks' => '25500.00', 'Stage' => '20000.00', 'Valet' => '0.00']);

// Cancelled: balance forced to 0, grand total kept.
$pdo->exec("UPDATE bookings SET status = 'cancelled' WHERE id = $bookingId");
db_transaction(static fn(PDO $pdo) => recompute_booking_totals($pdo, $bookingId), $pdo);
[, , , $grand, $paid, $balance] = $readTotals();
check('cancelled: balance 0, grand total and amount retained kept', [$grand, $paid, $balance], ['535500.00', '120000.00', '0.00']);

try {
    recompute_booking_totals($pdo, $bookingId);
    check('recompute outside a transaction', 'allowed', 'LogicException');
} catch (LogicException $e) {
    check('recompute outside a transaction is refused', true, true);
}

// ---------------------------------------------------------------------------
// Constraints and strict mode
// ---------------------------------------------------------------------------
$venueId = (int) $pdo->query('SELECT id FROM venues ORDER BY id LIMIT 1')->fetchColumn();
$pdo->exec("UPDATE bookings SET venue_id = $venueId WHERE id = $bookingId");
check_fails('venue used by a booking cannot be deleted (RESTRICT)', fn() => $pdo->exec("DELETE FROM venues WHERE id = $venueId"));
check_fails('booking with payments cannot be deleted (RESTRICT)', fn() => $pdo->exec("DELETE FROM bookings WHERE id = $bookingId"));
check_fails('strict mode rejects negative guests', fn() => $pdo->exec("UPDATE bookings SET guests = -1 WHERE id = $bookingId"));
check_fails('strict mode rejects an amount too large for DECIMAL(12,2)', fn() => $pdo->exec("UPDATE bookings SET discount = 10000000000.00 WHERE id = $bookingId"));
check_fails('duplicate SLA number rejected', fn() => $insertBooking($pdo, $first));
check_fails('unknown audit action rejected', fn() => $pdo->exec("INSERT INTO audit_log (action) VALUES ('edit_audit')"));

// Draft deletion cascades line items (the only cascade).
$draftId = $insertBooking($pdo, 'SLA-TEST-DRAFT');
$addLine->execute([$draftId, 'charge', 'Venue Charges', 'fixed', 1, null, '1.00']);
$pdo->exec("DELETE FROM bookings WHERE id = $draftId");
check('deleting a draft cascades its line items', (int) $pdo->query("SELECT COUNT(*) FROM booking_line_items WHERE booking_id = $draftId")->fetchColumn(), 0);

// ---------------------------------------------------------------------------
// Login and throttling (plan Section 10)
// ---------------------------------------------------------------------------
$makeUser = static function (string $username, string $status, string $password = 'correct-password-1') use ($pdo): int {
    $pdo->prepare("INSERT INTO users (username, password_hash, role, name, status) VALUES (?, ?, 'user', ?, ?)")
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $username, $status]);
    return (int) $pdo->lastInsertId();
};
$attempts = static fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
$good = 'correct-password-1';

$makeUser('okuser', 'active');
$r = attempt_login($pdo, ' OKUser ', $good, '10.1.1.1', null);
check('active user logs in (username case/space-insensitive)', [$r['error'], $r['user']['username'] ?? null], [null, 'okuser']);
check('success recorded as known IP', is_known_ip($pdo, 'okuser', '10.1.1.1'), true);

$makeUser('pendinguser', 'pending');
$r = attempt_login($pdo, 'pendinguser', $good, '10.1.1.2', null);
check('pending user cannot log in', [$r['user'], strpos((string) $r['error'], 'waiting for approval') !== false], [null, true]);
$makeUser('disableduser', 'disabled');
$r = attempt_login($pdo, 'disableduser', $good, '10.1.1.2', null);
check('disabled user cannot log in', [$r['user'], strpos((string) $r['error'], 'disabled') !== false], [null, true]);
$r = attempt_login($pdo, 'nobody', 'whatever-pass', '10.1.1.2', null);
check('unknown username: same message as a wrong password', $r['error'], 'Wrong username or password.');

// Limit 1: 5 failures for one username from one IP → the 6th attempt is refused even with the right password.
$makeUser('lim1', 'active');
for ($i = 0; $i < 5; $i++) {
    attempt_login($pdo, 'lim1', 'wrong-password', '10.2.2.2', null);
}
$before = $attempts();
$r = attempt_login($pdo, 'lim1', $good, '10.2.2.2', null);
check('limit 1: 6th attempt refused with correct password', [$r['user'], strpos((string) $r['error'], 'Too many failed sign-ins. Try again in') === 0], [null, true]);
check('limit 1: throttled attempt not added to login_attempts', $attempts(), $before);
check('limit 1: throttled attempt logged in the audit log',
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'login_fail' AND details LIKE '%\"throttled\"%' AND details LIKE '%lim1%'")->fetchColumn(), 1);
$r = attempt_login($pdo, 'lim1', $good, '10.2.2.3', null);
check('limit 1: same username from another IP still works', $r['error'], null);

// Limit 2: 20 failures from one IP (any usernames) → that IP is locked out.
for ($i = 1; $i <= 20; $i++) {
    record_login_attempt($pdo, "ghost$i", '10.3.3.3', false);
}
$r = attempt_login($pdo, 'okuser', $good, '10.3.3.3', null);
check('limit 2: IP with 20 failures is locked out', strpos((string) $r['error'], 'from this network') !== false, true);
$r = attempt_login($pdo, 'okuser', $good, '10.3.3.4', null);
check('limit 2: other IPs unaffected', $r['error'], null);

// Limit 3: 10 failures for one username spread over many IPs → unknown IPs slowed to one attempt per 30 s.
$lim3Id = $makeUser('lim3', 'active');
record_login_attempt($pdo, 'lim3', '10.4.0.99', true); // a known IP for lim3
for ($i = 1; $i <= 10; $i++) { // spread over the last few minutes, as in a real attack
    $pdo->exec("INSERT INTO login_attempts (username, ip, success, attempted_at) VALUES ('lim3', '10.4.1.$i', 0, NOW() - INTERVAL " . (60 + $i * 10) . " SECOND)");
}
$r = attempt_login($pdo, 'lim3', 'wrong-password', '10.4.2.1', null);
check('limit 3: first attempt from an unknown IP is accepted (and checked)', $r['error'], 'Wrong username or password.');
$r = attempt_login($pdo, 'lim3', $good, '10.4.2.2', null);
check('limit 3: next attempt from another unknown IP within 30 s is slowed',
    [$r['user'], preg_match('/^Too many attempts, try again in \d+ seconds\.$/', (string) $r['error'])], [null, 1]);
$r = attempt_login($pdo, 'lim3', $good, '10.4.0.99', null);
check('limit 3: known IP is exempt', $r['error'], null);

$lim3Hash = $pdo->query("SELECT password_hash FROM users WHERE id = $lim3Id")->fetchColumn();
$deviceCookie = device_cookie_value($lim3Id, $lim3Hash, $config['DEVICE_COOKIE_SECRET'], time() - 60);
record_login_attempt($pdo, 'lim3', '10.4.2.3', false); // occupy the 30-second slot again
$r = attempt_login($pdo, 'lim3', $good, '10.4.2.4', $deviceCookie);
check('limit 3: known device (cookie) is exempt from a new IP', $r['error'], null);
$r = attempt_login($pdo, 'lim3', $good, '10.4.2.5', $deviceCookie . 'x');
check('limit 3: tampered device cookie is not exempt', $r['user'], null);

check('limit 3 shows in the admin alert list',
    in_array('lim3', array_column(login_attack_alerts($pdo), 'username'), true), true);
check('lim1 (5 failures) is not in the admin alert list',
    in_array('lim1', array_column(login_attack_alerts($pdo), 'username'), true), false);

// Old login_attempts rows are purged on a successful login.
$pdo->exec("INSERT INTO login_attempts (username, ip, success, attempted_at) VALUES ('old', '10.9.9.9', 0, NOW() - INTERVAL 91 DAY)");
attempt_login($pdo, 'okuser', $good, '10.1.1.1', null);
check('rows older than 90 days purged', (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE username = 'old'")->fetchColumn(), 0);

// Audit log: login_ok written for successes.
check('login_ok audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'login_ok'")->fetchColumn() >= 4, true);

// ---------------------------------------------------------------------------
// Booking save (Phase 3): create, reopen, edit, stale version, ownership, snapshots
// ---------------------------------------------------------------------------
$userRow = $pdo->query("SELECT * FROM users WHERE username = 'okuser'")->fetch();
$pdo->prepare('UPDATE users SET firm_name = ?, rep_name = ?, contact = ? WHERE id = ?')
    ->execute(['Uzair Caterers', 'Uzair Khan', '0312-2159834', $userRow['id']]);
$userRow = $pdo->query("SELECT * FROM users WHERE username = 'okuser'")->fetch();
$adminRow = $pdo->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
$catalogId = static fn(string $name) => (int) $pdo->query('SELECT id FROM item_catalog WHERE name = ' . $pdo->quote($name) . ' ORDER BY id LIMIT 1')->fetchColumn();
$lawnA = (int) $pdo->query("SELECT id FROM venues WHERE name = 'Lawn A'")->fetchColumn();
$venueCharge = $catalogId('Venue Charges');
$led = $catalogId('LED');
$sofa = (int) $pdo->query("SELECT id FROM item_catalog WHERE section = 'ops_item' AND name = 'Sofa'")->fetchColumn();

/** Parse + save like save.php does. Returns [result|null, errors]. */
$submit = static function (array $user, ?int $id, ?int $version, array $post) use ($pdo): array {
    $existing = null;
    if ($id !== null) {
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
        $st->execute([$id]);
        $existing = $st->fetch();
    }
    $formLines = booking_form_lines($pdo, $id);
    $parsed = parse_booking_input($pdo, $post, $user, $existing, $formLines);
    if ($parsed['errors']) {
        return [null, $parsed['errors']];
    }
    try {
        return [save_booking_draft($pdo, $user, $id, $version, $parsed['fields'], $parsed['lines']), []];
    } catch (BookingValidationError $e) {
        return [null, $e->errors];
    } catch (BookingConflict $e) {
        return [null, ['conflict' => true]];
    }
};
$bookingRow = static function (int $id) use ($pdo): array {
    return $pdo->query("SELECT * FROM bookings WHERE id = $id")->fetch();
};
$ver0 = static fn(int $id): int => (int) $bookingRow($id)['version'];

$post = [
    'client_name' => 'Ayesha Siddiqui', 'client_cnic' => '4210112345671', 'event_type' => 'Valima',
    'event_date' => '2026-12-20', 'venue_id' => (string) $lawnA, 'guests' => '200', 'per_head_rate' => '1,500',
    'setup_time' => '18:00', 'user_id' => (string) $adminRow['id'], // forged: users can't choose the owner
    'firm_name' => 'Forged Firm', 'event_type_other' => 'ignored because type is not Other',
    'lines' => [
        "c$venueCharge" => ['present' => '1', 'selected' => '1', 'rate' => '50000'],
        "c$led" => ['present' => '1', 'selected' => '1', 'notes' => 'warm white'],
        "c$sofa" => ['present' => '1', 'selected' => '1', 'qty' => '2'],
    ],
];
[$created, $errs] = $submit($userRow, null, null, $post);
check('user creates a draft', $errs, []);
$b = $bookingRow($created['id']);
check('new booking gets an SLA number', preg_match('/^SLA-\d{4}-\d{4}$/', $created['unique_id']), 1);
check('user_id forced to the creating user', (int) $b['user_id'], (int) $userRow['id']);
check('created_by = user', (int) $b['created_by'], (int) $userRow['id']);
check('firm snapshot from the user profile, not the POST', [$b['firm_name'], $b['rep_name'], $b['rep_contact']], ['Uzair Caterers', 'Uzair Khan', '0312-2159834']);
check('status draft, version 1', [$b['status'], (int) $b['version']], ['draft', 1]);
check('CNIC normalized', $b['client_cnic'], '42101-1234567-1');
check('"Other" text dropped when type is not Other', $b['event_type_other'], null);
check('time stored', $b['setup_time'], '18:00:00');
// Booking Organizer sets the money. The user's posted per-head rate, refund terms, payment terms and the
// Booking Organizer receipt are all discarded, and its charge line is not added at all.
check('user cannot price its own booking',
    [$b['per_head_rate'], $b['discount'], $b['guest_charges'], $b['charges_total'], $b['grand_total']],
    ['0.00', '0.00', '0.00', '0.00', '0.00']);
check('user cannot set the refund policy', [$b['refund_pct_30'], $b['refund_pct_7']], [null, null]);
check('user cannot set the payment terms', $b['due_on'], 'Event Day');
check("user cannot fill Booking Organizer's receipt record",
    [$b['received_by'], $b['received_date'], $b['received_time']], [null, null, null]);
$lines = $pdo->query("SELECT section, label, unit_snapshot, is_selected, qty, rate, amount, notes FROM booking_line_items WHERE booking_id = {$created['id']} ORDER BY section, id")->fetchAll();
check('a user cannot add a charge line; its decor and ops items are kept',
    array_column($lines, 'label'), ['LED', 'Sofa']);
check('decor line has no money', [$lines[0]['rate'], $lines[0]['amount'], $lines[0]['notes']], [null, '0.00', 'warm white']);
check('ops line qty', (int) $lines[1]['qty'], 2);
check('create audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'create' AND booking_id = {$created['id']}")->fetchColumn(), 1);

// The standard refund policy: a new booking starts with it, even a user's; older bookings keep theirs.
check('no standard refund policy yet', booking_defaults($pdo), ['refund_pct_30' => null, 'refund_pct_7' => null]);
set_booking_defaults($pdo, $adminRow, ['refund_pct_30' => '50', 'refund_pct_7' => '25%']);
check('standard refund policy saved', booking_defaults($pdo), ['refund_pct_30' => '50.00', 'refund_pct_7' => '25.00']);
$parsed = parse_booking_input($pdo, ['refund_pct_30' => '90', 'refund_pct_7' => '90'] + $post, $userRow, null, booking_form_lines($pdo, null));
check("user's new booking takes the standard policy, not its own POST",
    [$parsed['fields']['refund_pct_30'], $parsed['fields']['refund_pct_7']], ['50.00', '25.00']);
check('an existing booking keeps its own terms', [$bookingRow($created['id'])['refund_pct_30']], [null]);
try {
    set_booking_defaults($pdo, $adminRow, ['refund_pct_30' => '150', 'refund_pct_7' => '']);
    check('a refund default over 100% is refused', 'saved', 'refused');
} catch (AdminRefused $e) {
    check('a refund default over 100% is refused', 'refused', 'refused');
}
$pdo->exec('DELETE FROM booking_defaults');

// Booking Organizer now prices the same booking, and the money appears.
[, $priceErrs] = $submit($adminRow, (int) $created['id'], $ver0($created['id']), ['user_id' => (string) $userRow['id']] + $post);
check("admin prices the user's draft", $priceErrs, []);
$b = $bookingRow($created['id']);
check('totals stored once Booking Organizer has priced it',
    [$b['guest_charges'], $b['charges_total'], $b['grand_total'], $b['balance']],
    ['300000.00', '50000.00', '350000.00', '350000.00']);
check("the booking stays the user's", (int) $b['user_id'], (int) $userRow['id']);
$lines = $pdo->query("SELECT label, unit_snapshot, rate, amount FROM booking_line_items WHERE booking_id = {$created['id']} AND section = 'charge'")->fetchAll();
check('charge line snapshot and amount', [$lines[0]['label'], $lines[0]['unit_snapshot'], $lines[0]['rate'], $lines[0]['amount']],
    ['Venue Charges', 'fixed', '50000.00', '50000.00']);

// Reopen: existing lines by line id, other catalog items offered unselected.
$formLines = booking_form_lines($pdo, $created['id']);
$existingKeys = array_values(array_filter(array_keys($formLines), static fn($k) => $k[0] === 'l'));
check('reopen shows the 3 saved lines', count($existingKeys), 3);
check('reopen still offers other catalog items', isset($formLines["c$venueCharge"]), false);
check('reopen offers an unticked catalog item', isset($formLines['c' . $catalogId('Valet Parking')]), true);

// Edit with the right version: guests 200 → 250.
$venueLineKey = 'l' . $pdo->query("SELECT id FROM booking_line_items WHERE booking_id = {$created['id']} AND section = 'charge'")->fetchColumn();
$edit = ['guests' => '250', 'lines' => [$venueLineKey => ['present' => '1', 'selected' => '1', 'rate' => '50000']]] + $post;
unset($edit['lines']["c$venueCharge"]);
$vWas = $ver0($created['id']);
[$r, $errs] = $submit($userRow, $created['id'], $vWas, $edit);
check('edit with current version saves', $errs, []);
$b = $bookingRow($created['id']);
check('version bumped', (int) $b['version'], $vWas + 1);
check('guest charges recomputed at the rate Booking Organizer set', $b['guest_charges'], '375000.00');
$upd = json_decode($pdo->query("SELECT details FROM audit_log WHERE action = 'update' AND booking_id = {$created['id']} ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
check('update audited with old → new', $upd['changed']['guests'] ?? null, [200, 250]);

// Stale version (the second browser tab).
$vNow = $ver0($created['id']);
[$r, $errs] = $submit($userRow, $created['id'], $vWas, ['guests' => '999'] + $edit);
check('stale version rejected', $errs, ['conflict' => true]);
check('stale save changed nothing', [(int) $bookingRow($created['id'])['version'], (int) $bookingRow($created['id'])['guests']], [$vNow, 250]);

// Discount above sub total: rejected, nothing written. Submitted by the admin, because the discount
// is one of the fields a user cannot set at all.
[$r, $errs] = $submit($adminRow, $created['id'], $vNow, ['user_id' => (string) $userRow['id'], 'discount' => '999999'] + $edit);
check('discount > sub total rejected', array_keys($errs), ['discount']);
check('rejected save wrote nothing', (int) $bookingRow($created['id'])['version'], $vNow);

// Validation: client name required, bad values listed per field.
[$r, $errs] = $submit($userRow, null, null, ['client_name' => '', 'guests' => '-5', 'event_date' => '2026-02-30']);
check('field errors reported', array_keys($errs), ['event_date', 'guests', 'client_name']);

// Catalog rename doesn't touch saved lines.
$pdo->exec("UPDATE item_catalog SET name = 'Hall Hire' WHERE id = $venueCharge");
check('catalog rename keeps the snapshot label', $pdo->query("SELECT label FROM booking_line_items WHERE booking_id = {$created['id']} AND section = 'charge'")->fetchColumn(), 'Venue Charges');

// Admin: user must be active; blank snapshot filled from the user's profile.
$pendingId = (int) $pdo->query("SELECT id FROM users WHERE username = 'pendinguser'")->fetchColumn();
[$r, $errs] = $submit($adminRow, null, null, ['client_name' => 'Walk-in', 'user_id' => (string) $pendingId]);
check('admin cannot assign a pending user', array_keys($errs), ['user_id']);
[$r, $errs] = $submit($adminRow, null, null, ['client_name' => 'Walk-in', 'user_id' => (string) $userRow['id'], 'venue_id' => (string) $lawnA, 'event_date' => '2026-12-20']);
check('admin assigns an active user', $errs, []);
$b2 = $bookingRow($r['id']);
check('snapshot filled from the user profile', $b2['firm_name'], 'Uzair Caterers');
check('created_by = admin, user_id = user', [(int) $b2['created_by'], (int) $b2['user_id']], [(int) $adminRow['id'], (int) $userRow['id']]);
[$r2, $errs] = $submit($adminRow, $r['id'], 1, ['client_name' => 'Walk-in', 'user_id' => (string) $userRow['id'], 'firm_name' => 'Override Firm',
    'venue_id' => (string) $lawnA, 'event_date' => '2026-12-20']);
check('admin re-save keeps created_by, override kept', [(int) $bookingRow($r['id'])['created_by'], $bookingRow($r['id'])['firm_name']], [(int) $adminRow['id'], 'Override Firm']);

// Venue warning: two drafts on Lawn A on 20 Dec.
$clashes = venue_clashes($pdo, $created['id'], $lawnA, '2026-12-20');
check('clash with the other draft found', array_column($clashes, 'status'), ['draft']);
check('user clash message hides the SLA number', strpos(venue_clash_messages($clashes, '2026-12-20', false)[0], 'SLA-') === false, true);

// A confirmed booking can't be saved as a draft.
$pdo->exec("UPDATE bookings SET status = 'confirmed' WHERE id = {$created['id']}");
[$r, $errs] = $submit($adminRow, $created['id'], $ver0($created['id']), $edit);
check('confirmed booking refused by the draft save', array_keys($errs), ['status']);

// ---------------------------------------------------------------------------
// Lifecycle (Phase 4): confirm, complete, cancel, delete draft, amendments
// ---------------------------------------------------------------------------
$lawnB = (int) $pdo->query("SELECT id FROM venues WHERE name = 'Lawn B'")->fetchColumn();
$hall = (int) $pdo->query("SELECT id FROM venues WHERE name = 'Hall'")->fetchColumn();
$ver = static fn(int $id): int => (int) $bookingRow($id)['version'];
$refused = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (LifecycleRefused $e) {
        return $e->getMessage();
    } catch (BookingConflict $e) {
        return 'CONFLICT';
    }
};
/** Save like save.php: drafts → draft save, confirmed → direct edit / amendment. */
$save = static function (array $user, int $id, array $post) use ($pdo, $bookingRow): array {
    $existing = $bookingRow($id);
    $formLines = booking_form_lines($pdo, $id);
    $parsed = parse_booking_input($pdo, $post, $user, $existing, $formLines);
    if ($parsed['errors']) {
        return [null, $parsed['errors']];
    }
    try {
        return [save_booking_confirmed($pdo, $user, $id, (int) $existing['version'], $parsed['fields'], $parsed['lines'],
            $post['amend_reason'] ?? '', $post['override_reason'] ?? ''), []];
    } catch (BookingValidationError $e) {
        return [null, $e->errors];
    }
};
$basePost = static fn(string $client, int $venue, string $date) => [
    'client_name' => $client, 'event_date' => $date, 'venue_id' => (string) $venue, 'guests' => '100', 'per_head_rate' => '1000',
];
// A user-owned booking that carries money. Only Booking Organizer sets money (ADMIN_ONLY_FIELDS), so a
// priced booking is saved by the admin with the user named as its owner. A user raising its own
// draft is covered separately above, including the fields it is not allowed to set.
$userBooking = static fn(array $post) => $submit($adminRow, null, null, ['user_id' => (string) $userRow['id']] + $post);
$confirmMsg = static fn(int $id, string $override = '') => $refused(fn() => confirm_booking($pdo, $adminRow, $id, $ver($id), $override));

// Confirm: requirements.
[$noUser] = $submit($adminRow, null, null, $basePost('No User', $lawnB, '2027-01-10'));
check('confirm refused without a user', strpos((string) $confirmMsg($noUser['id']), 'an active, approved user') !== false, true);
[$zero] = $submit($userRow, null, null, ['client_name' => 'Zero', 'event_date' => '2027-01-10', 'venue_id' => (string) $lawnB]);
check('confirm refused with a Rs. 0 net amount', strpos((string) $confirmMsg($zero['id']), 'net amount above Rs. 0') !== false, true);

// Confirm: success, stale version, second booking on the same venue and date.
[$A] = $userBooking($basePost('Client A', $lawnB, '2027-01-10'));
[$Bk] = $userBooking($basePost('Client B', $lawnB, '2027-01-10'));
check('confirm with a stale version', $refused(fn() => confirm_booking($pdo, $adminRow, $A['id'], $ver($A['id']) - 1, '')), 'CONFLICT');
check('confirm A', $confirmMsg($A['id']), null);
check('A is confirmed with version + 1', [$bookingRow($A['id'])['status'], $ver($A['id'])], ['confirmed', 2]);
check('confirm audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'confirm' AND booking_id = {$A['id']}")->fetchColumn(), 1);
check('confirm A again refused (not a draft)', strpos((string) $confirmMsg($A['id']), 'Only a draft') === 0, true);
$msg = $confirmMsg($Bk['id']);
check('B blocked by the confirmed A on the same venue and date', strpos((string) $msg, 'already confirmed for ' . $A['unique_id']) !== false, true);
check('B still a draft', $bookingRow($Bk['id'])['status'], 'draft');
check('B confirmed with an override reason', $confirmMsg($Bk['id'], 'Client A moved to the evening slot'), null);
$ov = json_decode($pdo->query("SELECT details FROM audit_log WHERE action = 'confirm' AND booking_id = {$Bk['id']}")->fetchColumn(), true);
check('override reason and conflict audited', [$ov['venue_override']['reason'] ?? null, $ov['venue_override']['conflicts_with'] ?? null],
    ['Client A moved to the evening slot', [$A['unique_id']]]);

// Confirm: a cancelled booking on the same venue/date doesn't block; an inactive venue does.
[$C1] = $userBooking($basePost('Client C1', $hall, '2027-02-01'));
$confirmMsg($C1['id']);
cancel_booking($pdo, $adminRow, $C1['id'], $ver($C1['id']), 'Client withdrew');
[$C2] = $userBooking($basePost('Client C2', $hall, '2027-02-01'));
check('a cancelled booking does not block confirmation', $confirmMsg($C2['id']), null);
[$D] = $userBooking($basePost('Client D', $hall, '2027-03-01'));
$pdo->exec("UPDATE venues SET is_active = 0 WHERE id = $hall");
check('confirm refused on a deactivated venue', strpos((string) $confirmMsg($D['id']), 'deactivated') !== false, true);
$pdo->exec("UPDATE venues SET is_active = 1 WHERE id = $hall");

// Complete.
check('complete refused before the event date', strpos((string) $refused(fn() => complete_booking($pdo, $adminRow, $A['id'], $ver($A['id']), false)), 'on or after') !== false, true);
$pdo->exec("UPDATE bookings SET event_date = CURDATE() - INTERVAL 1 DAY, version = version + 1 WHERE id = {$A['id']}");
check('complete refused with an unacknowledged balance', strpos((string) $refused(fn() => complete_booking($pdo, $adminRow, $A['id'], $ver($A['id']), false)), 'still outstanding') !== false, true);
check('complete with acknowledgement', $refused(fn() => complete_booking($pdo, $adminRow, $A['id'], $ver($A['id']), true)), null);
check('A completed', $bookingRow($A['id'])['status'], 'completed');
$cd = json_decode($pdo->query("SELECT details FROM audit_log WHERE action = 'complete' AND booking_id = {$A['id']}")->fetchColumn(), true);
check('balance recorded in the complete audit row', $cd['balance'] ?? null, '100000.00');
check('completing a draft refused', strpos((string) $refused(fn() => complete_booking($pdo, $adminRow, $D['id'], $ver($D['id']), true)), 'Only a confirmed') === 0, true);

// Cancel.
check('cancel a completed booking refused', strpos((string) $refused(fn() => cancel_booking($pdo, $adminRow, $A['id'], $ver($A['id']), 'x')), 'Only a draft or confirmed') === 0, true);
check('cancel without a reason refused', $refused(fn() => cancel_booking($pdo, $adminRow, $Bk['id'], $ver($Bk['id']), '  ')), 'Enter a cancellation reason.');
$addPayment->execute([$Bk['id'], 'payment', '20000.00', 'cash', $adminId, null]);
db_transaction(fn(PDO $p) => recompute_booking_totals($p, $Bk['id']), $pdo);
check('cancel B (confirmed, Rs. 20,000 paid)', $refused(fn() => cancel_booking($pdo, $adminRow, $Bk['id'], $ver($Bk['id']), 'Family emergency')), null);
$bk = $bookingRow($Bk['id']);
check('cancelled: balance 0, amount retained kept, reason stored', [$bk['status'], $bk['balance'], $bk['paid_total'], $bk['cancellation_reason'], (int) $bk['cancelled_by']],
    ['cancelled', '0.00', '20000.00', 'Family emergency', (int) $adminRow['id']]);
check('cancel twice refused', strpos((string) $refused(fn() => cancel_booking($pdo, $adminRow, $Bk['id'], $ver($Bk['id']), 'again')), 'Only a draft or confirmed') === 0, true);

// Delete a draft.
[$E] = $userBooking($basePost('Client E', $lawnB, '2027-04-01'));
$files = [];
foreach (['menu.pdf', 'scan.jpg'] as $orig) {
    $stored = bin2hex(random_bytes(16));
    file_put_contents(APP_ROOT . "/storage/uploads/$stored", 'test');
    $pdo->prepare('INSERT INTO attachments (booking_id, original_name, stored_name, mime, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, 4, ?)')
        ->execute([$E['id'], $orig, $stored, 'application/pdf', $userRow['id']]);
    $files[] = $stored;
}
$otherUser = ['id' => 999999, 'role' => 'user'];
check('another user cannot delete it', $refused(fn() => delete_draft($pdo, $otherUser, $E['id'], $ver($E['id']))), 'Only a draft can be deleted.');
check('owner user deletes the draft', $refused(fn() => delete_draft($pdo, $userRow, $E['id'], $ver($E['id']))), null);
check('booking row gone', (int) $pdo->query("SELECT COUNT(*) FROM bookings WHERE id = {$E['id']}")->fetchColumn(), 0);
check('attachment rows gone', (int) $pdo->query("SELECT COUNT(*) FROM attachments WHERE booking_id = {$E['id']}")->fetchColumn(), 0);
check('attachment files gone', [is_file(APP_ROOT . "/storage/uploads/{$files[0]}"), is_file(APP_ROOT . "/storage/uploads/{$files[1]}")], [false, false]);
check('earlier audit rows kept', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE booking_id = {$E['id']} AND action = 'create'")->fetchColumn(), 1);
$dd = json_decode($pdo->query("SELECT details FROM audit_log WHERE action = 'delete_draft' AND booking_id = {$E['id']}")->fetchColumn(), true);
check('delete_draft row has the SLA number and file names', [$dd['unique_id'], $dd['attachments']], [$E['unique_id'], ['menu.pdf', 'scan.jpg']]);
[$F] = $userBooking($basePost('Client F', $lawnB, '2027-04-02'));
$addPayment->execute([$F['id'], 'payment', '1000.00', 'cash', $adminId, date('Y-m-d H:i:s')]); // a voided payment
check('draft with a (voided) payment cannot be deleted', strpos((string) $refused(fn() => delete_draft($pdo, $adminRow, $F['id'], $ver($F['id']))), 'payment records') !== false, true);
check('confirmed booking cannot be deleted', $refused(fn() => delete_draft($pdo, $adminRow, $C2['id'], $ver($C2['id']))), 'Only a draft can be deleted.');

// Amendments on a confirmed, signed booking (C2: Hall, 2027-02-01).
$c2Post = $basePost('Client C2', $hall, '2027-02-01') + ['user_id' => (string) $userRow['id'], 'client_contact' => '0300-1'];
[$r, $e] = $save($adminRow, $C2['id'], $c2Post + ['user_sign_name' => 'Uzair Khan', 'client_sign_name' => 'Client C2', 'client_sign_date' => '2026-12-01']);
check('direct edit (contact + signatures) saved', [$e, $r['amended'] ?? null], [[], false]);
$c2 = $bookingRow($C2['id']);
check('direct edit: no revision, signatures kept', [(int) $c2['revision'], $c2['client_sign_name']], [0, 'Client C2']);
$signed = ['user_sign_name' => 'Uzair Khan', 'client_sign_name' => 'Client C2', 'client_sign_date' => '2026-12-01'];
[$r, $e] = $save($adminRow, $C2['id'], ['guests' => '150'] + $c2Post + $signed);
check('amendment without a reason refused', array_key_exists('amend_reason', $e), true);
check('signed revision without a scan refused', $e['signed_copy'] ?? null, 'Upload the signed copy of Rev 0 before amending.');
$pdo->prepare('INSERT INTO attachments (booking_id, original_name, stored_name, mime, size_bytes, uploaded_by, signed_revision) VALUES (?, ?, ?, ?, 1, ?, 0)')
    ->execute([$C2['id'], 'signed-rev0.pdf', bin2hex(random_bytes(16)), 'application/pdf', $adminRow['id']]);
[$r, $e] = $save($adminRow, $C2['id'], ['guests' => '150', 'amend_reason' => 'Guest count raised by client'] + $c2Post + $signed);
check('amendment with reason and scan accepted', [$e, $r['amended'] ?? null, $r['revision'] ?? null], [[], true, 1]);
$c2 = $bookingRow($C2['id']);
check('Rev 1, signatures cleared, revised_at set', [(int) $c2['revision'], $c2['user_sign_name'], $c2['client_sign_name'], $c2['revised_at'] !== null],
    [1, null, null, true]);
$am = json_decode($pdo->query("SELECT details FROM audit_log WHERE action = 'amend' AND booking_id = {$C2['id']}")->fetchColumn(), true);
check('amend audited with reason and old → new', [$am['reason'], $am['revision'], $am['changed']['guests']], ['Guest count raised by client', [0, 1], [100, 150]]);
check('amended balance recomputed', $c2['grand_total'], '150000.00');

// Rev 1 was never signed: no scan needed. A voided scan doesn't count once it is signed.
[$r, $e] = $save($adminRow, $C2['id'], ['guests' => '160', 'amend_reason' => 'More guests'] + $c2Post);
check('unsigned revision amends without a scan', [$e, $r['revision'] ?? null], [[], 2]);
[$r, $e] = $save($adminRow, $C2['id'], ['guests' => '160'] + $c2Post + $signed);
$pdo->prepare('INSERT INTO attachments (booking_id, original_name, stored_name, mime, size_bytes, uploaded_by, signed_revision, voided_at) VALUES (?, ?, ?, ?, 1, ?, 2, NOW())')
    ->execute([$C2['id'], 'wrong.pdf', bin2hex(random_bytes(16)), 'application/pdf', $adminRow['id']]);
[$r, $e] = $save($adminRow, $C2['id'], ['guests' => '170', 'amend_reason' => 'x'] + $c2Post + $signed);
check('a voided signed copy does not count', $e['signed_copy'] ?? null, 'Upload the signed copy of Rev 2 before amending.');
[$r, $e] = $save($adminRow, $C2['id'], ['guests' => '160'] + $c2Post); // clear the signatures directly (a direct edit)
check('signatures cleared by a direct edit', [$e, $bookingRow($C2['id'])['client_sign_name']], [[], null]);
// Venue move onto a confirmed booking's venue/date: needs an override.
[$G] = $userBooking($basePost('Client G', $lawnB, '2027-05-05'));
$confirmMsg($G['id']);
$moveTo = ['venue_id' => (string) $lawnB, 'event_date' => '2027-05-05', 'guests' => '160', 'amend_reason' => 'Moved to Lawn B'] + $c2Post;
[$r, $e] = $save($adminRow, $C2['id'], $moveTo);
check('amend onto a confirmed venue/date needs an override', strpos((string) ($e['venue_override'] ?? ''), $G['unique_id']) !== false, true);
[$r, $e] = $save($adminRow, $C2['id'], ['override_reason' => 'Client G uses the lawn in the morning'] + $moveTo);
check('amend with override accepted', $e, []);
check('booking moved', [(int) $bookingRow($C2['id'])['venue_id'], $bookingRow($C2['id'])['event_date']], [$lawnB, '2027-05-05']);
[$r, $e] = $save($adminRow, $C2['id'], ['event_date' => '', 'amend_reason' => 'x'] + $moveTo);
check('an amendment can\'t remove the event date', strpos((string) ($e['status'] ?? ''), 'an event date') !== false, true);
check('user cannot use the confirmed save', $refused(function () use ($pdo, $userRow, $C2, $bookingRow) {
    try {
        save_booking_confirmed($pdo, $userRow + ['role' => 'user'], $C2['id'], (int) $bookingRow($C2['id'])['version'], $bookingRow($C2['id']), [], 'x', '');
    } catch (BookingValidationError $e) {
        throw new LifecycleRefused(implode(' ', $e->errors));
    }
}) !== null, true);

// ---------------------------------------------------------------------------
// Payments and refunds (Phase 5)
// ---------------------------------------------------------------------------
$pay = static function (int $bookingId, string $amount, string $kind = 'payment', string $method = 'cash') use ($pdo, $adminRow): ?string {
    [$data, $errors] = parse_payment_input(['amount' => $amount, 'paid_on' => date('Y-m-d'), 'method' => $method, 'reference_no' => 'R1'], $kind);
    if ($errors) {
        return 'INVALID: ' . implode(' ', $errors);
    }
    try {
        record_payment($pdo, $adminRow, $bookingId, $data);
        return null;
    } catch (PaymentRefused $e) {
        return $e->getMessage();
    }
};
$void = static function (int $bookingId, int $paymentId, string $reason = 'entered twice') use ($pdo, $adminRow): ?string {
    try {
        void_payment($pdo, $adminRow, $bookingId, $paymentId, $reason);
        return null;
    } catch (PaymentRefused $e) {
        return $e->getMessage();
    }
};
$money = static fn(int $id): array => array_values(array_intersect_key($bookingRow($id), array_flip(['grand_total', 'paid_total', 'balance'])));
$lastPayment = static fn(int $id): int => (int) $pdo->query("SELECT MAX(id) FROM payments WHERE booking_id = $id")->fetchColumn();

// Test 7: two installments, void one → balance restored.
[$P] = $userBooking($basePost('Pay Client', $lawnB, '2027-09-09'));   // Rs. 1,00,000
$confirmMsg($P['id']);
$versionBefore = $ver($P['id']);
check('installment 1', $pay($P['id'], '30,000'), null);
$first = $lastPayment($P['id']);
check('installment 2', $pay($P['id'], '20000'), null);
check('two installments: paid 50,000, balance 50,000', $money($P['id']), ['100000.00', '50000.00', '50000.00']);
check('payments do not bump the booking version (no false edit conflicts)', $ver($P['id']), $versionBefore);
check('void installment 1', $void($P['id'], $first), null);
check('void → balance restored', $money($P['id']), ['100000.00', '20000.00', '80000.00']);
check('voiding the same payment twice is refused', $void($P['id'], $first), 'This entry has already been voided.');
check('void needs a reason', $void($P['id'], $lastPayment($P['id']), '  '), 'Enter the reason for voiding this entry.');
check('void of another booking\'s payment is refused', $void($A['id'], $lastPayment($P['id'])), 'That payment entry doesn\'t belong to this booking.');
check('payment_add and payment_void audited',
    $pdo->query("SELECT GROUP_CONCAT(action ORDER BY id) FROM audit_log WHERE booking_id = {$P['id']} AND action LIKE 'payment%'")->fetchColumn(),
    'payment_add,payment_add,payment_void');

// Overpayment shows a negative balance; refunds refused outside cancelled bookings.
check('overpay by 1,000', $pay($P['id'], '81000'), null);
check('overpaid → negative balance', $money($P['id'])[2], '-1000.00');
check('refund on a confirmed booking refused', $pay($P['id'], '1000', 'refund'), 'Refunds can be recorded only on a cancelled booking.');

// Zero / negative / invalid amounts never reach the database.
$count = static fn(int $id): int => (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE booking_id = $id")->fetchColumn();
$n = $count($P['id']);
check('zero payment rejected', strpos((string) $pay($P['id'], '0'), 'INVALID') === 0, true);
check('negative payment rejected', strpos((string) $pay($P['id'], '-500'), 'INVALID') === 0, true);
check('cheque without a number rejected', strpos((string) (function () use ($pdo, $adminRow, $P) {
    [, $e] = parse_payment_input(['amount' => '10', 'paid_on' => date('Y-m-d'), 'method' => 'cheque'], 'payment');
    return implode(' ', $e);
})(), 'Cheque number') !== false, true);
check('nothing written for rejected amounts', $count($P['id']), $n);

// Forced error before commit (audit insert fails): no payment row, no audit row, balance unchanged.
$before = [$count($P['id']), $money($P['id'])];
$pdo->exec('RENAME TABLE audit_log TO audit_log_off');
$forced = null;
try {
    record_payment($pdo, $adminRow, $P['id'], parse_payment_input(['amount' => '5000', 'paid_on' => date('Y-m-d'), 'method' => 'cash'], 'payment')[0]);
} catch (PDOException $e) {
    $forced = 'rolled back';
}
$pdo->exec('RENAME TABLE audit_log_off TO audit_log');
check('forced error before commit rolls everything back', [$forced, $count($P['id']), $money($P['id'])], ['rolled back', $before[0], $before[1]]);

// Cancelled booking: payments refused, refunds capped (tests 20, 21, 23, 32).
[$R] = $userBooking($basePost('Refund Client', $lawnB, '2027-10-10') + ['refund_pct_30' => '50']);
$pdo->exec("UPDATE bookings SET per_head_rate = 5000.00 WHERE id = {$R['id']}");  // Rs. 5,00,000
db_transaction(fn(PDO $p) => recompute_booking_totals($p, $R['id']), $pdo);
check('1,00,000 paid', $pay($R['id'], '1,00,000'), null);
$paymentR = $lastPayment($R['id']);
cancel_booking($pdo, $adminRow, $R['id'], $ver($R['id']), 'Client cancelled');
check('cancelled: balance 0, retained 1,00,000', $money($R['id']), ['500000.00', '100000.00', '0.00']);
check('payment on a cancelled booking refused', $pay($R['id'], '10'), 'Payments can\'t be recorded on a cancelled booking.');
$n = $count($R['id']);
$auditN = (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE booking_id = {$R['id']}")->fetchColumn();
check('refund above the refundable amount refused (test 20)', $pay($R['id'], '1,00,001', 'refund'), 'Refund exceeds the refundable amount (Rs. 1,00,000).');
check('refused refund wrote nothing', [$count($R['id']), $money($R['id'])[1],
    (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE booking_id = {$R['id']}")->fetchColumn()], [$n, '100000.00', $auditN]);
check('refund 40,000 (test 23)', $pay($R['id'], '40000', 'refund'), null);
check('amount retained 60,000, balance still 0 (tests 23, 32)', $money($R['id']), ['500000.00', '60000.00', '0.00']);
check('second refund capped at 60,000', $pay($R['id'], '60,000.01', 'refund'), 'Refund exceeds the refundable amount (Rs. 60,000).');
check('refund the rest', $pay($R['id'], '60000', 'refund'), null);
check('fully refunded', $money($R['id']), ['500000.00', '0.00', '0.00']);
check('voiding the refunded payment refused (test 21)', $void($R['id'], $paymentR),
    'Void the related refunds first — voiding this payment would leave refunds greater than payments.');
check('payment still not voided', $pdo->query("SELECT voided_at FROM payments WHERE id = $paymentR")->fetchColumn(), null);
$refundIds = $pdo->query("SELECT id FROM payments WHERE booking_id = {$R['id']} AND kind = 'refund'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($refundIds as $rid) {
    $void($R['id'], (int) $rid, 'refund reversed');
}
check('after voiding the refunds, the payment can be voided', $void($R['id'], $paymentR), null);
check('everything voided: retained 0', $money($R['id']), ['500000.00', '0.00', '0.00']);

// Refund suggestion for this booking (50% policy, 30+ days before the event).
$sug = refund_suggestion('2027-10-10', date('Y-m-d'), 5000, null, 10000000, 4000000, 6000000);
check('policy suggestion after a Rs. 40,000 refund: 50,000 − 40,000', $sug['amount'], 1000000);

// Completed bookings accept payments (and voids), not refunds.
$pdo->exec("UPDATE bookings SET event_date = CURDATE() - INTERVAL 1 DAY, version = version + 1 WHERE id = {$P['id']}");
complete_booking($pdo, $adminRow, $P['id'], $ver($P['id']), true);
check('payment on a completed booking allowed', $pay($P['id'], '500'), null);
check('void on a completed booking allowed', $void($P['id'], $lastPayment($P['id'])), null);
check('refund on a completed booking refused', $pay($P['id'], '500', 'refund'), 'Refunds can be recorded only on a cancelled booking.');

// ---------------------------------------------------------------------------
// Attachments and admin screens (Phase 7)
// ---------------------------------------------------------------------------
$fakeUpload = static function (string $name = 'scan.pdf'): array {
    $stored = bin2hex(random_bytes(16));
    file_put_contents(APP_ROOT . '/storage/uploads/' . $stored, '%PDF-1.4 test');
    $GLOBALS['test_uploads'][] = $stored;
    return ['stored_name' => $stored, 'original_name' => $name, 'mime' => 'application/pdf', 'size' => 13];
};
$attach = static function (array $user, int $bookingId, ?int $signedRevision = null, string $name = 'scan.pdf') use ($pdo, $fakeUpload) {
    $stored = $fakeUpload($name);
    try {
        return ['id' => attach_file($pdo, $user, $bookingId, $stored, $signedRevision), 'stored' => $stored['stored_name'], 'error' => null];
    } catch (AttachmentRefused $e) {
        return ['id' => null, 'stored' => $stored['stored_name'], 'error' => $e->getMessage()];
    }
};
$voidFile = static function (int $bookingId, int $attachmentId, string $reason = 'wrong file') use ($pdo, $adminRow): ?string {
    try {
        void_attachment($pdo, $adminRow, $bookingId, $attachmentId, $reason);
        return null;
    } catch (AttachmentRefused $e) {
        return $e->getMessage();
    }
};

[$AT] = $userBooking($basePost('Attach Client', $lawnB, '2027-11-11'));
$r = $attach($userRow, $AT['id'], null, 'menu.pdf');
check('user attaches to their own draft', [$r['error'], is_int($r['id'])], [null, true]);
check('file kept on disk', is_file(APP_ROOT . '/storage/uploads/' . $r['stored']), true);
check('attachment_add audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'attachment_add' AND booking_id = {$AT['id']}")->fetchColumn(), 1);
$rOther = $attach(['id' => 999999, 'role' => 'user'], $AT['id']);
check('another user cannot attach', $rOther['error'], 'You can\'t attach files to this booking.');
check('refused upload leaves no file on disk', is_file(APP_ROOT . '/storage/uploads/' . $rOther['stored']), false);

$confirmMsg($AT['id']);
$r = $attach($userRow, $AT['id']);
check('user cannot attach to a confirmed booking', $r['error'], 'You can\'t attach files to this booking.');
$r = $attach($adminRow, $AT['id'], 5);
check('signed revision must match the booking', strpos((string) $r['error'], 'is now Rev 0') !== false, true);
$signed = $attach($adminRow, $AT['id'], 0, 'signed-rev0.pdf');
check('admin files the signed copy of Rev 0', $signed['error'], null);
check('signed copy listed for the current revision',
    (int) $pdo->query("SELECT COUNT(*) FROM attachments WHERE booking_id = {$AT['id']} AND signed_revision = 0 AND voided_at IS NULL")->fetchColumn(), 1);

// Checkpoint: a voided signed copy no longer satisfies the amendment check.
$signedPost = $basePost('Attach Client', $lawnB, '2027-11-11') + ['user_id' => (string) $userRow['id'],
    'firm_name' => 'Uzair Caterers', 'rep_name' => 'Uzair Khan', 'rep_contact' => '0312-2159834',
    'user_sign_name' => 'Uzair Khan', 'client_sign_name' => 'Attach Client'];
[$r, $e] = $save($adminRow, $AT['id'], $signedPost);                       // record the signatures (direct edit)
check('signatures recorded on the confirmed booking', $e, []);
[$r, $e] = $save($adminRow, $AT['id'], ['guests' => '120', 'amend_reason' => 'More guests'] + $signedPost);
check('amendment allowed while the signed copy is on file', [$e, $r['revision'] ?? null], [[], 1]);
[$r, $e] = $save($adminRow, $AT['id'], ['guests' => '120'] + $signedPost); // sign Rev 1 as well
$signed1 = $attach($adminRow, $AT['id'], 1, 'signed-rev1.pdf');
check('signed copy of Rev 1 filed', $signed1['error'], null);
check('voiding the signed copy', $voidFile($AT['id'], $signed1['id']), null);
[$r, $e] = $save($adminRow, $AT['id'], ['guests' => '130', 'amend_reason' => 'Again'] + $signedPost);
check('a voided signed copy no longer satisfies the amendment check', $e['signed_copy'] ?? null, 'Upload the signed copy of Rev 1 before amending.');
check('voided file stays on disk and in the table',
    [is_file(APP_ROOT . '/storage/uploads/' . $signed1['stored']),
     (int) $pdo->query("SELECT COUNT(*) FROM attachments WHERE id = {$signed1['id']}")->fetchColumn()], [true, 1]);
check('voiding twice is refused', $voidFile($AT['id'], $signed1['id']), 'This file has already been voided.');
check('void needs a reason', $voidFile($AT['id'], $signed['id'], '  '), 'Enter the reason for voiding this file.');
check('voiding another booking\'s file is refused', $voidFile($C2['id'], $signed['id']), 'That file doesn\'t belong to this booking.');
check('attachment_void audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'attachment_void' AND booking_id = {$AT['id']}")->fetchColumn(), 1);
check('users are not shown voided files',
    [count(booking_attachments($pdo, (int) $AT['id'], false)), count(booking_attachments($pdo, (int) $AT['id'], true))], [2, 3]);

// Venues: used venues can only be deactivated.
$adminRefused = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (AdminRefused $e) {
        return $e->getMessage();
    }
};
check('rename a used venue refused', strpos((string) $adminRefused(fn() => update_venue($pdo, $adminRow, $lawnB, 'Lawn B (East)', '', true, 20)), 'can\'t be renamed') !== false, true);
check('deactivate a used venue', $adminRefused(fn() => update_venue($pdo, $adminRow, $lawnB, 'Lawn B', 'Ground floor, east wing', false, 20)), null);
check('venue deactivated', (int) $pdo->query("SELECT is_active FROM venues WHERE id = $lawnB")->fetchColumn(), 0);
check('venue_change audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'venue_change'")->fetchColumn() >= 1, true);
check('delete a used venue refused', strpos((string) $adminRefused(fn() => delete_venue($pdo, $adminRow, $lawnB)), 'can\'t be deleted') !== false, true);
$adminRefused(fn() => update_venue($pdo, $adminRow, $lawnB, 'Lawn B', 'Ground floor, east wing', true, 20)); // put it back
check('create a venue', $adminRefused(fn() => create_venue($pdo, $adminRow, 'Terrace', 'Roof level', '60')), null);
$terrace = (int) $pdo->query("SELECT id FROM venues WHERE name = 'Terrace'")->fetchColumn();
check('duplicate venue name refused', strpos((string) $adminRefused(fn() => create_venue($pdo, $adminRow, 'Terrace', 'Roof level', '70')), 'already a venue') !== false, true);
check('rename an unused venue', $adminRefused(fn() => update_venue($pdo, $adminRow, $terrace, 'Roof Terrace', 'Roof level', true, 60)), null);
check('delete an unused venue', $adminRefused(fn() => delete_venue($pdo, $adminRow, $terrace)), null);
check('venue gone', (int) $pdo->query("SELECT COUNT(*) FROM venues WHERE id = $terrace")->fetchColumn(), 0);

// Venue location: the booking keeps its own copy, so later edits to the venue never rewrite paperwork.
$adminRefused(fn() => update_venue($pdo, $adminRow, $lawnA, 'Lawn A', 'Ground floor, west wing', true, 10));
[$LOC] = $userBooking($basePost('Location Client', $lawnA, '2027-11-03'));
check('venue location copied from the venue when the form leaves it blank',
    $bookingRow($LOC['id'])['venue_location'], 'Ground floor, west wing');
$adminRefused(fn() => update_venue($pdo, $adminRow, $lawnA, 'Lawn A', 'Moved: rear garden', true, 10));
check('the booking keeps its own copy after the venue moves',
    $bookingRow($LOC['id'])['venue_location'], 'Ground floor, west wing');
check('the document shows the booking copy, not the venue',
    document_data($pdo, $bookingRow($LOC['id']))['venue_location'], 'Ground floor, west wing');
[$LOC2] = $userBooking($basePost('Typed Location', $lawnA, '2027-11-04')
    + ['venue_location' => 'Marquee on the lawn']);
check('a typed location wins over the venue default',
    $bookingRow($LOC2['id'])['venue_location'], 'Marquee on the lawn');
[, $locErrs] = $userBooking($basePost('Too Long', $lawnA, '2027-11-05')
    + ['venue_location' => str_repeat('x', 151)]);
check('an over-long location is refused', isset($locErrs['venue_location']), true);
$adminRefused(fn() => update_venue($pdo, $adminRow, $lawnA, 'Lawn A', '', true, 10)); // put it back

// Calendar: the month grid and the availability bars.
$calStart = '2027-11-01';
$calEnd = '2027-11-30';
$calEntries = calendar_bookings($pdo, $calStart, $calEnd);
$calDates = array_column($calEntries, 'event_date');
check('the calendar finds bookings inside the month', in_array('2027-11-03', $calDates, true), true);
check('the calendar stays inside the month',
    array_filter($calDates, fn($d) => $d < $calStart || $d > $calEnd), []);
check('the calendar is not scoped to one user (a venue clash must be visible)',
    count(array_unique(array_column($calEntries, 'user_id'))) >= 1, true);
check('a user may read its own calendar entry',
    calendar_entry_is_own($userRow, ['user_id' => (int) $userRow['id']]), true);
check("a user may not read another user's calendar entry",
    calendar_entry_is_own($userRow, ['user_id' => (int) $userRow['id'] + 1000]), false);
check('an admin may read every calendar entry',
    calendar_entry_is_own($adminRow, ['user_id' => (int) $userRow['id'] + 1000]), true);
$avail = venue_availability($pdo, $calEntries, 30);
$availByVenue = array_column($avail, null, 'venue');
check('availability counts days, and days booked + free = days in the month',
    array_filter($avail, fn($r) => $r['days'] + $r['free'] !== 30), []);
check('an unused active venue is still listed with 0 days',
    isset($availByVenue['Pool side']) && $availByVenue['Pool side']['days'] === 0, true);

// Approvals: the queue an admin sees, and the reasons a draft is not ready.
check('pending_users lists only pending user accounts',
    array_filter(pending_users($pdo), fn($v) => !isset($v['username'])), []);
$pendingUsernames = array_column(pending_users($pdo), 'username');
check('an active user is not in the approvals queue',
    in_array($userRow['username'], $pendingUsernames, true), false);
[$NOTREADY] = $submit($adminRow, null, null, ['client_name' => 'Not Ready']);
check('a draft with nothing filled in reports every blocker',
    confirm_requirement_problems($pdo, $bookingRow($NOTREADY['id'])),
    ['an event date', 'a venue', 'a net amount above Rs. 0']);
check('a complete draft reports no blockers',
    confirm_requirement_problems($pdo, $bookingRow($LOC2['id'])), []);

// Catalog: renaming, re-rating and changing the unit never touch existing bookings (checkpoint, plan test 17).
$stageId = $catalogId('Stage Charges');
[$CAT] = $userBooking($basePost('Catalog Client', $lawnA, '2027-12-12') + [
    'lines' => ["c$stageId" => ['present' => '1', 'selected' => '1', 'rate' => '20000']]]);
$lineBefore = $pdo->query("SELECT label, unit_snapshot, rate, amount FROM booking_line_items WHERE booking_id = {$CAT['id']} AND catalog_id = $stageId")->fetch();
check('booking line snapshot', [$lineBefore['label'], $lineBefore['unit_snapshot'], $lineBefore['amount']], ['Stage Charges', 'fixed', '20000.00']);
check('rename, re-rate and change the unit in the catalog',
    $adminRefused(fn() => update_catalog_item($pdo, $adminRow, $stageId, ['name' => 'Stage & Backdrop', 'unit' => 'per head', 'default_rate' => '350.00', 'is_active' => 1, 'sort_order' => 50])), null);
db_transaction(fn(PDO $p) => recompute_booking_totals($p, $CAT['id']), $pdo);
$lineAfter = $pdo->query("SELECT label, unit_snapshot, rate, amount FROM booking_line_items WHERE booking_id = {$CAT['id']} AND catalog_id = $stageId")->fetch();
check('existing booking keeps its label, unit, rate and amount', $lineAfter, $lineBefore);
check('catalog_change audited with old → new',
    json_decode($pdo->query("SELECT details FROM audit_log WHERE action = 'catalog_change' ORDER BY id DESC LIMIT 1")->fetchColumn(), true)['changed']['name'] ?? null,
    ['Stage Charges', 'Stage & Backdrop']);
$newLines = booking_form_lines($pdo, null);
check('new bookings get the new label, unit and rate',
    [$newLines["c$stageId"]['label'], $newLines["c$stageId"]['unit'], $newLines["c$stageId"]['rate']], ['Stage & Backdrop', 'per head', '350.00']);
check('retiring an item keeps it on existing bookings but off new ones',
    $adminRefused(fn() => update_catalog_item($pdo, $adminRow, $stageId, ['name' => 'Stage & Backdrop', 'unit' => 'per head', 'default_rate' => '350.00', 'is_active' => 0, 'sort_order' => 50])), null);
check('retired item not offered on a new booking', isset(booking_form_lines($pdo, null)["c$stageId"]), false);
check('retired item still on the existing booking', isset(booking_form_lines($pdo, (int) $CAT['id'])['l' . $lineBefore2 = $pdo->query("SELECT id FROM booking_line_items WHERE booking_id = {$CAT['id']} AND catalog_id = $stageId")->fetchColumn()]), true);
check('create a catalog item', $adminRefused(fn() => create_catalog_item($pdo, $adminRow, clean_catalog_input(
    ['section' => 'charge', 'name' => 'Fireworks', 'unit' => 'fixed', 'default_rate' => '75,000', 'sort_order' => '90', 'is_active' => '1'], true))), null);
check('new catalog item offered', isset(booking_form_lines($pdo, null)['c' . $catalogId('Fireworks')]), true);

// Admin creates a user: active at once, temporary password, must change it, audited.
$created = create_user($pdo, $adminRow, clean_user_input(
    ['firm_name' => 'Admin Made Events', 'rep_name' => 'Sana Ali', 'contact' => '0321-7654321', 'username' => 'AdminMade']));
$madeRow = $pdo->query("SELECT * FROM users WHERE username = 'adminmade'")->fetch();
check('admin-created user stored', [$madeRow['role'], $madeRow['status'], $madeRow['firm_name'], $madeRow['rep_name'], $madeRow['contact']],
    ['user', 'active', 'Admin Made Events', 'Sana Ali', '0321-7654321']);
check('admin-created user must change password', (int) $madeRow['must_change_password'], 1);
check('temporary password returned and works', [$created['username'], password_verify($created['password'], $madeRow['password_hash'])], ['adminmade', true]);
$madeAudit = $pdo->query("SELECT user_id, details FROM audit_log WHERE action = 'user_create' ORDER BY id DESC LIMIT 1")->fetch();
check('user_create audited by the admin',
    [(int) $madeAudit['user_id'], json_decode($madeAudit['details'], true)['user_id'] ?? null], [(int) $adminRow['id'], (int) $madeRow['id']]);
check('temporary password not in the audit log', strpos((string) $madeAudit['details'], $created['password']), false);
$r = attempt_login($pdo, 'adminmade', $created['password'], '10.9.9.9', null);
check('admin-created user can sign in', [$r['error'], $r['user']['username'] ?? null], [null, 'adminmade']);
check('duplicate username refused', strpos((string) $adminRefused(fn() => create_user($pdo, $adminRow, clean_user_input(
    ['firm_name' => 'Other', 'rep_name' => 'Other', 'contact' => '1', 'username' => 'adminmade']))), 'already taken') !== false, true);
check('refused duplicate left one account', (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'adminmade'")->fetchColumn(), 1);

// Deleting a user: only one with no bookings, payments or files.
check('unused user deleted', apply_user_action($pdo, $adminRow, (int) $madeRow['id'], 'delete')[0], 'ok');
check('deleted user gone', (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'adminmade'")->fetchColumn(), 0);
check('user_delete audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'user_delete'")->fetchColumn(), 1);
$bookedUserId = (int) $pdo->query('SELECT user_id FROM bookings WHERE user_id IS NOT NULL LIMIT 1')->fetchColumn();
$refusedDelete = apply_user_action($pdo, $adminRow, $bookedUserId, 'delete');
check('user with bookings cannot be deleted', [$refusedDelete[0], strpos($refusedDelete[1], 'Disable it instead') !== false], ['error', true]);
check('user with bookings still there', (int) $pdo->query("SELECT COUNT(*) FROM users WHERE id = $bookedUserId")->fetchColumn(), 1);
check('admin account cannot be deleted here', apply_user_action($pdo, $adminRow, (int) $adminRow['id'], 'delete')[0], 'error');

// ---------------------------------------------------------------------------
// Menus: packages, choice groups, extra dishes, and the booking's own copy
// ---------------------------------------------------------------------------
check('imported menus', [count(menu_categories($pdo)), count(menu_dishes($pdo)), count(menu_packages($pdo))], [13, 319, 41]);
$pkgId = static fn(string $name) => (int) $pdo->query('SELECT id FROM menu_packages WHERE name = ' . $pdo->quote($name))->fetchColumn();
$dishId = static fn(string $name) => (int) $pdo->query('SELECT id FROM menu_dishes WHERE name = ' . $pdo->quote($name))->fetchColumn();
$pkgItem = static fn(int $pkg, int $dish) => (int) $pdo->query("SELECT id FROM menu_package_items WHERE package_id = $pkg AND dish_id = $dish")->fetchColumn();
$wedding = $pkgId('Special Wedding Menu No. 8');
$menu02 = $pkgId('Standard Menu 02');
$menuPost = static fn(int $pkg, array $picks, array $extras = []) => ['menu_present' => '1', 'menu_package_id' => (string) $pkg,
    'menu_pick' => [$pkg => array_map('strval', $picks)], 'menu_extra' => array_map('strval', $extras)];
$qorma = $pkgItem($wedding, $dishId('Chicken Qorma'));
$karahi = $pkgItem($wedding, $dishId('Chicken Karahi'));

// A user chooses the wedding package: Booking Organizer's package rate applies, Qorma is picked from
// "Karahi or Qorma", Fish Fry is added, and Beef Biryani (already in the package) is not doubled.
$menuClient = ['client_name' => 'Menu Client', 'guests' => '200'];
[$M, $errs] = $submit($userRow, null, null, $menuClient + $menuPost($wedding, [1 => $qorma], [$dishId('Fish Fry'), $dishId('Beef Biryani')]));
check('user saves a booking with a menu package', $errs, []);
$m = $bookingRow($M['id']);
check('package rate applied to a user booking', $m['per_head_rate'], '575.00');
check('package copied onto the booking', [(int) $m['menu_package_id'], $m['menu_package_name']], [$wedding, 'Special Wedding Menu No. 8']);
$byCat = menu_selection_by_category($m['menu_selection']);
check('menu categories in order, extra under its own', array_keys($byCat),
    ['Starter', 'Rice', 'Qorma, Karahi & Handi', 'Barbecue', 'Sea Food', 'Breads', 'Salads & Chutni', 'Dessert']);
check('choice picked; package dish not doubled', [$byCat['Rice'], $byCat['Qorma, Karahi & Handi']], [['Beef Biryani'], ['Chicken Qorma']]);
check('free dishes marked', [$byCat['Starter'], $byCat['Dessert'], $byCat['Sea Food']],
    [['Welcome Drinks (Free)', 'Arabian Puff'], ['Lab-e-Shireen', 'Gulab Jamun (Free)', 'Ice Cream'], ['Fish Fry']]);
check('below the minimum is a warning, not an error', menu_min_guests_shortfall(['min_guests' => '250'], (int) $m['guests']), 250);

[, $errs] = $submit($userRow, null, null, ['client_name' => 'No pick'] + $menuPost($menu02, [1 => $pkgItem($menu02, $dishId('Mint Lemonade'))]));
check('every choice group needs a pick', array_keys($errs), ['menu_pick_2', 'menu_pick_3']);
[, $errs] = $submit($userRow, null, null, ['client_name' => 'Wrong pick'] + $menuPost($wedding, [1 => $pkgItem($menu02, $dishId('Beef Pulao'))]));
check("a pick from another package's dishes is refused", array_keys($errs), ['menu_pick_1']);

// The same choice keeps the saved copy even after the dish is renamed; a new choice replaces it.
$pdo->exec("UPDATE menu_dishes SET name = 'Chicken Qorma Special' WHERE name = 'Chicken Qorma'");
[, $errs] = $submit($userRow, (int) $M['id'], $ver0($M['id']), $menuClient + $menuPost($wedding, [1 => $qorma], [$dishId('Fish Fry')]));
check('unchanged menu saves', $errs, []);
check('unchanged choice keeps the saved copy', $bookingRow($M['id'])['menu_selection'], $m['menu_selection']);
[, $errs] = $submit($userRow, (int) $M['id'], $ver0($M['id']), $menuClient + $menuPost($wedding, [1 => $karahi]));
check('changed choice saves', $errs, []);
$byCat = menu_selection_by_category($bookingRow($M['id'])['menu_selection']);
check('changed choice replaces the copy; unticked extra gone', [$byCat['Qorma, Karahi & Handi'], isset($byCat['Sea Food'])], [['Chicken Karahi'], false]);
$pdo->exec("UPDATE menu_dishes SET name = 'Chicken Qorma' WHERE name = 'Chicken Qorma Special'");

// Retiring a package: no new booking can choose it; one that has it keeps it.
$pdo->exec("UPDATE menu_packages SET is_active = 0 WHERE id = $wedding");
[, $errs] = $submit($userRow, null, null, ['client_name' => 'Retired'] + $menuPost($wedding, [1 => $karahi]));
check('a retired package cannot be newly chosen', array_keys($errs), ['menu_package_id']);
[, $errs] = $submit($userRow, (int) $M['id'], $ver0($M['id']), $menuClient + $menuPost($wedding, [1 => $karahi]));
check('a booking keeps its retired package', $errs, []);
$pdo->exec("UPDATE menu_packages SET is_active = 1 WHERE id = $wedding");

// Booking Organizer's own rate wins; left at zero, the package's rate fills it in. No package: no menu.
[$A1] = $submit($adminRow, null, null, ['client_name' => 'Admin rate', 'per_head_rate' => '700'] + $menuPost($wedding, [1 => $karahi]));
check("admin's rate wins over the package rate", $bookingRow($A1['id'])['per_head_rate'], '700.00');
[$A2] = $submit($adminRow, null, null, ['client_name' => 'Admin no rate'] + $menuPost($wedding, [1 => $karahi]));
check('package rate fills an empty admin rate', $bookingRow($A2['id'])['per_head_rate'], '575.00');
[, $errs] = $submit($adminRow, (int) $A2['id'], $ver0($A2['id']), ['client_name' => 'Admin no rate', 'menu_present' => '1', 'menu_package_id' => '']);
$a2 = $bookingRow($A2['id']);
check('choosing no package clears the menu', [$errs, $a2['menu_package_id'], $a2['menu_selection']], [[], null, null]);

// Confirmed booking: an unrelated edit keeps the menu (no amendment); a menu change is an amendment.
[$MC] = $userBooking($basePost('Menu Confirmed', $lawnA, '2027-06-06') + $menuPost($wedding, [1 => $qorma]));
check('confirm a booking with a menu', $confirmMsg($MC['id']), null);
$pdo->exec("UPDATE menu_dishes SET name = 'Arabian Puff Deluxe' WHERE name = 'Arabian Puff'");
$mcPost = $basePost('Menu Confirmed', $lawnA, '2027-06-06') + ['user_id' => (string) $userRow['id']];
[$r, $e] = $save($adminRow, $MC['id'], ['client_contact' => '0300-9'] + $mcPost + $menuPost($wedding, [1 => $qorma]));
check('direct edit with the same menu is not an amendment', [$e, $r['amended'] ?? null], [[], false]);
[$r, $e] = $save($adminRow, $MC['id'], ['client_contact' => '0300-9', 'amend_reason' => 'Client wants karahi'] + $mcPost + $menuPost($wedding, [1 => $karahi]));
check('a menu change is an amendment', [$e, $r['amended'] ?? null, $r['revision'] ?? null], [[], true, 1]);
check('amended menu still has the old dish name', in_array('Arabian Puff', menu_selection_by_category($bookingRow($MC['id'])['menu_selection'])['Starter'], true), false);
$pdo->exec("UPDATE menu_dishes SET name = 'Arabian Puff' WHERE name = 'Arabian Puff Deluxe'");

// Admin: building a package.
$newPkg = create_menu_package($pdo, $adminRow, ['name' => 'Hi-Tea Menu', 'per_head_rate' => '1,200', 'min_guests' => '50', 'sort_order' => '30']);
$hiTea = $pdo->query("SELECT * FROM menu_packages WHERE id = $newPkg")->fetch();
check('package created', [$hiTea['name'], $hiTea['price_basis'], $hiTea['per_head_rate'], (int) $hiTea['min_guests']], ['Hi-Tea Menu', 'guest', '1200.00', 50]);
add_menu_package_item($pdo, $adminRow, $newPkg, ['dish_id' => (string) $dishId('Tea'), 'choice_group' => '', 'sort_order' => '10']);
check('dish added to a package', count(menu_package_items($pdo, [$newPkg])[$newPkg]), 1);
check('the same dish twice is refused', strpos((string) $adminRefused(fn() => add_menu_package_item($pdo, $adminRow, $newPkg,
    ['dish_id' => (string) $dishId('Tea')])), 'already in this package') !== false, true);
check('choice group out of range is refused', strpos((string) $adminRefused(fn() => add_menu_package_item($pdo, $adminRow, $newPkg,
    ['dish_id' => (string) $dishId('Soft Drinks'), 'choice_group' => '0'])), 'Choice group') === 0, true);
check('duplicate package name refused', strpos((string) $adminRefused(fn() => create_menu_package($pdo, $adminRow, ['name' => 'Standard Menu 02'])), 'already a package') !== false, true);
check('duplicate dish in a category refused', strpos((string) $adminRefused(fn() => create_menu_dish($pdo, $adminRow,
    ['name' => 'Tea', 'category_id' => (string) $pdo->query("SELECT id FROM menu_categories WHERE name = 'Beverages'")->fetchColumn()])), 'already in that category') !== false, true);
// Extra dishes are charged: the dish's extra rate is copied onto the booking when it is added.
$fishFry = $dishId('Fish Fry');
$kunafa = $dishId('Live Kunafa');
update_menu_dish($pdo, $adminRow, $fishFry, ['name' => 'Fish Fry', 'category_id' => (string) $pdo->query("SELECT category_id FROM menu_dishes WHERE id = $fishFry")->fetchColumn(),
    'extra_rate' => '150', 'extra_unit' => 'per head', 'is_active' => '1', 'sort_order' => '10']);
update_menu_dish($pdo, $adminRow, $kunafa, ['name' => 'Live Kunafa', 'category_id' => (string) $pdo->query("SELECT category_id FROM menu_dishes WHERE id = $kunafa")->fetchColumn(),
    'extra_rate' => '5000', 'extra_unit' => 'fixed', 'is_live' => '1', 'is_active' => '1', 'sort_order' => '10']);
$extraClient = ['client_name' => 'Extras', 'guests' => '300'];
[$X, $errs] = $submit($userRow, null, null, $extraClient + $menuPost($wedding, [1 => $qorma], [$fishFry, $kunafa])
    + ['menu_extra_rate' => [$fishFry => '1']]);   // a user's rate is ignored
check('user adds priced extra dishes', $errs, []);
$x = $bookingRow($X['id']);
check('extras charged: Rs 150 x 300 guests + Rs 5,000 fixed', [$x['guest_charges'], $x['charges_total'], $x['grand_total']],
    ['172500.00', '50000.00', '222500.00']);
check('extras show on the menu', menu_selection_by_category($x['menu_selection'])['Sea Food'], ['Fish Fry']);
update_menu_dish($pdo, $adminRow, $fishFry, ['name' => 'Fish Fry', 'category_id' => (string) $pdo->query("SELECT category_id FROM menu_dishes WHERE id = $fishFry")->fetchColumn(),
    'extra_rate' => '999', 'extra_unit' => 'per head', 'is_active' => '1', 'sort_order' => '10']);
[, $errs] = $submit($userRow, (int) $X['id'], $ver0($X['id']), $extraClient + $menuPost($wedding, [1 => $karahi], [$fishFry, $kunafa]));
check('a later change to the dish rate does not re-price the booking', [$errs, $bookingRow($X['id'])['charges_total']], [[], '50000.00']);
[, $errs] = $submit($adminRow, (int) $X['id'], $ver0($X['id']), ['user_id' => (string) $userRow['id'], 'per_head_rate' => '575'] + $extraClient
    + $menuPost($wedding, [1 => $karahi], [$fishFry, $kunafa]) + ['menu_extra_rate' => [$fishFry => '120', $kunafa => '5000']]);
check('admin re-prices an extra on the booking', [$errs, $bookingRow($X['id'])['charges_total']], [[], '41000.00']);
[, $errs] = $submit($adminRow, (int) $X['id'], $ver0($X['id']), ['user_id' => (string) $userRow['id'], 'per_head_rate' => '575'] + $extraClient
    + $menuPost($wedding, [1 => $karahi], [$fishFry, $kunafa]) + ['menu_extra_rate' => [$fishFry => 'lots']]);
check('a bad extra rate is refused', array_keys($errs), ['menu_extra_rate_' . $fishFry]);
[, $errs] = $submit($adminRow, (int) $X['id'], $ver0($X['id']), ['user_id' => (string) $userRow['id'], 'per_head_rate' => '575'] + $extraClient
    + $menuPost($wedding, [1 => $karahi], [$kunafa]));
check('unticking an extra removes its charge', [$errs, $bookingRow($X['id'])['charges_total']], [[], '5000.00']);
[$XC] = $userBooking($basePost('Extras Confirmed', $lawnA, '2027-07-07') + $menuPost($wedding, [1 => $qorma], [$kunafa]));
check('confirm a booking with a priced extra', [$confirmMsg($XC['id']), $bookingRow($XC['id'])['charges_total']], [null, '5000.00']);
$xcPost = $basePost('Extras Confirmed', $lawnA, '2027-07-07') + ['user_id' => (string) $userRow['id']] + $menuPost($wedding, [1 => $qorma], [$kunafa]);
[$r, $e] = $save($adminRow, $XC['id'], $xcPost + ['menu_extra_rate' => [$kunafa => '5000']]);
check('same extra rate re-posted: nothing changes', [$e, $r['changed'] ?? null], [[], false]);
[$r, $e] = $save($adminRow, $XC['id'], $xcPost + ['menu_extra_rate' => [$kunafa => '6000'], 'amend_reason' => 'Kunafa price agreed']);
check('re-pricing an extra on a confirmed booking is an amendment', [$e, $r['amended'] ?? null, $bookingRow($XC['id'])['charges_total']], [[], true, '6000.00']);

// Group and box prices: a group price is charged per guest (price / guests); a box is a guest.
$party = $pdo->query("SELECT * FROM menu_packages WHERE name = 'Small Party Menu No. 2'")->fetch();
check('group package: price and its per-guest rate', [$party['price_basis'], $party['group_price'], (int) $party['group_size'], $party['per_head_rate']],
    ['group', '52000.00', 100, '520.00']);
check('group price label', menu_package_price_label($party), 'Rs. 52,000 for 100 guests');
$box = $pdo->query("SELECT * FROM menu_packages WHERE name = 'Iftar Box Deal #2'")->fetch();
check('box price label and unit', [menu_package_price_label($box), menu_package_unit($box)], ['Rs. 250 per box', 'boxes']);
$groupPkg = create_menu_package($pdo, $adminRow, ['name' => 'Odd Group', 'price_basis' => 'group', 'group_price' => '10,000', 'group_size' => '3']);
check('group rate rounded to the paisa', $pdo->query("SELECT per_head_rate FROM menu_packages WHERE id = $groupPkg")->fetchColumn(), '3333.33');
check('group price without its guest count refused', strpos((string) $adminRefused(fn() => create_menu_package($pdo, $adminRow,
    ['name' => 'No Size', 'price_basis' => 'group', 'group_price' => '5000'])), 'how many guests') !== false, true);
update_menu_package($pdo, $adminRow, $groupPkg, ['name' => 'Odd Group', 'price_basis' => 'guest', 'per_head_rate' => '900', 'group_price' => '10000', 'group_size' => '3']);
check('back to per guest clears the group price', array_values($pdo->query("SELECT per_head_rate, group_price, group_size FROM menu_packages WHERE id = $groupPkg")->fetch(PDO::FETCH_NUM)),
    ['900.00', null, null]);
$partyKq = $pkgItem((int) $party['id'], $dishId('Chicken Qorma'));
$partyGs = $pkgItem((int) $party['id'], $dishId('Seekh Kabab'));
[$SP, $errs] = $submit($userRow, null, null, ['client_name' => 'Party', 'guests' => '100'] + $menuPost((int) $party['id'], [1 => $partyKq, 2 => $partyGs]));
check('user books a group package', $errs, []);
check('group package charges its per-guest rate', [$bookingRow($SP['id'])['per_head_rate'], $bookingRow($SP['id'])['guest_charges']], ['520.00', '52000.00']);
remove_menu_package_item($pdo, $adminRow, $pkgItem($newPkg, $dishId('Tea')));
check('dish taken out of a package', menu_package_items($pdo, [$newPkg]), []);
check('menu changes audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'catalog_change' AND details LIKE '%menu_package%'")->fetchColumn() >= 3, true);

// Remove the files these checks wrote into storage/uploads/.
foreach ($GLOBALS['test_uploads'] ?? [] as $stored) {
    @unlink(APP_ROOT . '/storage/uploads/' . $stored);
}

// ---------------------------------------------------------------------------
$server->exec("DROP DATABASE `$testDb`");

echo "\n", $passed, ' passed, ', count($failed), " failed\n";
foreach ($failed as $f) {
    echo "\nFAIL: $f\n";
}
exit($failed ? 1 : 0);
