<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

$admin = require_admin();
$pdo = db();

// Allowed status changes: action => [statuses it can start from, new status, audit action]
$transitions = [
    'approve' => [['pending', 'disabled'], 'active', 'vendor_approve'],
    'disable' => [['pending', 'active'], 'disabled', 'vendor_disable'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vendorId = (int) ($_POST['vendor_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');

    $message = db_transaction(static function (PDO $pdo) use ($vendorId, $action, $transitions, $admin) {
        $st = $pdo->prepare("SELECT id, username, status FROM users WHERE id = ? AND role = 'vendor' FOR UPDATE");
        $st->execute([$vendorId]);
        $vendor = $st->fetch();
        if (!$vendor) {
            return ['error', 'That vendor account no longer exists.'];
        }

        if (isset($transitions[$action])) {
            [$from, $to, $auditAction] = $transitions[$action];
            if (!in_array($vendor['status'], $from, true)) {
                return ['error', "“{$vendor['username']}” is {$vendor['status']}; that action doesn't apply."];
            }
            $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$to, $vendorId]);
            audit($pdo, $auditAction, (int) $admin['id'], null,
                ['vendor_id' => $vendorId, 'username' => $vendor['username'], 'status' => [$vendor['status'], $to]]);
            return ['ok', "“{$vendor['username']}” is now $to."];
        }

        if ($action === 'reset') {
            $temp = generate_temp_password();
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
                ->execute([password_hash($temp, PASSWORD_DEFAULT), $vendorId]);
            audit($pdo, 'password_reset', (int) $admin['id'], null, ['vendor_id' => $vendorId, 'username' => $vendor['username']]);
            return ['ok', "Password reset for “{$vendor['username']}”.", ['username' => $vendor['username'], 'password' => $temp]];
        }

        return ['error', 'Unknown action.'];
    });
    flash($message[0], $message[1]);
    if (isset($message[2])) {
        // Committed: show the temporary password to the admin once. Never logged or put in a URL.
        $_SESSION['temp_password'] = $message[2];
    }
    redirect('admin/vendors.php');
}

$tempPassword = $_SESSION['temp_password'] ?? null;
unset($_SESSION['temp_password']);

$vendors = $pdo->query("SELECT id, username, name, firm_name, rep_name, contact, status, must_change_password, last_login_at, created_at
                          FROM users WHERE role = 'vendor'
                         ORDER BY FIELD(status, 'pending', 'active', 'disabled'), firm_name, username")->fetchAll();
$pendingCount = count(array_filter($vendors, static fn($v) => $v['status'] === 'pending'));

$pageTitle = 'Vendors';
$activeTab = 'vendors';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>Vendor accounts</h2>
  <span class="muted"><?= count($vendors) ?> vendor(s)<?= $pendingCount ? ", $pendingCount waiting for approval" : '' ?></span>
</div>

<?php if ($tempPassword): ?>
<div class="card pad">
  <h3>TEMPORARY PASSWORD</h3>
  <p>Give this password to <strong><?= h($tempPassword['username']) ?></strong>. It is shown only once.
     They will have to choose their own password when they next sign in.</p>
  <div class="secret-box"><?= h($tempPassword['password']) ?></div>
</div>
<?php endif; ?>

<?php if (!$vendors): ?>
  <div class="card empty-note">No vendor accounts yet. Vendors create one from the sign-in page.</div>
<?php else: ?>
<div class="table-scroll">
<table class="registry">
  <thead><tr>
    <th>Firm</th><th>Representative</th><th>Contact</th><th>Username</th><th>Status</th><th>Registered</th><th>Last sign-in</th><th></th>
  </tr></thead>
  <tbody>
<?php foreach ($vendors as $v): ?>
    <tr>
      <td><?= h($v['firm_name']) ?></td>
      <td><?= h($v['rep_name']) ?></td>
      <td><?= h($v['contact']) ?></td>
      <td class="id-cell"><?= h($v['username']) ?></td>
      <td><span class="badge <?= h($v['status']) ?>"><?= h(strtoupper($v['status'])) ?></span>
        <?php if ((int) $v['must_change_password'] === 1): ?><div class="hint">must set password</div><?php endif; ?></td>
      <td><?= h(date('d M Y', strtotime($v['created_at']))) ?></td>
      <td><?= $v['last_login_at'] ? h(date('d M Y H:i', strtotime($v['last_login_at']))) : '<span class="muted">never</span>' ?></td>
      <td class="actions">
<?php if (in_array($v['status'], $transitions['approve'][0], true)): ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="vendor_id" value="<?= (int) $v['id'] ?>">
          <button class="rowbtn" name="action" value="approve"><?= $v['status'] === 'pending' ? 'Approve' : 'Re-enable' ?></button></form>
<?php endif; ?>
<?php if (in_array($v['status'], $transitions['disable'][0], true)): ?>
        <form method="post" data-confirm="Disable <?= h($v['username']) ?>? They will be signed out and unable to sign in.">
          <?= csrf_field() ?><input type="hidden" name="vendor_id" value="<?= (int) $v['id'] ?>">
          <button class="rowbtn del" name="action" value="disable"><?= $v['status'] === 'pending' ? 'Reject' : 'Disable' ?></button></form>
<?php endif; ?>
        <form method="post" data-confirm="Reset the password for <?= h($v['username']) ?>? A temporary password will be shown once.">
          <?= csrf_field() ?><input type="hidden" name="vendor_id" value="<?= (int) $v['id'] ?>">
          <button class="rowbtn" name="action" value="reset">Reset password</button></form>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
