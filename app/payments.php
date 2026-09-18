<?php
/**
 * Payments and refunds (plan Sections 6 and 8). Rows are never edited or deleted; the only update is
 * voiding, once. Every write is one transaction: lock the booking, check the status and the refund cap,
 * write, recompute the totals from the payment rows, audit, commit.
 */
declare(strict_types=1);

const PAYMENT_METHODS = ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'online' => 'Online'];

/** Statuses in which each kind of entry can be added (plan Section 8 table). Voids: any status. */
const PAYMENT_KIND_STATUSES = [
    'payment' => ['draft', 'confirmed', 'completed'],
    'refund'  => ['cancelled'],
];

/** The payment can't be recorded or voided in the booking's current state; the message is shown to the user. */
class PaymentRefused extends RuntimeException {}

function payment_kind_allowed(string $status, string $kind): bool
{
    return in_array($status, PAYMENT_KIND_STATUSES[$kind] ?? [], true);
}

/**
 * Validate a posted payment or refund.
 *
 * @return array{0: array, 1: array} [data in database form, field => message]
 */
function parse_payment_input(array $post, string $kind): array
{
    $errors = [];
    $data = ['kind' => $kind];
    $str = static fn(string $k): string => trim(is_string($post[$k] ?? null) ? $post[$k] : '');

    try {
        $paisa = parse_money($str('amount'), false);
        if ($paisa === null) {
            throw new InvalidInput('Enter the amount.');
        }
        $data['amount'] = paisa_to_decimal($paisa);
    } catch (InvalidInput $e) {
        $errors['amount'] = 'Amount: ' . $e->getMessage();
    }

    $date = $str('paid_on');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || $date < '2000-01-01') {
        $errors['paid_on'] = 'Date: enter a valid date.';
    } elseif ($date > date('Y-m-d')) {
        $errors['paid_on'] = 'Date: can\'t be in the future.';
    }
    $data['paid_on'] = $date;

    $method = $str('method');
    if (!isset(PAYMENT_METHODS[$method])) {
        $errors['method'] = 'Method: choose how the money was ' . ($kind === 'refund' ? 'returned' : 'paid') . '.';
    }
    $data['method'] = $method;

    foreach (['bank_name' => 100, 'reference_no' => 100, 'notes' => 255] as $field => $max) {
        $value = $str($field);
        if (mb_strlen($value) > $max) {
            $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ": at most $max characters.";
        }
        $data[$field] = $value === '' ? null : $value;
    }
    if ($method === 'cheque' && $data['reference_no'] === null) {
        $errors['reference_no'] = 'Cheque number: required for a cheque.';
    }
    return [$data, $errors];
}

/** [sum of non-voided payments, sum of non-voided refunds] in paisa, read inside the current transaction. */
function booking_payment_sums(PDO $pdo, int $bookingId): array
{
    $st = $pdo->prepare('SELECT kind, amount, voided_at FROM payments WHERE booking_id = ?');
    $st->execute([$bookingId]);
    $rows = array_map(static fn($r) => ['kind' => $r['kind'], 'amount' => decimal_to_paisa($r['amount']), 'voided' => $r['voided_at'] !== null],
        $st->fetchAll());
    return payment_sums($rows);
}

/** Lock the booking row for a payment write (payments don't change `version`, so no version check). */
function lock_booking_for_payment(PDO $pdo, int $bookingId): array
{
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
    $st->execute([$bookingId]);
    $booking = $st->fetch();
    if (!$booking) {
        throw new PaymentRefused('This booking no longer exists.');
    }
    return $booking;
}

/**
 * Add a payment or a refund.
 *
 * @return array{payment_id: int, unique_id: string, totals: array}
 */
function record_payment(PDO $pdo, array $admin, int $bookingId, array $data): array
{
    return db_transaction(static function (PDO $pdo) use ($admin, $bookingId, $data) {
        $b = lock_booking_for_payment($pdo, $bookingId);                                  // 1.
        if (!payment_kind_allowed($b['status'], $data['kind'])) {
            throw new PaymentRefused($data['kind'] === 'refund'
                ? 'Refunds can be recorded only on a cancelled booking.'
                : 'Payments can\'t be recorded on a ' . $b['status'] . ' booking.');
        }
        if ($data['kind'] === 'refund') {                                                  // 2. refund cap
            [$paid, $refunded] = booking_payment_sums($pdo, $bookingId);
            $refundable = $paid - $refunded;
            if (decimal_to_paisa($data['amount']) > $refundable) {
                throw new PaymentRefused('Refund exceeds the refundable amount (' . format_rs($refundable) . ').');
            }
        }
        $pdo->prepare('INSERT INTO payments (booking_id, kind, amount, paid_on, method, bank_name, reference_no, notes, recorded_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')                                    // 3.
            ->execute([$bookingId, $data['kind'], $data['amount'], $data['paid_on'], $data['method'],
                $data['bank_name'], $data['reference_no'], $data['notes'], $admin['id']]);
        $paymentId = (int) $pdo->lastInsertId();
        $totals = recompute_booking_totals($pdo, $bookingId);                              // 4.
        audit($pdo, 'payment_add', (int) $admin['id'], $bookingId, [                       // 5.
            'unique_id' => $b['unique_id'], 'payment_id' => $paymentId, 'kind' => $data['kind'], 'amount' => $data['amount'],
            'paid_on' => $data['paid_on'], 'method' => $data['method'], 'reference_no' => $data['reference_no'],
            'paid_total' => [$b['paid_total'], paisa_to_decimal($totals['paid_total'])],
            'balance' => [$b['balance'], paisa_to_decimal($totals['balance'])],
        ]);
        return ['payment_id' => $paymentId, 'unique_id' => $b['unique_id'], 'totals' => $totals];
    }, $pdo);
}

/**
 * Void a payment or refund (a data-entry correction, not a refund).
 *
 * @return array{unique_id: string, kind: string, amount: string, totals: array}
 */
function void_payment(PDO $pdo, array $admin, int $bookingId, int $paymentId, $reasonRaw): array
{
    $reason = trim(is_string($reasonRaw) ? $reasonRaw : '');
    if ($reason === '') {
        throw new PaymentRefused('Enter the reason for voiding this entry.');
    }
    if (mb_strlen($reason) > 255) {
        throw new PaymentRefused('The reason can be at most 255 characters.');
    }
    return db_transaction(static function (PDO $pdo) use ($admin, $bookingId, $paymentId, $reason) {
        $b = lock_booking_for_payment($pdo, $bookingId);                                  // 1.
        $st = $pdo->prepare('SELECT * FROM payments WHERE id = ? AND booking_id = ? FOR UPDATE');
        $st->execute([$paymentId, $bookingId]);
        $p = $st->fetch();
        if (!$p) {
            throw new PaymentRefused('That payment entry doesn\'t belong to this booking.');
        }
        if ($p['voided_at'] !== null) {
            throw new PaymentRefused('This entry has already been voided.');
        }
        if ($p['kind'] === 'payment') {                                                    // 2. refund cap
            [$paid, $refunded] = booking_payment_sums($pdo, $bookingId);
            if (decimal_to_paisa($p['amount']) > $paid - $refunded) {
                throw new PaymentRefused('Void the related refunds first — voiding this payment would leave refunds greater than payments.');
            }
        }
        $st = $pdo->prepare('UPDATE payments SET voided_at = NOW(), voided_by = ?, void_reason = ?
                              WHERE id = ? AND booking_id = ? AND voided_at IS NULL');          // 3.
        $st->execute([$admin['id'], $reason, $paymentId, $bookingId]);
        if ($st->rowCount() !== 1) {
            throw new PaymentRefused('This entry has already been voided.');
        }
        $totals = recompute_booking_totals($pdo, $bookingId);                              // 4.
        audit($pdo, 'payment_void', (int) $admin['id'], $bookingId, [                      // 5.
            'unique_id' => $b['unique_id'], 'payment_id' => $paymentId, 'kind' => $p['kind'], 'amount' => $p['amount'],
            'reason' => $reason, 'paid_total' => [$b['paid_total'], paisa_to_decimal($totals['paid_total'])],
            'balance' => [$b['balance'], paisa_to_decimal($totals['balance'])],
        ]);
        return ['unique_id' => $b['unique_id'], 'kind' => $p['kind'], 'amount' => $p['amount'], 'totals' => $totals];
    }, $pdo);
}

/** All entries for a booking, oldest first, with the names of who recorded and voided them. */
function booking_payments(PDO $pdo, int $bookingId): array
{
    $st = $pdo->prepare('SELECT p.*, r.name AS recorded_by_name, v.name AS voided_by_name
                           FROM payments p
                           JOIN users r ON r.id = p.recorded_by
                      LEFT JOIN users v ON v.id = p.voided_by
                          WHERE p.booking_id = ?
                          ORDER BY p.paid_on, p.id');
    $st->execute([$bookingId]);
    return $st->fetchAll();
}

// ---------------------------------------------------------------------------
// One-time form tokens: a double-click or a browser resubmit can't record the same entry twice.
// ---------------------------------------------------------------------------

function payment_form_token(): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['payment_tokens'][$token] = time();
    // Keep only the most recent 30 unused tokens.
    if (count($_SESSION['payment_tokens']) > 30) {
        asort($_SESSION['payment_tokens']);
        $_SESSION['payment_tokens'] = array_slice($_SESSION['payment_tokens'], -30, null, true);
    }
    return $token;
}

/** Use up a form token. False if it was never issued in this session or has already been used. */
function consume_payment_form_token($token): bool
{
    if (!is_string($token) || !isset($_SESSION['payment_tokens'][$token])) {
        return false;
    }
    unset($_SESSION['payment_tokens'][$token]);
    return true;
}
