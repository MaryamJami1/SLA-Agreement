<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/admin_data.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

$admin = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = ctype_digit((string) ($_POST['venue_id'] ?? '')) ? (int) $_POST['venue_id'] : 0;
    try {
        switch ($_POST['action'] ?? '') {
            case 'create':
                flash('ok', 'Added venue “' . create_venue($pdo, $admin, $_POST['name'] ?? '', $_POST['location'] ?? '',
                    $_POST['sort_order'] ?? '0') . '”.');
                break;
            case 'update':
                update_venue($pdo, $admin, $id, $_POST['name'] ?? '', $_POST['location'] ?? '',
                    isset($_POST['is_active']), $_POST['sort_order'] ?? '0');
                flash('ok', 'Venue saved.');
                break;
            case 'delete':
                flash('ok', 'Deleted venue “' . delete_venue($pdo, $admin, $id) . '”.');
                break;
            default:
                flash('error', 'Unknown action.');
        }
    } catch (AdminRefused | TryAgainException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/venues.php');
}

$venues = venues_with_usage($pdo);

$pageTitle = 'Venues';
$activeTab = 'venues';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Venues</h2>
  <span class="muted"><?= count($venues) ?> venue(s)</span>
</div>
<p class="muted">A venue used by any booking can't be renamed or deleted — bookings point at it, so its meaning must stay the
  same. Deactivate it instead and add the new name as a separate venue. Inactive venues aren't offered on new bookings.</p>
<p class="muted">The <strong>location</strong> is where the venue sits inside AO Mess (for example “Ground floor, behind the
  Hall”). It fills in automatically on the booking form and prints on the agreement, invoice and vendor sheet. Unlike the
  name, it can be changed at any time: each booking stores its own copy, so past paperwork never changes.</p>

<div class="card pad">
  <h3>VENUES</h3>
<?php foreach ($venues as $v): $used = (int) $v['bookings'] > 0; ?>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <input type="hidden" name="venue_id" value="<?= (int) $v['id'] ?>">
    <div class="field grow"><label for="vn<?= (int) $v['id'] ?>">Name</label>
      <input type="text" id="vn<?= (int) $v['id'] ?>" name="name" value="<?= h($v['name']) ?>" maxlength="100"<?= $used ? ' readonly' : '' ?>>
<?php if ($used): ?>      <span class="hint">used by <?= (int) $v['bookings'] ?> booking(s) — renaming is not allowed</span><?php endif; ?>
    </div>
    <div class="field grow"><label for="vl<?= (int) $v['id'] ?>">Location</label>
      <input type="text" id="vl<?= (int) $v['id'] ?>" name="location" value="<?= h($v['location'] ?? '') ?>" maxlength="150"
             placeholder="e.g. Ground floor, Block B"></div>
    <div class="field narrow"><label for="vs<?= (int) $v['id'] ?>">Order</label>
      <input type="text" id="vs<?= (int) $v['id'] ?>" name="sort_order" inputmode="numeric" value="<?= (int) $v['sort_order'] ?>"></div>
    <label class="check"><input type="checkbox" name="is_active" value="1"<?= (int) $v['is_active'] ? ' checked' : '' ?>> Active</label>
    <button class="btn small" name="action" value="update">Save</button>
  </form>
<?php if (!$used): ?>
  <form method="post" class="admin-row-extra" data-confirm="Delete “<?= h($v['name']) ?>”? It has never been used by a booking.">
    <?= csrf_field() ?>
    <input type="hidden" name="venue_id" value="<?= (int) $v['id'] ?>">
    <button class="btn small danger" name="action" value="delete">Delete “<?= h($v['name']) ?>”</button>
  </form>
<?php endif; ?>
<?php endforeach; ?>
</div>

<div class="card pad">
  <h3>ADD A VENUE</h3>
  <form method="post" class="admin-row">
    <?= csrf_field() ?>
    <div class="field grow"><label for="v_name">Name</label><input type="text" id="v_name" name="name" maxlength="100" required></div>
    <div class="field grow"><label for="v_location">Location</label>
      <input type="text" id="v_location" name="location" maxlength="150" placeholder="e.g. Ground floor, Block B"></div>
    <div class="field narrow"><label for="v_sort">Order</label><input type="text" id="v_sort" name="sort_order" inputmode="numeric" value="0"></div>
    <button class="btn primary" name="action" value="create">Add venue</button>
  </form>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
