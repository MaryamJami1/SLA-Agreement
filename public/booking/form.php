<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/views/form_helpers.php';
require_once APP_ROOT . '/app/lifecycle.php';

$viewer = require_login();
$pdo = db();

$booking = null;
if (isset($_GET['id'])) {
    $booking = load_booking_for_user($pdo, (int) $_GET['id'], $viewer, 'view');
}
// Vendors edit their own drafts; the admin edits drafts and confirmed bookings (direct edits / amendments).
$editable = $booking === null || booking_allows($booking, $viewer, 'edit');

if ($booking) {
    $values = $booking;
} else {
    $values = ['agreement_place' => 'Karachi', 'due_on' => 'Event Day'];
    if ($viewer['role'] === 'vendor') {
        $values += ['firm_name' => $viewer['firm_name'], 'rep_name' => $viewer['rep_name'], 'rep_contact' => $viewer['contact']];
    }
}

$formLines = booking_form_lines($pdo, $booking ? (int) $booking['id'] : null);
if (!$editable) {
    // Read-only: show only what is on the booking, not catalog items that could be added.
    $formLines = array_filter($formLines, static fn($l) => $l['line_id'] !== null);
}
$lineInput = [];
foreach ($formLines as $key => $l) {
    $lineInput[$key] = ['selected' => $l['selected'], 'rate' => $l['rate'], 'qty' => $l['qty'], 'notes' => $l['notes']];
}

$ctx = ['values' => $values, 'errors' => [], 'readonly' => !$editable];
$vendors = $viewer['role'] === 'admin' ? active_vendors($pdo) : [];
$venues = venues_for_form($pdo, $booking ? ($booking['venue_id'] === null ? null : (int) $booking['venue_id']) : null);
$conflict = false;
$clashMessages = $booking ? venue_clash_messages(
    venue_clashes($pdo, (int) $booking['id'], $booking['venue_id'] === null ? null : (int) $booking['venue_id'], $booking['event_date']),
    $booking['event_date'], $viewer['role'] === 'admin') : [];

$pageTitle = $booking ? $booking['unique_id'] : 'New booking';
$activeTab = $booking ? '' : 'new';
require APP_ROOT . '/app/views/layout_top.php';
require APP_ROOT . '/app/views/booking_form.php';
if ($booking) {
    require APP_ROOT . '/app/views/booking_actions.php';
}
require APP_ROOT . '/app/views/layout_bottom.php';
