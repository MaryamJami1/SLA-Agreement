<?php
/** The internal operations sheet for the vendor (from the reference event-inventory sheet). Expects $booking, $d, $viewer. */
declare(strict_types=1);

$rev = (int) $booking['revision'];
$watermark = document_watermark($booking);
$guests = (int) $booking['guests'];
$ops = $d['lines']['ops_item'] ?? [];
$decorSections = array_intersect_key($d['lines'], array_flip(['decor_general', 'decor_light', 'decor_generator', 'decor_flower', 'decor_extra']));
?>
<div class="doc-toolbar">
  <button type="button" class="btn primary" id="print-btn">Print / Save as PDF</button>
  <a class="btn" href="<?= h(url('booking/form.php?id=' . (int) $booking['id'])) ?>">Back to the booking</a>
  <a class="btn" href="<?= h(url('documents/agreement.php?id=' . (int) $booking['id'])) ?>">Agreement</a>
  <a class="btn" href="<?= h(url('documents/invoice.php?id=' . (int) $booking['id'])) ?>">Invoice</a>
</div>

<div class="doc card">
<?php if ($watermark): ?>
  <div class="watermark"><?= h($watermark) ?></div>
<?php endif; ?>

  <div class="doc-head">
    <h2>Operations Sheet — Event Inventory</h2>
    <div class="doc-meta">
      <div><span>SLA No.</span><strong><?= h(format_document_number($booking['unique_id'], 'SLA', $rev)) ?></strong></div>
      <div><span>Status</span><strong><?= h(strtoupper($booking['status'])) ?></strong></div>
      <div><span>Printed</span><strong><?= h(date('d M Y')) ?></strong></div>
    </div>
  </div>
  <p class="doc-note">Internal working copy for the vendor and AO Mess operations staff.</p>

  <table class="doc-table kv">
    <tr><th>Vendor</th><td><?= h(dv($booking['firm_name'])) ?></td>
        <th>Venue</th><td><?= h(dv($d['venue'])) ?></td></tr>
    <tr><th>Contact person</th><td><?= h(dv($booking['rep_name'])) ?> <?= h(dv($booking['rep_contact'], '')) ?></td>
        <th>Event</th><td><?= h(dchoice($booking, 'event_type', 'event_type_other')) ?></td></tr>
    <tr><th>Event date</th><td><?= h(ddate($booking['event_date'])) ?><?= $d['event_day'] ? ' (' . h($d['event_day']) . ')' : '' ?></td>
        <th>No. of PAX</th><td><?= $guests ?></td></tr>
    <tr><th>Setup ready by</th><td><?= h(dtime($booking['setup_time'])) ?></td>
        <th>Event starts</th><td><?= h(dtime($booking['start_time'])) ?></td></tr>
    <tr><th>Client</th><td><?= h(dv($booking['client_name'])) ?></td>
        <th>Client contact</th><td><?= h(dv($booking['client_contact'])) ?></td></tr>
  </table>

  <h4 class="doc-sub">Inventory / setup items</h4>
<?php if (!$ops): ?>
  <p class="doc-note">No operations items selected for this booking.</p>
<?php else: ?>
  <table class="doc-table ops">
    <thead><tr><th class="sn">S. No.</th><th>Particular</th><th class="num">Qty</th><th>Notes</th><th class="tick">Done</th></tr></thead>
    <tbody>
<?php foreach ($ops as $i => $item): ?>
      <tr><td class="sn"><?= $i + 1 ?></td><td><?= h($item['label']) ?></td>
        <td class="num"><?= $item['qty'] === null ? '—' : (int) $item['qty'] ?></td>
        <td><?= h(dv($item['notes'], '')) ?></td><td class="tick"></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php if ($decorSections): ?>
  <h4 class="doc-sub">Decor checklist</h4>
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

  <h4 class="doc-sub">Furniture &amp; manpower</h4>
  <table class="doc-table kv">
    <tr><th>Sofas</th><td><?= (int) $booking['sofas'] ?></td><th>Chairs</th><td><?= (int) $booking['chairs'] ?></td>
        <th>Dining tables</th><td><?= (int) $booking['tables_dining'] ?></td></tr>
    <tr><th>Buffet tables</th><td><?= (int) $booking['tables_buffet'] ?></td><th>Waiters</th><td><?= (int) $booking['waiters'] ?></td>
        <th>Chefs</th><td><?= (int) $booking['chefs'] ?></td></tr>
  </table>

<?php if ($booking['food_items'] || $booking['menu_type']): ?>
  <h4 class="doc-sub">Catering</h4>
  <p><strong><?= h(dchoice($booking, 'menu_type', 'menu_type_other')) ?></strong></p>
<?php if ($booking['food_items']): ?>
  <p><?= nl2br(h($booking['food_items'])) ?></p>
<?php endif; ?>
<?php endif; ?>

<?php if ($booking['special_commitments']): ?>
  <h4 class="doc-sub">Special commitments</h4>
  <p><?= nl2br(h($booking['special_commitments'])) ?></p>
<?php endif; ?>

  <h4 class="doc-sub">Account summary</h4>
  <table class="doc-table money compact">
    <tr><td>Total amount</td><td class="num"><?= h(rs($booking['grand_total'])) ?></td></tr>
    <tr><td><?= $booking['status'] === 'cancelled' ? 'Retained' : 'Received' ?></td>
        <td class="num"><?= h(format_rs($d['paid'] - $d['refunded'])) ?></td></tr>
<?php if ($booking['status'] !== 'cancelled'): ?>
    <tr class="grand"><td>Remaining</td><td class="num"><?= h(rs($booking['balance'])) ?></td></tr>
<?php endif; ?>
  </table>

  <div class="doc-signs">
    <div><div class="sign-line"></div><span>Vendor representative on site</span></div>
    <div><div class="sign-line"></div><span>AO Mess operations</span></div>
  </div>
</div>
