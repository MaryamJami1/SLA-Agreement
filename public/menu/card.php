<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/attachments.php';

// A package's menu card. Packages are offered to every signed-in user, so any of them may see it.
require_login();
$id = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;
$st = db()->prepare('SELECT name, card_stored_name, card_mime FROM menu_packages WHERE id = ?');
$st->execute([$id]);
$package = $st->fetch();
if (!$package || $package['card_stored_name'] === null || !isset(UPLOAD_TYPES[$package['card_mime']])) {
    not_found();
}
$path = attachment_path($package['card_stored_name']);
if (!is_file($path)) {
    app_log('menu card: missing file for package ' . $id . ' (' . $package['card_stored_name'] . ')');
    not_found();
}

header('Content-Type: ' . $package['card_mime']);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $package['name']) . '.' . UPLOAD_TYPES[$package['card_mime']] . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
