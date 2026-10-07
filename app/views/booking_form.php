<?php
/**
 * The booking form (layout from the mockup's "Agreement for Catering / Decoration Services").
 * Expects: $booking (stored row or null), $ctx (values/errors/readonly), $formLines, $lineInput,
 *          $viewer, $users, $venues, $conflict, $clashMessages.
 *
 * The sheet is one form and one POST — always. The three steps below are presentation only: every
 * section stays in the DOM, so save.php receives exactly what it always did, and a browser without
 * JavaScript (or a printer) simply shows all of them at once.
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
/* Prices, payment and refund terms and the office record are Booking Organizer's to set. A user is
   shown them as plain text (never as greyed-out boxes), and the sections that are Booking Organizer's
   alone are left off a user's form. bookings.php discards all of them from a user's POST in any case. */
$adminCtx = ['readonly' => $ro || !$isAdmin] + $ctx;

/* The step rail. The sections themselves carry data-step; these are only the labels above them. */
$steps = [
    1 => ['name' => 'Client & Event', 'hint' => 'Client, date & venue'],
    2 => ['name' => 'Services',       'hint' => 'Catering, decor & items'],
    3 => ['name' => 'Price & Sign',   'hint' => 'Totals, terms & signatures'],
];

/* "Items to provide": every ticklist (charges, decor, operations items) as one list, one group per
   section. A group opens by itself when something in it is ticked or has a problem. */
$groupLabels = ['charge' => 'Charges', 'ops_item' => 'Setup items (operations sheet)'] + LINE_SECTIONS;
$groupOpen = static function (string $sec, array $lines) use ($lineChecked, $ctx, $booking): bool {
    foreach ($lines as $key => $_) {
        if ($lineChecked($key) || isset($ctx['errors']["line_$key"])) {
            return true;
        }
    }
    return $sec === 'charge' && $booking === null;
};
/* Menu (app/menus.php): the packages and extra dishes on offer, what is chosen ($menuInput), and the
   copy the booking already holds ($savedMenu), which is what prints. */
$menuInput ??= menu_input_from_booking($booking);
$menuPackages = menu_packages_for_form(db(), $menuInput['package']);
$menuDishes = menu_dishes_for_form(db(), array_keys($menuInput['extras']));
$menuDishesByCategory = [];
foreach ($menuDishes as $dish) {
    $menuDishesByCategory[$dish['category']][] = $dish;
}
$menuOn = $menuPackages || $menuDishes;
$savedMenu = menu_selection_by_category($booking['menu_selection'] ?? null);
/* Extra dishes the booking already has, with the rate copied onto it (dish id => entry). The admin can
   re-price them here; the rate a re-shown form posted wins, so a failed save keeps what was typed. */
$savedExtras = [];
foreach (menu_selection_entries($booking['menu_selection'] ?? null) as $e) {
    if (!empty($e['extra'])) {
        $savedExtras[(int) $e['dish_id']] = $e;
    }
}
$postedExtraRates = $_SERVER['REQUEST_METHOD'] === 'POST' && is_array($_POST['menu_extra_rate'] ?? null) ? $_POST['menu_extra_rate'] : [];
$menuMinShortfall = menu_min_guests_shortfall($menuPackages[$menuInput['package']] ?? null, (int) fv($ctx, 'guests'));
$menuUnit = menu_package_unit($menuPackages[$menuInput['package']] ?? null);

/* A stored percentage ("50.00") as the agreement says it ("50%"). */
$pct = static function (string $v): string {
    if ($v === '') {
        return '—';
    }
    return (str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v) . '%';
};

/* The summary strip is seeded here so it is right before any script runs; app.js then keeps it live. */
$eventStamp = fv($ctx, 'event_date') !== '' ? strtotime(fv($ctx, 'event_date')) : false;
$sumDate = $eventStamp ? date('d M Y', $eventStamp) : '—';
$sumVenue = '—';
if ($venueValue === 'other') {
    $sumVenue = fv($ctx, 'venue_other') !== '' ? fv($ctx, 'venue_other') : 'Other';
} elseif ($venueValue !== '') {
    foreach ($venues as $v) {
        if ((string) $v['id'] === $venueValue) {
            $sumVenue = (string) $v['name'];
            break;
        }
    }
}
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

<form method="post" action="<?= h(url('booking/save.php')) ?>" id="booking-form" class="card form-sheet" data-readonly="<?= $ro ? '1' : '0' ?>" data-paid="<?= $booking ? h(rs($booking['paid_total'])) : '—' ?>" data-balance="<?= $booking ? h(rs($booking['balance'])) : '—' ?>" data-per-head="<?= h(fv($ctx, 'per_head_rate')) ?>" data-discount="<?= h(fv($ctx, 'discount')) ?>" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $booking ? (int) $booking['id'] : '' ?>">
  <input type="hidden" name="version" value="<?= $booking ? (int) ($ctx['values']['version'] ?? $booking['version']) : '' ?>">

  <div class="sheet-head">
    <div class="sheet-title">
      <h2>Agreement for Catering / Decoration Services</h2>
      <p class="sheet-sub"><?= $booking ? 'Last saved ' . h(date('d M Y H:i', strtotime((string) ($booking['updated_at'] ?? $booking['created_at'])))) : 'Not saved yet' ?>
<?php if (!$isAdmin && fv($ctx, 'firm_name') !== ''): ?>
        · Booked by <?= h(fv($ctx, 'firm_name')) ?><?= fv($ctx, 'rep_name') !== '' ? ' (' . h(fv($ctx, 'rep_name')) . ')' : '' ?>
<?php endif; ?></p>
    </div>
    <div class="sheet-ids">
      <span class="badge status-<?= h($status) ?>"><?= h(strtoupper($status)) ?></span>
      <div class="rec-id">ID: <?= $booking ? h(format_document_number($booking['unique_id'], 'SLA', (int) $booking['revision'])) : 'assigned on first save' ?></div>
    </div>
  </div>

  <dl class="sheet-summary">
    <div><dt>Client</dt><dd id="sum-client"><?= fv($ctx, 'client_name') !== '' ? h(fv($ctx, 'client_name')) : '—' ?></dd></div>
    <div><dt>Event date</dt><dd id="sum-date"><?= h($sumDate) ?></dd></div>
    <div><dt>Venue</dt><dd id="sum-venue"><?= h($sumVenue) ?></dd></div>
    <div><dt>Guests</dt><dd id="sum-guests"><?= fv($ctx, 'guests') !== '' ? h(fv($ctx, 'guests')) : '—' ?></dd></div>
    <div class="sum-net"><dt>Net</dt><dd id="sum-net"><?= h(rs($booking['grand_total'] ?? '0')) ?></dd></div>
  </dl>

<?php if (!$ro && $status === 'confirmed'): ?>
  <section class="block amend-panel">
    <h3>Changing a confirmed agreement</h3>
    <p class="muted">Contact numbers, address, reference, decor-by, the Booking Organizer record, signatures and operations-sheet items
      are saved directly. <strong>Any other change is an amendment:</strong> it needs a reason, raises the revision
      (now Rev <?= (int) $booking['revision'] ?>), and clears the signatures so the amended agreement is signed again.</p>
    <div class="grid">
      <?= textarea_field($ctx, 'amend_reason', 'Amendment reason (required for an amendment)', '', ' maxlength="1000" rows="2"') ?>
      <?= textarea_field($ctx, 'override_reason', 'Venue override reason (only if moving to a venue/date already confirmed)', '', ' maxlength="1000" rows="2"') ?>
    </div>
    <?= field_error($ctx, 'venue_override') ?><?= field_error($ctx, 'signed_copy') ?><?= field_error($ctx, 'status') ?>
  </section>
<?php endif; ?>
<?php if ($ro): ?>
  <p class="readonly-note">
    <?= $status === 'draft' ? 'You can view this booking but not edit it.' : 'This booking is ' . h($status) . ' and can no longer be edited here.' ?>
  </p>
<?php endif; ?>

  <nav class="wizard-steps" aria-label="Sections of this agreement" hidden>
    <ol>
<?php foreach ($steps as $n => $step): ?>
      <li><button type="button" class="step" data-goto="<?= $n ?>" aria-controls="step-<?= $n ?>">
          <span class="step-num"><?= $n ?></span>
          <span class="step-body">
            <span class="step-name"><?= h($step['name']) ?></span>
            <span class="step-meta"><?= h($step['hint']) ?></span>
          </span>
        </button></li>
<?php endforeach; ?>
    </ol>
  </nav>

  <!-- Step 1 · Client & Event -->
  <div class="step-panel" id="step-1" data-step="1" tabindex="-1">
  <section class="block" id="b-client" data-step="1" data-nav="Client">
    <h3 class="block-head">Client (Event Owner)</h3>
    <div class="grid">
      <?= input_field($ctx, 'client_name', 'Name (Mr./Ms.) *', 'text', 'span2', ' maxlength="150" required') ?>
      <?= input_field($ctx, 'client_relation', 'S/o, W/o, D/o', 'text', 'span2', ' maxlength="150"') ?>
      <?= input_field($ctx, 'client_cnic', 'CNIC No.', 'text', '', ' placeholder="#####-#######-#" maxlength="15"') ?>
      <?= input_field($ctx, 'client_company', 'Company', 'text', '', ' maxlength="150"') ?>
      <?= input_field($ctx, 'client_contact', 'Contact No.', 'text', '', ' maxlength="50"') ?>
      <?= input_field($ctx, 'client_contact2', 'Contact No. 2', 'text', '', ' maxlength="50"') ?>
      <?= textarea_field($ctx, 'client_address', 'Residential Address', 'span2', ' maxlength="255"') ?>
      <?= input_field($ctx, 'reference_name', 'Referred by (optional)', 'text', '', ' maxlength="150"') ?>
      <?= input_field($ctx, 'reference_department', 'Reference department (optional)', 'text', '', ' maxlength="150"') ?>
    </div>
  </section>

  <section class="block" id="b-event" data-step="1" data-nav="Event details">
    <h3 class="block-head">Event Details</h3>
    <div class="grid g3">
      <?= select_field($ctx, 'event_type', 'Type of Event', EVENT_TYPES, 'event_type_other') ?>
      <?= input_field($ctx, 'event_date', 'Date of Event', 'date') ?>
      <div class="field"><label>Day</label><output id="event-day" class="readout"><?= fv($ctx, 'event_date') !== '' && strtotime(fv($ctx, 'event_date')) ? h(date('l', strtotime(fv($ctx, 'event_date')))) : '—' ?></output></div>
      <?= input_field($ctx, 'alt_date', 'Alternate Date', 'date') ?>
      <div class="field"><label for="f_venue_id">Venue at Booking Organizer</label>
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

<?php if ($isAdmin): ?>
  <section class="block" id="b-user" data-step="1" data-nav="User">
    <h3 class="block-head">User (Service Provider — Booking Organizer Empaneled)</h3>
    <div class="grid">
      <div class="field span2"><label for="f_user_id">User account</label>
        <select id="f_user_id" name="user_id"<?= ro($ctx) ?>>
          <option value="">— not assigned yet (required to confirm) —</option>
<?php $userListed = false; foreach ($users as $v): $sel = (string) $v['id'] === fv($ctx, 'user_id'); $userListed = $userListed || $sel; ?>
          <option value="<?= (int) $v['id'] ?>"<?= $sel ? ' selected' : '' ?>><?= h($v['firm_name'] . ' — ' . $v['rep_name'] . ' (' . $v['username'] . ')') ?></option>
<?php endforeach; ?>
<?php if (!$userListed && fv($ctx, 'user_id') !== ''): ?>
          <option value="<?= h(fv($ctx, 'user_id')) ?>" selected>Current user (account not active)</option>
<?php endif; ?>
        </select>
        <?= field_error($ctx, 'user_id') ?>
        <span class="hint">Only active users are listed. Blank firm/representative fields are filled from the user's profile.</span>
      </div>
      <?= input_field($ctx, 'firm_name', 'Name of Firm (M/s.)', 'text', 'span2', ' maxlength="150"') ?>
      <?= input_field($ctx, 'rep_name', 'Representative Name (Mr.)', 'text', '', ' maxlength="100"') ?>
      <?= input_field($ctx, 'rep_contact', 'Contact No.', 'text', '', ' maxlength="50"') ?>
    </div>
  </section>
<?php endif; ?>
  </div>

  <!-- Step 2 · Services -->
  <div class="step-panel" id="step-2" data-step="2" tabindex="-1">
  <section class="block" id="b-catering" data-step="2" data-nav="Catering">
    <h3 class="block-head">Catering Services — Food &amp; Beverages</h3>
    <div class="grid">
      <?= select_field($ctx, 'menu_type', 'Menu Type', options_with_current(menu_type_options(), fv($ctx, 'menu_type')), 'menu_type_other') ?>
<?php if ($menuOn && !$ro): ?>
      <div class="field"><label for="f_menu_package_id">Menu package</label>
        <input type="hidden" name="menu_present" value="1">
        <select id="f_menu_package_id" name="menu_package_id">
          <option value="">— No package (custom menu) —</option>
<?php foreach ($menuPackages as $pid => $p): ?>
          <option value="<?= $pid ?>"<?= $pid === $menuInput['package'] ? ' selected' : '' ?>
                  data-rate="<?= h((string) $p['per_head_rate']) ?>" data-min="<?= (int) $p['min_guests'] ?>" data-unit="<?= h(menu_package_unit($p)) ?>"><?= h($p['name'])
            . (menu_package_price_label($p) !== '' ? ' — ' . menu_package_price_label($p) : '')
            . ((int) $p['is_active'] ? '' : ' (no longer offered)') ?></option>
<?php endforeach; ?>
        </select>
        <?= field_error($ctx, 'menu_package_id') ?>
        <span class="hint">A priced package sets the per-head rate when it is chosen. For a box deal, enter the number of boxes as the guests.</span>
      </div>
<?php endif; ?>
    </div>
<?php if ($menuOn && !$ro): ?>
    <p class="flash info menu-min-warning" id="menu-min-warning"<?= $menuMinShortfall === null ? ' hidden' : '' ?>><?=
      $menuMinShortfall === null ? '' : h("This package is for at least $menuMinShortfall $menuUnit; the booking has " . (int) fv($ctx, 'guests') . '.') ?></p>
<?php foreach ($menuPackages as $pid => $p): ?>
    <div class="menu-package" data-package="<?= $pid ?>">
      <p class="menu-package-head"><strong><?= h($p['name']) ?></strong>
<?php if ($p['description']): ?> · <span class="muted"><?= h($p['description']) ?></span><?php endif; ?>
<?php if ($p['min_guests'] !== null): ?> · <span class="muted">minimum <?= (int) $p['min_guests'] ?> <?= h(menu_package_unit($p)) ?></span><?php endif; ?>
<?php if ($p['card_stored_name']): ?> · <a href="<?= h(url('menu/card.php?id=' . $pid)) ?>" target="_blank" rel="noopener">View menu card</a><?php endif; ?></p>
<?php if (!$p['items']): ?>
      <p class="hint">No dishes in this package yet.</p>
<?php endif; ?>
      <div class="menu-courses">
<?php foreach (menu_package_layout($p['items']) as $category => $rows): ?>
        <div class="menu-course"><h4><?= h($category) ?></h4><ul>
<?php foreach ($rows as $row): ?>
<?php if (isset($row['item'])): ?>
          <li><?= h(menu_dish_label($row['item']['dish'], (bool) (int) $row['item']['is_live'], (bool) (int) $row['item']['is_free'])) ?></li>
<?php else: ?>
<?php
    $pickedId = $menuInput['picks'][$row['choice']] ?? null;
    if ($pid !== $menuInput['package'] || !in_array($pickedId, array_map('intval', array_column($row['items'], 'id')), true)) {
        $pickedId = (int) $row['items'][0]['id']; // a fresh choice starts on the first dish
    }
?>
          <li class="menu-choice"><span class="hint">Choose one:</span>
<?php foreach ($row['items'] as $item): ?>
            <label class="check"><input type="radio" name="menu_pick[<?= $pid ?>][<?= (int) $row['choice'] ?>]" value="<?= (int) $item['id'] ?>"<?= (int) $item['id'] === $pickedId ? ' checked' : '' ?>>
              <?= h(menu_dish_label($item['dish'], (bool) (int) $item['is_live'], (bool) (int) $item['is_free'])) ?></label>
<?php endforeach; ?>
            <?= $pid === $menuInput['package'] ? field_error($ctx, 'menu_pick_' . $row['choice']) : '' ?></li>
<?php endif; ?>
<?php endforeach; ?>
        </ul></div>
<?php endforeach; ?>
      </div>
    </div>
<?php endforeach; ?>
<?php if ($menuDishes): ?>
<?php $extraCount = count($menuInput['extras']); ?>
    <details class="item-group menu-extras"<?= $extraCount ? ' open' : '' ?>>
      <summary><span class="item-group-name">Extra dishes (on top of the package, or a menu of your own)</span>
        <span class="menu-extra-count"><?= $extraCount ? $extraCount . ' ticked' : '' ?></span></summary>
      <div class="menu-courses">
<?php foreach ($menuDishesByCategory as $category => $dishes): ?>
        <div class="menu-course"><h4><?= h($category) ?></h4>
<?php foreach ($dishes as $dish): ?>
<?php
    // The rate this extra costs: the booking's own once it has it, else the dish's current extra rate.
    $extraRate = isset($savedExtras[(int) $dish['id']]) ? ($savedExtras[(int) $dish['id']]['rate'] ?? null) : $dish['extra_rate'];
    $extraUnit = isset($savedExtras[(int) $dish['id']]) ? ($savedExtras[(int) $dish['id']]['unit'] ?? 'per head') : $dish['extra_unit'];
?>
          <label class="check"><input type="checkbox" name="menu_extra[]" value="<?= (int) $dish['id'] ?>" data-rate="<?= h((string) $extraRate) ?>" data-unit="<?= h($extraUnit) ?>"<?= isset($menuInput['extras'][(int) $dish['id']]) ? ' checked' : '' ?>>
            <?= h(menu_dish_label($dish['name'], (bool) (int) $dish['is_live'], false)) ?><?php if (menu_extra_rate_label($extraRate, $extraUnit) !== ''): ?>
            <span class="hint">· <?= h(menu_extra_rate_label($extraRate, $extraUnit)) ?></span><?php endif; ?></label>
<?php endforeach; ?>
        </div>
<?php endforeach; ?>
      </div>
    </details>
<?php endif; ?>
<?php endif; ?>
<?php if ($savedMenu): ?>
    <div class="menu-saved">
      <h4 class="sub-head">Menu on this booking<?= ($booking['menu_package_name'] ?? null) ? ': ' . h($booking['menu_package_name']) : '' ?></h4>
      <dl class="menu-saved-list">
<?php foreach ($savedMenu as $category => $labels): ?>
        <div><dt><?= h($category) ?></dt><dd><?= h(implode(', ', $labels)) ?></dd></div>
<?php endforeach; ?>
      </dl>
<?php if ($savedExtras): ?>
      <table class="lines-table menu-extra-rates">
        <thead><tr><th>Extra dish</th><th class="num">Rate (Rs.)</th><th>Basis</th></tr></thead>
        <tbody>
<?php foreach ($savedExtras as $dishId => $e): ?>
          <tr><td><?= h((string) $e['dish']) ?><?= field_error($ctx, 'menu_extra_rate_' . $dishId) ?></td>
<?php if ($isAdmin && !$ro): ?>
            <td><input type="text" class="rate" inputmode="decimal" name="menu_extra_rate[<?= $dishId ?>]" aria-label="Rate for <?= h((string) $e['dish']) ?>"
                       value="<?= h(is_string($postedExtraRates[$dishId] ?? null) ? $postedExtraRates[$dishId] : (string) ($e['rate'] ?? '')) ?>"></td>
<?php else: ?>
            <td class="num"><?= ($e['rate'] ?? null) !== null ? h(rs($e['rate'])) : '<span class="hint">to be priced</span>' ?></td>
<?php endif; ?>
            <td class="hint"><?= ($e['unit'] ?? 'per head') === 'fixed' ? 'fixed' : 'per guest' ?></td></tr>
<?php endforeach; ?>
        </tbody>
      </table>
<?php endif; ?>
<?php if (!$ro): ?>
      <p class="hint">This is what prints on the agreement. Changing the package or the dishes above replaces it when you save.
        Extra dishes are added to Items &amp; charges<?= $isAdmin ? '; a newly ticked one takes the dish\'s rate, which you can change here after saving' : ' at Booking Organizer\'s rates' ?>.</p>
<?php endif; ?>
    </div>
<?php endif; ?>
    <div class="grid">
      <?= textarea_field($ctx, 'food_items', $menuOn || $savedMenu ? 'Menu notes / other food items' : 'Detailed Food Items (starter, main, desserts, beverages)', 'span2', ' rows="3" placeholder="One item per line"') ?>
    </div>
  </section>

  <section class="block" id="b-decor" data-step="2" data-nav="Decoration">
    <h3 class="block-head">Decoration &amp; Setup Standards</h3>
    <div class="grid">
      <?= input_field($ctx, 'theme', 'Theme / Color Scheme', 'text', 'span2', ' maxlength="150"') ?>
<?php if (form_section_shown('stage')): ?>
      <?= select_field($ctx, 'stage', 'Stage Decoration', options_with_current(form_option_names('stage'), fv($ctx, 'stage')), 'stage_other') ?>
      <?= textarea_field($ctx, 'stage_desc', 'Detailed Description') ?>
<?php endif; ?>
<?php if (form_section_shown('entrance')): ?>
      <?= select_field($ctx, 'entrance', 'Entrance Decoration', options_with_current(form_option_names('entrance'), fv($ctx, 'entrance')), 'entrance_other') ?>
<?php endif; ?>
<?php if (form_section_shown('lighting')): ?>
      <?= select_field($ctx, 'lighting', 'Lighting', options_with_current(form_option_names('lighting'), fv($ctx, 'lighting')), 'lighting_other') ?>
<?php endif; ?>
<?php if (form_section_shown('floor')): ?>
      <?= select_field($ctx, 'floor_covering', 'Floor Covering', options_with_current(form_option_names('floor'), fv($ctx, 'floor_covering')), 'floor_other') ?>
<?php endif; ?>
      <?= textarea_field($ctx, 'addl_decor', 'Additional Decor (centerpieces, aisle, etc.)') ?>
      <?= input_field($ctx, 'decor_by', 'Decor By', 'text', '', ' maxlength="150"') ?>
    </div>
  </section>

<?php if ($sections): ?>
  <section class="block" id="b-items" data-step="2" data-nav="Items to provide">
    <h3 class="block-head">Items to Provide</h3>
<?php if (!$isAdmin && !$ro): ?>
    <p class="hint">Tick what this event needs. Rates are set by Booking Organizer; an item without a rate yet is priced when the booking is reviewed.</p>
<?php endif; ?>
<?php foreach ($groupLabels as $sec => $groupLabel): if (empty($sections[$sec])) { continue; } ?>
<?php $ticked = count(array_filter(array_keys($sections[$sec]), $lineChecked)); ?>
    <details class="item-group"<?= $groupOpen($sec, $sections[$sec]) ? ' open' : '' ?>>
      <summary><span class="item-group-name"><?= h($groupLabel) ?></span>
        <span class="item-group-count"><?= $ticked ? $ticked . ' ticked' : '' ?></span></summary>
      <div class="table-scroll">
      <table class="lines-table">
        <thead><tr><th></th><th>Item</th><th>Basis</th><th class="num">Rate (Rs.)</th><th>Qty</th><th class="num">Amount</th><th>Notes</th></tr></thead>
        <tbody>
<?php foreach ($sections[$sec] as $key => $line): ?>
<?php $hasQty = $line['unit'] === 'per unit' || $sec === 'ops_item'; ?>
          <tr class="charge-line" data-unit="<?= h($line['unit']) ?>"<?= $isAdmin ? '' : ' data-rate="' . h($lineVal($key, 'rate')) . '"' ?>>
            <td><input type="hidden" name="lines[<?= h($key) ?>][present]" value="1">
              <input type="checkbox" name="lines[<?= h($key) ?>][selected]" value="1" aria-label="Include <?= h($line['label']) ?>"<?= $lineChecked($key) ? ' checked' : '' ?><?= ro($ctx) ?>></td>
            <td><?= h($line['label']) ?><?= field_error($ctx, "line_$key") ?></td>
            <td class="hint"><?= h($line['unit'] === 'per head' ? 'per guest' : $line['unit']) ?></td>
<?php if ($isAdmin): ?>
            <td><input type="text" class="rate" inputmode="decimal" name="lines[<?= h($key) ?>][rate]" value="<?= h($lineVal($key, 'rate')) ?>" aria-label="Rate for <?= h($line['label']) ?>"<?= ro($ctx) ?>></td>
<?php else: ?>
            <td class="num"><?= $lineVal($key, 'rate') !== '' ? h(rs($lineVal($key, 'rate'))) : '<span class="hint">—</span>' ?></td>
<?php endif; ?>
            <td><?php if ($hasQty): ?><input type="text" class="qty" inputmode="numeric" name="lines[<?= h($key) ?>][qty]" value="<?= h($lineVal($key, 'qty')) ?>" aria-label="Quantity for <?= h($line['label']) ?>"<?= ro($ctx) ?>><?php else: ?><span class="hint"><?= $line['unit'] === 'per head' ? '× guests' : '—' ?></span><?php endif; ?></td>
            <td class="num line-amount"><?= h(rs($line['amount'] ?? '0')) ?></td>
            <td><input type="text" class="note" name="lines[<?= h($key) ?>][notes]" value="<?= h($lineVal($key, 'notes')) ?>" maxlength="255" aria-label="Notes for <?= h($line['label']) ?>"<?= ro($ctx) ?>></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </details>
<?php endforeach; ?>
  </section>
<?php endif; ?>
  </div>

  <!-- Step 3 · Price & Sign -->
  <div class="step-panel" id="step-3" data-step="3" tabindex="-1">
  <section class="block" id="b-price" data-step="3" data-nav="Price & terms">
    <h3 class="block-head">Price &amp; Terms</h3>
    <div class="grid g3">
<?php if ($isAdmin): ?>
      <?= money_field($adminCtx, 'per_head_rate', 'Per-head catering rate (Rs.)') ?>
<?php else: ?>
      <div class="field"><label>Per-head catering rate</label><output class="readout money" id="per-head-readout"><?= fv($ctx, 'per_head_rate') !== '' && (float) fv($ctx, 'per_head_rate') > 0 ? h(rs(fv($ctx, 'per_head_rate'))) : 'set by Booking Organizer' ?></output></div>
<?php endif; ?>
      <div class="field"><label>Guests</label><output id="guests-readout" class="readout"><?= h(fv($ctx, 'guests') ?: '0') ?></output></div>
      <div class="field"><label>Guest charges</label><output id="t-guest" class="readout money"><?= h(rs($booking['guest_charges'] ?? '0')) ?></output></div>
    </div>
    <table class="totals-table">
      <tr><td>Guest charges</td><td class="num" id="t-guest2"><?= h(rs($booking['guest_charges'] ?? '0')) ?></td></tr>
      <tr><td>Items &amp; charges</td><td class="num" id="t-charges"><?= h(rs($booking['charges_total'] ?? '0')) ?></td></tr>
      <tr><td>Sub total</td><td class="num" id="t-sub"><?= h(rs($booking['sub_total'] ?? '0')) ?></td></tr>
<?php if ($isAdmin): ?>
      <tr><td><label for="f_discount">Discount (Rs.)</label></td>
        <td class="num"><input type="text" inputmode="decimal" id="f_discount" name="discount" value="<?= h(fv($ctx, 'discount')) ?>"<?= ro($adminCtx) ?>><?= field_error($ctx, 'discount') ?></td></tr>
<?php elseif (fv($ctx, 'discount') !== '' && (float) fv($ctx, 'discount') > 0): ?>
      <tr><td>Discount</td><td class="num"><?= h(rs(fv($ctx, 'discount'))) ?></td></tr>
<?php endif; ?>
      <tr class="grand"><td>Net amount</td><td class="num" id="t-grand"><?= h(rs($booking['grand_total'] ?? '0')) ?></td></tr>
<?php if ($booking): ?>
      <tr><td>Paid to date</td><td class="num"><?= h(rs($booking['paid_total'])) ?></td></tr>
      <tr><td>Balance</td><td class="num"><?= h(rs($booking['balance'])) ?></td></tr>
<?php endif; ?>
    </table>
    <?= field_error($ctx, 'totals') ?>
    <p class="hint">Amounts on this page update as you type; the server recalculates everything when you save.</p>

    <h4 class="sub-head">Payment &amp; cancellation terms</h4>
<?php if ($isAdmin): ?>
    <div class="grid g3">
      <?= select_field($adminCtx, 'due_on', 'Balance Due On', DUE_ON_TYPES, null, false) ?>
      <?= input_field($adminCtx, 'refund_pct_30', 'Refund if cancelled >30 days before (%)', 'text', '', ' inputmode="decimal"') ?>
      <?= input_field($adminCtx, 'refund_pct_7', 'Refund if cancelled 7–30 days before (%)', 'text', '', ' inputmode="decimal"') ?>
    </div>
    <p class="hint">Cancelled less than 7 days before: the advance is non-refundable. Payments and refunds are recorded separately, one entry each.</p>
<?php else: ?>
    <ul class="terms-list">
      <li>Balance due on: <strong><?= h(fv($ctx, 'due_on') ?: 'Event Day') ?></strong></li>
      <li>Refund if cancelled: more than 30 days before <strong><?= h($pct(fv($ctx, 'refund_pct_30'))) ?></strong>;
        7–30 days before <strong><?= h($pct(fv($ctx, 'refund_pct_7'))) ?></strong>; less than 7 days before, the advance is non-refundable.</li>
    </ul>
<?php endif; ?>
    <p class="policy-note">If User cancels, User refunds 200% of advance received. Force Majeure (war, strikes, government bans,
      floods/rains, death in family — reported within 12 hours) permits event re-scheduling or full refund.</p>
  </section>

  <section class="block" id="b-signatures" data-step="3" data-nav="Sign-off">
    <h3 class="block-head">Commitments &amp; Signatures</h3>
    <div class="grid">
      <?= textarea_field($ctx, 'special_commitments', 'Special commitments by the User (optional)', 'span2', ' placeholder="e.g. Dedicated event coordinator on site, specific type of flower"') ?>
    </div>
    <div class="grid g3">
      <?= input_field($ctx, 'agreement_day', 'Agreement signed on (day)', 'text', '', ' placeholder="e.g. 14th" maxlength="20"') ?>
      <?= input_field($ctx, 'agreement_month', 'Month, Year', 'text', '', ' placeholder="e.g. September, 2026" maxlength="40"') ?>
      <?= input_field($ctx, 'agreement_place', 'Place', 'text', '', ' maxlength="80"') ?>
    </div>
    <div class="grid">
      <?= input_field($ctx, 'user_sign_name', 'User — Name', 'text', '', ' maxlength="150"') ?>
      <?= input_field($ctx, 'user_sign_date', 'User — Date', 'date') ?>
      <?= input_field($ctx, 'client_sign_name', 'Client — Name', 'text', '', ' maxlength="150"') ?>
      <?= input_field($ctx, 'client_sign_date', 'Client — Date', 'date') ?>
    </div>
  </section>

<?php if ($isAdmin): ?>
  <section class="block" id="b-records" data-step="3" data-nav="Office records" data-collapsible="1">
    <h3 class="block-head">For Booking Organizer Records</h3>
    <div class="grid g3">
      <?= input_field($adminCtx, 'received_by', 'Received By', 'text', '', ' maxlength="100"') ?>
      <?= input_field($adminCtx, 'received_date', 'Date', 'date') ?>
      <?= input_field($adminCtx, 'received_time', 'Time', 'time') ?>
    </div>
  </section>
<?php endif; ?>
  </div>

  <div class="actionbar">
    <button type="button" class="btn ghost" data-wizard="prev" hidden>&larr; Back</button>
    <span class="step-position" id="step-position"></span>
    <a class="btn ghost" href="<?= h(url('booking/list.php')) ?>"><?= $ro ? 'Back' : 'Cancel' ?></a>
<?php if (!$ro): ?>
    <button type="submit" class="btn primary"><?= $booking ? 'Save Changes' : 'Save as New Booking' ?></button>
<?php endif; ?>
    <button type="button" class="btn primary" data-wizard="next" hidden>Continue &rarr;</button>
  </div>
</form>
