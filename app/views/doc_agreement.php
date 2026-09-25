<?php
/** The SLA agreement. Expects $booking, $d (document_data), $viewer. */
declare(strict_types=1);

$rev = (int) $booking['revision'];
$number = format_document_number($booking['unique_id'], 'SLA', $rev);
$watermark = document_watermark($booking);
$guests = (int) $booking['guests'];
?>
<div class="doc-toolbar">
  <button type="button" class="btn primary" id="print-btn">Print / Save as PDF</button>
  <a class="btn" href="<?= h(url('booking/form.php?id=' . (int) $booking['id'])) ?>">Back to the booking</a>
  <a class="btn" href="<?= h(url('documents/invoice.php?id=' . (int) $booking['id'])) ?>">Invoice</a>
  <a class="btn" href="<?= h(url('documents/vendor_sheet.php?id=' . (int) $booking['id'])) ?>">Ops sheet</a>
</div>

<div class="doc card">
<?php if ($watermark): ?>
  <div class="watermark"><?= h($watermark) ?></div>
<?php endif; ?>

  <div class="doc-head">
    <h2>Agreement for Catering / Decoration Services</h2>
    <div class="doc-meta">
      <div><span>Agreement No.</span><strong><?= h($number) ?></strong></div>
      <div><span>Status</span><strong><?= h(strtoupper($booking['status'])) ?></strong></div>
<?php if ($booking['confirmed_at']): ?>
      <div><span>Issued</span><strong><?= h(ddate($booking['confirmed_at'])) ?></strong></div>
<?php endif; ?>
    </div>
  </div>

<?php if ($rev > 0): ?>
  <p class="doc-amend"><strong>Amended Agreement — supersedes Rev <?= $rev - 1 ?>.</strong>
    Amended on <?= h(ddate($d['amendment']['at'] ?? $booking['revised_at'])) ?><?php
    if (!empty($d['amendment']['reason'])): ?>. Reason: <?= h($d['amendment']['reason']) ?><?php endif; ?>.
    The parties must sign this revision.</p>
<?php endif; ?>

  <p class="doc-intro">This Agreement is made on <strong><?= h(dv($booking['agreement_day'], '____')) ?></strong>
    <strong><?= h(dv($booking['agreement_month'], '____________')) ?></strong> at
    <strong><?= h(dv($booking['agreement_place'], 'Karachi')) ?></strong> between the Vendor and the Client named below,
    for the event described in this Schedule.</p>

  <div class="doc-parties">
    <div class="doc-box">
      <h4>Vendor (Service Provider — AO Mess empaneled)</h4>
      <p><strong><?= h(dv($booking['firm_name'])) ?></strong></p>
      <p>Representative: <?= h(dv($booking['rep_name'])) ?></p>
      <p>Contact: <?= h(dv($booking['rep_contact'])) ?></p>
    </div>
    <div class="doc-box">
      <h4>Client (Event Owner)</h4>
      <p><strong><?= h(dv($booking['client_name'])) ?></strong></p>
<?php if ($booking['client_relation']): ?>      <p><?= h($booking['client_relation']) ?></p><?php endif; ?>
      <p>CNIC: <?= h(dv($booking['client_cnic'])) ?></p>
      <p>Contact: <?= h(dv($booking['client_contact'])) ?><?= $booking['client_contact2'] ? ' / ' . h($booking['client_contact2']) : '' ?></p>
<?php if ($booking['client_company']): ?>      <p>Company: <?= h($booking['client_company']) ?></p><?php endif; ?>
<?php if ($booking['client_address']): ?>      <p><?= h($booking['client_address']) ?></p><?php endif; ?>
    </div>
  </div>

  <section class="doc-block">
    <h3>1. Event</h3>
    <table class="doc-table kv">
      <tr><th>Type of event</th><td><?= h(dchoice($booking, 'event_type', 'event_type_other')) ?></td>
          <th>Date</th><td><?= h(ddate($booking['event_date'])) ?><?= $d['event_day'] ? ' (' . h($d['event_day']) . ')' : '' ?></td></tr>
      <tr><th>Venue</th><td><?= h(dvenue($d)) ?></td>
          <th>Alternate date</th><td><?= h(ddate($booking['alt_date'])) ?></td></tr>
      <tr><th>Setup ready by</th><td><?= h(dtime($booking['setup_time'])) ?></td>
          <th>Event starts</th><td><?= h(dtime($booking['start_time'])) ?></td></tr>
      <tr><th>Estimated guests</th><td><?= $guests ?></td>
          <th>Referred by</th><td><?= h(dv(trim($booking['reference_name'] . ' ' . ($booking['reference_department'] ? '(' . $booking['reference_department'] . ')' : '')))) ?></td></tr>
    </table>
  </section>

  <section class="doc-block">
    <h3>2. Catering</h3>
    <table class="doc-table kv">
      <tr><th>Menu type</th><td colspan="3"><?= h(dchoice($booking, 'menu_type', 'menu_type_other')) ?></td></tr>
<?php if ($booking['food_items']): ?>
      <tr><th>Food items</th><td colspan="3"><?= nl2br(h($booking['food_items'])) ?></td></tr>
<?php endif; ?>
    </table>
  </section>

  <section class="doc-block">
    <h3>3. Decoration &amp; setup standards</h3>
    <table class="doc-table kv">
      <tr><th>Theme / colours</th><td><?= h(dv($booking['theme'])) ?></td>
          <th>Decor by</th><td><?= h(dv($booking['decor_by'])) ?></td></tr>
      <tr><th>Stage</th><td><?= h(dchoice($booking, 'stage', 'stage_other')) ?></td>
          <th>Entrance</th><td><?= h(dchoice($booking, 'entrance', 'entrance_other')) ?></td></tr>
      <tr><th>Lighting</th><td><?= h(dchoice($booking, 'lighting', 'lighting_other')) ?></td>
          <th>Floor covering</th><td><?= h(dchoice($booking, 'floor_covering', 'floor_other')) ?></td></tr>
<?php if ($booking['stage_desc']): ?>
      <tr><th>Stage details</th><td colspan="3"><?= nl2br(h($booking['stage_desc'])) ?></td></tr>
<?php endif; ?>
<?php if ($booking['addl_decor']): ?>
      <tr><th>Additional decor</th><td colspan="3"><?= nl2br(h($booking['addl_decor'])) ?></td></tr>
<?php endif; ?>
    </table>

<?php $decorSections = array_intersect_key($d['lines'], array_flip(['decor_general', 'decor_light', 'decor_generator', 'decor_flower', 'decor_extra'])); ?>
<?php if ($decorSections): ?>
    <h4 class="doc-sub">Included decor items</h4>
    <div class="doc-checklist">
<?php foreach ($decorSections as $section => $items): ?>
      <div>
        <strong><?= h(LINE_SECTIONS[$section]) ?></strong>
        <ul>
<?php foreach ($items as $item): ?>
          <li><?= h($item['label']) ?><?= $item['notes'] ? ' — ' . h($item['notes']) : '' ?></li>
<?php endforeach; ?>
        </ul>
      </div>
<?php endforeach; ?>
    </div>
<?php endif; ?>
  </section>

  <section class="doc-block">
    <h3>4. Charges</h3>
    <table class="doc-table money">
      <thead><tr><th>Description</th><th class="num">Rate</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead>
      <tbody>
        <tr><td>Catering per guest</td><td class="num"><?= h(rs($booking['per_head_rate'])) ?></td>
            <td class="num"><?= $guests ?></td><td class="num"><?= h(rs($booking['guest_charges'])) ?></td></tr>
<?php foreach ($d['charges'] as $line): ?>
        <tr><td><?= h($line['label']) ?><?= $line['notes'] ? ' <span class="doc-note">(' . h($line['notes']) . ')</span>' : '' ?></td>
            <td class="num"><?= h(rs($line['rate'])) ?></td>
            <td class="num"><?= $line['unit_snapshot'] === 'per unit' ? (int) $line['qty'] : ($line['unit_snapshot'] === 'per head' ? $guests : '—') ?></td>
            <td class="num"><?= h(rs($line['amount'])) ?></td></tr>
<?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="3">Sub total</td><td class="num"><?= h(rs($booking['sub_total'])) ?></td></tr>
<?php if (decimal_to_paisa($booking['discount']) > 0): ?>
        <tr><td colspan="3">Discount</td><td class="num">− <?= h(rs($booking['discount'])) ?></td></tr>
<?php endif; ?>
        <tr class="grand"><td colspan="3">Net amount payable</td><td class="num"><?= h(rs($booking['grand_total'])) ?></td></tr>
      </tfoot>
    </table>
    <p class="doc-words"><strong>Net amount in words:</strong> <?= h(amount_in_words(decimal_to_paisa($booking['grand_total']))) ?></p>
    <p>Balance due on: <strong><?= h($booking['due_on']) ?></strong>.</p>
  </section>

  <section class="doc-block">
    <h3>5. Cancellation &amp; refund policy</h3>
    <ul class="doc-list">
      <li>Cancelled more than 30 days before the event: <strong><?= $booking['refund_pct_30'] !== null ? h(rtrim(rtrim($booking['refund_pct_30'], '0'), '.')) . '%' : '____' ?></strong> of the amount paid is refunded.</li>
      <li>Cancelled 7–30 days before the event: <strong><?= $booking['refund_pct_7'] !== null ? h(rtrim(rtrim($booking['refund_pct_7'], '0'), '.')) . '%' : '____' ?></strong> of the amount paid is refunded.</li>
      <li>Cancelled less than 7 days before the event: the advance is non-refundable.</li>
    </ul>
    <p class="doc-policy">If the Vendor cancels, the Vendor refunds 200% of the advance received. Force Majeure (war, strikes,
      government bans, floods/rains, death in the family — reported within 12 hours) permits re-scheduling of the event or a
      full refund.</p>
  </section>

<?php if ($booking['special_commitments']): ?>
  <section class="doc-block">
    <h3>6. Special commitments by the Vendor</h3>
    <p><?= nl2br(h($booking['special_commitments'])) ?></p>
  </section>
<?php endif; ?>

  <p class="doc-review">Clause wording is pending legal review by AO Mess.</p>

  <div class="doc-signs">
    <div>
      <div class="sign-line"><?= h(dv($booking['vendor_sign_name'], '')) ?></div>
      <span>Vendor — <?= h(dv($booking['firm_name'], 'name and signature')) ?></span>
      <span>Date: <?= h(ddate($booking['vendor_sign_date'], '____________')) ?></span>
    </div>
    <div>
      <div class="sign-line"><?= h(dv($booking['client_sign_name'], '')) ?></div>
      <span>Client — <?= h(dv($booking['client_name'], 'name and signature')) ?></span>
      <span>Date: <?= h(ddate($booking['client_sign_date'], '____________')) ?></span>
    </div>
  </div>

  <table class="doc-table kv doc-received">
    <tr><th>Received by (AO Mess)</th><td><?= h(dv($booking['received_by'])) ?></td>
        <th>Date</th><td><?= h(ddate($booking['received_date'])) ?></td>
        <th>Time</th><td><?= h(dtime($booking['received_time'])) ?></td></tr>
  </table>
</div>
