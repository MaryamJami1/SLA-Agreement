<?php
/**
 * The event slots of one venue on one date, each marked available or booked, as JSON for the booking
 * form. GET venue_id, date (Y-m-d, optional), booking (the booking being edited, optional).
 *
 * Only availability is returned, never who holds a slot, so users may ask about any venue — just as
 * the calendar shows them which dates are taken. The save re-checks everything under a lock.
 */
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';

$viewer = require_login();
$pdo = db();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$venueId = ctype_digit((string) ($_GET['venue_id'] ?? '')) ? (int) $_GET['venue_id'] : 0;
$date = (string) ($_GET['date'] ?? '');
$d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$date = $d && $d->format('Y-m-d') === $date ? $date : null;

// The booking being edited: its own slot stays listed (even if since disabled) and doesn't count as
// taken. Only a booking this user may see is honoured.
$bookingId = null;
$keepSlotId = null;
if (ctype_digit((string) ($_GET['booking'] ?? ''))) {
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([(int) $_GET['booking']]);
    $b = $st->fetch();
    if ($b && booking_allows($b, $viewer, 'view')) {
        $bookingId = (int) $b['id'];
        if ($b['slot_id'] !== null && (int) $b['venue_id'] === $venueId) {
            $keepSlotId = (int) $b['slot_id'];
        }
    }
}

$st = $pdo->prepare('SELECT id FROM venues WHERE id = ?');
$st->execute([$venueId]);
if (!$st->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown venue.']);
    exit;
}

echo json_encode(['venue_id' => $venueId, 'date' => $date,
    'slots' => slot_availability($pdo, $venueId, $date, $bookingId, $keepSlotId)], JSON_UNESCAPED_UNICODE);
