<?php
/**
 * Admin-managed reference data: venues (plan Section 5, "Venue history is protected") and the item
 * catalog (charges, decor checklist, operations items).
 *
 * Bookings store snapshots of catalog labels, units and rates, so renaming or retiring an item never
 * changes an existing booking. Venues are different: bookings reference venue rows, so a venue that
 * has ever been used can only be deactivated.
 */
declare(strict_types=1);

const CATALOG_UNITS = ['fixed', 'per unit', 'per head'];

/** The change is not allowed; the message is shown to the admin. */
class AdminRefused extends RuntimeException {}

// ---------------------------------------------------------------------------
// Venues
// ---------------------------------------------------------------------------

function venues_with_usage(PDO $pdo): array
{
    return $pdo->query('SELECT v.id, v.name, v.location, v.is_active, v.sort_order,
                               (SELECT COUNT(*) FROM bookings b WHERE b.venue_id = v.id) AS bookings
                          FROM venues v ORDER BY v.sort_order, v.name')->fetchAll();
}

function clean_name($raw, int $max, string $label): string
{
    $name = trim(is_string($raw) ? $raw : '');
    if ($name === '') {
        throw new AdminRefused("Enter the $label.");
    }
    if (mb_strlen($name) > $max) {
        throw new AdminRefused(ucfirst($label) . " can be at most $max characters.");
    }
    return $name;
}

/** Same as clean_name(), but an empty value is allowed and stored as NULL. */
function clean_optional_name($raw, int $max, string $label): ?string
{
    $value = trim(is_string($raw) ? $raw : '');
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value) > $max) {
        throw new AdminRefused(ucfirst($label) . " can be at most $max characters.");
    }
    return $value;
}

function clean_sort_order($raw): int
{
    try {
        return parse_whole_number($raw, 9999) ?? 0;
    } catch (InvalidInput $e) {
        throw new AdminRefused('Sort order: ' . $e->getMessage());
    }
}

function create_venue(PDO $pdo, array $admin, $nameRaw, $locationRaw, $sortRaw): string
{
    $name = clean_name($nameRaw, 100, 'venue name');
    $location = clean_optional_name($locationRaw, 150, 'venue location');
    $sort = clean_sort_order($sortRaw);
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $name, $location, $sort) {
            $pdo->prepare('INSERT INTO venues (name, location, is_active, sort_order) VALUES (?, ?, 1, ?)')
                ->execute([$name, $location, $sort]);
            $id = (int) $pdo->lastInsertId();
            audit($pdo, 'venue_change', (int) $admin['id'], null,
                ['venue_id' => $id, 'created' => ['name' => $name, 'location' => $location, 'sort_order' => $sort]]);
            return $name;
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("There is already a venue called “{$name}”.");
        }
        throw $e;
    }
}

/**
 * Rename, re-locate, re-sort or activate/deactivate a venue. A venue any booking has ever used can't
 * be renamed (bookings point at the row, so its meaning must not change) — only deactivated.
 * The location is free to change: each booking stored its own copy, so history is unaffected.
 */
function update_venue(PDO $pdo, array $admin, int $venueId, $nameRaw, $locationRaw, bool $isActive, $sortRaw): string
{
    $name = clean_name($nameRaw, 100, 'venue name');
    $location = clean_optional_name($locationRaw, 150, 'venue location');
    $sort = clean_sort_order($sortRaw);
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $venueId, $name, $location, $isActive, $sort) {
            $st = $pdo->prepare('SELECT * FROM venues WHERE id = ? FOR UPDATE');
            $st->execute([$venueId]);
            $venue = $st->fetch();
            if (!$venue) {
                throw new AdminRefused('That venue no longer exists.');
            }
            if ($name !== $venue['name'] && venue_is_used($pdo, $venueId)) {
                throw new AdminRefused("“{$venue['name']}” is used by at least one booking, so it can't be renamed. "
                    . 'Deactivate it and create the new name as a separate venue.');
            }
            $pdo->prepare('UPDATE venues SET name = ?, location = ?, is_active = ?, sort_order = ? WHERE id = ?')
                ->execute([$name, $location, $isActive ? 1 : 0, $sort, $venueId]);
            $changed = [];
            foreach (['name' => $name, 'location' => $location, 'is_active' => $isActive ? 1 : 0, 'sort_order' => $sort] as $field => $value) {
                if ((string) $venue[$field] !== (string) $value) {
                    $changed[$field] = [$venue[$field], $value];
                }
            }
            if ($changed) {
                audit($pdo, 'venue_change', (int) $admin['id'], null, ['venue_id' => $venueId, 'changed' => $changed]);
            }
            return $venue['name'];
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("There is already a venue called “{$name}”.");
        }
        throw $e;
    }
}

/** A venue no booking has ever referenced can be deleted (e.g. to fix a typo right after creating it). */
function delete_venue(PDO $pdo, array $admin, int $venueId): string
{
    return db_transaction(static function (PDO $pdo) use ($admin, $venueId) {
        $st = $pdo->prepare('SELECT * FROM venues WHERE id = ? FOR UPDATE');
        $st->execute([$venueId]);
        $venue = $st->fetch();
        if (!$venue) {
            throw new AdminRefused('That venue no longer exists.');
        }
        if (venue_is_used($pdo, $venueId)) {
            throw new AdminRefused("“{$venue['name']}” is used by at least one booking, so it can't be deleted. Deactivate it instead.");
        }
        $pdo->prepare('DELETE FROM venues WHERE id = ?')->execute([$venueId]);
        audit($pdo, 'venue_change', (int) $admin['id'], null, ['venue_id' => $venueId, 'deleted' => ['name' => $venue['name']]]);
        return $venue['name'];
    }, $pdo);
}

/** True when any booking (any status, drafts included) references this venue. */
function venue_is_used(PDO $pdo, int $venueId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM bookings WHERE venue_id = ? LIMIT 1');
    $st->execute([$venueId]);
    return (bool) $st->fetchColumn();
}

// ---------------------------------------------------------------------------
// Event slots (per venue; see app/slots.php)
// ---------------------------------------------------------------------------

/** Every slot of a venue, active or not, with how many bookings have ever used it. */
function venue_slots_with_usage(PDO $pdo, int $venueId): array
{
    $st = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM bookings b WHERE b.slot_id = s.id) AS bookings
                           FROM venue_slots s WHERE s.venue_id = ? ORDER BY s.sort_order, s.start_time, s.id');
    $st->execute([$venueId]);
    return $st->fetchAll();
}

/** 'HH:MM' from a time input → 'HH:MM:00'. */
function clean_slot_time($raw, string $label): string
{
    $raw = trim(is_string($raw) ? $raw : '');
    if ($raw === '') {
        throw new AdminRefused("Enter the $label.");
    }
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::00)?$/', $raw, $m) !== 1) {
        throw new AdminRefused(ucfirst($label) . ': enter a valid time.');
    }
    return "{$m[1]}:{$m[2]}:00";
}

/**
 * The posted slot, validated. An end at or before the start is only accepted as "the next day" when
 * the admin says so (ticks "Ends after midnight"), so 3:00 PM → 12:00 PM can't become a 21-hour slot
 * by a slip. Ending exactly at 12:00 AM (midnight) needs no tick.
 *
 * @return array{name: string, icon: ?string, start_time: string, end_time: string, sort_order: int}
 */
function clean_slot_input(array $post): array
{
    $name = clean_name($post['name'] ?? '', SLOT_NAME_MAX, 'slot name');
    $icon = clean_optional_name($post['icon'] ?? '', SLOT_ICON_MAX, 'icon');
    $start = clean_slot_time($post['start_time'] ?? '', 'start time');
    $end = clean_slot_time($post['end_time'] ?? '', 'end time');
    $nextDay = isset($post['ends_next_day']);
    if ($start === $end) {
        throw new AdminRefused('The end time must be different from the start time.');
    }
    $crosses = slot_crosses_midnight($start, $end);
    if ($crosses && !$nextDay && $end !== '00:00:00') {
        throw new AdminRefused('The end time (' . slot_time_label($end) . ') is before the start time (' . slot_time_label($start)
            . '). If the slot runs past midnight, tick “Ends after midnight”.');
    }
    if (!$crosses && $nextDay) {
        throw new AdminRefused('“Ends after midnight” is ticked, but ' . slot_time_label($end) . ' is after ' . slot_time_label($start)
            . ' on the same day. Untick it, or choose an end time after midnight.');
    }
    return ['name' => $name, 'icon' => $icon, 'start_time' => $start, 'end_time' => $end,
        'sort_order' => clean_sort_order($post['sort_order'] ?? '0')];
}

/** Refuse when the slot would overlap another active slot of the same venue. */
function assert_no_slot_overlap(PDO $pdo, int $venueId, ?int $exceptSlotId, string $start, string $end): void
{
    foreach (venue_slots($pdo, $venueId) as $other) {
        if ((int) $other['id'] !== $exceptSlotId && slots_overlap($start, $end, $other['start_time'], $other['end_time'])) {
            throw new AdminRefused(slot_range_label($start, $end) . ' overlaps “' . $other['name'] . '” ('
                . slot_range_label($other['start_time'], $other['end_time']) . '). Slots at one venue can\'t overlap — '
                . 'change the times, or disable “' . $other['name'] . '” first.');
        }
    }
}

/** Lock the venue row: slot changes at one venue happen one at a time (and queue behind bookings). */
function lock_venue_for_slots(PDO $pdo, int $venueId): array
{
    $st = $pdo->prepare('SELECT * FROM venues WHERE id = ? FOR UPDATE');
    $st->execute([$venueId]);
    $venue = $st->fetch();
    if (!$venue) {
        throw new AdminRefused('That venue no longer exists.');
    }
    return $venue;
}

function slot_duplicate_name(PDOException $e, string $name): void
{
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        throw new AdminRefused("This venue already has a slot called “{$name}”.");
    }
    throw $e;
}

function create_venue_slot(PDO $pdo, array $admin, int $venueId, array $slot): string
{
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $venueId, $slot) {
            lock_venue_for_slots($pdo, $venueId);
            assert_no_slot_overlap($pdo, $venueId, null, $slot['start_time'], $slot['end_time']);
            $pdo->prepare('INSERT INTO venue_slots (venue_id, name, icon, start_time, end_time, sort_order, is_active)
                           VALUES (?, ?, ?, ?, ?, ?, 1)')
                ->execute([$venueId, $slot['name'], $slot['icon'], $slot['start_time'], $slot['end_time'], $slot['sort_order']]);
            audit($pdo, 'venue_change', (int) $admin['id'], null,
                ['venue_id' => $venueId, 'slot_created' => ['id' => (int) $pdo->lastInsertId()] + $slot]);
            return $slot['name'];
        }, $pdo);
    } catch (PDOException $e) {
        slot_duplicate_name($e, $slot['name']);
    }
}

/**
 * Change a slot's name, icon, times, order or status. Bookings keep the name and times they were made
 * with (their own snapshot), so this never changes an existing booking — only new ones.
 */
function update_venue_slot(PDO $pdo, array $admin, int $slotId, array $slot, bool $isActive): string
{
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $slotId, $slot, $isActive) {
            $old = slot_for_update($pdo, $slotId);
            if ($isActive) {
                assert_no_slot_overlap($pdo, (int) $old['venue_id'], $slotId, $slot['start_time'], $slot['end_time']);
            }
            $pdo->prepare('UPDATE venue_slots SET name = ?, icon = ?, start_time = ?, end_time = ?, sort_order = ?, is_active = ?,
                                  updated_at = NOW() WHERE id = ?')
                ->execute([$slot['name'], $slot['icon'], $slot['start_time'], $slot['end_time'], $slot['sort_order'], $isActive ? 1 : 0, $slotId]);
            $changed = [];
            foreach ($slot + ['is_active' => $isActive ? 1 : 0] as $field => $value) {
                if ((string) $old[$field] !== (string) $value) {
                    $changed[$field] = [$old[$field], $value];
                }
            }
            if ($changed) {
                audit($pdo, 'venue_change', (int) $admin['id'], null,
                    ['venue_id' => (int) $old['venue_id'], 'slot_id' => $slotId, 'slot_changed' => $changed]);
            }
            return $slot['name'];
        }, $pdo);
    } catch (PDOException $e) {
        slot_duplicate_name($e, $slot['name']);
    }
}

/** Enable or disable a slot. A disabled slot isn't offered on new bookings; bookings holding it keep it. */
function set_venue_slot_active(PDO $pdo, array $admin, int $slotId, bool $isActive): string
{
    return db_transaction(static function (PDO $pdo) use ($admin, $slotId, $isActive) {
        $old = slot_for_update($pdo, $slotId);
        if ($isActive) {
            assert_no_slot_overlap($pdo, (int) $old['venue_id'], $slotId, $old['start_time'], $old['end_time']);
        }
        if ((int) $old['is_active'] !== ($isActive ? 1 : 0)) {
            $pdo->prepare('UPDATE venue_slots SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([$isActive ? 1 : 0, $slotId]);
            audit($pdo, 'venue_change', (int) $admin['id'], null,
                ['venue_id' => (int) $old['venue_id'], 'slot_id' => $slotId, 'slot_changed' => ['is_active' => [(int) $old['is_active'], $isActive ? 1 : 0]]]);
        }
        return $old['name'];
    }, $pdo);
}

/** Delete a slot no booking has ever used (any status). A used slot can only be disabled. */
function delete_venue_slot(PDO $pdo, array $admin, int $slotId): string
{
    return db_transaction(static function (PDO $pdo) use ($admin, $slotId) {
        $old = slot_for_update($pdo, $slotId);
        $st = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE slot_id = ?');
        $st->execute([$slotId]);
        if ((int) $st->fetchColumn() > 0) {
            throw new AdminRefused("“{$old['name']}” is used by at least one booking, so it can't be deleted — those bookings "
                . 'would lose their slot. Disable it instead: it stops being offered, and existing bookings keep it.');
        }
        $pdo->prepare('DELETE FROM venue_slots WHERE id = ?')->execute([$slotId]);
        audit($pdo, 'venue_change', (int) $admin['id'], null,
            ['venue_id' => (int) $old['venue_id'], 'slot_deleted' => ['id' => $slotId, 'name' => $old['name']]]);
        return $old['name'];
    }, $pdo);
}

/** Copy another venue's active slots to a venue that has none yet (a quick start for a new venue). */
function copy_venue_slots(PDO $pdo, array $admin, int $fromVenueId, int $toVenueId): int
{
    if ($fromVenueId === $toVenueId) {
        throw new AdminRefused('Choose a different venue to copy from.');
    }
    return db_transaction(static function (PDO $pdo) use ($admin, $fromVenueId, $toVenueId) {
        lock_venue_for_slots($pdo, $toVenueId);
        $st = $pdo->prepare('SELECT COUNT(*) FROM venue_slots WHERE venue_id = ?');
        $st->execute([$toVenueId]);
        if ((int) $st->fetchColumn() > 0) {
            throw new AdminRefused('This venue already has slots. Copying is only for a venue with none yet.');
        }
        $source = venue_slots($pdo, $fromVenueId);
        if (!$source) {
            throw new AdminRefused('That venue has no active slots to copy.');
        }
        $insert = $pdo->prepare('INSERT INTO venue_slots (venue_id, name, icon, start_time, end_time, sort_order, is_active)
                                 VALUES (?, ?, ?, ?, ?, ?, 1)');
        foreach ($source as $s) {
            $insert->execute([$toVenueId, $s['name'], $s['icon'], $s['start_time'], $s['end_time'], $s['sort_order']]);
        }
        audit($pdo, 'venue_change', (int) $admin['id'], null,
            ['venue_id' => $toVenueId, 'slots_copied_from' => $fromVenueId, 'count' => count($source)]);
        return count($source);
    }, $pdo);
}

/** The slot row, locked after its venue (same lock order as bookings: venue first). */
function slot_for_update(PDO $pdo, int $slotId): array
{
    $st = $pdo->prepare('SELECT venue_id FROM venue_slots WHERE id = ?');
    $st->execute([$slotId]);
    $venueId = $st->fetchColumn();
    if ($venueId === false) {
        throw new AdminRefused('That slot no longer exists.');
    }
    lock_venue_for_slots($pdo, (int) $venueId);
    $st = $pdo->prepare('SELECT * FROM venue_slots WHERE id = ? FOR UPDATE');
    $st->execute([$slotId]);
    $slot = $st->fetch();
    if (!$slot) {
        throw new AdminRefused('That slot no longer exists.');
    }
    return $slot;
}

// ---------------------------------------------------------------------------
// Item catalog
// ---------------------------------------------------------------------------

function catalog_items(PDO $pdo): array
{
    $items = $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM booking_line_items l WHERE l.catalog_id = c.id) AS used
                            FROM item_catalog c ORDER BY c.section, c.sort_order, c.id')->fetchAll();
    $bySection = [];
    foreach ($items as $item) {
        $bySection[$item['section']][] = $item;
    }
    return $bySection;
}

/** Validate the posted catalog fields. @throws AdminRefused */
function clean_catalog_input(array $post, bool $withSection): array
{
    $data = [
        'name'       => clean_name($post['name'] ?? '', 150, 'item name'),
        'unit'       => (string) ($post['unit'] ?? ''),
        'sort_order' => clean_sort_order($post['sort_order'] ?? '0'),
        'is_active'  => isset($post['is_active']) ? 1 : 0,
    ];
    if (!in_array($data['unit'], CATALOG_UNITS, true)) {
        throw new AdminRefused('Choose how the item is charged: fixed, per unit or per head.');
    }
    try {
        $rate = parse_money(is_string($post['default_rate'] ?? null) ? $post['default_rate'] : '');
    } catch (InvalidInput $e) {
        throw new AdminRefused('Default rate: ' . $e->getMessage());
    }
    $data['default_rate'] = $rate === null ? null : paisa_to_decimal($rate);
    if ($withSection) {
        $data['section'] = (string) ($post['section'] ?? '');
        if (!isset(LINE_SECTIONS[$data['section']])) {
            throw new AdminRefused('Choose the section the item belongs to.');
        }
    }
    return $data;
}

function create_catalog_item(PDO $pdo, array $admin, array $data): string
{
    return db_transaction(static function (PDO $pdo) use ($admin, $data) {
        $pdo->prepare('INSERT INTO item_catalog (section, name, unit, default_rate, is_active, sort_order)
                       VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$data['section'], $data['name'], $data['unit'], $data['default_rate'], $data['is_active'], $data['sort_order']]);
        $id = (int) $pdo->lastInsertId();
        audit($pdo, 'catalog_change', (int) $admin['id'], null, ['catalog_id' => $id, 'created' => $data]);
        return $data['name'];
    }, $pdo);
}

/**
 * Rename, re-rate, re-unit, re-sort or retire a catalog item. Existing bookings are untouched: they
 * keep the label, unit and rate copied onto them when the line was added.
 */
function update_catalog_item(PDO $pdo, array $admin, int $itemId, array $data): string
{
    return db_transaction(static function (PDO $pdo) use ($admin, $itemId, $data) {
        $st = $pdo->prepare('SELECT * FROM item_catalog WHERE id = ? FOR UPDATE');
        $st->execute([$itemId]);
        $item = $st->fetch();
        if (!$item) {
            throw new AdminRefused('That catalog item no longer exists.');
        }
        $pdo->prepare('UPDATE item_catalog SET name = ?, unit = ?, default_rate = ?, is_active = ?, sort_order = ? WHERE id = ?')
            ->execute([$data['name'], $data['unit'], $data['default_rate'], $data['is_active'], $data['sort_order'], $itemId]);
        $changed = [];
        foreach (['name', 'unit', 'default_rate', 'is_active', 'sort_order'] as $field) {
            if ((string) $item[$field] !== (string) $data[$field]) {
                $changed[$field] = [$item[$field], $data[$field]];
            }
        }
        if ($changed) {
            audit($pdo, 'catalog_change', (int) $admin['id'], null, ['catalog_id' => $itemId, 'changed' => $changed]);
        }
        return $item['name'];
    }, $pdo);
}

// ---------------------------------------------------------------------------
// Vendor accounts
//
// Shared by the Vendors page and the Approvals page, so both screens apply exactly the same rules.
// ---------------------------------------------------------------------------

/** Allowed status changes: action => [statuses it can start from, new status, audit action]. */
const VENDOR_TRANSITIONS = [
    'approve' => [['pending', 'disabled'], 'active', 'vendor_approve'],
    'disable' => [['pending', 'active'], 'disabled', 'vendor_disable'],
];

/**
 * Approve, disable or reset the password of one vendor account, in a single transaction.
 *
 * @return array{0: string, 1: string, 2?: array{username: string, password: string}}
 *         [flash type, message] and, for a password reset, the temporary password to show once.
 */
function apply_vendor_action(PDO $pdo, array $admin, int $vendorId, string $action): array
{
    return db_transaction(static function (PDO $pdo) use ($vendorId, $action, $admin) {
        $st = $pdo->prepare("SELECT id, username, status FROM users WHERE id = ? AND role = 'vendor' FOR UPDATE");
        $st->execute([$vendorId]);
        $vendor = $st->fetch();
        if (!$vendor) {
            return ['error', 'That vendor account no longer exists.'];
        }

        if (isset(VENDOR_TRANSITIONS[$action])) {
            [$from, $to, $auditAction] = VENDOR_TRANSITIONS[$action];
            if (!in_array($vendor['status'], $from, true)) {
                return ['error', "“{$vendor['username']}” is {$vendor['status']}; that action doesn't apply."];
            }
            $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$to, $vendorId]);
            audit($pdo, $auditAction, (int) $admin['id'], null,
                ['vendor_id' => $vendorId, 'username' => $vendor['username'], 'status' => [$vendor['status'], $to]]);
            return ['ok', "“{$vendor['username']}” is now $to."];
        }

        if ($action === 'reset') {
            $temp = generate_temp_password();
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
                ->execute([password_hash($temp, PASSWORD_DEFAULT), $vendorId]);
            audit($pdo, 'password_reset', (int) $admin['id'], null, ['vendor_id' => $vendorId, 'username' => $vendor['username']]);
            return ['ok', "Password reset for “{$vendor['username']}”.", ['username' => $vendor['username'], 'password' => $temp]];
        }

        return ['error', 'Unknown action.'];
    }, $pdo);
}

/** Vendor accounts still waiting for a decision, oldest request first. */
function pending_vendors(PDO $pdo): array
{
    return $pdo->query("SELECT id, username, name, firm_name, rep_name, contact, created_at
                          FROM users WHERE role = 'vendor' AND status = 'pending'
                         ORDER BY created_at, username")->fetchAll();
}
