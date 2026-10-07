<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/admin_data.php';

$admin = require_admin();
$pdo = db();

$transitions = USER_TRANSITIONS;

// The "Add user" form: kept filled in when it is refused, so the admin can correct it.
$new = ['firm_name' => '', 'rep_name' => '', 'contact' => '', 'username' => ''];
$createError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    foreach ($new as $key => $_) {
        $new[$key] = trim(is_string($_POST[$key] ?? null) ? $_POST[$key] : '');
    }
    try {
        $created = create_user($pdo, $admin, clean_user_input($new));
        flash('ok', "User “{$created['username']}” created. It is active and can sign in now.");
        // Show the temporary password to the admin once. Never logged or put in a URL.
        $_SESSION['temp_password'] = $created;
        redirect('admin/users.php');
    } catch (AdminRefused | TryAgainException $e) {
        $createError = $e->getMessage();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = apply_user_action($pdo, $admin, (int) ($_POST['user_id'] ?? 0), (string) ($_POST['action'] ?? ''));
    flash($message[0], $message[1]);
    if (isset($message[2])) {
        // Committed: show the temporary password to the admin once. Never logged or put in a URL.
        $_SESSION['temp_password'] = $message[2];
    }
    redirect('admin/users.php');
}

$tempPassword = $_SESSION['temp_password'] ?? null;
unset($_SESSION['temp_password']);

$users = $pdo->query("SELECT id, username, name, firm_name, rep_name, contact, status, must_change_password, last_login_at, created_at
                          FROM users WHERE role = 'user'
                         ORDER BY FIELD(status, 'pending', 'active', 'disabled'), firm_name, username")->fetchAll();
$pendingCount = count(array_filter($users, static fn($v) => $v['status'] === 'pending'));

$pageTitle = 'Users';
$activeTab = 'users';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="page-head">
  <h2>User accounts</h2>
  <span class="muted"><?= count($users) ?> user(s)<?= $pendingCount ? ", $pendingCount waiting for approval" : '' ?></span>
</div>

<?php if ($tempPassword): ?>
<div class="card pad">
  <h3>TEMPORARY PASSWORD</h3>
  <p>Give this password to <strong><?= h($tempPassword['username']) ?></strong>. It is shown only once.
     They will have to choose their own password when they next sign in.</p>
  <div class="secret-box"><?= h($tempPassword['password']) ?></div>
</div>
<?php endif; ?>

<div class="card pad" id="add-user">
  <h3>ADD A USER</h3>
  <p class="muted">The account is active straight away. A temporary password is shown once; the user chooses their own when they first sign in.</p>
<?php if ($createError): ?>
  <ul class="errors"><li><?= h($createError) ?></li></ul>
<?php endif; ?>
  <form method="post" action="<?= h(url('admin/users.php')) ?>" class="admin-row" novalidate>
    <?= csrf_field() ?>
    <div class="field grow"><label for="v_firm">Firm name</label>
      <input type="text" id="v_firm" name="firm_name" value="<?= h($new['firm_name']) ?>" maxlength="150" required></div>
    <div class="field grow"><label for="v_rep">Representative name</label>
      <input type="text" id="v_rep" name="rep_name" value="<?= h($new['rep_name']) ?>" maxlength="100" required></div>
    <div class="field"><label for="v_contact">Contact number</label>
      <input type="text" id="v_contact" name="contact" value="<?= h($new['contact']) ?>" maxlength="50" required></div>
    <div class="field"><label for="v_username">Username</label>
      <input type="text" id="v_username" name="username" value="<?= h($new['username']) ?>" maxlength="50" autocomplete="off" required></div>
    <button class="btn primary" name="action" value="create">Add user</button>
  </form>
</div>

<?php if (!$users): ?>
  <div class="card empty-note">No user accounts yet. Users can register from the sign-in page, or add one above.</div>
<?php else: ?>
<div class="table-scroll">
<table class="registry">
  <thead><tr>
    <th>Firm</th><th>Representative</th><th>Contact</th><th>Username</th><th>Status</th><th>Registered</th><th>Last sign-in</th><th></th>
  </tr></thead>
  <tbody>
<?php foreach ($users as $v): ?>
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
        <form method="post" action="<?= h(url('admin/users.php')) ?>"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $v['id'] ?>">
          <button class="rowbtn" name="action" value="approve"><?= $v['status'] === 'pending' ? 'Approve' : 'Re-enable' ?></button></form>
<?php endif; ?>
<?php if (in_array($v['status'], $transitions['disable'][0], true)): ?>
        <form method="post" action="<?= h(url('admin/users.php')) ?>" data-confirm="Disable <?= h($v['username']) ?>? They will be signed out and unable to sign in.">
          <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $v['id'] ?>">
          <button class="rowbtn del" name="action" value="disable"><?= $v['status'] === 'pending' ? 'Reject' : 'Disable' ?></button></form>
<?php endif; ?>
        <form method="post" action="<?= h(url('admin/users.php')) ?>" data-confirm="Delete <?= h($v['username']) ?> permanently? This can't be undone. A user with bookings can only be disabled.">
          <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $v['id'] ?>">
          <button class="rowbtn del" name="action" value="delete">Delete</button></form>
        <form method="post" action="<?= h(url('admin/users.php')) ?>" data-confirm="Reset the password for <?= h($v['username']) ?>? A temporary password will be shown once.">
          <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $v['id'] ?>">
          <button class="rowbtn" name="action" value="reset">Reset password</button></form>
      </td>
    </tr>
<?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
