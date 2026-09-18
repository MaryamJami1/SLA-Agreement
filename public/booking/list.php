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
$where = [$scope];
if ($status !== '') {
    $where[] = 'b.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $like = '%' . like_escape($q) . '%';
    $where[] = '(b.unique_id LIKE ? OR b.client_name LIKE ? OR b.firm_name LIKE ? OR b.client_contact LIKE ?
                 OR v.name LIKE ? OR b.venue_other LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) FROM bookings b LEFT JOIN venues v ON v.id = b.venue_id WHERE $whereSql");
$st->execute($params);
$total = (int) $st->fetchColumn();
$pages = max(1, (int) ceil($total / REGISTRY_PAGE_SIZE));
$page = min($page, $pages);
$offset = ($page - 1) * REGISTRY_PAGE_SIZE;

$st = $pdo->prepare("SELECT b.id, b.unique_id, b.revision, b.status, b.client_name, b.firm_name, b.event_date,
                            b.grand_total, b.paid_total, b.balance, COALESCE(v.name, b.venue_other) AS venue
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
<?php if ($pendingVendors > 0): ?>
<div class="flash info"><strong><?= $pendingVendors ?></strong> vendor account(s) are waiting for approval.
  <a href="<?= h(url('admin/vendors.php')) ?>">Review vendors</a></div>
<?php endif; ?>

<form method="get" action="<?= h(url('booking/list.php')) ?>" class="list-toolbar">
  <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by SLA number, client, firm, contact or venue…" aria-label="Search">
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
  <a class="btn primary" href="<?= h(url('booking/form.php')) ?>">+ New Booking</a>
</form>

<p class="muted registry-count"><?= $total ?> booking(s)<?= $q !== '' || $status !== '' ? ' match' : '' ?><?= $pages > 1 ? " — page $page of $pages" : '' ?></p>

<?php if (!$rows): ?>
  <div class="card empty-note"><?= $total === 0 && $q === '' && $status === '' ? 'No bookings yet. Click “+ New Booking” to start the register.' : 'No bookings match.' ?></div>
<?php else: ?>
<div class="card table-scroll">
<table class="registry">
  <thead><tr><th>SLA No.</th><th>Status</th><th>Client</th><th>Vendor firm</th><th>Event date</th><th>Venue</th>
    <th class="num">Net amount</th><th class="num">Paid</th><th class="num">Balance</th><th></th></tr></thead>
  <tbody>
<?php foreach ($rows as $b): ?>
    <tr>
      <td class="id-cell"><?= h(format_document_number($b['unique_id'], 'SLA', (int) $b['revision'])) ?></td>
      <td><span class="badge status-<?= h($b['status']) ?>"><?= h(strtoupper($b['status'])) ?></span></td>
      <td><?= h($b['client_name']) ?></td>
      <td><?= h($b['firm_name']) ?></td>
      <td><?= $b['event_date'] ? h(date('d M Y', strtotime($b['event_date']))) . '<div class="hint">' . h(date('l', strtotime($b['event_date']))) . '</div>' : '—' ?></td>
      <td><?= h($b['venue'] ?? '—') ?></td>
      <td class="num"><?= h(rs($b['grand_total'])) ?></td>
      <td class="num"><?= h(rs($b['paid_total'])) ?></td>
      <td class="num"><?= $b['status'] === 'cancelled' ? '<span class="hint">retained</span>' : h(rs($b['balance'])) ?></td>
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
