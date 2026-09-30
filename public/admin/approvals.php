<?php
/**
 * Approvals: everything waiting on an admin decision, in one place.
 *
 * Two queues that are otherwise on different pages — vendor accounts asking to join, and draft
 * bookings asking to be confirmed. Vendor accounts are decided here (the same rules as the Vendors
 * page, via apply_vendor_action). Bookings are not: confirming needs the booking's version, the
 * venue clash check and an optional override reason, so this page says what is holding each one up
 * and links to the booking, where those safeguards live.
 */
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/lifecycle.php';
require_once APP_ROOT . '/app/admin_data.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

$admin = require_admin();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = apply_vendor_action($pdo, $admin, (int) ($_POST['vendor_id'] ?? 0), (string) ($_POST['action'] ?? ''));
    flash($message[0], $message[1]);
    redirect('admin/approvals.php');
}

$pendingVendors = pending_vendors($pdo);

$drafts = $pdo->query("SELECT b.*, u.firm_name AS vendor_firm, u.username AS vendor_username,
                              COALESCE(v.name, b.venue_other) AS venue_name
                         FROM bookings b
                         LEFT JOIN users u ON u.id = b.vendor_id
                         LEFT JOIN venues v ON v.id = b.venue_id
                        WHERE b.status = 'draft'
                        ORDER BY b.event_date IS NULL, b.event_date, b.id")->fetchAll();

// Why each draft can't be confirmed yet: missing requirements first, then venue clashes.
$blockers = [];
foreach ($drafts as $d) {
    $id = (int) $d['id'];
    $blockers[$id] = confirm_requirement_problems($pdo, $d);
    if ($d['vendor_id'] === null) {
        $blockers[$id][] = 'a vendor';
    }
    foreach (venue_clash_messages(
        venue_clashes($pdo, $id, $d['venue_id'] === null ? null : (int) $d['venue_id'], $d['event_date']),
        $d['event_date'], true) as $clash) {
        $blockers[$id][] = $clash;
    }
}
$readyCount = count(array_filter($blockers, static fn($b) => $b === []));

$pageTitle = 'Approvals';
$activeTab = 'approvals';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Approvals</h2>
  <span class="muted"><?= count($pendingVendors) ?> account(s) and <?= count($drafts) ?> draft booking(s) waiting</span>
</div>

<div class="card pad">
  <h3>VENDOR ACCOUNTS AWAITING APPROVAL</h3>
<?php if (!$pendingVendors): ?>
  <p class="muted">No accounts are waiting. New sign-ups appear here.</p>
<?php else: ?>
  <p class="muted">Approving an account lets that vendor sign in and raise bookings. Rejecting it disables the account;
    nothing is deleted, and it can be re-enabled later from the <a href="<?= h(url('admin/vendors.php')) ?>">Vendors</a> page.</p>
  <table class="registry">
    <thead><tr><th>Firm</th><th>Username</th><th>Contact person</th><th>Contact</th><th>Requested</th><th class="actions">Decision</th></tr></thead>
    <tbody>
<?php foreach ($pendingVendors as $v): ?>
      <tr>
        <td><?= h($v['firm_name'] ?? '—') ?></td>
        <td><?= h($v['username']) ?></td>
        <td><?= h($v['rep_name'] ?? $v['name'] ?? '—') ?></td>
        <td><?= h($v['contact'] ?? '—') ?></td>
        <td><?= h(date('d M Y', strtotime((string) $v['created_at']))) ?></td>
        <td class="actions">
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="vendor_id" value="<?= (int) $v['id'] ?>">
            <button class="rowbtn" name="action" value="approve">Approve</button>
          </form>
          <form method="post" class="inline" data-confirm="Reject “<?= h($v['username']) ?>”? The account is disabled, not deleted.">
            <?= csrf_field() ?>
            <input type="hidden" name="vendor_id" value="<?= (int) $v['id'] ?>">
            <button class="rowbtn del" name="action" value="disable">Reject</button>
          </form>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<div class="card pad">
  <h3>BOOKINGS AWAITING CONFIRMATION</h3>
<?php if (!$drafts): ?>
  <p class="muted">No drafts are waiting. Every booking is confirmed, completed or cancelled.</p>
<?php else: ?>
  <p class="muted"><?= $readyCount ?> of <?= count($drafts) ?> can be confirmed now. Open a booking to confirm it — the
    confirm step re-checks the venue at that moment, so nothing here can be confirmed behind your back.</p>
  <table class="registry">
    <thead><tr><th>SLA no.</th><th>Client</th><th>Vendor</th><th>Event date</th><th>Venue</th><th class="num">Net</th><th>Holding it up</th><th class="actions"></th></tr></thead>
    <tbody>
<?php foreach ($drafts as $d): $id = (int) $d['id']; $problems = $blockers[$id]; ?>
      <tr>
        <td class="id-cell"><?= h(format_document_number($d['unique_id'], 'SLA', (int) $d['revision'])) ?></td>
        <td><?= h($d['client_name'] ?? '—') ?></td>
        <td><?= h($d['vendor_firm'] ?? $d['vendor_username'] ?? '—') ?></td>
        <td><?= $d['event_date'] ? h(date('d M Y', strtotime($d['event_date']))) : '<span class="hint">not set</span>' ?></td>
        <td><?= h($d['venue_name'] ?? '—') ?></td>
        <td class="num"><?= h(rs($d['grand_total'])) ?></td>
        <td>
<?php if (!$problems): ?>
          <span class="badge state-pass">READY</span>
<?php else: ?>
          <ul class="blocker-list">
<?php foreach ($problems as $problem): ?>
            <li><?= h($problem) ?></li>
<?php endforeach; ?>
          </ul>
<?php endif; ?>
        </td>
        <td class="actions"><a class="rowbtn" href="<?= h(url('booking/form.php?id=' . $id)) ?>">Open</a></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
