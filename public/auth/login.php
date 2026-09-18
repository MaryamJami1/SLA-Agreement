<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

if (current_user() !== null) {
    redirect('index.php');
}

$error = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $result = attempt_login(db(), $username, $password, (string) client_ip(), $_COOKIE[DEVICE_COOKIE_NAME] ?? null);
    if ($result['user'] !== null) {
        begin_login_session($result['user']);
        set_device_cookie($result['user']);
        redirect((int) $result['user']['must_change_password'] === 1 ? 'auth/change_password.php' : 'index.php');
    }
    $error = $result['error'];
}

$pageTitle = 'Sign in';
$bare = true;
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="auth-card">
  <img src="<?= h(url('assets/img/logo.png')) ?>" alt="ASK Organizers" class="login-logo">
  <h2>ASK Organizers Portal</h2>
  <p class="login-sub">Sign in to the AO Mess SLA register.</p>

  <form method="post" action="<?= h(url('auth/login.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="username">Username</label>
      <input type="text" id="username" name="username" value="<?= h($username) ?>" autocomplete="username" autofocus required></div>
    <div class="field"><label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required></div>
    <button type="submit" class="btn primary loginbtn">Sign In</button>
  </form>

  <p class="login-error"><?= h($error) ?></p>
  <p class="login-switch">New vendor? <a href="<?= h(url('auth/register.php')) ?>">Create an account</a></p>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
