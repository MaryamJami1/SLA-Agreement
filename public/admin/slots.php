<?php
/**
 * Event Slot Management: the time slots each venue offers on the booking form.
 *
 * Everything the booking form shows about slots — names, times, how many, their order — comes from
 * here. Bookings copy a slot's name and times when it is chosen, so editing a slot only affects new
 * bookings. A slot any booking has used can't be deleted, only disabled.
 */
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/admin_data.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

$admin = require_admin();
$pdo = db();

$venues = $pdo->query('SELECT v.id, v.name, v.is_active,
                              (SELECT COUNT(*) FROM venue_slots s WHERE s.venue_id = v.id AND s.is_active = 1) AS active_slots
                         FROM venues v ORDER BY v.is_active DESC, v.sort_order, v.name')->fetchAll();
$venueIds = array_map(static fn($v) => (int) $v['id'], $venues);
$venueId = ctype_digit((string) ($_REQUEST['venue'] ?? '')) ? (int) $_REQUEST['venue'] : ($venueIds[0] ?? 0);
if ($venues && !in_array($venueId, $venueIds, true)) {
    not_found();
}
$back = 'admin/slots.php?venue=' . $venueId;

// The add/edit form, re-shown with the admin's input when a save is refused.
$formMode = isset($_GET['add']) ? 'add' : (ctype_digit((string) ($_GET['edit'] ?? '')) ? 'edit' : null);
$formSlotId = $formMode === 'edit' ? (int) $_GET['edit'] : null;
$formValues = null;
$formError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slotId = ctype_digit((string) ($_POST['slot_id'] ?? '')) ? (int) $_POST['slot_id'] : 0;
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'create':
                flash('ok', 'Added the slot “' . create_venue_slot($pdo, $admin, $venueId, clean_slot_input($_POST)) . '”. It is offered on new bookings now.');
                redirect($back);
                break;
            case 'update':
                update_venue_slot($pdo, $admin, $slotId, clean_slot_input($_POST), isset($_POST['is_active']));
                flash('ok', 'Slot saved. New bookings use the new details; existing bookings keep the times they were booked with.');
                redirect($back);
                break;
            case 'enable':
            case 'disable':
                $name = set_venue_slot_active($pdo, $admin, $slotId, $action === 'enable');
                flash('ok', $action === 'enable' ? "“{$name}” is offered on new bookings again."
                    : "“{$name}” is disabled: it is no longer offered on new bookings. Existing bookings keep it.");
                break;
            case 'delete':
                flash('ok', 'Deleted the slot “' . delete_venue_slot($pdo, $admin, $slotId) . '”.');
                break;
            case 'copy':
                $from = ctype_digit((string) ($_POST['from_venue'] ?? '')) ? (int) $_POST['from_venue'] : 0;
                $n = copy_venue_slots($pdo, $admin, $from, $venueId);
                flash('ok', "Copied $n slot(s). Adjust them below if this venue keeps different hours.");
                break;
            default:
                flash('error', 'Unknown action.');
        }
    } catch (AdminRefused | TryAgainException $e) {
        if ($action === 'create' || $action === 'update') {
            // Keep what was typed and show the problem next to it.
            $formMode = $action === 'create' ? 'add' : 'edit';
            $formSlotId = $action === 'update' ? $slotId : null;
            $formValues = [
                'name' => (string) ($_POST['name'] ?? ''), 'icon' => (string) ($_POST['icon'] ?? ''),
                'start_time' => (string) ($_POST['start_time'] ?? ''), 'end_time' => (string) ($_POST['end_time'] ?? ''),
                'sort_order' => (string) ($_POST['sort_order'] ?? ''), 'ends_next_day' => isset($_POST['ends_next_day']),
                'is_active' => isset($_POST['is_active']),
            ];
            $formError = $e->getMessage();
        } else {
            flash('error', $e->getMessage());
            redirect($back);
        }
    }
    if ($formError === null) {
        redirect($back);
    }
}

$venue = null;
foreach ($venues as $v) {
    if ((int) $v['id'] === $venueId) {
        $venue = $v;
    }
}
$slots = $venue ? venue_slots_with_usage($pdo, $venueId) : [];
$copySources = array_filter($venues, static fn($v) => (int) $v['id'] !== $venueId && (int) $v['active_slots'] > 0);

$editing = null;
if ($formMode === 'edit') {
    foreach ($slots as $s) {
        if ((int) $s['id'] === $formSlotId) {
            $editing = $s;
        }
    }
    if ($editing === null) {
        $formMode = null;
    }
}
if ($formMode !== null && $formValues === null) {
    $formValues = $editing ? [
        'name' => $editing['name'], 'icon' => (string) $editing['icon'],
        'start_time' => substr($editing['start_time'], 0, 5), 'end_time' => substr($editing['end_time'], 0, 5),
        'sort_order' => (string) $editing['sort_order'],
        'ends_next_day' => slot_crosses_midnight($editing['start_time'], $editing['end_time']) && $editing['end_time'] !== '00:00:00',
        'is_active' => (bool) (int) $editing['is_active'],
    ] : [
        'name' => '', 'icon' => '', 'start_time' => '', 'end_time' => '', 'ends_next_day' => false, 'is_active' => true,
        'sort_order' => (string) (($slots ? max(array_map(static fn($s) => (int) $s['sort_order'], $slots)) : 0) + 10),
    ];
}

$pageTitle = 'Event slots';
$activeTab = 'slots';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Event Slot Management</h2>
<?php if ($venue): ?>
  <a class="btn primary" href="<?= h(url($back . '&add=1#slot-form')) ?>">+ Add New Slot</a>
<?php endif; ?>
</div>
<p class="muted">Each venue offers its own event slots. The booking form lists the active slots of the chosen venue and marks
  the ones already booked on the chosen date; a venue, date and slot can only be booked once. Changing a slot here affects
  new bookings only — every booking keeps the name and times it was made with.</p>

<?php if (!$venues): ?>
<div class="card empty-note">There are no venues yet. <a href="<?= h(url('admin/venues.php')) ?>">Add a venue</a> first.</div>
<?php else: ?>
<nav class="venue-tabs" aria-label="Venues">
<?php foreach ($venues as $v): ?>
  <a href="<?= h(url('admin/slots.php?venue=' . (int) $v['id'])) ?>"<?= (int) $v['id'] === $venueId ? ' class="current" aria-current="page"' : '' ?>>
    <?= h($v['name']) ?><span class="venue-tab-count"><?= (int) $v['active_slots'] ?></span><?= (int) $v['is_active'] ? '' : ' <span class="hint">(inactive)</span>' ?></a>
<?php endforeach; ?>
</nav>

<?php if ($formMode !== null): ?>
<div class="card pad slot-form-card" id="slot-form">
  <h3><?= $formMode === 'add' ? 'ADD A NEW SLOT — ' : 'EDIT SLOT — ' ?><?= h(mb_strtoupper($venue['name'])) ?></h3>
<?php if ($formError !== null): ?>
  <div class="flash error" role="alert"><?= h($formError) ?></div>
<?php endif; ?>
<?php if ($editing && (int) $editing['bookings'] > 0): ?>
  <p class="muted">Used by <?= (int) $editing['bookings'] ?> booking(s). They keep the name and times they were booked with; only new bookings
    see your changes.</p>
<?php endif; ?>
  <form method="post" action="<?= h(url($back)) ?>" class="slot-form">
    <?= csrf_field() ?>
    <input type="hidden" name="venue" value="<?= (int) $venueId ?>">
<?php if ($editing): ?>    <input type="hidden" name="slot_id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="grid g3">
      <div class="field"><label for="s_name">Slot name</label>
        <input type="text" id="s_name" name="name" value="<?= h($formValues['name']) ?>" maxlength="<?= SLOT_NAME_MAX ?>" required placeholder="e.g. Evening"></div>
      <div class="field"><label for="s_start">Start time</label>
        <input type="time" id="s_start" name="start_time" value="<?= h($formValues['start_time']) ?>" required></div>
      <div class="field"><label for="s_end">End time</label>
        <input type="time" id="s_end" name="end_time" value="<?= h($formValues['end_time']) ?>" required>
        <label class="check"><input type="checkbox" name="ends_next_day" value="1"<?= $formValues['ends_next_day'] ? ' checked' : '' ?>> Ends after midnight (next day)</label>
        <span class="hint">Not needed for a slot ending exactly at 12:00 AM.</span></div>
      <div class="field"><label for="s_icon">Icon <span class="hint">(optional)</span></label>
        <input type="text" id="s_icon" name="icon" value="<?= h($formValues['icon']) ?>" maxlength="<?= SLOT_ICON_MAX ?>" placeholder="e.g. 🌙"></div>
      <div class="field"><label for="s_sort">Display order</label>
        <input type="text" id="s_sort" name="sort_order" inputmode="numeric" value="<?= h($formValues['sort_order']) ?>"></div>
<?php if ($editing): ?>
      <div class="field"><label>Status</label>
        <label class="check"><input type="checkbox" name="is_active" value="1"<?= $formValues['is_active'] ? ' checked' : '' ?>> Active — offered on new bookings</label></div>
<?php endif; ?>
    </div>
    <div class="slot-form-actions">
      <button class="btn primary" name="action" value="<?= $editing ? 'update' : 'create' ?>"><?= $editing ? 'Save slot' : 'Add slot' ?></button>
      <a class="btn ghost" href="<?= h(url($back)) ?>">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card pad">
  <h3>SLOTS AT <?= h(mb_strtoupper($venue['name'])) ?></h3>
<?php if (!$slots): ?>
  <p class="muted">No slots yet, so this venue can't be booked. Add its first slot<?= $copySources ? ', or copy the slots of another venue and adjust them' : '' ?>.</p>
<?php if ($copySources): ?>
  <form method="post" action="<?= h(url($back)) ?>" class="admin-row">
    <?= csrf_field() ?>
    <input type="hidden" name="venue" value="<?= (int) $venueId ?>">
    <div class="field grow"><label for="copy_from">Copy slots from</label>
      <select id="copy_from" name="from_venue">
<?php foreach ($copySources as $v): ?>
        <option value="<?= (int) $v['id'] ?>"><?= h($v['name']) ?> (<?= (int) $v['active_slots'] ?> slots)</option>
<?php endforeach; ?>
      </select></div>
    <button class="btn small" name="action" value="copy">Copy slots</button>
  </form>
<?php endif; ?>
<?php else: ?>
  <div class="table-scroll">
  <table class="registry slot-table">
    <thead><tr>
      <th>Slot name</th><th>Start time</th><th>End time</th><th>Length</th><th>Status</th><th class="num">Order</th><th class="num">Bookings</th><th></th>
    </tr></thead>
    <tbody>
<?php foreach ($slots as $s): $used = (int) $s['bookings'] > 0; $active = (int) $s['is_active'] === 1; ?>
      <tr<?= $active ? '' : ' class="is-inactive"' ?>>
        <td><span class="slot-cell"><?php if ($s['icon'] !== null): ?><span aria-hidden="true"><?= h($s['icon']) ?></span> <?php endif; ?><strong><?= h($s['name']) ?></strong></span></td>
        <td><?= h(slot_time_label($s['start_time'])) ?></td>
        <td><?= h(slot_time_label($s['end_time'])) ?><?php if (slot_crosses_midnight($s['start_time'], $s['end_time']) && $s['end_time'] !== '00:00:00'): ?> <span class="hint">next day</span><?php endif; ?></td>
        <td><?= h(slot_duration_label($s['start_time'], $s['end_time'])) ?></td>
        <td><span class="badge <?= $active ? 'active' : 'disabled' ?>"><?= $active ? 'ACTIVE' : 'DISABLED' ?></span></td>
        <td class="num"><?= (int) $s['sort_order'] ?></td>
        <td class="num"><?= (int) $s['bookings'] ?></td>
        <td class="actions">
          <a class="rowbtn" href="<?= h(url($back . '&edit=' . (int) $s['id'] . '#slot-form')) ?>">Edit</a>
          <form method="post" action="<?= h(url($back)) ?>"<?= $active ? ' data-confirm="Disable “' . h($s['name']) . '”? It will no longer be offered on new bookings. Existing bookings keep it."' : '' ?>>
            <?= csrf_field() ?><input type="hidden" name="slot_id" value="<?= (int) $s['id'] ?>">
            <button class="rowbtn" name="action" value="<?= $active ? 'disable' : 'enable' ?>"><?= $active ? 'Disable' : 'Enable' ?></button></form>
<?php if (!$used): ?>
          <form method="post" action="<?= h(url($back)) ?>" data-confirm="Delete “<?= h($s['name']) ?>”? No booking has used it.">
            <?= csrf_field() ?><input type="hidden" name="slot_id" value="<?= (int) $s['id'] ?>">
            <button class="rowbtn del" name="action" value="delete">Delete</button></form>
<?php else: ?>
          <span class="rowbtn is-locked" title="Used by bookings — disable it instead">Delete</span>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="hint slot-footnote">Slots at one venue can't overlap. A slot used by any booking can't be deleted — disable it instead, and
    those bookings keep it.</p>
<?php endif; ?>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
