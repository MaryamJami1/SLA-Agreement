<?php
/**
 * Booking calendar: one month at a time, so a date is checked before it is promised.
 *
 * The grid is deliberately not scoped to the signed-in vendor — a vendor who can't see that Lawn A
 * is taken will promise it anyway. Other vendors' bookings appear as the venue and its status only;
 * client names and SLA numbers stay with the booking's owner and the admin.
 */
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/counters.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

$user = require_login();
$pdo = db();

// ---------------------------------------------------------------------------
// Which month
// ---------------------------------------------------------------------------
$today = new DateTimeImmutable('today');
$monthRaw = is_string($_GET['month'] ?? null) ? $_GET['month'] : '';
$cursor = null;
if (preg_match('/^(\d{4})-(\d{2})$/', $monthRaw, $m)) {
    $year = (int) $m[1];
    $mon = (int) $m[2];
    if ($year >= 2000 && $year <= 2100 && $mon >= 1 && $mon <= 12) {
        $cursor = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $mon));
    }
}
$cursor = $cursor ?? $today->modify('first day of this month');

$monthStart = $cursor->format('Y-m-01');
$daysInMonth = (int) $cursor->format('t');
$monthEnd = $cursor->format('Y-m-t');
$prevMonth = $cursor->modify('-1 month')->format('Y-m');
$nextMonth = $cursor->modify('+1 month')->format('Y-m');

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$entries = calendar_bookings($pdo, $monthStart, $monthEnd);
$byDay = [];
foreach ($entries as $entry) {
    $byDay[$entry['event_date']][] = $entry;
}
$availability = venue_availability($pdo, $entries, $daysInMonth);
$busiest = 0;
foreach ($availability as $row) {
    $busiest = max($busiest, $row['days']);
}

/** Leading blanks so the 1st lands under the right weekday (weeks start on Sunday). */
$leadingBlanks = (int) $cursor->format('w');
$cells = $leadingBlanks + $daysInMonth;
$trailingBlanks = (7 - ($cells % 7)) % 7;

$pageTitle = 'Booking Calendar';
$activeTab = 'calendar';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Booking Calendar</h2>
  <span class="muted"><?= count($entries) ?> booking(s) this month</span>
</div>
<p class="muted">Every venue booking for the month, whoever raised it. Check a date here before promising it — a venue can
  only be confirmed once per day. Cancelled bookings are not shown; they release the date.</p>

<div class="card pad cal-card">
  <div class="cal-toolbar">
    <a class="btn small" href="<?= h(url('booking/calendar.php?month=' . $prevMonth)) ?>" rel="prev">&lsaquo; <?= h((new DateTimeImmutable($prevMonth . '-01'))->format('M Y')) ?></a>
    <h3 class="cal-month"><?= h($cursor->format('F Y')) ?></h3>
    <a class="btn small" href="<?= h(url('booking/calendar.php?month=' . $nextMonth)) ?>" rel="next"><?= h((new DateTimeImmutable($nextMonth . '-01'))->format('M Y')) ?> &rsaquo;</a>
  </div>
<?php if ($cursor->format('Y-m') !== $today->format('Y-m')): ?>
  <p class="cal-today-link"><a href="<?= h(url('booking/calendar.php')) ?>">Back to <?= h($today->format('F Y')) ?></a></p>
<?php endif; ?>

  <div class="cal-legend">
    <span><span class="cal-dot status-confirmed"></span> Confirmed</span>
    <span><span class="cal-dot status-draft"></span> Draft — not yet confirmed</span>
    <span><span class="cal-dot status-completed"></span> Completed</span>
  </div>

  <div class="cal-grid" role="grid" aria-label="<?= h($cursor->format('F Y')) ?> bookings">
<?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dayName): ?>
    <div class="cal-head" role="columnheader"><?= h($dayName) ?></div>
<?php endforeach; ?>
<?php for ($i = 0; $i < $leadingBlanks; $i++): ?>
    <div class="cal-cell blank" role="gridcell"></div>
<?php endfor; ?>
<?php for ($day = 1; $day <= $daysInMonth; $day++):
        $iso = $cursor->format('Y-m-') . sprintf('%02d', $day);
        $dayEntries = $byDay[$iso] ?? [];
        $isToday = $iso === $today->format('Y-m-d');
        $isPast = $iso < $today->format('Y-m-d'); ?>
    <div class="cal-cell<?= $isToday ? ' today' : '' ?><?= $isPast ? ' past' : '' ?><?= $dayEntries ? ' booked' : '' ?>" role="gridcell">
      <div class="cal-date"><?= $day ?><?= $isToday ? ' <span class="cal-today-tag">today</span>' : '' ?></div>
<?php if (!$dayEntries): ?>
      <div class="cal-free">Free</div>
<?php else: ?>
      <ul class="cal-entries">
<?php foreach ($dayEntries as $entry):
              $mine = calendar_entry_is_own($user, $entry);
              $where = trim((string) $entry['venue_location']);
              $where = $where === '' ? '' : ' (' . $where . ')'; ?>
        <li class="cal-entry">
          <span class="cal-dot status-<?= h($entry['status']) ?>" title="<?= h(ucfirst($entry['status'])) ?>"></span>
<?php if ($mine): ?>
          <a href="<?= h(url('booking/form.php?id=' . (int) $entry['id'])) ?>"
             title="<?= h(format_document_number($entry['unique_id'], 'SLA', (int) $entry['revision']) . ' — ' . $entry['client_name']
                          . ', ' . ($entry['venue'] ?? 'no venue') . $where . ', ' . ucfirst($entry['status'])) ?>">
            <?= h($entry['venue'] ?? 'No venue') ?></a>
          <span class="cal-who"><?= h($entry['client_name']) ?></span>
<?php else: ?>
          <span class="cal-taken" title="<?= h(($entry['venue'] ?? 'No venue') . $where . ' — ' . ucfirst($entry['status']) . ', booked by another vendor') ?>"><?= h($entry['venue'] ?? 'No venue') ?></span>
          <span class="cal-who">booked</span>
<?php endif; ?>
        </li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
    </div>
<?php endfor; ?>
<?php for ($i = 0; $i < $trailingBlanks; $i++): ?>
    <div class="cal-cell blank" role="gridcell"></div>
<?php endfor; ?>
  </div>
</div>

<div class="card pad">
  <h3>VENUE AVAILABILITY — <?= h(strtoupper($cursor->format('F Y'))) ?></h3>
<?php if (!$availability): ?>
  <p class="muted">No venues are set up yet.</p>
<?php else: ?>
  <p class="muted">Days each venue is taken out of the <?= $daysInMonth ?> in this month.</p>
  <table class="registry avail-table">
    <thead><tr><th>Venue</th><th class="avail-bar-col">Booked</th><th class="num">Days booked</th><th class="num">Days free</th></tr></thead>
    <tbody>
<?php foreach ($availability as $row): $pct = $busiest > 0 ? (int) round($row['days'] / $daysInMonth * 100) : 0; ?>
      <tr>
        <td><?= h($row['venue']) ?></td>
        <td class="avail-bar-col">
          <div class="avail-bar" role="img" aria-label="<?= $row['days'] ?> of <?= $daysInMonth ?> days booked">
            <span style="width: <?= $pct ?>%"></span>
          </div>
        </td>
        <td class="num"><?= $row['days'] ?></td>
        <td class="num"><?= $row['free'] ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
