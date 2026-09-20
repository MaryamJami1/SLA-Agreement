<?php
/**
 * Lifecycle actions under the booking form (plan Section 8 table). Expects $booking, $viewer, $pdo.
 * Every action posts the booking's id and version; the server re-checks everything under lock.
 */
declare(strict_types=1);

$isAdmin = $viewer['role'] === 'admin';
$st = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE booking_id = ?');
$st->execute([(int) $booking['id']]);
$hasPayments = (int) $st->fetchColumn() > 0;
$canDelete = booking_allows($booking, $viewer, 'delete') && !$hasPayments;
$balance = decimal_to_paisa($booking['balance']);
$ref = csrf_field() . '<input type="hidden" name="id" value="' . (int) $booking['id'] . '">'
     . '<input type="hidden" name="version" value="' . (int) $booking['version'] . '">';

$blocking = [];
if ($booking['status'] === 'draft' && $booking['venue_id'] !== null && $booking['event_date'] !== null) {
    $q = $pdo->prepare("SELECT unique_id, status FROM bookings WHERE venue_id = ? AND event_date = ?
                          AND status IN ('confirmed', 'completed') AND id <> ?");
    $q->execute([(int) $booking['venue_id'], $booking['event_date'], (int) $booking['id']]);
    $blocking = $q->fetchAll();
}
$actions = $isAdmin || $canDelete;
?>
<div class="card pad lifecycle">
  <h3>DOCUMENTS</h3>
  <p class="muted">Printed from the browser. A draft prints with a DRAFT watermark; an amended booking prints its Rev number.</p>
  <p>
    <a class="btn" href="<?= h(url('documents/agreement.php?id=' . (int) $booking['id'])) ?>">SLA agreement</a>
    <a class="btn" href="<?= h(url('documents/invoice.php?id=' . (int) $booking['id'])) ?>">Customer invoice</a>
    <a class="btn" href="<?= h(url('documents/vendor_sheet.php?id=' . (int) $booking['id'])) ?>">Operations sheet</a>
  </p>
</div>
<?php if ($booking['status'] === 'cancelled'): ?>
<div class="card pad lifecycle">
  <h3>CANCELLED</h3>
  <p>Cancelled on <?= h(date('d M Y H:i', strtotime((string) $booking['cancelled_at']))) ?>.
     Reason: <?= h($booking['cancellation_reason']) ?></p>
  <p>Amount retained: <strong><?= h(rs($booking['paid_total'])) ?></strong>. Nothing further is owed.</p>
</div>
<?php elseif ($booking['status'] === 'completed'): ?>
<div class="card pad lifecycle">
  <h3>COMPLETED</h3>
  <p>Completed on <?= h(date('d M Y H:i', strtotime((string) $booking['completed_at']))) ?>.
     Balance: <strong><?= h(rs($booking['balance'])) ?></strong>.</p>
</div>
<?php elseif ($actions): ?>
<div class="card pad lifecycle">
  <h3>ACTIONS</h3>
  <div class="action-grid">

<?php if ($isAdmin && $booking['status'] === 'draft'): ?>
    <form method="post" action="<?= h(url('booking/confirm.php')) ?>" class="action-box">
      <?= $ref ?>
      <h4>Confirm</h4>
      <p class="muted">Issues the SLA. Needs an active vendor, client name, event date, venue and a net amount above Rs. 0.
        After confirming, vendors can only view and print it.</p>
<?php if ($blocking): ?>
      <p class="field-error">The venue is already <?= h($blocking[0]['status']) ?> for <?= h($blocking[0]['unique_id']) ?> on this date.
        Confirming needs an override reason, which is recorded in the audit log.</p>
      <div class="field"><label for="override_reason">Override reason</label>
        <textarea id="override_reason" name="override_reason" rows="2" maxlength="1000"></textarea></div>
<?php endif; ?>
      <button type="submit" class="btn primary">Confirm booking</button>
    </form>
<?php endif; ?>

<?php if ($isAdmin && $booking['status'] === 'confirmed'): ?>
    <form method="post" action="<?= h(url('booking/complete.php')) ?>" class="action-box">
      <?= $ref ?>
      <h4>Complete</h4>
<?php if ($booking['event_date'] !== null && $booking['event_date'] <= date('Y-m-d')): ?>
      <p class="muted">Marks the event as held. The booking then accepts payments only.</p>
<?php if ($balance !== 0): ?>
      <label class="check"><input type="checkbox" name="ack_balance" value="1">
        I acknowledge <?= $balance > 0 ? h(rs($balance)) . ' is still outstanding' : 'the booking is overpaid by ' . h(format_rs(-$balance)) ?>.</label>
<?php endif; ?>
      <button type="submit" class="btn">Mark completed</button>
<?php else: ?>
      <p class="muted">Available on or after the event date<?= $booking['event_date'] ? ' (' . h(date('d M Y', strtotime($booking['event_date']))) . ')' : '' ?>.</p>
<?php endif; ?>
    </form>
<?php endif; ?>

<?php if ($isAdmin && in_array($booking['status'], ['draft', 'confirmed'], true)): ?>
    <form method="post" action="<?= h(url('booking/cancel.php')) ?>" class="action-box"
          data-confirm="Cancel <?= h($booking['unique_id']) ?>? This can't be undone.">
      <?= $ref ?>
      <h4>Cancel</h4>
      <p class="muted">Cancelled bookings keep their record and accept refunds only.</p>
      <div class="field"><label for="cancel_reason">Cancellation reason</label>
        <textarea id="cancel_reason" name="reason" rows="2" maxlength="1000" required></textarea></div>
      <button type="submit" class="btn danger">Cancel booking</button>
    </form>
<?php endif; ?>

<?php if ($booking['status'] === 'draft' && booking_allows($booking, $viewer, 'delete')): ?>
    <form method="post" action="<?= h(url('booking/delete.php')) ?>" class="action-box"
          data-confirm="Delete draft <?= h($booking['unique_id']) ?> permanently? Its number will not be reused.">
      <?= $ref ?>
      <h4>Delete draft</h4>
<?php if ($hasPayments): ?>
      <p class="muted">This draft has payment records, so it can't be deleted. Cancel it instead.</p>
<?php else: ?>
      <p class="muted">Removes the draft and its files. The audit history is kept.</p>
      <button type="submit" class="btn danger">Delete draft</button>
<?php endif; ?>
    </form>
<?php endif; ?>

  </div>
</div>
<?php endif; ?>
