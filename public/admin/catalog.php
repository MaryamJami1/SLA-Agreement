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
        } elseif (($_POST['action'] ?? '') === 'menu_create') {
            flash('ok', 'Added menu type “' . create_menu_type($pdo, $admin, $_POST['name'] ?? '', $_POST['sort_order'] ?? '0') . '”.');
        } elseif (($_POST['action'] ?? '') === 'menu_update') {
            update_menu_type($pdo, $admin, $id, $_POST['name'] ?? '', isset($_POST['is_active']), $_POST['sort_order'] ?? '0');
            flash('ok', 'Menu type saved.');
        } elseif (($_POST['action'] ?? '') === 'section_visibility') {
            $shown = isset($_POST['is_shown']);
            $label = set_form_section_shown($pdo, $admin, (string) ($_POST['list'] ?? ''), $shown);
            flash('ok', "“{$label}” is now " . ($shown ? 'shown on' : 'hidden from') . ' the booking form.');
        } elseif (($_POST['action'] ?? '') === 'booking_defaults') {
            set_booking_defaults($pdo, $admin, $_POST);
            flash('ok', 'Standard refund policy saved. New bookings start with it.');
        } elseif (($_POST['action'] ?? '') === 'option_create') {
            flash('ok', 'Added “' . create_form_option($pdo, $admin, (string) ($_POST['list'] ?? ''), $_POST['name'] ?? '', $_POST['sort_order'] ?? '0') . '”.');
        } elseif (($_POST['action'] ?? '') === 'option_update') {
            update_form_option($pdo, $admin, $id, $_POST['name'] ?? '', isset($_POST['is_active']), $_POST['sort_order'] ?? '0');
            flash('ok', 'Option saved.');
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
$menuTypes = menu_types_with_usage($pdo);
$formOptions = form_options_with_usage($pdo);
$sectionShown = form_section_visibility();
$defaults = booking_defaults($pdo);

$pageTitle = 'Charge and item catalog';
$activeTab = 'catalog';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Charge &amp; item catalog</h2>
  <span class="muted"><?= $total ?> item(s)</span>
</div>
<p class="muted">These items fill the booking form. Bookings copy the label, unit and rate when a line is added, so renaming
  an item, changing its rate or unit, or retiring it never changes a booking that already exists including old invoices.
  Items are retired (unticked “Offered”), never deleted.</p>

<div class="card pad catalog-group" id="refund-policy">
  <div class="page-head">
    <h3>STANDARD REFUND POLICY</h3>
  </div>
  <p class="muted">Every new booking starts with these percentages — including one a user creates — so the user and the
    client see the refund terms on the booking and the agreement straight away. You can still change them on any single
    booking under Price &amp; Terms. Changing them here never changes a booking that already exists. Cancelled less than
    7 days before the event: the advance is non-refundable.</p>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
<?php foreach (BOOKING_DEFAULT_FIELDS as $field => $label): ?>
    <div class="field"><label for="d_<?= h($field) ?>"><?= h($label) ?></label>
      <input type="text" id="d_<?= h($field) ?>" name="<?= h($field) ?>" inputmode="decimal" placeholder="e.g. 50"
             value="<?= $defaults[$field] === null ? '' : h(rtrim(rtrim($defaults[$field], '0'), '.')) ?>"></div>
<?php endforeach; ?>
    <button class="btn primary" name="action" value="booking_defaults">Save policy</button>
  </form>
</div>

<?php
// Two groups only: Charges, and everything else (decor checklist and operations items).
$groups = [
    'Charges' => ['charge' => LINE_SECTIONS['charge']],
    'Decor & operations items' => array_diff_key(LINE_SECTIONS, ['charge' => true]),
];
foreach ($groups as $groupTitle => $groupSections):
    $rows = [];
    foreach ($groupSections as $section => $label) {
        foreach ($sections[$section] ?? [] as $item) {
            $rows[] = [$label, $item];
        }
    }
?>
<div class="card pad catalog-group">
  <div class="page-head">
    <h3><?= h(strtoupper($groupTitle)) ?> <span class="muted">(<?= count($rows) ?>)</span></h3>
<?php if (count($rows) > 8): ?>
    <input type="search" class="catalog-filter" placeholder="Filter by name…" aria-label="Filter <?= h($groupTitle) ?>">
<?php endif; ?>
  </div>
<?php if (!$rows): ?>
  <p class="muted">Nothing here yet.</p>
<?php endif; ?>
<?php foreach ($rows as [$label, $item]): ?>
  <form method="post" class="admin-row catalog-row<?= (int) $item['is_active'] ? '' : ' is-retired' ?>" data-name="<?= h(strtolower($item['name'])) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
    <div class="field grow"><label for="n<?= (int) $item['id'] ?>">Name <span class="catalog-chip"><?= h($label) ?></span></label>
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
             value="<?= $item['default_rate'] === null ? '' : h($item['default_rate']) ?>"></div>
    <div class="field narrow"><label for="s<?= (int) $item['id'] ?>">Order</label>
      <input type="text" id="s<?= (int) $item['id'] ?>" name="sort_order" inputmode="numeric" value="<?= (int) $item['sort_order'] ?>"></div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $item['is_active'] ? ' checked' : '' ?>> Offered</label>
    <button class="btn small" name="action" value="update">Save</button>
  </form>
<?php endforeach; ?>
</div>
<?php endforeach; ?>

<div class="card pad catalog-group">
  <div class="page-head">
    <h3>MENU TYPES <span class="muted">(<?= count($menuTypes) ?>)</span></h3>
  </div>
  <p class="muted">The Menu Type dropdown under Catering on the booking form. “Other” is always offered last. Renaming or
    retiring a menu type never changes a booking that already uses it. Dishes and menu packages are on the
    <a href="<?= h(url('admin/menus.php')) ?>">Menus</a> page.</p>
<?php if (!$menuTypes): ?>
  <p class="muted">Nothing here yet.</p>
<?php endif; ?>
<?php foreach ($menuTypes as $m): ?>
  <form method="post" class="admin-row catalog-row<?= (int) $m['is_active'] ? '' : ' is-retired' ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="item_id" value="<?= (int) $m['id'] ?>">
    <div class="field grow"><label for="mn<?= (int) $m['id'] ?>">Name</label>
      <input type="text" id="mn<?= (int) $m['id'] ?>" name="name" value="<?= h($m['name']) ?>" maxlength="100" required>
      <span class="hint">on <?= (int) $m['bookings'] ?> booking(s)</span></div>
    <div class="field narrow"><label for="ms<?= (int) $m['id'] ?>">Order</label>
      <input type="text" id="ms<?= (int) $m['id'] ?>" name="sort_order" inputmode="numeric" value="<?= (int) $m['sort_order'] ?>"></div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $m['is_active'] ? ' checked' : '' ?>> Offered</label>
    <button class="btn small" name="action" value="menu_update">Save</button>
  </form>
<?php endforeach; ?>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <div class="field grow"><label for="mn_new">Add a menu type</label><input type="text" id="mn_new" name="name" maxlength="100" required></div>
    <div class="field narrow"><label for="ms_new">Order</label><input type="text" id="ms_new" name="sort_order" inputmode="numeric" value="0"></div>
    <button class="btn primary" name="action" value="menu_create">Add menu type</button>
  </form>
</div>

<?php foreach (FORM_OPTION_LISTS as $list => [$column, $listLabel]): ?>
<div class="card pad catalog-group">
  <div class="page-head">
    <h3><?= h(strtoupper($listLabel)) ?> <span class="muted">(<?= count($formOptions[$list]) ?>)</span></h3>
    <form method="post" class="admin-row">
      <?= csrf_field() ?>
      <input type="hidden" name="list" value="<?= h($list) ?>">
      <label class="check"><input type="checkbox" name="is_shown" value="1"<?= $sectionShown[$list] ? ' checked' : '' ?>> Show on booking form</label>
      <button class="btn small" name="action" value="section_visibility">Save</button>
    </form>
  </div>
<?php if (!$sectionShown[$list]): ?>
  <p class="muted"><strong>Hidden:</strong> this dropdown is not on the booking form. Bookings that already have a value keep it.</p>
<?php endif; ?>
  <p class="muted">The “<?= h($listLabel) ?>” dropdown under Decoration &amp; Setup Standards on the booking form. “Other” is always
    offered last. Renaming or retiring an option never changes a booking that already uses it.</p>
<?php if (!$formOptions[$list]): ?>
  <p class="muted">Nothing here yet.</p>
<?php endif; ?>
<?php foreach ($formOptions[$list] as $o): ?>
  <form method="post" class="admin-row catalog-row<?= (int) $o['is_active'] ? '' : ' is-retired' ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="item_id" value="<?= (int) $o['id'] ?>">
    <div class="field grow"><label for="on<?= (int) $o['id'] ?>">Name</label>
      <input type="text" id="on<?= (int) $o['id'] ?>" name="name" value="<?= h($o['name']) ?>" maxlength="100" required>
      <span class="hint">on <?= (int) $o['bookings'] ?> booking(s)</span></div>
    <div class="field narrow"><label for="os<?= (int) $o['id'] ?>">Order</label>
      <input type="text" id="os<?= (int) $o['id'] ?>" name="sort_order" inputmode="numeric" value="<?= (int) $o['sort_order'] ?>"></div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $o['is_active'] ? ' checked' : '' ?>> Offered</label>
    <button class="btn small" name="action" value="option_update">Save</button>
  </form>
<?php endforeach; ?>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <input type="hidden" name="list" value="<?= h($list) ?>">
    <div class="field grow"><label for="on_new_<?= h($list) ?>">Add an option</label><input type="text" id="on_new_<?= h($list) ?>" name="name" maxlength="100" required></div>
    <div class="field narrow"><label for="os_new_<?= h($list) ?>">Order</label><input type="text" id="os_new_<?= h($list) ?>" name="sort_order" inputmode="numeric" value="0"></div>
    <button class="btn primary" name="action" value="option_create">Add option</button>
  </form>
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
<script>
document.querySelectorAll('.catalog-filter').forEach(function (box) {
  box.addEventListener('input', function () {
    var q = box.value.trim().toLowerCase();
    box.closest('.catalog-group').querySelectorAll('.catalog-row').forEach(function (row) {
      row.hidden = q !== '' && row.dataset.name.indexOf(q) === -1;
    });
  });
});
</script>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
