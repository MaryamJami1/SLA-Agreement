<?php
/**
 * Vendor management (admin only): vendor categories, vendors and their priced services, vendor
 * services assigned to an event, vendor invoices and the payments made against them.
 *
 * The same rules as the rest of the system apply:
 *  - money is integer paisa; DECIMAL strings are converted at the edges (app/money.php);
 *  - event lines copy the service name, unit and rate, and an invoice copies its lines and the
 *    vendor's details, so editing a vendor or a rate never changes an event or an invoice;
 *  - anything that has been used is deactivated, never deleted;
 *  - payments are never edited or deleted, only voided once;
 *  - every write is one transaction that locks what it changes and writes an audit entry.
 *
 * Needs app/admin_data.php (AdminRefused, clean_name, …) and app/payments.php (PAYMENT_METHODS, …).
 */
declare(strict_types=1);

/** How a vendor service is charged. The amount of an event line is always qty × rate. */
const VENDOR_UNITS = [
    'fixed'      => 'Fixed',
    'per person' => 'Per person',
    'per unit'   => 'Per unit',
    'per day'    => 'Per day',
    'per hour'   => 'Per hour',
];

/** Booking statuses on which vendor services can be added to or removed from the event. */
const VENDOR_LINE_STATUSES = ['draft', 'confirmed', 'completed'];

/** Payment status of an invoice => [label, badge class]. */
const VENDOR_PAYMENT_STATUSES = [
    'unpaid'  => ['Unpaid', 'state-fail'],
    'partial' => ['Partially Paid', 'state-warn'],
    'paid'    => ['Paid', 'state-pass'],
    'void'    => ['Void', 'status-completed'],
];

// ---------------------------------------------------------------------------
// Pure calculations (covered by tests/run.php)
// ---------------------------------------------------------------------------

/**
 * Totals of one vendor invoice, in paisa. Tax is a percentage (in hundredths: 16% = 1600) of the
 * amount after discount, rounded to the nearest paisa.
 *
 * @param int[] $lineAmounts
 * @return array{sub_total: int, discount: int, taxable: int, tax_amount: int, grand_total: int, paid_total: int, balance: int}
 */
function vendor_invoice_totals(array $lineAmounts, int $discount, int $taxPct, int $paid): array
{
    $sub = array_sum($lineAmounts);
    $taxable = $sub - $discount;
    $tax = intdiv($taxable * $taxPct + 5000, 10000);
    $grand = $taxable + $tax;
    return [
        'sub_total'   => $sub,
        'discount'    => $discount,
        'taxable'     => $taxable,
        'tax_amount'  => $tax,
        'grand_total' => $grand,
        'paid_total'  => $paid,
        'balance'     => $grand - $paid,
    ];
}

/** Problems with invoice totals, as messages; empty means the invoice can be issued. */
function vendor_invoice_problems(array $totals): array
{
    $problems = [];
    if ($totals['discount'] > $totals['sub_total']) {
        $problems[] = 'The discount can\'t be more than the sub total (' . format_rs($totals['sub_total']) . ').';
    } elseif ($totals['grand_total'] <= 0) {
        $problems[] = 'The invoice total must be more than Rs. 0.';
    }
    foreach (['sub_total', 'tax_amount', 'grand_total'] as $key) {
        if ($totals[$key] > MONEY_MAX_PAISA) {
            $problems[] = 'These amounts are too large: a total would exceed Rs. 9,99,99,99,999.99.';
            break;
        }
    }
    return $problems;
}

/** 'unpaid', 'partial', 'paid' or 'void' (amounts in paisa). */
function vendor_payment_status(string $invoiceStatus, int $grandTotal, int $paid): string
{
    if ($invoiceStatus === 'void') {
        return 'void';
    }
    if ($paid <= 0) {
        return 'unpaid';
    }
    return $paid < $grandTotal ? 'partial' : 'paid';
}

/** Amount of an event line in paisa: qty × rate. @throws InvalidInput when it would not fit DECIMAL(12,2) */
function vendor_line_amount(int $qty, int $ratePaisa): int
{
    if ($ratePaisa > 0 && $qty > intdiv(MONEY_MAX_PAISA, $ratePaisa)) {
        throw new InvalidInput('Quantity × rate is too large (maximum Rs. 9,99,99,99,999.99).');
    }
    return $qty * $ratePaisa;
}

/** "Wedding — Mr. Farid (SLA-2026-0001)": how an event is named on vendor screens and invoices. */
function vendor_event_label(array $booking): string
{
    $type = $booking['event_type'] === 'Other' ? ($booking['event_type_other'] ?: 'Event') : ($booking['event_type'] ?: 'Event');
    $client = trim((string) ($booking['client_name'] ?? ''));
    return $type . ($client !== '' ? ' — ' . $client : '') . ' (' . $booking['unique_id'] . ')';
}

// ---------------------------------------------------------------------------
// Categories
// ---------------------------------------------------------------------------

function vendor_categories_with_usage(PDO $pdo): array
{
    return $pdo->query('SELECT c.id, c.name, c.is_active, c.sort_order,
                               (SELECT COUNT(*) FROM vendors v WHERE v.category_id = c.id) AS vendors
                          FROM vendor_categories c ORDER BY c.sort_order, c.name')->fetchAll();
}

/** Categories offered when creating or editing a vendor: the active ones plus $keepId (the vendor's own). */
function vendor_categories_for_select(PDO $pdo, ?int $keepId = null): array
{
    $st = $pdo->prepare('SELECT id, name, is_active FROM vendor_categories WHERE is_active = 1 OR id = ? ORDER BY sort_order, name');
    $st->execute([$keepId ?? 0]);
    return $st->fetchAll();
}

function create_vendor_category(PDO $pdo, array $admin, $nameRaw, $sortRaw): string
{
    $name = clean_name($nameRaw, 100, 'category name');
    $sort = clean_sort_order($sortRaw);
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $name, $sort) {
            $pdo->prepare('INSERT INTO vendor_categories (name, is_active, sort_order) VALUES (?, 1, ?)')->execute([$name, $sort]);
            audit($pdo, 'vendor_change', (int) $admin['id'], null,
                ['vendor_category_id' => (int) $pdo->lastInsertId(), 'created' => ['name' => $name, 'sort_order' => $sort]]);
            return $name;
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("There is already a category called “{$name}”.");
        }
        throw $e;
    }
}

/** Rename, re-sort or retire a category. Vendors keep their category; it is just no longer offered. */
function update_vendor_category(PDO $pdo, array $admin, int $id, $nameRaw, bool $isActive, $sortRaw): string
{
    $name = clean_name($nameRaw, 100, 'category name');
    $sort = clean_sort_order($sortRaw);
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $id, $name, $isActive, $sort) {
            $st = $pdo->prepare('SELECT * FROM vendor_categories WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) {
                throw new AdminRefused('That category no longer exists.');
            }
            $pdo->prepare('UPDATE vendor_categories SET name = ?, is_active = ?, sort_order = ? WHERE id = ?')
                ->execute([$name, $isActive ? 1 : 0, $sort, $id]);
            $changed = audit_changes($row, ['name' => $name, 'is_active' => $isActive ? 1 : 0, 'sort_order' => $sort]);
            if ($changed) {
                audit($pdo, 'vendor_change', (int) $admin['id'], null, ['vendor_category_id' => $id, 'changed' => $changed]);
            }
            return $row['name'];
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("There is already a category called “{$name}”.");
        }
        throw $e;
    }
}

/** A category no vendor has ever been filed under can be deleted. */
function delete_vendor_category(PDO $pdo, array $admin, int $id): string
{
    return db_transaction(static function (PDO $pdo) use ($admin, $id) {
        $st = $pdo->prepare('SELECT * FROM vendor_categories WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            throw new AdminRefused('That category no longer exists.');
        }
        $st = $pdo->prepare('SELECT 1 FROM vendors WHERE category_id = ? LIMIT 1');
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            throw new AdminRefused("“{$row['name']}” has vendors, so it can't be deleted. Deactivate it instead.");
        }
        $pdo->prepare('DELETE FROM vendor_categories WHERE id = ?')->execute([$id]);
        audit($pdo, 'vendor_change', (int) $admin['id'], null, ['vendor_category_id' => $id, 'deleted' => ['name' => $row['name']]]);
        return $row['name'];
    }, $pdo);
}

/** field => [old, new] for the fields whose value changed (compared as strings, like the other admin pages). */
function audit_changes(array $before, array $after): array
{
    $changed = [];
    foreach ($after as $field => $value) {
        if ((string) ($before[$field] ?? '') !== (string) ($value ?? '')) {
            $changed[$field] = [$before[$field] ?? null, $value];
        }
    }
    return $changed;
}

// ---------------------------------------------------------------------------
// Vendors
// ---------------------------------------------------------------------------

/**
 * Validate the vendor form.
 *
 * @return array{category_id: int, name: string, contact_person: ?string, phone: ?string, phone2: ?string,
 *               email: ?string, address: ?string, notes: ?string, is_active: int}
 * @throws AdminRefused
 */
function clean_vendor_input(array $post): array
{
    $categoryId = (string) ($post['category_id'] ?? '');
    if (!ctype_digit($categoryId) || (int) $categoryId < 1) {
        throw new AdminRefused('Choose the vendor\'s category.');
    }
    $data = [
        'category_id'    => (int) $categoryId,
        'name'           => clean_name($post['name'] ?? '', 150, 'vendor name'),
        'contact_person' => clean_optional_name($post['contact_person'] ?? '', 100, 'contact person'),
        'phone'          => clean_optional_name($post['phone'] ?? '', 50, 'phone number'),
        'phone2'         => clean_optional_name($post['phone2'] ?? '', 50, 'second phone number'),
        'email'          => clean_optional_name($post['email'] ?? '', 150, 'email address'),
        'address'        => clean_optional_name($post['address'] ?? '', 255, 'address'),
        'notes'          => clean_optional_name($post['notes'] ?? '', 2000, 'notes'),
        'is_active'      => isset($post['is_active']) ? 1 : 0,
    ];
    if ($data['email'] !== null && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
        throw new AdminRefused('Enter a valid email address, or leave it empty.');
    }
    return $data;
}

/** @throws AdminRefused when the category is missing (or retired and not the vendor's current one) */
function check_vendor_category(PDO $pdo, int $categoryId, ?int $currentId = null): void
{
    $st = $pdo->prepare('SELECT is_active FROM vendor_categories WHERE id = ?');
    $st->execute([$categoryId]);
    $active = $st->fetchColumn();
    if ($active === false || ((int) $active !== 1 && $categoryId !== $currentId)) {
        throw new AdminRefused('Choose one of the active categories.');
    }
}

/**
 * @param array $picks from clean_catalog_picks(): the category services ticked on the form
 * @return int the new vendor's id
 */
function create_vendor(PDO $pdo, array $admin, array $data, array $picks = []): int
{
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $data, $picks) {
            check_vendor_category($pdo, $data['category_id']);
            $pdo->prepare('INSERT INTO vendors (category_id, name, contact_person, phone, phone2, email, address, notes, is_active)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$data['category_id'], $data['name'], $data['contact_person'], $data['phone'], $data['phone2'],
                    $data['email'], $data['address'], $data['notes'], $data['is_active']]);
            $id = (int) $pdo->lastInsertId();
            $services = insert_catalog_services($pdo, $id, $data['category_id'], $picks);
            audit($pdo, 'vendor_change', (int) $admin['id'], null, ['vendor_id' => $id, 'created' => $data, 'services' => $services]);
            return $id;
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("There is already a vendor called “{$data['name']}”.");
        }
        throw $e;
    }
}

/** Edit a vendor. Issued invoices keep the details they copied. */
function update_vendor(PDO $pdo, array $admin, int $vendorId, array $data): void
{
    try {
        db_transaction(static function (PDO $pdo) use ($admin, $vendorId, $data) {
            $st = $pdo->prepare('SELECT * FROM vendors WHERE id = ? FOR UPDATE');
            $st->execute([$vendorId]);
            $vendor = $st->fetch();
            if (!$vendor) {
                throw new AdminRefused('That vendor no longer exists.');
            }
            check_vendor_category($pdo, $data['category_id'], (int) $vendor['category_id']);
            $pdo->prepare('UPDATE vendors SET category_id = ?, name = ?, contact_person = ?, phone = ?, phone2 = ?, email = ?,
                                  address = ?, notes = ?, is_active = ? WHERE id = ?')
                ->execute([$data['category_id'], $data['name'], $data['contact_person'], $data['phone'], $data['phone2'],
                    $data['email'], $data['address'], $data['notes'], $data['is_active'], $vendorId]);
            $changed = audit_changes($vendor, $data);
            if ($changed) {
                audit($pdo, 'vendor_change', (int) $admin['id'], null, ['vendor_id' => $vendorId, 'changed' => $changed]);
            }
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("There is already a vendor called “{$data['name']}”.");
        }
        throw $e;
    }
}

/** True when any event line or invoice points at this vendor. */
function vendor_is_used(PDO $pdo, int $vendorId): bool
{
    $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM booking_vendor_items WHERE vendor_id = ?)
                              + (SELECT COUNT(*) FROM vendor_invoices WHERE vendor_id = ?)');
    $st->execute([$vendorId, $vendorId]);
    return (int) $st->fetchColumn() > 0;
}

/** A vendor can be deleted when it was never used, or once it has been deactivated. */
function vendor_can_be_deleted(array $vendor, bool $used): bool
{
    return !$used || (int) $vendor['is_active'] === 0;
}

/**
 * Delete a vendor with its services. A vendor that was used must be deactivated first, and then
 * EVERYTHING of it goes too: its lines on events, its invoices (with their items) and the payments
 * made against them. This can't be undone; the audit entry records what was removed.
 */
function delete_vendor(PDO $pdo, array $admin, int $vendorId): string
{
    return db_transaction(static function (PDO $pdo) use ($admin, $vendorId) {
        $st = $pdo->prepare('SELECT * FROM vendors WHERE id = ? FOR UPDATE');
        $st->execute([$vendorId]);
        $vendor = $st->fetch();
        if (!$vendor) {
            throw new AdminRefused('That vendor no longer exists.');
        }
        if (!vendor_can_be_deleted($vendor, vendor_is_used($pdo, $vendorId))) {
            throw new AdminRefused("“{$vendor['name']}” has been assigned to events. Deactivate it first, then delete it.");
        }

        $st = $pdo->prepare('SELECT id, invoice_no, grand_total, paid_total FROM vendor_invoices WHERE vendor_id = ? FOR UPDATE');
        $st->execute([$vendorId]);
        $invoices = $st->fetchAll();
        $st = $pdo->prepare('SELECT COUNT(*) FROM booking_vendor_items WHERE vendor_id = ?');
        $st->execute([$vendorId]);
        $lines = (int) $st->fetchColumn();

        // Children before parents: event lines point at invoices, payments point at invoices.
        $pdo->prepare('DELETE FROM booking_vendor_items WHERE vendor_id = ?')->execute([$vendorId]);
        $st = $pdo->prepare('DELETE p FROM vendor_payments p JOIN vendor_invoices i ON i.id = p.vendor_invoice_id WHERE i.vendor_id = ?');
        $st->execute([$vendorId]);
        $payments = $st->rowCount();
        $pdo->prepare('DELETE FROM vendor_invoices WHERE vendor_id = ?')->execute([$vendorId]); // items cascade
        $pdo->prepare('DELETE FROM vendor_services WHERE vendor_id = ?')->execute([$vendorId]);
        $pdo->prepare('DELETE FROM vendors WHERE id = ?')->execute([$vendorId]);

        audit($pdo, 'vendor_change', (int) $admin['id'], null, ['vendor_id' => $vendorId, 'deleted' => [
            'name' => $vendor['name'], 'event_lines' => $lines, 'payments' => $payments,
            'invoices' => array_map(static fn($i) => [
                'invoice_no' => $i['invoice_no'], 'grand_total' => $i['grand_total'], 'paid_total' => $i['paid_total']], $invoices),
        ]]);
        return $vendor['name'];
    }, $pdo);
}

/**
 * Vendors with their money position (issued invoices only), optionally for one category.
 * Amounts are DECIMAL strings.
 */
function vendors_list(PDO $pdo, ?int $categoryId = null): array
{
    $st = $pdo->prepare('SELECT v.*, c.name AS category_name,
                                (SELECT COUNT(*) FROM vendor_services s WHERE s.vendor_id = v.id AND s.is_active = 1) AS services,
                                COALESCE(t.invoiced, 0) AS invoiced, COALESCE(t.paid, 0) AS paid, COALESCE(t.outstanding, 0) AS outstanding,
                                COALESCE(t.invoices, 0) AS invoices
                           FROM vendors v
                           JOIN vendor_categories c ON c.id = v.category_id
                      LEFT JOIN (SELECT vendor_id, COUNT(*) AS invoices, SUM(grand_total) AS invoiced, SUM(paid_total) AS paid,
                                        SUM(balance) AS outstanding
                                   FROM vendor_invoices WHERE status = \'issued\' GROUP BY vendor_id) t ON t.vendor_id = v.id
                          WHERE (? = 0 OR v.category_id = ?)
                          ORDER BY v.is_active DESC, c.sort_order, c.name, v.name');
    $st->execute([$categoryId ?? 0, $categoryId ?? 0]);
    return $st->fetchAll();
}

/**
 * Everything the vendor page shows.
 *
 * @return ?array{vendor: array, services: array, events: array, invoices: array, payments: array,
 *                invoiced: int, paid: int, outstanding: int}
 */
function vendor_overview(PDO $pdo, int $vendorId): ?array
{
    $st = $pdo->prepare('SELECT v.*, c.name AS category_name FROM vendors v JOIN vendor_categories c ON c.id = v.category_id WHERE v.id = ?');
    $st->execute([$vendorId]);
    $vendor = $st->fetch();
    if (!$vendor) {
        return null;
    }

    $st = $pdo->prepare('SELECT s.*, (SELECT COUNT(*) FROM booking_vendor_items l WHERE l.vendor_service_id = s.id) AS used
                           FROM vendor_services s WHERE s.vendor_id = ? ORDER BY s.is_active DESC, s.sort_order, s.name');
    $st->execute([$vendorId]);
    $services = $st->fetchAll();

    // Every event the vendor has lines on, with what is assigned and what is still to be invoiced.
    $st = $pdo->prepare('SELECT b.id, b.unique_id, b.status, b.event_type, b.event_type_other, b.client_name, b.event_date,
                                COUNT(l.id) AS line_count, SUM(l.amount) AS assigned,
                                SUM(CASE WHEN l.vendor_invoice_id IS NULL THEN l.amount ELSE 0 END) AS uninvoiced
                           FROM booking_vendor_items l JOIN bookings b ON b.id = l.booking_id
                          WHERE l.vendor_id = ?
                          GROUP BY b.id, b.unique_id, b.status, b.event_type, b.event_type_other, b.client_name, b.event_date
                          ORDER BY b.event_date IS NULL, b.event_date DESC, b.id DESC');
    $st->execute([$vendorId]);
    $events = $st->fetchAll();

    $st = $pdo->prepare('SELECT * FROM vendor_invoices WHERE vendor_id = ? ORDER BY invoice_date DESC, id DESC');
    $st->execute([$vendorId]);
    $invoices = $st->fetchAll();

    $st = $pdo->prepare('SELECT p.*, i.invoice_no, i.event_label, r.name AS recorded_by_name
                           FROM vendor_payments p
                           JOIN vendor_invoices i ON i.id = p.vendor_invoice_id
                           JOIN users r ON r.id = p.recorded_by
                          WHERE i.vendor_id = ?
                          ORDER BY p.paid_on DESC, p.id DESC');
    $st->execute([$vendorId]);
    $payments = $st->fetchAll();

    $invoiced = $paid = $outstanding = 0;
    foreach ($invoices as $inv) {
        if ($inv['status'] === 'issued') {
            $invoiced += decimal_to_paisa($inv['grand_total']);
            $paid += decimal_to_paisa($inv['paid_total']);
            $outstanding += decimal_to_paisa($inv['balance']);
        }
    }
    return compact('vendor', 'services', 'events', 'invoices', 'payments', 'invoiced', 'paid', 'outstanding');
}

// ---------------------------------------------------------------------------
// Vendor services
// ---------------------------------------------------------------------------

/**
 * @return array{name: string, unit: string, rate: string, sort_order: int, is_active: int}
 * @throws AdminRefused
 */
function clean_vendor_service_input(array $post): array
{
    $data = [
        'name'       => clean_name($post['name'] ?? '', 150, 'service name'),
        'unit'       => (string) ($post['unit'] ?? ''),
        'sort_order' => clean_sort_order($post['sort_order'] ?? '0'),
        'is_active'  => isset($post['is_active']) ? 1 : 0,
    ];
    if (!isset(VENDOR_UNITS[$data['unit']])) {
        throw new AdminRefused('Choose how the service is charged.');
    }
    try {
        $rate = parse_money(is_string($post['rate'] ?? null) ? $post['rate'] : '');
    } catch (InvalidInput $e) {
        throw new AdminRefused('Rate: ' . $e->getMessage());
    }
    if ($rate === null) {
        throw new AdminRefused('Enter the rate (0 is allowed).');
    }
    $data['rate'] = paisa_to_decimal($rate);
    return $data;
}

/**
 * The services each category offers (vendor_category_services), for the checkbox picker:
 * category id => [['id' => int, 'name' => string], ...].
 */
function vendor_category_service_catalog(PDO $pdo): array
{
    $catalog = [];
    foreach ($pdo->query('SELECT id, category_id, name FROM vendor_category_services ORDER BY category_id, sort_order, name') as $r) {
        $catalog[(int) $r['category_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
    }
    return $catalog;
}

/**
 * Validate the ticked category services: services[] holds catalog ids, service_unit[id] and
 * service_rate[id] how each is charged. An empty rate means 0 (set it later on the vendor page).
 *
 * @return array<int, array{unit: string, rate: string}> catalog id => unit and DECIMAL rate
 * @throws AdminRefused
 */
function clean_catalog_picks(array $post): array
{
    $ids = is_array($post['services'] ?? null) ? $post['services'] : [];
    $units = is_array($post['service_unit'] ?? null) ? $post['service_unit'] : [];
    $rates = is_array($post['service_rate'] ?? null) ? $post['service_rate'] : [];
    $picks = [];
    foreach ($ids as $raw) {
        if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1) {
            continue;
        }
        $id = (int) $raw;
        $unit = is_string($units[$id] ?? null) ? $units[$id] : 'fixed';
        if (!isset(VENDOR_UNITS[$unit])) {
            throw new AdminRefused('Choose how each ticked service is charged.');
        }
        try {
            $rate = parse_money(is_string($rates[$id] ?? null) ? $rates[$id] : '') ?? 0;
        } catch (InvalidInput $e) {
            throw new AdminRefused('Rate of a ticked service: ' . $e->getMessage());
        }
        $picks[$id] = ['unit' => $unit, 'rate' => paisa_to_decimal($rate)];
    }
    return $picks;
}

/**
 * Give a vendor the ticked catalog services of its category. Picks from another category and
 * services the vendor already has (same name) are skipped. Runs inside the caller's transaction.
 *
 * @return string[] the names added
 */
function insert_catalog_services(PDO $pdo, int $vendorId, int $categoryId, array $picks): array
{
    if (!$picks) {
        return [];
    }
    $ids = array_keys($picks);
    $st = $pdo->prepare('SELECT id, name, sort_order FROM vendor_category_services
                          WHERE category_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                          ORDER BY sort_order, name');
    $st->execute(array_merge([$categoryId], $ids));
    $rows = $st->fetchAll();

    $st = $pdo->prepare('SELECT name FROM vendor_services WHERE vendor_id = ?');
    $st->execute([$vendorId]);
    $have = array_map('mb_strtolower', $st->fetchAll(PDO::FETCH_COLUMN));

    $insert = $pdo->prepare('INSERT INTO vendor_services (vendor_id, name, unit, rate, is_active, sort_order) VALUES (?, ?, ?, ?, 1, ?)');
    $added = [];
    foreach ($rows as $r) {
        if (in_array(mb_strtolower($r['name']), $have, true)) {
            continue;
        }
        $pick = $picks[(int) $r['id']];
        $insert->execute([$vendorId, $r['name'], $pick['unit'], $pick['rate'], (int) $r['sort_order']]);
        $added[] = $r['name'];
    }
    return $added;
}

/** Add ticked services of the vendor's own category. @return string[] the names added */
function add_catalog_services(PDO $pdo, array $admin, int $vendorId, array $picks): array
{
    if (!$picks) {
        throw new AdminRefused('Tick at least one service to add.');
    }
    return db_transaction(static function (PDO $pdo) use ($admin, $vendorId, $picks) {
        $st = $pdo->prepare('SELECT category_id FROM vendors WHERE id = ? FOR UPDATE');
        $st->execute([$vendorId]);
        $categoryId = $st->fetchColumn();
        if ($categoryId === false) {
            throw new AdminRefused('That vendor no longer exists.');
        }
        $added = insert_catalog_services($pdo, $vendorId, (int) $categoryId, $picks);
        if (!$added) {
            throw new AdminRefused('The vendor already has every service you ticked.');
        }
        audit($pdo, 'vendor_change', (int) $admin['id'], null, ['vendor_id' => $vendorId, 'services_added' => $added]);
        return $added;
    }, $pdo);
}

function create_vendor_service(PDO $pdo, array $admin, int $vendorId, array $data): string
{
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $vendorId, $data) {
            $st = $pdo->prepare('SELECT id FROM vendors WHERE id = ? FOR UPDATE');
            $st->execute([$vendorId]);
            if (!$st->fetchColumn()) {
                throw new AdminRefused('That vendor no longer exists.');
            }
            $pdo->prepare('INSERT INTO vendor_services (vendor_id, name, unit, rate, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$vendorId, $data['name'], $data['unit'], $data['rate'], $data['is_active'], $data['sort_order']]);
            audit($pdo, 'vendor_change', (int) $admin['id'], null,
                ['vendor_id' => $vendorId, 'vendor_service_id' => (int) $pdo->lastInsertId(), 'created' => $data]);
            return $data['name'];
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("This vendor already has a service called “{$data['name']}”.");
        }
        throw $e;
    }
}

/** Rename, re-rate, re-unit or retire a service. Events and invoices keep the values they copied. */
function update_vendor_service(PDO $pdo, array $admin, int $vendorId, int $serviceId, array $data): string
{
    try {
        return db_transaction(static function (PDO $pdo) use ($admin, $vendorId, $serviceId, $data) {
            $st = $pdo->prepare('SELECT * FROM vendor_services WHERE id = ? AND vendor_id = ? FOR UPDATE');
            $st->execute([$serviceId, $vendorId]);
            $row = $st->fetch();
            if (!$row) {
                throw new AdminRefused('That service no longer exists.');
            }
            $pdo->prepare('UPDATE vendor_services SET name = ?, unit = ?, rate = ?, is_active = ?, sort_order = ? WHERE id = ?')
                ->execute([$data['name'], $data['unit'], $data['rate'], $data['is_active'], $data['sort_order'], $serviceId]);
            $changed = audit_changes($row, $data);
            if ($changed) {
                audit($pdo, 'vendor_change', (int) $admin['id'], null,
                    ['vendor_id' => $vendorId, 'vendor_service_id' => $serviceId, 'changed' => $changed]);
            }
            return $row['name'];
        }, $pdo);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            throw new AdminRefused("This vendor already has a service called “{$data['name']}”.");
        }
        throw $e;
    }
}

/** A service never put on an event can be deleted. */
function delete_vendor_service(PDO $pdo, array $admin, int $vendorId, int $serviceId): string
{
    return db_transaction(static function (PDO $pdo) use ($admin, $vendorId, $serviceId) {
        $st = $pdo->prepare('SELECT * FROM vendor_services WHERE id = ? AND vendor_id = ? FOR UPDATE');
        $st->execute([$serviceId, $vendorId]);
        $row = $st->fetch();
        if (!$row) {
            throw new AdminRefused('That service no longer exists.');
        }
        $st = $pdo->prepare('SELECT 1 FROM booking_vendor_items WHERE vendor_service_id = ? LIMIT 1');
        $st->execute([$serviceId]);
        if ($st->fetchColumn()) {
            throw new AdminRefused("“{$row['name']}” has been used on an event, so it can't be deleted. Deactivate it instead.");
        }
        $pdo->prepare('DELETE FROM vendor_services WHERE id = ?')->execute([$serviceId]);
        audit($pdo, 'vendor_change', (int) $admin['id'], null,
            ['vendor_id' => $vendorId, 'vendor_service_id' => $serviceId, 'deleted' => ['name' => $row['name']]]);
        return $row['name'];
    }, $pdo);
}

// ---------------------------------------------------------------------------
// Vendor services on an event
// ---------------------------------------------------------------------------

/** Active vendors that have active services, each with its services, for the "add a service" picker. */
function vendors_for_assignment(PDO $pdo): array
{
    $rows = $pdo->query('SELECT v.id AS vendor_id, v.name AS vendor_name, c.name AS category_name,
                                s.id, s.name, s.unit, s.rate
                           FROM vendors v
                           JOIN vendor_categories c ON c.id = v.category_id
                           JOIN vendor_services s ON s.vendor_id = v.id AND s.is_active = 1
                          WHERE v.is_active = 1
                          ORDER BY c.sort_order, c.name, v.name, s.sort_order, s.name')->fetchAll();
    $vendors = [];
    foreach ($rows as $r) {
        $vid = (int) $r['vendor_id'];
        $vendors[$vid] ??= ['id' => $vid, 'name' => $r['vendor_name'], 'category' => $r['category_name'], 'services' => []];
        $vendors[$vid]['services'][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'unit' => $r['unit'], 'rate' => $r['rate']];
    }
    return array_values($vendors);
}

/** The event's vendor lines, grouped by vendor: vendor id => ['name', 'category', 'lines' => [...]]. */
function booking_vendor_lines(PDO $pdo, int $bookingId): array
{
    $st = $pdo->prepare('SELECT l.*, v.name AS vendor_name, c.name AS category_name, i.invoice_no, i.status AS invoice_status
                           FROM booking_vendor_items l
                           JOIN vendors v ON v.id = l.vendor_id
                           JOIN vendor_categories c ON c.id = v.category_id
                      LEFT JOIN vendor_invoices i ON i.id = l.vendor_invoice_id
                          WHERE l.booking_id = ?
                          ORDER BY v.name, l.id');
    $st->execute([$bookingId]);
    $byVendor = [];
    foreach ($st->fetchAll() as $row) {
        $vid = (int) $row['vendor_id'];
        $byVendor[$vid] ??= ['id' => $vid, 'name' => $row['vendor_name'], 'category' => $row['category_name'], 'lines' => []];
        $byVendor[$vid]['lines'][] = $row;
    }
    return $byVendor;
}

/** The event's vendor invoices, newest first. */
function booking_vendor_invoices(PDO $pdo, int $bookingId): array
{
    $st = $pdo->prepare('SELECT * FROM vendor_invoices WHERE booking_id = ? ORDER BY id DESC');
    $st->execute([$bookingId]);
    return $st->fetchAll();
}

/** Lock the booking for a vendor write. @throws AdminRefused */
function lock_booking_for_vendors(PDO $pdo, int $bookingId): array
{
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
    $st->execute([$bookingId]);
    $booking = $st->fetch();
    if (!$booking) {
        throw new AdminRefused('This booking no longer exists.');
    }
    return $booking;
}

/**
 * Put one of a vendor's services on the event. The name, unit and rate are copied; the rate may be
 * changed for this event (a negotiated price).
 *
 * @return string the line's label
 * @throws AdminRefused
 */
function add_vendor_line(PDO $pdo, array $admin, int $bookingId, array $post): string
{
    $serviceId = (string) ($post['service_id'] ?? '');
    if (!ctype_digit($serviceId) || (int) $serviceId < 1) {
        throw new AdminRefused('Choose the vendor service.');
    }
    try {
        $qty = parse_whole_number($post['qty'] ?? '');
        if ($qty === null || $qty < 1) {
            throw new InvalidInput('Enter a quantity of 1 or more.');
        }
    } catch (InvalidInput $e) {
        throw new AdminRefused('Quantity: ' . $e->getMessage());
    }
    try {
        $rateTyped = parse_money(is_string($post['rate'] ?? null) ? $post['rate'] : '');
    } catch (InvalidInput $e) {
        throw new AdminRefused('Rate: ' . $e->getMessage());
    }
    $notes = clean_optional_name($post['notes'] ?? '', 255, 'note');

    return db_transaction(static function (PDO $pdo) use ($admin, $bookingId, $serviceId, $qty, $rateTyped, $notes) {
        $b = lock_booking_for_vendors($pdo, $bookingId);
        if (!in_array($b['status'], VENDOR_LINE_STATUSES, true)) {
            throw new AdminRefused('Vendor services can\'t be added to a ' . $b['status'] . ' booking.');
        }
        $st = $pdo->prepare('SELECT s.*, v.name AS vendor_name, v.is_active AS vendor_active
                               FROM vendor_services s JOIN vendors v ON v.id = s.vendor_id WHERE s.id = ?');
        $st->execute([(int) $serviceId]);
        $service = $st->fetch();
        if (!$service || (int) $service['is_active'] !== 1 || (int) $service['vendor_active'] !== 1) {
            throw new AdminRefused('That service is no longer offered. Choose another one.');
        }
        $rate = $rateTyped ?? decimal_to_paisa($service['rate']);
        try {
            $amount = vendor_line_amount($qty, $rate);
        } catch (InvalidInput $e) {
            throw new AdminRefused($e->getMessage());
        }
        $pdo->prepare('INSERT INTO booking_vendor_items (booking_id, vendor_id, vendor_service_id, label, unit, qty, rate, amount, notes, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$bookingId, (int) $service['vendor_id'], (int) $service['id'], $service['name'], $service['unit'], $qty,
                paisa_to_decimal($rate), paisa_to_decimal($amount), $notes, $admin['id']]);
        audit($pdo, 'vendor_assign', (int) $admin['id'], $bookingId, [
            'unique_id' => $b['unique_id'], 'line_id' => (int) $pdo->lastInsertId(), 'added' => [
                'vendor' => $service['vendor_name'], 'service' => $service['name'], 'unit' => $service['unit'], 'qty' => $qty,
                'rate' => paisa_to_decimal($rate), 'amount' => paisa_to_decimal($amount)],
        ]);
        return $service['vendor_name'] . ' — ' . $service['name'];
    }, $pdo);
}

/** Remove a line that is not on an issued invoice. @throws AdminRefused */
function remove_vendor_line(PDO $pdo, array $admin, int $bookingId, int $lineId): string
{
    return db_transaction(static function (PDO $pdo) use ($admin, $bookingId, $lineId) {
        $b = lock_booking_for_vendors($pdo, $bookingId);
        if (!in_array($b['status'], VENDOR_LINE_STATUSES, true)) {
            throw new AdminRefused('Vendor services can\'t be removed from a ' . $b['status'] . ' booking.');
        }
        $st = $pdo->prepare('SELECT l.*, v.name AS vendor_name FROM booking_vendor_items l JOIN vendors v ON v.id = l.vendor_id
                              WHERE l.id = ? AND l.booking_id = ? FOR UPDATE');
        $st->execute([$lineId, $bookingId]);
        $line = $st->fetch();
        if (!$line) {
            throw new AdminRefused('That vendor service is no longer on this booking.');
        }
        if ($line['vendor_invoice_id'] !== null) {
            throw new AdminRefused('That service is on an issued invoice. Void the invoice first to change it.');
        }
        $pdo->prepare('DELETE FROM booking_vendor_items WHERE id = ?')->execute([$lineId]);
        audit($pdo, 'vendor_assign', (int) $admin['id'], $bookingId, [
            'unique_id' => $b['unique_id'], 'line_id' => $lineId, 'removed' => [
                'vendor' => $line['vendor_name'], 'service' => $line['label'], 'qty' => (int) $line['qty'],
                'rate' => $line['rate'], 'amount' => $line['amount']],
        ]);
        return $line['vendor_name'] . ' — ' . $line['label'];
    }, $pdo);
}

// ---------------------------------------------------------------------------
// Vendor invoices
// ---------------------------------------------------------------------------

/**
 * Issue an invoice for every not-yet-invoiced line of one vendor on one event.
 *
 * @return array{id: int, invoice_no: string}
 * @throws AdminRefused
 */
function generate_vendor_invoice(PDO $pdo, array $admin, int $bookingId, int $vendorId, $discountRaw, $taxRaw, $notesRaw): array
{
    try {
        $discount = parse_money(is_string($discountRaw) ? $discountRaw : '') ?? 0;
    } catch (InvalidInput $e) {
        throw new AdminRefused('Discount: ' . $e->getMessage());
    }
    try {
        $taxPct = parse_percent(is_string($taxRaw) ? $taxRaw : '') ?? 0;
    } catch (InvalidInput $e) {
        throw new AdminRefused('Tax: ' . $e->getMessage());
    }
    $notes = clean_optional_name($notesRaw, 255, 'invoice note');

    return db_transaction(static function (PDO $pdo) use ($admin, $bookingId, $vendorId, $discount, $taxPct, $notes) {
        $b = lock_booking_for_vendors($pdo, $bookingId);
        $st = $pdo->prepare('SELECT * FROM vendors WHERE id = ? FOR UPDATE');
        $st->execute([$vendorId]);
        $vendor = $st->fetch();
        if (!$vendor) {
            throw new AdminRefused('That vendor no longer exists.');
        }
        $st = $pdo->prepare('SELECT * FROM booking_vendor_items
                              WHERE booking_id = ? AND vendor_id = ? AND vendor_invoice_id IS NULL ORDER BY id FOR UPDATE');
        $st->execute([$bookingId, $vendorId]);
        $lines = $st->fetchAll();
        if (!$lines) {
            throw new AdminRefused("“{$vendor['name']}” has no services on this booking that still need an invoice.");
        }

        $t = vendor_invoice_totals(array_map(static fn($l) => decimal_to_paisa($l['amount']), $lines), $discount, $taxPct, 0);
        if ($problems = vendor_invoice_problems($t)) {
            throw new AdminRefused(implode(' ', $problems));
        }

        $venue = $b['venue_other'];
        if ($b['venue_id'] !== null) {
            $v = $pdo->prepare('SELECT name FROM venues WHERE id = ?');
            $v->execute([(int) $b['venue_id']]);
            $venue = $v->fetchColumn() ?: $venue;
        }
        if ($venue && $b['venue_location']) {
            $venue .= ' — ' . $b['venue_location'];
        }
        $phones = implode(' / ', array_filter([$vendor['phone'], $vendor['phone2']]));

        $number = next_vendor_invoice_no($pdo, (int) date('Y'));
        $pdo->prepare('INSERT INTO vendor_invoices (invoice_no, booking_id, vendor_id, invoice_date, vendor_name, vendor_contact_person,
                                                    vendor_phone, vendor_email, vendor_address, event_label, event_date, event_venue,
                                                    discount, tax_pct, notes, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$number, $bookingId, $vendorId, date('Y-m-d'), $vendor['name'], $vendor['contact_person'],
                $phones !== '' ? $phones : null, $vendor['email'], $vendor['address'], mb_substr(vendor_event_label($b), 0, 255),
                $b['event_date'], $venue ? mb_substr($venue, 0, 255) : null,
                paisa_to_decimal($discount), paisa_to_decimal($taxPct), $notes, $admin['id']]);
        $invoiceId = (int) $pdo->lastInsertId();

        $copy = $pdo->prepare('INSERT INTO vendor_invoice_items (vendor_invoice_id, label, unit, qty, rate, amount, notes, sort_order)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lines as $i => $l) {
            $copy->execute([$invoiceId, $l['label'], $l['unit'], (int) $l['qty'], $l['rate'], $l['amount'], $l['notes'], ($i + 1) * 10]);
        }
        $ids = array_map(static fn($l) => (int) $l['id'], $lines);
        $pdo->prepare('UPDATE booking_vendor_items SET vendor_invoice_id = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
            ->execute(array_merge([$invoiceId], $ids));

        $totals = recompute_vendor_invoice_totals($pdo, $invoiceId);
        audit($pdo, 'vendor_invoice', (int) $admin['id'], $bookingId, [
            'unique_id' => $b['unique_id'], 'vendor_invoice_id' => $invoiceId, 'invoice_no' => $number, 'vendor' => $vendor['name'],
            'lines' => count($lines), 'sub_total' => paisa_to_decimal($totals['sub_total']), 'discount' => paisa_to_decimal($discount),
            'tax_pct' => paisa_to_decimal($taxPct), 'grand_total' => paisa_to_decimal($totals['grand_total']),
        ]);
        return ['id' => $invoiceId, 'invoice_no' => $number];
    }, $pdo);
}

/**
 * Void an invoice issued by mistake. Only while it has no (non-voided) payments; its lines become
 * available for a new invoice. The voided invoice and its items are kept.
 */
function void_vendor_invoice(PDO $pdo, array $admin, int $invoiceId, $reasonRaw): string
{
    $reason = clean_name($reasonRaw, 255, 'reason for voiding the invoice');
    return db_transaction(static function (PDO $pdo) use ($admin, $invoiceId, $reason) {
        $inv = lock_vendor_invoice($pdo, $invoiceId);
        if ($inv['status'] === 'void') {
            throw new AdminRefused('This invoice has already been voided.');
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM vendor_payments WHERE vendor_invoice_id = ? AND voided_at IS NULL');
        $st->execute([$invoiceId]);
        if ((int) $st->fetchColumn() > 0) {
            throw new AdminRefused('This invoice has payments. Void those payments first.');
        }
        $pdo->prepare("UPDATE vendor_invoices SET status = 'void', voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?")
            ->execute([$admin['id'], $reason, $invoiceId]);
        $pdo->prepare('UPDATE booking_vendor_items SET vendor_invoice_id = NULL WHERE vendor_invoice_id = ?')->execute([$invoiceId]);
        audit($pdo, 'vendor_invoice', (int) $admin['id'], (int) $inv['booking_id'], [
            'vendor_invoice_id' => $invoiceId, 'invoice_no' => $inv['invoice_no'], 'voided' => true, 'reason' => $reason,
            'grand_total' => $inv['grand_total'],
        ]);
        return $inv['invoice_no'];
    }, $pdo);
}

/** @throws AdminRefused */
function lock_vendor_invoice(PDO $pdo, int $invoiceId): array
{
    $st = $pdo->prepare('SELECT * FROM vendor_invoices WHERE id = ? FOR UPDATE');
    $st->execute([$invoiceId]);
    $inv = $st->fetch();
    if (!$inv) {
        throw new AdminRefused('That invoice no longer exists.');
    }
    return $inv;
}

/**
 * Recompute and store an invoice's totals from its items and payments. The ONLY writer of those
 * columns. Must run inside the transaction that holds the invoice lock.
 *
 * @return array the totals from vendor_invoice_totals() (paisa)
 */
function recompute_vendor_invoice_totals(PDO $pdo, int $invoiceId): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('recompute_vendor_invoice_totals() must run inside the invoice transaction.');
    }
    $st = $pdo->prepare('SELECT discount, tax_pct FROM vendor_invoices WHERE id = ?');
    $st->execute([$invoiceId]);
    $inv = $st->fetch();
    if (!$inv) {
        throw new RuntimeException("Vendor invoice $invoiceId not found.");
    }
    $st = $pdo->prepare('SELECT amount FROM vendor_invoice_items WHERE vendor_invoice_id = ?');
    $st->execute([$invoiceId]);
    $amounts = array_map('decimal_to_paisa', $st->fetchAll(PDO::FETCH_COLUMN));
    $st = $pdo->prepare('SELECT amount FROM vendor_payments WHERE vendor_invoice_id = ? AND voided_at IS NULL');
    $st->execute([$invoiceId]);
    $paid = array_sum(array_map('decimal_to_paisa', $st->fetchAll(PDO::FETCH_COLUMN)));

    $t = vendor_invoice_totals($amounts, decimal_to_paisa($inv['discount']), decimal_to_percent($inv['tax_pct']) ?? 0, $paid);
    $pdo->prepare('UPDATE vendor_invoices SET sub_total = ?, tax_amount = ?, grand_total = ?, paid_total = ?, balance = ? WHERE id = ?')
        ->execute([paisa_to_decimal($t['sub_total']), paisa_to_decimal($t['tax_amount']), paisa_to_decimal($t['grand_total']),
            paisa_to_decimal($t['paid_total']), paisa_to_decimal($t['balance']), $invoiceId]);
    return $t;
}

/** The invoice with its items and payments (who recorded / voided them), or null. */
function load_vendor_invoice(PDO $pdo, int $invoiceId): ?array
{
    $st = $pdo->prepare('SELECT i.*, b.unique_id, b.status AS booking_status, u.name AS voided_by_name
                           FROM vendor_invoices i
                           JOIN bookings b ON b.id = i.booking_id
                      LEFT JOIN users u ON u.id = i.voided_by
                          WHERE i.id = ?');
    $st->execute([$invoiceId]);
    $inv = $st->fetch();
    if (!$inv) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM vendor_invoice_items WHERE vendor_invoice_id = ? ORDER BY sort_order, id');
    $st->execute([$invoiceId]);
    $inv['items'] = $st->fetchAll();
    $st = $pdo->prepare('SELECT p.*, r.name AS recorded_by_name, v.name AS voided_by_name
                           FROM vendor_payments p
                           JOIN users r ON r.id = p.recorded_by
                      LEFT JOIN users v ON v.id = p.voided_by
                          WHERE p.vendor_invoice_id = ?
                          ORDER BY p.paid_on, p.id');
    $st->execute([$invoiceId]);
    $inv['payments'] = $st->fetchAll();
    $inv['payment_status'] = vendor_payment_status($inv['status'], decimal_to_paisa($inv['grand_total']), decimal_to_paisa($inv['paid_total']));
    return $inv;
}

/** A status badge for an invoice row (expects grand_total, paid_total and status). */
function vendor_status_badge(array $invoice): string
{
    $key = vendor_payment_status($invoice['status'], decimal_to_paisa($invoice['grand_total']), decimal_to_paisa($invoice['paid_total']));
    [$label, $class] = VENDOR_PAYMENT_STATUSES[$key];
    return '<span class="badge ' . h($class) . '">' . h(strtoupper($label)) . '</span>';
}

// ---------------------------------------------------------------------------
// Payments to vendors
// ---------------------------------------------------------------------------

/**
 * Record a payment made to the vendor against an issued invoice.
 *
 * @param array $data from parse_payment_input($_POST, 'payment')
 * @return array the invoice totals after the payment (paisa)
 */
function record_vendor_payment(PDO $pdo, array $admin, int $invoiceId, array $data): array
{
    return db_transaction(static function (PDO $pdo) use ($admin, $invoiceId, $data) {
        $inv = lock_vendor_invoice($pdo, $invoiceId);
        if ($inv['status'] !== 'issued') {
            throw new AdminRefused('Payments can\'t be recorded on a voided invoice.');
        }
        $pdo->prepare('INSERT INTO vendor_payments (vendor_invoice_id, amount, paid_on, method, bank_name, reference_no, notes, recorded_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$invoiceId, $data['amount'], $data['paid_on'], $data['method'], $data['bank_name'], $data['reference_no'],
                $data['notes'], $admin['id']]);
        $paymentId = (int) $pdo->lastInsertId();
        $t = recompute_vendor_invoice_totals($pdo, $invoiceId);
        audit($pdo, 'vendor_payment_add', (int) $admin['id'], (int) $inv['booking_id'], [
            'vendor_invoice_id' => $invoiceId, 'invoice_no' => $inv['invoice_no'], 'vendor_payment_id' => $paymentId,
            'amount' => $data['amount'], 'paid_on' => $data['paid_on'], 'method' => $data['method'], 'reference_no' => $data['reference_no'],
            'paid_total' => [$inv['paid_total'], paisa_to_decimal($t['paid_total'])],
            'balance' => [$inv['balance'], paisa_to_decimal($t['balance'])],
        ]);
        return $t;
    }, $pdo);
}

/** Void a vendor payment (a data-entry correction). */
function void_vendor_payment(PDO $pdo, array $admin, int $invoiceId, int $paymentId, $reasonRaw): array
{
    $reason = clean_name($reasonRaw, 255, 'reason for voiding this payment');
    return db_transaction(static function (PDO $pdo) use ($admin, $invoiceId, $paymentId, $reason) {
        $inv = lock_vendor_invoice($pdo, $invoiceId);
        $st = $pdo->prepare('SELECT * FROM vendor_payments WHERE id = ? AND vendor_invoice_id = ? FOR UPDATE');
        $st->execute([$paymentId, $invoiceId]);
        $p = $st->fetch();
        if (!$p) {
            throw new AdminRefused('That payment doesn\'t belong to this invoice.');
        }
        if ($p['voided_at'] !== null) {
            throw new AdminRefused('This payment has already been voided.');
        }
        $pdo->prepare('UPDATE vendor_payments SET voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?')
            ->execute([$admin['id'], $reason, $paymentId]);
        $t = recompute_vendor_invoice_totals($pdo, $invoiceId);
        audit($pdo, 'vendor_payment_void', (int) $admin['id'], (int) $inv['booking_id'], [
            'vendor_invoice_id' => $invoiceId, 'invoice_no' => $inv['invoice_no'], 'vendor_payment_id' => $paymentId,
            'amount' => $p['amount'], 'reason' => $reason,
            'paid_total' => [$inv['paid_total'], paisa_to_decimal($t['paid_total'])],
            'balance' => [$inv['balance'], paisa_to_decimal($t['balance'])],
        ]);
        return $t;
    }, $pdo);
}

/** "Imran — 0300-1234567": the contact person and phone numbers on one line, or an em dash. */
function vendor_contact_line(array $vendor): string
{
    $line = implode(' — ', array_filter([
        trim((string) ($vendor['contact_person'] ?? '')),
        implode(' / ', array_filter([trim((string) ($vendor['phone'] ?? '')), trim((string) ($vendor['phone2'] ?? ''))])),
    ]));
    return $line !== '' ? $line : '—';
}
