<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/attachments.php';

require_post();
$user = require_login();
$bookingId = ctype_digit((string) ($_POST['booking_id'] ?? '')) ? (int) $_POST['booking_id'] : 0;
$booking = load_booking_for_user(db(), $bookingId, $user, 'view');
if (!can_upload_attachment($booking, $user)) {
    not_found(); // vendors may only attach to their own drafts
}
$back = 'booking/form.php?id=' . $bookingId . '#attachments';

// "Signed copy of Rev N": only offered on a confirmed booking; N is re-checked under the booking lock.
$signedRevision = null;
if (isset($_POST['is_signed_copy']) && ctype_digit((string) ($_POST['signed_revision'] ?? ''))) {
    $signedRevision = (int) $_POST['signed_revision'];
}

try {
    $stored = accept_uploaded_file($_FILES['file'] ?? []);
    attach_file(db(), $user, $bookingId, $stored, $signedRevision);
    flash('ok', 'Uploaded ' . $stored['original_name']
        . ($signedRevision !== null ? " as the signed copy of Rev $signedRevision." : '.'));
} catch (AttachmentRefused | TryAgainException $e) {
    flash('error', $e->getMessage());
}
redirect($back);
