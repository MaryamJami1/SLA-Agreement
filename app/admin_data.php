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
    return $pdo->query('SELECT v.id, v.name, v.is_active, v.sort_order,
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

function clean_sort_order($raw): int
{
    try {
        return parse_whole_number($raw, 9999) ?? 0;
    } catch (InvalidInput $e) {
        throw new AdminRefused('Sort order: ' . $e->getMessage());
    }
}

function create_venue(PDO $pdo, array $admin, $nameRaw, $sortRaw): string
{
    $name = clean_name($nameRaw, 100, 'venue name');
    $sort = clean_sort_order($sortRaw);
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $name, $sort) {
            $pdo->prepare('INSERT INTO venues (name, is_active, sort_order) VALUES (?, 1, ?)')->execute([$name, $sort]);
            $id = (int) $pdo->lastInsertId();
            audit($pdo, 'venue_change', (int) $admin['id'], null, ['venue_id' => $id, 'created' => ['name' => $name, 'sort_order' => $sort]]);
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
 * Rename, re-sort or activate/deactivate a venue. A venue any booking has ever used can't be renamed
 * (bookings point at the row, so its meaning must not change) — only deactivated.
 */
function update_venue(PDO $pdo, array $admin, int $venueId, $nameRaw, bool $isActive, $sortRaw): string
{
    $name = clean_name($nameRaw, 100, 'venue name');
    $sort = clean_sort_order($sortRaw);
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $venueId, $name, $isActive, $sort) {
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
            $pdo->prepare('UPDATE venues SET name = ?, is_active = ?, sort_order = ? WHERE id = ?')
                ->execute([$name, $isActive ? 1 : 0, $sort, $venueId]);
            $changed = [];
            foreach (['name' => $name, 'is_active' => $isActive ? 1 : 0, 'sort_order' => $sort] as $field => $value) {
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
