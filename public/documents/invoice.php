<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/payments.php';
require_once APP_ROOT . '/app/documents.php';
require_once APP_ROOT . '/app/views/form_helpers.php';

$viewer = require_admin();   // printed documents are AO Mess's: vendors get a 404
$pdo = db();
$booking = load_booking_for_user($pdo, ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0, $viewer, 'view');
$d = document_data($pdo, $booking);

$pageTitle = 'Invoice ' . $booking['unique_id'];
$activeTab = '';
$bodyClass = 'document';
require APP_ROOT . '/app/views/layout_top.php';
require APP_ROOT . '/app/views/doc_invoice.php';
require APP_ROOT . '/app/views/layout_bottom.php';
