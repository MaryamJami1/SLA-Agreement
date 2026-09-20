<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/attachments.php';

$user = require_login();
$pdo = db();
$id = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;

$st = $pdo->prepare('SELECT * FROM attachments WHERE id = ?');
$st->execute([$id]);
$file = $st->fetch();
if (!$file) {
    not_found();
}
// The booking decides who may see the file; a vendor asking for someone else's gets the same 404.
load_booking_for_user($pdo, (int) $file['booking_id'], $user, 'view');
if ($file['voided_at'] !== null && $user['role'] !== 'admin') {
    not_found();
}
$path = attachment_path($file['stored_name']);
if (!is_file($path)) {
    app_log('download: missing file for attachment ' . $id . ' (' . $file['stored_name'] . ')');
    not_found();
}

// Serve with the stored type (never guessed from the name) and no sniffing.
header('Content-Type: ' . $file['mime']);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . (isset(UPLOAD_TYPES[$file['mime']]) ? 'inline' : 'attachment')
    . '; filename="' . str_replace('"', '', $file['original_name']) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($path);
