<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/views/form_helpers.php';
require_once APP_ROOT . '/app/lifecycle.php';

require_post();
$viewer = require_login();
$pdo = db();

$id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
$version = ctype_digit((string) ($_POST['version'] ?? '')) ? (int) $_POST['version'] : null;

$booking = null;
if ($id !== null) {
    // Vendors: own drafts only. Admin: drafts, and confirmed bookings (direct edits / amendments).
    $booking = load_booking_for_user($pdo, $id, $viewer, 'edit');
}
$confirmed = $booking !== null && $booking['status'] === 'confirmed';

$formLines = booking_form_lines($pdo, $id);
$parsed = parse_booking_input($pdo, $_POST, $viewer, $booking, $formLines);
$errors = $parsed['errors'];
$conflict = false;

if (!$errors) {
    try {
        if ($confirmed) {
            $saved = save_booking_confirmed($pdo, $viewer, $id, (int) $version, $parsed['fields'], $parsed['lines'],
                $_POST['amend_reason'] ?? '', $_POST['override_reason'] ?? '');
            flash('ok', !$saved['changed'] ? 'No changes to save.'
                : ($saved['amended']
                    ? "{$saved['unique_id']} amended: it is now Rev {$saved['revision']}. The signatures were cleared; the amended agreement must be signed again."
                    : "Saved {$saved['unique_id']} (no change to the agreed terms)."));
            redirect('booking/form.php?id=' . $saved['id']);
        }
        $saved = save_booking_draft($pdo, $viewer, $id, $version, $parsed['fields'], $parsed['lines']);
        flash('ok', ($id === null ? 'Booking created: ' : 'Saved ') . $saved['unique_id'] . '.');
        // Post/Redirect/Get: a refresh can't submit (or create) the booking twice.
        redirect('booking/form.php?id=' . $saved['id']);
    } catch (BookingValidationError $e) {
        $errors = $e->errors;
    } catch (BookingConflict $e) {
        $conflict = true;
        $errors = ['conflict' => 'Changed by someone else.'];
    }
}

// Not saved: show the form again with exactly what was typed.
http_response_code($conflict ? 409 : 422);
$values = [];
foreach (array_keys(booking_field_specs()) as $name) {
    $values[$name] = is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
}
$values['vendor_id'] = is_string($_POST['vendor_id'] ?? null) ? $_POST['vendor_id'] : ($booking['vendor_id'] ?? '');
$values['venue_id'] = is_string($_POST['venue_id'] ?? null) && $_POST['venue_id'] !== 'other' ? $_POST['venue_id'] : '';
$values['venue_other'] = ($_POST['venue_id'] ?? '') === 'other' ? (string) ($_POST['venue_other'] ?? '') : '';
$values['version'] = $version;
$values['amend_reason'] = is_string($_POST['amend_reason'] ?? null) ? $_POST['amend_reason'] : '';
$values['override_reason'] = is_string($_POST['override_reason'] ?? null) ? $_POST['override_reason'] : '';
if ($viewer['role'] === 'vendor') {
    $values['firm_name'] = $viewer['firm_name'];
    $values['rep_name'] = $viewer['rep_name'];
    $values['rep_contact'] = $viewer['contact'];
}

$postedLines = is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [];
$lineInput = [];
foreach ($formLines as $key => $l) {
    $in = is_array($postedLines[$key] ?? null) ? $postedLines[$key] : [];
    $lineInput[$key] = [
        'selected' => isset($in['selected']),
        'rate'     => is_string($in['rate'] ?? null) ? $in['rate'] : '',
        'qty'      => is_string($in['qty'] ?? null) ? $in['qty'] : '',
        'notes'    => is_string($in['notes'] ?? null) ? $in['notes'] : '',
    ];
}

$ctx = ['values' => $values, 'errors' => $conflict ? [] : $errors, 'readonly' => false];
$vendors = $viewer['role'] === 'admin' ? active_vendors($pdo) : [];
$venues = venues_for_form($pdo, $booking && $booking['venue_id'] !== null ? (int) $booking['venue_id'] : null);
$clashMessages = [];

$pageTitle = $booking ? $booking['unique_id'] : 'New booking';
$activeTab = $booking ? '' : 'new';
require APP_ROOT . '/app/views/layout_top.php';
require APP_ROOT . '/app/views/booking_form.php';
require APP_ROOT . '/app/views/layout_bottom.php';
