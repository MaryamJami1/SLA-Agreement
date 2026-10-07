<?php
/** A vendor invoice (same layout as the customer invoice). Expects $inv from load_vendor_invoice(). */
declare(strict_types=1);

$paidNet = decimal_to_paisa($inv['paid_total']);
$balance = decimal_to_paisa($inv['balance']);
$isVoid = $inv['status'] === 'void';
[$statusLabel] = VENDOR_PAYMENT_STATUSES[$inv['payment_status']];
$livePayments = array_values(array_filter($inv['payments'], static fn($p) => $p['voided_at'] === null));
?>
<div class="doc-toolbar">
  <button type="button" class="btn primary" id="print-btn">Print / Save as PDF</button>
  <a class="btn" href="<?= h(url('booking/form.php?id=' . (int) $inv['booking_id'])) ?>#vendors">Back to the booking</a>
  <a class="btn" href="<?= h(url('admin/vendor.php?id=' . (int) $inv['vendor_id'])) ?>">Vendor</a>
</div>

<div class="doc card" style="--z:<?= h(vendor_invoice_print_zoom($inv)) ?>">
<?php if ($isVoid): ?>
  <div class="watermark">VOID</div>
<?php endif; ?>

  <div class="doc-head">
    <h2>Vendor Invoice</h2>
    <div class="doc-meta">
      <div><span>Invoice No.</span><strong><?= h($inv['invoice_no']) ?></strong></div>
      <div><span>Invoice Date</span><strong><?= h(ddate($inv['invoice_date'])) ?></strong></div>
      <div><span>Event Ref.</span><strong><?= h($inv['unique_id']) ?></strong></div>
      <div><span>Payment Status</span><strong><?= h($statusLabel) ?></strong></div>
    </div>
  </div>

  <div class="doc-parties">
    <div class="doc-box">
      <h4>Vendor</h4>
      <p><strong><?= h($inv['vendor_name']) ?></strong></p>
<?php if ($inv['vendor_contact_person']): ?>      <p>Contact: <?= h($inv['vendor_contact_person']) ?></p><?php endif; ?>
<?php if ($inv['vendor_phone']): ?>      <p>Phone: <?= h($inv['vendor_phone']) ?></p><?php endif; ?>
<?php if ($inv['vendor_email']): ?>      <p>Email: <?= h($inv['vendor_email']) ?></p><?php endif; ?>
<?php if ($inv['vendor_address']): ?>      <p><?= h($inv['vendor_address']) ?></p><?php endif; ?>
    </div>
    <div class="doc-box">
      <h4>Event</h4>
      <p><strong><?= h($inv['event_label']) ?></strong></p>
      <p>Date: <?= h(ddate($inv['event_date'])) ?><?= $inv['event_date'] ? ' (' . h(date('l', strtotime($inv['event_date']))) . ')' : '' ?></p>
      <p>Venue: <?= h(dv($inv['event_venue'])) ?></p>
      <p>Issued by: <?= h(cfg('ORG_NAME', 'ASK Organizers')) ?></p>
    </div>
  </div>

  <table class="doc-table money">
    <thead><tr><th>Service</th><th>Unit</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
    <tbody>
<?php foreach ($inv['items'] as $item): ?>
      <tr>
        <td><?= h($item['label']) ?><?php if ($item['notes']): ?><div class="doc-note"><?= h($item['notes']) ?></div><?php endif; ?></td>
        <td><?= h(VENDOR_UNITS[$item['unit']] ?? $item['unit']) ?></td>
        <td class="num"><?= h(group_digits_south_asian((string) $item['qty'])) ?></td>
        <td class="num"><?= h(rs($item['rate'])) ?></td>
        <td class="num"><?= h(rs($item['amount'])) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="4">Sub total</td><td class="num"><?= h(rs($inv['sub_total'])) ?></td></tr>
<?php if (decimal_to_paisa($inv['discount']) > 0): ?>
      <tr><td colspan="4">Discount</td><td class="num">− <?= h(rs($inv['discount'])) ?></td></tr>
<?php endif; ?>
<?php if (decimal_to_paisa($inv['tax_amount']) > 0): ?>
      <tr><td colspan="4">Tax (<?= h(rtrim(rtrim($inv['tax_pct'], '0'), '.')) ?>%)</td><td class="num"><?= h(rs($inv['tax_amount'])) ?></td></tr>
<?php endif; ?>
      <tr class="grand"><td colspan="4">Grand total</td><td class="num"><?= h(rs($inv['grand_total'])) ?></td></tr>
    </tfoot>
  </table>
<?php if ($inv['notes']): ?>
  <p class="doc-note"><?= h($inv['notes']) ?></p>
<?php endif; ?>

  <h4 class="doc-sub">Payments made</h4>
<?php if (!$livePayments): ?>
  <p class="doc-note">No payments made against this invoice yet.</p>
<?php else: ?>
  <table class="doc-table money">
    <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead>
    <tbody>
<?php foreach ($livePayments as $p): ?>
      <tr><td><?= h(ddate($p['paid_on'])) ?></td>
        <td><?= h(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></td>
        <td><?= h(dv(trim(($p['bank_name'] ?? '') . ' ' . ($p['reference_no'] ?? '')))) ?></td>
        <td class="num"><?= h(rs($p['amount'])) ?></td></tr>
<?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="3">Total paid</td><td class="num"><?= h(format_rs($paidNet)) ?></td></tr>
    </tfoot>
  </table>
<?php endif; ?>

<?php if ($isVoid): ?>
  <p class="doc-balance">This invoice was voided on <?= h(ddate($inv['voided_at'])) ?>. Nothing is payable against it.</p>
<?php else: ?>
  <p class="doc-balance"><?= $balance < 0 ? 'Overpaid' : 'Remaining amount' ?>: <strong><?= h(format_rs(abs($balance))) ?></strong>
    — <?= h($statusLabel) ?></p>
  <p class="doc-words"><strong>Grand total in words:</strong> <?= h(amount_in_words(decimal_to_paisa($inv['grand_total']))) ?></p>
<?php endif; ?>

  <div class="doc-signs">
    <div><div class="sign-line"></div><span>Authorized Signatory — <?= h(cfg('ORG_NAME', 'ASK Organizers')) ?></span></div>
    <div><div class="sign-line"></div><span>Vendor Acknowledgement — <?= h($inv['vendor_name']) ?></span></div>
  </div>

  <p class="doc-footer">Issued by <?= h(cfg('ORG_NAME', 'ASK Organizers')) ?> for services provided at event
    <?= h($inv['unique_id']) ?>. Please quote invoice <?= h($inv['invoice_no']) ?> on all correspondence.</p>
</div>
