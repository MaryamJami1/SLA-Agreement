<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

$user = require_login();

// Until the registry exists (Phase 4), the home page lists the most recent bookings.
$pdo = db();
$pendingVendors = $user['role'] === 'admin'
    ? (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'vendor' AND status = 'pending'")->fetchColumn()
    : 0;

[$scope, $params] = booking_scope_sql($user);
$st = $pdo->prepare("SELECT b.id, b.unique_id, b.revision, b.status, b.client_name, b.firm_name, b.event_date, b.grand_total,
                            COALESCE(v.name, b.venue_other) AS venue
                       FROM bookings b LEFT JOIN venues v ON v.id = b.venue_id
                      WHERE $scope
                      ORDER BY COALESCE(b.updated_at, b.created_at) DESC, b.id DESC
                      LIMIT 15");
$st->execute($params);
$recent = $st->fetchAll();

$pageTitle = 'Home';
$activeTab = 'home';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Welcome, <?= h($user['name']) ?></h2>
  <a class="btn primary" href="<?= h(url('booking/form.php')) ?>">+ New Booking</a>
</div>

<?php if ($user['role'] === 'admin' && $pendingVendors > 0): ?>
<div class="flash info"><strong><?= $pendingVendors ?></strong> vendor account(s) are waiting for approval.
  <a href="<?= h(url('admin/vendors.php')) ?>">Review vendors</a></div>
<?php endif; ?>

<h3 class="list-title">Recently updated bookings</h3>
<?php if (!$recent): ?>
  <div class="card empty-note">No bookings yet. Click “+ New Booking” to start the register.</div>
<?php else: ?>
<div class="table-scroll">
<table class="registry">
  <thead><tr><th>SLA No.</th><th>Status</th><th>Client</th><th>Vendor firm</th><th>Event date</th><th>Venue</th><th class="num">Net amount</th><th></th></tr></thead>
  <tbody>
<?php foreach ($recent as $b): ?>
    <tr>
      <td class="id-cell"><?= h(format_document_number($b['unique_id'], 'SLA', (int) $b['revision'])) ?></td>
      <td><span class="badge status-<?= h($b['status']) ?>"><?= h(strtoupper($b['status'])) ?></span></td>
      <td><?= h($b['client_name']) ?></td>
      <td><?= h($b['firm_name']) ?></td>
      <td><?= $b['event_date'] ? h(date('d M Y', strtotime($b['event_date']))) : '—' ?></td>
      <td><?= h($b['venue'] ?? '—') ?></td>
      <td class="num"><?= h(rs($b['grand_total'])) ?></td>
      <td class="actions"><a class="rowbtn" href="<?= h(url('booking/form.php?id=' . (int) $b['id'])) ?>">Open</a></td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
