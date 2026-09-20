<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/attachments.php';

require_post();
$admin = require_login();
$bookingId = ctype_digit((string) ($_POST['booking_id'] ?? '')) ? (int) $_POST['booking_id'] : 0;
$attachmentId = ctype_digit((string) ($_POST['attachment_id'] ?? '')) ? (int) $_POST['attachment_id'] : 0;
load_booking_for_user(db(), $bookingId, $admin, 'admin'); // voiding is admin-only

try {
    $r = void_attachment(db(), $admin, $bookingId, $attachmentId, $_POST['reason'] ?? '');
    flash('ok', 'Voided ' . $r['file'] . '. It no longer counts as a signed copy.');
} catch (AttachmentRefused | TryAgainException $e) {
    flash('error', $e->getMessage());
}
redirect('booking/form.php?id=' . $bookingId . '#attachments');
