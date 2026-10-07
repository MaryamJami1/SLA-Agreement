<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/admin_data.php';
require_once APP_ROOT . '/app/payments.php';
require_once APP_ROOT . '/app/documents.php';
require_once APP_ROOT . '/app/vendors.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

$viewer = require_admin();
$pdo = db();
$inv = load_vendor_invoice($pdo, ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0);
if ($inv === null) {
    not_found();
}
$iid = (int) $inv['id'];

// Sticky payment input after a rejected submission (one-time).
$sticky = ($_SESSION['vendor_payment_form']['invoice_id'] ?? null) === $iid ? $_SESSION['vendor_payment_form'] : null;
unset($_SESSION['vendor_payment_form']);
$in = static fn(string $k, string $default = '') => $sticky['input'][$k] ?? $default;
$err = static fn(string $k) => isset($sticky['errors'][$k]) ? '<span class="field-error">' . h($sticky['errors'][$k]) . '</span>' : '';
$action = url('vendors/save.php');
$hidden = csrf_field() . '<input type="hidden" name="invoice_id" value="' . $iid . '">';

$pageTitle = 'Vendor invoice ' . $inv['invoice_no'];
$activeTab = 'vendors';
$bodyClass = 'document doc-sheet-page';
require APP_ROOT . '/app/views/layout_top.php';
require APP_ROOT . '/app/views/doc_vendor_invoice.php';
?>
<div class="card pad payments no-print" id="payments">
  <h3>PAYMENTS TO <?= h(mb_strtoupper($inv['vendor_name'])) ?></h3>
  <div class="money-summary">
    <div><span>Invoice total</span><strong><?= h(rs($inv['grand_total'])) ?></strong></div>
    <div><span>Paid</span><strong><?= h(rs($inv['paid_total'])) ?></strong></div>
    <div class="emph"><span><?= decimal_to_paisa($inv['balance']) < 0 ? 'Overpaid by' : 'Remaining' ?></span>
      <strong><?= h(format_rs(abs(decimal_to_paisa($inv['balance'])))) ?></strong></div>
    <div><span>Status</span><strong><?= vendor_status_badge($inv) ?></strong></div>
  </div>

<?php if (!$inv['payments']): ?>
  <p class="muted">No payments recorded yet.</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="lines-table payment-table">
    <thead><tr><th>Date</th><th>Method</th><th>Bank / reference</th><th class="num">Amount</th><th>Recorded by</th><th></th></tr></thead>
    <tbody>
<?php foreach ($inv['payments'] as $p): $voided = $p['voided_at'] !== null; ?>
      <tr class="<?= $voided ? 'voided' : '' ?>">
        <td><?= h(date('d M Y', strtotime($p['paid_on']))) ?></td>
        <td><?= h(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></td>
        <td><?= h(trim(($p['bank_name'] ?? '') . ' ' . ($p['reference_no'] ?? ''))) ?: '—' ?>
          <?php if ($p['notes']): ?><div class="hint"><?= h($p['notes']) ?></div><?php endif; ?></td>
        <td class="num"><?= h(rs($p['amount'])) ?></td>
        <td><?= h($p['recorded_by_name']) ?><div class="hint"><?= h(date('d M Y H:i', strtotime($p['created_at']))) ?></div></td>
        <td>
<?php if ($voided): ?>
          <span class="badge status-cancelled">VOIDED</span>
          <div class="hint"><?= h(date('d M Y', strtotime($p['voided_at']))) ?> by <?= h($p['voided_by_name']) ?>: <?= h($p['void_reason']) ?></div>
<?php else: ?>
          <form method="post" action="<?= h($action) ?>" class="void-form"
                data-confirm="Void this payment of <?= h(rs($p['amount'])) ?>? Voiding corrects a data-entry mistake.">
            <?= $hidden ?>
            <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
            <input type="text" name="reason" placeholder="reason for voiding" maxlength="255" required aria-label="Reason for voiding">
            <button type="submit" class="btn small danger" name="action" value="void_payment">Void</button>
          </form>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php if ($inv['status'] === 'issued'): ?>
  <form method="post" action="<?= h($action) ?>" class="payment-form">
    <?= $hidden ?>
    <input type="hidden" name="form_token" value="<?= h(payment_form_token()) ?>">
    <h4>Record a payment to the vendor</h4>
    <div class="grid g3">
      <div class="field"><label for="p_amount">Amount (Rs.)</label>
        <input type="text" id="p_amount" name="amount" inputmode="decimal" value="<?= h($in('amount')) ?>" required><?= $err('amount') ?></div>
      <div class="field"><label for="p_paid_on">Date paid</label>
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
    <button type="submit" class="btn primary" name="action" value="add_payment">Record payment</button>
  </form>

<?php if (!array_filter($inv['payments'], static fn($p) => $p['voided_at'] === null)): ?>
  <form method="post" action="<?= h($action) ?>" class="payment-form"
        data-confirm="Void invoice <?= h($inv['invoice_no']) ?>? Its services become available for a new invoice. This can't be undone.">
    <?= $hidden ?>
    <h4>Void this invoice</h4>
    <p class="muted">Only for an invoice issued by mistake (for example, a wrong quantity or discount). The invoice is kept, marked VOID,
      and its services can be corrected and invoiced again from the booking page.</p>
    <div class="field"><label for="vi_reason">Reason</label>
      <input type="text" id="vi_reason" name="reason" maxlength="255" required></div>
    <p><button type="submit" class="btn danger" name="action" value="void_invoice">Void invoice</button></p>
  </form>
<?php endif; ?>
<?php else: ?>
  <p class="muted">Voided on <?= h(date('d M Y H:i', strtotime((string) $inv['voided_at']))) ?> by <?= h($inv['voided_by_name']) ?>:
    <?= h($inv['void_reason']) ?></p>
<?php endif; ?>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
