<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/payments.php';

require_post();
$admin = require_login();
$bookingId = ctype_digit((string) ($_POST['booking_id'] ?? '')) ? (int) $_POST['booking_id'] : 0;
$paymentId = ctype_digit((string) ($_POST['payment_id'] ?? '')) ? (int) $_POST['payment_id'] : 0;
load_booking_for_user(db(), $bookingId, $admin, 'admin'); // voids are admin-only

try {
    $r = void_payment(db(), $admin, $bookingId, $paymentId, $_POST['reason'] ?? '');
    flash('ok', ucfirst($r['kind']) . ' of ' . format_rs(decimal_to_paisa($r['amount'])) . ' voided.');
} catch (PaymentRefused | TryAgainException $e) {
    flash('error', $e->getMessage());
}
redirect('booking/form.php?id=' . $bookingId . '#payments');
