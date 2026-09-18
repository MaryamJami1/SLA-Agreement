<?php
/**
 * Bookings: field rules, the single authorization chokepoint, form lines, validation and save.
 *
 * load_booking_for_user() is the ONLY way pages get a booking by id (plan Section 8).
 * Phase 3 saves drafts; amendments of confirmed bookings are added in Phase 4.
 */
declare(strict_types=1);

const EVENT_TYPES    = ['Mehndi', 'Barat', 'Valima', 'Birthday', 'Corporate', 'Other'];
const MENU_TYPES     = ['Buffet', 'Sitting Dinner', 'Hi-Tea', 'Other'];
const STAGE_TYPES    = ['Full backdrop — fresh flowers', 'Artificial flowers', 'Fabric', 'Other'];
const ENTRANCE_TYPES = ['Welcome arch', 'Floral gate', 'None', 'Other'];
const LIGHTING_TYPES = ['Simple', 'Uplighting', 'Fairy lights', 'Spotlights', 'Other'];
const FLOOR_TYPES    = ['Red Carpet', 'Regular Flooring', 'Other'];
const DUE_ON_TYPES   = ['Event Day', 'As agreed'];

const LONG_TEXT_MAX = 10000;

/** Selects with an "If Other, specify" companion field. */
const OTHER_PAIRS = [
    'event_type'     => 'event_type_other',
    'menu_type'      => 'menu_type_other',
    'stage'          => 'stage_other',
    'entrance'       => 'entrance_other',
    'lighting'       => 'lighting_other',
    'floor_covering' => 'floor_other',
];

const LINE_SECTIONS = [
    'charge'          => 'Charges',
    'decor_general'   => 'General Decor',
    'decor_light'     => 'Light',
    'decor_generator' => 'Generator',
    'decor_flower'    => 'Flower',
    'decor_extra'     => 'Extra',
    'ops_item'        => 'Operations Sheet Items',
];

/** A booking save failed validation; ->errors maps field => message. */
class BookingValidationError extends RuntimeException
{
    public array $errors;

    public function __construct(array $errors)
    {
        parent::__construct('Booking validation failed.');
        $this->errors = $errors;
    }
}

/** Someone else saved the booking after this form was opened (optimistic lock). */
class BookingConflict extends RuntimeException {}

/**
 * Scalar booking columns the form edits: name => [type, max length or options, label].
 * vendor_id and venue_id/venue_other are handled separately.
 */
function booking_field_specs(): array
{
    return [
        'agreement_day'        => ['text', 20, 'Day of signing'],
        'agreement_month'      => ['text', 40, 'Month, year'],
        'agreement_place'      => ['text', 80, 'Place'],
        'client_name'          => ['text', 150, 'Client name'],
        'client_relation'      => ['text', 150, 'S/o, W/o, D/o'],
        'client_cnic'          => ['cnic', 15, 'CNIC'],
        'client_contact'       => ['text', 50, 'Contact no.'],
        'client_contact2'      => ['text', 50, 'Contact no. 2'],
        'client_company'       => ['text', 150, 'Company'],
        'client_address'       => ['text', 255, 'Residential address'],
        'reference_name'       => ['text', 150, 'Reference name'],
        'reference_department' => ['text', 150, 'Reference department'],
        'firm_name'            => ['text', 150, 'Name of firm'],
        'rep_name'             => ['text', 100, 'Representative name'],
        'rep_contact'          => ['text', 50, 'Representative contact'],
        'event_type'           => ['select', EVENT_TYPES, 'Type of event'],
        'event_type_other'     => ['text', 100, 'Other event type'],
        'event_date'           => ['date', null, 'Date of event'],
        'alt_date'             => ['date', null, 'Alternate date'],
        'setup_time'           => ['time', null, 'Setup ready by'],
        'start_time'           => ['time', null, 'Event start time'],
        'guests'               => ['count', null, 'Estimated guests'],
        'menu_type'            => ['select', MENU_TYPES, 'Menu type'],
        'menu_type_other'      => ['text', 100, 'Other menu type'],
        'food_items'           => ['longtext', LONG_TEXT_MAX, 'Food items'],
        'theme'                => ['text', 150, 'Theme / colour scheme'],
        'stage'                => ['select', STAGE_TYPES, 'Stage decoration'],
        'stage_other'          => ['text', 150, 'Other stage decoration'],
        'stage_desc'           => ['longtext', LONG_TEXT_MAX, 'Stage description'],
        'entrance'             => ['select', ENTRANCE_TYPES, 'Entrance decoration'],
        'entrance_other'       => ['text', 150, 'Other entrance decoration'],
        'lighting'             => ['select', LIGHTING_TYPES, 'Lighting'],
        'lighting_other'       => ['text', 150, 'Other lighting'],
        'floor_covering'       => ['select', FLOOR_TYPES, 'Floor covering'],
        'floor_other'          => ['text', 150, 'Other floor covering'],
        'addl_decor'           => ['longtext', LONG_TEXT_MAX, 'Additional decor'],
        'decor_by'             => ['text', 150, 'Decor by'],
        'sofas'                => ['count', null, 'Sofas'],
        'chairs'               => ['count', null, 'Chairs'],
        'tables_dining'        => ['count', null, 'Dining tables'],
        'tables_buffet'        => ['count', null, 'Buffet tables'],
        'waiters'              => ['count', null, 'Waiters'],
        'chefs'                => ['count', null, 'Chefs'],
        'per_head_rate'        => ['money', null, 'Per-head rate'],
        'discount'             => ['money', null, 'Discount'],
        'due_on'               => ['select', DUE_ON_TYPES, 'Balance due on'],
        'refund_pct_30'        => ['pct', null, 'Refund % (30+ days)'],
        'refund_pct_7'         => ['pct', null, 'Refund % (7–30 days)'],
        'special_commitments'  => ['longtext', LONG_TEXT_MAX, 'Special commitments'],
        'vendor_sign_name'     => ['text', 150, 'Vendor signature name'],
        'vendor_sign_date'     => ['date', null, 'Vendor signature date'],
        'client_sign_name'     => ['text', 150, 'Client signature name'],
        'client_sign_date'     => ['date', null, 'Client signature date'],
        'received_by'          => ['text', 100, 'Received by'],
        'received_date'        => ['date', null, 'Received date'],
        'received_time'        => ['time', null, 'Received time'],
    ];
}

// ---------------------------------------------------------------------------
// Authorization chokepoint
// ---------------------------------------------------------------------------

/**
 * Load a booking the user may act on with $intent, or respond 404 (never 403: the page must not
 * reveal that the booking exists). No other code queries bookings by id.
 *
 *   view   — admin: any; vendor: own bookings
 *   edit   — admin: draft or confirmed; vendor: own drafts
 *   delete — admin: any draft; vendor: own drafts
 *   admin  — money, voids, cancel, confirm, complete: admin only
 */
function load_booking_for_user(PDO $pdo, int $id, array $user, string $intent): array
{
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$id]);
    $booking = $st->fetch();
    if (!$booking || !booking_allows($booking, $user, $intent)) {
        not_found();
    }
    return $booking;
}

/** The rule behind load_booking_for_user(), also re-checked against the locked row inside saves. */
function booking_allows(array $booking, array $user, string $intent): bool
{
    $isAdmin = $user['role'] === 'admin';
    $isOwner = !$isAdmin && (int) $booking['vendor_id'] === (int) $user['id'];
    switch ($intent) {
        case 'view':
            return $isAdmin || $isOwner;
        case 'edit':
            return ($isAdmin && in_array($booking['status'], ['draft', 'confirmed'], true))
                || ($isOwner && $booking['status'] === 'draft');
        case 'delete':
            return ($isAdmin || $isOwner) && $booking['status'] === 'draft';
        case 'admin':
            return $isAdmin;
    }
    return false;
}

/** WHERE fragment limiting a list to the bookings $user may see (same rule as 'view'). */
function booking_scope_sql(array $user, string $alias = 'b'): array
{
    return $user['role'] === 'admin'
        ? ['1 = 1', []]
        : ["$alias.vendor_id = ?", [(int) $user['id']]];
}

// ---------------------------------------------------------------------------
// Lookups for the form
// ---------------------------------------------------------------------------

function active_vendors(PDO $pdo): array
{
    return $pdo->query("SELECT id, username, firm_name, rep_name, contact FROM users
                         WHERE role = 'vendor' AND status = 'active' ORDER BY firm_name, username")->fetchAll();
}

/** Active venues, plus the booking's current venue if it has since been deactivated. */
function venues_for_form(PDO $pdo, ?int $currentVenueId): array
{
    $st = $pdo->prepare('SELECT id, name, is_active FROM venues WHERE is_active = 1 OR id = ? ORDER BY sort_order, name');
    $st->execute([$currentVenueId ?? 0]);
    return $st->fetchAll();
}

/**
 * The line rows shown on the form: the booking's existing lines, plus every active catalog item not
 * yet on it (unselected, with the current default rate). Keys: 'l<lineId>' for existing lines,
 * 'c<catalogId>' for catalog items not yet added. Grouped by section in catalog order.
 */
function booking_form_lines(PDO $pdo, ?int $bookingId): array
{
    $lines = [];
    $onBooking = [];
    if ($bookingId !== null) {
        $st = $pdo->prepare('SELECT * FROM booking_line_items WHERE booking_id = ? ORDER BY sort_order, id');
        $st->execute([$bookingId]);
        foreach ($st->fetchAll() as $row) {
            $lines['l' . $row['id']] = [
                'key' => 'l' . $row['id'], 'line_id' => (int) $row['id'], 'catalog_id' => $row['catalog_id'] === null ? null : (int) $row['catalog_id'],
                'section' => $row['section'], 'label' => $row['label'], 'unit' => $row['unit_snapshot'],
                'selected' => (bool) $row['is_selected'], 'qty' => $row['qty'], 'rate' => $row['rate'],
                'amount' => $row['amount'], 'notes' => $row['notes'], 'sort_order' => (int) $row['sort_order'],
            ];
            if ($row['catalog_id'] !== null) {
                $onBooking[(int) $row['catalog_id']] = true;
            }
        }
    }
    foreach ($pdo->query('SELECT * FROM item_catalog WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll() as $item) {
        if (isset($onBooking[(int) $item['id']])) {
            continue;
        }
        $lines['c' . $item['id']] = [
            'key' => 'c' . $item['id'], 'line_id' => null, 'catalog_id' => (int) $item['id'],
            'section' => $item['section'], 'label' => $item['name'], 'unit' => $item['unit'],
            'selected' => false, 'qty' => null, 'rate' => $item['default_rate'], 'amount' => '0.00',
            'notes' => null, 'sort_order' => (int) $item['sort_order'],
        ];
    }
    // Group by section (catalog section order), keeping sort order inside each section.
    $order = array_flip(array_keys(LINE_SECTIONS));
    uasort($lines, static fn($a, $b) => [$order[$a['section']], $a['sort_order'], $a['key']] <=> [$order[$b['section']], $b['sort_order'], $b['key']]);
    return $lines;
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

/**
 * Validate and normalize the posted form. Values come back in database form (strings for DECIMAL,
 * 'Y-m-d' dates, 'H:i:s' times, ints for counts, null for empty).
 *
 * @param array      $formLines booking_form_lines() for this booking
 * @param array|null $existing  the stored booking (null when creating)
 * @return array{fields: array, lines: array, errors: array}
 */
function parse_booking_input(PDO $pdo, array $post, array $user, ?array $existing, array $formLines): array
{
    $fields = [];
    $errors = [];

    foreach (booking_field_specs() as $name => [$type, $param, $label]) {
        $raw = $post[$name] ?? '';
        $raw = is_string($raw) ? trim($raw) : '';
        try {
            $fields[$name] = parse_booking_value($type, $param, $raw);
        } catch (InvalidInput $e) {
            $errors[$name] = "$label: " . $e->getMessage();
            $fields[$name] = null;
        }
    }

    // "If Other, specify" is kept only when "Other" is chosen.
    foreach (OTHER_PAIRS as $select => $other) {
        if ($fields[$select] !== 'Other') {
            $fields[$other] = null;
        }
    }
    $fields['agreement_place'] = $fields['agreement_place'] ?? 'Karachi';
    $fields['due_on'] = $fields['due_on'] ?? 'Event Day';
    foreach (['guests', 'sofas', 'chairs', 'tables_dining', 'tables_buffet', 'waiters', 'chefs'] as $count) {
        $fields[$count] = $fields[$count] ?? 0;
    }
    foreach (['per_head_rate', 'discount'] as $money) {
        $fields[$money] = $fields[$money] ?? '0.00';
    }

    if ($fields['client_name'] === null && !isset($errors['client_name'])) {
        $errors['client_name'] = 'Client name: required to save the booking.';
    }

    // Venue: a venue id, 'other' (free text), or empty.
    $venueRaw = is_string($post['venue_id'] ?? null) ? trim($post['venue_id']) : '';
    $fields['venue_id'] = null;
    $fields['venue_other'] = null;
    if ($venueRaw === 'other') {
        $other = trim((string) ($post['venue_other'] ?? ''));
        if ($other === '') {
            $errors['venue_other'] = 'Venue: enter the venue name, or choose one from the list.';
        } elseif (mb_strlen($other) > 150) {
            $errors['venue_other'] = 'Venue: at most 150 characters.';
        } else {
            $fields['venue_other'] = $other;
        }
    } elseif ($venueRaw !== '') {
        $venueId = ctype_digit($venueRaw) ? (int) $venueRaw : 0;
        $st = $pdo->prepare('SELECT id, is_active FROM venues WHERE id = ?');
        $st->execute([$venueId]);
        $venue = $st->fetch();
        $unchanged = $existing !== null && (int) $existing['venue_id'] === $venueId;
        if (!$venue || (!(int) $venue['is_active'] && !$unchanged)) {
            $errors['venue_id'] = 'Venue: choose a venue from the list.';
        } else {
            $fields['venue_id'] = $venueId;
        }
    }

    // Vendor (ownership rules, plan Section 5).
    if ($user['role'] === 'vendor') {
        // Vendors never choose or change the owner; the snapshot always comes from their own profile.
        $fields['vendor_id'] = $existing === null ? (int) $user['id'] : (int) $existing['vendor_id'];
        $fields['firm_name'] = $user['firm_name'];
        $fields['rep_name'] = $user['rep_name'];
        $fields['rep_contact'] = $user['contact'];
    } else {
        $vendorRaw = is_string($post['vendor_id'] ?? null) ? trim($post['vendor_id']) : '';
        $fields['vendor_id'] = $vendorRaw === '' ? null : (ctype_digit($vendorRaw) ? (int) $vendorRaw : -1);
        if ($fields['vendor_id'] === -1) {
            $errors['vendor_id'] = 'Vendor: choose a vendor from the list.';
            $fields['vendor_id'] = null;
        }
    }

    [$lines, $lineErrors] = parse_booking_lines($post['lines'] ?? [], $formLines);
    $errors += $lineErrors;

    return ['fields' => $fields, 'lines' => $lines, 'errors' => $errors];
}

/** @throws InvalidInput */
function parse_booking_value(string $type, $param, string $raw)
{
    switch ($type) {
        case 'text':
        case 'longtext':
            if ($raw === '') {
                return null;
            }
            if (mb_strlen($raw, 'UTF-8') > $param) {
                throw new InvalidInput("at most $param characters.");
            }
            return $raw;
        case 'select':
            if ($raw === '') {
                return null;
            }
            if (!in_array($raw, $param, true)) {
                throw new InvalidInput('choose an option from the list.');
            }
            return $raw;
        case 'cnic':
            if ($raw === '') {
                return null;
            }
            $digits = preg_replace('/\D/', '', $raw);
            $cnic = strlen($digits) === 13 ? substr($digits, 0, 5) . '-' . substr($digits, 5, 7) . '-' . substr($digits, 12) : $raw;
            if (!is_valid_cnic($cnic)) {
                throw new InvalidInput('enter 13 digits as #####-#######-#.');
            }
            return $cnic;
        case 'date':
            if ($raw === '') {
                return null;
            }
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if (!$d || $d->format('Y-m-d') !== $raw || (int) $d->format('Y') < 2000 || (int) $d->format('Y') > 2100) {
                throw new InvalidInput('enter a valid date.');
            }
            return $raw;
        case 'time':
            if ($raw === '') {
                return null;
            }
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $raw, $m) !== 1) {
                throw new InvalidInput('enter a valid time.');
            }
            return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
        case 'count':
            return parse_whole_number($raw);
        case 'money':
            $paisa = parse_money($raw);
            return $paisa === null ? null : paisa_to_decimal($paisa);
        case 'pct':
            $hundredths = parse_percent($raw);
            return $hundredths === null ? null : paisa_to_decimal($hundredths);
    }
    throw new LogicException("Unknown field type '$type'");
}

/**
 * Validate the posted line rows against the rows the form showed. Section, label and unit always come
 * from the database, never from the POST.
 *
 * @return array{0: array, 1: array} [lines keyed like $formLines, errors]
 */
function parse_booking_lines($posted, array $formLines): array
{
    $posted = is_array($posted) ? $posted : [];
    $lines = [];
    $errors = [];
    foreach ($formLines as $key => $line) {
        $in = $posted[$key] ?? null;
        if (!is_array($in) || !isset($in['present'])) {
            continue; // not on the submitted form: an existing line stays as it is
        }
        $selected = isset($in['selected']);
        $label = $line['label'];
        $rate = null;
        $qty = null;
        $notes = is_string($in['notes'] ?? null) ? trim($in['notes']) : '';
        if (mb_strlen($notes) > 255) {
            $errors["line_$key"] = "$label: notes can be at most 255 characters.";
        }
        try {
            if ($line['section'] === 'charge') {
                $ratePaisa = parse_money(is_string($in['rate'] ?? null) ? $in['rate'] : '');
                $rate = $ratePaisa === null ? null : paisa_to_decimal($ratePaisa);
                if ($selected && $rate === null) {
                    throw new InvalidInput('enter a rate for the selected charge.');
                }
                if ($line['unit'] === 'per unit') {
                    $qty = parse_whole_number(is_string($in['qty'] ?? null) ? $in['qty'] : '');
                    if ($selected && $qty === null) {
                        throw new InvalidInput('enter a quantity (0 or more).');
                    }
                }
            } elseif ($line['section'] === 'ops_item') {
                $qty = parse_whole_number(is_string($in['qty'] ?? null) ? $in['qty'] : '');
            }
        } catch (InvalidInput $e) {
            $errors["line_$key"] = "$label: " . $e->getMessage();
        }
        if ($line['line_id'] === null && !$selected) {
            continue; // catalog item not ticked: nothing is stored
        }
        $lines[$key] = $line + ['new' => [
            'selected' => $selected, 'rate' => $rate, 'qty' => $qty, 'notes' => $notes === '' ? null : $notes,
        ]];
    }
    return [$lines, $errors];
}

// ---------------------------------------------------------------------------
// Save (drafts)
// ---------------------------------------------------------------------------

/**
 * Create a booking (when $bookingId is null) or save changes to a draft, in one transaction.
 * Creating reserves the SLA number in the same transaction; editing uses the optimistic lock.
 *
 * @return array{id: int, unique_id: string}
 * @throws BookingValidationError|BookingConflict
 */
function save_booking_draft(PDO $pdo, array $user, ?int $bookingId, ?int $version, array $fields, array $lines): array
{
    return db_transaction(static function (PDO $pdo) use ($user, $bookingId, $version, $fields, $lines) {
        $old = null;
        if ($bookingId !== null) {
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND version = ? FOR UPDATE');
            $st->execute([$bookingId, $version]);
            $old = $st->fetch();
            if (!$old) {
                throw new BookingConflict();
            }
            // Re-check against the locked row: the booking may have changed since the page loaded.
            if (!booking_allows($old, $user, 'edit') || $old['status'] !== 'draft') {
                throw new BookingValidationError(['status' => 'This booking is no longer a draft, so it can\'t be edited here.']);
            }
        }

        // An admin setting or changing the vendor: must be an active, approved vendor (shared lock; LOCK IN SHARE MODE works on MySQL 8 and MariaDB, FOR SHARE is MySQL-only).
        if ($user['role'] === 'admin' && $fields['vendor_id'] !== null
            && ($old === null || (int) $old['vendor_id'] !== $fields['vendor_id'])) {
            $st = $pdo->prepare("SELECT id, firm_name, rep_name, contact FROM users
                                  WHERE id = ? AND role = 'vendor' AND status = 'active' LOCK IN SHARE MODE");
            $st->execute([$fields['vendor_id']]);
            $vendor = $st->fetch();
            if (!$vendor) {
                throw new BookingValidationError(['vendor_id' => 'Vendor: choose an active, approved vendor.']);
            }
            // Blank snapshot fields are filled from the vendor's profile; typed values override it.
            $fields['firm_name'] = $fields['firm_name'] ?? $vendor['firm_name'];
            $fields['rep_name'] = $fields['rep_name'] ?? $vendor['rep_name'];
            $fields['rep_contact'] = $fields['rep_contact'] ?? $vendor['contact'];
        }

        // Cross-field money checks on the final line set, before anything is written.
        $chargeLines = [];
        foreach (booking_final_charge_lines($pdo, $bookingId, $lines) as $k => $l) {
            $chargeLines[$k] = $l;
        }
        $discount = decimal_to_paisa($fields['discount']);
        $totals = compute_totals(decimal_to_paisa($fields['per_head_rate']), (int) $fields['guests'], $discount, $chargeLines, [], 'draft');
        if ($problems = validate_totals($totals, $discount)) {
            throw new BookingValidationError($problems);
        }

        $columns = array_keys($fields);
        if ($old === null) {
            $uniqueId = next_sla_id($pdo, (int) date('Y'));
            $all = $columns;
            array_push($all, 'unique_id', 'created_by', 'updated_by', 'updated_at');
            $pdo->prepare('INSERT INTO bookings (' . implode(', ', $all) . ') VALUES ('
                . implode(', ', array_fill(0, count($columns) + 3, '?')) . ', NOW())')
                ->execute(array_merge(array_values($fields), [$uniqueId, $user['id'], $user['id']]));
            $id = (int) $pdo->lastInsertId();
        } else {
            $id = (int) $old['id'];
            $uniqueId = $old['unique_id'];
            $set = implode(', ', array_map(static fn($c) => "$c = ?", $columns));
            $st = $pdo->prepare("UPDATE bookings SET $set, updated_by = ?, updated_at = NOW(), version = version + 1
                                  WHERE id = ? AND version = ?");
            $st->execute(array_merge(array_values($fields), [$user['id'], $id, $version]));
            if ($st->rowCount() !== 1) {
                throw new BookingConflict();
            }
        }

        $lineChanges = write_booking_lines($pdo, $id, $lines);
        recompute_booking_totals($pdo, $id);

        if ($old === null) {
            audit($pdo, 'create', (int) $user['id'], $id, ['unique_id' => $uniqueId, 'fields' => array_filter($fields, static fn($v) => $v !== null && $v !== 0 && $v !== '0.00'), 'lines' => $lineChanges]);
        } else {
            $changed = booking_field_diff($old, $fields);
            if ($changed || $lineChanges) {
                audit($pdo, 'update', (int) $user['id'], $id, ['unique_id' => $uniqueId, 'changed' => $changed, 'lines' => $lineChanges]);
            }
        }
        return ['id' => $id, 'unique_id' => $uniqueId];
    }, $pdo);
}

/** Charge lines as they will be after this save (existing rows merged with the posted changes). */
function booking_final_charge_lines(PDO $pdo, ?int $bookingId, array $lines): array
{
    $final = [];
    if ($bookingId !== null) {
        $st = $pdo->prepare("SELECT id, unit_snapshot, is_selected, qty, rate FROM booking_line_items WHERE booking_id = ? AND section = 'charge'");
        $st->execute([$bookingId]);
        foreach ($st->fetchAll() as $row) {
            $final['l' . $row['id']] = ['unit' => $row['unit_snapshot'], 'selected' => (bool) $row['is_selected'],
                'rate' => $row['rate'] === null ? null : decimal_to_paisa($row['rate']), 'qty' => $row['qty'] === null ? null : (int) $row['qty']];
        }
    }
    foreach ($lines as $key => $line) {
        if ($line['section'] !== 'charge') {
            continue;
        }
        $final[$key] = ['unit' => $line['unit'], 'selected' => $line['new']['selected'],
            'rate' => $line['new']['rate'] === null ? null : decimal_to_paisa($line['new']['rate']), 'qty' => $line['new']['qty']];
    }
    return $final;
}

/**
 * Update existing lines and insert newly ticked catalog items (with label/unit/rate snapshots).
 * Amounts are left to recompute_booking_totals(). Returns a summary of what changed, for the audit log.
 */
function write_booking_lines(PDO $pdo, int $bookingId, array $lines): array
{
    $changes = [];
    $update = $pdo->prepare('UPDATE booking_line_items SET is_selected = ?, qty = ?, rate = ?, notes = ? WHERE id = ? AND booking_id = ?');
    $insert = $pdo->prepare('INSERT INTO booking_line_items (booking_id, catalog_id, section, label, unit_snapshot, is_selected, qty, rate, notes, sort_order)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($lines as $line) {
        $new = $line['new'];
        $isCharge = $line['section'] === 'charge';
        $rate = $isCharge ? $new['rate'] : null;
        if ($line['line_id'] === null) {
            $insert->execute([$bookingId, $line['catalog_id'], $line['section'], $line['label'], $line['unit'],
                $new['selected'] ? 1 : 0, $new['qty'], $rate, $new['notes'], $line['sort_order']]);
            $changes[] = ['added' => $line['label'], 'section' => $line['section'], 'rate' => $rate, 'qty' => $new['qty']];
            continue;
        }
        $before = ['selected' => (bool) $line['selected'], 'qty' => $line['qty'] === null ? null : (int) $line['qty'],
            'rate' => $line['rate'], 'notes' => $line['notes']];
        $after = ['selected' => $new['selected'], 'qty' => $new['qty'], 'rate' => $rate, 'notes' => $new['notes']];
        if ($before != $after) {
            $update->execute([$new['selected'] ? 1 : 0, $new['qty'], $rate, $new['notes'], $line['line_id'], $bookingId]);
            $changes[] = ['line' => $line['label'], 'from' => $before, 'to' => $after];
        }
    }
    return $changes;
}

/** Changed fields as [field => [old, new]], comparing database values as strings. */
function booking_field_diff(array $old, array $new): array
{
    $diff = [];
    foreach ($new as $field => $value) {
        $before = $old[$field] ?? null;
        $b = $before === null ? null : (string) $before;
        $a = $value === null ? null : (string) $value;
        if ($b !== $a) {
            $diff[$field] = [$before, $value];
        }
    }
    return $diff;
}

// ---------------------------------------------------------------------------
// Venue warnings (drafts never block; plan Section 9)
// ---------------------------------------------------------------------------

/** Other non-cancelled bookings on the same venue and date. */
function venue_clashes(PDO $pdo, ?int $bookingId, ?int $venueId, ?string $eventDate): array
{
    if ($venueId === null || $eventDate === null) {
        return [];
    }
    $st = $pdo->prepare("SELECT b.id, b.unique_id, b.status, v.name AS venue_name
                           FROM bookings b JOIN venues v ON v.id = b.venue_id
                          WHERE b.venue_id = ? AND b.event_date = ? AND b.status <> 'cancelled' AND b.id <> ?
                          ORDER BY FIELD(b.status, 'completed', 'confirmed', 'draft'), b.id");
    $st->execute([$venueId, $eventDate, $bookingId ?? 0]);
    return $st->fetchAll();
}

/** Warning text for clashes. Vendors aren't shown other vendors' SLA numbers. */
function venue_clash_messages(array $clashes, ?string $eventDate, bool $isAdmin): array
{
    $messages = [];
    foreach ($clashes as $c) {
        $who = $isAdmin ? ' (' . $c['unique_id'] . ')' : '';
        $date = date('d M Y', strtotime((string) $eventDate));
        $messages[] = $c['status'] === 'draft'
            ? "{$c['venue_name']} also has another draft booking on $date$who. Only one booking per venue per day can be confirmed."
            : "{$c['venue_name']} is already {$c['status']} for another booking on $date$who. This booking can't be confirmed for the same venue and date.";
    }
    return $messages;
}
