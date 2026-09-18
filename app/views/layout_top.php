<?php
/**
 * Page header. Set before including:
 *   $pageTitle  string  shown in the browser tab
 *   $activeTab  string  optional: which nav tab is highlighted ('home', 'vendors', …)
 *   $bare       bool    optional: sign-in style page (centred card, no masthead or nav)
 *
 * The page is wrapped in a single-row table with the letterhead in <thead>, so the letterhead
 * repeats on every printed page (ported from the mockup).
 */
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'AO Mess';
$activeTab = $activeTab ?? '';
$bare = $bare ?? false;
$viewer = current_user();
$flashes = session_status() === PHP_SESSION_ACTIVE ? take_flashes() : [];

$tabs = [];
if ($viewer !== null && (int) $viewer['must_change_password'] !== 1) {
    $tabs['home'] = ['index.php', 'Home'];
    $tabs['new'] = ['booking/form.php', 'New Booking'];
    if ($viewer['role'] === 'admin') {
        $tabs['vendors'] = ['admin/vendors.php', 'Vendors'];
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> — AO Mess</title>
<link rel="stylesheet" href="<?= h(url('assets/css/style.css')) ?>">
<link rel="icon" type="image/png" href="<?= h(url('assets/img/logo.png')) ?>">
<script src="<?= h(url('assets/js/app.js')) ?>" defer></script>
</head>
<body class="<?= $bare ? 'bare' : '' ?>">
<?php if ($bare): ?>
<div class="auth-page">
<?php foreach ($flashes as $f): ?>
  <div class="flash <?= h($f['type']) ?>"><?= h($f['message']) ?></div>
<?php endforeach; ?>
<?php else: ?>
<table class="page-table">
<thead>
<tr><td>
<div class="letterhead">
  <img src="<?= h(url('assets/img/logo.png')) ?>" alt="ASK Organizers" class="letterhead-logo">
  <div class="letterhead-meta">
    <div class="letterhead-line1">Event Planning &amp; Catering Coordination</div>
    <div class="letterhead-line2"><?= h(cfg('ORG_ADDRESS', 'Karachi, Pakistan')) ?></div>
<?php if (cfg('ORG_PHONE') || cfg('ORG_EMAIL')): ?>
    <div class="letterhead-line2"><?= h(trim(cfg('ORG_PHONE', '') . '  ' . cfg('ORG_EMAIL', ''))) ?></div>
<?php endif; ?>
  </div>
</div>
</td></tr>
</thead>
<tbody>
<tr><td>
<header class="masthead">
  <div class="crest-row">
    <h1>AO Mess — Catering &amp; Decoration SLA Register</h1>
    <div class="schedule-tag">SCHEDULE&#8209;A</div>
  </div>
  <div class="sub">Mandatory Service Level Agreement — digital register</div>
</header>

<nav class="tabs">
<?php foreach ($tabs as $key => [$href, $label]): ?>
  <a href="<?= h(url($href)) ?>" class="<?= $key === $activeTab ? 'active' : '' ?>"><?= h($label) ?></a>
<?php endforeach; ?>
  <div class="spacer"></div>
<?php if ($viewer !== null): ?>
  <div class="userbadge">
    <span><?= h($viewer['name']) ?></span>
    <span class="role-tag"><?= $viewer['role'] === 'admin' ? 'ADMIN' : 'VENDOR' ?></span>
<?php if ((int) $viewer['must_change_password'] !== 1): ?>
    <a class="navlink" href="<?= h(url('auth/change_password.php')) ?>">Password</a>
<?php endif; ?>
    <form method="post" action="<?= h(url('auth/logout.php')) ?>" class="inline">
      <?= csrf_field() ?>
      <button type="submit" class="linkbtn">Sign out</button>
    </form>
  </div>
<?php endif; ?>
</nav>

<main>
<?php if ($viewer !== null && $viewer['role'] === 'admin'):
    foreach (login_attack_alerts(db()) as $alert): ?>
  <div class="flash error">Security warning: <?= (int) $alert['failures'] ?> failed logins for
    “<?= h($alert['username']) ?>” from <?= (int) $alert['ips'] ?> IP address(es) in the last 15 minutes.
    Sign-ins for this account from new locations are being slowed down.</div>
<?php endforeach; endif; ?>
<?php foreach ($flashes as $f): ?>
  <div class="flash <?= h($f['type']) ?>"><?= h($f['message']) ?></div>
<?php endforeach; ?>
<?php endif; ?>
