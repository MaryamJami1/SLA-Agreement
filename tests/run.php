<?php
/**
 * Plain-PHP tests for the pure functions (no database needed).
 * Run:  php tests/run.php        Exit code 0 = all passed.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Karachi');
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/money.php';
require __DIR__ . '/../app/counters.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/bookings.php';
require __DIR__ . '/../app/lifecycle.php';
require __DIR__ . '/../app/payments.php';
require __DIR__ . '/../app/documents.php';
require __DIR__ . '/../app/attachments.php';
require __DIR__ . '/../app/admin_data.php';
require __DIR__ . '/../app/charts.php';
require __DIR__ . '/../app/dashboard.php';
require __DIR__ . '/../app/vendors.php';

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

/** Assert that $fn throws InvalidInput. */
function check_rejects(string $name, callable $fn): void
{
    try {
        $result = $fn();
        check("$name (should be rejected)", 'returned ' . var_export($result, true), 'InvalidInput');
    } catch (InvalidInput $e) {
        check($name, true, true);
    }
}

// ---------------------------------------------------------------------------
// Number to words (lakh / crore)
// ---------------------------------------------------------------------------
check('words 0', number_to_words(0), 'Zero');
check('words 1', number_to_words(1), 'One');
check('words 99', number_to_words(99), 'Ninety Nine');
check('words 100', number_to_words(100), 'One Hundred');
check('words 115', number_to_words(115), 'One Hundred Fifteen');
check('words 1,000', number_to_words(1000), 'One Thousand');
check('words 1,00,000', number_to_words(100000), 'One Lakh');
check('words 12,34,56,789', number_to_words(123456789),
    'Twelve Crore Thirty Four Lakh Fifty Six Thousand Seven Hundred Eighty Nine');
check('words 999 crore', number_to_words(9999999999), 'Nine Hundred Ninety Nine Crore Ninety Nine Lakh Ninety Nine Thousand Nine Hundred Ninety Nine');
check('words 20,00,00,000', number_to_words(200000000), 'Twenty Crore');
check('amount words whole', amount_in_words(10000000), 'One Lakh Rupees Only');
check('amount words paisa', amount_in_words(1250), 'Twelve Rupees and Fifty Paisa Only');
check('amount words zero', amount_in_words(0), 'Zero Rupees Only');

// ---------------------------------------------------------------------------
// Rs. formatting (South Asian grouping)
// ---------------------------------------------------------------------------
check('rs 12,34,567', format_rs(123456700), 'Rs. 12,34,567');
check('rs 0', format_rs(0), 'Rs. 0');
check('rs 999', format_rs(99900), 'Rs. 999');
check('rs 1,000', format_rs(100000), 'Rs. 1,000');
check('rs 1,00,000', format_rs(10000000), 'Rs. 1,00,000');
check('rs paisa', format_rs(123456750), 'Rs. 12,34,567.50');
check('rs 5 paisa', format_rs(5), 'Rs. 0.05');
check('rs negative', format_rs(-150000), 'Rs. -1,500');
check('rs max', format_rs(MONEY_MAX_PAISA), 'Rs. 9,99,99,99,999.99');

// ---------------------------------------------------------------------------
// Decimal <-> paisa
// ---------------------------------------------------------------------------
check('dec→paisa 1234.50', decimal_to_paisa('1234.50'), 123450);
check('dec→paisa 0.05', decimal_to_paisa('0.05'), 5);
check('dec→paisa -12.30', decimal_to_paisa('-12.30'), -1230);
check('dec→paisa 7', decimal_to_paisa('7'), 700);
check('paisa→dec 123450', paisa_to_decimal(123450), '1234.50');
check('paisa→dec 5', paisa_to_decimal(5), '0.05');
check('paisa→dec -1230', paisa_to_decimal(-1230), '-12.30');
check('paisa→dec 0', paisa_to_decimal(0), '0.00');

// ---------------------------------------------------------------------------
// Numeric parsing (server-side validation)
// ---------------------------------------------------------------------------
check('money 1,00,000', parse_money('1,00,000'), 10000000);
check('money Rs. 500', parse_money('Rs. 500'), 50000);
check('money rs500', parse_money('rs500'), 50000);
check('money 0.50', parse_money('0.50'), 50);
check('money 12.5', parse_money('12.5'), 1250);
check('money 0 allowed', parse_money('0'), 0);
check('money empty', parse_money(''), null);
check('money null', parse_money(null), null);
check('money max', parse_money('9999999999.99'), MONEY_MAX_PAISA);
check('money leading zeros', parse_money('000123'), 12300);
foreach (['-1', '1e5', 'abc', 'NaN', 'INF', '12.345', '.5', '5.', '1.2.3', '10000000000', '99999999999999999999'] as $bad) {
    check_rejects("money rejects '$bad'", fn() => parse_money($bad));
}
check_rejects('money rejects 0 when > 0 required', fn() => parse_money('0', false));
check_rejects('money rejects 0.00 when > 0 required', fn() => parse_money('0.00', false));

check('whole 250', parse_whole_number('250'), 250);
check('whole 0', parse_whole_number('0'), 0);
check('whole empty', parse_whole_number(''), null);
check('whole 1,00,000', parse_whole_number('1,00,000'), 100000);
foreach (['2.5', '-3', 'abc', '1e3', '100001', '99999999999999999999'] as $bad) {
    check_rejects("whole rejects '$bad'", fn() => parse_whole_number($bad));
}

check('pct 50', parse_percent('50'), 5000);
check('pct 12.5%', parse_percent('12.5%'), 1250);
check('pct 100', parse_percent('100'), 10000);
check('pct 0', parse_percent('0'), 0);
check('pct empty', parse_percent(''), null);
foreach (['150', '-5', 'abc', '100.01', '50.123'] as $bad) {
    check_rejects("pct rejects '$bad'", fn() => parse_percent($bad));
}

// ---------------------------------------------------------------------------
// Charge line amounts
// ---------------------------------------------------------------------------
check('fixed ignores qty and guests', line_amount('fixed', true, 500000, 7, 300), 500000);
check('per unit = qty × rate', line_amount('per unit', true, 25000, 4, 300), 100000);
check('per unit, no qty', line_amount('per unit', true, 25000, null, 300), 0);
check('per head = guests × rate', line_amount('per head', true, 8500, 99, 250), 2125000);
check('unselected = 0', line_amount('fixed', false, 500000, null, 300), 0);
check('no rate = 0', line_amount('fixed', true, null, null, 300), 0);

// ---------------------------------------------------------------------------
// Totals chain
// ---------------------------------------------------------------------------
$lines = [
    'venue'  => ['unit' => 'fixed', 'selected' => true, 'rate' => 5000000, 'qty' => null],       // Rs. 50,000
    'drinks' => ['unit' => 'per head', 'selected' => true, 'rate' => 8500, 'qty' => null],       // Rs. 85 × guests
    'stage'  => ['unit' => 'per unit', 'selected' => true, 'rate' => 1000000, 'qty' => 2],       // Rs. 20,000
    'valet'  => ['unit' => 'fixed', 'selected' => false, 'rate' => 999900, 'qty' => null],       // unselected
];
$payments = [
    ['kind' => 'payment', 'amount' => 10000000, 'voided' => false], // Rs. 1,00,000
    ['kind' => 'payment', 'amount' => 5000000, 'voided' => true],   // voided: ignored
    ['kind' => 'payment', 'amount' => 2000000, 'voided' => false],  // Rs. 20,000
];
$t = compute_totals(150000, 250, 1000000, $lines, $payments, 'confirmed'); // Rs. 1,500/head, 250 guests, Rs. 10,000 discount
check('guest charges = rate × guests', $t['guest_charges'], 37500000);
check('charges total (selected only)', $t['charges_total'], 5000000 + 2125000 + 2000000);
check('line amounts', $t['line_amounts'], ['venue' => 5000000, 'drinks' => 2125000, 'stage' => 2000000, 'valet' => 0]);
check('sub total', $t['sub_total'], 37500000 + 9125000);
check('grand total = sub − discount', $t['grand_total'], 46625000 - 1000000);
check('paid total ignores voided', $t['paid_total'], 12000000);
check('balance', $t['balance'], 45625000 - 12000000);
check('no discount errors', validate_totals($t, 1000000), []);

$t2 = compute_totals(0, 0, 0, ['v' => ['unit' => 'fixed', 'selected' => true, 'rate' => 300000, 'qty' => null]], [], 'draft');
check('charges only', [$t2['sub_total'], $t2['grand_total'], $t2['balance']], [300000, 300000, 300000]);

$t3 = compute_totals(0, 0, 0, ['v' => ['unit' => 'fixed', 'selected' => true, 'rate' => 50000000, 'qty' => null]], [
    ['kind' => 'payment', 'amount' => 10000000, 'voided' => false],
    ['kind' => 'refund', 'amount' => 4000000, 'voided' => false],
    ['kind' => 'refund', 'amount' => 999, 'voided' => true],
], 'cancelled');
check('refunds subtracted', $t3['paid_total'], 6000000);
check('cancelled balance forced to 0', $t3['balance'], 0);
check('grand total kept on cancelled', $t3['grand_total'], 50000000);

$t4 = compute_totals(0, 0, 0, ['v' => ['unit' => 'fixed', 'selected' => true, 'rate' => 100000, 'qty' => null]],
    [['kind' => 'payment', 'amount' => 150000, 'voided' => false]], 'completed');
check('overpaid balance is negative', $t4['balance'], -50000);

$t5 = compute_totals(0, 0, 500001, ['v' => ['unit' => 'fixed', 'selected' => true, 'rate' => 500000, 'qty' => null]], [], 'draft');
check('discount > sub total rejected', array_keys(validate_totals($t5, 500001)), ['discount']);
check('discount = sub total allowed', validate_totals(compute_totals(0, 0, 500000, ['v' => ['unit' => 'fixed', 'selected' => true, 'rate' => 500000, 'qty' => null]], [], 'draft'), 500000), []);
$t6 = compute_totals(MONEY_MAX_PAISA, 100000, 0, [], [], 'draft');
check('overflowing total rejected', array_keys(validate_totals($t6, 0)), ['totals']);

// ---------------------------------------------------------------------------
// Refund policy suggestion
// ---------------------------------------------------------------------------
$r = refund_suggestion('2026-12-31', '2026-11-01', 5000, 2500, 10000000, 0, 10000000);
check('≥ 30 days uses pct_30', [$r['amount'], $r['pct']], [5000000, 5000]);
$r = refund_suggestion('2026-12-31', '2026-12-20', 5000, 2500, 10000000, 0, 10000000);
check('≥ 7 days uses pct_7', [$r['amount'], $r['pct']], [2500000, 2500]);
$r = refund_suggestion('2026-12-31', '2026-12-28', 5000, 2500, 10000000, 0, 10000000);
check('< 7 days suggests 0', [$r['amount'], $r['pct']], [0, 0]);
$r = refund_suggestion('2026-12-31', '2026-12-01', 5000, 2500, 10000000, 0, 10000000);
check('exactly 30 days uses pct_30', $r['pct'], 5000);
$r = refund_suggestion('2026-12-31', '2026-11-01', 5000, 2500, 10000000, 3000000, 7000000);
check('second refund: 50% of 1,00,000 − 30,000 already refunded', $r['amount'], 2000000);
$r = refund_suggestion('2026-12-31', '2026-11-01', 5000, 2500, 10000000, 0, 1500000);
check('never more than refundable', $r['amount'], 1500000);
$r = refund_suggestion(null, '2026-11-01', 5000, 2500, 10000000, 0, 10000000);
check('no event date → no suggestion', [$r['amount'], $r['reason'] !== null], [null, true]);
$r = refund_suggestion('2026-12-31', '2026-11-01', null, 2500, 10000000, 0, 10000000);
check('empty pct → no suggestion', [$r['amount'], $r['reason'] !== null], [null, true]);
$r = refund_suggestion('2026-12-31', '2027-01-05', 5000, 2500, 10000000, 0, 10000000);
check('cancelled after event → no suggestion', [$r['amount'], $r['reason'] !== null], [null, true]);
$r = refund_suggestion('2026-12-31', '2026-11-01', 5000, 2500, 10000000, 10000000, 0);
check('nothing refundable → no suggestion', [$r['amount'], $r['reason'] !== null], [null, true]);

// ---------------------------------------------------------------------------
// IDs, CNIC, paths
// ---------------------------------------------------------------------------
check('SLA id', format_sla_id(2026, 1), 'SLA-2026-0001');
check('SLA id 4 digits', format_sla_id(2026, 1234), 'SLA-2026-1234');
check('SLA id 5 digits', format_sla_id(2026, 12345), 'SLA-2026-12345');
check('doc number rev 0', format_document_number('SLA-2026-0001', 'SLA', 0), 'SLA-2026-0001');
check('invoice number rev 1', format_document_number('SLA-2026-0001', 'INV', 1), 'INV-2026-0001 Rev 1');

check('cnic valid', is_valid_cnic('42101-1234567-1'), true);
foreach (['4210112345671', '42101-123456-1', '42101-1234567-12', 'abcde-1234567-1', ' 42101-1234567-1'] as $bad) {
    check("cnic rejects '$bad'", is_valid_cnic($bad), false);
}

check('path inside', path_is_inside('/home/u/public_html/app', '/home/u/public_html'), true);
check('path same dir', path_is_inside('/home/u/public_html', '/home/u/public_html'), true);
check('path sibling prefix', path_is_inside('/home/u/public_html2/app', '/home/u/public_html'), false);
check('path outside', path_is_inside('/home/u/app', '/home/u/public_html'), false);
check('path windows separators', path_is_inside('C:\\site\\public\\app', 'C:/site/public'), true);

check('south asian grouping', group_digits_south_asian('1234567'), '12,34,567');
check('h() escapes', h('<a href="x">\'&'), '&lt;a href=&quot;x&quot;&gt;&#039;&amp;');
check('h(null)', h(null), '');

// ---------------------------------------------------------------------------
// Auth: usernames, passwords, device cookie, forced-change allowlist
// ---------------------------------------------------------------------------
check('username normalized', normalize_username('  Uzair.Khan '), 'uzair.khan');
check('username valid', is_valid_username('uzair_6925'), true);
foreach (['ab', 'has space', 'UPPER', str_repeat('a', 51), 'bad!char', ''] as $bad) {
    check("username rejects '$bad'", is_valid_username($bad), false);
}

check('good password', password_problems('correct horse', 'correct horse', 'user1'), []);
check('password too short', count(password_problems('short', 'short', 'user1')), 1);
check('password 10 chars ok', password_problems('abcdefghij', 'abcdefghij', 'user1'), []);
check('password 9 chars rejected', count(password_problems('abcdefghi', 'abcdefghi', 'user1')), 1);
check('password over 72 bytes rejected', count(password_problems(str_repeat('a', 73), str_repeat('a', 73), 'user1')), 1);
check('password 72 bytes ok', password_problems(str_repeat('a', 72), str_repeat('a', 72), 'user1'), []);
check('password mismatch', count(password_problems('abcdefghij', 'abcdefghik', 'user1')), 1);
check('password same as current', count(password_problems('Temp123456', 'Temp123456', 'user1', 'Temp123456')), 1);
check('password same as username', count(password_problems('user.name1', 'user.name1', 'user.name1')), 1);
check('multibyte chars count as characters', password_problems('پاکستان۱۲۳', 'پاکستان۱۲۳', 'x'), []);

$temp = generate_temp_password();
check('temp password length', strlen($temp), 12);
check('temp password alphabet', preg_match('/^[A-HJ-NP-Za-km-np-z2-9]{12}$/', $temp), 1);
check('temp passwords differ', generate_temp_password() !== generate_temp_password(), true);
check('temp password passes the rules', password_problems($temp, $temp, 'user1'), []);

// Admin "Add user" form.
$userIn = ['firm_name' => ' Khan Events ', 'rep_name' => 'Uzair Khan', 'contact' => '0300-1234567', 'username' => ' Uzair.Khan '];
check('add user: cleaned', clean_user_input($userIn),
    ['firm_name' => 'Khan Events', 'rep_name' => 'Uzair Khan', 'contact' => '0300-1234567', 'username' => 'uzair.khan']);
$userRefused = static function (array $in): ?string {
    try {
        clean_user_input($in);
        return null;
    } catch (AdminRefused $e) {
        return $e->getMessage();
    }
};
check('add user: firm name required', $userRefused(['firm_name' => '  '] + $userIn), 'Enter the firm name.');
check('add user: representative required', $userRefused(['rep_name' => ''] + $userIn), 'Enter the representative name.');
check('add user: contact too long', $userRefused(['contact' => str_repeat('1', 51)] + $userIn) !== null, true);
check('add user: bad username', $userRefused(['username' => 'a b'] + $userIn) !== null, true);
check('add user: missing username', $userRefused(array_diff_key($userIn, ['username' => 1])) !== null, true);
check('add user: non-string field', $userRefused(['firm_name' => ['x']] + $userIn), 'Enter the firm name.');

$secret = str_repeat('k', 64);
$hash = '$2y$10$abcdefghijklmnopqrstuuM5Cq2eAkHkG7Vw1d2m3n4o5p6q7r8s9t';
$now = 1790000000;
$cookie = device_cookie_value(7, $hash, $secret, $now);
check('device cookie valid', device_cookie_is_valid($cookie, 7, $hash, $secret, $now + 60), true);
check('device cookie other user', device_cookie_is_valid($cookie, 8, $hash, $secret, $now), false);
check('device cookie after password change', device_cookie_is_valid($cookie, 7, $hash . 'x', $secret, $now), false);
check('device cookie wrong secret', device_cookie_is_valid($cookie, 7, $hash, str_repeat('j', 64), $now), false);
check('device cookie expired after 90 days', device_cookie_is_valid($cookie, 7, $hash, $secret, $now + 91 * 86400), false);
check('device cookie valid at 89 days', device_cookie_is_valid($cookie, 7, $hash, $secret, $now + 89 * 86400), true);
check('device cookie tampered', device_cookie_is_valid(substr($cookie, 0, -1) . (substr($cookie, -1) === 'a' ? 'b' : 'a'), 7, $hash, $secret, $now), false);
check('device cookie issued in the future', device_cookie_is_valid(device_cookie_value(7, $hash, $secret, $now + 3600), 7, $hash, $secret, $now), false);
check('device cookie garbage', device_cookie_is_valid('7.123.zz', 7, $hash, $secret, $now), false);
check('device cookie null', device_cookie_is_valid(null, 7, $hash, $secret, $now), false);

check('allowlist change password', is_password_change_allowlisted('/auth/change_password.php'), true);
check('allowlist logout', is_password_change_allowlisted('/portal/auth/logout.php'), true);
check('allowlist blocks home', is_password_change_allowlisted('/index.php'), false);
check('allowlist blocks admin', is_password_change_allowlisted('/admin/users.php'), false);
check('allowlist blocks look-alike', is_password_change_allowlisted('/auth/change_password.php.bak'), false);

// ---------------------------------------------------------------------------
// Booking field parsing
// ---------------------------------------------------------------------------
check('text trimmed/empty → null', parse_booking_value('text', 10, ''), null);
check('text kept', parse_booking_value('text', 10, 'Ali'), 'Ali');
check_rejects('text too long', fn() => parse_booking_value('text', 3, 'abcd'));
check('text length counts characters, not bytes (Urdu)', parse_booking_value('text', 5, 'عائشہ'), 'عائشہ');
check('select valid', parse_booking_value('select', EVENT_TYPES, 'Valima'), 'Valima');
check_rejects('select invalid', fn() => parse_booking_value('select', EVENT_TYPES, 'Rave'));
check('cnic formatted from 13 digits', parse_booking_value('cnic', 15, '4210112345671'), '42101-1234567-1');
check('cnic with dashes', parse_booking_value('cnic', 15, '42101-1234567-1'), '42101-1234567-1');
check_rejects('cnic wrong length', fn() => parse_booking_value('cnic', 15, '42101-123456-1'));
check('date valid', parse_booking_value('date', null, '2026-12-20'), '2026-12-20');
check_rejects('date 31 Feb', fn() => parse_booking_value('date', null, '2026-02-31'));
check_rejects('date wrong format', fn() => parse_booking_value('date', null, '20/12/2026'));
check_rejects('date year 1999', fn() => parse_booking_value('date', null, '1999-12-20'));
check('time HH:MM → HH:MM:SS', parse_booking_value('time', null, '18:30'), '18:30:00');
check_rejects('time 24:00', fn() => parse_booking_value('time', null, '24:00'));
check('count', parse_booking_value('count', null, '250'), 250);
check_rejects('count negative', fn() => parse_booking_value('count', null, '-5'));
check('money → decimal', parse_booking_value('money', null, '1,500'), '1500.00');
check_rejects('money negative', fn() => parse_booking_value('money', null, '-1'));
check('pct → decimal', parse_booking_value('pct', null, '50'), '50.00');
check_rejects('pct over 100', fn() => parse_booking_value('pct', null, '150'));

// ---------------------------------------------------------------------------
// Authorization rule (load_booking_for_user)
// ---------------------------------------------------------------------------
$adminU = ['id' => 1, 'role' => 'admin'];
$owner = ['id' => 5, 'role' => 'user'];
$other = ['id' => 6, 'role' => 'user'];
$bk = static fn(string $status, ?int $userId = 5) => ['status' => $status, 'user_id' => $userId];
check('owner views own booking', booking_allows($bk('confirmed'), $owner, 'view'), true);
check('other user cannot view', booking_allows($bk('draft'), $other, 'view'), false);
check('user cannot view unassigned draft', booking_allows($bk('draft', null), $owner, 'view'), false);
check('owner edits own draft', booking_allows($bk('draft'), $owner, 'edit'), true);
check('owner cannot edit confirmed', booking_allows($bk('confirmed'), $owner, 'edit'), false);
check('admin edits confirmed (amendment)', booking_allows($bk('confirmed'), $adminU, 'edit'), true);
check('admin cannot edit completed', booking_allows($bk('completed'), $adminU, 'edit'), false);
check('admin cannot edit cancelled', booking_allows($bk('cancelled'), $adminU, 'edit'), false);
check('owner deletes own draft', booking_allows($bk('draft'), $owner, 'delete'), true);
check('nobody deletes confirmed', [booking_allows($bk('confirmed'), $owner, 'delete'), booking_allows($bk('confirmed'), $adminU, 'delete')], [false, false]);
check('money/confirm is admin only', [booking_allows($bk('draft'), $owner, 'admin'), booking_allows($bk('draft'), $adminU, 'admin')], [false, true]);
check('unknown intent denied', booking_allows($bk('draft'), $adminU, 'anything'), false);
check('scope: admin sees all', booking_scope_sql($adminU), ['1 = 1', []]);
check('scope: user sees own', booking_scope_sql($owner), ['b.user_id = ?', [5]]);

// ---------------------------------------------------------------------------
// Line validation
// ---------------------------------------------------------------------------
$formLines = [
    'l1' => ['key' => 'l1', 'line_id' => 1, 'catalog_id' => 1, 'section' => 'charge', 'label' => 'Venue', 'unit' => 'fixed', 'selected' => true, 'qty' => null, 'rate' => '5000.00', 'notes' => null, 'sort_order' => 1],
    'c2' => ['key' => 'c2', 'line_id' => null, 'catalog_id' => 2, 'section' => 'charge', 'label' => 'Tracing', 'unit' => 'per unit', 'selected' => false, 'qty' => null, 'rate' => '300.00', 'notes' => null, 'sort_order' => 2],
    'c3' => ['key' => 'c3', 'line_id' => null, 'catalog_id' => 3, 'section' => 'decor_light', 'label' => 'LED', 'unit' => 'fixed', 'selected' => false, 'qty' => null, 'rate' => null, 'notes' => null, 'sort_order' => 1],
    'c4' => ['key' => 'c4', 'line_id' => null, 'catalog_id' => 4, 'section' => 'ops_item', 'label' => 'Sofa', 'unit' => 'per unit', 'selected' => false, 'qty' => null, 'rate' => null, 'notes' => null, 'sort_order' => 1],
];
[$pl, $pe] = parse_booking_lines([
    'l1' => ['present' => '1', 'rate' => '6,000', 'notes' => ' ok '],          // unticked existing line: kept, unselected
    'c2' => ['present' => '1', 'selected' => '1', 'rate' => '300', 'qty' => '4'],
    'c3' => ['present' => '1', 'rate' => '999'],                                // unticked catalog item: not stored
    'c4' => ['present' => '1', 'selected' => '1', 'qty' => '2', 'rate' => '50'], // ops: a posted rate is ignored
    'l99' => ['present' => '1', 'selected' => '1', 'rate' => '1'],              // not on this form: ignored
], $formLines);
check('line errors none', $pe, []);
check('lines kept', array_keys($pl), ['l1', 'c2', 'c4']);
check('existing line unselected with new rate', $pl['l1']['new'], ['selected' => false, 'rate' => '6000.00', 'qty' => null, 'notes' => 'ok']);
check('per-unit charge qty', $pl['c2']['new'], ['selected' => true, 'rate' => '300.00', 'qty' => 4, 'notes' => null]);
check('ops item keeps qty and takes no rate', [$pl['c4']['new']['qty'], $pl['c4']['new']['rate']], [2, null]);
check('section/label come from the form lines, not the POST', [$pl['c2']['section'], $pl['c2']['label']], ['charge', 'Tracing']);
[, $pe] = parse_booking_lines(['c2' => ['present' => '1', 'selected' => '1', 'rate' => '', 'qty' => '1']], $formLines);
check('selected charge needs a rate', array_keys($pe), ['line_c2']);
[, $pe] = parse_booking_lines(['c2' => ['present' => '1', 'selected' => '1', 'rate' => '10', 'qty' => '']], $formLines);
check('selected per-unit charge needs a qty', array_keys($pe), ['line_c2']);
[, $pe] = parse_booking_lines(['l1' => ['present' => '1', 'selected' => '1', 'rate' => '-5']], $formLines);
check('negative rate rejected', array_keys($pe), ['line_l1']);
[$pl] = parse_booking_lines([], $formLines);
check('no posted lines → no changes', $pl, []);
// A user ticks what the event needs but never sets a rate: the posted rate is ignored.
[$pl, $pe] = parse_booking_lines([
    'l1' => ['present' => '1', 'selected' => '1', 'rate' => '1', 'notes' => 'needed'],
    'c2' => ['present' => '1', 'selected' => '1', 'rate' => '1', 'qty' => '4'],
    'c3' => ['present' => '1', 'selected' => '1', 'rate' => '999'],
], $formLines, false);
check('user line errors none', $pe, []);
check('user keeps the stored rate on an existing charge', $pl['l1']['new'], ['selected' => true, 'rate' => '5000.00', 'qty' => null, 'notes' => 'needed']);
check('user-ticked charge takes the catalog rate', $pl['c2']['new'], ['selected' => true, 'rate' => '300.00', 'qty' => 4, 'notes' => null]);
check('user cannot price a decor item', $pl['c3']['new']['rate'], null);
$unpriced = ['c5' => ['key' => 'c5', 'line_id' => null, 'catalog_id' => 5, 'section' => 'charge', 'label' => 'Service', 'unit' => 'fixed', 'selected' => false, 'qty' => null, 'rate' => null, 'notes' => null, 'sort_order' => 3]];
[$pl, $pe] = parse_booking_lines(['c5' => ['present' => '1', 'selected' => '1', 'rate' => '900']], $unpriced, false);
check('user may tick a charge that has no rate yet', [$pe, $pl['c5']['new']['rate']], [[], null]);
[, $pe] = parse_booking_lines(['c5' => ['present' => '1', 'selected' => '1', 'rate' => '']], $unpriced, true);
check('admin must price that charge', array_keys($pe), ['line_c5']);

check('field diff', booking_field_diff(['guests' => 250, 'discount' => '0.00', 'theme' => null, 'setup_time' => '18:00:00'],
    ['guests' => 300, 'discount' => '0.00', 'theme' => null, 'setup_time' => '18:00:00']), ['guests' => [250, 300]]);

// ---------------------------------------------------------------------------
// Confirmed bookings: direct edit vs amendment
// ---------------------------------------------------------------------------
$oldB = ['client_contact' => '0300', 'guests' => 200, 'client_company' => null, 'user_sign_name' => 'Uzair', 'decor_by' => null];
$line = static fn(string $section, bool $was, bool $now) => ['line_id' => 7, 'section' => $section, 'label' => 'X', 'selected' => $was,
    'qty' => null, 'rate' => null, 'notes' => null, 'new' => ['selected' => $now, 'qty' => null, 'rate' => null, 'notes' => null]];

$c = classify_confirmed_changes($oldB, ['client_contact' => '0311'] + $oldB, []);
check('contact change is a direct edit', [array_keys($c['changed']), $c['amendment_fields']], [['client_contact'], []]);
$c = classify_confirmed_changes($oldB, ['user_sign_name' => 'U. Khan', 'decor_by' => 'In-house'] + $oldB, []);
check('signature and decor-by are direct edits', $c['amendment_fields'], []);
$c = classify_confirmed_changes($oldB, ['guests' => 250, 'client_contact' => '0311'] + $oldB, []);
check('mixed save counts as an amendment', array_keys($c['amendment_fields']), ['guests']);
$c = classify_confirmed_changes($oldB, ['client_company' => 'ACME'] + $oldB, []);
check('company is an amendment field', array_keys($c['amendment_fields']), ['client_company']);
$c = classify_confirmed_changes($oldB, $oldB, [$line('ops_item', false, true)]);
check('ops item change is a direct edit', [count($c['line_changes']), $c['amendment_lines']], [1, []]);
$c = classify_confirmed_changes($oldB, $oldB, [$line('decor_light', false, true)]);
check('decor checklist change is an amendment', count($c['amendment_lines']), 1);
$c = classify_confirmed_changes($oldB, $oldB, [$line('charge', true, true)]);
check('unchanged line is no change', [$c['line_changes'], $c['changed']], [[], []]);

check('LIKE escape', like_escape('50%_off\\'), '50\\%\\_off\\\\');

// ---------------------------------------------------------------------------
// Payments
// ---------------------------------------------------------------------------
check('payment allowed on draft/confirmed/completed', [payment_kind_allowed('draft', 'payment'), payment_kind_allowed('confirmed', 'payment'), payment_kind_allowed('completed', 'payment')], [true, true, true]);
check('payment not allowed on cancelled', payment_kind_allowed('cancelled', 'payment'), false);
check('refund only on cancelled', [payment_kind_allowed('cancelled', 'refund'), payment_kind_allowed('confirmed', 'refund'), payment_kind_allowed('completed', 'refund')], [true, false, false]);
$today = date('Y-m-d');
[$pd, $pe] = parse_payment_input(['amount' => 'Rs. 1,00,000', 'paid_on' => $today, 'method' => 'bank_transfer', 'bank_name' => ' HBL ', 'reference_no' => 'TX-1'], 'payment');
check('valid payment', [$pe, $pd['amount'], $pd['bank_name'], $pd['notes']], [[], '100000.00', 'HBL', null]);
foreach (['0', '0.00', '-500', 'abc', '', '1e5'] as $bad) {
    [, $pe] = parse_payment_input(['amount' => $bad, 'paid_on' => $today, 'method' => 'cash'], 'payment');
    check("payment amount '$bad' rejected", array_keys($pe), ['amount']);
}
[, $pe] = parse_payment_input(['amount' => '100', 'paid_on' => date('Y-m-d', strtotime('+1 day')), 'method' => 'cash'], 'payment');
check('future date rejected', array_keys($pe), ['paid_on']);
[, $pe] = parse_payment_input(['amount' => '100', 'paid_on' => '2026-02-30', 'method' => 'cash'], 'payment');
check('invalid date rejected', array_keys($pe), ['paid_on']);
[, $pe] = parse_payment_input(['amount' => '100', 'paid_on' => $today, 'method' => 'barter'], 'payment');
check('unknown method rejected', array_keys($pe), ['method']);
[, $pe] = parse_payment_input(['amount' => '100', 'paid_on' => $today, 'method' => 'cheque'], 'payment');
check('cheque needs a cheque number', array_keys($pe), ['reference_no']);
[$pd, $pe] = parse_payment_input(['amount' => '100', 'paid_on' => $today, 'method' => 'cash'], 'refund');
check('refund kind kept', [$pe, $pd['kind']], [[], 'refund']);
$_SESSION = [];
$tok = payment_form_token();
check('form token usable once', [consume_payment_form_token($tok), consume_payment_form_token($tok)], [true, false]);
check('unknown form token rejected', consume_payment_form_token('forged'), false);

// ---------------------------------------------------------------------------
// Documents
// ---------------------------------------------------------------------------
check('display value', [dv('Ali'), dv(''), dv(null), dv('  '), dv(null, '____')], ['Ali', '—', '—', '—', '____']);
check('display date', [ddate('2026-12-20'), ddate(null), ddate('', 'n/a')], ['20 Dec 2026', '—', 'n/a']);
check('display time', [dtime('18:30:00'), dtime('09:05:00'), dtime(null)], ['6:30 pm', '9:05 am', '—']);
$bk = ['event_type' => 'Valima', 'event_type_other' => null, 'menu_type' => 'Other', 'menu_type_other' => 'Mixed grill',
    'event_date' => '2026-12-20', 'status' => 'confirmed'];
check('choice: plain option', dchoice($bk, 'event_type', 'event_type_other'), 'Valima');
check('choice: Other uses the typed text', dchoice($bk, 'menu_type', 'menu_type_other'), 'Mixed grill');
check('choice: Other with no text', dchoice(['menu_type' => 'Other', 'menu_type_other' => null], 'menu_type', 'menu_type_other'), 'Other');
check('watermark: draft', document_watermark(['status' => 'draft']), 'DRAFT');
check('watermark: cancelled', document_watermark(['status' => 'cancelled']), 'CANCELLED');
check('watermark: none when confirmed or completed',
    [document_watermark(['status' => 'confirmed']), document_watermark(['status' => 'completed'])], [null, null]);
check('invoice description', invoice_description($bk, 'Lawn A'),
    'Catering, decoration & event management services — Valima (Mixed grill menu) at Lawn A on 20 Dec 2026.');
check('invoice description without a venue or date',
    invoice_description(['event_type' => null, 'event_type_other' => null, 'menu_type' => null, 'menu_type_other' => null, 'event_date' => null], null),
    'Catering, decoration & event management services — event at Booking Organizer.');
check('agreement number keeps Rev', format_document_number('SLA-2026-0007', 'SLA', 2), 'SLA-2026-0007 Rev 2');
check('invoice number keeps Rev', format_document_number('SLA-2026-0007', 'INV', 2), 'INV-2026-0007 Rev 2');

// ---------------------------------------------------------------------------
// Attachments and admin data
// ---------------------------------------------------------------------------
check('file name kept', sanitize_file_name('Signed SLA Rev 0.pdf'), 'Signed SLA Rev 0.pdf');
check('path stripped from the file name', sanitize_file_name('C:\\Users\\me\\scan.pdf'), 'C: Users me scan.pdf');
check('quotes and control characters stripped', sanitize_file_name("bad\"name\r\n.pdf"), 'badname.pdf');
check('empty file name replaced', sanitize_file_name('   '), 'upload');
check('long file name trimmed', mb_strlen(sanitize_file_name(str_repeat('a', 400) . '.pdf')), 255);

$draft = ['user_id' => 5, 'status' => 'draft'];
$confirmed = ['user_id' => 5, 'status' => 'confirmed'];
$adminUser = ['id' => 1, 'role' => 'admin'];
$ownerUser = ['id' => 5, 'role' => 'user'];
$otherUser = ['id' => 6, 'role' => 'user'];
check('owner user uploads to their draft', can_upload_attachment($draft, $ownerUser), true);
check('owner user cannot upload to a confirmed booking', can_upload_attachment($confirmed, $ownerUser), false);
check('other user cannot upload', can_upload_attachment($draft, $otherUser), false);
check('admin uploads in any status', [can_upload_attachment($draft, $adminUser), can_upload_attachment($confirmed, $adminUser),
    can_upload_attachment(['user_id' => 5, 'status' => 'cancelled'], $adminUser)], [true, true, true]);
check('accepted upload types', array_keys(UPLOAD_TYPES), ['application/pdf', 'image/jpeg', 'image/png']);
check('upload limit is 5 MB', UPLOAD_MAX_BYTES, 5242880);

$catalog = clean_catalog_input(['name' => ' Valet Parking ', 'unit' => 'fixed', 'default_rate' => '9,500', 'sort_order' => '20', 'is_active' => '1'], false);
check('catalog input cleaned', $catalog, ['name' => 'Valet Parking', 'unit' => 'fixed', 'sort_order' => 20, 'is_active' => 1, 'default_rate' => '9500.00']);
check('catalog rate may be empty', clean_catalog_input(['name' => 'X', 'unit' => 'fixed', 'default_rate' => '', 'sort_order' => '0'], false)['default_rate'], null);
check('unticked "offered" retires the item', clean_catalog_input(['name' => 'X', 'unit' => 'fixed', 'sort_order' => '0'], false)['is_active'], 0);
$refused = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (AdminRefused $e) {
        return $e->getMessage();
    }
};
check('catalog name required', $refused(fn() => clean_catalog_input(['name' => '  ', 'unit' => 'fixed'], false)) !== null, true);
check('catalog unit must be known', $refused(fn() => clean_catalog_input(['name' => 'X', 'unit' => 'per kilo'], false)) !== null, true);
check('catalog section must be known on create', $refused(fn() => clean_catalog_input(['name' => 'X', 'unit' => 'fixed', 'section' => 'nope'], true)) !== null, true);
check('catalog section accepted on create', clean_catalog_input(['name' => 'X', 'unit' => 'fixed', 'section' => 'ops_item', 'sort_order' => '0'], true)['section'], 'ops_item');
check('ops item default rate is dropped', clean_catalog_input(['name' => 'X', 'unit' => 'fixed', 'section' => 'ops_item', 'default_rate' => '500', 'sort_order' => '0'], true)['default_rate'], null);
check('negative rate refused', $refused(fn() => clean_catalog_input(['name' => 'X', 'unit' => 'fixed', 'default_rate' => '-5'], false)) !== null, true);
check('sort order must be a whole number', $refused(fn() => clean_sort_order('abc')) !== null, true);
check('sort order default', clean_sort_order(''), 0);

// ---------------------------------------------------------------------------
// HTTPS redirect target (production hardening)
// ---------------------------------------------------------------------------
check('canonical host wins over the Host header',
    https_redirect_target(['HTTP_HOST' => 'evil.example', 'REQUEST_URI' => '/booking/list.php'], 'aomess.pk'),
    'https://aomess.pk/booking/list.php');
check('host header used when no canonical host is set',
    https_redirect_target(['HTTP_HOST' => 'aomess.pk:8080', 'REQUEST_URI' => '/'], ''), 'https://aomess.pk:8080/');
check('host header filtered', https_redirect_target(['HTTP_HOST' => "aomess.pk/evil\r\nX: y", 'REQUEST_URI' => '/'], ''),
    'https://aomess.pkevilX:y/');
check('no host at all', https_redirect_target(['REQUEST_URI' => '/'], ''), null);
check('relative request URI forced to root',
    https_redirect_target(['HTTP_HOST' => 'aomess.pk', 'REQUEST_URI' => 'https://evil.example/x'], ''), 'https://aomess.pk/');

// ---------------------------------------------------------------------------
// Dashboard: chart scales, short money figures, periods
// ---------------------------------------------------------------------------
check('axis step for nothing', axis_step(0), 1);
check('axis step small count', axis_step(3), 1);
check('axis step count of 7', axis_step(7), 2);
check('axis step count of 20', axis_step(20), 5);
check('axis step uses 25', axis_step(90), 25);
check('axis step 1,30,000', axis_step(130000), 50000);
check('axis step exact fit', axis_step(400), 100);
check('axis ticks', axis_ticks(7), [0, 2, 4, 6, 8]);
check('axis ticks are whole numbers for 1', axis_ticks(1), [0, 1, 2, 3, 4]);
check('compact zero', compact_rs(0), 'Rs. 0');
check('compact below a thousand', compact_rs(95000), 'Rs. 950');
check('compact thousands', compact_rs(4550000), 'Rs. 45.5K');
check('compact drops .0', compact_rs(5000000), 'Rs. 50K');
check('compact lakh', compact_rs(123456700), 'Rs. 12.3 Lakh');
check('compact crore', compact_rs(1234567800), 'Rs. 1.2 Cr');
check('compact big crore grouped', compact_rs(123456789000), 'Rs. 123.5 Cr');
check('compact rounds up into the next unit', compact_rs(999600000), 'Rs. 1 Cr');
check('compact 99,960 is 1 Lakh, not 100K', compact_rs(9996000), 'Rs. 1 Lakh');
check('compact negative', compact_rs(-500000), 'Rs. -5K');
check('percent up', percent_change(150, 100), 50);
check('percent down', percent_change(75, 100), -25);
check('percent from nothing has no figure', percent_change(10, 0), null);
check('empty chart', chart_is_empty([['values' => [0, 0]], ['values' => [0]]]), true);
check('chart with one value', chart_is_empty([['values' => [0, 0]], ['values' => [3]]]), false);
check('rank bar with no value draws nothing', strpos(svg_rank_bar(0, 10, 's-booked'), '<rect') === false, true);
check('rank bar is a share of the largest', strpos(svg_rank_bar(5, 10, 's-booked'), 'width="50%"') !== false, true);
check('a tiny share is still visible', strpos(svg_rank_bar(1, 1000, 's-booked'), 'width="1%"') !== false, true);

$chart = svg_column_chart(
    [['label' => 'Jan', 'sub' => '2026', 'title' => 'January 2026'], ['label' => 'Feb', 'sub' => '', 'title' => 'February 2026']],
    [['class' => 's-booked', 'name' => 'A <b>', 'values' => [4, 0], 'display' => ['4', '0']],
     ['class' => 's-collected', 'name' => 'B', 'values' => [-2, 8], 'display' => ['-2', '8']]],
    'strval', false, 'Test "chart"');
check('chart escapes its labels', strpos($chart, 'A <b>') === false && strpos($chart, 'A &lt;b&gt;') !== false, true);
check('chart has one target per period', substr_count($chart, 'class="chart-hit"'), 2);
check('zero and negative values draw no column', substr_count($chart, 'class="chart-mark'), 2);
check('negative value still reported', strpos($chart, 'B -2') !== false, true);
check('chart has no style attribute (CSP)', strpos($chart, 'style=') === false, true);

check('share of a whole', percent_of(1, 3), 33);
check('share is capped at 100', percent_of(150, 100), 100);
check('share of nothing', percent_of(5, 0), 0);
check('negative share is 0', percent_of(-5, 100), 0);

$area = svg_area_chart(
    [['label' => 'Jan', 'sub' => '', 'title' => 'January 2026'], ['label' => 'Feb', 'sub' => '', 'title' => 'February 2026']],
    [['class' => 's-booked', 'name' => 'Booked', 'values' => [4, 8], 'display' => ['4', '8']]], 'strval', 'Area');
check('area chart has a line, a wash and a dot per period',
    [substr_count($area, 'class="chart-line'), substr_count($area, 'class="chart-area'), substr_count($area, 'class="chart-dot')], [1, 1, 2]);
check('area chart has one target per period', substr_count($area, 'class="chart-hit"'), 2);
check('area chart has no style attribute (CSP)', strpos($area, 'style=') === false, true);

$donut = svg_donut([
    ['class' => 's-cash', 'name' => 'Cash', 'value' => 75, 'display' => 'Rs. 75'],
    ['class' => 's-online', 'name' => 'Online <i>', 'value' => 25, 'display' => 'Rs. 25'],
    ['class' => 's-cheque', 'name' => 'Cheque', 'value' => 0, 'display' => 'Rs. 0'],
    ['class' => 's-bank-transfer', 'name' => 'Bank', 'value' => -10, 'display' => 'Rs. -10'],
], '100', 'collected', 'Donut');
check('donut draws only the shares above zero', substr_count($donut, 'class="donut-seg'), 2);
check('donut escapes its names', strpos($donut, 'Online <i>') === false, true);
check('donut with nothing in it is just the track', substr_count(svg_donut([], '0', 'x', 'y'), 'donut-seg'), 0);
check('gauge shows its percentage', strpos(svg_gauge(72, 's-collected', 'g'), '>72%<') !== false, true);
check('gauge is capped', strpos(svg_gauge(140, 's-collected', 'g'), '>100%<') !== false, true);
check('empty gauge has a track and no fill', substr_count(svg_gauge(0, 's-collected', 'g'), 'gauge-fill'), 0);
check('every icon is drawn', strpos(dash_icon('venue'), '<path') !== false && dash_icon('nope') !== '', true);

$p = dashboard_period('12m', new DateTimeImmutable('2026-09-30'));
check('12m period', [$p['start'], $p['end'], count($p['months']), $p['months'][0], $p['months'][11]],
    ['2025-10-01', '2026-09-30', 12, '2025-10', '2026-09']);
check('12m comparison', [$p['previous']['start'], $p['previous']['end']], ['2024-10-01', '2025-09-30']);
$p = dashboard_period('6m', new DateTimeImmutable('2026-03-31'));
check('6m period from a 31st', [$p['start'], $p['end'], $p['months']],
    ['2025-10-01', '2026-03-31', ['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03']]);
check('6m comparison', [$p['previous']['start'], $p['previous']['end']], ['2025-04-01', '2025-09-30']);
$p = dashboard_period('year', new DateTimeImmutable('2026-09-30'));
check('year period', [$p['start'], $p['end'], count($p['months']), $p['previous']], ['2026-01-01', '2026-12-31', 12, null]);
check('unknown range falls back', dashboard_period('<script>', new DateTimeImmutable('2026-09-30'))['key'], '12m');
check('february end in a leap year', dashboard_period('6m', new DateTimeImmutable('2028-02-10'))['end'], '2028-02-29');
$day = new DateTimeImmutable('2026-09-30');
$p = dashboard_period('custom', $day, '2026-02', '2026-04');
check('custom period', [$p['key'], $p['start'], $p['end'], $p['months'], $p['clamped']],
    ['custom', '2026-02-01', '2026-04-30', ['2026-02', '2026-03', '2026-04'], false]);
check('custom comparison is the same length before it', [$p['previous']['start'], $p['previous']['end'], $p['previous']['label']],
    ['2025-11-01', '2026-01-31', 'previous 3 months']);
check('custom range given backwards is put right', dashboard_period('custom', $day, '2026-04', '2026-02')['months'], ['2026-02', '2026-03', '2026-04']);
check('one-month custom range', [count(dashboard_period('custom', $day, '2026-02', '2026-02')['months']),
    dashboard_period('custom', $day, '2026-02', '2026-02')['previous']['label']], [1, 'previous month']);
$p = dashboard_period('custom', $day, '2020-01', '2026-01');
check('custom range is capped', [count($p['months']), $p['clamped'], $p['end']], [DASHBOARD_MAX_MONTHS, true, '2021-12-31']);
check('unreadable custom range falls back', dashboard_period('custom', $day, '2026-13', 'x')['key'], '12m');
check('custom range needs both ends', dashboard_period('custom', $day, '2026-02', '')['key'], '12m');
check('month parser rejects a day', dashboard_month('2026-02-01'), null);
check('activity subject: a booking', dashboard_activity_subject(['unique_id' => 'SLA-2026-0007', 'revision' => 2, 'client_name' => 'Ayesha', 'details' => null]),
    'SLA-2026-0007 Rev 2 · Ayesha');
check('activity subject: an account', dashboard_activity_subject(['unique_id' => null, 'details' => '{"user_id":4,"username":"uzair"}']), 'uzair');
check('activity subject: nothing named', dashboard_activity_subject(['unique_id' => null, 'details' => 'not json']), '');
check('every audit action but sign-ins has a name', count(DASHBOARD_ACTIVITY), 25);

$labels = dashboard_month_labels(['2025-11', '2025-12', '2026-01']);
check('month labels', array_column($labels, 'label'), ['Nov', 'Dec', 'Jan']);
check('year under the first month and each January', array_column($labels, 'sub'), ['2025', '', '2026']);
check('admin home', home_path(['role' => 'admin']), 'admin/dashboard.php');
check('user home', home_path(['role' => 'user']), 'booking/list.php');

// ---------------------------------------------------------------------------
// Vendors: invoice totals, payment status, numbering
// ---------------------------------------------------------------------------
$buffet = vendor_line_amount(300, 250000);
check('vendor line: 300 × Rs. 2,500', $buffet, 75000000);
check('vendor line shown', format_rs($buffet), 'Rs. 7,50,000');
check_rejects('vendor line too large', static fn() => vendor_line_amount(100000, 99999999999));
$t = vendor_invoice_totals([75000000, 9000000, 12000000], 0, 0, 50000000);
check('vendor invoice: Rs. 9,60,000 total', [$t['sub_total'], $t['grand_total']], [96000000, 96000000]);
check('vendor invoice: Rs. 4,60,000 remaining', $t['balance'], 46000000);
check('vendor invoice: partially paid', vendor_payment_status('issued', $t['grand_total'], $t['paid_total']), 'partial');
check('vendor invoice: unpaid', vendor_payment_status('issued', 96000000, 0), 'unpaid');
check('vendor invoice: paid', vendor_payment_status('issued', 96000000, 96000000), 'paid');
check('vendor invoice: overpaid counts as paid', vendor_payment_status('issued', 96000000, 96000100), 'paid');
check('vendor invoice: void', vendor_payment_status('void', 96000000, 0), 'void');
$t = vendor_invoice_totals([1000000], 100000, 1600, 0);            // Rs. 10,000 − 1,000, then 16% tax
check('vendor invoice: tax on the discounted amount', [$t['taxable'], $t['tax_amount'], $t['grand_total']], [900000, 144000, 1044000]);
$t = vendor_invoice_totals([333], 0, 1250, 0);                     // 12.5% of Rs. 3.33 = 41.625 paisa
check('vendor invoice: tax rounds to the nearest paisa', $t['tax_amount'], 42);
check('vendor invoice: no problems', vendor_invoice_problems(vendor_invoice_totals([1000000], 0, 0, 0)), []);
check('vendor invoice: discount above sub total refused', count(vendor_invoice_problems(vendor_invoice_totals([1000], 2000, 0, 0))), 1);
check('vendor invoice: zero total refused', count(vendor_invoice_problems(vendor_invoice_totals([0], 0, 0, 0))), 1);
check('vendor invoice number', format_vendor_invoice_no(2026, 7), 'VINV-2026-0007');
check('vendor event label', vendor_event_label(['event_type' => 'Wedding', 'event_type_other' => null,
    'client_name' => 'Mr. Farid', 'unique_id' => 'SLA-2026-0001']), 'Wedding — Mr. Farid (SLA-2026-0001)');
check('vendor event label, other type, no client', vendor_event_label(['event_type' => 'Other', 'event_type_other' => 'Mehndi',
    'client_name' => '', 'unique_id' => 'SLA-2026-0002']), 'Mehndi (SLA-2026-0002)');
check('vendor contact line', vendor_contact_line(['contact_person' => 'Imran', 'phone' => '0300-1', 'phone2' => '0321-2']),
    'Imran — 0300-1 / 0321-2');
check('vendor contact line, empty', vendor_contact_line([]), '—');

// ---------------------------------------------------------------------------
// Menus
// ---------------------------------------------------------------------------
$mi = static fn(int $id, string $cat, string $dish, ?int $group, int $free = 0, int $live = 0) => ['id' => $id, 'dish_id' => 100 + $id,
    'category' => $cat, 'category_sort' => 0, 'dish' => $dish, 'choice_group' => $group, 'is_free' => $free, 'is_live' => $live];
$layout = menu_package_layout([$mi(1, 'Starter', 'Juice', 1), $mi(2, 'Starter', 'Lemonade', 1),
    $mi(3, 'Main Course', 'Biryani', 2), $mi(4, 'Main Course', 'Chargha', null, 0, 1), $mi(5, 'Main Course', 'Pulao', 2)]);
check('menu layout: categories in order', array_keys($layout), ['Starter', 'Main Course']);
check('menu layout: a choice group gathers its dishes', array_column($layout['Main Course'][0]['items'], 'dish'), ['Biryani', 'Pulao']);
check('menu layout: an included dish stays in place', $layout['Main Course'][1]['item']['dish'], 'Chargha');
check('menu dish label', menu_dish_label('Gulab Jamun', true, true), 'Gulab Jamun (Live) (Free)');
check('menu dish label: a name that says live is not marked twice', menu_dish_label('Garlic Naan (Live)', true, false), 'Garlic Naan (Live)');
check('menu price label, per guest', menu_package_price_label(['price_basis' => 'guest', 'per_head_rate' => '575.00']), 'Rs. 575 per guest');
check('menu price label, none', menu_package_price_label(['price_basis' => 'guest', 'per_head_rate' => null]), '');
check('menu selection key ignores order', menu_selection_key(3, [9, 4], [7, 2]), menu_selection_key(3, [4, 9], [2, 7]));
check('menu selection key: different package', menu_selection_key(3, [4], []) === menu_selection_key(4, [4], []), false);
$saved = json_encode([menu_snapshot_entry($mi(1, 'Starter', 'Juice', 1), false), menu_snapshot_entry($mi(4, 'Main Course', 'Chargha', null, 1, 1), false),
    menu_snapshot_entry(['id' => 55, 'name' => 'Fish Fry', 'category' => 'Sea Food', 'is_live' => 0], true)]);
check('menu saved copy, by category', menu_selection_by_category($saved),
    ['Starter' => ['Juice'], 'Main Course' => ['Chargha (Live) (Free)'], 'Sea Food' => ['Fish Fry']]);
check('menu saved copy: picks and extras', menu_selection_ids($saved), [[1], [55]]);
check('menu chooser from a booking', menu_input_from_booking(['menu_package_id' => '3', 'menu_selection' => $saved]),
    ['package' => 3, 'picks' => [1 => 1], 'extras' => [55 => true]]);
check('menu chooser from the form', menu_input_from_post(['menu_package_id' => '3', 'menu_pick' => [3 => [1 => '2'], 4 => [1 => '9']],
    'menu_extra' => ['55', 'x']]), ['package' => 3, 'picks' => [1 => 2], 'extras' => [55 => true]]);
check('menu: no saved copy', menu_selection_by_category(null), []);
$extrasJson = json_encode([
    menu_snapshot_entry($mi(1, 'Starter', 'Juice', null), false),
    menu_snapshot_entry(['id' => 55, 'name' => 'Fish Fry', 'category' => 'Sea Food', 'is_live' => 0, 'extra_rate' => '150.00', 'extra_unit' => 'per head'], true),
    menu_snapshot_entry(['id' => 56, 'name' => 'Live Kunafa', 'category' => 'Dessert', 'is_live' => 1, 'extra_rate' => '5000.00', 'extra_unit' => 'fixed'], true),
    menu_snapshot_entry(['id' => 57, 'name' => 'Paan', 'category' => 'Beverages', 'is_live' => 0, 'extra_rate' => null, 'extra_unit' => 'per head'], true),
]);
check('extra dishes become charge lines (package dishes do not)', menu_extra_charge_lines($extrasJson), [
    'menu_extra_55' => ['unit' => 'per head', 'selected' => true, 'rate' => 15000, 'qty' => null],
    'menu_extra_56' => ['unit' => 'fixed', 'selected' => true, 'rate' => 500000, 'qty' => null],
    'menu_extra_57' => ['unit' => 'per head', 'selected' => true, 'rate' => null, 'qty' => null],
]);
check('extra dishes in the totals: per guest, fixed, unpriced',
    compute_totals(57500, 300, 0, menu_extra_charge_lines($extrasJson), [], 'draft')['charges_total'], 15000 * 300 + 500000);
check('extra rate labels', [menu_extra_rate_label('150.00', 'per head'), menu_extra_rate_label('5000.00', 'fixed'), menu_extra_rate_label(null, 'per head')],
    ['Rs. 150 per guest', 'Rs. 5,000 fixed', '']);
[$repriced, $rateErrors] = menu_apply_extra_rates($extrasJson, ['55' => '120', '56' => '5,000', '57' => '40']);
check('admin re-prices extras', [$rateErrors, array_column(menu_selection_entries($repriced), 'rate', 'dish_id')[55],
    array_column(menu_selection_entries($repriced), 'rate', 'dish_id')[57]], [[], '120.00', '40.00']);
check('same rates posted again: the saved copy is unchanged', menu_apply_extra_rates($extrasJson, ['55' => '150', '56' => '5000', '57' => ''])[0], $extrasJson);
check('a bad extra rate is refused', array_keys(menu_apply_extra_rates($extrasJson, ['55' => 'abc'])[1]), ['menu_extra_rate_55']);
check('rates for package dishes are ignored', menu_apply_extra_rates($extrasJson, ['101' => '99'])[0], $extrasJson);
check('menu: unreadable saved copy', menu_selection_by_category('not json'), []);
check('menu: below the package minimum', menu_min_guests_shortfall(['min_guests' => '250'], 200), 250);
check('menu: at the package minimum', menu_min_guests_shortfall(['min_guests' => '250'], 250), null);
check('menu: no minimum', menu_min_guests_shortfall(['min_guests' => null], 10), null);
check('invoice description with a menu package',
    invoice_description(['event_type' => 'Barat', 'event_type_other' => null, 'menu_type' => 'Buffet', 'menu_type_other' => null,
        'menu_package_name' => 'Menu 02', 'event_date' => null], null),
    'Catering, decoration & event management services — Barat (Buffet menu, Menu 02) at Booking Organizer.');

// ---------------------------------------------------------------------------
echo "\n", $passed, ' passed, ', count($failed), " failed\n";
foreach ($failed as $f) {
    echo "\nFAIL: $f\n";
}
exit($failed ? 1 : 0);
