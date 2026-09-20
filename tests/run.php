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

check('good password', password_problems('correct horse', 'correct horse', 'vendor1'), []);
check('password too short', count(password_problems('short', 'short', 'vendor1')), 1);
check('password 10 chars ok', password_problems('abcdefghij', 'abcdefghij', 'vendor1'), []);
check('password 9 chars rejected', count(password_problems('abcdefghi', 'abcdefghi', 'vendor1')), 1);
check('password over 72 bytes rejected', count(password_problems(str_repeat('a', 73), str_repeat('a', 73), 'vendor1')), 1);
check('password 72 bytes ok', password_problems(str_repeat('a', 72), str_repeat('a', 72), 'vendor1'), []);
check('password mismatch', count(password_problems('abcdefghij', 'abcdefghik', 'vendor1')), 1);
check('password same as current', count(password_problems('Temp123456', 'Temp123456', 'vendor1', 'Temp123456')), 1);
check('password same as username', count(password_problems('vendor.name1', 'vendor.name1', 'vendor.name1')), 1);
check('multibyte chars count as characters', password_problems('پاکستان۱۲۳', 'پاکستان۱۲۳', 'x'), []);

$temp = generate_temp_password();
check('temp password length', strlen($temp), 12);
check('temp password alphabet', preg_match('/^[A-HJ-NP-Za-km-np-z2-9]{12}$/', $temp), 1);
check('temp passwords differ', generate_temp_password() !== generate_temp_password(), true);
check('temp password passes the rules', password_problems($temp, $temp, 'vendor1'), []);

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
check('allowlist blocks admin', is_password_change_allowlisted('/admin/vendors.php'), false);
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
$owner = ['id' => 5, 'role' => 'vendor'];
$other = ['id' => 6, 'role' => 'vendor'];
$bk = static fn(string $status, ?int $vendorId = 5) => ['status' => $status, 'vendor_id' => $vendorId];
check('owner views own booking', booking_allows($bk('confirmed'), $owner, 'view'), true);
check('other vendor cannot view', booking_allows($bk('draft'), $other, 'view'), false);
check('vendor cannot view unassigned draft', booking_allows($bk('draft', null), $owner, 'view'), false);
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
check('scope: vendor sees own', booking_scope_sql($owner), ['b.vendor_id = ?', [5]]);

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
    'c4' => ['present' => '1', 'selected' => '1', 'qty' => '2', 'rate' => '50'], // ops: rate ignored
    'l99' => ['present' => '1', 'selected' => '1', 'rate' => '1'],              // not on this form: ignored
], $formLines);
check('line errors none', $pe, []);
check('lines kept', array_keys($pl), ['l1', 'c2', 'c4']);
check('existing line unselected with new rate', $pl['l1']['new'], ['selected' => false, 'rate' => '6000.00', 'qty' => null, 'notes' => 'ok']);
check('per-unit charge qty', $pl['c2']['new'], ['selected' => true, 'rate' => '300.00', 'qty' => 4, 'notes' => null]);
check('ops item has qty, no rate', [$pl['c4']['new']['qty'], $pl['c4']['new']['rate']], [2, null]);
check('section/label come from the form lines, not the POST', [$pl['c2']['section'], $pl['c2']['label']], ['charge', 'Tracing']);
[, $pe] = parse_booking_lines(['c2' => ['present' => '1', 'selected' => '1', 'rate' => '', 'qty' => '1']], $formLines);
check('selected charge needs a rate', array_keys($pe), ['line_c2']);
[, $pe] = parse_booking_lines(['c2' => ['present' => '1', 'selected' => '1', 'rate' => '10', 'qty' => '']], $formLines);
check('selected per-unit charge needs a qty', array_keys($pe), ['line_c2']);
[, $pe] = parse_booking_lines(['l1' => ['present' => '1', 'selected' => '1', 'rate' => '-5']], $formLines);
check('negative rate rejected', array_keys($pe), ['line_l1']);
[$pl] = parse_booking_lines([], $formLines);
check('no posted lines → no changes', $pl, []);

check('field diff', booking_field_diff(['guests' => 250, 'discount' => '0.00', 'theme' => null, 'setup_time' => '18:00:00'],
    ['guests' => 300, 'discount' => '0.00', 'theme' => null, 'setup_time' => '18:00:00']), ['guests' => [250, 300]]);

// ---------------------------------------------------------------------------
// Confirmed bookings: direct edit vs amendment
// ---------------------------------------------------------------------------
$oldB = ['client_contact' => '0300', 'guests' => 200, 'client_company' => null, 'vendor_sign_name' => 'Uzair', 'decor_by' => null];
$line = static fn(string $section, bool $was, bool $now) => ['line_id' => 7, 'section' => $section, 'label' => 'X', 'selected' => $was,
    'qty' => null, 'rate' => null, 'notes' => null, 'new' => ['selected' => $now, 'qty' => null, 'rate' => null, 'notes' => null]];

$c = classify_confirmed_changes($oldB, ['client_contact' => '0311'] + $oldB, []);
check('contact change is a direct edit', [array_keys($c['changed']), $c['amendment_fields']], [['client_contact'], []]);
$c = classify_confirmed_changes($oldB, ['vendor_sign_name' => 'U. Khan', 'decor_by' => 'In-house'] + $oldB, []);
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
    'Catering, decoration & event management services — event at AO Mess.');
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

$draft = ['vendor_id' => 5, 'status' => 'draft'];
$confirmed = ['vendor_id' => 5, 'status' => 'confirmed'];
$adminUser = ['id' => 1, 'role' => 'admin'];
$ownerVendor = ['id' => 5, 'role' => 'vendor'];
$otherVendor = ['id' => 6, 'role' => 'vendor'];
check('owner vendor uploads to their draft', can_upload_attachment($draft, $ownerVendor), true);
check('owner vendor cannot upload to a confirmed booking', can_upload_attachment($confirmed, $ownerVendor), false);
check('other vendor cannot upload', can_upload_attachment($draft, $otherVendor), false);
check('admin uploads in any status', [can_upload_attachment($draft, $adminUser), can_upload_attachment($confirmed, $adminUser),
    can_upload_attachment(['vendor_id' => 5, 'status' => 'cancelled'], $adminUser)], [true, true, true]);
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
echo "\n", $passed, ' passed, ', count($failed), " failed\n";
foreach ($failed as $f) {
    echo "\nFAIL: $f\n";
}
exit($failed ? 1 : 0);
