<?php
/**
 * Menus: categories, the dish library and priced packages (Menus page), and how a booking picks one.
 *
 * A package lists dishes. Dishes in a package that share a choice_group are alternatives ("Beef
 * Biryani OR Beef Pulao"): the booking picks one per group. A booking may also add extra dishes from
 * the library, with or without a package.
 *
 * The booking keeps its own copy — bookings.menu_package_name and the JSON list in
 * bookings.menu_selection — so renaming, re-pricing or retiring anything here never changes a booking
 * that already exists. Categories, dishes and packages are retired (unticked "Offered"), never
 * deleted; only a dish's place in a package can be removed.
 *
 * One entry of bookings.menu_selection:
 *   cat, dish   category and dish names, as they were when chosen
 *   live, free  printed as "(Live)" / "(Free)"
 *   extra       true for a dish added on top of the package
 *   group, item the choice group and package item it was picked from (null for included dishes)
 *   dish_id     the dish it came from
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Reading the catalog
// ---------------------------------------------------------------------------

/** Every category, with how many dishes it holds. */
function menu_categories(PDO $pdo): array
{
    return $pdo->query('SELECT c.*, (SELECT COUNT(*) FROM menu_dishes d WHERE d.category_id = c.id) AS dishes
                          FROM menu_categories c ORDER BY c.sort_order, c.name')->fetchAll();
}

const MENU_DISH_SELECT = 'SELECT d.*, c.name AS category, c.sort_order AS category_sort, c.is_active AS category_active
                            FROM menu_dishes d JOIN menu_categories c ON c.id = d.category_id';
const MENU_DISH_ORDER = ' ORDER BY c.sort_order, c.name, d.sort_order, d.name';

/** Every dish with its category and how many packages use it, in menu order. */
function menu_dishes(PDO $pdo): array
{
    return $pdo->query('SELECT d.*, c.name AS category, c.is_active AS category_active,
                               (SELECT COUNT(*) FROM menu_package_items i WHERE i.dish_id = d.id) AS packages
                          FROM menu_dishes d JOIN menu_categories c ON c.id = d.category_id' . MENU_DISH_ORDER)->fetchAll();
}

/**
 * The dishes the booking form offers as extras: offered dishes in offered categories, plus any the
 * booking already has (so a retired dish it chose still shows, ticked).
 */
function menu_dishes_for_form(PDO $pdo, array $chosenIds): array
{
    $ids = array_values(array_map('intval', $chosenIds)) ?: [0];
    $st = $pdo->prepare(MENU_DISH_SELECT . ' WHERE (d.is_active = 1 AND c.is_active = 1) OR d.id IN ('
        . implode(', ', array_fill(0, count($ids), '?')) . ')' . MENU_DISH_ORDER);
    $st->execute($ids);
    return $st->fetchAll();
}

/** Every package, with how many bookings chose it. */
function menu_packages(PDO $pdo): array
{
    return $pdo->query('SELECT p.*, (SELECT COUNT(*) FROM bookings b WHERE b.menu_package_id = p.id) AS bookings
                          FROM menu_packages p ORDER BY p.sort_order, p.name')->fetchAll();
}

/** @return array<int, list<array>> package id => its dishes in menu order */
function menu_package_items(PDO $pdo, array $packageIds): array
{
    $ids = array_values(array_map('intval', $packageIds));
    if (!$ids) {
        return [];
    }
    $st = $pdo->prepare('SELECT i.*, d.name AS dish, d.is_live, d.is_active AS dish_active,
                                c.name AS category, c.sort_order AS category_sort
                           FROM menu_package_items i
                           JOIN menu_dishes d ON d.id = i.dish_id
                           JOIN menu_categories c ON c.id = d.category_id
                          WHERE i.package_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')
                          ORDER BY c.sort_order, c.name, i.sort_order, d.sort_order, d.name');
    $st->execute($ids);
    $byPackage = [];
    foreach ($st->fetchAll() as $row) {
        $byPackage[(int) $row['package_id']][] = $row;
    }
    return $byPackage;
}

/** Packages the booking form offers: offered ones plus the booking's own, each with its 'items'. */
function menu_packages_for_form(PDO $pdo, ?int $currentId): array
{
    $st = $pdo->prepare('SELECT * FROM menu_packages WHERE is_active = 1 OR id = ? ORDER BY sort_order, name');
    $st->execute([$currentId ?? 0]);
    $packages = [];
    foreach ($st->fetchAll() as $p) {
        $packages[(int) $p['id']] = $p + ['items' => []];
    }
    foreach (menu_package_items($pdo, array_keys($packages)) as $id => $items) {
        $packages[$id]['items'] = $items;
    }
    return $packages;
}

/**
 * A package's dishes as they read on a menu card, by category. Each category holds a list of
 * ['item' => row] for an included dish or ['choice' => group, 'items' => rows] for "pick one of",
 * placed where the group's first dish is.
 */
function menu_package_layout(array $items): array
{
    $layout = [];
    $groupAt = [];
    foreach ($items as $item) {
        $category = (string) $item['category'];
        if ($item['choice_group'] === null) {
            $layout[$category][] = ['item' => $item];
            continue;
        }
        $group = (int) $item['choice_group'];
        if (!isset($groupAt[$group])) {
            $groupAt[$group] = [$category, count($layout[$category] ?? [])];
            $layout[$category][] = ['choice' => $group, 'items' => []];
        }
        [$at, $pos] = $groupAt[$group];
        $layout[$at][$pos]['items'][] = $item;
    }
    return $layout;
}

/** "Lahori Chargha (Live)", "Gulab Jamun (Free)". A name that already says "live" isn't marked twice. */
function menu_dish_label(string $dish, bool $live, bool $free): string
{
    return $dish . ($live && stripos($dish, 'live') === false ? ' (Live)' : '') . ($free ? ' (Free)' : '');
}

/** How a package is priced: price_basis => [label on the admin page, what the booking's guest count counts]. */
const MENU_PRICE_BASES = [
    'guest' => ['Per guest', 'guests'],
    'box'   => ['Per box', 'boxes'],
    'group' => ['One price for a group', 'guests'],
];

/** "Rs. 575 per guest", "Rs. 250 per box", "Rs. 52,000 for 100 guests"; '' when the package has no price. */
function menu_package_price_label(array $p): string
{
    $basis = $p['price_basis'] ?? 'guest';
    if ($basis === 'group' && ($p['group_price'] ?? null) !== null) {
        return format_rs(decimal_to_paisa($p['group_price'])) . ' for ' . (int) $p['group_size'] . ' guests';
    }
    if (($p['per_head_rate'] ?? null) === null) {
        return '';
    }
    return format_rs(decimal_to_paisa($p['per_head_rate'])) . ($basis === 'box' ? ' per box' : ' per guest');
}

/** What the booking's guest count means for this package: 'guests' or 'boxes'. */
function menu_package_unit(?array $p): string
{
    return MENU_PRICE_BASES[$p['price_basis'] ?? 'guest'][1] ?? 'guests';
}

// ---------------------------------------------------------------------------
// A booking's menu
// ---------------------------------------------------------------------------

/** The booking's saved menu entries; [] when there is none. */
function menu_selection_entries(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
}

/** The saved menu as it prints: category => list of dish labels. */
function menu_selection_by_category(?string $json): array
{
    $byCategory = [];
    foreach (menu_selection_entries($json) as $e) {
        $byCategory[(string) ($e['cat'] ?? '')][] = menu_dish_label((string) ($e['dish'] ?? ''), !empty($e['live']), !empty($e['free']));
    }
    return $byCategory;
}

/**
 * What was chosen, by id rather than by name: the package, the dish picked in each choice group and
 * the extra dishes. Two equal keys are the same choice, so the booking keeps the copy it has.
 */
function menu_selection_key(?int $packageId, array $pickedItemIds, array $extraDishIds): string
{
    $picks = array_map('intval', $pickedItemIds);
    $extras = array_map('intval', $extraDishIds);
    sort($picks);
    sort($extras);
    return ($packageId ?? 0) . '|' . implode(',', $picks) . '|' . implode(',', $extras);
}

/**
 * What the menu chooser shows as chosen.
 *
 * @return array{package: ?int, picks: array<int, int>, extras: array<int, true>} picks: group => package item id
 */
function menu_input_from_booking(?array $booking): array
{
    $input = ['package' => null, 'picks' => [], 'extras' => []];
    if ($booking === null) {
        return $input;
    }
    $input['package'] = ($booking['menu_package_id'] ?? null) === null ? null : (int) $booking['menu_package_id'];
    foreach (menu_selection_entries($booking['menu_selection'] ?? null) as $e) {
        if (!empty($e['extra'])) {
            $input['extras'][(int) ($e['dish_id'] ?? 0)] = true;
        } elseif (($e['group'] ?? null) !== null) {
            $input['picks'][(int) $e['group']] = (int) ($e['item'] ?? 0);
        }
    }
    return $input;
}

/** As menu_input_from_booking(), from the posted form. */
function menu_input_from_post(array $post): array
{
    $raw = is_string($post['menu_package_id'] ?? null) ? trim($post['menu_package_id']) : '';
    $package = $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    $input = ['package' => $package, 'picks' => [], 'extras' => []];
    $picks = $package !== null && is_array($post['menu_pick'][$package] ?? null) ? $post['menu_pick'][$package] : [];
    foreach ($picks as $group => $itemId) {
        if (is_string($itemId) && ctype_digit($itemId)) {
            $input['picks'][(int) $group] = (int) $itemId;
        }
    }
    foreach (is_array($post['menu_extra'] ?? null) ? $post['menu_extra'] : [] as $dishId) {
        if (is_string($dishId) && ctype_digit($dishId)) {
            $input['extras'][(int) $dishId] = true;
        }
    }
    return $input;
}

/** One saved-menu entry from a package item row or (for an extra) a dish row. */
function menu_snapshot_entry(array $row, bool $extra): array
{
    return [
        'cat'     => (string) $row['category'],
        'dish'    => (string) ($row['dish'] ?? $row['name']),
        'live'    => (bool) (int) $row['is_live'],
        'free'    => !$extra && (bool) (int) ($row['is_free'] ?? 0),
        'extra'   => $extra,
        'group'   => $extra || $row['choice_group'] === null ? null : (int) $row['choice_group'],
        'item'    => $extra ? null : (int) $row['id'],
        'dish_id' => (int) ($extra ? $row['id'] : $row['dish_id']),
    ] + ($extra ? ['rate' => $row['extra_rate'] ?? null, 'unit' => $row['extra_unit'] ?? 'per head'] : []);
}

/**
 * Validate the menu part of the booking form and build the booking's copy of it.
 *
 * The same choice as the booking already has keeps the stored copy untouched, so an unrelated save
 * never rewrites the menu (or turns a confirmed booking's save into an amendment) just because the
 * package was edited since.
 *
 * An extra dish takes the dish's extra rate when it is added and then keeps the rate the booking has;
 * only the admin can change it on the booking (menu_extra_rate[dish id]).
 *
 * @return array{fields: array, errors: array, package: ?array}
 *         fields: menu_package_id, menu_package_name, menu_selection;
 *         package: the package row when the booking newly chose it (its rate then applies), else null
 */
function parse_booking_menu(PDO $pdo, array $post, ?array $existing, bool $isAdmin = false): array
{
    $existingId = ($existing['menu_package_id'] ?? null) === null ? null : (int) $existing['menu_package_id'];
    $keep = [
        'menu_package_id'   => $existingId,
        'menu_package_name' => $existing['menu_package_name'] ?? null,
        'menu_selection'    => $existing['menu_selection'] ?? null,
    ];
    if (!isset($post['menu_present'])) {
        return ['fields' => $keep, 'errors' => [], 'package' => null]; // the form had no menu section
    }

    $input = menu_input_from_post($post);
    $entries = [];
    $order = [];   // per entry: [category order, category, extra, position], so extras sit under their category
    $add = static function (array $row, bool $extra) use (&$entries, &$order): void {
        $order[] = [(int) $row['category_sort'], (string) $row['category'], $extra ? 1 : 0, count($order)];
        $entries[] = menu_snapshot_entry($row, $extra);
    };
    $picks = [];
    $errors = [];
    $package = null;

    if ($input['package'] !== null) {
        $st = $pdo->prepare('SELECT * FROM menu_packages WHERE id = ?');
        $st->execute([$input['package']]);
        $package = $st->fetch();
        if (!$package || (!(int) $package['is_active'] && $input['package'] !== $existingId)) {
            return ['fields' => $keep, 'errors' => ['menu_package_id' => 'Menu package: choose a package from the list.'], 'package' => null];
        }
        $items = menu_package_items($pdo, [$input['package']])[$input['package']] ?? [];
        foreach (menu_package_layout($items) as $rows) {
            foreach ($rows as $row) {
                if (isset($row['item'])) {
                    $add($row['item'], false);
                    continue;
                }
                $picked = null;
                foreach ($row['items'] as $item) {
                    if ((int) $item['id'] === ($input['picks'][$row['choice']] ?? null)) {
                        $picked = $item;
                    }
                }
                if ($picked === null) {
                    $errors['menu_pick_' . $row['choice']] = 'Menu: choose one of '
                        . implode(' / ', array_column($row['items'], 'dish')) . '.';
                    continue;
                }
                $add($picked, false);
                $picks[] = (int) $picked['id'];
            }
        }
    }

    // Extra dishes: offered ones, or ones the booking already had. A dish already in the package is
    // not added twice.
    $extras = [];
    if ($input['extras']) {
        $inMenu = array_flip(array_column($entries, 'dish_id'));
        $hadExtra = [];
        foreach (menu_selection_entries($existing['menu_selection'] ?? null) as $e) {
            if (!empty($e['extra'])) {
                $hadExtra[(int) ($e['dish_id'] ?? 0)] = $e;
            }
        }
        $ids = array_keys($input['extras']);
        $st = $pdo->prepare(MENU_DISH_SELECT . ' WHERE d.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')' . MENU_DISH_ORDER);
        $st->execute($ids);
        foreach ($st->fetchAll() as $dish) {
            $id = (int) $dish['id'];
            $offered = (int) $dish['is_active'] && (int) $dish['category_active'];
            if (!isset($inMenu[$id]) && ($offered || isset($hadExtra[$id]))) {
                $add($dish, true);
                if (isset($hadExtra[$id])) { // an extra the booking already had keeps its price
                    $entries[count($entries) - 1]['rate'] = $hadExtra[$id]['rate'] ?? null;
                    $entries[count($entries) - 1]['unit'] = $hadExtra[$id]['unit'] ?? 'per head';
                }
                $extras[] = $id;
            }
        }
    }

    if ($errors) {
        return ['fields' => $keep, 'errors' => $errors, 'package' => null];
    }
    $unchanged = $existing !== null
        && menu_selection_key($input['package'], $picks, $extras) === menu_selection_key($existingId, ...menu_selection_ids($existing['menu_selection'] ?? null));
    if ($unchanged) {
        $fields = $keep;
    } else {
        $positions = array_keys($entries);
        usort($positions, static fn($a, $b) => $order[$a] <=> $order[$b]);
        $entries = array_map(static fn($i) => $entries[$i], $positions);
        $fields = [
            'menu_package_id'   => $input['package'],
            'menu_package_name' => $package['name'] ?? null,
            'menu_selection'    => $entries ? json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ];
    }

    // The admin prices the extra dishes on this booking; a user's posted rates are ignored.
    if ($isAdmin && is_array($post['menu_extra_rate'] ?? null)) {
        [$fields['menu_selection'], $rateErrors] = menu_apply_extra_rates($fields['menu_selection'], $post['menu_extra_rate']);
        if ($rateErrors) {
            return ['fields' => $keep, 'errors' => $rateErrors, 'package' => null];
        }
    }

    return [
        'fields'  => $fields,
        'errors'  => [],
        'package' => !$unchanged && $package !== null && $input['package'] !== $existingId ? $package : null,
    ];
}

/**
 * Set the rates the admin entered for the booking's extra dishes ($posted: dish id => rate text; empty
 * = not priced yet). Returns the stored copy unchanged when no rate actually changes, so a save that
 * only re-posts the same rates is not a change to the booking.
 *
 * @return array{0: ?string, 1: array} [menu_selection, errors]
 */
function menu_apply_extra_rates(?string $json, array $posted): array
{
    $entries = menu_selection_entries($json);
    $changed = false;
    $errors = [];
    foreach ($entries as $i => $e) {
        $dishId = (int) ($e['dish_id'] ?? 0);
        if (empty($e['extra']) || !isset($posted[$dishId]) || !is_string($posted[$dishId])) {
            continue;
        }
        try {
            $paisa = parse_money($posted[$dishId]);
        } catch (InvalidInput $ex) {
            $errors['menu_extra_rate_' . $dishId] = 'Extra dish ' . ($e['dish'] ?? '') . ': ' . $ex->getMessage();
            continue;
        }
        $old = ($e['rate'] ?? null) === null ? null : decimal_to_paisa($e['rate']);
        if ($paisa !== $old) {
            $entries[$i]['rate'] = $paisa === null ? null : paisa_to_decimal($paisa);
            $entries[$i]['unit'] = $e['unit'] ?? 'per head';
            $changed = true;
        }
    }
    if ($errors || !$changed) {
        return [$json, $errors];
    }
    return [json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), []];
}

/**
 * The booking's extra dishes as charge lines for compute_totals(), keyed 'menu_extra_<dish id>'.
 * An extra without a rate is a line with no money until it is priced.
 */
function menu_extra_charge_lines(?string $json): array
{
    $lines = [];
    foreach (menu_selection_entries($json) as $e) {
        if (!empty($e['extra'])) {
            $lines['menu_extra_' . (int) ($e['dish_id'] ?? 0)] = [
                'unit'     => ($e['unit'] ?? 'per head') === 'fixed' ? 'fixed' : 'per head',
                'selected' => true,
                'rate'     => ($e['rate'] ?? null) === null ? null : decimal_to_paisa($e['rate']),
                'qty'      => null,
            ];
        }
    }
    return $lines;
}

/** "Rs. 150 per guest" / "Rs. 2,000 fixed" for an extra dish's rate; '' when it has none. */
function menu_extra_rate_label(?string $rate, ?string $unit): string
{
    if ($rate === null || $rate === '') {
        return '';
    }
    return format_rs(decimal_to_paisa($rate)) . (($unit ?? 'per head') === 'fixed' ? ' fixed' : ' per guest');
}

/** [picked package item ids, extra dish ids] of a saved menu, for menu_selection_key(). */
function menu_selection_ids(?string $json): array
{
    $picks = [];
    $extras = [];
    foreach (menu_selection_entries($json) as $e) {
        if (!empty($e['extra'])) {
            $extras[] = (int) ($e['dish_id'] ?? 0);
        } elseif (($e['group'] ?? null) !== null) {
            $picks[] = (int) ($e['item'] ?? 0);
        }
    }
    return [$picks, $extras];
}

/** The package's minimum guest (or box) count when the booking is below it, else null (a warning only). */
function menu_min_guests_shortfall(?array $package, int $guests): ?int
{
    $min = $package === null || $package['min_guests'] === null ? 0 : (int) $package['min_guests'];
    return $min > 0 && $guests < $min ? $min : null;
}

// ---------------------------------------------------------------------------
// Admin: categories, dishes, packages (Menus page). Every change is audited as catalog_change.
// ---------------------------------------------------------------------------

/** Tables the helpers below write; table names never come from input. */
const MENU_TABLES = ['menu_categories', 'menu_dishes', 'menu_packages', 'menu_package_items'];

/** Insert one row and audit it. @return int the new id */
function menu_insert(PDO $pdo, array $admin, string $table, array $data, string $duplicateMessage): int
{
    if (!in_array($table, MENU_TABLES, true)) {
        throw new LogicException("Unknown menu table '$table'");
    }
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $table, $data) {
            $columns = array_keys($data);
            $pdo->prepare("INSERT INTO $table (" . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')')->execute(array_values($data));
            $id = (int) $pdo->lastInsertId();
            audit($pdo, 'catalog_change', (int) $admin['id'], null, ['table' => $table, 'id' => $id, 'created' => $data]);
            return $id;
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused($duplicateMessage);
        }
        throw $e;
    }
}

/** Update one row under a row lock and audit what changed. @return array the row as it was */
function menu_update(PDO $pdo, array $admin, string $table, int $id, array $data, string $missingMessage, string $duplicateMessage): array
{
    if (!in_array($table, MENU_TABLES, true)) {
        throw new LogicException("Unknown menu table '$table'");
    }
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $table, $id, $data, $missingMessage) {
            $st = $pdo->prepare("SELECT * FROM $table WHERE id = ? FOR UPDATE");
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) {
                throw new AdminRefused($missingMessage);
            }
            $set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($data)));
            $pdo->prepare("UPDATE $table SET $set WHERE id = ?")->execute(array_merge(array_values($data), [$id]));
            $changed = [];
            foreach ($data as $field => $value) {
                if ((string) $row[$field] !== (string) $value) {
                    $changed[$field] = [$row[$field], $value];
                }
            }
            if ($changed) {
                audit($pdo, 'catalog_change', (int) $admin['id'], null, ['table' => $table, 'id' => $id, 'changed' => $changed]);
            }
            return $row;
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused($duplicateMessage);
        }
        throw $e;
    }
}

/** @throws AdminRefused */
function clean_menu_category_input(array $post, bool $withActive): array
{
    $data = [
        'name'       => clean_name($post['name'] ?? '', 100, 'category name'),
        'sort_order' => clean_sort_order($post['sort_order'] ?? '0'),
    ];
    if ($withActive) {
        $data['is_active'] = isset($post['is_active']) ? 1 : 0;
    }
    return $data;
}

/** @throws AdminRefused */
function clean_menu_dish_input(PDO $pdo, array $post, bool $withActive): array
{
    $categoryId = ctype_digit((string) ($post['category_id'] ?? '')) ? (int) $post['category_id'] : 0;
    $st = $pdo->prepare('SELECT id FROM menu_categories WHERE id = ?');
    $st->execute([$categoryId]);
    if (!$st->fetchColumn()) {
        throw new AdminRefused('Choose the category the dish belongs to.');
    }
    try {
        $rate = parse_money(is_string($post['extra_rate'] ?? null) ? $post['extra_rate'] : '');
    } catch (InvalidInput $e) {
        throw new AdminRefused('Extra rate: ' . $e->getMessage());
    }
    $unit = (string) ($post['extra_unit'] ?? 'per head');
    if (!in_array($unit, ['per head', 'fixed'], true)) {
        throw new AdminRefused('Choose how the extra rate is charged: per guest or fixed.');
    }
    $data = [
        'category_id' => $categoryId,
        'name'        => clean_name($post['name'] ?? '', 150, 'dish name'),
        'is_live'     => isset($post['is_live']) ? 1 : 0,
        'extra_rate'  => $rate === null ? null : paisa_to_decimal($rate),
        'extra_unit'  => $unit,
        'sort_order'  => clean_sort_order($post['sort_order'] ?? '0'),
    ];
    if ($withActive) {
        $data['is_active'] = isset($post['is_active']) ? 1 : 0;
    }
    return $data;
}

/**
 * A package's name, price and minimum. A group price ("Rs 52,000 for 100 guests") is stored as given
 * and also as the per-guest rate a booking is charged (group price / group size, to the paisa).
 *
 * @throws AdminRefused
 */
function clean_menu_package_input(array $post, bool $withActive): array
{
    $field = static fn(string $name): string => is_string($post[$name] ?? null) ? $post[$name] : '';
    $basis = $field('price_basis') ?: 'guest';
    if (!isset(MENU_PRICE_BASES[$basis])) {
        throw new AdminRefused('Choose how the package is priced.');
    }
    try {
        $rate = parse_money($field('per_head_rate'));
        $groupPrice = parse_money($field('group_price'));
        $groupSize = parse_whole_number($field('group_size'));
        $minGuests = parse_whole_number($field('min_guests'));
    } catch (InvalidInput $e) {
        throw new AdminRefused('Package price: ' . $e->getMessage());
    }
    if ($basis === 'group') {
        if ($groupPrice === null || !$groupSize) {
            throw new AdminRefused('A group price needs both the price and how many guests it is for.');
        }
        $rate = intdiv($groupPrice + intdiv($groupSize, 2), $groupSize); // per guest, rounded to the paisa
    } else {
        $groupPrice = null;
        $groupSize = null;
    }
    $data = [
        'name'          => clean_name($post['name'] ?? '', 100, 'package name'),
        'description'   => clean_optional_name($post['description'] ?? '', 255, 'description'),
        'price_basis'   => $basis,
        'per_head_rate' => $rate === null ? null : paisa_to_decimal($rate),
        'group_price'   => $groupPrice === null ? null : paisa_to_decimal($groupPrice),
        'group_size'    => $groupSize,
        'min_guests'    => $minGuests === 0 ? null : $minGuests,
        'sort_order'    => clean_sort_order($post['sort_order'] ?? '0'),
    ];
    if ($withActive) {
        $data['is_active'] = isset($post['is_active']) ? 1 : 0;
    }
    return $data;
}

/** A dish's place in a package: choice group (blank = always included), free, order. @throws AdminRefused */
function clean_menu_item_input(array $post): array
{
    $raw = trim(is_string($post['choice_group'] ?? null) ? $post['choice_group'] : '');
    if ($raw !== '' && (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 99)) {
        throw new AdminRefused('Choice group: leave it blank, or enter a number from 1 to 99.');
    }
    return [
        'choice_group' => $raw === '' ? null : (int) $raw,
        'is_free'      => isset($post['is_free']) ? 1 : 0,
        'sort_order'   => clean_sort_order($post['sort_order'] ?? '0'),
    ];
}

function create_menu_category(PDO $pdo, array $admin, array $post): string
{
    $data = clean_menu_category_input($post, false) + ['is_active' => 1];
    menu_insert($pdo, $admin, 'menu_categories', $data, "There is already a category called “{$data['name']}”.");
    return $data['name'];
}

function update_menu_category(PDO $pdo, array $admin, int $id, array $post): string
{
    $data = clean_menu_category_input($post, true);
    menu_update($pdo, $admin, 'menu_categories', $id, $data, 'That category no longer exists.', "There is already a category called “{$data['name']}”.");
    return $data['name'];
}

function create_menu_dish(PDO $pdo, array $admin, array $post): string
{
    $data = clean_menu_dish_input($pdo, $post, false) + ['is_active' => 1];
    menu_insert($pdo, $admin, 'menu_dishes', $data, "“{$data['name']}” is already in that category.");
    return $data['name'];
}

function update_menu_dish(PDO $pdo, array $admin, int $id, array $post): string
{
    $data = clean_menu_dish_input($pdo, $post, true);
    menu_update($pdo, $admin, 'menu_dishes', $id, $data, 'That dish no longer exists.', "“{$data['name']}” is already in that category.");
    return $data['name'];
}

function create_menu_package(PDO $pdo, array $admin, array $post): int
{
    $data = clean_menu_package_input($post, false) + ['is_active' => 1];
    return menu_insert($pdo, $admin, 'menu_packages', $data, "There is already a package called “{$data['name']}”.");
}

function update_menu_package(PDO $pdo, array $admin, int $id, array $post): string
{
    $data = clean_menu_package_input($post, true);
    menu_update($pdo, $admin, 'menu_packages', $id, $data, 'That package no longer exists.', "There is already a package called “{$data['name']}”.");
    return $data['name'];
}

/** Put a dish into a package. */
function add_menu_package_item(PDO $pdo, array $admin, int $packageId, array $post): void
{
    $dishId = ctype_digit((string) ($post['dish_id'] ?? '')) ? (int) $post['dish_id'] : 0;
    $st = $pdo->prepare('SELECT d.id FROM menu_dishes d WHERE d.id = ? AND d.is_active = 1');
    $st->execute([$dishId]);
    if (!$st->fetchColumn()) {
        throw new AdminRefused('Choose a dish from the list.');
    }
    $st = $pdo->prepare('SELECT id FROM menu_packages WHERE id = ?');
    $st->execute([$packageId]);
    if (!$st->fetchColumn()) {
        throw new AdminRefused('That package no longer exists.');
    }
    menu_insert($pdo, $admin, 'menu_package_items', ['package_id' => $packageId, 'dish_id' => $dishId] + clean_menu_item_input($post),
        'That dish is already in this package.');
}

function update_menu_package_item(PDO $pdo, array $admin, int $itemId, array $post): void
{
    menu_update($pdo, $admin, 'menu_package_items', $itemId, clean_menu_item_input($post),
        'That dish is no longer in the package.', 'That dish is already in this package.');
}

/** Take a dish out of a package. Bookings that chose it keep their copy. */
function remove_menu_package_item(PDO $pdo, array $admin, int $itemId): void
{
    db_transaction(static function (PDO $pdo) use ($admin, $itemId) {
        $st = $pdo->prepare('SELECT * FROM menu_package_items WHERE id = ? FOR UPDATE');
        $st->execute([$itemId]);
        $row = $st->fetch();
        if (!$row) {
            throw new AdminRefused('That dish is no longer in the package.');
        }
        $pdo->prepare('DELETE FROM menu_package_items WHERE id = ?')->execute([$itemId]);
        audit($pdo, 'catalog_change', (int) $admin['id'], null, ['table' => 'menu_package_items', 'id' => $itemId, 'deleted' => $row]);
    }, $pdo);
}

/**
 * Set (or with $stored = null, remove) a package's menu card. $stored comes from
 * accept_uploaded_file(); the replaced file is deleted only after the change is committed, and the
 * new one is deleted again if the change fails.
 */
function set_menu_package_card(PDO $pdo, array $admin, int $packageId, ?array $stored): void
{
    try {
        $old = db_transaction(static function (PDO $pdo) use ($admin, $packageId, $stored) {
            $st = $pdo->prepare('SELECT * FROM menu_packages WHERE id = ? FOR UPDATE');
            $st->execute([$packageId]);
            $package = $st->fetch();
            if (!$package) {
                throw new AdminRefused('That package no longer exists.');
            }
            if ($stored !== null && !str_starts_with($stored['mime'], 'image/')) {
                throw new AdminRefused('The menu card must be a JPG or PNG image.');
            }
            $pdo->prepare('UPDATE menu_packages SET card_stored_name = ?, card_mime = ? WHERE id = ?')
                ->execute([$stored['stored_name'] ?? null, $stored['mime'] ?? null, $packageId]);
            audit($pdo, 'catalog_change', (int) $admin['id'], null, ['table' => 'menu_packages', 'id' => $packageId,
                'changed' => ['card' => [$package['card_stored_name'], $stored['stored_name'] ?? null]]]);
            return $package['card_stored_name'];
        }, $pdo);
    } catch (Throwable $e) {
        if ($stored !== null) {
            @unlink(attachment_path($stored['stored_name']));
        }
        throw $e;
    }
    if ($old !== null && is_file(attachment_path($old)) && !@unlink(attachment_path($old))) {
        app_log("menu card: could not delete storage/uploads/$old (remove it by hand)");
    }
}
