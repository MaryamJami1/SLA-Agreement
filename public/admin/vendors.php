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

$new = ['category_id' => '', 'name' => '', 'contact_person' => '', 'phone' => '', 'phone2' => '', 'email' => '', 'address' => '', 'notes' => ''];
$createError = null;
$createPost = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $id = create_vendor($pdo, $admin, clean_vendor_input($_POST), clean_catalog_picks($_POST));
        flash('ok', 'Vendor added. Check the services and their rates below.');
        redirect('admin/vendor.php?id=' . $id);
    } catch (AdminRefused | TryAgainException $e) {
        // Show the form again with what was typed.
        $createError = $e->getMessage();
        $createPost = $_POST;
        foreach ($new as $k => $_) {
            $new[$k] = is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
        }
    }
}

$categories = vendor_categories_with_usage($pdo);
$filter = ctype_digit((string) ($_GET['category'] ?? '')) ? (int) $_GET['category'] : 0;
$vendors = vendors_list($pdo, $filter ?: null);
$totals = ['invoiced' => 0, 'paid' => 0, 'outstanding' => 0];
foreach ($vendors as $v) {
    foreach ($totals as $k => $_) {
        $totals[$k] += decimal_to_paisa($v[$k]);
    }
}
$selectable = vendor_categories_for_select($pdo);
$catalog = vendor_category_service_catalog($pdo);

$pageTitle = 'Vendors';
$activeTab = 'vendors';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Vendors</h2>
  <span class="muted"><?= count($vendors) ?> vendor(s)</span>
  <a class="btn small" href="<?= h(url('admin/vendor_categories.php')) ?>">Manage categories</a>
</div>
<p class="muted">Service providers Booking Organizer hires for events — caterers, decorators, photographers and so on. Vendors
  don't sign in; everything here is managed by the admin. Assign a vendor's services to an event from the booking page, then
  issue the vendor invoice and record what has been paid.</p>

<div class="card pad">
  <div class="money-summary">
    <div><span>Total invoiced</span><strong><?= h(format_rs($totals['invoiced'])) ?></strong></div>
    <div><span>Paid to vendors</span><strong><?= h(format_rs($totals['paid'])) ?></strong></div>
    <div class="emph"><span>Outstanding</span><strong><?= h(format_rs($totals['outstanding'])) ?></strong></div>
  </div>
</div>

<form method="get" action="<?= h(url('admin/vendors.php')) ?>" class="list-toolbar">
  <select name="category" aria-label="Category">
      <option value="">All categories</option>
<?php foreach ($categories as $c): ?>
      <option value="<?= (int) $c['id'] ?>"<?= $filter === (int) $c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?> (<?= (int) $c['vendors'] ?>)</option>
<?php endforeach; ?>
  </select>
  <button type="submit" class="btn">Show</button>
</form>

<?php if (!$vendors): ?>
  <div class="card empty-note"><?= $filter ? 'No vendors in this category yet.' : 'No vendors yet. Add the first one below.' ?></div>
<?php else: ?>
<div class="card table-scroll">
<table class="registry">
  <thead><tr>
    <th>Vendor</th><th>Category</th><th>Contact</th><th class="num">Services</th>
    <th class="num">Invoiced</th><th class="num">Paid</th><th class="num">Outstanding</th><th>Status</th>
  </tr></thead>
  <tbody>
<?php foreach ($vendors as $v): ?>
    <tr>
      <td><a href="<?= h(url('admin/vendor.php?id=' . (int) $v['id'])) ?>"><strong><?= h($v['name']) ?></strong></a></td>
      <td><?= h($v['category_name']) ?></td>
      <td><?= h(vendor_contact_line($v)) ?></td>
      <td class="num"><?= (int) $v['services'] ?></td>
      <td class="num"><?= h(rs($v['invoiced'])) ?></td>
      <td class="num"><?= h(rs($v['paid'])) ?></td>
      <td class="num"><?= h(rs($v['outstanding'])) ?></td>
      <td><span class="badge <?= (int) $v['is_active'] ? 'active' : 'disabled' ?>"><?= (int) $v['is_active'] ? 'ACTIVE' : 'INACTIVE' ?></span></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<div class="card pad" id="add-vendor">
  <h3>ADD A VENDOR</h3>
<?php if ($createError): ?>
  <ul class="errors"><li><?= h($createError) ?></li></ul>
<?php endif; ?>
  <form method="post" action="<?= h(url('admin/vendors.php')) ?>#add-vendor" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="is_active" value="1">
    <div class="grid g3">
      <div class="field"><label for="n_name">Vendor name</label>
        <input type="text" id="n_name" name="name" maxlength="150" value="<?= h($new['name']) ?>" required></div>
      <div class="field"><label for="n_category">Category</label>
        <select id="n_category" name="category_id" required>
          <option value="">—</option>
<?php foreach ($selectable as $c): ?>
          <option value="<?= (int) $c['id'] ?>"<?= (string) $new['category_id'] === (string) $c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
<?php endforeach; ?>
        </select></div>
      <div class="field"><label for="n_person">Contact person</label>
        <input type="text" id="n_person" name="contact_person" maxlength="100" value="<?= h($new['contact_person']) ?>"></div>
      <div class="field"><label for="n_phone">Phone</label>
        <input type="text" id="n_phone" name="phone" maxlength="50" value="<?= h($new['phone']) ?>"></div>
      <div class="field"><label for="n_phone2">Second phone</label>
        <input type="text" id="n_phone2" name="phone2" maxlength="50" value="<?= h($new['phone2']) ?>"></div>
      <div class="field"><label for="n_email">Email</label>
        <input type="email" id="n_email" name="email" maxlength="150" value="<?= h($new['email']) ?>"></div>
      <div class="field span2"><label for="n_address">Address</label>
        <input type="text" id="n_address" name="address" maxlength="255" value="<?= h($new['address']) ?>"></div>
      <div class="field"><label for="n_notes">Notes</label>
        <input type="text" id="n_notes" name="notes" maxlength="2000" value="<?= h($new['notes']) ?>"></div>
    </div>
    <h4>Services</h4>
    <p class="muted">Tick the services this vendor provides. The list follows the category you choose.</p>
<?php vendor_service_picker($catalog, array_column($selectable, 'id'), ctype_digit((string) $new['category_id']) ? (int) $new['category_id'] : null,
    'n_category', [], $createPost); ?>
    <p><button class="btn primary">Add vendor</button></p>
  </form>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
