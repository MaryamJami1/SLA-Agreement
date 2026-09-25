<?php
/**
 * Printable documents (plan Section 8, "Documents after an amendment", and Section 12):
 * the SLA agreement, the customer invoice and the vendor operations sheet.
 *
 * The pages print through the browser; there is no server-side PDF. The letterhead repeats on every
 * printed page because the layout wraps the page in a table with the letterhead in <thead>.
 */
declare(strict_types=1);

/**
 * Everything the three documents need for one booking.
 *
 * @return array{venue: string, venue_location: ?string, lines: array, charges: array, payments: array, paid: int, refunded: int,
 *               amendment: ?array, event_day: ?string}
 */
function document_data(PDO $pdo, array $booking): array
{
    $bookingId = (int) $booking['id'];

    $venue = $booking['venue_other'];
    if ($booking['venue_id'] !== null) {
        $st = $pdo->prepare('SELECT name FROM venues WHERE id = ?');
        $st->execute([(int) $booking['venue_id']]);
        $venue = $st->fetchColumn() ?: $venue;
    }

    $st = $pdo->prepare('SELECT * FROM booking_line_items WHERE booking_id = ? AND is_selected = 1 ORDER BY sort_order, id');
    $st->execute([$bookingId]);
    $lines = [];
    foreach ($st->fetchAll() as $row) {
        $lines[$row['section']][] = $row;
    }

    $payments = array_values(array_filter(booking_payments($pdo, $bookingId), static fn($p) => $p['voided_at'] === null));
    [$paid, $refunded] = payment_sums(array_map(
        static fn($p) => ['kind' => $p['kind'], 'amount' => decimal_to_paisa($p['amount']), 'voided' => false], $payments));

    // The booking's own copy of the location; the venue row is only a fallback for bookings
    // saved before venue locations existed.
    $venueLocation = trim((string) ($booking['venue_location'] ?? ''));
    if ($venueLocation === '' && $booking['venue_id'] !== null) {
        $st = $pdo->prepare('SELECT location FROM venues WHERE id = ?');
        $st->execute([(int) $booking['venue_id']]);
        $venueLocation = trim((string) ($st->fetchColumn() ?: ''));
    }

    return [
        'venue'     => $venue ?: null,
        'venue_location' => $venueLocation !== '' ? $venueLocation : null,
        'lines'     => $lines,
        'charges'   => $lines['charge'] ?? [],
        'payments'  => $payments,
        'paid'      => $paid,
        'refunded'  => $refunded,
        'amendment' => (int) $booking['revision'] > 0 ? last_amendment($pdo, $bookingId) : null,
        'event_day' => $booking['event_date'] ? date('l', strtotime($booking['event_date'])) : null,
    ];
}

/** The most recent amendment's reason and date, from the audit log. */
function last_amendment(PDO $pdo, int $bookingId): ?array
{
    $st = $pdo->prepare("SELECT details, created_at FROM audit_log
                          WHERE booking_id = ? AND action = 'amend' ORDER BY id DESC LIMIT 1");
    $st->execute([$bookingId]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $details = json_decode((string) $row['details'], true) ?: [];
    return ['reason' => $details['reason'] ?? null, 'at' => $row['created_at'], 'revision' => $details['revision'] ?? null];
}

/** A value for display, or an em dash when it is empty. */
function dv($value, string $empty = '—'): string
{
    $value = is_string($value) ? trim($value) : $value;
    return $value === null || $value === '' ? $empty : (string) $value;
}

/** 'd M Y' for a stored date, or an em dash. */
function ddate($date, string $empty = '—'): string
{
    return $date ? date('d M Y', strtotime((string) $date)) : $empty;
}

/** 'h:i am/pm' for a stored time, or an em dash. */
function dtime($time, string $empty = '—'): string
{
    return $time ? date('g:i a', strtotime('2000-01-01 ' . $time)) : $empty;
}

/** The venue with its location appended, e.g. "Lawn A — Ground floor, Block B". */
function dvenue(array $d, string $empty = '—'): string
{
    $venue = dv($d['venue'], '');
    $location = dv($d['venue_location'] ?? null, '');
    if ($venue === '') {
        return $location === '' ? $empty : $location;
    }
    return $location === '' ? $venue : "$venue — $location";
}

/** A select value plus its "If Other, specify" text. */
function dchoice(array $booking, string $field, string $otherField): string
{
    return $booking[$field] === 'Other' ? dv($booking[$otherField], 'Other') : dv($booking[$field]);
}

/** The watermark a document prints with, or null: drafts and cancelled bookings are not final. */
function document_watermark(array $booking): ?string
{
    return match ($booking['status']) {
        'draft' => 'DRAFT',
        'cancelled' => 'CANCELLED',
        default => null,
    };
}

/** The description line the invoice shows for the services. */
function invoice_description(array $booking, ?string $venue): string
{
    $type = $booking['event_type'] === 'Other' ? dv($booking['event_type_other'], 'event') : dv($booking['event_type'], 'event');
    $menu = $booking['menu_type'] === 'Other' ? $booking['menu_type_other'] : $booking['menu_type'];
    return 'Catering, decoration & event management services — ' . $type
        . ($menu ? ' (' . $menu . ' menu)' : '')
        . ' at ' . ($venue ?: 'AO Mess')
        . ($booking['event_date'] ? ' on ' . ddate($booking['event_date']) : '') . '.';
}
