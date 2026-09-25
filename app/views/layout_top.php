<?php
/**
 * Page header. Set before including:
 *   $pageTitle  string  shown in the browser tab
 *   $activeTab  string  optional: which nav tab is highlighted ('home', 'vendors', …)
 *   $bare       bool    optional: sign-in style page (centred card, no app bar)
 *
 * The page is wrapped in a single-row table with the letterhead in <thead>, so the letterhead
 * repeats on every printed page. The letterhead is print-only: on screen the application
 * header takes its place, and each page supplies its own title.
 */
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'AO Mess';
$activeTab = $activeTab ?? '';
$bare = $bare ?? false;
$bodyClass = $bodyClass ?? '';
$viewer = current_user();
$flashes = session_status() === PHP_SESSION_ACTIVE ? take_flashes() : [];

/**
 * How many items are sitting on the Approvals page. One cheap count, admins only, so the tab can
 * say there is something to do without the admin having to go and look.
 */
$approvalCount = 0;

$tabs = [];
if ($viewer !== null && (int) $viewer['must_change_password'] !== 1) {
    $tabs['registry'] = ['booking/list.php', 'Registry'];
    $tabs['calendar'] = ['booking/calendar.php', 'Calendar'];
    $tabs['new'] = ['booking/form.php', 'New Booking'];
    if ($viewer['role'] === 'admin') {
        $tabs['approvals'] = ['admin/approvals.php', 'Approvals'];
        $tabs['vendors'] = ['admin/vendors.php', 'Vendors'];
        $tabs['catalog'] = ['admin/catalog.php', 'Catalog'];
        $tabs['venues'] = ['admin/venues.php', 'Venues'];
        $tabs['preflight'] = ['admin/preflight.php', 'Checks'];
        try {
            $approvalCount = (int) db()->query(
                "SELECT (SELECT COUNT(*) FROM users WHERE role = 'vendor' AND status = 'pending')
                      + (SELECT COUNT(*) FROM bookings WHERE status = 'draft')")->fetchColumn();
        } catch (Throwable $e) {
            // A header must never take the page down; the Approvals page itself will report the fault.
            $approvalCount = 0;
        }
    }
}

/** Two-letter monogram for the account chip. */
$initials = '';
if ($viewer !== null) {
    foreach (preg_split('/\s+/', trim((string) $viewer['name'])) ?: [] as $part) {
        if ($part !== '' && mb_strlen($initials) < 2) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
    $initials = $initials !== '' ? $initials : 'U';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> — AO Mess</title>
<meta name="theme-color" content="#12332C">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600&display=swap">
<link rel="stylesheet" href="<?= h(url('assets/css/style.css')) ?>">
<link rel="icon" type="image/png" href="<?= h(url('assets/img/logo.png')) ?>">
<script src="<?= h(url('assets/js/app.js')) ?>" defer></script>
</head>
<body class="<?= h(trim(($bare ? 'bare ' : '') . ($bodyClass ?? ''))) ?>">
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
<div class="letterhead-rule">
  <span class="letterhead-title">AO Mess — Catering &amp; Decoration SLA Register</span>
  <span class="schedule-tag">SCHEDULE&#8209;A</span>
</div>
</td></tr>
</thead>
<tbody>
<tr><td>
<header class="appbar">
  <div class="appbar-inner">
    <a class="brand" href="<?= h(url($viewer !== null ? 'booking/list.php' : 'index.php')) ?>">
      <img src="<?= h(url('assets/img/logo.png')) ?>" alt="" class="brand-mark">
      <span class="brand-text">
        <strong>AO&nbsp;Mess</strong>
        <span>SLA Register</span>
      </span>
    </a>
<?php if ($tabs): ?>
    <nav class="appnav" aria-label="Sections">
<?php foreach ($tabs as $key => [$href, $label]): ?>
      <a href="<?= h(url($href)) ?>"<?= $key === $activeTab ? ' class="active" aria-current="page"' : '' ?>><?= h($label) ?><?php
        if ($key === 'approvals' && $approvalCount > 0): ?><span class="nav-count" aria-label="<?= $approvalCount ?> waiting"><?= $approvalCount ?></span><?php endif; ?></a>
<?php endforeach; ?>
    </nav>
<?php endif; ?>
<?php if ($viewer !== null): ?>
    <div class="appbar-user">
      <span class="avatar" aria-hidden="true"><?= h($initials) ?></span>
      <span class="who">
        <span class="who-name"><?= h($viewer['name']) ?></span>
        <span class="role-tag"><?= $viewer['role'] === 'admin' ? 'Admin' : 'Vendor' ?></span>
      </span>
<?php if ((int) $viewer['must_change_password'] !== 1): ?>
      <a class="navlink" href="<?= h(url('auth/change_password.php')) ?>">Password</a>
<?php endif; ?>
      <form method="post" action="<?= h(url('auth/logout.php')) ?>" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="linkbtn">Sign out</button>
      </form>
    </div>
<?php endif; ?>
  </div>
</header>

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
