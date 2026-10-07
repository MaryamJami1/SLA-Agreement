<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/admin_data.php';
require_once APP_ROOT . '/app/attachments.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

$admin = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = ctype_digit((string) ($_POST['item_id'] ?? '')) ? (int) $_POST['item_id'] : 0;
    $action = (string) ($_POST['action'] ?? '');
    // Come back to the part of the page the form was on.
    $anchor = str_starts_with($action, 'category') ? 'categories' : (str_starts_with($action, 'dish') ? 'dishes' : 'packages');
    try {
        switch ($action) {
            case 'category_create':
                flash('ok', 'Added the category “' . create_menu_category($pdo, $admin, $_POST) . '”.');
                break;
            case 'category_update':
                update_menu_category($pdo, $admin, $id, $_POST);
                flash('ok', 'Category saved.');
                break;
            case 'dish_create':
                flash('ok', 'Added “' . create_menu_dish($pdo, $admin, $_POST) . '” to the dishes.');
                break;
            case 'dish_update':
                update_menu_dish($pdo, $admin, $id, $_POST);
                flash('ok', 'Dish saved.');
                break;
            case 'package_create':
                $id = create_menu_package($pdo, $admin, $_POST);
                flash('ok', 'Package created. Now add its dishes below.');
                $anchor = 'package-' . $id;
                break;
            case 'package_update':
                update_menu_package($pdo, $admin, $id, $_POST);
                flash('ok', 'Package saved.');
                $anchor = 'package-' . $id;
                break;
            case 'item_add':
                add_menu_package_item($pdo, $admin, $id, $_POST);
                flash('ok', 'Dish added to the package.');
                $anchor = 'package-' . $id;
                break;
            case 'item_update':
            case 'item_remove':
                $packageId = ctype_digit((string) ($_POST['package_id'] ?? '')) ? (int) $_POST['package_id'] : 0;
                if ($action === 'item_remove') {
                    remove_menu_package_item($pdo, $admin, $id);
                    flash('ok', 'Dish taken out of the package.');
                } else {
                    update_menu_package_item($pdo, $admin, $id, $_POST);
                    flash('ok', 'Package dish saved.');
                }
                $anchor = 'package-' . $packageId;
                break;
            case 'card_upload':
                set_menu_package_card($pdo, $admin, $id, accept_uploaded_file($_FILES['card'] ?? []));
                flash('ok', 'Menu card uploaded.');
                $anchor = 'package-' . $id;
                break;
            case 'card_remove':
                set_menu_package_card($pdo, $admin, $id, null);
                flash('ok', 'Menu card removed.');
                $anchor = 'package-' . $id;
                break;
            default:
                flash('error', 'Unknown action.');
        }
    } catch (AdminRefused | AttachmentRefused | TryAgainException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/menus.php#' . $anchor);
}

$categories = menu_categories($pdo);
$dishes = menu_dishes($pdo);
$packages = menu_packages($pdo);
$packageItems = menu_package_items($pdo, array_column($packages, 'id'));

$activeDishesByCategory = [];
$dishesByCategory = [];
foreach ($dishes as $d) {
    $dishesByCategory[$d['category']][] = $d;
    if ((int) $d['is_active']) {
        $activeDishesByCategory[$d['category']][] = $d;
    }
}

/* A package's price: per guest, per box, or one price for a group. For a group only the price and
   its guest count are entered; the per-guest rate shown under them is worked out on save. */
$priceFields = static function (?array $p, string $suffix): string {
    $basis = $p['price_basis'] ?? 'guest';
    $value = static fn(string $field): string => h((string) ($p[$field] ?? ''));
    $html = '<div class="field"><label for="pb' . $suffix . '">Priced</label><select id="pb' . $suffix . '" name="price_basis">';
    foreach (MENU_PRICE_BASES as $key => [$label]) {
        $html .= '<option value="' . $key . '"' . ($key === $basis ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $html .= '</select></div>'
        . '<div class="field narrow"><label for="pr' . $suffix . '">Rate (guest / box)</label>'
        . '<input type="text" id="pr' . $suffix . '" name="per_head_rate" inputmode="decimal" value="' . ($basis === 'group' ? '' : $value('per_head_rate')) . '"></div>'
        . '<div class="field narrow"><label for="pg' . $suffix . 'p">Group price</label>'
        . '<input type="text" id="pg' . $suffix . 'p" name="group_price" inputmode="decimal" value="' . $value('group_price') . '"></div>'
        . '<div class="field narrow"><label for="pg' . $suffix . 's">for guests</label>'
        . '<input type="text" id="pg' . $suffix . 's" name="group_size" inputmode="numeric" value="' . $value('group_size') . '"></div>'
        . '<div class="field narrow"><label for="pm' . $suffix . '">Min. guests / boxes</label>'
        . '<input type="text" id="pm' . $suffix . '" name="min_guests" inputmode="numeric" value="' . $value('min_guests') . '">';
    if ($basis === 'group' && $p['per_head_rate'] !== null) {
        $html .= '<span class="hint">= ' . h(rs($p['per_head_rate'])) . ' per guest</span>';
    }
    return $html . '</div>';
};

/* What a dish costs when a booking adds it on top of its package. */
$extraRateFields = static function (?array $d, string $suffix): string {
    $unit = $d['extra_unit'] ?? 'per head';
    return '<div class="field narrow"><label for="dr' . $suffix . '">Extra rate</label>'
        . '<input type="text" id="dr' . $suffix . '" name="extra_rate" inputmode="decimal" placeholder="not priced" value="'
        . h((string) ($d['extra_rate'] ?? '')) . '"></div>'
        . '<div class="field narrow"><label for="du' . $suffix . '">Charged</label><select id="du' . $suffix . '" name="extra_unit">'
        . '<option value="per head"' . ($unit === 'per head' ? ' selected' : '') . '>per guest</option>'
        . '<option value="fixed"' . ($unit === 'fixed' ? ' selected' : '') . '>fixed</option></select></div>';
};

$pageTitle = 'Menus';
$activeTab = 'menus';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Menus</h2>
  <span class="muted"><?= count($packages) ?> package(s) · <?= count($dishes) ?> dish(es)</span>
</div>
<p class="muted">Build your menus once here and pick them on the booking form. Put each dish in the dish list under a category,
  then build packages from those dishes. Dishes in a package that share a <strong>choice group</strong> number are alternatives:
  the booking picks one (for example Beef Biryani = 1 and Beef Pulao = 1 reads “Beef Biryani or Beef Pulao”). A booking keeps
  its own copy of the menu it chose, so editing or retiring anything here never changes a booking that already exists.
  A dish's <strong>extra rate</strong> is what a booking pays when it adds the dish on top of its package (per guest, or a
  fixed amount); a dish without one is added unpriced until you price it on the booking.</p>
<p class="menu-jump"><a href="#packages">Packages</a> · <a href="#dishes">Dishes</a> · <a href="#categories">Categories</a></p>

<h3 id="packages" class="menu-section-title">PACKAGES</h3>
<?php if (!$packages): ?>
<p class="muted">No packages yet. Add your first one below.</p>
<?php endif; ?>
<?php foreach ($packages as $p): $pid = (int) $p['id']; $items = $packageItems[$pid] ?? []; ?>
<div class="card pad catalog-group<?= (int) $p['is_active'] ? '' : ' is-retired' ?>" id="package-<?= $pid ?>">
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <input type="hidden" name="item_id" value="<?= $pid ?>">
    <div class="field grow"><label for="pn<?= $pid ?>">Package name</label>
      <input type="text" id="pn<?= $pid ?>" name="name" value="<?= h($p['name']) ?>" maxlength="100" required>
      <span class="hint">on <?= (int) $p['bookings'] ?> booking(s)</span></div>
    <div class="field grow"><label for="pd<?= $pid ?>">Description</label>
      <input type="text" id="pd<?= $pid ?>" name="description" value="<?= h((string) $p['description']) ?>" maxlength="255"></div>
<?= $priceFields($p, (string) $pid) ?>
    <div class="field narrow"><label for="ps<?= $pid ?>">Order</label>
      <input type="text" id="ps<?= $pid ?>" name="sort_order" inputmode="numeric" value="<?= (int) $p['sort_order'] ?>"></div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $p['is_active'] ? ' checked' : '' ?>> Offered</label>
    <button class="btn small" name="action" value="package_update">Save</button>
  </form>

  <div class="menu-admin-body">
    <div class="menu-admin-items">
<?php if (!$items): ?>
      <p class="muted">No dishes in this package yet.</p>
<?php else: ?>
      <div class="table-scroll">
      <table class="lines-table">
        <thead><tr><th>Dish</th><th>Category</th><th>Choice group</th><th>Free</th><th>Order</th><th></th></tr></thead>
        <tbody>
<?php foreach ($items as $it): $iid = (int) $it['id']; $f = 'pi' . $iid; ?>
          <tr<?= (int) $it['dish_active'] ? '' : ' class="is-retired"' ?>>
            <td><?= h(menu_dish_label($it['dish'], (bool) (int) $it['is_live'], false)) ?><?= (int) $it['dish_active'] ? '' : ' <span class="hint">(dish retired)</span>' ?></td>
            <td class="hint"><?= h($it['category']) ?></td>
            <td><input form="<?= $f ?>" type="text" class="qty" name="choice_group" inputmode="numeric" value="<?= h((string) $it['choice_group']) ?>" aria-label="Choice group for <?= h($it['dish']) ?>"></td>
            <td><input form="<?= $f ?>" type="checkbox" name="is_free" value="1"<?= (int) $it['is_free'] ? ' checked' : '' ?> aria-label="<?= h($it['dish']) ?> is free"></td>
            <td><input form="<?= $f ?>" type="text" class="qty" name="sort_order" inputmode="numeric" value="<?= (int) $it['sort_order'] ?>" aria-label="Order of <?= h($it['dish']) ?>"></td>
            <td class="nowrap"><form method="post" id="<?= $f ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="item_id" value="<?= $iid ?>">
                <input type="hidden" name="package_id" value="<?= $pid ?>">
                <button class="btn small" name="action" value="item_update">Save</button>
                <button class="btn small ghost" name="action" value="item_remove">Remove</button>
              </form></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
      </div>
<?php endif; ?>
<?php if ($activeDishesByCategory): ?>
      <form method="post" class="admin-row">
        <?= csrf_field() ?>
        <input type="hidden" name="item_id" value="<?= $pid ?>">
        <div class="field grow"><label for="pa<?= $pid ?>">Add a dish</label>
          <select id="pa<?= $pid ?>" name="dish_id" required>
            <option value="">—</option>
<?php foreach ($activeDishesByCategory as $category => $list): ?>
            <optgroup label="<?= h($category) ?>">
<?php foreach ($list as $d): ?>
              <option value="<?= (int) $d['id'] ?>"><?= h(menu_dish_label($d['name'], (bool) (int) $d['is_live'], false)) ?></option>
<?php endforeach; ?>
            </optgroup>
<?php endforeach; ?>
          </select></div>
        <div class="field narrow"><label for="pg<?= $pid ?>">Choice group</label>
          <input type="text" id="pg<?= $pid ?>" name="choice_group" inputmode="numeric" placeholder="blank = included"></div>
        <div class="field narrow"><label for="po<?= $pid ?>">Order</label>
          <input type="text" id="po<?= $pid ?>" name="sort_order" inputmode="numeric" value="<?= (count($items) + 1) * 10 ?>"></div>
        <label class="check"><input type="checkbox" name="is_free" value="1"> Free</label>
        <button class="btn primary" name="action" value="item_add">Add dish</button>
      </form>
<?php else: ?>
      <p class="muted">Add dishes to the dish list first (below), then put them into this package.</p>
<?php endif; ?>
    </div>

    <div class="menu-admin-side">
      <h4 class="sub-head">How it reads</h4>
<?php if ($items): ?>
      <dl class="menu-saved-list">
<?php foreach (menu_package_layout($items) as $category => $rows): ?>
        <div><dt><?= h($category) ?></dt><dd><?= h(implode(', ', array_map(static fn($row) => isset($row['item'])
            ? menu_dish_label($row['item']['dish'], (bool) (int) $row['item']['is_live'], (bool) (int) $row['item']['is_free'])
            : implode(' or ', array_map(static fn($i) => menu_dish_label($i['dish'], (bool) (int) $i['is_live'], (bool) (int) $i['is_free']), $row['items'])), $rows))) ?></dd></div>
<?php endforeach; ?>
      </dl>
<?php else: ?>
      <p class="muted">—</p>
<?php endif; ?>
      <h4 class="sub-head">Menu card</h4>
<?php if ($p['card_stored_name']): ?>
      <a href="<?= h(url('menu/card.php?id=' . $pid)) ?>" target="_blank" rel="noopener"><img class="menu-card-thumb" src="<?= h(url('menu/card.php?id=' . $pid)) ?>" alt="Menu card for <?= h($p['name']) ?>"></a>
      <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="item_id" value="<?= $pid ?>">
        <button class="btn small ghost" name="action" value="card_remove">Remove card</button>
      </form>
<?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="menu-card-upload">
        <?= csrf_field() ?>
        <input type="hidden" name="item_id" value="<?= $pid ?>">
        <input type="file" name="card" accept="image/jpeg,image/png" required aria-label="Menu card image for <?= h($p['name']) ?>">
        <button class="btn small" name="action" value="card_upload"><?= $p['card_stored_name'] ? 'Replace card' : 'Upload card' ?></button>
        <span class="hint">JPG or PNG, up to 5 MB. Shown beside the package on the booking form.</span>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<div class="card pad">
  <h3>ADD A PACKAGE</h3>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <div class="field grow"><label for="pn_new">Package name</label><input type="text" id="pn_new" name="name" maxlength="100" required placeholder="e.g. Special Wedding Menu No. 8"></div>
    <div class="field grow"><label for="pd_new">Description</label><input type="text" id="pd_new" name="description" maxlength="255"></div>
<?= $priceFields(null, '_new') ?>
    <div class="field narrow"><label for="ps_new">Order</label><input type="text" id="ps_new" name="sort_order" inputmode="numeric" value="<?= (count($packages) + 1) * 10 ?>"></div>
    <button class="btn primary" name="action" value="package_create">Add package</button>
  </form>
</div>

<h3 id="dishes" class="menu-section-title">DISHES</h3>
<div class="card pad catalog-group">
  <div class="page-head">
    <h3>DISH LIST <span class="muted">(<?= count($dishes) ?>)</span></h3>
<?php if (count($dishes) > 8): ?>
    <input type="search" class="catalog-filter" placeholder="Filter by name…" aria-label="Filter dishes">
<?php endif; ?>
  </div>
<?php if (!$dishes): ?>
  <p class="muted">No dishes yet.</p>
<?php endif; ?>
<?php foreach ($dishesByCategory as $category => $list): ?>
<?php foreach ($list as $d): $did = (int) $d['id']; ?>
  <form method="post" class="admin-row catalog-row<?= (int) $d['is_active'] ? '' : ' is-retired' ?>" data-name="<?= h(mb_strtolower($d['name'] . ' ' . $category)) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="item_id" value="<?= $did ?>">
    <div class="field grow"><label for="dn<?= $did ?>">Dish <span class="catalog-chip"><?= h($category) ?></span></label>
      <input type="text" id="dn<?= $did ?>" name="name" value="<?= h($d['name']) ?>" maxlength="150" required>
      <span class="hint">in <?= (int) $d['packages'] ?> package(s)</span></div>
    <div class="field"><label for="dc<?= $did ?>">Category</label>
      <select id="dc<?= $did ?>" name="category_id">
<?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $d['category_id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
<?php endforeach; ?>
      </select></div>
<?= $extraRateFields($d, (string) $did) ?>
    <div class="field narrow"><label for="ds<?= $did ?>">Order</label>
      <input type="text" id="ds<?= $did ?>" name="sort_order" inputmode="numeric" value="<?= (int) $d['sort_order'] ?>"></div>
    <label class="check"><input type="checkbox" name="is_live" value="1"<?= (int) $d['is_live'] ? ' checked' : '' ?>> Live counter</label>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $d['is_active'] ? ' checked' : '' ?>> Offered</label>
    <button class="btn small" name="action" value="dish_update">Save</button>
  </form>
<?php endforeach; ?>
<?php endforeach; ?>
<?php if ($categories): ?>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <div class="field grow"><label for="dn_new">Add a dish</label><input type="text" id="dn_new" name="name" maxlength="150" required></div>
    <div class="field"><label for="dc_new">Category</label>
      <select id="dc_new" name="category_id" required>
<?php foreach ($categories as $c): if (!(int) $c['is_active']) { continue; } ?>
        <option value="<?= (int) $c['id'] ?>"><?= h($c['name']) ?></option>
<?php endforeach; ?>
      </select></div>
<?= $extraRateFields(null, '_new') ?>
    <div class="field narrow"><label for="ds_new">Order</label><input type="text" id="ds_new" name="sort_order" inputmode="numeric" value="0"></div>
    <label class="check"><input type="checkbox" name="is_live" value="1"> Live counter</label>
    <button class="btn primary" name="action" value="dish_create">Add dish</button>
  </form>
<?php else: ?>
  <p class="muted">Add a category first (below).</p>
<?php endif; ?>
</div>

<h3 id="categories" class="menu-section-title">CATEGORIES</h3>
<div class="card pad catalog-group">
  <p class="muted">The headings a menu is printed under (Starter, Main Course, Dessert, …), in this order.</p>
<?php foreach ($categories as $c): $cid = (int) $c['id']; ?>
  <form method="post" class="admin-row catalog-row<?= (int) $c['is_active'] ? '' : ' is-retired' ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="item_id" value="<?= $cid ?>">
    <div class="field grow"><label for="cn<?= $cid ?>">Category</label>
      <input type="text" id="cn<?= $cid ?>" name="name" value="<?= h($c['name']) ?>" maxlength="100" required>
      <span class="hint"><?= (int) $c['dishes'] ?> dish(es)</span></div>
    <div class="field narrow"><label for="cs<?= $cid ?>">Order</label>
      <input type="text" id="cs<?= $cid ?>" name="sort_order" inputmode="numeric" value="<?= (int) $c['sort_order'] ?>"></div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $c['is_active'] ? ' checked' : '' ?>> Offered</label>
    <button class="btn small" name="action" value="category_update">Save</button>
  </form>
<?php endforeach; ?>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <div class="field grow"><label for="cn_new">Add a category</label><input type="text" id="cn_new" name="name" maxlength="100" required></div>
    <div class="field narrow"><label for="cs_new">Order</label><input type="text" id="cs_new" name="sort_order" inputmode="numeric" value="<?= (count($categories) + 1) * 10 ?>"></div>
    <button class="btn primary" name="action" value="category_create">Add category</button>
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
