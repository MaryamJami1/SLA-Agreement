<?php
/**
 * Figures for the admin Dashboard. Read-only: nothing here writes.
 *
 * What the numbers mean (the page says the same in its footnote):
 *   Booked value  grand_total of confirmed and completed bookings, by the month of the event.
 *                 A draft is not yet agreed and a cancelled booking is no longer owed.
 *   Collected     payments less refunds, voided entries ignored, by the date the money moved.
 *                 Money retained on a cancelled booking counts: it was received.
 *   Outstanding   balance of confirmed and completed bookings, as of now.
 *
 * Money is summed by MySQL as DECIMAL and carried in whole paisa from there on, as in app/money.php.
 * Dates come from PHP (Asia/Karachi), never MySQL's clock.
 */
declare(strict_types=1);

const DASHBOARD_RANGES = ['6m' => 'Last 6 months', '12m' => 'Last 12 months', 'year' => 'This year'];
const DASHBOARD_DEFAULT_RANGE = '12m';

/** The longest custom range: past this the monthly columns are too thin to read. */
const DASHBOARD_MAX_MONTHS = 24;

/** Bookings whose terms are agreed: the ones that count as business done. */
const DASHBOARD_AGREED = "b.status IN ('confirmed', 'completed')";

/** Non-voided payments less refunds. */
const DASHBOARD_NET_PAID = "SUM(CASE WHEN p.kind = 'refund' THEN -p.amount ELSE p.amount END)";

const DASHBOARD_UPCOMING_DAYS = 14;
const DASHBOARD_LOOKAHEAD_DAYS = 30;
const DASHBOARD_RANK_ROWS = 6;

// ---------------------------------------------------------------------------
// Period (pure)
// ---------------------------------------------------------------------------

/**
 * The months a range covers, and the period it is compared against.
 *
 * '6m' / '12m' end with the current month. 'year' is January to December of this year, so the months
 * still to come show what is already booked; it has no comparison, because a part-year of payments
 * against a whole previous year would mislead. 'custom' runs from the month $from to the month $to
 * ('YYYY-MM'), at most DASHBOARD_MAX_MONTHS long, and is compared with the same length before it.
 *
 * @return array{key: string, label: string, start: string, end: string, months: string[], clamped: bool,
 *               previous: ?array{start: string, end: string, label: string}}
 */
function dashboard_period(string $range, DateTimeImmutable $today, string $from = '', string $to = ''): array
{
    $key = isset(DASHBOARD_RANGES[$range]) ? $range : DASHBOARD_DEFAULT_RANGE;
    $thisMonth = $today->modify('first day of this month')->setTime(0, 0);
    $clamped = false;

    // A custom range is whole months, "from" to "to" inclusive. Anything unreadable falls back to
    // the default range rather than guessing what was meant.
    $custom = $range === 'custom' ? [dashboard_month($from), dashboard_month($to)] : [null, null];
    if ($custom[0] !== null && $custom[1] !== null) {
        [$start, $last] = $custom[0] <= $custom[1] ? $custom : [$custom[1], $custom[0]];
        $count = ((int) $last->format('Y') - (int) $start->format('Y')) * 12 + (int) $last->format('n') - (int) $start->format('n') + 1;
        if ($count > DASHBOARD_MAX_MONTHS) {
            $count = DASHBOARD_MAX_MONTHS;
            $clamped = true;
        }
        $key = 'custom';
        $label = 'Custom range';
        $compare = true;
    } elseif ($key === 'year') {
        $start = $thisMonth->setDate((int) $today->format('Y'), 1, 1);
        $count = 12;
        $label = DASHBOARD_RANGES[$key];
        $compare = false;
    } else {
        $count = $key === '6m' ? 6 : 12;
        $start = $thisMonth->modify('-' . ($count - 1) . ' months');
        $label = DASHBOARD_RANGES[$key];
        $compare = true;
    }

    $months = [];
    for ($i = 0; $i < $count; $i++) {
        $months[] = $start->modify("+$i months")->format('Y-m');
    }
    return [
        'key'      => $key,
        'label'    => $label,
        'start'    => $start->format('Y-m-d'),
        'end'      => $start->modify('+' . ($count - 1) . ' months')->format('Y-m-t'),
        'months'   => $months,
        'clamped'  => $clamped,
        'previous' => $compare ? [
            'start' => $start->modify("-$count months")->format('Y-m-d'),
            'end'   => $start->modify('-1 day')->format('Y-m-d'),
            'label' => $count === 1 ? 'previous month' : "previous $count months",
        ] : null,
    ];
}

/** 'YYYY-MM' (2000–2100) → the first day of that month, or null when it is anything else. */
function dashboard_month(string $value): ?DateTimeImmutable
{
    if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) !== 1) {
        return null;
    }
    $year = (int) $m[1];
    $month = (int) $m[2];
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        return null;
    }
    return (new DateTimeImmutable('today'))->setDate($year, $month, 1);
}

/**
 * Column labels for a run of months: the month, with the year under the first one and under each
 * January — enough to place every column without repeating the year twelve times.
 */
function dashboard_month_labels(array $months): array
{
    $labels = [];
    foreach ($months as $i => $ym) {
        $date = new DateTimeImmutable($ym . '-01');
        $labels[] = [
            'label' => $date->format('M'),
            'sub'   => $i === 0 || $date->format('n') === '1' ? $date->format('Y') : '',
            'title' => $date->format('F Y'),
        ];
    }
    return $labels;
}

// ---------------------------------------------------------------------------
// Headline figures
// ---------------------------------------------------------------------------

/**
 * Agreed business with an event date in the window: its value, how many bookings, and how much of
 * that value has been paid so far on those same bookings.
 *
 * @return array{booked: int, bookings: int, paid: int, guests: int}
 */
function dashboard_booked(PDO $pdo, string $start, string $end): array
{
    $st = $pdo->prepare('SELECT COALESCE(SUM(b.grand_total), 0) AS total, COALESCE(SUM(b.paid_total), 0) AS paid,
                                COALESCE(SUM(b.guests), 0) AS guests, COUNT(*) AS n
                           FROM bookings b
                          WHERE ' . DASHBOARD_AGREED . ' AND b.event_date BETWEEN ? AND ?');
    $st->execute([$start, $end]);
    $row = $st->fetch();
    return ['booked' => decimal_to_paisa($row['total']), 'bookings' => (int) $row['n'],
        'paid' => decimal_to_paisa($row['paid']), 'guests' => (int) $row['guests']];
}

/**
 * What fell through in the period: bookings cancelled in it and what was kept on them, and the
 * refunds paid out in it (on any booking).
 *
 * @return array{cancelled: int, retained: int, refunded: int, refunds: int}
 */
function dashboard_cancellations(PDO $pdo, array $period): array
{
    $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(b.paid_total), 0) AS retained
                           FROM bookings b
                          WHERE b.status = 'cancelled' AND b.cancelled_at >= ? AND b.cancelled_at < ? + INTERVAL 1 DAY");
    $st->execute([$period['start'], $period['end']]);
    $cancelled = $st->fetch();

    $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(p.amount), 0) AS total
                           FROM payments p
                          WHERE p.kind = 'refund' AND p.voided_at IS NULL AND p.paid_on BETWEEN ? AND ?");
    $st->execute([$period['start'], $period['end']]);
    $refunds = $st->fetch();

    return [
        'cancelled' => (int) $cancelled['n'],
        'retained'  => decimal_to_paisa($cancelled['retained']),
        'refunded'  => decimal_to_paisa($refunds['total']),
        'refunds'   => (int) $refunds['n'],
    ];
}

/** Net money received in the window, in paisa. */
function dashboard_collected(PDO $pdo, string $start, string $end): int
{
    $st = $pdo->prepare('SELECT COALESCE(' . DASHBOARD_NET_PAID . ', 0)
                           FROM payments p
                          WHERE p.voided_at IS NULL AND p.paid_on BETWEEN ? AND ?');
    $st->execute([$start, $end]);
    return decimal_to_paisa($st->fetchColumn());
}

/**
 * The figures on the tiles. The period figures carry the same figure for the comparison period when
 * there is one; the rest are as of today.
 */
function dashboard_kpis(PDO $pdo, array $period, DateTimeImmutable $today): array
{
    $now = dashboard_booked($pdo, $period['start'], $period['end']);
    $kpis = [
        'booked'      => $now['booked'],
        'bookings'    => $now['bookings'],
        'booked_paid' => $now['paid'],
        'guests'      => $now['guests'],
        'collected'   => dashboard_collected($pdo, $period['start'], $period['end']),
        'collected_today' => dashboard_collected($pdo, $today->format('Y-m-d'), $today->format('Y-m-d')),
        'previous'    => null,
    ];
    if ($period['previous'] !== null) {
        $before = dashboard_booked($pdo, $period['previous']['start'], $period['previous']['end']);
        $kpis['previous'] = [
            'booked'    => $before['booked'],
            'bookings'  => $before['bookings'],
            'collected' => dashboard_collected($pdo, $period['previous']['start'], $period['previous']['end']),
        ];
    }

    $row = $pdo->query('SELECT COALESCE(SUM(b.balance), 0) AS due, COALESCE(SUM(b.balance > 0), 0) AS owing
                          FROM bookings b WHERE ' . DASHBOARD_AGREED)->fetch();
    $kpis['outstanding'] = decimal_to_paisa($row['due']);
    $kpis['owing_bookings'] = (int) $row['owing'];

    $st = $pdo->prepare("SELECT COALESCE(SUM(b.status = 'confirmed'), 0) AS confirmed, COALESCE(SUM(b.status = 'draft'), 0) AS drafts
                           FROM bookings b
                          WHERE b.status IN ('draft', 'confirmed') AND b.event_date BETWEEN ? AND ?");
    $st->execute([$today->format('Y-m-d'), $today->modify('+' . DASHBOARD_LOOKAHEAD_DAYS . ' days')->format('Y-m-d')]);
    $row = $st->fetch();
    $kpis['upcoming'] = (int) $row['confirmed'];
    $kpis['upcoming_drafts'] = (int) $row['drafts'];

    $row = $pdo->query("SELECT (SELECT COUNT(*) FROM users WHERE role = 'user' AND status = 'pending') AS users,
                               (SELECT COUNT(*) FROM bookings WHERE status = 'draft') AS drafts,
                               (SELECT COUNT(*) FROM users WHERE role = 'user' AND status = 'active') AS active_users,
                               (SELECT COUNT(*) FROM venues WHERE is_active = 1) AS active_venues")->fetch();
    $kpis['pending_users'] = (int) $row['users'];
    $kpis['draft_bookings'] = (int) $row['drafts'];
    $kpis['active_users'] = (int) $row['active_users'];
    $kpis['active_venues'] = (int) $row['active_venues'];

    return $kpis;
}

// ---------------------------------------------------------------------------
// By month
// ---------------------------------------------------------------------------

/**
 * Booked value and money collected for each month of the period, months with nothing included.
 *
 * @return array<string, array{booked: int, collected: int}> keyed 'Y-m', in order
 */
function dashboard_money_by_month(PDO $pdo, array $period): array
{
    $out = array_fill_keys($period['months'], ['booked' => 0, 'collected' => 0]);

    $st = $pdo->prepare("SELECT DATE_FORMAT(b.event_date, '%Y-%m') AS ym, SUM(b.grand_total) AS total
                           FROM bookings b
                          WHERE " . DASHBOARD_AGREED . " AND b.event_date BETWEEN ? AND ?
                          GROUP BY ym");
    $st->execute([$period['start'], $period['end']]);
    foreach ($st->fetchAll() as $row) {
        if (isset($out[$row['ym']])) {
            $out[$row['ym']]['booked'] = decimal_to_paisa($row['total']);
        }
    }

    $st = $pdo->prepare("SELECT DATE_FORMAT(p.paid_on, '%Y-%m') AS ym, " . DASHBOARD_NET_PAID . " AS total
                           FROM payments p
                          WHERE p.voided_at IS NULL AND p.paid_on BETWEEN ? AND ?
                          GROUP BY ym");
    $st->execute([$period['start'], $period['end']]);
    foreach ($st->fetchAll() as $row) {
        if (isset($out[$row['ym']])) {
            $out[$row['ym']]['collected'] = decimal_to_paisa($row['total']);
        }
    }
    return $out;
}

/**
 * How many bookings have their event in each month, split by status. A booking with no event date
 * yet has no month to sit in and is left out.
 *
 * @return array<string, array{draft: int, confirmed: int, completed: int, cancelled: int}> keyed 'Y-m', in order
 */
function dashboard_status_by_month(PDO $pdo, array $period): array
{
    $out = array_fill_keys($period['months'], ['draft' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0]);
    $st = $pdo->prepare("SELECT DATE_FORMAT(b.event_date, '%Y-%m') AS ym, b.status, COUNT(*) AS n
                           FROM bookings b
                          WHERE b.event_date BETWEEN ? AND ?
                          GROUP BY ym, b.status");
    $st->execute([$period['start'], $period['end']]);
    foreach ($st->fetchAll() as $row) {
        if (isset($out[$row['ym']][$row['status']])) {
            $out[$row['ym']][$row['status']] = (int) $row['n'];
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Rankings
// ---------------------------------------------------------------------------

/** Net money received by payment method, largest first. Each row: label, amount (paisa), n (entries). */
function dashboard_payment_methods(PDO $pdo, array $period): array
{
    $st = $pdo->prepare('SELECT p.method, ' . DASHBOARD_NET_PAID . ' AS total, COUNT(*) AS n
                           FROM payments p
                          WHERE p.voided_at IS NULL AND p.paid_on BETWEEN ? AND ?
                          GROUP BY p.method');
    $st->execute([$period['start'], $period['end']]);
    $rows = [];
    foreach ($st->fetchAll() as $row) {
        $rows[] = ['key' => $row['method'], 'label' => PAYMENT_METHODS[$row['method']] ?? $row['method'],
            'amount' => decimal_to_paisa($row['total']), 'n' => (int) $row['n']];
    }
    usort($rows, static fn(array $a, array $b) => $b['amount'] <=> $a['amount']);
    return $rows;
}

/** Agreed bookings by event type, most frequent first. Each row: label, n, amount (paisa). */
function dashboard_event_types(PDO $pdo, array $period): array
{
    $st = $pdo->prepare("SELECT COALESCE(NULLIF(b.event_type, ''), 'Not set') AS label, COUNT(*) AS n, SUM(b.grand_total) AS total
                           FROM bookings b
                          WHERE " . DASHBOARD_AGREED . " AND b.event_date BETWEEN ? AND ?
                          GROUP BY label
                          ORDER BY n DESC, total DESC, label");
    $st->execute([$period['start'], $period['end']]);
    return dashboard_rank_rows($st->fetchAll());
}

/** Venues by number of agreed bookings. Each row: label, n, amount (paisa). */
function dashboard_top_venues(PDO $pdo, array $period): array
{
    $st = $pdo->prepare("SELECT COALESCE(v.name, NULLIF(b.venue_other, ''), 'No venue') AS label, COUNT(*) AS n, SUM(b.grand_total) AS total
                           FROM bookings b LEFT JOIN venues v ON v.id = b.venue_id
                          WHERE " . DASHBOARD_AGREED . " AND b.event_date BETWEEN ? AND ?
                          GROUP BY label
                          ORDER BY n DESC, total DESC, label
                          LIMIT " . DASHBOARD_RANK_ROWS);
    $st->execute([$period['start'], $period['end']]);
    return dashboard_rank_rows($st->fetchAll());
}

/** Users by booked value. Each row: label, n, amount (paisa). */
function dashboard_top_users(PDO $pdo, array $period): array
{
    $st = $pdo->prepare("SELECT COALESCE(NULLIF(MAX(u.firm_name), ''), MAX(u.username), 'No user assigned') AS label,
                                COUNT(*) AS n, SUM(b.grand_total) AS total
                           FROM bookings b LEFT JOIN users u ON u.id = b.user_id
                          WHERE " . DASHBOARD_AGREED . " AND b.event_date BETWEEN ? AND ?
                          GROUP BY b.user_id
                          ORDER BY total DESC, n DESC, label
                          LIMIT " . DASHBOARD_RANK_ROWS);
    $st->execute([$period['start'], $period['end']]);
    return dashboard_rank_rows($st->fetchAll());
}

function dashboard_rank_rows(array $rows): array
{
    return array_map(static fn(array $r) => [
        'label'  => (string) $r['label'],
        'n'      => (int) $r['n'],
        'amount' => decimal_to_paisa($r['total']),
    ], $rows);
}

// ---------------------------------------------------------------------------
// Action lists
// ---------------------------------------------------------------------------

/** Events in the next two weeks that are still going ahead, soonest first. */
function dashboard_upcoming(PDO $pdo, DateTimeImmutable $today): array
{
    $st = $pdo->prepare("SELECT b.id, b.unique_id, b.revision, b.status, b.client_name, b.firm_name, b.event_date, b.guests, b.balance,
                                COALESCE(v.name, b.venue_other) AS venue
                           FROM bookings b LEFT JOIN venues v ON v.id = b.venue_id
                          WHERE b.status IN ('draft', 'confirmed') AND b.event_date BETWEEN ? AND ?
                          ORDER BY b.event_date, b.id
                          LIMIT 8");
    $st->execute([$today->format('Y-m-d'), $today->modify('+' . DASHBOARD_UPCOMING_DAYS . ' days')->format('Y-m-d')]);
    return $st->fetchAll();
}

/**
 * Agreed bookings whose event has happened and which still owe money, longest overdue first.
 * These are the balances to chase; a balance on an event still to come is not yet late.
 *
 * @return array{rows: array, count: int, amount: int}
 */
function dashboard_overdue(PDO $pdo, DateTimeImmutable $today): array
{
    $where = DASHBOARD_AGREED . ' AND b.balance > 0 AND b.event_date < ?';
    $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(b.balance), 0) AS total FROM bookings b WHERE $where");
    $st->execute([$today->format('Y-m-d')]);
    $totals = $st->fetch();

    $st = $pdo->prepare("SELECT b.id, b.unique_id, b.revision, b.status, b.client_name, b.client_contact, b.event_date, b.balance
                           FROM bookings b
                          WHERE $where
                          ORDER BY b.event_date, b.id
                          LIMIT 6");
    $st->execute([$today->format('Y-m-d')]);
    return ['rows' => $st->fetchAll(), 'count' => (int) $totals['n'], 'amount' => decimal_to_paisa($totals['total'])];
}

/**
 * Confirmed bookings with no signed copy on file for their current revision, soonest event first.
 * An amendment raises the revision, so a copy signed for an earlier one does not count.
 *
 * @return array{rows: array, count: int}
 */
function dashboard_unsigned(PDO $pdo): array
{
    $where = "b.status = 'confirmed' AND NOT EXISTS (
                  SELECT 1 FROM attachments a
                   WHERE a.booking_id = b.id AND a.signed_revision = b.revision AND a.voided_at IS NULL)";
    $count = (int) $pdo->query("SELECT COUNT(*) FROM bookings b WHERE $where")->fetchColumn();
    $rows = $pdo->query("SELECT b.id, b.unique_id, b.revision, b.client_name, b.firm_name, b.event_date
                           FROM bookings b
                          WHERE $where
                          ORDER BY b.event_date IS NULL, b.event_date, b.id
                          LIMIT 6")->fetchAll();
    return ['rows' => $rows, 'count' => $count];
}

/**
 * Days each venue is taken in the coming DASHBOARD_LOOKAHEAD_DAYS days, today included. The same
 * counting as the Calendar's availability table: distinct days, cancelled bookings left out.
 *
 * @return list<array{venue: string, days: int, free: int}>
 */
function dashboard_venue_occupancy(PDO $pdo, DateTimeImmutable $today): array
{
    $end = $today->modify('+' . (DASHBOARD_LOOKAHEAD_DAYS - 1) . ' days');
    $entries = calendar_bookings($pdo, $today->format('Y-m-d'), $end->format('Y-m-d'));
    return venue_availability($pdo, $entries, DASHBOARD_LOOKAHEAD_DAYS);
}

/** What each audit action is called in the activity list. Sign-ins are left out of the list. */
const DASHBOARD_ACTIVITY = [
    'create'          => 'Booking created',
    'update'          => 'Booking edited',
    'amend'           => 'Booking amended',
    'confirm'         => 'Booking confirmed',
    'complete'        => 'Booking completed',
    'cancel'          => 'Booking cancelled',
    'delete_draft'    => 'Draft deleted',
    'payment_add'     => 'Payment recorded',
    'payment_void'    => 'Payment voided',
    'attachment_add'  => 'File uploaded',
    'attachment_void' => 'File voided',
    'user_register' => 'User registered',
    'user_create'   => 'User created by admin',
    'user_approve'  => 'User approved',
    'user_disable'  => 'User disabled',
    'user_delete'   => 'User deleted',
    'password_reset'  => 'Password reset',
    'password_change' => 'Password changed',
    'catalog_change'  => 'Store item changed',
    'venue_change'    => 'Venue changed',
    'vendor_change'       => 'Vendor changed',
    'vendor_assign'       => 'Vendor service assigned',
    'vendor_invoice'      => 'Vendor invoice issued',
    'vendor_payment_add'  => 'Vendor payment recorded',
    'vendor_payment_void' => 'Vendor payment voided',
];

/** The latest things done in the system, newest first, with who did them. */
function dashboard_activity(PDO $pdo): array
{
    return $pdo->query("SELECT a.id, a.action, a.details, a.created_at, a.booking_id,
                               u.name AS user_name, b.unique_id, b.revision, b.client_name
                          FROM audit_log a
                          LEFT JOIN users u ON u.id = a.user_id
                          LEFT JOIN bookings b ON b.id = a.booking_id
                         WHERE a.action NOT IN ('login_ok', 'login_fail')
                         ORDER BY a.id DESC
                         LIMIT 8")->fetchAll();
}

/**
 * What an activity row was about: the booking's number and client, or for account actions the
 * username recorded with the entry. Empty when the entry names nothing (pure).
 */
function dashboard_activity_subject(array $row): string
{
    if (!empty($row['unique_id'])) {
        $subject = format_document_number((string) $row['unique_id'], 'SLA', (int) $row['revision']);
        return trim((string) ($row['client_name'] ?? '')) !== '' ? $subject . ' · ' . $row['client_name'] : $subject;
    }
    $details = json_decode((string) ($row['details'] ?? ''), true);
    return is_array($details) && is_string($details['username'] ?? null) ? $details['username'] : '';
}

/** The most recent money movements that still stand, newest first. */
function dashboard_latest_payments(PDO $pdo): array
{
    return $pdo->query("SELECT p.id, p.kind, p.amount, p.paid_on, p.method, b.id AS booking_id, b.unique_id, b.revision, b.client_name
                          FROM payments p JOIN bookings b ON b.id = p.booking_id
                         WHERE p.voided_at IS NULL
                         ORDER BY p.paid_on DESC, p.id DESC
                         LIMIT 6")->fetchAll();
}

/** The agreed bookings that owe the most. */
function dashboard_largest_balances(PDO $pdo): array
{
    return $pdo->query('SELECT b.id, b.unique_id, b.revision, b.status, b.client_name, b.event_date, b.grand_total, b.balance
                          FROM bookings b
                         WHERE ' . DASHBOARD_AGREED . ' AND b.balance > 0
                         ORDER BY b.balance DESC, b.event_date, b.id
                         LIMIT 5')->fetchAll();
}
