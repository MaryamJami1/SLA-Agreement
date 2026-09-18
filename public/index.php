<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$user = require_login();

// Until the registry exists (Phase 4), the home page is a simple overview.
$pdo = db();
$pendingVendors = $user['role'] === 'admin'
    ? (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'vendor' AND status = 'pending'")->fetchColumn()
    : 0;

$pageTitle = 'Home';
$activeTab = 'home';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head"><h2>Welcome, <?= h($user['name']) ?></h2></div>

<div class="card pad">
  <h3><?= $user['role'] === 'admin' ? 'ADMINISTRATOR' : 'VENDOR' ?></h3>
<?php if ($user['role'] === 'admin'): ?>
  <p>
<?php if ($pendingVendors > 0): ?>
    <strong><?= $pendingVendors ?></strong> vendor account(s) are waiting for approval.
    <a class="btn small" href="<?= h(url('admin/vendors.php')) ?>">Review vendors</a>
<?php else: ?>
    No vendor accounts are waiting for approval. <a href="<?= h(url('admin/vendors.php')) ?>">Manage vendors</a>
<?php endif; ?>
  </p>
<?php else: ?>
  <p>Signed in for <strong><?= h($user['firm_name']) ?></strong>.</p>
<?php endif; ?>
  <p class="muted">The booking form and registry are being built next.</p>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
