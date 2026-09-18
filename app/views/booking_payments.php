<?php
/**
 * Payments and refunds for one booking (plan Sections 6 and 8). Expects $booking, $viewer, $pdo.
 * Everyone who can view the booking sees the list; only the admin records or voids entries.
 */
declare(strict_types=1);

$isAdmin = $viewer['role'] === 'admin';
$bid = (int) $booking['id'];
$entries = booking_payments($pdo, $bid);
$status = $booking['status'];
$kindAllowed = null;
foreach (['payment', 'refund'] as $k) {
    if (payment_kind_allowed($status, $k)) {
        $kindAllowed = $k;
    }
}

[$paid, $refunded] = payment_sums(array_map(static fn($e) => ['kind' => $e['kind'], 'amount' => decimal_to_paisa($e['amount']), 'voided' => $e['voided_at'] !== null], $entries));
$refundable = $paid - $refunded;
$balance = decimal_to_paisa($booking['balance']);

// Sticky input after a rejected submission (one-time).
$sticky = ($_SESSION['payment_form']['booking_id'] ?? null) === $bid ? $_SESSION['payment_form'] : null;
unset($_SESSION['payment_form']);
$in = static fn(string $k, string $default = '') => $sticky['input'][$k] ?? $default;
$err = static fn(string $k) => isset($sticky['errors'][$k]) ? '<span class="field-error">' . h($sticky['errors'][$k]) . '</span>' : '';

$suggestion = null;
if ($status === 'cancelled') {
    $suggestion = refund_suggestion($booking['event_date'], substr((string) $booking['cancelled_at'], 0, 10),
        decimal_to_percent($booking['refund_pct_30']), decimal_to_percent($booking['refund_pct_7']), $paid, $refunded, $refundable);
}
?>
<div class="card pad payments" id="payments">
  <h3>PAYMENTS &amp; REFUNDS</h3>

  <div class="money-summary">
    <div><span>Net amount</span><strong><?= h(rs($booking['grand_total'])) ?></strong></div>
    <div><span>Payments received</span><strong><?= h(format_rs($paid)) ?></strong></div>
<?php if ($refunded > 0 || $status === 'cancelled'): ?>
    <div><span>Refunded</span><strong><?= h(format_rs($refunded)) ?></strong></div>
<?php endif; ?>
<?php if ($status === 'cancelled'): ?>
    <div class="emph"><span>Amount retained</span><strong><?= h(format_rs($paid - $refunded)) ?></strong></div>
<?php else: ?>
    <div class="emph"><span><?= $balance < 0 ? 'Overpaid by' : 'Balance' ?></span><strong><?= h(format_rs(abs($balance))) ?></strong></div>
<?php endif; ?>
  </div>

<?php if (!$entries): ?>
  <p class="muted">No payments recorded yet.</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="lines-table payment-table">
    <thead><tr><th>Date</th><th>Type</th><th>Method</th><th>Bank / reference</th><th class="num">Amount</th><th>Recorded by</th><th></th></tr></thead>
    <tbody>
<?php foreach ($entries as $e): $voided = $e['voided_at'] !== null; ?>
      <tr class="<?= $voided ? 'voided' : '' ?> <?= $e['kind'] === 'refund' ? 'refund' : '' ?>">
        <td><?= h(date('d M Y', strtotime($e['paid_on']))) ?></td>
        <td><?= $e['kind'] === 'refund' ? 'Refund' : 'Payment' ?></td>
        <td><?= h(PAYMENT_METHODS[$e['method']] ?? $e['method']) ?></td>
        <td><?= h(trim(($e['bank_name'] ?? '') . ' ' . ($e['reference_no'] ?? ''))) ?: '—' ?>
          <?php if ($e['notes']): ?><div class="hint"><?= h($e['notes']) ?></div><?php endif; ?></td>
        <td class="num"><?= $e['kind'] === 'refund' ? '−' : '' ?><?= h(rs($e['amount'])) ?></td>
        <td><?= h($e['recorded_by_name']) ?><div class="hint"><?= h(date('d M Y H:i', strtotime($e['created_at']))) ?></div></td>
        <td>
<?php if ($voided): ?>
          <span class="badge status-cancelled">VOIDED</span>
          <div class="hint"><?= h(date('d M Y', strtotime($e['voided_at']))) ?> by <?= h($e['voided_by_name']) ?>: <?= h($e['void_reason']) ?></div>
<?php elseif ($isAdmin): ?>
          <form method="post" action="<?= h(url('payments/void.php')) ?>" class="void-form"
                data-confirm="Void this <?= h($e['kind']) ?> of <?= h(rs($e['amount'])) ?>? Voiding corrects a data-entry mistake; it is not a refund.">
            <?= csrf_field() ?>
            <input type="hidden" name="booking_id" value="<?= $bid ?>">
            <input type="hidden" name="payment_id" value="<?= (int) $e['id'] ?>">
            <input type="text" name="reason" placeholder="reason for voiding" maxlength="255" required aria-label="Reason for voiding">
            <button type="submit" class="rowbtn del">Void</button>
          </form>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php if ($isAdmin && $kindAllowed !== null): $isRefund = $kindAllowed === 'refund'; ?>
  <form method="post" action="<?= h(url('payments/add.php')) ?>" class="payment-form">
    <?= csrf_field() ?>
    <input type="hidden" name="booking_id" value="<?= $bid ?>">
    <input type="hidden" name="kind" value="<?= $kindAllowed ?>">
    <input type="hidden" name="form_token" value="<?= h(payment_form_token()) ?>">
    <h4><?= $isRefund ? 'Record a refund' : 'Record a payment' ?></h4>
<?php if ($isRefund): ?>
    <p class="muted">Refundable: <strong><?= h(format_rs($refundable)) ?></strong> (payments received minus refunds already made).
<?php if ($suggestion['amount'] !== null): ?>
      <br>Policy suggests refunding <strong><?= h(format_rs($suggestion['amount'])) ?></strong>
      (<?= h(rtrim(rtrim(paisa_to_decimal($suggestion['pct']), '0'), '.')) ?>% of <?= h(format_rs($paid)) ?> paid<?= $refunded ? ', ' . h(format_rs($refunded)) . ' already refunded' : '' ?>).
      This is a suggestion only.
<?php else: ?>
      <br><?= h($suggestion['reason']) ?>
<?php endif; ?>
    </p>
<?php endif; ?>
    <div class="grid g3">
      <div class="field"><label for="p_amount">Amount (Rs.)</label>
        <input type="text" id="p_amount" name="amount" inputmode="decimal" value="<?= h($in('amount')) ?>" required><?= $err('amount') ?></div>
      <div class="field"><label for="p_paid_on">Date <?= $isRefund ? 'refunded' : 'received' ?></label>
        <input type="date" id="p_paid_on" name="paid_on" value="<?= h($in('paid_on', date('Y-m-d'))) ?>" max="<?= date('Y-m-d') ?>" required><?= $err('paid_on') ?></div>
      <div class="field"><label for="p_method">Method</label>
        <select id="p_method" name="method" required>
          <option value="">—</option>
<?php foreach (PAYMENT_METHODS as $value => $label): ?>
          <option value="<?= $value ?>"<?= $in('method') === $value ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
        </select><?= $err('method') ?></div>
      <div class="field"><label for="p_bank_name">Bank</label>
        <input type="text" id="p_bank_name" name="bank_name" maxlength="100" value="<?= h($in('bank_name')) ?>"><?= $err('bank_name') ?></div>
      <div class="field"><label for="p_reference_no">Cheque / transaction no.</label>
        <input type="text" id="p_reference_no" name="reference_no" maxlength="100" value="<?= h($in('reference_no')) ?>"><?= $err('reference_no') ?></div>
      <div class="field"><label for="p_notes">Notes</label>
        <input type="text" id="p_notes" name="notes" maxlength="255" value="<?= h($in('notes')) ?>"><?= $err('notes') ?></div>
    </div>
    <button type="submit" class="btn primary"><?= $isRefund ? 'Record refund' : 'Record payment' ?></button>
  </form>
<?php endif; ?>
<?php if ($status !== 'cancelled' && $balance < 0): ?>
  <p class="hint">This booking is overpaid. Refunds are recorded only after a booking is cancelled.</p>
<?php endif; ?>
</div>
