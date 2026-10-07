<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/admin_data.php';
require_once APP_ROOT . '/app/payments.php';
require_once APP_ROOT . '/app/vendors.php';
require_once APP_ROOT . '/app/views/form_helpers.php';
require_once APP_ROOT . '/app/views/vendor_service_picker.php';

$admin = require_admin();
$pdo = db();
$vendorId = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceId = ctype_digit((string) ($_POST['service_id'] ?? '')) ? (int) $_POST['service_id'] : 0;
    $back = 'admin/vendor.php?id=' . $vendorId;
    try {
        switch ($_POST['action'] ?? '') {
            case 'update':
                update_vendor($pdo, $admin, $vendorId, clean_vendor_input($_POST));
                flash('ok', 'Vendor details saved.');
                break;
            case 'delete':
                flash('ok', 'Deleted vendor “' . delete_vendor($pdo, $admin, $vendorId) . '”.');
                redirect('admin/vendors.php');
                break;
            case 'service_create':
                flash('ok', 'Added service “' . create_vendor_service($pdo, $admin, $vendorId, clean_vendor_service_input($_POST)) . '”.');
                $back .= '#services';
                break;
            case 'services_from_category':
                $added = add_catalog_services($pdo, $admin, $vendorId, clean_catalog_picks($_POST));
                flash('ok', 'Added ' . count($added) . ' service(s): ' . implode(', ', $added) . '.');
                $back .= '#services';
                break;
            case 'service_update':
                update_vendor_service($pdo, $admin, $vendorId, $serviceId, clean_vendor_service_input($_POST));
                flash('ok', 'Service saved. Events and invoices that already use it keep their own name and rate.');
                $back .= '#services';
                break;
            case 'service_delete':
                flash('ok', 'Deleted service “' . delete_vendor_service($pdo, $admin, $vendorId, $serviceId) . '”.');
                $back .= '#services';
                break;
            default:
                flash('error', 'Unknown action.');
        }
    } catch (AdminRefused | TryAgainException $e) {
        flash('error', $e->getMessage());
    }
    redirect($back);
}

$o = vendor_overview($pdo, $vendorId);
if ($o === null) {
    not_found();
}
$v = $o['vendor'];
$categories = vendor_categories_for_select($pdo, (int) $v['category_id']);
$used = $o['events'] || $o['invoices'];
$catalog = vendor_category_service_catalog($pdo);

$pageTitle = $v['name'];
$activeTab = 'vendors';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2><?= h($v['name']) ?></h2>
  <span class="badge <?= (int) $v['is_active'] ? 'active' : 'disabled' ?>"><?= (int) $v['is_active'] ? 'ACTIVE' : 'INACTIVE' ?></span>
  <span class="muted"><?= h($v['category_name']) ?></span>
  <a class="btn small" href="<?= h(url('admin/vendors.php')) ?>">All vendors</a>
</div>

<div class="card pad">
  <div class="money-summary">
    <div><span>Total invoiced</span><strong><?= h(format_rs($o['invoiced'])) ?></strong></div>
    <div><span>Total paid</span><strong><?= h(format_rs($o['paid'])) ?></strong></div>
    <div class="emph"><span>Outstanding</span><strong><?= h(format_rs($o['outstanding'])) ?></strong></div>
  </div>
</div>

<div class="card pad" id="details">
  <h3>VENDOR DETAILS</h3>
  <form method="post" action="<?= h(url('admin/vendor.php?id=' . $vendorId)) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="grid g3">
      <div class="field"><label for="e_name">Vendor name</label>
        <input type="text" id="e_name" name="name" maxlength="150" value="<?= h($v['name']) ?>" required></div>
      <div class="field"><label for="e_category">Category</label>
        <select id="e_category" name="category_id" required>
<?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $v['category_id'] ? ' selected' : '' ?>><?= h($c['name']) ?><?= (int) $c['is_active'] ? '' : ' (inactive)' ?></option>
<?php endforeach; ?>
        </select></div>
      <div class="field"><label for="e_person">Contact person</label>
        <input type="text" id="e_person" name="contact_person" maxlength="100" value="<?= h($v['contact_person']) ?>"></div>
      <div class="field"><label for="e_phone">Phone</label>
        <input type="text" id="e_phone" name="phone" maxlength="50" value="<?= h($v['phone']) ?>"></div>
      <div class="field"><label for="e_phone2">Second phone</label>
        <input type="text" id="e_phone2" name="phone2" maxlength="50" value="<?= h($v['phone2']) ?>"></div>
      <div class="field"><label for="e_email">Email</label>
        <input type="email" id="e_email" name="email" maxlength="150" value="<?= h($v['email']) ?>"></div>
      <div class="field span2"><label for="e_address">Address</label>
        <input type="text" id="e_address" name="address" maxlength="255" value="<?= h($v['address']) ?>"></div>
      <div class="field"><label for="e_notes">Notes</label>
        <input type="text" id="e_notes" name="notes" maxlength="2000" value="<?= h($v['notes']) ?>"></div>
    </div>
    <p>
      <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $v['is_active'] ? ' checked' : '' ?>>
        Active (inactive vendors can't be assigned to new events)</label>
    </p>
    <p><button class="btn primary" name="action" value="update">Save details</button></p>
    <p class="hint">Added <?= h(date('d M Y', strtotime($v['created_at']))) ?>. Invoices already issued keep the details they were issued with.</p>
  </form>
<?php if (vendor_can_be_deleted($v, $used)): ?>
  <form method="post" action="<?= h(url('admin/vendor.php?id=' . $vendorId)) ?>" class="admin-row-extra"
        data-confirm="<?= $used
            ? 'Permanently delete “' . h($v['name']) . '” with its services, its lines on ' . count($o['events']) . ' event(s), '
              . count($o['invoices']) . ' invoice(s) and ' . count($o['payments']) . ' payment(s)? This can’t be undone.'
            : 'Delete “' . h($v['name']) . '” and its services? It has never been assigned to an event.' ?>">
    <?= csrf_field() ?>
    <button class="btn small danger" name="action" value="delete">Delete this vendor</button>
<?php if ($used): ?>
    <span class="hint">Also deletes this vendor's event lines, invoices and payments, for good.</span>
<?php endif; ?>
  </form>
<?php else: ?>
  <p class="hint admin-row-extra">To delete this vendor, untick <strong>Active</strong> and save first.</p>
<?php endif; ?>
</div>

<div class="card pad" id="services">
  <h3>SERVICES &amp; RATES</h3>
  <p class="muted">Each service is copied onto an event when it is assigned, with its rate, so changing a rate here never changes
    an event or an invoice that already has it. A service used on any event can only be deactivated.</p>
<?php if (!$o['services']): ?>
  <p class="muted">No services yet. Add the first one below.</p>
<?php endif; ?>
<?php foreach ($o['services'] as $s): $sid = (int) $s['id']; ?>
  <form method="post" action="<?= h(url('admin/vendor.php?id=' . $vendorId)) ?>" class="admin-row">
    <?= csrf_field() ?>
    <input type="hidden" name="service_id" value="<?= $sid ?>">
    <div class="field grow"><label for="sn<?= $sid ?>">Service</label>
      <input type="text" id="sn<?= $sid ?>" name="name" maxlength="150" value="<?= h($s['name']) ?>">
<?php if ((int) $s['used'] > 0): ?>      <span class="hint">used on <?= (int) $s['used'] ?> event line(s)</span><?php endif; ?></div>
    <div class="field narrow"><label for="su<?= $sid ?>">Unit</label>
      <select id="su<?= $sid ?>" name="unit">
<?php foreach (VENDOR_UNITS as $value => $label): ?>
        <option value="<?= h($value) ?>"<?= $s['unit'] === $value ? ' selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
      </select></div>
    <div class="field narrow"><label for="sr<?= $sid ?>">Rate (Rs.)</label>
      <input type="text" id="sr<?= $sid ?>" name="rate" inputmode="decimal" value="<?= h(rtrim(rtrim($s['rate'], '0'), '.')) ?>"></div>
    <div class="field narrow"><label for="so<?= $sid ?>">Order</label>
      <input type="text" id="so<?= $sid ?>" name="sort_order" inputmode="numeric" value="<?= (int) $s['sort_order'] ?>"></div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $s['is_active'] ? ' checked' : '' ?>> Offered</label>
    <button class="btn small" name="action" value="service_update">Save</button>
  </form>
<?php if ((int) $s['used'] === 0): ?>
  <form method="post" action="<?= h(url('admin/vendor.php?id=' . $vendorId)) ?>" class="admin-row-extra"
        data-confirm="Delete the service “<?= h($s['name']) ?>”? It has never been used on an event.">
    <?= csrf_field() ?>
    <input type="hidden" name="service_id" value="<?= $sid ?>">
    <button class="btn small danger" name="action" value="service_delete">Delete “<?= h($s['name']) ?>”</button>
  </form>
<?php endif; ?>
<?php endforeach; ?>

  <h4>Add services from “<?= h($v['category_name']) ?>”</h4>
  <form method="post" action="<?= h(url('admin/vendor.php?id=' . $vendorId)) ?>">
    <?= csrf_field() ?>
<?php vendor_service_picker($catalog, [(int) $v['category_id']], (int) $v['category_id'], null, array_column($o['services'], 'name')); ?>
<?php if (!empty($catalog[(int) $v['category_id']])): ?>
    <p><button class="btn primary" name="action" value="services_from_category">Add ticked services</button></p>
<?php endif; ?>
  </form>

  <h4>Add another service</h4>
  <form method="post" action="<?= h(url('admin/vendor.php?id=' . $vendorId)) ?>" class="admin-row">
    <?= csrf_field() ?>
    <input type="hidden" name="is_active" value="1">
    <div class="field grow"><label for="ns_name">Service</label>
      <input type="text" id="ns_name" name="name" maxlength="150" placeholder="e.g. Buffet" required></div>
    <div class="field narrow"><label for="ns_unit">Unit</label>
      <select id="ns_unit" name="unit">
<?php foreach (VENDOR_UNITS as $value => $label): ?>
        <option value="<?= h($value) ?>"><?= h($label) ?></option>
<?php endforeach; ?>
      </select></div>
    <div class="field narrow"><label for="ns_rate">Rate (Rs.)</label>
      <input type="text" id="ns_rate" name="rate" inputmode="decimal" required></div>
    <div class="field narrow"><label for="ns_sort">Order</label>
      <input type="text" id="ns_sort" name="sort_order" inputmode="numeric" value="0"></div>
    <button class="btn primary" name="action" value="service_create">Add service</button>
  </form>
</div>

<div class="card pad" id="events">
  <h3>ASSIGNED EVENTS</h3>
<?php if (!$o['events']): ?>
  <p class="muted">Not assigned to any event yet. Open a booking and use its <strong>Vendors</strong> section to assign this vendor.</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="registry">
    <thead><tr><th>Booking</th><th>Event</th><th>Event date</th><th>Status</th><th class="num">Lines</th>
      <th class="num">Assigned</th><th class="num">Not invoiced yet</th></tr></thead>
    <tbody>
<?php foreach ($o['events'] as $e): ?>
      <tr>
        <td class="id-cell"><a href="<?= h(url('booking/form.php?id=' . (int) $e['id'])) ?>#vendors"><?= h($e['unique_id']) ?></a></td>
        <td><?= h(vendor_event_label($e)) ?></td>
        <td><?= $e['event_date'] ? h(date('d M Y', strtotime($e['event_date']))) : '—' ?></td>
        <td><span class="badge status-<?= h($e['status']) ?>"><?= h(strtoupper($e['status'])) ?></span></td>
        <td class="num"><?= (int) $e['line_count'] ?></td>
        <td class="num"><?= h(rs($e['assigned'])) ?></td>
        <td class="num"><?= h(rs($e['uninvoiced'])) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>

<div class="card pad" id="invoices">
  <h3>INVOICES</h3>
<?php if (!$o['invoices']): ?>
  <p class="muted">No invoices yet. Invoices are issued from the booking page once services are assigned.</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="registry">
    <thead><tr><th>Invoice</th><th>Date</th><th>Event</th><th class="num">Total</th><th class="num">Paid</th>
      <th class="num">Remaining</th><th>Status</th></tr></thead>
    <tbody>
<?php foreach ($o['invoices'] as $i): ?>
      <tr>
        <td class="id-cell"><a href="<?= h(url('vendors/invoice.php?id=' . (int) $i['id'])) ?>"><?= h($i['invoice_no']) ?></a></td>
        <td><?= h(date('d M Y', strtotime($i['invoice_date']))) ?></td>
        <td><?= h($i['event_label']) ?></td>
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
</div>

<div class="card pad" id="payments">
  <h3>PAYMENTS</h3>
<?php if (!$o['payments']): ?>
  <p class="muted">No payments recorded yet. Payments are recorded on each invoice.</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="registry">
    <thead><tr><th>Date</th><th>Invoice</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th>Recorded by</th></tr></thead>
    <tbody>
<?php foreach ($o['payments'] as $p): $voided = $p['voided_at'] !== null; ?>
      <tr class="<?= $voided ? 'voided' : '' ?>">
        <td><?= h(date('d M Y', strtotime($p['paid_on']))) ?></td>
        <td class="id-cell"><a href="<?= h(url('vendors/invoice.php?id=' . (int) $p['vendor_invoice_id'])) ?>"><?= h($p['invoice_no']) ?></a></td>
        <td><?= h(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></td>
        <td><?= h(trim(($p['bank_name'] ?? '') . ' ' . ($p['reference_no'] ?? ''))) ?: '—' ?>
          <?php if ($p['notes']): ?><div class="hint"><?= h($p['notes']) ?></div><?php endif; ?></td>
        <td class="num"><?= h(rs($p['amount'])) ?></td>
        <td><?= h($p['recorded_by_name']) ?><?php if ($voided): ?> <span class="badge status-cancelled">VOIDED</span>
          <div class="hint"><?= h($p['void_reason']) ?></div><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
