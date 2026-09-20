<?php
/** The customer invoice (layout ported from the mockup's renderInvoice()). Expects $booking, $d, $viewer. */
declare(strict_types=1);

$rev = (int) $booking['revision'];
$number = format_document_number($booking['unique_id'], 'INV', $rev);
$watermark = document_watermark($booking);
$guests = (int) $booking['guests'];
$balance = decimal_to_paisa($booking['balance']);
$netPaid = $d['paid'] - $d['refunded'];
?>
<div class="doc-toolbar">
  <button type="button" class="btn primary" id="print-btn">Print / Save as PDF</button>
  <a class="btn" href="<?= h(url('booking/form.php?id=' . (int) $booking['id'])) ?>">Back to the booking</a>
  <a class="btn" href="<?= h(url('documents/agreement.php?id=' . (int) $booking['id'])) ?>">Agreement</a>
  <a class="btn" href="<?= h(url('documents/vendor_sheet.php?id=' . (int) $booking['id'])) ?>">Ops sheet</a>
</div>

<div class="doc card">
<?php if ($watermark): ?>
  <div class="watermark"><?= h($watermark) ?></div>
<?php endif; ?>

  <div class="doc-head">
    <h2>Customer Invoice</h2>
    <div class="doc-meta">
      <div><span>Invoice No.</span><strong><?= h($number) ?></strong></div>
      <div><span>Invoice Date</span><strong><?= h(ddate($booking['confirmed_at'], 'not issued yet')) ?></strong></div>
<?php if ($rev > 0): ?>
      <div><span>Revised</span><strong><?= h(ddate($d['amendment']['at'] ?? $booking['revised_at'])) ?></strong></div>
<?php endif; ?>
      <div><span>Linked SLA</span><strong><?= h(format_document_number($booking['unique_id'], 'SLA', $rev)) ?></strong></div>
    </div>
  </div>

  <div class="doc-parties">
    <div class="doc-box">
      <h4>Billed To</h4>
      <p><strong><?= h(dv($booking['client_name'])) ?></strong></p>
<?php if ($booking['client_relation']): ?>      <p><?= h($booking['client_relation']) ?></p><?php endif; ?>
<?php if ($booking['client_company']): ?>      <p><?= h($booking['client_company']) ?></p><?php endif; ?>
<?php if ($booking['client_cnic']): ?>      <p>CNIC: <?= h($booking['client_cnic']) ?></p><?php endif; ?>
<?php if ($booking['client_contact']): ?>      <p>Contact: <?= h($booking['client_contact']) ?></p><?php endif; ?>
<?php if ($booking['client_address']): ?>      <p><?= h($booking['client_address']) ?></p><?php endif; ?>
    </div>
    <div class="doc-box">
      <h4>Event Reference</h4>
      <p>Type: <?= h(dchoice($booking, 'event_type', 'event_type_other')) ?></p>
      <p>Date: <?= h(ddate($booking['event_date'])) ?><?= $d['event_day'] ? ' (' . h($d['event_day']) . ')' : '' ?></p>
      <p>Venue: <?= h(dv($d['venue'])) ?></p>
      <p>Guests: <?= $guests ?></p>
      <p>Vendor: <?= h(dv($booking['firm_name'])) ?></p>
    </div>
  </div>

  <table class="doc-table money">
    <thead><tr><th>Description</th><th class="num">Amount</th></tr></thead>
    <tbody>
      <tr>
        <td><?= h(invoice_description($booking, $d['venue'])) ?>
<?php if ($booking['special_commitments']): ?>
          <div class="doc-note">Special commitments: <?= h($booking['special_commitments']) ?></div>
<?php endif; ?>
        </td>
        <td class="num"><?= h(rs($booking['guest_charges'])) ?></td>
      </tr>
<?php foreach ($d['charges'] as $line): ?>
      <tr><td><?= h($line['label']) ?>
<?php if ($line['unit_snapshot'] === 'per unit'): ?> <span class="doc-note">(<?= (int) $line['qty'] ?> × <?= h(rs($line['rate'])) ?>)</span>
<?php elseif ($line['unit_snapshot'] === 'per head'): ?> <span class="doc-note">(<?= $guests ?> guests × <?= h(rs($line['rate'])) ?>)</span>
<?php endif; ?>
        </td><td class="num"><?= h(rs($line['amount'])) ?></td></tr>
<?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td>Sub total</td><td class="num"><?= h(rs($booking['sub_total'])) ?></td></tr>
<?php if (decimal_to_paisa($booking['discount']) > 0): ?>
      <tr><td>Discount</td><td class="num">− <?= h(rs($booking['discount'])) ?></td></tr>
<?php endif; ?>
      <tr class="grand"><td>Net amount</td><td class="num"><?= h(rs($booking['grand_total'])) ?></td></tr>
    </tfoot>
  </table>

  <h4 class="doc-sub">Payments received</h4>
<?php if (!$d['payments']): ?>
  <p class="doc-note">No payments recorded against this invoice yet.</p>
<?php else: ?>
  <table class="doc-table money">
    <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr></thead>
    <tbody>
<?php foreach ($d['payments'] as $p): ?>
      <tr><td><?= h(ddate($p['paid_on'])) ?></td>
        <td><?= h(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?><?= $p['kind'] === 'refund' ? ' (refund)' : '' ?></td>
        <td><?= h(dv(trim(($p['bank_name'] ?? '') . ' ' . ($p['reference_no'] ?? '')))) ?></td>
        <td class="num"><?= $p['kind'] === 'refund' ? '− ' : '' ?><?= h(rs($p['amount'])) ?></td></tr>
<?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="3"><?= $booking['status'] === 'cancelled' ? 'Amount retained' : 'Total received' ?></td>
          <td class="num"><?= h(format_rs($netPaid)) ?></td></tr>
    </tfoot>
  </table>
<?php endif; ?>

<?php if ($booking['status'] === 'cancelled'): ?>
  <p class="doc-balance">This booking was cancelled on <?= h(ddate($booking['cancelled_at'])) ?>.
    Amount retained: <strong><?= h(format_rs($netPaid)) ?></strong>. Nothing further is payable.</p>
  <p class="doc-words"><strong>Amount retained in words:</strong> <?= h(amount_in_words($netPaid)) ?></p>
<?php else: ?>
  <p class="doc-balance"><?= $balance < 0 ? 'Overpaid (refundable on request)' : 'Balance due' ?>
    <?= $booking['due_on'] ? ' — ' . h($booking['due_on']) : '' ?>:
    <strong><?= h(format_rs(abs($balance))) ?></strong></p>
  <p class="doc-words"><strong><?= $balance < 0 ? 'Overpayment' : 'Balance due' ?> in words:</strong> <?= h(amount_in_words(abs($balance))) ?></p>
<?php endif; ?>

  <div class="doc-signs">
    <div><div class="sign-line"></div><span>Authorized Signatory — <?= h(dv($booking['firm_name'], cfg('ORG_NAME', 'ASK Organizers'))) ?></span></div>
    <div><div class="sign-line"></div><span>Client Acknowledgement — <?= h(dv($booking['client_name'], '')) ?></span></div>
  </div>

  <p class="doc-footer">Thank you for choosing <?= h(cfg('ORG_NAME', 'ASK Organizers')) ?>.
    This invoice is issued against Service Level Agreement <?= h(format_document_number($booking['unique_id'], 'SLA', $rev)) ?>
    held on file with AO Mess.</p>
</div>
