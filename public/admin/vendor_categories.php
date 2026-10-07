<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/admin_data.php';
require_once APP_ROOT . '/app/payments.php';
require_once APP_ROOT . '/app/vendors.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

$admin = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = ctype_digit((string) ($_POST['category_id'] ?? '')) ? (int) $_POST['category_id'] : 0;
    try {
        switch ($_POST['action'] ?? '') {
            case 'create':
                flash('ok', 'Added category “' . create_vendor_category($pdo, $admin, $_POST['name'] ?? '', $_POST['sort_order'] ?? '0') . '”.');
                break;
            case 'update':
                update_vendor_category($pdo, $admin, $id, $_POST['name'] ?? '', isset($_POST['is_active']), $_POST['sort_order'] ?? '0');
                flash('ok', 'Category saved.');
                break;
            case 'delete':
                flash('ok', 'Deleted category “' . delete_vendor_category($pdo, $admin, $id) . '”.');
                break;
            default:
                flash('error', 'Unknown action.');
        }
    } catch (AdminRefused | TryAgainException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/vendor_categories.php');
}

$categories = vendor_categories_with_usage($pdo);

$pageTitle = 'Vendor categories';
$activeTab = 'vendors';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Vendor categories</h2>
  <span class="muted"><?= count($categories) ?> categor<?= count($categories) === 1 ? 'y' : 'ies' ?></span>
  <a class="btn small" href="<?= h(url('admin/vendors.php')) ?>">Back to vendors</a>
</div>
<p class="muted">Every vendor is filed under one category. A category that has vendors can't be deleted — deactivate it instead,
  and it is no longer offered for new vendors. Vendors already in it keep it.</p>

<div class="card pad">
  <h3>CATEGORIES</h3>
<?php foreach ($categories as $c): $used = (int) $c['vendors'] > 0; ?>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
    <div class="field grow"><label for="cn<?= (int) $c['id'] ?>">Name</label>
      <input type="text" id="cn<?= (int) $c['id'] ?>" name="name" value="<?= h($c['name']) ?>" maxlength="100">
      <span class="hint"><?= (int) $c['vendors'] ?> vendor(s)</span></div>
    <div class="field narrow"><label for="cs<?= (int) $c['id'] ?>">Order</label>
      <input type="text" id="cs<?= (int) $c['id'] ?>" name="sort_order" inputmode="numeric" value="<?= (int) $c['sort_order'] ?>"></div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $c['is_active'] ? ' checked' : '' ?>> Active</label>
    <button class="btn small" name="action" value="update">Save</button>
  </form>
<?php if (!$used): ?>
  <form method="post" class="admin-row-extra" data-confirm="Delete the category “<?= h($c['name']) ?>”? No vendor uses it.">
    <?= csrf_field() ?>
    <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
    <button class="btn small danger" name="action" value="delete">Delete “<?= h($c['name']) ?>”</button>
  </form>
<?php endif; ?>
<?php endforeach; ?>
</div>

<div class="card pad">
  <h3>ADD A CATEGORY</h3>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <div class="field grow"><label for="c_name">Name</label><input type="text" id="c_name" name="name" maxlength="100" required></div>
    <div class="field narrow"><label for="c_sort">Order</label><input type="text" id="c_sort" name="sort_order" inputmode="numeric" value="0"></div>
    <button class="btn primary" name="action" value="create">Add category</button>
  </form>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
