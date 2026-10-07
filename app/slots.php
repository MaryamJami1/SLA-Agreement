<?php
/**
 * Event slots: the fixed time slots each venue offers (venue_slots), configured by the admin on the
 * Event Slots page. Nothing about slots is hard-coded — names, times, how many there are and their
 * order all come from the table.
 *
 * Times are TIME values. An end at or before the start means the slot ends the next day, so
 * 20:00 → 00:00 is "8 PM until midnight" and 22:00 → 02:00 runs two hours past it.
 *
 * A booking holds one slot on one date. It copies the slot's name and times when the slot is chosen
 * (slot_name / slot_start / slot_end), so the admin editing the slot later never changes an existing
 * booking or its paperwork. The same slot can't be held twice on one date: the save checks it under
 * the venue lock, and the unique key on bookings (slot_hold, event_date) refuses it regardless.
 */
declare(strict_types=1);

const MINUTES_PER_DAY = 1440;
const SLOT_NAME_MAX = 60;
const SLOT_ICON_MAX = 16;

// ---------------------------------------------------------------------------
// Time arithmetic (pure)
// ---------------------------------------------------------------------------

/** 'HH:MM' or 'HH:MM:SS' → minutes after midnight. */
function time_to_minutes(string $time): int
{
    $parts = explode(':', $time);
    return (int) $parts[0] * 60 + (int) ($parts[1] ?? 0);
}

function slot_crosses_midnight(string $start, string $end): bool
{
    return time_to_minutes($end) <= time_to_minutes($start);
}

/** The slot as [start, end) minutes from the start of the event day; end may pass 1440 (next day). */
function slot_interval(string $start, string $end): array
{
    $s = time_to_minutes($start);
    $e = time_to_minutes($end);
    return [$s, $e <= $s ? $e + MINUTES_PER_DAY : $e];
}

/**
 * True when the two slots share any minute. Slots repeat every day, so one that runs past midnight is
 * also compared with the next day's early slots (22:00 → 02:00 clashes with 01:00 → 03:00).
 * Touching ends (15:00 → 16:00 after 12:00 → 15:00) do not overlap.
 */
function slots_overlap(string $startA, string $endA, string $startB, string $endB): bool
{
    [$a1, $a2] = slot_interval($startA, $endA);
    [$b1, $b2] = slot_interval($startB, $endB);
    foreach ([-MINUTES_PER_DAY, 0, MINUTES_PER_DAY] as $shift) {
        if ($a1 < $b2 + $shift && $b1 + $shift < $a2) {
            return true;
        }
    }
    return false;
}

/** '20:00:00' → '8:00 PM'; '00:00:00' → '12:00 AM'. */
function slot_time_label(?string $time): string
{
    if ($time === null || $time === '') {
        return '';
    }
    $m = time_to_minutes($time);
    $h = intdiv($m, 60);
    return sprintf('%d:%02d %s', $h % 12 === 0 ? 12 : $h % 12, $m % 60, $h < 12 ? 'AM' : 'PM');
}

/** '12:00 PM – 3:00 PM'. */
function slot_range_label(?string $start, ?string $end): string
{
    if ($start === null || $start === '' || $end === null || $end === '') {
        return '';
    }
    return slot_time_label($start) . ' – ' . slot_time_label($end);
}

/** '3 h', '2 h 30 min'. */
function slot_duration_label(string $start, string $end): string
{
    [$s, $e] = slot_interval($start, $end);
    $mins = $e - $s;
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return trim(($h ? "$h h" : '') . ($m ? " $m min" : ''));
}

/** How a booking's slot reads on screen and on paper: 'Evening (8:00 PM – 12:00 AM)'. */
function booking_slot_label(array $booking): string
{
    if (($booking['slot_name'] ?? null) === null) {
        return '';
    }
    return $booking['slot_name'] . ' (' . slot_range_label($booking['slot_start'], $booking['slot_end']) . ')';
}

// ---------------------------------------------------------------------------
// Queries
// ---------------------------------------------------------------------------

/** A venue's slots in display order; with $activeOnly, only those offered for new bookings. */
function venue_slots(PDO $pdo, int $venueId, bool $activeOnly = true): array
{
    $st = $pdo->prepare('SELECT * FROM venue_slots WHERE venue_id = ?' . ($activeOnly ? ' AND is_active = 1' : '')
        . ' ORDER BY sort_order, start_time, id');
    $st->execute([$venueId]);
    return $st->fetchAll();
}

/**
 * The venue's slots for a date, each marked available or booked — what the booking form shows.
 *
 * Lists the active slots, plus the booking's own slot ($keepSlotId) even if it has since been
 * disabled, so an existing booking always shows what it holds. A slot is booked when another live
 * (not cancelled) booking holds it on that date. A confirmed or completed booking from before slots
 * existed (no slot recorded) took the whole day, so it marks every slot booked.
 *
 * Only availability is returned — never who holds a slot — so this is safe to show users.
 *
 * @return list<array{id: int, name: string, icon: ?string, start: string, end: string, time: string,
 *                    available: bool, own: bool, disabled: bool}>
 */
function slot_availability(PDO $pdo, int $venueId, ?string $date, ?int $bookingId, ?int $keepSlotId): array
{
    $st = $pdo->prepare('SELECT * FROM venue_slots WHERE venue_id = ? AND (is_active = 1 OR id = ?)
                          ORDER BY sort_order, start_time, id');
    $st->execute([$venueId, $keepSlotId ?? 0]);
    $slots = $st->fetchAll();

    $held = [];
    $wholeDay = false;
    if ($date !== null && $date !== '') {
        $st = $pdo->prepare("SELECT slot_id, status FROM bookings
                              WHERE venue_id = ? AND event_date = ? AND status <> 'cancelled' AND id <> ?");
        $st->execute([$venueId, $date, $bookingId ?? 0]);
        foreach ($st->fetchAll() as $b) {
            if ($b['slot_id'] !== null) {
                $held[(int) $b['slot_id']] = true;
            } elseif (in_array($b['status'], ['confirmed', 'completed'], true)) {
                $wholeDay = true;
            }
        }
    }

    $out = [];
    foreach ($slots as $s) {
        $id = (int) $s['id'];
        $own = $keepSlotId !== null && $id === $keepSlotId;
        $out[] = [
            'id' => $id, 'name' => $s['name'], 'icon' => $s['icon'],
            'start' => substr($s['start_time'], 0, 5), 'end' => substr($s['end_time'], 0, 5),
            'time' => slot_range_label($s['start_time'], $s['end_time']),
            'available' => !isset($held[$id]) && !$wholeDay,
            'own' => $own, 'disabled' => !(int) $s['is_active'],
        ];
    }
    return $out;
}

/**
 * Why this booking can't hold $slotId on $date, or null if it can. A LOCKING read, so it sees a booking
 * another transaction has just committed; call it after locking the venue row (lock_venues()).
 * Users aren't shown other users' SLA numbers.
 */
function slot_taken_message(PDO $pdo, ?int $bookingId, int $slotId, string $date, bool $isAdmin): ?string
{
    $st = $pdo->prepare("SELECT b.unique_id, b.status FROM bookings b
                          WHERE b.slot_id = ? AND b.event_date = ? AND b.status <> 'cancelled' AND b.id <> ?
                          LIMIT 1 FOR UPDATE");
    $st->execute([$slotId, $date, $bookingId ?? 0]);
    $holder = $st->fetch();
    if (!$holder) {
        return null;
    }
    $st = $pdo->prepare('SELECT name FROM venue_slots WHERE id = ?');
    $st->execute([$slotId]);
    $name = (string) $st->fetchColumn();
    return "Event slot: $name is already booked at this venue on " . date('d M Y', strtotime($date))
        . ($isAdmin ? " ({$holder['unique_id']}, {$holder['status']})" : '')
        . '. Choose another slot, date or venue.';
}

/** True when a PDOException is the unique key that stops a slot being held twice on one date. */
function is_slot_hold_violation(PDOException $e): bool
{
    return (int) ($e->errorInfo[1] ?? 0) === 1062 && strpos($e->getMessage(), 'uq_bookings_slot_hold') !== false;
}

// ---------------------------------------------------------------------------
// Booking form input
// ---------------------------------------------------------------------------

/**
 * The posted slot, validated against the chosen venue. Returns [slot fields, error message or null].
 *
 * Only venues from the list have slots; "Other" (free-text) venues keep a typed start time. With a
 * listed venue and a date, a slot is required (except on an older booking that isn't moving). A newly chosen slot must be active and belong to the
 * venue; the slot a booking already holds is kept even if the admin has since disabled it, and keeps
 * the name and times it was booked with.
 */
function parse_booking_slot(PDO $pdo, $raw, ?int $venueId, ?string $eventDate, ?array $existing): array
{
    $none = ['slot_id' => null, 'slot_name' => null, 'slot_start' => null, 'slot_end' => null];
    if ($venueId === null) {
        return [$none, null];
    }
    $raw = is_string($raw) ? trim($raw) : '';
    if ($raw === '') {
        // No date yet (nothing to hold), or a booking from before slots existed that isn't moving:
        // it may be saved as it is. Confirming it still needs a slot (confirm_booking()).
        $unmoved = $existing !== null && $existing['slot_id'] === null && $existing['event_date'] === $eventDate
            && $existing['venue_id'] !== null && (int) $existing['venue_id'] === $venueId;
        if ($eventDate === null || $unmoved) {
            return [$none, null];
        }
        return [$none, venue_slots($pdo, $venueId) === []
            ? 'Event slot: this venue has no event slots set up yet, so it can\'t be booked. Choose another venue, or ask Booking Organizer to add its slots.'
            : 'Event slot: choose one of the available slots for this venue and date.'];
    }
    $slotId = ctype_digit($raw) ? (int) $raw : 0;
    $st = $pdo->prepare('SELECT * FROM venue_slots WHERE id = ?');
    $st->execute([$slotId]);
    $slot = $st->fetch();
    $kept = $existing !== null && $existing['slot_id'] !== null && (int) $existing['slot_id'] === $slotId;
    if (!$slot || (int) $slot['venue_id'] !== $venueId || (!(int) $slot['is_active'] && !$kept)) {
        return [$none, 'Event slot: choose one of the slots offered for this venue.'];
    }
    if ($kept && $existing['slot_name'] !== null) {
        return [['slot_id' => $slotId, 'slot_name' => $existing['slot_name'],
            'slot_start' => $existing['slot_start'], 'slot_end' => $existing['slot_end']], null];
    }
    return [['slot_id' => $slotId, 'slot_name' => $slot['name'],
        'slot_start' => $slot['start_time'], 'slot_end' => $slot['end_time']], null];
}
