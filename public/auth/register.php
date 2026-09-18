<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

if (current_user() !== null) {
    redirect('index.php');
}

const REGISTRATIONS_PER_IP_PER_HOUR = 5;

$in = ['firm_name' => '', 'rep_name' => '', 'contact' => '', 'username' => ''];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($in as $key => $_) {
        $in[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $in['username'] = normalize_username($in['username']);
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['password_confirm'] ?? '');

    if ($in['firm_name'] === '' || mb_strlen($in['firm_name']) > 150) {
        $errors[] = 'Enter your firm name (at most 150 characters).';
    }
    if ($in['rep_name'] === '' || mb_strlen($in['rep_name']) > 100) {
        $errors[] = 'Enter the representative\'s name (at most 100 characters).';
    }
    if ($in['contact'] === '' || mb_strlen($in['contact']) > 50) {
        $errors[] = 'Enter a contact number (at most 50 characters).';
    }
    if (!is_valid_username($in['username'])) {
        $errors[] = 'Choose a username of 3–50 characters: letters, digits, dot, underscore or hyphen.';
    }
    $errors = array_merge($errors, password_problems($password, $confirm, $in['username']));

    $pdo = db();
    $st = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE action = 'vendor_register' AND ip = ? AND created_at > NOW() - INTERVAL 1 HOUR");
    $st->execute([client_ip()]);
    if ((int) $st->fetchColumn() >= REGISTRATIONS_PER_IP_PER_HOUR) {
        $errors = ['Too many accounts have been created from this network recently. Please try again later.'];
    }

    if (!$errors) {
        try {
            db_transaction(static function (PDO $pdo) use ($in, $password) {
                $pdo->prepare("INSERT INTO users (username, password_hash, role, name, firm_name, rep_name, contact, status)
                               VALUES (?, ?, 'vendor', ?, ?, ?, ?, 'pending')")
                    ->execute([$in['username'], password_hash($password, PASSWORD_DEFAULT), $in['rep_name'],
                        $in['firm_name'], $in['rep_name'], $in['contact']]);
                $id = (int) $pdo->lastInsertId();
                audit($pdo, 'vendor_register', $id, null,
                    ['username' => $in['username'], 'firm_name' => $in['firm_name'], 'rep_name' => $in['rep_name'], 'contact' => $in['contact']]);
            });
            flash('ok', 'Your vendor account has been created and is waiting for approval by AO Mess. You can sign in once it has been approved.');
            redirect('auth/login.php');
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) { // 1062 = duplicate key: the username is taken
                throw $e;
            }
            $errors[] = 'That username is already taken. Please choose another.';
        }
    }
}

$pageTitle = 'Create vendor account';
$bare = true;
require APP_ROOT . '/app/views/layout_top.php';
?>
<div class="auth-card">
  <img src="<?= h(url('assets/img/logo.png')) ?>" alt="ASK Organizers" class="login-logo">
  <h2>Create a vendor account</h2>
  <p class="login-sub">AO Mess will review and approve your account before you can sign in.</p>

<?php if ($errors): ?>
  <ul class="errors"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

  <form method="post" action="<?= h(url('auth/register.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="field"><label for="firm_name">Firm name</label>
      <input type="text" id="firm_name" name="firm_name" value="<?= h($in['firm_name']) ?>" maxlength="150" required></div>
    <div class="field"><label for="rep_name">Representative name</label>
      <input type="text" id="rep_name" name="rep_name" value="<?= h($in['rep_name']) ?>" maxlength="100" required></div>
    <div class="field"><label for="contact">Contact number</label>
      <input type="text" id="contact" name="contact" value="<?= h($in['contact']) ?>" maxlength="50" required></div>
    <div class="field"><label for="username">Choose a username</label>
      <input type="text" id="username" name="username" value="<?= h($in['username']) ?>" maxlength="50" autocomplete="username" required>
      <span class="hint">3–50 characters: letters, digits, . _ -</span></div>
    <div class="field"><label for="password">Choose a password</label>
      <input type="password" id="password" name="password" autocomplete="new-password" required>
      <span class="hint">At least <?= PASSWORD_MIN_CHARS ?> characters</span></div>
    <div class="field"><label for="password_confirm">Repeat the password</label>
      <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required></div>
    <button type="submit" class="btn primary loginbtn">Create Vendor Account</button>
  </form>

  <p class="login-switch">Already have an account? <a href="<?= h(url('auth/login.php')) ?>">Sign in</a></p>
</div>
<?php require APP_ROOT . '/app/views/layout_bottom.php';
