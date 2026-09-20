<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/admin_data.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

$admin = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = ctype_digit((string) ($_POST['item_id'] ?? '')) ? (int) $_POST['item_id'] : 0;
    try {
        if (($_POST['action'] ?? '') === 'create') {
            flash('ok', 'Added “' . create_catalog_item($pdo, $admin, clean_catalog_input($_POST, true)) . '” to the catalog.');
        } elseif (($_POST['action'] ?? '') === 'update') {
            update_catalog_item($pdo, $admin, $id, clean_catalog_input($_POST, false));
            flash('ok', 'Catalog item saved.');
        } else {
            flash('error', 'Unknown action.');
        }
    } catch (AdminRefused | TryAgainException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/catalog.php');
}

$sections = catalog_items($pdo);
$total = array_sum(array_map('count', $sections));

$pageTitle = 'Charge and item catalog';
$activeTab = 'catalog';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Charge &amp; item catalog</h2>
  <span class="muted"><?= $total ?> item(s)</span>
</div>
<p class="muted">These items fill the booking form. Bookings copy the label, unit and rate when a line is added, so renaming
  an item, changing its rate or unit, or retiring it never changes a booking that already exists — including old invoices.
  Items are retired (unticked “Offered”), never deleted.</p>

<?php foreach (LINE_SECTIONS as $section => $label): $items = $sections[$section] ?? []; ?>
<div class="card pad">
  <h3><?= h(strtoupper($label)) ?><?= $section === 'charge' ? ' — the only section that carries money' : '' ?></h3>
<?php if (!$items): ?>
  <p class="muted">Nothing in this section yet.</p>
<?php endif; ?>
<?php foreach ($items as $item): ?>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
    <div class="field grow"><label for="n<?= (int) $item['id'] ?>">Name</label>
      <input type="text" id="n<?= (int) $item['id'] ?>" name="name" value="<?= h($item['name']) ?>" maxlength="150" required>
      <span class="hint">on <?= (int) $item['used'] ?> booking line(s)</span></div>
    <div class="field narrow"><label for="u<?= (int) $item['id'] ?>">Charged</label>
      <select id="u<?= (int) $item['id'] ?>" name="unit">
<?php foreach (CATALOG_UNITS as $unit): ?>
        <option value="<?= h($unit) ?>"<?= $item['unit'] === $unit ? ' selected' : '' ?>><?= h($unit === 'per head' ? 'per guest' : $unit) ?></option>
<?php endforeach; ?>
      </select></div>
    <div class="field narrow"><label for="r<?= (int) $item['id'] ?>">Default rate</label>
      <input type="text" id="r<?= (int) $item['id'] ?>" name="default_rate" inputmode="decimal"
             value="<?= $item['default_rate'] === null ? '' : h($item['default_rate']) ?>"<?= $section === 'charge' ? '' : ' disabled' ?>></div>
    <div class="field narrow"><label for="s<?= (int) $item['id'] ?>">Order</label>
      <input type="text" id="s<?= (int) $item['id'] ?>" name="sort_order" inputmode="numeric" value="<?= (int) $item['sort_order'] ?>"></div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $item['is_active'] ? ' checked' : '' ?>> Offered</label>
    <button class="btn small" name="action" value="update">Save</button>
  </form>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="card pad">
  <h3>ADD AN ITEM</h3>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <div class="field"><label for="c_section">Section</label>
      <select id="c_section" name="section" required>
<?php foreach (LINE_SECTIONS as $section => $label): ?>
        <option value="<?= h($section) ?>"><?= h($label) ?></option>
<?php endforeach; ?>
      </select></div>
    <div class="field grow"><label for="c_name">Name</label><input type="text" id="c_name" name="name" maxlength="150" required></div>
    <div class="field narrow"><label for="c_unit">Charged</label>
      <select id="c_unit" name="unit">
<?php foreach (CATALOG_UNITS as $unit): ?>
        <option value="<?= h($unit) ?>"><?= h($unit === 'per head' ? 'per guest' : $unit) ?></option>
<?php endforeach; ?>
      </select></div>
    <div class="field narrow"><label for="c_rate">Default rate</label><input type="text" id="c_rate" name="default_rate" inputmode="decimal"></div>
    <div class="field narrow"><label for="c_sort">Order</label><input type="text" id="c_sort" name="sort_order" inputmode="numeric" value="0"></div>
    <label class="check"><input type="checkbox" name="is_active" value="1" checked> Offered</label>
    <button class="btn primary" name="action" value="create">Add item</button>
  </form>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
