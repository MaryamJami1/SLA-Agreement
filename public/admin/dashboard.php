<?php
/**
 * Dashboard: the business at a glance, and the admin's home page.
 *
 * One period control scopes every figure that is "for a period"; the tiles that are "as of now"
 * (outstanding, upcoming, approvals, today's collection) say so. Nothing is changed from here — each
 * list links to the booking or the page where the decision is made. What the figures mean is in
 * app/dashboard.php.
 */
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/payments.php';
require_once APP_ROOT . '/app/charts.php';
require_once APP_ROOT . '/app/dashboard.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

require_admin();
$pdo = db();

$today = new DateTimeImmutable('today');
$param = static fn(string $name): string => is_string($_GET[$name] ?? null) ? $_GET[$name] : '';
$period = dashboard_period($param('range'), $today, $param('from'), $param('to'));

/** This page for a range, keeping a custom range's months. $extra adds e.g. the export flag. */
$rangeUrl = static function (string $key, array $extra = []) use ($period): string {
    $query = $key === DASHBOARD_DEFAULT_RANGE ? [] : ['range' => $key];
    if ($key === 'custom') {
        $query += ['from' => $period['months'][0], 'to' => $period['months'][count($period['months']) - 1]];
    }
    $query += $extra;
    return url('admin/dashboard.php' . ($query ? '?' . http_build_query($query) : ''));
};

$money = dashboard_money_by_month($pdo, $period);
$statuses = dashboard_status_by_month($pdo, $period);

// The monthly figures as a spreadsheet: one row per month, amounts as plain numbers.
if ($param('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dashboard-' . $period['months'][0] . '-to-' . $period['months'][count($period['months']) - 1] . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Month', 'Booked value', 'Collected', 'Completed', 'Confirmed', 'Draft', 'Cancelled'], ',', '"', '');
    foreach ($period['months'] as $ym) {
        fputcsv($out, [$ym, paisa_to_decimal($money[$ym]['booked']), paisa_to_decimal($money[$ym]['collected']),
            $statuses[$ym]['completed'], $statuses[$ym]['confirmed'], $statuses[$ym]['draft'], $statuses[$ym]['cancelled']], ',', '"', '');
    }
    exit;
}

$kpis = dashboard_kpis($pdo, $period, $today);
$cancellations = dashboard_cancellations($pdo, $period);
$overdue = dashboard_overdue($pdo, $today);
$unsigned = dashboard_unsigned($pdo);
$occupancy = dashboard_venue_occupancy($pdo, $today);
$activity = dashboard_activity($pdo);
$methods = dashboard_payment_methods($pdo, $period);
$eventTypes = dashboard_event_types($pdo, $period);
$venues = dashboard_top_venues($pdo, $period);
$users = dashboard_top_users($pdo, $period);
$upcoming = dashboard_upcoming($pdo, $today);
$balances = dashboard_largest_balances($pdo);
$latestPayments = dashboard_latest_payments($pdo);

$months = dashboard_month_labels($period['months']);

// ---------------------------------------------------------------------------
// Chart series
// ---------------------------------------------------------------------------
// The money axis is scaled in whole rupees so its gridlines land on round rupee figures.
$moneySeries = [
    ['class' => 's-booked', 'name' => 'Booked value', 'values' => [], 'display' => []],
    ['class' => 's-collected', 'name' => 'Collected', 'values' => [], 'display' => []],
];
foreach ($money as $row) {
    foreach (['booked', 'collected'] as $k => $key) {
        $moneySeries[$k]['values'][] = intdiv($row[$key], 100);
        $moneySeries[$k]['display'][] = format_rs($row[$key]);
    }
}
$rupeeTick = static fn(int $rupees): string => $rupees === 0 ? '0' : substr(compact_rs($rupees * 100), 4);

// Stacked bottom-up: what has happened, what is agreed, what is pending, what fell through.
$statusSeries = [];
$statusCount = [];
foreach (['completed' => 'Completed', 'confirmed' => 'Confirmed', 'draft' => 'Draft', 'cancelled' => 'Cancelled'] as $status => $name) {
    $values = array_column($statuses, $status);
    $statusCount[$status] = array_sum($values);
    $statusSeries[] = ['class' => 's-' . $status, 'name' => $name, 'values' => $values, 'display' => array_map('strval', $values)];
}
$statusTotals = array_map(static fn(array $row): string => (string) array_sum($row), array_values($statuses));
$allBookings = array_sum($statusCount);

// Payment methods keep their own colour whichever of them appear, so a quiet month does not repaint the ring.
$methodSegments = [];
$methodTotal = 0;
foreach ($methods as $row) {
    $methodSegments[] = ['class' => 's-' . str_replace('_', '-', $row['key']), 'name' => $row['label'], 'value' => $row['amount'],
        'display' => format_rs($row['amount']), 'n' => $row['n']];
    $methodTotal += $row['amount'];
}

// Of what this period's events are worth, how much has been paid on them.
$paidPct = percent_of($kpis['booked_paid'], $kpis['booked']);
$stillOwed = max(0, $kpis['booked'] - $kpis['booked_paid']);
// Whole rupees and whole guests: an average to the paisa would be false precision.
$averageValue = $kpis['bookings'] > 0 ? intdiv(intdiv($kpis['booked'], $kpis['bookings']) + 50, 100) * 100 : 0;
$averageGuests = $kpis['bookings'] > 0 ? (int) round($kpis['guests'] / $kpis['bookings']) : 0;
$paidWord = $kpis['booked'] <= 0 ? 'No events yet' : ($paidPct >= 80 ? 'Good' : ($paidPct >= 50 ? 'Fair' : 'Low'));

/** "▲ Up 12% vs previous 12 months"; empty when the range has nothing to compare with. */
$delta = static function (int $now, ?int $before) use ($period): string {
    if ($before === null || $period['previous'] === null) {
        return '';
    }
    $versus = ' vs ' . $period['previous']['label'];
    $change = percent_change($now, $before);
    if ($change === null) {
        return 'Nothing in the ' . h($period['previous']['label']);
    }
    if ($change === 0) {
        return 'No change' . h($versus);
    }
    return '<span aria-hidden="true">' . ($change > 0 ? '▲' : '▼') . '</span> ' . ($change > 0 ? 'Up ' : 'Down ') . abs($change) . '%' . h($versus);
};
$plural = static fn(int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
$previous = $kpis['previous'];
$waiting = $kpis['pending_users'] + $kpis['draft_bookings'];
$periodSpan = (new DateTimeImmutable($period['start']))->format('M Y') . ' – ' . (new DateTimeImmutable($period['end']))->format('M Y');

/** A ranked table: name, bar, the figure the bar shows, and a second figure for context. */
$rankTable = static function (array $rows, string $nameHead, string $barKey, string $class, array $heads): string {
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, $row[$barKey]);
    }
    $cell = static fn(array $row, string $key): string => $key === 'amount'
        ? '<span title="' . h(format_rs($row['amount'])) . '">' . h(compact_rs($row['amount'])) . '</span>'
        : (string) $row['n'];
    $other = $barKey === 'amount' ? 'n' : 'amount';
    $html = '<table class="rank-table"><thead><tr><th scope="col">' . h($nameHead) . '</th><th scope="col" class="rank-bar-col"><span class="sr-only">Share</span></th>'
        . '<th scope="col" class="num">' . h($heads[$barKey]) . '</th><th scope="col" class="num">' . h($heads[$other]) . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><th scope="row">' . h($row['label']) . '</th><td class="rank-bar-col">' . svg_rank_bar($row[$barKey], $max, $class) . '</td>'
            . '<td class="num rank-main">' . $cell($row, $barKey) . '</td><td class="num rank-side">' . $cell($row, $other) . '</td></tr>';
    }
    return $html . '</tbody></table>';
};

$pageTitle = 'Dashboard';
$activeTab = 'dashboard';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head dash-head">
  <div>
    <h2>Dashboard</h2>
    <span class="muted">As of <?= h($today->format('d M Y')) ?> · <?= h($period['label']) ?> (<?= h($periodSpan) ?>)</span>
  </div>
  <a class="btn small" href="<?= h($rangeUrl($period['key'], ['export' => 'csv'])) ?>">Download CSV</a>
</div>

<div class="dash-controls">
  <nav class="reg-tabs dash-range" aria-label="Period">
<?php foreach (DASHBOARD_RANGES as $key => $label): ?>
    <a class="reg-tab<?= $key === $period['key'] ? ' current' : '' ?>"<?= $key === $period['key'] ? ' aria-current="true"' : '' ?>
       href="<?= h($rangeUrl($key)) ?>"><?= h($label) ?></a>
<?php endforeach; ?>
  </nav>
  <form method="get" action="<?= h(url('admin/dashboard.php')) ?>" class="dash-custom<?= $period['key'] === 'custom' ? ' current' : '' ?>">
    <input type="hidden" name="range" value="custom">
    <label for="dash-from">From</label>
    <input type="month" id="dash-from" name="from" value="<?= h($period['months'][0]) ?>" placeholder="YYYY-MM" pattern="\d{4}-\d{2}" required>
    <label for="dash-to">to</label>
    <input type="month" id="dash-to" name="to" value="<?= h($period['months'][count($period['months']) - 1]) ?>" placeholder="YYYY-MM" pattern="\d{4}-\d{2}" required>
    <button type="submit" class="btn small">Apply</button>
  </form>
</div>
<?php if ($period['clamped']): ?>
<div class="flash info">A custom range can cover at most <?= DASHBOARD_MAX_MONTHS ?> months, so this one stops at <?= h((new DateTimeImmutable($period['end']))->format('F Y')) ?>.</div>
<?php endif; ?>

<div class="hero-grid">
  <div class="hero hero-blue">
    <div class="hero-main">
      <span class="hero-icon"><?= dash_icon('money') ?></span>
      <div class="hero-text">
        <span class="hero-label">Booked value</span>
        <span class="hero-value" title="<?= h(format_rs($kpis['booked'])) ?>"><?= h(compact_rs($kpis['booked'])) ?></span>
      </div>
    </div>
    <div class="hero-foot"><?= $delta($kpis['booked'], $previous['booked'] ?? null) ?: h($plural($kpis['bookings'], 'event', 'events') . ' confirmed or completed') ?></div>
  </div>
  <div class="hero hero-green">
    <div class="hero-main">
      <span class="hero-icon"><?= dash_icon('wallet') ?></span>
      <div class="hero-text">
        <span class="hero-label">Collected</span>
        <span class="hero-value" title="<?= h(format_rs($kpis['collected'])) ?>"><?= h(compact_rs($kpis['collected'])) ?></span>
      </div>
    </div>
    <div class="hero-foot"><?= $delta($kpis['collected'], $previous['collected'] ?? null) ?: 'Payments less refunds' ?></div>
  </div>
  <div class="hero hero-rose">
    <div class="hero-main">
      <span class="hero-icon"><?= dash_icon('alert') ?></span>
      <div class="hero-text">
        <span class="hero-label">Outstanding now</span>
        <span class="hero-value" title="<?= h(format_rs($kpis['outstanding'])) ?>"><?= h(compact_rs($kpis['outstanding'])) ?></span>
      </div>
    </div>
    <div class="hero-foot"><?= $overdue['count'] > 0
        ? h(compact_rs($overdue['amount'])) . ' overdue on ' . h($plural($overdue['count'], 'past event', 'past events'))
        : 'Owed on ' . h($plural($kpis['owing_bookings'], 'confirmed booking', 'confirmed bookings')) ?></div>
  </div>
  <div class="hero hero-teal">
    <div class="hero-main">
      <span class="hero-icon"><?= dash_icon('percent') ?></span>
      <div class="hero-text">
        <span class="hero-label">Paid so far</span>
        <span class="hero-value"><?= $paidPct ?>%</span>
      </div>
    </div>
    <div class="hero-foot"><?= h($paidWord) ?><?= $kpis['booked'] > 0 ? ' · of this period’s booked value' : '' ?></div>
  </div>
</div>

<div class="stat-grid">
  <div class="stat">
    <div class="stat-body">
      <span class="stat-label">Total bookings</span>
      <span class="stat-value"><?= $allBookings ?></span>
      <a class="stat-link" href="<?= h(url('booking/list.php')) ?>">View registry</a>
    </div>
    <span class="stat-icon"><?= dash_icon('list') ?></span>
  </div>
  <div class="stat">
    <div class="stat-body">
      <span class="stat-label">Events booked</span>
      <span class="stat-value"><?= $kpis['bookings'] ?></span>
      <span class="stat-note"><?= $delta($kpis['bookings'], $previous['bookings'] ?? null) ?: 'Confirmed and completed' ?></span>
    </div>
    <span class="stat-icon"><?= dash_icon('calendar') ?></span>
  </div>
  <div class="stat">
    <div class="stat-body">
      <span class="stat-label">Events in next <?= DASHBOARD_LOOKAHEAD_DAYS ?> days</span>
      <span class="stat-value"><?= $kpis['upcoming'] ?></span>
      <a class="stat-link" href="<?= h(url('booking/calendar.php')) ?>"><?= $kpis['upcoming_drafts'] > 0
          ? 'Plus ' . h($plural($kpis['upcoming_drafts'], 'draft', 'drafts')) . ' · calendar' : 'View calendar' ?></a>
    </div>
    <span class="stat-icon"><?= dash_icon('clock') ?></span>
  </div>
  <div class="stat<?= $waiting > 0 ? ' stat-attention' : '' ?>">
    <div class="stat-body">
      <span class="stat-label">Waiting for approval</span>
      <span class="stat-value"><?= $waiting ?></span>
      <a class="stat-link" href="<?= h(url('admin/approvals.php')) ?>"><?= $waiting > 0 ? 'Review approvals' : 'Nothing is waiting' ?></a>
    </div>
    <span class="stat-icon"><?= dash_icon('alert') ?></span>
  </div>
  <div class="stat">
    <div class="stat-body">
      <span class="stat-label">Today’s collection</span>
      <span class="stat-value"><?= h(rs($kpis['collected_today'])) ?></span>
      <span class="stat-note"><?= h($today->format('d M Y')) ?></span>
    </div>
    <span class="stat-icon"><?= dash_icon('today') ?></span>
  </div>
  <div class="stat">
    <div class="stat-body">
      <span class="stat-label">Draft bookings</span>
      <span class="stat-value"><?= $kpis['draft_bookings'] ?></span>
      <a class="stat-link" href="<?= h(url('booking/list.php?status=draft')) ?>">View drafts</a>
    </div>
    <span class="stat-icon"><?= dash_icon('draft') ?></span>
  </div>
  <div class="stat">
    <div class="stat-body">
      <span class="stat-label">Active users</span>
      <span class="stat-value"><?= $kpis['active_users'] ?></span>
      <a class="stat-link" href="<?= h(url('admin/users.php')) ?>"><?= $kpis['pending_users'] > 0
          ? h($plural($kpis['pending_users'], 'account', 'accounts')) . ' pending' : 'View users' ?></a>
    </div>
    <span class="stat-icon"><?= dash_icon('users') ?></span>
  </div>
  <div class="stat">
    <div class="stat-body">
      <span class="stat-label">Active venues</span>
      <span class="stat-value"><?= $kpis['active_venues'] ?></span>
      <a class="stat-link" href="<?= h(url('admin/venues.php')) ?>">View venues</a>
    </div>
    <span class="stat-icon"><?= dash_icon('venue') ?></span>
  </div>
  <div class="stat">
    <div class="stat-body">
      <span class="stat-label">Average booking value</span>
      <span class="stat-value" title="<?= h(format_rs($averageValue)) ?>"><?= h(compact_rs($averageValue)) ?></span>
      <span class="stat-note">Per confirmed or completed event</span>
    </div>
    <span class="stat-icon"><?= dash_icon('money') ?></span>
  </div>
  <div class="stat">
    <div class="stat-body">
      <span class="stat-label">Average guests</span>
      <span class="stat-value"><?= $averageGuests ?></span>
      <span class="stat-note">Per confirmed or completed event</span>
    </div>
    <span class="stat-icon"><?= dash_icon('users') ?></span>
  </div>
  <div class="stat">
    <div class="stat-body">
      <span class="stat-label">Cancelled in this period</span>
      <span class="stat-value"><?= $cancellations['cancelled'] ?></span>
      <a class="stat-link" href="<?= h(url('booking/list.php?status=cancelled')) ?>"><?= $cancellations['cancelled'] > 0
          ? h(rs($cancellations['retained'])) . ' retained' : 'View cancelled' ?></a>
    </div>
    <span class="stat-icon"><?= dash_icon('cancel') ?></span>
  </div>
  <div class="stat">
    <div class="stat-body">
      <span class="stat-label">Refunded in this period</span>
      <span class="stat-value" title="<?= h(format_rs($cancellations['refunded'])) ?>"><?= h(compact_rs($cancellations['refunded'])) ?></span>
      <span class="stat-note"><?= h($plural($cancellations['refunds'], 'refund', 'refunds')) ?> paid out</span>
    </div>
    <span class="stat-icon"><?= dash_icon('wallet') ?></span>
  </div>
</div>

<div class="dash-grid">
  <section class="card pad dash-panel span-8" aria-labelledby="p-status">
    <header class="panel-head">
      <div>
        <h3 id="p-status">Bookings by status</h3>
        <p class="panel-sub">Number of bookings per month, by event date</p>
      </div>
      <ul class="chart-legend">
<?php foreach (array_reverse($statusSeries) as $s): ?>
        <li><span class="swatch <?= h($s['class']) ?>"></span><?= h($s['name']) ?></li>
<?php endforeach; ?>
      </ul>
    </header>
<?php if (chart_is_empty($statusSeries)): ?>
    <p class="chart-empty">No bookings with an event date in this period yet.</p>
<?php else: ?>
    <div class="chart-scroll"><?= svg_column_chart($months, $statusSeries, 'strval', true, 'Bookings per month by status', $statusTotals) ?></div>
    <details class="chart-table">
      <summary>View as table</summary>
      <div class="table-scroll">
      <table class="registry">
        <thead><tr><th>Month</th><th class="num">Completed</th><th class="num">Confirmed</th><th class="num">Draft</th><th class="num">Cancelled</th><th class="num">Total</th></tr></thead>
        <tbody>
<?php foreach (array_values($statuses) as $i => $row): ?>
          <tr><td><?= h($months[$i]['title']) ?></td><td class="num"><?= $row['completed'] ?></td><td class="num"><?= $row['confirmed'] ?></td>
            <td class="num"><?= $row['draft'] ?></td><td class="num"><?= $row['cancelled'] ?></td><td class="num"><?= array_sum($row) ?></td></tr>
<?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </details>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-4" aria-labelledby="p-latest">
    <header class="panel-head">
      <div>
        <h3 id="p-latest">Latest payments</h3>
        <p class="panel-sub">The most recent money in and out</p>
      </div>
      <a class="panel-link" href="<?= h(url('booking/list.php')) ?>">Registry</a>
    </header>
<?php if (!$latestPayments): ?>
    <p class="chart-empty">No payments have been recorded yet.</p>
<?php else: ?>
    <ul class="feed">
<?php foreach ($latestPayments as $p): $refund = $p['kind'] === 'refund'; ?>
      <li>
        <span class="feed-dot<?= $refund ? ' refund' : '' ?>" aria-hidden="true"></span>
        <div class="feed-body">
          <div class="feed-title"><?= $refund ? 'Refund of ' : '' ?><?= h(rs($p['amount'])) ?> · <?= h(PAYMENT_METHODS[$p['method']] ?? $p['method']) ?></div>
          <div class="feed-meta"><?= h(date('d M Y', strtotime($p['paid_on']))) ?> · <?= h($p['client_name'] ?? '—') ?></div>
        </div>
        <a class="btn small feed-open" href="<?= h(url('booking/form.php?id=' . (int) $p['booking_id'])) ?>"
           aria-label="Open <?= h(format_document_number($p['unique_id'], 'SLA', (int) $p['revision'])) ?>">Open</a>
      </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-12" aria-labelledby="p-money">
    <header class="panel-head">
      <div>
        <h3 id="p-money">Booked value and money collected</h3>
        <p class="panel-sub">Rupees per month — bookings by event date, payments by the date received</p>
      </div>
      <ul class="chart-legend">
        <li><span class="swatch s-booked"></span>Booked value</li>
        <li><span class="swatch s-collected"></span>Collected</li>
      </ul>
    </header>
<?php if (chart_is_empty($moneySeries)): ?>
    <p class="chart-empty">No confirmed bookings or payments in this period yet.</p>
<?php else: ?>
    <div class="chart-scroll"><?= svg_area_chart($months, $moneySeries, $rupeeTick, 'Booked value and money collected per month') ?></div>
    <details class="chart-table">
      <summary>View as table</summary>
      <div class="table-scroll">
      <table class="registry">
        <thead><tr><th>Month</th><th class="num">Booked value</th><th class="num">Collected</th></tr></thead>
        <tbody>
<?php foreach (array_values($money) as $i => $row): ?>
          <tr><td><?= h($months[$i]['title']) ?></td><td class="num"><?= h(format_rs($row['booked'])) ?></td><td class="num"><?= h(format_rs($row['collected'])) ?></td></tr>
<?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </details>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-4" aria-labelledby="p-paid">
    <header class="panel-head">
      <div>
        <h3 id="p-paid">Payment progress</h3>
        <p class="panel-sub">Paid on this period’s confirmed and completed events</p>
      </div>
    </header>
<?php if ($kpis['booked'] <= 0): ?>
    <p class="chart-empty">No confirmed bookings in this period.</p>
<?php else: ?>
    <div class="gauge-wrap"><?= svg_gauge($paidPct, 's-collected', $paidPct . '% of booked value has been paid') ?></div>
    <ul class="key-list">
      <li><span class="swatch s-collected"></span><span class="key-name">Paid</span><span class="key-value"><?= h(rs($kpis['booked_paid'])) ?></span></li>
      <li><span class="swatch swatch-track s-collected"></span><span class="key-name">Still owed</span><span class="key-value"><?= h(rs($stillOwed)) ?></span></li>
      <li class="key-total"><span class="key-name">Booked value</span><span class="key-value"><?= h(rs($kpis['booked'])) ?></span></li>
    </ul>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-4" aria-labelledby="p-methods">
    <header class="panel-head">
      <div>
        <h3 id="p-methods">Payments by method</h3>
        <p class="panel-sub">Money collected, net of refunds</p>
      </div>
    </header>
<?php if (!$methods || $methodTotal <= 0): ?>
    <p class="chart-empty">No payments recorded in this period.</p>
<?php else: ?>
    <div class="donut-wrap"><?= svg_donut($methodSegments, substr(compact_rs($methodTotal), 4), 'collected', 'Money collected by payment method') ?></div>
    <ul class="key-list">
<?php foreach ($methodSegments as $s): ?>
      <li><span class="swatch <?= h($s['class']) ?>"></span><span class="key-name"><?= h($s['name']) ?></span>
        <span class="key-share"><?= percent_of(max(0, $s['value']), $methodTotal) ?>%</span><span class="key-value"><?= h($s['display']) ?></span></li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-4" aria-labelledby="p-types">
    <header class="panel-head">
      <div>
        <h3 id="p-types">Event types</h3>
        <p class="panel-sub">Confirmed and completed bookings</p>
      </div>
    </header>
<?php if (!$eventTypes): ?>
    <p class="chart-empty">No confirmed bookings in this period.</p>
<?php else: ?>
    <?= $rankTable($eventTypes, 'Type', 'n', 's-booked', ['n' => 'Bookings', 'amount' => 'Value']) ?>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-6" aria-labelledby="p-venues">
    <header class="panel-head">
      <div>
        <h3 id="p-venues">Busiest venues</h3>
        <p class="panel-sub">By number of confirmed and completed bookings</p>
      </div>
      <a class="panel-link" href="<?= h(url('booking/calendar.php')) ?>">Calendar</a>
    </header>
<?php if (!$venues): ?>
    <p class="chart-empty">No confirmed bookings in this period.</p>
<?php else: ?>
    <?= $rankTable($venues, 'Venue', 'n', 's-booked', ['n' => 'Bookings', 'amount' => 'Value']) ?>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-6" aria-labelledby="p-users">
    <header class="panel-head">
      <div>
        <h3 id="p-users">Top users</h3>
        <p class="panel-sub">By booked value</p>
      </div>
      <a class="panel-link" href="<?= h(url('admin/users.php')) ?>">Users</a>
    </header>
<?php if (!$users): ?>
    <p class="chart-empty">No confirmed bookings in this period.</p>
<?php else: ?>
    <?= $rankTable($users, 'User', 'amount', 's-booked', ['amount' => 'Value', 'n' => 'Bookings']) ?>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-7" aria-labelledby="p-upcoming">
    <header class="panel-head">
      <div>
        <h3 id="p-upcoming">Upcoming events</h3>
        <p class="panel-sub">The next <?= DASHBOARD_UPCOMING_DAYS ?> days</p>
      </div>
      <a class="panel-link" href="<?= h(url('booking/calendar.php')) ?>">Calendar</a>
    </header>
<?php if (!$upcoming): ?>
    <p class="chart-empty">No events in the next <?= DASHBOARD_UPCOMING_DAYS ?> days.</p>
<?php else: ?>
    <div class="table-scroll">
    <table class="registry dash-list">
      <thead><tr><th>Date</th><th>Client</th><th>Status</th><th class="actions"></th></tr></thead>
      <tbody>
<?php foreach ($upcoming as $b): ?>
        <tr>
          <td>
            <div class="reg-primary"><?= h(date('D, d M', strtotime($b['event_date']))) ?></div>
            <?= event_when_chip($b['event_date'], $today) ?>
          </td>
          <td>
            <div class="reg-primary"><?= h($b['client_name'] ?? '—') ?></div>
            <div class="hint"><?= h($b['venue'] ?? 'no venue') ?> · <?= (int) $b['guests'] ?> guests</div>
          </td>
          <td><span class="badge status-<?= h($b['status']) ?>"><?= h(strtoupper($b['status'])) ?></span></td>
          <td class="actions"><a class="rowbtn" href="<?= h(url('booking/form.php?id=' . (int) $b['id'])) ?>">Open</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-5" aria-labelledby="p-occupancy">
    <header class="panel-head">
      <div>
        <h3 id="p-occupancy">Venue occupancy</h3>
        <p class="panel-sub">Days taken in the next <?= DASHBOARD_LOOKAHEAD_DAYS ?> days, drafts included</p>
      </div>
      <a class="panel-link" href="<?= h(url('booking/calendar.php')) ?>">Calendar</a>
    </header>
<?php if (!$occupancy): ?>
    <p class="chart-empty">No venues are set up yet.</p>
<?php else: ?>
    <table class="rank-table">
      <thead><tr><th scope="col">Venue</th><th scope="col" class="rank-bar-col"><span class="sr-only">Share of days taken</span></th>
        <th scope="col" class="num">Taken</th><th scope="col" class="num">Free</th></tr></thead>
      <tbody>
<?php foreach ($occupancy as $row): ?>
        <tr><th scope="row"><?= h($row['venue']) ?></th>
          <td class="rank-bar-col"><?= svg_rank_bar($row['days'], DASHBOARD_LOOKAHEAD_DAYS, 's-booked') ?></td>
          <td class="num rank-main"><?= $row['days'] ?></td><td class="num rank-side"><?= $row['free'] ?></td></tr>
<?php endforeach; ?>
      </tbody>
    </table>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-6" aria-labelledby="p-overdue">
    <header class="panel-head">
      <div>
        <h3 id="p-overdue">Overdue balances</h3>
        <p class="panel-sub"><?= $overdue['count'] > 0
            ? h(rs($overdue['amount'])) . ' owed on ' . h($plural($overdue['count'], 'event', 'events')) . ' already held'
            : 'Money still owed after the event' ?></p>
      </div>
      <a class="panel-link" href="<?= h(url('booking/list.php')) ?>">Registry</a>
    </header>
<?php if (!$overdue['rows']): ?>
    <p class="chart-empty">Nothing is overdue.</p>
<?php else: ?>
    <div class="table-scroll">
    <table class="registry dash-list">
      <thead><tr><th>Booking</th><th>Event</th><th class="num">Balance</th><th class="actions"></th></tr></thead>
      <tbody>
<?php foreach ($overdue['rows'] as $b): $late = (int) (new DateTimeImmutable($b['event_date']))->diff($today)->format('%a'); ?>
        <tr>
          <td>
            <div class="reg-primary"><?= h($b['client_name'] ?? '—') ?></div>
            <div class="hint"><?= h(format_document_number($b['unique_id'], 'SLA', (int) $b['revision'])) ?><?= trim((string) $b['client_contact']) !== '' ? ' · ' . h($b['client_contact']) : '' ?></div>
          </td>
          <td>
            <div><?= h(date('d M Y', strtotime($b['event_date']))) ?></div>
            <span class="due-chip <?= $late > 30 ? 'now' : 'soon' ?>"><?= h($plural($late, 'day', 'days')) ?> late</span>
          </td>
          <td class="num"><span class="owing"><?= h(rs($b['balance'])) ?></span></td>
          <td class="actions"><a class="rowbtn" href="<?= h(url('booking/form.php?id=' . (int) $b['id'])) ?>">Open</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php if ($overdue['count'] > count($overdue['rows'])): ?>
    <p class="panel-more">Showing the <?= count($overdue['rows']) ?> longest overdue of <?= $overdue['count'] ?>.</p>
<?php endif; ?>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-6" aria-labelledby="p-balances">
    <header class="panel-head">
      <div>
        <h3 id="p-balances">Largest balances</h3>
        <p class="panel-sub">Still owed on confirmed and completed bookings</p>
      </div>
      <a class="panel-link" href="<?= h(url('booking/list.php')) ?>">Registry</a>
    </header>
<?php if (!$balances): ?>
    <p class="chart-empty">Nothing is outstanding.</p>
<?php else: ?>
    <div class="table-scroll">
    <table class="registry dash-list">
      <thead><tr><th>Booking</th><th class="num">Balance</th><th class="actions"></th></tr></thead>
      <tbody>
<?php foreach ($balances as $b): ?>
        <tr>
          <td>
            <div class="reg-primary"><?= h($b['client_name'] ?? '—') ?></div>
            <div class="hint"><?= h(format_document_number($b['unique_id'], 'SLA', (int) $b['revision'])) ?><?= $b['event_date'] ? ' · ' . h(date('d M Y', strtotime($b['event_date']))) : '' ?></div>
          </td>
          <td class="num">
            <div class="owing"><?= h(rs($b['balance'])) ?></div>
            <div class="hint">of <?= h(rs($b['grand_total'])) ?></div>
          </td>
          <td class="actions"><a class="rowbtn" href="<?= h(url('booking/form.php?id=' . (int) $b['id'])) ?>">Open</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-6" aria-labelledby="p-unsigned">
    <header class="panel-head">
      <div>
        <h3 id="p-unsigned">Signed copy missing</h3>
        <p class="panel-sub"><?= $unsigned['count'] > 0
            ? h($plural($unsigned['count'], 'confirmed booking has', 'confirmed bookings have')) . ' no signed agreement on file'
            : 'Confirmed bookings with no signed agreement on file' ?></p>
      </div>
    </header>
<?php if (!$unsigned['rows']): ?>
    <p class="chart-empty">Every confirmed booking has its signed copy.</p>
<?php else: ?>
    <div class="table-scroll">
    <table class="registry dash-list">
      <thead><tr><th>Booking</th><th>Event</th><th class="actions"></th></tr></thead>
      <tbody>
<?php foreach ($unsigned['rows'] as $b): ?>
        <tr>
          <td>
            <div class="reg-primary"><?= h($b['client_name'] ?? '—') ?></div>
            <div class="hint"><?= h(format_document_number($b['unique_id'], 'SLA', (int) $b['revision'])) ?><?= trim((string) $b['firm_name']) !== '' ? ' · ' . h($b['firm_name']) : '' ?></div>
          </td>
          <td>
            <div><?= $b['event_date'] ? h(date('d M Y', strtotime($b['event_date']))) : '<span class="muted">Date not set</span>' ?></div>
            <?= event_when_chip($b['event_date'], $today) ?>
          </td>
          <td class="actions"><a class="rowbtn" href="<?= h(url('booking/form.php?id=' . (int) $b['id'])) ?>">Open</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
    </div>
<?php if ($unsigned['count'] > count($unsigned['rows'])): ?>
    <p class="panel-more">Showing the <?= count($unsigned['rows']) ?> soonest of <?= $unsigned['count'] ?>.</p>
<?php endif; ?>
<?php endif; ?>
  </section>

  <section class="card pad dash-panel span-6" aria-labelledby="p-activity">
    <header class="panel-head">
      <div>
        <h3 id="p-activity">Recent activity</h3>
        <p class="panel-sub">The latest changes, and who made them</p>
      </div>
    </header>
<?php if (!$activity): ?>
    <p class="chart-empty">Nothing has been recorded yet.</p>
<?php else: ?>
    <ul class="feed">
<?php foreach ($activity as $a): $subject = dashboard_activity_subject($a); ?>
      <li>
        <span class="feed-dot neutral" aria-hidden="true"></span>
        <div class="feed-body">
          <div class="feed-title"><?= h(DASHBOARD_ACTIVITY[$a['action']] ?? $a['action']) ?><?= $subject !== '' ? ' · ' . h($subject) : '' ?></div>
          <div class="feed-meta"><?= h(date('d M Y, H:i', strtotime((string) $a['created_at']))) ?> · <?= h($a['user_name'] ?? 'Not signed in') ?></div>
        </div>
<?php if (!empty($a['unique_id'])): ?>
        <a class="btn small feed-open" href="<?= h(url('booking/form.php?id=' . (int) $a['booking_id'])) ?>">Open</a>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ul>
<?php endif; ?>
  </section>
</div>

<p class="dash-note"><strong>How these are counted.</strong> Booked value is the net amount of confirmed and completed
  bookings, placed in the month of the event; drafts and cancelled bookings are left out. Collected is payments less
  refunds on the date the money moved, including money retained on cancelled bookings. Paid so far is how much of this
  period’s booked value has been paid on those same bookings. Outstanding is what confirmed and completed bookings still
  owe today — the Registry’s figure also counts drafts, so it can be higher.</p>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
