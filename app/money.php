<?php
/**
 * Money model (plan Section 6). All arithmetic is in integer paisa (1 rupee = 100 paisa);
 * PHP floats are never used for money. DECIMAL strings from the database are converted
 * with decimal_to_paisa() and written back with paisa_to_decimal().
 *
 * recompute_booking_totals() is the ONLY code that writes a booking's six total columns.
 */
declare(strict_types=1);

/** Largest value a DECIMAL(12,2) column holds: 9,99,99,99,999.99 rupees. */
const MONEY_MAX_PAISA = 999999999999;

// ---------------------------------------------------------------------------
// Conversion
// ---------------------------------------------------------------------------

/** "12345.5" / "-12.30" / "0.00" (from the database) → paisa. */
function decimal_to_paisa($decimal): int
{
    $s = trim((string) ($decimal ?? '0'));
    if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $s, $m) !== 1) {
        throw new UnexpectedValueException("Not a decimal amount: '$s'");
    }
    $paisa = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
    return $m[1] === '-' ? -$paisa : $paisa;
}

/** paisa → "12345.50", the form MySQL expects for DECIMAL(12,2). */
function paisa_to_decimal(int $paisa): string
{
    $sign = $paisa < 0 ? '-' : '';
    $abs = abs($paisa);
    return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
}

// ---------------------------------------------------------------------------
// Input parsing (server-side numeric validation)
// ---------------------------------------------------------------------------

/**
 * Parse a money amount typed by a user: "12500", "12,500.50", "Rs. 1,00,000".
 * Returns paisa, or null for an empty value (the caller decides whether it is required).
 * Never accepts a negative sign, exponent, NaN/INF, or more than 2 decimals.
 *
 * @throws InvalidInput
 */
function parse_money($raw, bool $allowZero = true): ?int
{
    $s = trim((string) ($raw ?? ''));
    $s = preg_replace('/^rs\.?\s*/i', '', $s);
    $s = str_replace([',', ' '], '', $s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $s, $m) !== 1) {
        throw new InvalidInput('Enter an amount like 12500 or 12,500.50 (no minus sign, at most 2 decimals).');
    }
    $whole = ltrim($m[1], '0');
    if (strlen($whole) > 10) {
        throw new InvalidInput('Amount is too large (maximum Rs. 9,99,99,99,999.99).');
    }
    $paisa = (int) ($whole === '' ? '0' : $whole) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    if ($paisa > MONEY_MAX_PAISA) {
        throw new InvalidInput('Amount is too large (maximum Rs. 9,99,99,99,999.99).');
    }
    if (!$allowZero && $paisa === 0) {
        throw new InvalidInput('Amount must be greater than 0.');
    }
    return $paisa;
}

/**
 * Parse a refund percentage (refund_pct_30 / refund_pct_7): empty → null (no policy),
 * otherwise 0–100 with at most 2 decimals. Returned in hundredths of a percent (50.25% → 5025).
 *
 * @throws InvalidInput
 */
function parse_percent($raw): ?int
{
    $s = trim(str_replace('%', '', (string) ($raw ?? '')));
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $s, $m) !== 1) {
        throw new InvalidInput('Enter a percentage from 0 to 100 (at most 2 decimals).');
    }
    $hundredths = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    if ($hundredths > 10000) {
        throw new InvalidInput('Enter a percentage from 0 to 100 (at most 2 decimals).');
    }
    return $hundredths;
}

/** DECIMAL(5,2) from the database (or null) → hundredths of a percent (or null). */
function decimal_to_percent($decimal): ?int
{
    return $decimal === null || $decimal === '' ? null : decimal_to_paisa($decimal);
}

// ---------------------------------------------------------------------------
// Display
// ---------------------------------------------------------------------------

/** paisa → "Rs. 12,34,567" (paisa shown only when non-zero: "Rs. 12,34,567.50"). */
function format_rs(int $paisa): string
{
    $sign = $paisa < 0 ? '-' : '';
    $abs = abs($paisa);
    $out = 'Rs. ' . $sign . group_digits_south_asian((string) intdiv($abs, 100));
    if ($abs % 100 !== 0) {
        $out .= '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

/** 0 → "Zero", 100000 → "One Lakh", 123456789 → "Twelve Crore Thirty Four Lakh …". */
function number_to_words(int $n): string
{
    if ($n < 0) {
        return 'Minus ' . number_to_words(-$n);
    }
    if ($n === 0) {
        return 'Zero';
    }
    $parts = [];
    if ($n >= 10000000) {
        $parts[] = number_to_words(intdiv($n, 10000000)) . ' Crore';
        $n %= 10000000;
    }
    foreach ([[100000, 'Lakh'], [1000, 'Thousand']] as [$size, $name]) {
        if ($n >= $size) {
            $parts[] = words_below_thousand(intdiv($n, $size)) . ' ' . $name;
            $n %= $size;
        }
    }
    if ($n > 0) {
        $parts[] = words_below_thousand($n);
    }
    return implode(' ', $parts);
}

function words_below_thousand(int $n): string
{
    static $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    static $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $words = [];
    if ($n >= 100) {
        $words[] = $ones[intdiv($n, 100)] . ' Hundred';
        $n %= 100;
    }
    if ($n >= 20) {
        $words[] = $tens[intdiv($n, 10)] . ($n % 10 ? ' ' . $ones[$n % 10] : '');
    } elseif ($n > 0) {
        $words[] = $ones[$n];
    }
    return implode(' ', $words);
}

/** paisa → "One Lakh Rupees Only" / "Twelve Rupees and Fifty Paisa Only". */
function amount_in_words(int $paisa): string
{
    $abs = abs($paisa);
    $text = number_to_words(intdiv($abs, 100)) . ' Rupees';
    if ($abs % 100 !== 0) {
        $text .= ' and ' . number_to_words($abs % 100) . ' Paisa';
    }
    return ($paisa < 0 ? 'Minus ' : '') . $text . ' Only';
}

// ---------------------------------------------------------------------------
// Totals chain (pure)
// ---------------------------------------------------------------------------

/**
 * Amount of one charge line, from its unit snapshot (plan Section 6 table).
 * Unselected lines are 0. A missing rate counts as 0 (validation requires a rate on selected lines).
 */
function line_amount(string $unit, bool $selected, ?int $ratePaisa, ?int $qty, int $guests): int
{
    if (!$selected || $ratePaisa === null) {
        return 0;
    }
    switch ($unit) {
        case 'fixed':
            return $ratePaisa;
        case 'per unit':
            return ($qty ?? 0) * $ratePaisa;
        case 'per head':
            return $guests * $ratePaisa;
    }
    throw new UnexpectedValueException("Unknown unit '$unit'");
}

/**
 * The whole chain for one booking.
 *
 * @param array $chargeLines each ['unit' => string, 'selected' => bool, 'rate' => ?int paisa, 'qty' => ?int]
 *                           (keys of this array are preserved in 'line_amounts')
 * @param array $payments    each ['kind' => 'payment'|'refund', 'amount' => int paisa, 'voided' => bool]
 * @return array{line_amounts: array, guest_charges: int, charges_total: int, sub_total: int,
 *               grand_total: int, paid_total: int, balance: int, refundable: int}
 */
function compute_totals(int $perHeadRate, int $guests, int $discount, array $chargeLines, array $payments, string $status): array
{
    $lineAmounts = [];
    $chargesTotal = 0;
    foreach ($chargeLines as $key => $line) {
        $amount = line_amount($line['unit'], (bool) $line['selected'], $line['rate'], $line['qty'], $guests);
        $lineAmounts[$key] = $amount;
        $chargesTotal += $amount;
    }
    $guestCharges = $perHeadRate * $guests;
    $subTotal = $guestCharges + $chargesTotal;
    $grandTotal = $subTotal - $discount;

    [$paid, $refunded] = payment_sums($payments);
    $paidTotal = $paid - $refunded;

    return [
        'line_amounts'  => $lineAmounts,
        'guest_charges' => $guestCharges,
        'charges_total' => $chargesTotal,
        'sub_total'     => $subTotal,
        'grand_total'   => $grandTotal,
        'paid_total'    => $paidTotal,
        'balance'       => $status === 'cancelled' ? 0 : $grandTotal - $paidTotal,
        'refundable'    => $paidTotal,
    ];
}

/** [sum of non-voided payments, sum of non-voided refunds], in paisa. */
function payment_sums(array $payments): array
{
    $paid = 0;
    $refunded = 0;
    foreach ($payments as $p) {
        if ($p['voided']) {
            continue;
        }
        if ($p['kind'] === 'refund') {
            $refunded += $p['amount'];
        } else {
            $paid += $p['amount'];
        }
    }
    return [$paid, $refunded];
}

/**
 * Cross-field checks on computed totals (plan Section 6): discount ≤ sub_total, and every
 * computed amount fits DECIMAL(12,2). Returns field => message; empty means valid.
 */
function validate_totals(array $totals, int $discount): array
{
    $errors = [];
    if ($discount > $totals['sub_total']) {
        $errors['discount'] = 'Discount can\'t be more than the sub total (' . format_rs($totals['sub_total']) . ').';
    }
    $amounts = array_merge($totals['line_amounts'], [$totals['guest_charges'], $totals['sub_total'], $totals['grand_total']]);
    foreach ($amounts as $amount) {
        if (abs($amount) > MONEY_MAX_PAISA) {
            $errors['totals'] = 'These amounts are too large: a total would exceed Rs. 9,99,99,99,999.99.';
            break;
        }
    }
    return $errors;
}

/**
 * Refund policy hint for a cancelled booking (plan Section 6). Never enforced.
 * Percentages are in hundredths (50% = 5000). Dates are 'Y-m-d'.
 *
 * @return array{amount: ?int, pct: ?int, reason: ?string} amount/pct are null when no suggestion is made,
 *                                                         and reason then says why
 */
function refund_suggestion(?string $eventDate, string $cancelledDate, ?int $pct30, ?int $pct7,
                           int $totalPaid, int $alreadyRefunded, int $refundable): array
{
    $none = static fn(string $reason) => ['amount' => null, 'pct' => null, 'reason' => $reason];

    if ($eventDate === null || $eventDate === '') {
        return $none('No policy suggestion — the booking has no event date.');
    }
    if ($refundable <= 0) {
        return $none('No policy suggestion — nothing is left to refund.');
    }
    $days = (int) (new DateTimeImmutable($cancelledDate))->diff(new DateTimeImmutable($eventDate))->format('%r%a');
    if ($days < 0) {
        return $none('No policy suggestion — the booking was cancelled after the event date.');
    }
    if ($days >= 30) {
        $pct = $pct30;
        $label = '30';
    } elseif ($days >= 7) {
        $pct = $pct7;
        $label = '7';
    } else {
        $pct = 0;
        $label = null;
    }
    if ($pct === null) {
        return $none("No policy suggestion — the {$label}-day refund percentage is empty.");
    }
    $policyTotal = intdiv($totalPaid * $pct + 5000, 10000); // rounded to the nearest paisa
    $amount = max(0, min($refundable, $policyTotal - $alreadyRefunded));
    return ['amount' => $amount, 'pct' => $pct, 'reason' => null];
}

// ---------------------------------------------------------------------------
// Database: the single writer of booking totals
// ---------------------------------------------------------------------------

/**
 * Recompute and store a booking's charge line amounts and its six total columns from the
 * database state. The caller MUST be inside a transaction holding the booking row lock
 * (SELECT … FOR UPDATE). Does not change `version`.
 *
 * @return array the computed totals (paisa)
 */
function recompute_booking_totals(PDO $pdo, int $bookingId): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('recompute_booking_totals() must run inside the booking transaction.');
    }

    $st = $pdo->prepare('SELECT status, per_head_rate, guests, discount FROM bookings WHERE id = ?');
    $st->execute([$bookingId]);
    $b = $st->fetch();
    if (!$b) {
        throw new RuntimeException("Booking $bookingId not found.");
    }

    $st = $pdo->prepare("SELECT id, unit_snapshot, is_selected, qty, rate, amount
                           FROM booking_line_items WHERE booking_id = ? AND section = 'charge'");
    $st->execute([$bookingId]);
    $lines = [];
    $storedAmounts = [];
    foreach ($st->fetchAll() as $row) {
        $lines[(int) $row['id']] = [
            'unit'     => $row['unit_snapshot'],
            'selected' => (bool) $row['is_selected'],
            'rate'     => $row['rate'] === null ? null : decimal_to_paisa($row['rate']),
            'qty'      => $row['qty'] === null ? null : (int) $row['qty'],
        ];
        $storedAmounts[(int) $row['id']] = decimal_to_paisa($row['amount']);
    }

    $st = $pdo->prepare('SELECT kind, amount, voided_at FROM payments WHERE booking_id = ?');
    $st->execute([$bookingId]);
    $payments = [];
    foreach ($st->fetchAll() as $row) {
        $payments[] = ['kind' => $row['kind'], 'amount' => decimal_to_paisa($row['amount']), 'voided' => $row['voided_at'] !== null];
    }

    $discount = decimal_to_paisa($b['discount']);
    $t = compute_totals(decimal_to_paisa($b['per_head_rate']), (int) $b['guests'], $discount, $lines, $payments, $b['status']);
    if ($errors = validate_totals($t, $discount)) {
        // Saves validate before calling this, so reaching here means a bug or bad data: refuse to store it.
        throw new RuntimeException('Booking totals failed validation: ' . implode(' ', $errors));
    }

    $upd = $pdo->prepare('UPDATE booking_line_items SET amount = ? WHERE id = ?');
    foreach ($t['line_amounts'] as $lineId => $amount) {
        if ($storedAmounts[$lineId] !== $amount) {
            $upd->execute([paisa_to_decimal($amount), $lineId]);
        }
    }

    $pdo->prepare('UPDATE bookings SET guest_charges = ?, charges_total = ?, sub_total = ?,
                          grand_total = ?, paid_total = ?, balance = ? WHERE id = ?')
        ->execute([
            paisa_to_decimal($t['guest_charges']), paisa_to_decimal($t['charges_total']),
            paisa_to_decimal($t['sub_total']), paisa_to_decimal($t['grand_total']),
            paisa_to_decimal($t['paid_total']), paisa_to_decimal($t['balance']), $bookingId,
        ]);

    return $t;
}
