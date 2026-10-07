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
// Menu types (the booking form's Menu Type dropdown)
//
// Bookings store the chosen name as text, so renaming or retiring a menu type never changes an
// existing booking. "Other" is built into the form, so it can't be created here.
// ---------------------------------------------------------------------------

function menu_types_with_usage(PDO $pdo): array
{
    return $pdo->query('SELECT m.id, m.name, m.is_active, m.sort_order,
                               (SELECT COUNT(*) FROM bookings b WHERE b.menu_type = m.name) AS bookings
                          FROM menu_types m ORDER BY m.sort_order, m.name')->fetchAll();
}

/** Validate a menu type name. @throws AdminRefused */
function clean_menu_type_name($raw): string
{
    $name = clean_name($raw, 100, 'menu type name');
    if (strcasecmp($name, 'Other') === 0) {
        throw new AdminRefused('“Other” is always offered on the form, so it can\'t be added as a menu type.');
    }
    return $name;
}

function create_menu_type(PDO $pdo, array $admin, $nameRaw, $sortRaw): string
{
    $name = clean_menu_type_name($nameRaw);
    $sort = clean_sort_order($sortRaw);
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $name, $sort) {
            $pdo->prepare('INSERT INTO menu_types (name, is_active, sort_order) VALUES (?, 1, ?)')->execute([$name, $sort]);
            audit($pdo, 'catalog_change', (int) $admin['id'], null,
                ['menu_type_id' => (int) $pdo->lastInsertId(), 'created' => ['name' => $name, 'sort_order' => $sort]]);
            return $name;
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("There is already a menu type called “{$name}”.");
        }
        throw $e;
    }
}

/** Rename, re-sort or retire a menu type. Existing bookings keep the name they stored. */
function update_menu_type(PDO $pdo, array $admin, int $id, $nameRaw, bool $isActive, $sortRaw): string
{
    $name = clean_menu_type_name($nameRaw);
    $sort = clean_sort_order($sortRaw);
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $id, $name, $isActive, $sort) {
            $st = $pdo->prepare('SELECT * FROM menu_types WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) {
                throw new AdminRefused('That menu type no longer exists.');
            }
            $pdo->prepare('UPDATE menu_types SET name = ?, is_active = ?, sort_order = ? WHERE id = ?')
                ->execute([$name, $isActive ? 1 : 0, $sort, $id]);
            $changed = [];
            foreach (['name' => $name, 'is_active' => $isActive ? 1 : 0, 'sort_order' => $sort] as $field => $value) {
                if ((string) $row[$field] !== (string) $value) {
                    $changed[$field] = [$row[$field], $value];
                }
            }
            if ($changed) {
                audit($pdo, 'catalog_change', (int) $admin['id'], null, ['menu_type_id' => $id, 'changed' => $changed]);
            }
            return $row['name'];
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("There is already a menu type called “{$name}”.");
        }
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// Form options (Stage, Entrance, Lighting and Floor Covering dropdowns; see FORM_OPTION_LISTS)
//
// Same rules as menu types: bookings store the chosen name as text, and "Other" is built in.
// ---------------------------------------------------------------------------

/** @return array<string, array> list key => rows (id, name, is_active, sort_order, bookings) */
function form_options_with_usage(PDO $pdo): array
{
    $byList = [];
    foreach (FORM_OPTION_LISTS as $list => [$column]) {
        // $column comes from the FORM_OPTION_LISTS constant, never from input.
        $st = $pdo->prepare("SELECT o.id, o.name, o.is_active, o.sort_order,
                                    (SELECT COUNT(*) FROM bookings b WHERE b.`$column` = o.name) AS bookings
                               FROM form_options o WHERE o.list_key = ? ORDER BY o.sort_order, o.name");
        $st->execute([$list]);
        $byList[$list] = $st->fetchAll();
    }
    return $byList;
}

/**
 * Save the standard refund policy that new bookings start with. An empty box clears that default.
 * Bookings that already exist keep their own terms.
 *
 * @throws AdminRefused
 */
function set_booking_defaults(PDO $pdo, array $admin, array $post): void
{
    $new = [];
    foreach (BOOKING_DEFAULT_FIELDS as $field => $label) {
        try {
            $hundredths = parse_percent(is_string($post[$field] ?? null) ? $post[$field] : '');
        } catch (InvalidInput $e) {
            throw new AdminRefused("$label: " . $e->getMessage());
        }
        $new[$field] = $hundredths === null ? null : paisa_to_decimal($hundredths);
    }
    db_transaction(static function (PDO $pdo) use ($admin, $new) {
        $before = booking_defaults($pdo);
        $changed = [];
        $upsert = $pdo->prepare('INSERT INTO booking_defaults (field, value) VALUES (?, ?)
                                 ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($new as $field => $value) {
            $upsert->execute([$field, $value]);
            if ($before[$field] !== $value) {
                $changed[$field] = [$before[$field], $value];
            }
        }
        if ($changed) {
            audit($pdo, 'catalog_change', (int) $admin['id'], null, ['booking_defaults' => true, 'changed' => $changed]);
        }
    }, $pdo);
}

/** Show or hide one dropdown on the booking form. Bookings that already have a value keep it. */
function set_form_section_shown(PDO $pdo, array $admin, string $list, bool $shown): string
{
    if (!isset(FORM_OPTION_LISTS[$list])) {
        throw new AdminRefused('Unknown option list.');
    }
    return db_transaction(static function (PDO $pdo) use ($admin, $list, $shown) {
        $st = $pdo->prepare('SELECT is_shown FROM form_sections WHERE list_key = ? FOR UPDATE');
        $st->execute([$list]);
        $before = $st->fetchColumn();
        $pdo->prepare('INSERT INTO form_sections (list_key, is_shown) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE is_shown = VALUES(is_shown)')->execute([$list, $shown ? 1 : 0]);
        if ($before === false || (int) $before !== ($shown ? 1 : 0)) {
            audit($pdo, 'catalog_change', (int) $admin['id'], null,
                ['form_section' => $list, 'changed' => ['is_shown' => [$before === false ? 1 : (int) $before, $shown ? 1 : 0]]]);
        }
        return FORM_OPTION_LISTS[$list][1];
    }, $pdo);
}

/** @throws AdminRefused */
function clean_form_option_name($raw): string
{
    $name = clean_name($raw, 100, 'option name');
    if (strcasecmp($name, 'Other') === 0) {
        throw new AdminRefused('“Other” is always offered on the form, so it can\'t be added as an option.');
    }
    return $name;
}

function create_form_option(PDO $pdo, array $admin, string $list, $nameRaw, $sortRaw): string
{
    if (!isset(FORM_OPTION_LISTS[$list])) {
        throw new AdminRefused('Unknown option list.');
    }
    $name = clean_form_option_name($nameRaw);
    $sort = clean_sort_order($sortRaw);
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $list, $name, $sort) {
            $pdo->prepare('INSERT INTO form_options (list_key, name, is_active, sort_order) VALUES (?, ?, 1, ?)')
                ->execute([$list, $name, $sort]);
            audit($pdo, 'catalog_change', (int) $admin['id'], null,
                ['form_option_id' => (int) $pdo->lastInsertId(), 'list' => $list, 'created' => ['name' => $name, 'sort_order' => $sort]]);
            return $name;
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("“{$name}” is already in that list.");
        }
        throw $e;
    }
}

/** Rename, re-sort or retire an option. Existing bookings keep the name they stored. */
function update_form_option(PDO $pdo, array $admin, int $id, $nameRaw, bool $isActive, $sortRaw): string
{
    $name = clean_form_option_name($nameRaw);
    $sort = clean_sort_order($sortRaw);
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $id, $name, $isActive, $sort) {
            $st = $pdo->prepare('SELECT * FROM form_options WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) {
                throw new AdminRefused('That option no longer exists.');
            }
            $pdo->prepare('UPDATE form_options SET name = ?, is_active = ?, sort_order = ? WHERE id = ?')
                ->execute([$name, $isActive ? 1 : 0, $sort, $id]);
            $changed = [];
            foreach (['name' => $name, 'is_active' => $isActive ? 1 : 0, 'sort_order' => $sort] as $field => $value) {
                if ((string) $row[$field] !== (string) $value) {
                    $changed[$field] = [$row[$field], $value];
                }
            }
            if ($changed) {
                audit($pdo, 'catalog_change', (int) $admin['id'], null,
                    ['form_option_id' => $id, 'list' => $row['list_key'], 'changed' => $changed]);
            }
            return $row['name'];
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("“{$name}” is already in that list.");
        }
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// User accounts
//
// Shared by the Users page and the Approvals page, so both screens apply exactly the same rules.
// ---------------------------------------------------------------------------

/** Allowed status changes: action => [statuses it can start from, new status, audit action]. */
const USER_TRANSITIONS = [
    'approve' => [['pending', 'disabled'], 'active', 'user_approve'],
    'disable' => [['pending', 'active'], 'disabled', 'user_disable'],
];

/**
 * Approve, disable, delete or reset the password of one user account, in a single transaction.
 *
 * @return array{0: string, 1: string, 2?: array{username: string, password: string}}
 *         [flash type, message] and, for a password reset, the temporary password to show once.
 */
function apply_user_action(PDO $pdo, array $admin, int $userId, string $action): array
{
    return db_transaction(static function (PDO $pdo) use ($userId, $action, $admin) {
        $st = $pdo->prepare("SELECT id, username, status FROM users WHERE id = ? AND role = 'user' FOR UPDATE");
        $st->execute([$userId]);
        $user = $st->fetch();
        if (!$user) {
            return ['error', 'That user account no longer exists.'];
        }

        if (isset(USER_TRANSITIONS[$action])) {
            [$from, $to, $auditAction] = USER_TRANSITIONS[$action];
            if (!in_array($user['status'], $from, true)) {
                return ['error', "“{$user['username']}” is {$user['status']}; that action doesn't apply."];
            }
            $pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$to, $userId]);
            audit($pdo, $auditAction, (int) $admin['id'], null,
                ['user_id' => $userId, 'username' => $user['username'], 'status' => [$user['status'], $to]]);
            return ['ok', "“{$user['username']}” is now $to."];
        }

        if ($action === 'reset') {
            $temp = generate_temp_password();
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
                ->execute([password_hash($temp, PASSWORD_DEFAULT), $userId]);
            audit($pdo, 'password_reset', (int) $admin['id'], null, ['user_id' => $userId, 'username' => $user['username']]);
            return ['ok', "Password reset for “{$user['username']}”.", ['username' => $user['username'], 'password' => $temp]];
        }

        if ($action === 'delete') {
            // Only an account that never did anything can go: bookings, payments and files keep who made them.
            if (user_record_count($pdo, $userId) > 0) {
                return ['error', "“{$user['username']}” has bookings or other records, so it can't be deleted. Disable it instead."];
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            audit($pdo, 'user_delete', (int) $admin['id'], null, ['user_id' => $userId, 'username' => $user['username']]);
            return ['ok', "“{$user['username']}” has been deleted."];
        }

        return ['error', 'Unknown action.'];
    }, $pdo);
}

/** How many bookings, payments and files point at this user (any of them blocks deleting the account). */
function user_record_count(PDO $pdo, int $userId): int
{
    $st = $pdo->prepare('SELECT
        (SELECT COUNT(*) FROM bookings WHERE ? IN (user_id, created_by, updated_by, cancelled_by))
      + (SELECT COUNT(*) FROM payments WHERE ? IN (recorded_by, voided_by))
      + (SELECT COUNT(*) FROM attachments WHERE ? IN (uploaded_by, voided_by))');
    $st->execute([$userId, $userId, $userId]);
    return (int) $st->fetchColumn();
}

/**
 * Validate the admin's "Add user" form. Same fields and limits as the self-registration page.
 *
 * @return array{firm_name: string, rep_name: string, contact: string, username: string}
 * @throws AdminRefused
 */
function clean_user_input(array $post): array
{
    $data = [
        'firm_name' => clean_name($post['firm_name'] ?? '', 150, 'firm name'),
        'rep_name'  => clean_name($post['rep_name'] ?? '', 100, 'representative name'),
        'contact'   => clean_name($post['contact'] ?? '', 50, 'contact number'),
        'username'  => normalize_username(is_string($post['username'] ?? null) ? $post['username'] : ''),
    ];
    if (!is_valid_username($data['username'])) {
        throw new AdminRefused('Choose a username of 3–50 characters: letters, digits, dot, underscore or hyphen.');
    }
    return $data;
}

/**
 * Create a user account on the admin's behalf. It is active straight away (an admin made it, so there is
 * nothing to approve) and gets a temporary password the user must replace when they first sign in.
 *
 * @param array $data from clean_user_input()
 * @return array{username: string, password: string} the temporary password, to show the admin once
 * @throws AdminRefused when the username is taken
 */
function create_user(PDO $pdo, array $admin, array $data): array
{
    $temp = generate_temp_password();
    try {
        db_transaction(static function (PDO $pdo) use ($admin, $data, $temp) {
            $pdo->prepare("INSERT INTO users (username, password_hash, role, name, firm_name, rep_name, contact, status, must_change_password)
                           VALUES (?, ?, 'user', ?, ?, ?, ?, 'active', 1)")
                ->execute([$data['username'], password_hash($temp, PASSWORD_DEFAULT), $data['rep_name'],
                    $data['firm_name'], $data['rep_name'], $data['contact']]);
            $id = (int) $pdo->lastInsertId();
            audit($pdo, 'user_create', (int) $admin['id'], null, ['user_id' => $id] + $data);
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1062) { // 1062 = duplicate key: the username is taken
            throw $e;
        }
        throw new AdminRefused("The username “{$data['username']}” is already taken. Please choose another.");
    }
    return ['username' => $data['username'], 'password' => $temp];
}

/** User accounts still waiting for a decision, oldest request first. */
function pending_users(PDO $pdo): array
{
    return $pdo->query("SELECT id, username, name, firm_name, rep_name, contact, created_at
                          FROM users WHERE role = 'user' AND status = 'pending'
                         ORDER BY created_at, username")->fetchAll();
}
