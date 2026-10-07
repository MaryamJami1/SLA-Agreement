<?php
/**
 * Printable documents (plan Section 8, "Documents after an amendment", and Section 12):
 * the SLA agreement, the customer invoice and the user operations sheet.
 *
 * The pages print through the browser; there is no server-side PDF. The letterhead repeats on every
 * printed page because the layout wraps the page in a table with the letterhead in <thead>.
 */
declare(strict_types=1);

/** Text lines that fill a printed document page at its normal (smallest) size. */
const DOCUMENT_PAGE_UNITS = 66;

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
    // Priced lines for the invoice and agreement: every ticked charge, then any ticked decor item
    // that has a rate. Decor without a rate stays a checklist entry only.
    $priced = $lines['charge'] ?? [];
    foreach (LINE_SECTIONS as $section => $_) {
        if ($section === 'charge' || !section_is_priced($section)) {
            continue;
        }
        foreach ($lines[$section] ?? [] as $row) {
            if ($row['rate'] !== null) {
                $priced[] = $row;
            }
        }
    }

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
        'charges'   => $priced,
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
    $menu = implode(', ', array_filter([$menu ? $menu . ' menu' : null, $booking['menu_package_name'] ?? null]));
    return 'Catering, decoration & event management services — ' . $type
        . ($menu !== '' ? ' (' . $menu . ')' : '')
        . ' at ' . ($venue ?: 'Booking Organizer')
        . ($booking['event_date'] ? ' on ' . ddate($booking['event_date']) : '') . '.';
}

/**
 * How much the printed agreement is enlarged so that it fills one A4 sheet: '1' for a full
 * agreement, more for a sparse one. An estimate from the amount of text, kept on the safe side;
 * the stylesheet then stretches the tables over whatever height is still free.
 */
function agreement_print_zoom(array $booking, array $decorSections): string
{
    $wrap = 'document_text_lines';
    $row = 1.3; // one table row, in text lines

    $client = 3 + count(array_filter([$booking['client_relation'], $booking['client_company'], $booking['client_address']]));
    $units = 3 + 2 + 1.5 + max(4, $client)          // title, opening sentence, the two parties
        + 5 * 1.7                                    // section headings
        + 5 * $row                                   // event
        + $row                                       // menu type
        + 3 * $row                                   // decoration
        + (decimal_to_paisa($booking['discount']) > 0 ? 4 : 3) * $row + 1.5   // charges, amount in words
        + 5.5                                        // cancellation policy
        + 1.2 + 4.5 + $row + 1;                      // review note, signatures, received-by

    if ((int) $booking['revision'] > 0) {
        $units += 3;
    }
    foreach (['food_items', 'stage_desc', 'addl_decor'] as $field) {
        if ($booking[$field]) {
            $units += $wrap($booking[$field], 85) + 0.3;
        }
    }
    $units += document_menu_lines($booking, 85, $row);
    if ($decorSections) {
        $units += 1.3;
        foreach ($decorSections as $items) {
            $units += $wrap(implode(', ', array_map(
                static fn($item) => $item['label'] . ($item['notes'] ? ' (' . $item['notes'] . ')' : ''), $items)), 85) + 0.3;
        }
    }
    if ($booking['special_commitments']) {
        $units += 1.7 + $wrap($booking['special_commitments'], 110);
    }

    return document_print_zoom($units, 1.45);
}

/** Printed lines the booking's menu takes: a package row ($packageRow lines) and a row per category. */
function document_menu_lines(array $booking, int $perLine, float $packageRow): float
{
    $lines = ($booking['menu_package_name'] ?? null) ? $packageRow : 0;
    foreach (menu_selection_by_category($booking['menu_selection'] ?? null) as $labels) {
        $lines += document_text_lines(implode(', ', $labels), $perLine) + 0.3;
    }
    return $lines;
}

/** Printed lines a text takes in a column about $perLine characters wide. */
function document_text_lines(?string $text, int $perLine): int
{
    $lines = 0;
    foreach (preg_split('/\R/', trim((string) $text)) as $line) {
        $lines += max(1, (int) ceil(mb_strlen($line) / $perLine));
    }
    return $lines;
}

/**
 * The enlargement for a document holding about $units text lines. DOCUMENT_PAGE_UNITS lines fill
 * the sheet at normal size. Enlarged text also wraps sooner, hence the root; the cap keeps a
 * near-empty document from printing in poster type.
 */
function document_print_zoom(float $units, float $cap): string
{
    $zoom = min($cap, max(1.0, (DOCUMENT_PAGE_UNITS / $units) ** 0.85));
    return number_format($zoom, 2, '.', '');
}

/** As agreement_print_zoom(), for the customer invoice. */
function invoice_print_zoom(array $booking, array $d): string
{
    $row = 1.3;
    $client = 2 + count(array_filter([$booking['client_relation'], $booking['client_company'], $booking['client_cnic'],
        $booking['client_contact'], $booking['client_address']]));
    $units = 3 + 1.5 + max(6, $client) + 1                                   // title, the two boxes
        + $row * (decimal_to_paisa($booking['discount']) > 0 ? 3 : 2)        // heading row, totals
        + document_text_lines(invoice_description($booking, $d['venue']), 80) + 0.3
        + 1.5                                                                // "Payments received"
        + ($d['payments'] ? $row * (count($d['payments']) + 2) : 1.3)
        + 2.5 + 1.5 + 4.5 + 3;                                               // balance, words, signatures, footer
    if ($booking['special_commitments']) {
        $units += document_text_lines('Special commitments: ' . $booking['special_commitments'], 80);
    }
    return document_print_zoom($units, 1.6);
}

/** As agreement_print_zoom(), for the user operations sheet. */
function ops_sheet_print_zoom(array $booking, array $d, array $decorSections): string
{
    $row = 1.3;
    $ops = $d['lines']['ops_item'] ?? [];
    $units = 3 + 1.3 + 5 * $row                                              // title, note, event table
        + 1.5 + ($ops ? $row * (count($ops) + 1) : 1.3)                      // inventory
        + 1.5 + 3 * $row + 4.5;                                              // account summary, signatures
    if ($decorSections) {
        $units += 1.5;
        foreach ($decorSections as $items) {
            $units += document_text_lines(implode(', ', array_map(
                static fn($item) => $item['label'] . ($item['notes'] ? ' (' . $item['notes'] . ')' : ''), $items)), 85) + 0.3;
        }
    }
    if ($booking['food_items'] || $booking['menu_type'] || ($booking['menu_package_name'] ?? null) || ($booking['menu_selection'] ?? null)) {
        $units += 2.7 + ($booking['food_items'] ? document_text_lines($booking['food_items'], 110) : 0)
            + document_menu_lines($booking, 85, 0);
    }
    if ($booking['special_commitments']) {
        $units += 1.5 + document_text_lines($booking['special_commitments'], 110);
    }
    return document_print_zoom($units, 1.45);
}

/** As agreement_print_zoom(), for a vendor invoice (from load_vendor_invoice()). */
function vendor_invoice_print_zoom(array $invoice): string
{
    $row = 1.3;
    $payments = count(array_filter($invoice['payments'], static fn($p) => $p['voided_at'] === null));
    $totalRows = 2 + (decimal_to_paisa($invoice['discount']) > 0 ? 1 : 0) + (decimal_to_paisa($invoice['tax_amount']) > 0 ? 1 : 0);
    $units = 3 + 1.5 + 6 + 1                                                 // title, the two boxes
        + $row * (count($invoice['items']) + 1 + $totalRows)                  // items and totals
        + 1.5 + ($payments ? $row * ($payments + 2) : 1.3)                    // "Payments made"
        + 2.5 + 1.5 + 4.5 + 3;                                               // balance, words, signatures, footer
    if ($invoice['notes']) {
        $units += document_text_lines($invoice['notes'], 80);
    }
    return document_print_zoom($units, 1.6);
}
