<?php
/**
 * Vendors on one event (admin only — booking agents never see vendor costs). Expects $booking, $pdo.
 * Assign vendor services, issue a vendor invoice per vendor, and jump to the invoices.
 */
declare(strict_types=1);

$bid = (int) $booking['id'];
$vendorGroups = booking_vendor_lines($pdo, $bid);
$vendorInvoices = booking_vendor_invoices($pdo, $bid);
$assignable = vendors_for_assignment($pdo);
$canChangeLines = in_array($booking['status'], VENDOR_LINE_STATUSES, true);
$vendorAction = url('vendors/save.php');
$hidden = csrf_field() . '<input type="hidden" name="booking_id" value="' . $bid . '">';
?>
<div class="card pad payments vendors no-print" id="vendors">
  <h3>VENDORS</h3>
  <p class="muted">Vendor services booked for this event, at the rates agreed with each vendor. Only the admin sees this section.
    Issue one invoice per vendor once their services are settled; services on an issued invoice are locked.</p>

<?php if (!$vendorGroups): ?>
  <p class="muted">No vendors assigned yet.</p>
<?php endif; ?>
<?php foreach ($vendorGroups as $g):
    $open = array_values(array_filter($g['lines'], static fn($l) => $l['vendor_invoice_id'] === null));
    $openTotal = array_sum(array_map(static fn($l) => decimal_to_paisa($l['amount']), $open));
    $allTotal = array_sum(array_map(static fn($l) => decimal_to_paisa($l['amount']), $g['lines'])); ?>
  <div class="vendor-group">
    <h4><a href="<?= h(url('admin/vendor.php?id=' . $g['id'])) ?>"><?= h($g['name']) ?></a> <span class="muted">· <?= h($g['category']) ?></span></h4>
    <div class="table-scroll">
    <table class="lines-table">
      <thead><tr><th>Service</th><th>Unit</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th><th>Invoice</th></tr></thead>
      <tbody>
<?php foreach ($g['lines'] as $l): ?>
        <tr>
          <td><?= h($l['label']) ?><?php if ($l['notes']): ?><div class="hint"><?= h($l['notes']) ?></div><?php endif; ?></td>
          <td><?= h(VENDOR_UNITS[$l['unit']] ?? $l['unit']) ?></td>
          <td class="num"><?= h(group_digits_south_asian((string) $l['qty'])) ?></td>
          <td class="num"><?= h(rs($l['rate'])) ?></td>
          <td class="num"><?= h(rs($l['amount'])) ?></td>
          <td>
<?php if ($l['vendor_invoice_id'] !== null): ?>
            <a href="<?= h(url('vendors/invoice.php?id=' . (int) $l['vendor_invoice_id'])) ?>"><?= h($l['invoice_no']) ?></a>
<?php elseif ($canChangeLines): ?>
            <form method="post" action="<?= h($vendorAction) ?>" class="inline"
                  data-confirm="Remove “<?= h($l['label']) ?>” from this event?">
              <?= $hidden ?>
              <input type="hidden" name="line_id" value="<?= (int) $l['id'] ?>">
              <button class="btn small danger" name="action" value="remove_line">Remove</button>
            </form>
<?php else: ?>
            <span class="muted">not invoiced</span>
<?php endif; ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="4">Total for <?= h($g['name']) ?></td><td class="num"><strong><?= h(format_rs($allTotal)) ?></strong></td><td></td></tr>
      </tfoot>
    </table>
    </div>
<?php if ($open): ?>
    <form method="post" action="<?= h($vendorAction) ?>" class="payment-form"
          data-confirm="Issue an invoice to <?= h($g['name']) ?> for <?= count($open) ?> service(s), <?= h(format_rs($openTotal)) ?> before discount and tax?">
      <?= $hidden ?>
      <input type="hidden" name="vendor_id" value="<?= (int) $g['id'] ?>">
      <h4>Generate invoice — <?= count($open) ?> service(s) not invoiced yet, <?= h(format_rs($openTotal)) ?></h4>
      <div class="grid g3">
        <div class="field"><label for="vd<?= (int) $g['id'] ?>">Discount (Rs.)</label>
          <input type="text" id="vd<?= (int) $g['id'] ?>" name="discount" inputmode="decimal" placeholder="0"></div>
        <div class="field"><label for="vt<?= (int) $g['id'] ?>">Tax (%)</label>
          <input type="text" id="vt<?= (int) $g['id'] ?>" name="tax_pct" inputmode="decimal" placeholder="0"></div>
        <div class="field"><label for="vn<?= (int) $g['id'] ?>">Note on the invoice</label>
          <input type="text" id="vn<?= (int) $g['id'] ?>" name="notes" maxlength="255"></div>
      </div>
      <button class="btn primary" name="action" value="generate_invoice">Generate invoice</button>
    </form>
<?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($vendorInvoices): ?>
  <h4>Vendor invoices for this event</h4>
  <div class="table-scroll">
  <table class="lines-table">
    <thead><tr><th>Invoice</th><th>Vendor</th><th>Date</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Remaining</th><th>Status</th></tr></thead>
    <tbody>
<?php foreach ($vendorInvoices as $i): ?>
      <tr>
        <td><a href="<?= h(url('vendors/invoice.php?id=' . (int) $i['id'])) ?>"><?= h($i['invoice_no']) ?></a></td>
        <td><?= h($i['vendor_name']) ?></td>
        <td><?= h(date('d M Y', strtotime($i['invoice_date']))) ?></td>
        <td class="num"><?= h(rs($i['grand_total'])) ?></td>
        <td class="num"><?= h(rs($i['paid_total'])) ?></td>
        <td class="num"><?= $i['status'] === 'void' ? '—' : h(rs($i['balance'])) ?></td>
        <td><?= vendor_status_badge($i) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php if (!$canChangeLines): ?>
  <p class="hint">This booking is <?= h($booking['status']) ?>, so vendor services can't be added or removed. Existing invoices still accept payments.</p>
<?php elseif (!$assignable): ?>
  <p class="muted">No active vendor has services yet. <a href="<?= h(url('admin/vendors.php')) ?>">Add vendors and their services</a> first.</p>
<?php else: ?>
  <form method="post" action="<?= h($vendorAction) ?>" class="payment-form" id="vendor-line-form" data-guests="<?= (int) $booking['guests'] ?>">
    <?= $hidden ?>
    <h4>Assign a vendor service</h4>
    <div class="grid g3">
      <div class="field span2"><label for="vl_service">Vendor and service</label>
        <select id="vl_service" name="service_id" required>
          <option value="">—</option>
<?php foreach ($assignable as $av): ?>
          <optgroup label="<?= h($av['name'] . ' (' . $av['category'] . ')') ?>">
<?php foreach ($av['services'] as $s): ?>
            <option value="<?= (int) $s['id'] ?>" data-unit="<?= h(VENDOR_UNITS[$s['unit']] ?? $s['unit']) ?>" data-unit-key="<?= h($s['unit']) ?>"
                    data-rate="<?= h($s['rate']) ?>"><?= h($av['name'] . ' — ' . $s['name'] . ' · ' . (VENDOR_UNITS[$s['unit']] ?? $s['unit']) . ' · ' . rs($s['rate'])) ?></option>
<?php endforeach; ?>
          </optgroup>
<?php endforeach; ?>
        </select></div>
      <div class="field"><label for="vl_unit">Unit</label>
        <input type="text" id="vl_unit" value="" readonly tabindex="-1" aria-readonly="true"></div>
      <div class="field"><label for="vl_qty">Quantity</label>
        <input type="text" id="vl_qty" name="qty" inputmode="numeric" value="1" required></div>
      <div class="field"><label for="vl_rate">Rate</label>
        <input type="text" id="vl_rate" value="" readonly tabindex="-1" aria-readonly="true"></div>
      <div class="field"><label for="vl_amount">Amount</label>
        <input type="text" id="vl_amount" value="" readonly tabindex="-1" aria-readonly="true"></div>
      <div class="field span3"><label for="vl_notes">Note</label>
        <input type="text" id="vl_notes" name="notes" maxlength="255"></div>
    </div>
    <p class="hint">The rate comes from the vendor's price list (change it on the vendor's page). Per-person services start
      with this booking's guest count (<?= (int) $booking['guests'] ?>).</p>
    <button class="btn primary" name="action" value="add_line">Assign service</button>
  </form>
<?php endif; ?>
</div>
