<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/payments.php';

require_post();
$admin = require_login();
$bookingId = ctype_digit((string) ($_POST['booking_id'] ?? '')) ? (int) $_POST['booking_id'] : 0;
load_booking_for_user(db(), $bookingId, $admin, 'admin'); // recording money is admin-only
$kind = ($_POST['kind'] ?? '') === 'refund' ? 'refund' : 'payment';
$back = 'booking/form.php?id=' . $bookingId . '#payments';

/** Show the form again with what was typed (one-time, cleared once displayed). */
$keepInput = static function (array $errors) use ($bookingId, $kind): void {
    $fields = array_intersect_key($_POST, array_flip(['amount', 'paid_on', 'method', 'bank_name', 'reference_no', 'notes']));
    $_SESSION['payment_form'] = ['booking_id' => $bookingId, 'kind' => $kind, 'errors' => $errors,
        'input' => array_map(static fn($v) => is_string($v) ? $v : '', $fields)];
};

if (!consume_payment_form_token($_POST['form_token'] ?? null)) {
    flash('error', 'That form was already submitted (or has expired), so nothing new was recorded. Check the payments list below.');
    redirect($back);
}

[$data, $errors] = parse_payment_input($_POST, $kind);
if ($errors) {
    $keepInput($errors);
    flash('error', ($kind === 'refund' ? 'Refund' : 'Payment') . ' not recorded: ' . implode(' ', $errors));
    redirect($back);
}

try {
    $r = record_payment(db(), $admin, $bookingId, $data);
    flash('ok', ($kind === 'refund' ? 'Refund of ' : 'Payment of ') . format_rs(decimal_to_paisa($data['amount'])) . ' recorded.'
        . ($r['totals']['balance'] < 0 ? ' The booking is now overpaid by ' . format_rs(-$r['totals']['balance']) . '.' : ''));
} catch (PaymentRefused | TryAgainException $e) {
    $keepInput(['amount' => $e->getMessage()]);
    flash('error', $e->getMessage());
}
redirect($back);
