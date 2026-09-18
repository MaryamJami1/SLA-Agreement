<?php
/**
 * The booking form (layout from the mockup's "Agreement for Catering / Decoration Services").
 * Expects: $booking (stored row or null), $ctx (values/errors/readonly), $formLines, $lineInput,
 *          $viewer, $vendors, $venues, $conflict, $clashMessages.
 */
declare(strict_types=1);

$isAdmin = $viewer['role'] === 'admin';
$ro = $ctx['readonly'];
$status = $booking['status'] ?? 'draft';
$venueValue = fv($ctx, 'venue_id');
if ($venueValue === '' && fv($ctx, 'venue_other') !== '') {
    $venueValue = 'other';
}
$sections = [];
foreach ($formLines as $key => $line) {
    $sections[$line['section']][$key] = $line;
}
$lineVal = static function (string $key, string $field) use ($lineInput): string {
    $v = $lineInput[$key][$field] ?? '';
    return $v === null ? '' : (string) $v;
};
$lineChecked = static fn(string $key): bool => !empty($lineInput[$key]['selected']);
?>
<?php if ($conflict): ?>
<div class="flash error">
  <strong>Not saved:</strong> this booking was changed by someone else after you opened it, so your changes were not saved
  (to avoid overwriting theirs). Your entries are still shown below so you can copy them.
  <a href="<?= h(url('booking/form.php?id=' . (int) $booking['id'])) ?>">Open the latest version</a>.
</div>
<?php endif; ?>
<?php if ($ctx['errors'] && !$conflict): ?>
<div class="flash error"><strong>Not saved.</strong> Please correct the <?= count($ctx['errors']) ?> problem(s) marked below.
  <ul class="error-list"><?php foreach ($ctx['errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php foreach ($clashMessages as $m): ?>
<div class="flash info"><?= h($m) ?></div>
<?php endforeach; ?>

<form method="post" action="<?= h(url('booking/save.php')) ?>" id="booking-form" class="card form-sheet" data-readonly="<?= $ro ? '1' : '0' ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $booking ? (int) $booking['id'] : '' ?>">
  <input type="hidden" name="version" value="<?= $booking ? (int) ($ctx['values']['version'] ?? $booking['version']) : '' ?>">

  <div class="sheet-head">
    <h2>Agreement for Catering / Decoration Services</h2>
    <div class="sheet-ids">
      <span class="badge status-<?= h($status) ?>"><?= h(strtoupper($status)) ?></span>
      <div class="rec-id">ID: <?= $booking ? h(format_document_number($booking['unique_id'], 'SLA', (int) $booking['revision'])) : 'assigned on first save' ?></div>
    </div>
  </div>
<?php if ($ro): ?>
  <p class="readonly-note">
    <?= $status === 'draft' ? 'You can view this booking but not edit it.' : 'This booking is ' . h($status) . ' and can no longer be edited here.' ?>
  </p>
<?php endif; ?>

  <section class="block">
    <h3>Agreement Date &amp; Location</h3>
    <div class="grid g3">
      <?= input_field($ctx, 'agreement_day', 'Day of Signing', 'text', '', ' placeholder="e.g. 14th" maxlength="20"') ?>
      <?= input_field($ctx, 'agreement_month', 'Month, Year', 'text', '', ' placeholder="e.g. September, 2026" maxlength="40"') ?>
      <?= input_field($ctx, 'agreement_place', 'Place', 'text', '', ' maxlength="80"') ?>
    </div>
  </section>

  <section class="block">
    <h3>Client (Event Owner)</h3>
    <div class="grid">
      <?= input_field($ctx, 'client_name', 'Name (Mr./Ms.) *', 'text', 'span2', ' maxlength="150" required') ?>
      <?= input_field($ctx, 'client_relation', 'S/o, W/o, D/o', 'text', 'span2', ' maxlength="150"') ?>
      <?= input_field($ctx, 'client_cnic', 'CNIC No.', 'text', '', ' placeholder="#####-#######-#" maxlength="15"') ?>
      <?= input_field($ctx, 'client_company', 'Company', 'text', '', ' maxlength="150"') ?>
      <?= input_field($ctx, 'client_contact', 'Contact No.', 'text', '', ' maxlength="50"') ?>
      <?= input_field($ctx, 'client_contact2', 'Contact No. 2', 'text', '', ' maxlength="50"') ?>
      <?= textarea_field($ctx, 'client_address', 'Residential Address', 'span2', ' maxlength="255"') ?>
    </div>
  </section>

  <section class="block">
    <h3>Reference</h3>
    <div class="grid">
      <?= input_field($ctx, 'reference_name', 'Referred by (name)', 'text', '', ' maxlength="150"') ?>
      <?= input_field($ctx, 'reference_department', 'Reference department', 'text', '', ' maxlength="150"') ?>
    </div>
  </section>

  <section class="block">
    <h3>Vendor (Service Provider — AO Mess Empaneled)</h3>
    <div class="grid">
<?php if ($isAdmin): ?>
      <div class="field span2"><label for="f_vendor_id">Vendor account</label>
        <select id="f_vendor_id" name="vendor_id"<?= ro($ctx) ?>>
          <option value="">— not assigned yet (required to confirm) —</option>
<?php $vendorListed = false; foreach ($vendors as $v): $sel = (string) $v['id'] === fv($ctx, 'vendor_id'); $vendorListed = $vendorListed || $sel; ?>
          <option value="<?= (int) $v['id'] ?>"<?= $sel ? ' selected' : '' ?>><?= h($v['firm_name'] . ' — ' . $v['rep_name'] . ' (' . $v['username'] . ')') ?></option>
<?php endforeach; ?>
<?php if (!$vendorListed && fv($ctx, 'vendor_id') !== ''): ?>
          <option value="<?= h(fv($ctx, 'vendor_id')) ?>" selected>Current vendor (account not active)</option>
<?php endif; ?>
        </select>
        <?= field_error($ctx, 'vendor_id') ?>
        <span class="hint">Only active vendors are listed. Blank firm/representative fields are filled from the vendor's profile.</span>
      </div>
<?php endif; ?>
      <?= input_field(['readonly' => $ro || !$isAdmin] + $ctx, 'firm_name', 'Name of Firm (M/s.)', 'text', 'span2', ' maxlength="150"') ?>
      <?= input_field(['readonly' => $ro || !$isAdmin] + $ctx, 'rep_name', 'Representative Name (Mr.)', 'text', '', ' maxlength="100"') ?>
      <?= input_field(['readonly' => $ro || !$isAdmin] + $ctx, 'rep_contact', 'Contact No.', 'text', '', ' maxlength="50"') ?>
<?php if (!$isAdmin): ?>
      <p class="hint span2">Taken from your vendor profile when the booking is saved.</p>
<?php endif; ?>
    </div>
  </section>

  <section class="block">
    <h3>Event Details</h3>
    <div class="grid g3">
      <?= select_field($ctx, 'event_type', 'Type of Event', EVENT_TYPES, 'event_type_other') ?>
      <?= input_field($ctx, 'event_date', 'Date of Event', 'date') ?>
      <div class="field"><label>Day</label><output id="event-day" class="readout"><?= fv($ctx, 'event_date') !== '' && strtotime(fv($ctx, 'event_date')) ? h(date('l', strtotime(fv($ctx, 'event_date')))) : '—' ?></output></div>
      <?= input_field($ctx, 'alt_date', 'Alternate Date', 'date') ?>
      <div class="field"><label for="f_venue_id">Venue at AO Mess</label>
        <select id="f_venue_id" name="venue_id" data-other="f_venue_other"<?= ro($ctx) ?>>
          <option value="">—</option>
<?php foreach ($venues as $v): ?>
          <option value="<?= (int) $v['id'] ?>"<?= (string) $v['id'] === $venueValue ? ' selected' : '' ?>><?= h($v['name']) ?><?= (int) $v['is_active'] ? '' : ' (no longer offered)' ?></option>
<?php endforeach; ?>
          <option value="other"<?= $venueValue === 'other' ? ' selected' : '' ?> data-is-other="1">Other</option>
        </select>
        <?= field_error($ctx, 'venue_id') ?>
      </div>
      <div class="field other-field<?= $venueValue === 'other' ? '' : ' hidden' ?>"><label for="f_venue_other">If Other, specify</label>
        <input type="text" id="f_venue_other" name="venue_other" value="<?= h(fv($ctx, 'venue_other')) ?>" maxlength="150"<?= ro($ctx) ?>>
        <?= field_error($ctx, 'venue_other') ?>
        <span class="hint">Venues outside the list can't be checked for double booking.</span>
      </div>
      <?= input_field($ctx, 'setup_time', 'Setup Ready By', 'time') ?>
      <?= input_field($ctx, 'start_time', 'Event Start Time', 'time') ?>
      <?= count_field($ctx, 'guests', 'Estimated Guests') ?>
    </div>
  </section>

  <section class="block">
    <h3>Catering Services — Food &amp; Beverages</h3>
    <div class="grid">
      <?= select_field($ctx, 'menu_type', 'Menu Type', MENU_TYPES, 'menu_type_other') ?>
      <?= textarea_field($ctx, 'food_items', 'Detailed Food Items (starter, main, desserts, beverages)', 'span2', ' rows="4" placeholder="One item per line"') ?>
    </div>
  </section>

  <section class="block">
    <h3>Decoration &amp; Setup Standards</h3>
    <div class="grid">
      <?= input_field($ctx, 'theme', 'Theme / Color Scheme', 'text', 'span2', ' maxlength="150"') ?>
      <?= select_field($ctx, 'stage', 'Stage Decoration', STAGE_TYPES, 'stage_other') ?>
      <?= textarea_field($ctx, 'stage_desc', 'Detailed Description') ?>
      <?= select_field($ctx, 'entrance', 'Entrance Decoration', ENTRANCE_TYPES, 'entrance_other') ?>
      <?= select_field($ctx, 'lighting', 'Lighting', LIGHTING_TYPES, 'lighting_other') ?>
      <?= select_field($ctx, 'floor_covering', 'Floor Covering', FLOOR_TYPES, 'floor_other') ?>
      <?= textarea_field($ctx, 'addl_decor', 'Additional Decor (centerpieces, aisle, etc.)') ?>
      <?= input_field($ctx, 'decor_by', 'Decor By', 'text', '', ' maxlength="150"') ?>
    </div>
  </section>

<?php $decorSections = array_intersect_key($sections, array_flip(['decor_general', 'decor_light', 'decor_generator', 'decor_flower', 'decor_extra'])); ?>
<?php if ($decorSections): ?>
  <section class="block">
    <h3>General Decor Checklist</h3>
    <div class="checklist">
<?php foreach ($decorSections as $sec => $_): ?>
      <div class="checklist-group">
        <h4><?= h(LINE_SECTIONS[$sec]) ?></h4>
<?php foreach ($sections[$sec] as $key => $line): ?>
        <div class="check-row">
          <input type="hidden" name="lines[<?= h($key) ?>][present]" value="1">
          <label><input type="checkbox" name="lines[<?= h($key) ?>][selected]" value="1"<?= $lineChecked($key) ? ' checked' : '' ?><?= ro($ctx) ?>> <?= h($line['label']) ?></label>
          <input type="text" class="note" name="lines[<?= h($key) ?>][notes]" value="<?= h($lineVal($key, 'notes')) ?>" placeholder="notes" maxlength="255" aria-label="Notes for <?= h($line['label']) ?>"<?= ro($ctx) ?>>
          <?= field_error($ctx, "line_$key") ?>
        </div>
<?php endforeach; ?>
      </div>
<?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

  <section class="block">
    <h3>Furniture &amp; Manpower Commitment</h3>
    <table class="furniture-table">
<?php foreach (['sofas' => ['Sofas', 'Units'], 'chairs' => ['General Chairs (Guest)', 'Units'], 'tables_dining' => ['Tables (Dining)', 'Units'],
                'tables_buffet' => ['Tables (Buffet)', 'Units'], 'waiters' => ['Waiters / Service Staff', 'Persons (Uniformed)'], 'chefs' => ['Chefs / Kitchen Staff', 'Persons']] as $name => [$label, $unit]): ?>
      <tr><td><label for="f_<?= $name ?>"><?= h($label) ?></label></td>
        <td class="qty"><input type="text" inputmode="numeric" id="f_<?= $name ?>" name="<?= $name ?>" value="<?= h(fv($ctx, $name)) ?>"<?= ro($ctx) ?>> <?= h($unit) ?><?= field_error($ctx, $name) ?></td></tr>
<?php endforeach; ?>
      <tr><td>Total Staff on Site</td><td class="qty"><output id="total-staff" class="readout"><?= (int) fv($ctx, 'waiters') + (int) fv($ctx, 'chefs') ?></output> Persons</td></tr>
    </table>
  </section>

  <section class="block">
    <h3>Charges</h3>
    <div class="grid g3">
      <?= money_field($ctx, 'per_head_rate', 'Per-head catering rate (Rs.)') ?>
      <div class="field"><label>Guests</label><output id="guests-readout" class="readout"><?= h(fv($ctx, 'guests') ?: '0') ?></output></div>
      <div class="field"><label>Guest charges</label><output id="t-guest" class="readout money"><?= h(rs($booking['guest_charges'] ?? '0')) ?></output></div>
    </div>
<?php if (!empty($sections['charge'])): ?>
    <div class="table-scroll">
    <table class="lines-table">
      <thead><tr><th></th><th>Charge</th><th>Basis</th><th>Rate (Rs.)</th><th>Qty</th><th class="num">Amount</th><th>Notes</th></tr></thead>
      <tbody>
<?php foreach ($sections['charge'] as $key => $line): ?>
        <tr class="charge-line" data-unit="<?= h($line['unit']) ?>">
          <td><input type="hidden" name="lines[<?= h($key) ?>][present]" value="1">
            <input type="checkbox" name="lines[<?= h($key) ?>][selected]" value="1" aria-label="Include <?= h($line['label']) ?>"<?= $lineChecked($key) ? ' checked' : '' ?><?= ro($ctx) ?>></td>
          <td><?= h($line['label']) ?><?= field_error($ctx, "line_$key") ?></td>
          <td class="hint"><?= h($line['unit'] === 'per head' ? 'per guest' : $line['unit']) ?></td>
          <td><input type="text" class="rate" inputmode="decimal" name="lines[<?= h($key) ?>][rate]" value="<?= h($lineVal($key, 'rate')) ?>" aria-label="Rate for <?= h($line['label']) ?>"<?= ro($ctx) ?>></td>
          <td><?php if ($line['unit'] === 'per unit'): ?><input type="text" class="qty" inputmode="numeric" name="lines[<?= h($key) ?>][qty]" value="<?= h($lineVal($key, 'qty')) ?>" aria-label="Quantity for <?= h($line['label']) ?>"<?= ro($ctx) ?>><?php else: ?><span class="hint"><?= $line['unit'] === 'per head' ? '× guests' : '—' ?></span><?php endif; ?></td>
          <td class="num line-amount"><?= h(rs($line['amount'] ?? '0')) ?></td>
          <td><input type="text" class="note" name="lines[<?= h($key) ?>][notes]" value="<?= h($lineVal($key, 'notes')) ?>" maxlength="255" aria-label="Notes for <?= h($line['label']) ?>"<?= ro($ctx) ?>></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php endif; ?>
    <table class="totals-table">
      <tr><td>Guest charges</td><td class="num" id="t-guest2"><?= h(rs($booking['guest_charges'] ?? '0')) ?></td></tr>
      <tr><td>Other charges</td><td class="num" id="t-charges"><?= h(rs($booking['charges_total'] ?? '0')) ?></td></tr>
      <tr><td>Sub total</td><td class="num" id="t-sub"><?= h(rs($booking['sub_total'] ?? '0')) ?></td></tr>
      <tr><td><label for="f_discount">Discount (Rs.)</label></td>
        <td class="num"><input type="text" inputmode="decimal" id="f_discount" name="discount" value="<?= h(fv($ctx, 'discount')) ?>"<?= ro($ctx) ?>><?= field_error($ctx, 'discount') ?></td></tr>
      <tr class="grand"><td>Net amount</td><td class="num" id="t-grand"><?= h(rs($booking['grand_total'] ?? '0')) ?></td></tr>
<?php if ($booking): ?>
      <tr><td>Paid to date</td><td class="num"><?= h(rs($booking['paid_total'])) ?></td></tr>
      <tr><td>Balance</td><td class="num"><?= h(rs($booking['balance'])) ?></td></tr>
<?php endif; ?>
    </table>
    <?= field_error($ctx, 'totals') ?>
    <p class="hint">Amounts on this page update as you type; the server recalculates everything when you save.</p>
  </section>

<?php if (!empty($sections['ops_item'])): ?>
  <section class="block">
    <h3>Operations Sheet Items (internal)</h3>
    <table class="lines-table">
      <thead><tr><th></th><th>Item</th><th>Qty</th><th>Notes</th></tr></thead>
      <tbody>
<?php foreach ($sections['ops_item'] as $key => $line): ?>
        <tr>
          <td><input type="hidden" name="lines[<?= h($key) ?>][present]" value="1">
            <input type="checkbox" name="lines[<?= h($key) ?>][selected]" value="1" aria-label="Include <?= h($line['label']) ?>"<?= $lineChecked($key) ? ' checked' : '' ?><?= ro($ctx) ?>></td>
          <td><?= h($line['label']) ?><?= field_error($ctx, "line_$key") ?></td>
          <td><input type="text" class="qty" inputmode="numeric" name="lines[<?= h($key) ?>][qty]" value="<?= h($lineVal($key, 'qty')) ?>" aria-label="Quantity for <?= h($line['label']) ?>"<?= ro($ctx) ?>></td>
          <td><input type="text" class="note" name="lines[<?= h($key) ?>][notes]" value="<?= h($lineVal($key, 'notes')) ?>" maxlength="255" aria-label="Notes for <?= h($line['label']) ?>"<?= ro($ctx) ?>></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>

  <section class="block">
    <h3>Payment Terms</h3>
    <div class="grid g3">
      <?= select_field($ctx, 'due_on', 'Balance Due On', DUE_ON_TYPES, null, false) ?>
    </div>
    <p class="hint">Payments and refunds are recorded separately, one entry each, by AO Mess.</p>
  </section>

  <section class="block">
    <h3>Cancellation &amp; Refund Policy</h3>
    <div class="grid g3">
      <?= input_field($ctx, 'refund_pct_30', 'Refund if cancelled >30 days before (%)', 'text', '', ' inputmode="decimal"') ?>
      <?= input_field($ctx, 'refund_pct_7', 'Refund if cancelled 7–30 days before (%)', 'text', '', ' inputmode="decimal"') ?>
      <div class="field"><label>&lt;7 days before</label><output class="readout">Advance non-refundable</output></div>
    </div>
    <p class="policy-note">If Vendor cancels, Vendor refunds 200% of advance received. Force Majeure (war, strikes, government bans,
      floods/rains, death in family — reported within 12 hours) permits event re-scheduling or full refund.</p>
  </section>

  <section class="block">
    <h3>Special Commitments</h3>
    <?= textarea_field($ctx, 'special_commitments', 'Specific promises made by the Vendor', '', ' placeholder="e.g. Dedicated event coordinator on site, specific type of flower"') ?>
  </section>

  <section class="block">
    <h3>Acknowledgment &amp; Signatures</h3>
    <div class="grid">
      <?= input_field($ctx, 'vendor_sign_name', 'Vendor — Name', 'text', '', ' maxlength="150"') ?>
      <?= input_field($ctx, 'vendor_sign_date', 'Vendor — Date', 'date') ?>
      <?= input_field($ctx, 'client_sign_name', 'Client — Name', 'text', '', ' maxlength="150"') ?>
      <?= input_field($ctx, 'client_sign_date', 'Client — Date', 'date') ?>
    </div>
  </section>

  <section class="block">
    <h3>For AO Mess Records</h3>
    <div class="grid g3">
      <?= input_field($ctx, 'received_by', 'Received By', 'text', '', ' maxlength="100"') ?>
      <?= input_field($ctx, 'received_date', 'Date', 'date') ?>
      <?= input_field($ctx, 'received_time', 'Time', 'time') ?>
    </div>
  </section>

  <div class="actionbar">
<?php if (!$ro): ?>
    <button type="submit" class="btn primary"><?= $booking ? 'Save Changes' : 'Save as New Booking' ?></button>
<?php endif; ?>
    <a class="btn" href="<?= h(url('index.php')) ?>"><?= $ro ? 'Back' : 'Cancel' ?></a>
    <span class="status"><?= $booking ? 'Last saved ' . h(date('d M Y H:i', strtotime((string) ($booking['updated_at'] ?? $booking['created_at'])))) : 'Not saved yet' ?></span>
  </div>
</form>
