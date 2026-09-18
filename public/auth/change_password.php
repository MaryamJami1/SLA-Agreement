<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

$user = require_login();
$forced = (int) $user['must_change_password'] === 1;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['new_password_confirm'] ?? '');

    if (!password_verify($current, $user['password_hash'])) {
        $errors[] = 'The current password is not correct.';
    } else {
        $errors = password_problems($new, $confirm, $user['username'], $current);
    }

    if (!$errors) {
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        db_transaction(static function (PDO $pdo) use ($user, $newHash, $forced) {
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
                ->execute([$newHash, $user['id']]);
            audit($pdo, 'password_change', (int) $user['id'], null, ['forced' => $forced]);
        });
        session_regenerate_id(true);
        // The old device cookie was signed with the old hash and is now invalid; issue a new one.
        set_device_cookie(['id' => $user['id'], 'password_hash' => $newHash]);
        flash('ok', 'Your password has been changed.');
        redirect('index.php');
    }
}

$pageTitle = 'Change password';
$bare = $forced;
$activeTab = '';
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="<?= $forced ? 'auth-card' : 'card pad narrow' ?>">
<?php if ($forced): ?>
  <img src="<?= h(url('assets/img/logo.png')) ?>" alt="ASK Organizers" class="login-logo">
  <h2>Set a new password</h2>
  <p class="login-sub">Your password was set by an administrator. Choose your own password to continue.</p>
<?php else: ?>
  <h3>CHANGE PASSWORD</h3>
<?php endif; ?>

<?php if ($errors): ?>
  <ul class="errors"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

  <form method="post" action="<?= h(url('auth/change_password.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="current_password"><?= $forced ? 'Current (temporary) password' : 'Current password' ?></label>
      <input type="password" id="current_password" name="current_password" autocomplete="current-password" required autofocus></div>
    <div class="field"><label for="new_password">New password</label>
      <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
      <span class="hint">At least <?= PASSWORD_MIN_CHARS ?> characters</span></div>
    <div class="field"><label for="new_password_confirm">Repeat the new password</label>
      <input type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password" required></div>
    <button type="submit" class="btn primary <?= $forced ? 'loginbtn' : '' ?>">Change password</button>
  </form>

<?php if ($forced): ?>
  <form method="post" action="<?= h(url('auth/logout.php')) ?>" class="login-switch">
    <?= csrf_field() ?>
    <button type="submit" class="linkbtn signout-dark">Sign out</button>
  </form>
<?php endif; ?>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
