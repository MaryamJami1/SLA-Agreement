<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

$user = require_login();
$pdo = db();

const REGISTRY_PAGE_SIZE = 25;
const REGISTRY_STATUSES = ['draft', 'confirmed', 'completed', 'cancelled'];

$q = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$q = mb_substr($q, 0, 100);
$status = in_array($_GET['status'] ?? '', REGISTRY_STATUSES, true) ? $_GET['status'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));

[$scope, $params] = booking_scope_sql($user);

// Two WHERE clauses: one with the status filter for the listing, one without it for the status
// tabs — a tab must still say how many "confirmed" there are while you are looking at "draft".
$where = [$scope];
if ($q !== '') {
    $like = '%' . like_escape($q) . '%';
    $where[] = '(b.unique_id LIKE ? OR b.client_name LIKE ? OR b.firm_name LIKE ? OR b.client_contact LIKE ?
                 OR v.name LIKE ? OR b.venue_other LIKE ? OR b.venue_location LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like, $like);
}
$countParams = $params;
$countWhereSql = implode(' AND ', $where);

if ($status !== '') {
    $where[] = 'b.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

// Counts and money per status, for the tabs and the summary strip.
$st = $pdo->prepare("SELECT b.status, COUNT(*) AS n, COALESCE(SUM(b.balance), 0) AS due
                       FROM bookings b LEFT JOIN venues v ON v.id = b.venue_id
                      WHERE $countWhereSql GROUP BY b.status");
$st->execute($countParams);
$byStatus = [];
$allCount = 0;
$outstandingPaisa = 0;                      // money is added in whole paisa, never as decimals
foreach ($st->fetchAll() as $r) {
    $byStatus[$r['status']] = ['n' => (int) $r['n'], 'due' => $r['due']];
    $allCount += (int) $r['n'];
    // Cancelled bookings retain what was paid; nothing further is owed on them.
    if ($r['status'] !== 'cancelled') {
        $outstandingPaisa += decimal_to_paisa($r['due']);
    }
}
$outstanding = paisa_to_decimal($outstandingPaisa);

$st = $pdo->prepare("SELECT COUNT(*) FROM bookings b LEFT JOIN venues v ON v.id = b.venue_id WHERE $whereSql");
$st->execute($params);
$total = (int) $st->fetchColumn();
$pages = max(1, (int) ceil($total / REGISTRY_PAGE_SIZE));
$page = min($page, $pages);
$offset = ($page - 1) * REGISTRY_PAGE_SIZE;

$st = $pdo->prepare("SELECT b.id, b.unique_id, b.revision, b.status, b.client_name, b.firm_name, b.event_date,
                            b.grand_total, b.paid_total, b.balance, b.venue_location,
                            COALESCE(v.name, b.venue_other) AS venue
                       FROM bookings b LEFT JOIN venues v ON v.id = b.venue_id
                      WHERE $whereSql
                      ORDER BY b.event_date IS NULL, b.event_date DESC, b.id DESC
                      LIMIT " . REGISTRY_PAGE_SIZE . " OFFSET $offset");
$st->execute($params);
$rows = $st->fetchAll();

$pendingVendors = $user['role'] === 'admin'
    ? (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'vendor' AND status = 'pending'")->fetchColumn()
    : 0;

$pageUrl = static function (int $p) use ($q, $status): string {
    return url('booking/list.php?' . http_build_query(array_filter(['q' => $q, 'status' => $status, 'page' => $p > 1 ? $p : null])));
};

$pageTitle = 'Registry';
$activeTab = 'registry';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Registry</h2>
  <a class="btn primary" href="<?= h(url('booking/form.php')) ?>">+ New Booking</a>
</div>

<?php if ($pendingVendors > 0): ?>
<div class="flash info"><strong><?= $pendingVendors ?></strong> vendor account(s) are waiting for approval.
  <a href="<?= h(url('admin/approvals.php')) ?>">Review approvals</a></div>
<?php endif; ?>

<div class="reg-summary">
  <div class="reg-stat"><span class="reg-stat-n"><?= $allCount ?></span><span class="reg-stat-l">Booking<?= $allCount === 1 ? '' : 's' ?></span></div>
  <div class="reg-stat"><span class="reg-stat-n"><?= $byStatus['confirmed']['n'] ?? 0 ?></span><span class="reg-stat-l">Confirmed</span></div>
  <div class="reg-stat"><span class="reg-stat-n"><?= $byStatus['draft']['n'] ?? 0 ?></span><span class="reg-stat-l">Draft</span></div>
  <div class="reg-stat reg-stat-money"><span class="reg-stat-n"><?= h(rs($outstanding)) ?></span><span class="reg-stat-l">Outstanding</span></div>
</div>

<form method="get" action="<?= h(url('booking/list.php')) ?>" class="list-toolbar">
  <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by SLA number, client, firm, contact or venue…" aria-label="Search">
<?php /* Kept for browsers without JS and for anyone submitting the form by keyboard. */ ?>
  <select name="status" aria-label="Status">
    <option value="">All statuses</option>
<?php foreach (REGISTRY_STATUSES as $s): ?>
    <option value="<?= $s ?>"<?= $s === $status ? ' selected' : '' ?>><?= ucfirst($s) ?></option>
<?php endforeach; ?>
  </select>
  <button type="submit" class="btn">Search</button>
<?php if ($q !== '' || $status !== ''): ?>
  <a class="btn" href="<?= h(url('booking/list.php')) ?>">Clear</a>
<?php endif; ?>
</form>

<nav class="reg-tabs" aria-label="Filter by status">
  <a class="reg-tab<?= $status === '' ? ' current' : '' ?>" href="<?= h(url('booking/list.php?' . http_build_query(array_filter(['q' => $q])))) ?>">
    All <span class="reg-tab-n"><?= $allCount ?></span></a>
<?php foreach (REGISTRY_STATUSES as $s): $n = $byStatus[$s]['n'] ?? 0; ?>
  <a class="reg-tab status-<?= $s ?><?= $s === $status ? ' current' : '' ?><?= $n ? '' : ' empty' ?>"
     href="<?= h(url('booking/list.php?' . http_build_query(array_filter(['q' => $q, 'status' => $s])))) ?>">
    <?= ucfirst($s) ?> <span class="reg-tab-n"><?= $n ?></span></a>
<?php endforeach; ?>
</nav>

<p class="muted registry-count"><?= $total ?> booking(s)<?= $q !== '' || $status !== '' ? ' match' : '' ?><?= $pages > 1 ? " — page $page of $pages" : '' ?></p>

<?php if (!$rows): ?>
  <div class="card empty-note"><?= $total === 0 && $q === '' && $status === '' ? 'No bookings yet. Click “+ New Booking” to start the register.' : 'No bookings match.' ?></div>
<?php else: ?>
<?php
// "in 5 days" / "today" beside an upcoming event, so what needs attention stands out.
$today = new DateTimeImmutable('today');
$whenHint = static function (?string $date) use ($today): string {
    if (!$date) {
        return '';
    }
    $days = (int) $today->diff(new DateTimeImmutable($date))->format('%r%a');
    if ($days < 0)   { return ''; }
    if ($days === 0) { return '<span class="due-chip now">today</span>'; }
    if ($days === 1) { return '<span class="due-chip now">tomorrow</span>'; }
    if ($days <= 14) { return '<span class="due-chip soon">in ' . $days . ' days</span>'; }
    return '';
};
?>
<div class="card table-scroll">
<table class="registry reg-table">
  <thead><tr><th>Booking</th><th>Client</th><th>Event</th>
    <th class="num">Net amount</th><th class="num">Paid</th><th class="num">Balance</th><th></th></tr></thead>
  <tbody>
<?php foreach ($rows as $b): $owing = $b['status'] !== 'cancelled' && decimal_to_paisa($b['balance']) > 0; ?>
    <tr class="reg-row status-<?= h($b['status']) ?>">
      <td>
        <div class="id-cell"><?= h(format_document_number($b['unique_id'], 'SLA', (int) $b['revision'])) ?></div>
        <span class="badge status-<?= h($b['status']) ?>"><?= h(strtoupper($b['status'])) ?></span>
      </td>
      <td>
        <div class="reg-primary"><?= h($b['client_name']) ?></div>
        <div class="hint"><?= h($b['firm_name'] ?: 'No vendor assigned') ?></div>
      </td>
      <td>
<?php if ($b['event_date']): ?>
        <div class="reg-primary"><?= h(date('d M Y', strtotime($b['event_date']))) ?> <?= $whenHint($b['event_date']) ?></div>
        <div class="hint"><?= h(date('D', strtotime($b['event_date']))) ?> · <?= h($b['venue'] ?? 'no venue') ?><?= trim((string) $b['venue_location']) !== '' ? ' — ' . h($b['venue_location']) : '' ?></div>
<?php else: ?>
        <div class="reg-primary muted">Date not set</div>
        <div class="hint"><?= h($b['venue'] ?? 'no venue') ?></div>
<?php endif; ?>
      </td>
      <td class="num"><?= h(rs($b['grand_total'])) ?></td>
      <td class="num"><?= decimal_to_paisa($b['paid_total']) > 0 ? h(rs($b['paid_total'])) : '<span class="hint">—</span>' ?></td>
      <td class="num"><?= $b['status'] === 'cancelled'
            ? '<span class="hint">retained</span>'
            : ($owing ? '<span class="owing">' . h(rs($b['balance'])) . '</span>' : '<span class="settled">settled</span>') ?></td>
      <td class="actions"><a class="rowbtn" href="<?= h(url('booking/form.php?id=' . (int) $b['id'])) ?>">Open</a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
<?php if ($page > 1): ?><a class="btn small" href="<?= h($pageUrl($page - 1)) ?>">← Previous</a><?php endif; ?>
  <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
<?php if ($page < $pages): ?><a class="btn small" href="<?= h($pageUrl($page + 1)) ?>">Next →</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
