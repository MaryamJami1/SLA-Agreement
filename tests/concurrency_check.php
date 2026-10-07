<?php
/**
 * Real concurrency checks (plan Section 15, tests 16 and 44), local only.
 * Runs separate PHP processes in parallel against a throwaway "<DB_NAME>_race" database:
 *   1. Two new bookings for the same venue, date and slot saved at the same moment → exactly one succeeds.
 *   2. Two drafts for different slots of the same venue and date confirmed at the same moment → both succeed.
 *   3. Two confirmed bookings amended at the same moment in opposite directions (A → B, B → A)
 *      → both succeed, no deadlock (venues are always locked in ascending id order).
 * The parent holds the venue lock while the workers start, so they really do queue up together.
 *
 * Run:  php tests/concurrency_check.php        Exit code 0 = all passed.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
date_default_timezone_set('Asia/Karachi');
foreach (['helpers', 'db', 'money', 'counters', 'audit', 'auth', 'bookings', 'lifecycle'] as $lib) {
    require APP_ROOT . "/app/$lib.php";
}
$config = require APP_ROOT . '/config/config.php';
if (($config['APP_ENV'] ?? '') === 'production') {
    fwrite(STDERR, "Refusing to run with APP_ENV = production.\n");
    exit(1);
}
$config['DB_NAME'] .= '_race';
$GLOBALS['APP_CONFIG'] = $config;

// ---------------------------------------------------------------------------
// Worker mode: php concurrency_check.php worker <action> <json args>
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === 'worker') {
    $pdo = db_connect($config);
    $args = json_decode($argv[3], true);
    $admin = $pdo->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
    try {
        if ($argv[2] === 'confirm') {
            confirm_booking($pdo, $admin, $args['id'], $args['version'], '');
        } elseif ($argv[2] === 'create') {
            $parsed = parse_booking_input($pdo, $args, $admin, null, booking_form_lines($pdo, null));
            if ($parsed['errors']) {
                throw new BookingValidationError($parsed['errors']);
            }
            save_booking_draft($pdo, $admin, null, null, $parsed['fields'], $parsed['lines']);
        } else { // amend: move the booking to another venue (and that venue's slot)
            $b = $pdo->query('SELECT * FROM bookings WHERE id = ' . (int) $args['id'])->fetch();
            $fields = array_intersect_key($b, booking_field_specs());
            $fields['user_id'] = (int) $b['user_id'];
            $fields['venue_id'] = $args['venue_id'];
            $fields['venue_other'] = null;
            $slot = $pdo->query('SELECT * FROM venue_slots WHERE id = ' . (int) $args['slot_id'])->fetch();
            $fields += ['slot_id' => (int) $slot['id'], 'slot_name' => $slot['name'], 'slot_start' => $slot['start_time'], 'slot_end' => $slot['end_time']];
            $fields['start_time'] = $slot['start_time'];
            $r = save_booking_confirmed($pdo, $admin, (int) $b['id'], (int) $b['version'], $fields, [], 'Swap venues', '');
            if (!$r['amended']) {
                throw new RuntimeException('not recorded as an amendment');
            }
        }
        echo 'OK';
    } catch (LifecycleRefused $e) {
        echo 'REFUSED: ' . $e->getMessage();
    } catch (BookingValidationError $e) {
        echo 'INVALID: ' . implode(' ', $e->errors);
    } catch (Throwable $e) {
        echo 'ERROR ' . get_class($e) . ': ' . $e->getMessage();
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Parent
// ---------------------------------------------------------------------------
$passed = 0;
$failed = [];
function check(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed[] = $name;
        echo "  FAIL $name\n    expected: " . var_export($expected, true) . "\n    actual:   " . var_export($actual, true) . "\n";
    }
}

$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['DB_HOST'], $config['DB_PORT']),
    $config['DB_USER'], $config['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("DROP DATABASE IF EXISTS `{$config['DB_NAME']}`");
$server->exec("CREATE DATABASE `{$config['DB_NAME']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = db_connect($config);
foreach (['schema.sql', 'seed.sql'] as $file) {
    foreach (preg_split('/;\s*\n/', file_get_contents(APP_ROOT . "/database/$file")) as $statement) {
        $body = trim(preg_replace('/^\s*--.*$/m', '', $statement));
        if ($body !== '') {
            $pdo->exec($body);
        }
    }
}
$pdo->exec("INSERT INTO users (username, password_hash, role, name, firm_name, rep_name, contact, status)
            VALUES ('raceuser', 'x', 'user', 'Race User', 'Race Firm', 'Race Rep', '0300', 'active')");
$userId = (int) $pdo->lastInsertId();
$adminId = (int) $pdo->query("SELECT id FROM users WHERE username = 'admin'")->fetchColumn();
$venue = static fn(string $name): int => (int) $pdo->query('SELECT id FROM venues WHERE name = ' . $pdo->quote($name))->fetchColumn();
$seq = 0;
$slotOf = static function (int $venueId, string $name) use ($pdo): int {
    return (int) $pdo->query("SELECT id FROM venue_slots WHERE venue_id = $venueId AND name = " . $pdo->quote($name))->fetchColumn();
};
$makeBooking = static function (int $venueId, string $date, string $status, string $slot = 'Morning') use ($pdo, $userId, $adminId, &$seq, $slotOf): int {
    $seq++;
    $pdo->prepare("INSERT INTO bookings (unique_id, user_id, created_by, status, client_name, event_date, venue_id, slot_id, slot_name, guests, per_head_rate)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 100, 1000.00)")
        ->execute([sprintf('SLA-RACE-%04d', $seq), $userId, $adminId, $status, "Client $seq", $date, $venueId, $slotOf($venueId, $slot), $slot]);
    $id = (int) $pdo->lastInsertId();
    db_transaction(static fn(PDO $p) => recompute_booking_totals($p, $id), $pdo);
    return $id;
};

/** Start workers while $venueId is locked by this process; release after they are waiting; collect output. */
$race = static function (int $venueId, array $jobs) use ($config): array {
    $holder = db_connect($config);
    $holder->beginTransaction();
    $holder->query("SELECT id FROM venues WHERE id = $venueId FOR UPDATE")->fetchAll();

    $procs = [];
    foreach ($jobs as [$action, $args]) {
        $cmd = [PHP_BINARY, __FILE__, 'worker', $action, json_encode($args)];
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $GLOBALS['race_pipes'][] = $pipes;
    }
    sleep(2);                // both workers are now blocked on the venue lock
    $holder->commit();       // release: they proceed at the same moment

    $out = [];
    foreach ($GLOBALS['race_pipes'] as $i => $pipes) {
        $out[] = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($procs[$i]);
    }
    $GLOBALS['race_pipes'] = [];
    return $out;
};

// 1. Two people booking the same venue, date and slot at the same moment.
echo "== Two new bookings for Lawn A, Evening, on the same date, at the same moment\n";
$lawnA = $venue('Lawn A');
$post = static fn(string $client) => ['client_name' => $client, 'user_id' => (string) $userId, 'event_date' => '2027-06-01',
    'venue_id' => (string) $lawnA, 'slot_id' => (string) $slotOf($lawnA, 'Evening'), 'guests' => '100', 'per_head_rate' => '1000'];
$out = $race($lawnA, [['create', $post('Racer 1')], ['create', $post('Racer 2')]]);
sort($out);
check('exactly one booking succeeded', count(array_filter($out, static fn($o) => $o === 'OK')), 1);
check('the other was told the slot is taken',
    count(array_filter($out, static fn($o) => strpos($o, 'INVALID: Event slot: Evening is already booked') === 0)), 1);
$evening = $slotOf($lawnA, 'Evening');
check('database: one live booking holds the slot',
    (int) $pdo->query("SELECT COUNT(*) FROM bookings WHERE event_date = '2027-06-01' AND slot_id = $evening AND status <> 'cancelled'")->fetchColumn(), 1);
foreach ($out as $o) {
    echo "    worker: $o\n";
}

// 2. Simultaneous confirmation of two drafts in different slots of one venue and date: no false clash.
echo "== Two confirmations for Lawn A on one date, Morning and Afternoon, at the same moment\n";
$d1 = $makeBooking($lawnA, '2027-06-02', 'draft', 'Morning');
$d2 = $makeBooking($lawnA, '2027-06-02', 'draft', 'Afternoon');
$out = $race($lawnA, [['confirm', ['id' => $d1, 'version' => 1]], ['confirm', ['id' => $d2, 'version' => 1]]]);
check('both confirmations succeeded', $out, ['OK', 'OK']);
check('two confirm audit rows', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'confirm' AND booking_id IN ($d1, $d2)")->fetchColumn(), 2);
foreach ($out as $o) {
    echo "    worker: $o\n";
}

// 3. Opposite amendments A → B and B → A at the same moment: no deadlock.
echo "== Amend booking 1 (Lawn B → Hall) and booking 2 (Hall → Lawn B) at the same moment\n";
$lawnB = $venue('Lawn B');
$hall = $venue('Hall');
$b1 = $makeBooking($lawnB, '2027-07-01', 'confirmed');
$b2 = $makeBooking($hall, '2027-07-02', 'confirmed');
$out = $race(min($lawnB, $hall), [['amend', ['id' => $b1, 'venue_id' => $hall, 'slot_id' => $slotOf($hall, 'Morning')]],
    ['amend', ['id' => $b2, 'venue_id' => $lawnB, 'slot_id' => $slotOf($lawnB, 'Morning')]]]);
check('both amendments succeeded (no deadlock)', $out, ['OK', 'OK']);
check('venues swapped', [(int) $pdo->query("SELECT venue_id FROM bookings WHERE id = $b1")->fetchColumn(),
    (int) $pdo->query("SELECT venue_id FROM bookings WHERE id = $b2")->fetchColumn()], [$hall, $lawnB]);
check('both are Rev 1 with an amend audit row', (int) $pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'amend' AND booking_id IN ($b1, $b2)")->fetchColumn(), 2);
foreach ($out as $o) {
    echo "    worker: $o\n";
}

$server->exec("DROP DATABASE `{$config['DB_NAME']}`");
echo "\n", $passed, ' passed, ', count($failed), " failed\n";
exit($failed ? 1 : 0);
