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

$config = require APP_ROOT . '/config/config.php';
$GLOBALS['APP_CONFIG'] = $config;
if (($config['APP_ENV'] ?? '') === 'production') {
    fwrite(STDERR, "Refusing to run database checks with APP_ENV = production.\n");
    exit(1);
}
$testDb = $config['DB_NAME'] . '_test';

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
foreach (['schema.sql', 'seed.sql'] as $file) {
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
check('all 11 tables exist', $tables, ['attachments', 'audit_log', 'booking_line_items', 'bookings', 'counters',
    'item_catalog', 'login_attempts', 'payments', 'schema_version', 'users', 'venues']);
check('all tables InnoDB', (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB'")->fetchColumn(), 0);
check('schema_version = 1', (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn(), 1);

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
    $pdo->prepare("INSERT INTO users (username, password_hash, role, name, status) VALUES (?, ?, 'vendor', ?, ?)")
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $username, $status]);
    return (int) $pdo->lastInsertId();
};
$attempts = static fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
$good = 'correct-password-1';

$makeUser('okvendor', 'active');
$r = attempt_login($pdo, ' OKVendor ', $good, '10.1.1.1', null);
check('active vendor logs in (username case/space-insensitive)', [$r['error'], $r['user']['username'] ?? null], [null, 'okvendor']);
check('success recorded as known IP', is_known_ip($pdo, 'okvendor', '10.1.1.1'), true);

$makeUser('pendingvendor', 'pending');
$r = attempt_login($pdo, 'pendingvendor', $good, '10.1.1.2', null);
check('pending vendor cannot log in', [$r['user'], strpos((string) $r['error'], 'waiting for approval') !== false], [null, true]);
$makeUser('disabledvendor', 'disabled');
$r = attempt_login($pdo, 'disabledvendor', $good, '10.1.1.2', null);
check('disabled vendor cannot log in', [$r['user'], strpos((string) $r['error'], 'disabled') !== false], [null, true]);
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
$r = attempt_login($pdo, 'okvendor', $good, '10.3.3.3', null);
check('limit 2: IP with 20 failures is locked out', strpos((string) $r['error'], 'from this network') !== false, true);
$r = attempt_login($pdo, 'okvendor', $good, '10.3.3.4', null);
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
attempt_login($pdo, 'okvendor', $good, '10.1.1.1', null);
check('rows older than 90 days purged', (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE username = 'old'")->fetchColumn(), 0);

// Audit log: login_ok written for successes.
check('login_ok audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'login_ok'")->fetchColumn() >= 4, true);

// ---------------------------------------------------------------------------
// Booking save (Phase 3): create, reopen, edit, stale version, ownership, snapshots
// ---------------------------------------------------------------------------
$vendorRow = $pdo->query("SELECT * FROM users WHERE username = 'okvendor'")->fetch();
$pdo->prepare('UPDATE users SET firm_name = ?, rep_name = ?, contact = ? WHERE id = ?')
    ->execute(['Uzair Caterers', 'Uzair Khan', '0312-2159834', $vendorRow['id']]);
$vendorRow = $pdo->query("SELECT * FROM users WHERE username = 'okvendor'")->fetch();
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

$post = [
    'client_name' => 'Ayesha Siddiqui', 'client_cnic' => '4210112345671', 'event_type' => 'Valima',
    'event_date' => '2026-12-20', 'venue_id' => (string) $lawnA, 'guests' => '200', 'per_head_rate' => '1,500',
    'setup_time' => '18:00', 'vendor_id' => (string) $adminRow['id'], // forged: vendors can't choose the owner
    'firm_name' => 'Forged Firm', 'event_type_other' => 'ignored because type is not Other',
    'lines' => [
        "c$venueCharge" => ['present' => '1', 'selected' => '1', 'rate' => '50000'],
        "c$led" => ['present' => '1', 'selected' => '1', 'notes' => 'warm white'],
        "c$sofa" => ['present' => '1', 'selected' => '1', 'qty' => '2'],
    ],
];
[$created, $errs] = $submit($vendorRow, null, null, $post);
check('vendor creates a draft', $errs, []);
$b = $bookingRow($created['id']);
check('new booking gets an SLA number', preg_match('/^SLA-\d{4}-\d{4}$/', $created['unique_id']), 1);
check('vendor_id forced to the creating vendor', (int) $b['vendor_id'], (int) $vendorRow['id']);
check('created_by = vendor', (int) $b['created_by'], (int) $vendorRow['id']);
check('firm snapshot from the vendor profile, not the POST', [$b['firm_name'], $b['rep_name'], $b['rep_contact']], ['Uzair Caterers', 'Uzair Khan', '0312-2159834']);
check('status draft, version 1', [$b['status'], (int) $b['version']], ['draft', 1]);
check('CNIC normalized', $b['client_cnic'], '42101-1234567-1');
check('"Other" text dropped when type is not Other', $b['event_type_other'], null);
check('time stored', $b['setup_time'], '18:00:00');
check('totals stored', [$b['guest_charges'], $b['charges_total'], $b['grand_total'], $b['balance']], ['300000.00', '50000.00', '350000.00', '350000.00']);
$lines = $pdo->query("SELECT section, label, unit_snapshot, is_selected, qty, rate, amount, notes FROM booking_line_items WHERE booking_id = {$created['id']} ORDER BY section, id")->fetchAll();
check('only ticked catalog items stored', array_column($lines, 'label'), ['Venue Charges', 'LED', 'Sofa']);
check('charge line snapshot and amount', [$lines[0]['unit_snapshot'], $lines[0]['rate'], $lines[0]['amount']], ['fixed', '50000.00', '50000.00']);
check('decor line has no money', [$lines[1]['rate'], $lines[1]['amount'], $lines[1]['notes']], [null, '0.00', 'warm white']);
check('ops line qty', (int) $lines[2]['qty'], 2);
check('create audited', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'create' AND booking_id = {$created['id']}")->fetchColumn(), 1);

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
[$r, $errs] = $submit($vendorRow, $created['id'], 1, $edit);
check('edit with current version saves', $errs, []);
$b = $bookingRow($created['id']);
check('version bumped', (int) $b['version'], 2);
check('guest charges recomputed', $b['guest_charges'], '375000.00');
$upd = json_decode($pdo->query("SELECT details FROM audit_log WHERE action = 'update' AND booking_id = {$created['id']} ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
check('update audited with old → new', $upd['changed']['guests'] ?? null, [200, 250]);

// Stale version (the second browser tab).
[$r, $errs] = $submit($vendorRow, $created['id'], 1, ['guests' => '999'] + $edit);
check('stale version rejected', $errs, ['conflict' => true]);
check('stale save changed nothing', [(int) $bookingRow($created['id'])['version'], (int) $bookingRow($created['id'])['guests']], [2, 250]);

// Discount above sub total: rejected, nothing written.
[$r, $errs] = $submit($vendorRow, $created['id'], 2, ['discount' => '999999'] + $edit);
check('discount > sub total rejected', array_keys($errs), ['discount']);
check('rejected save wrote nothing', (int) $bookingRow($created['id'])['version'], 2);

// Validation: client name required, bad values listed per field.
[$r, $errs] = $submit($vendorRow, null, null, ['client_name' => '', 'guests' => '-5', 'event_date' => '2026-02-30']);
check('field errors reported', array_keys($errs), ['event_date', 'guests', 'client_name']);

// Catalog rename doesn't touch saved lines.
$pdo->exec("UPDATE item_catalog SET name = 'Hall Hire' WHERE id = $venueCharge");
check('catalog rename keeps the snapshot label', $pdo->query("SELECT label FROM booking_line_items WHERE booking_id = {$created['id']} AND section = 'charge'")->fetchColumn(), 'Venue Charges');

// Admin: vendor must be active; blank snapshot filled from the vendor's profile.
$pendingId = (int) $pdo->query("SELECT id FROM users WHERE username = 'pendingvendor'")->fetchColumn();
[$r, $errs] = $submit($adminRow, null, null, ['client_name' => 'Walk-in', 'vendor_id' => (string) $pendingId]);
check('admin cannot assign a pending vendor', array_keys($errs), ['vendor_id']);
[$r, $errs] = $submit($adminRow, null, null, ['client_name' => 'Walk-in', 'vendor_id' => (string) $vendorRow['id'], 'venue_id' => (string) $lawnA, 'event_date' => '2026-12-20']);
check('admin assigns an active vendor', $errs, []);
$b2 = $bookingRow($r['id']);
check('snapshot filled from the vendor profile', $b2['firm_name'], 'Uzair Caterers');
check('created_by = admin, vendor_id = vendor', [(int) $b2['created_by'], (int) $b2['vendor_id']], [(int) $adminRow['id'], (int) $vendorRow['id']]);
[$r2, $errs] = $submit($adminRow, $r['id'], 1, ['client_name' => 'Walk-in', 'vendor_id' => (string) $vendorRow['id'], 'firm_name' => 'Override Firm',
    'venue_id' => (string) $lawnA, 'event_date' => '2026-12-20']);
check('admin re-save keeps created_by, override kept', [(int) $bookingRow($r['id'])['created_by'], $bookingRow($r['id'])['firm_name']], [(int) $adminRow['id'], 'Override Firm']);

// Venue warning: two drafts on Lawn A on 20 Dec.
$clashes = venue_clashes($pdo, $created['id'], $lawnA, '2026-12-20');
check('clash with the other draft found', array_column($clashes, 'status'), ['draft']);
check('vendor clash message hides the SLA number', strpos(venue_clash_messages($clashes, '2026-12-20', false)[0], 'SLA-') === false, true);

// A confirmed booking can't be saved as a draft.
$pdo->exec("UPDATE bookings SET status = 'confirmed' WHERE id = {$created['id']}");
[$r, $errs] = $submit($adminRow, $created['id'], 2, $edit);
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
$confirmMsg = static fn(int $id, string $override = '') => $refused(fn() => confirm_booking($pdo, $adminRow, $id, $ver($id), $override));

// Confirm: requirements.
[$noVendor] = $submit($adminRow, null, null, $basePost('No Vendor', $lawnB, '2027-01-10'));
check('confirm refused without a vendor', strpos((string) $confirmMsg($noVendor['id']), 'an active, approved vendor') !== false, true);
[$zero] = $submit($vendorRow, null, null, ['client_name' => 'Zero', 'event_date' => '2027-01-10', 'venue_id' => (string) $lawnB]);
check('confirm refused with a Rs. 0 net amount', strpos((string) $confirmMsg($zero['id']), 'net amount above Rs. 0') !== false, true);

// Confirm: success, stale version, second booking on the same venue and date.
[$A] = $submit($vendorRow, null, null, $basePost('Client A', $lawnB, '2027-01-10'));
[$Bk] = $submit($vendorRow, null, null, $basePost('Client B', $lawnB, '2027-01-10'));
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
[$C1] = $submit($vendorRow, null, null, $basePost('Client C1', $hall, '2027-02-01'));
$confirmMsg($C1['id']);
cancel_booking($pdo, $adminRow, $C1['id'], $ver($C1['id']), 'Client withdrew');
[$C2] = $submit($vendorRow, null, null, $basePost('Client C2', $hall, '2027-02-01'));
check('a cancelled booking does not block confirmation', $confirmMsg($C2['id']), null);
[$D] = $submit($vendorRow, null, null, $basePost('Client D', $hall, '2027-03-01'));
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
[$E] = $submit($vendorRow, null, null, $basePost('Client E', $lawnB, '2027-04-01'));
$files = [];
foreach (['menu.pdf', 'scan.jpg'] as $orig) {
    $stored = bin2hex(random_bytes(16));
    file_put_contents(APP_ROOT . "/storage/uploads/$stored", 'test');
    $pdo->prepare('INSERT INTO attachments (booking_id, original_name, stored_name, mime, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, 4, ?)')
        ->execute([$E['id'], $orig, $stored, 'application/pdf', $vendorRow['id']]);
    $files[] = $stored;
}
$otherVendor = ['id' => 999999, 'role' => 'vendor'];
check('another vendor cannot delete it', $refused(fn() => delete_draft($pdo, $otherVendor, $E['id'], $ver($E['id']))), 'Only a draft can be deleted.');
check('owner vendor deletes the draft', $refused(fn() => delete_draft($pdo, $vendorRow, $E['id'], $ver($E['id']))), null);
check('booking row gone', (int) $pdo->query("SELECT COUNT(*) FROM bookings WHERE id = {$E['id']}")->fetchColumn(), 0);
check('attachment rows gone', (int) $pdo->query("SELECT COUNT(*) FROM attachments WHERE booking_id = {$E['id']}")->fetchColumn(), 0);
check('attachment files gone', [is_file(APP_ROOT . "/storage/uploads/{$files[0]}"), is_file(APP_ROOT . "/storage/uploads/{$files[1]}")], [false, false]);
check('earlier audit rows kept', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE booking_id = {$E['id']} AND action = 'create'")->fetchColumn(), 1);
$dd = json_decode($pdo->query("SELECT details FROM audit_log WHERE action = 'delete_draft' AND booking_id = {$E['id']}")->fetchColumn(), true);
check('delete_draft row has the SLA number and file names', [$dd['unique_id'], $dd['attachments']], [$E['unique_id'], ['menu.pdf', 'scan.jpg']]);
[$F] = $submit($vendorRow, null, null, $basePost('Client F', $lawnB, '2027-04-02'));
$addPayment->execute([$F['id'], 'payment', '1000.00', 'cash', $adminId, date('Y-m-d H:i:s')]); // a voided payment
check('draft with a (voided) payment cannot be deleted', strpos((string) $refused(fn() => delete_draft($pdo, $adminRow, $F['id'], $ver($F['id']))), 'payment records') !== false, true);
check('confirmed booking cannot be deleted', $refused(fn() => delete_draft($pdo, $adminRow, $C2['id'], $ver($C2['id']))), 'Only a draft can be deleted.');

// Amendments on a confirmed, signed booking (C2: Hall, 2027-02-01).
$c2Post = $basePost('Client C2', $hall, '2027-02-01') + ['vendor_id' => (string) $vendorRow['id'], 'client_contact' => '0300-1'];
[$r, $e] = $save($adminRow, $C2['id'], $c2Post + ['vendor_sign_name' => 'Uzair Khan', 'client_sign_name' => 'Client C2', 'client_sign_date' => '2026-12-01']);
check('direct edit (contact + signatures) saved', [$e, $r['amended'] ?? null], [[], false]);
$c2 = $bookingRow($C2['id']);
check('direct edit: no revision, signatures kept', [(int) $c2['revision'], $c2['client_sign_name']], [0, 'Client C2']);
$signed = ['vendor_sign_name' => 'Uzair Khan', 'client_sign_name' => 'Client C2', 'client_sign_date' => '2026-12-01'];
[$r, $e] = $save($adminRow, $C2['id'], ['guests' => '150'] + $c2Post + $signed);
check('amendment without a reason refused', array_key_exists('amend_reason', $e), true);
check('signed revision without a scan refused', $e['signed_copy'] ?? null, 'Upload the signed copy of Rev 0 before amending.');
$pdo->prepare('INSERT INTO attachments (booking_id, original_name, stored_name, mime, size_bytes, uploaded_by, signed_revision) VALUES (?, ?, ?, ?, 1, ?, 0)')
    ->execute([$C2['id'], 'signed-rev0.pdf', bin2hex(random_bytes(16)), 'application/pdf', $adminRow['id']]);
[$r, $e] = $save($adminRow, $C2['id'], ['guests' => '150', 'amend_reason' => 'Guest count raised by client'] + $c2Post + $signed);
check('amendment with reason and scan accepted', [$e, $r['amended'] ?? null, $r['revision'] ?? null], [[], true, 1]);
$c2 = $bookingRow($C2['id']);
check('Rev 1, signatures cleared, revised_at set', [(int) $c2['revision'], $c2['vendor_sign_name'], $c2['client_sign_name'], $c2['revised_at'] !== null],
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
[$G] = $submit($vendorRow, null, null, $basePost('Client G', $lawnB, '2027-05-05'));
$confirmMsg($G['id']);
$moveTo = ['venue_id' => (string) $lawnB, 'event_date' => '2027-05-05', 'guests' => '160', 'amend_reason' => 'Moved to Lawn B'] + $c2Post;
[$r, $e] = $save($adminRow, $C2['id'], $moveTo);
check('amend onto a confirmed venue/date needs an override', strpos((string) ($e['venue_override'] ?? ''), $G['unique_id']) !== false, true);
[$r, $e] = $save($adminRow, $C2['id'], ['override_reason' => 'Client G uses the lawn in the morning'] + $moveTo);
check('amend with override accepted', $e, []);
check('booking moved', [(int) $bookingRow($C2['id'])['venue_id'], $bookingRow($C2['id'])['event_date']], [$lawnB, '2027-05-05']);
[$r, $e] = $save($adminRow, $C2['id'], ['event_date' => '', 'amend_reason' => 'x'] + $moveTo);
check('an amendment can\'t remove the event date', strpos((string) ($e['status'] ?? ''), 'an event date') !== false, true);
check('vendor cannot use the confirmed save', $refused(function () use ($pdo, $vendorRow, $C2, $bookingRow) {
    try {
        save_booking_confirmed($pdo, $vendorRow + ['role' => 'vendor'], $C2['id'], (int) $bookingRow($C2['id'])['version'], $bookingRow($C2['id']), [], 'x', '');
    } catch (BookingValidationError $e) {
        throw new LifecycleRefused(implode(' ', $e->errors));
    }
}) !== null, true);

// ---------------------------------------------------------------------------
$server->exec("DROP DATABASE `$testDb`");

echo "\n", $passed, ' passed, ', count($failed), " failed\n";
foreach ($failed as $f) {
    echo "\nFAIL: $f\n";
}
exit($failed ? 1 : 0);
