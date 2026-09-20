<?php
/**
 * Booking lifecycle (plan Sections 8 and 9): confirm, complete, cancel, delete draft, and saving
 * changes to a confirmed booking (direct edits and amendments).
 *
 * Lock order everywhere: venue row(s) first (ascending id, one statement), then the booking, then the
 * vendor's users row with a shared lock. Every operation is one transaction via db_transaction(),
 * which retries a deadlock once.
 */
declare(strict_types=1);

/** The operation is not allowed in the booking's current state; the message is shown to the user. */
class LifecycleRefused extends RuntimeException {}

/**
 * Fields of a confirmed booking that are saved directly (no revision, signatures kept).
 * Everything else the form edits is an amendment field. Signature fields are direct edits so that
 * the signing of a confirmed agreement can be recorded.
 */
const DIRECT_EDIT_FIELDS = [
    'client_contact', 'client_contact2', 'client_address',
    'reference_name', 'reference_department', 'decor_by',
    'received_by', 'received_date', 'received_time',
    'vendor_sign_name', 'vendor_sign_date', 'client_sign_name', 'client_sign_date',
];
const SIGNATURE_FIELDS = ['vendor_sign_name', 'vendor_sign_date', 'client_sign_name', 'client_sign_date'];
const REASON_MAX = 1000;

// ---------------------------------------------------------------------------
// Locking helpers
// ---------------------------------------------------------------------------

/** Lock venue rows in ascending id order with one statement (nulls and duplicates skipped). */
function lock_venues(PDO $pdo, array $venueIds): void
{
    $ids = array_values(array_unique(array_map('intval', array_filter($venueIds, static fn($v) => $v !== null && $v !== ''))));
    if (!$ids) {
        return;
    }
    sort($ids, SORT_NUMERIC);
    $in = implode(', ', array_fill(0, count($ids), '?'));
    $pdo->prepare("SELECT id FROM venues WHERE id IN ($in) ORDER BY id FOR UPDATE")->execute($ids);
}

/** Lock the booking at the version the user saw; a changed version means someone else saved first. */
function lock_booking(PDO $pdo, int $id, int $version): array
{
    $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND version = ? FOR UPDATE');
    $st->execute([$id, $version]);
    $booking = $st->fetch();
    if (!$booking) {
        throw new BookingConflict();
    }
    return $booking;
}

/** The vendor's row under a shared lock if it is an active vendor, else null. */
function lock_active_vendor(PDO $pdo, ?int $vendorId): ?array
{
    if ($vendorId === null) {
        return null;
    }
    $st = $pdo->prepare("SELECT id, firm_name, rep_name, contact FROM users
                          WHERE id = ? AND role = 'vendor' AND status = 'active' LOCK IN SHARE MODE");
    $st->execute([$vendorId]);
    return $st->fetch() ?: null;
}

/**
 * Confirmed/completed bookings on the same venue and date, as a LOCKING read so it sees rows another
 * transaction has just committed (a plain SELECT could read an older snapshot).
 */
function blocking_venue_conflicts(PDO $pdo, int $bookingId, int $venueId, string $eventDate): array
{
    $st = $pdo->prepare("SELECT id, unique_id, status FROM bookings
                          WHERE venue_id = ? AND event_date = ? AND status IN ('confirmed', 'completed') AND id <> ?
                          FOR UPDATE");
    $st->execute([$venueId, $eventDate, $bookingId]);
    return $st->fetchAll();
}

function clean_reason($raw): string
{
    $reason = trim(is_string($raw) ? $raw : '');
    if (mb_strlen($reason) > REASON_MAX) {
        throw new LifecycleRefused('The reason can be at most ' . REASON_MAX . ' characters.');
    }
    return $reason;
}

/** Problems that stop a booking from being (or staying) confirmed, apart from the vendor and venue conflicts. */
function confirm_requirement_problems(PDO $pdo, array $b, bool $venueMayBeInactive = false): array
{
    $problems = [];
    if (trim((string) $b['client_name']) === '') {
        $problems[] = 'a client name';
    }
    if ($b['event_date'] === null || $b['event_date'] === '') {
        $problems[] = 'an event date';
    }
    if ($b['venue_id'] === null && trim((string) $b['venue_other']) === '') {
        $problems[] = 'a venue';
    } elseif ($b['venue_id'] !== null && !$venueMayBeInactive) {
        $st = $pdo->prepare('SELECT is_active FROM venues WHERE id = ?');
        $st->execute([(int) $b['venue_id']]);
        if (!(int) $st->fetchColumn()) {
            $problems[] = 'a venue that is still offered (this venue has been deactivated; choose another)';
        }
    }
    if (decimal_to_paisa($b['grand_total']) <= 0) {
        $problems[] = 'a net amount above Rs. 0';
    }
    return $problems;
}

// ---------------------------------------------------------------------------
// Confirm (plan Section 9, race-safe)
// ---------------------------------------------------------------------------

function confirm_booking(PDO $pdo, array $admin, int $id, int $version, $overrideRaw): string
{
    $override = clean_reason($overrideRaw);
    return db_transaction(static function (PDO $pdo) use ($admin, $id, $version, $override) {
        // Unlocked read of the venue, so its row can be locked BEFORE the booking.
        $st = $pdo->prepare('SELECT venue_id FROM bookings WHERE id = ?');
        $st->execute([$id]);
        $venueId = $st->fetchColumn();
        $venueId = $venueId === false || $venueId === null ? null : (int) $venueId;

        lock_venues($pdo, [$venueId]);                          // 1. venue
        $b = lock_booking($pdo, $id, $version);                 // 2. booking at the version the admin saw
        if (($b['venue_id'] === null ? null : (int) $b['venue_id']) !== $venueId) {
            throw new BookingConflict();
        }
        if ($b['status'] !== 'draft') {
            throw new LifecycleRefused("Only a draft can be confirmed; this booking is {$b['status']}.");
        }
        $problems = confirm_requirement_problems($pdo, $b);
        if (lock_active_vendor($pdo, $b['vendor_id'] === null ? null : (int) $b['vendor_id']) === null) {
            array_unshift($problems, 'an active, approved vendor');
        }
        if ($problems) {
            throw new LifecycleRefused('This booking can\'t be confirmed yet. It needs ' . implode(', ', $problems) . '.');
        }

        $conflicts = $venueId !== null ? blocking_venue_conflicts($pdo, $id, $venueId, $b['event_date']) : []; // 3.
        if ($conflicts && $override === '') {                  // 4.
            throw new LifecycleRefused('The venue is already ' . $conflicts[0]['status'] . ' for ' . $conflicts[0]['unique_id']
                . ' on this date. Only one booking per venue per day can be confirmed. To confirm anyway, enter an override reason.');
        }

        $pdo->prepare("UPDATE bookings SET status = 'confirmed', confirmed_at = NOW(), updated_by = ?, updated_at = NOW(),
                              version = version + 1 WHERE id = ?")->execute([$admin['id'], $id]); // 5.
        $details = ['unique_id' => $b['unique_id'], 'status' => ['draft', 'confirmed'], 'venue_id' => $venueId,
            'venue_other' => $b['venue_other'], 'event_date' => $b['event_date']];
        if ($conflicts) {
            $details['venue_override'] = ['reason' => $override, 'conflicts_with' => array_column($conflicts, 'unique_id')];
        }
        audit($pdo, 'confirm', (int) $admin['id'], $id, $details);
        return $b['unique_id'];
    }, $pdo);
}

// ---------------------------------------------------------------------------
// Complete and cancel (plan Section 8: one transaction, booking row locked first)
// ---------------------------------------------------------------------------

function complete_booking(PDO $pdo, array $admin, int $id, int $version, bool $ackBalance): string
{
    return db_transaction(static function (PDO $pdo) use ($admin, $id, $version, $ackBalance) {
        $b = lock_booking($pdo, $id, $version);
        if ($b['status'] !== 'confirmed') {
            throw new LifecycleRefused("Only a confirmed booking can be completed; this booking is {$b['status']}.");
        }
        if ($b['event_date'] === null || $b['event_date'] > date('Y-m-d')) {
            throw new LifecycleRefused('A booking can be completed only on or after its event date.');
        }
        $balance = decimal_to_paisa($b['balance']);
        if ($balance !== 0 && !$ackBalance) {
            throw new LifecycleRefused(($balance > 0 ? format_rs($balance) . ' is still outstanding' : 'The booking is overpaid by ' . format_rs(-$balance))
                . '. Tick the acknowledgement to complete it anyway.');
        }
        $pdo->prepare("UPDATE bookings SET status = 'completed', completed_at = NOW(), updated_by = ?, updated_at = NOW(),
                              version = version + 1 WHERE id = ?")->execute([$admin['id'], $id]);
        $t = recompute_booking_totals($pdo, $id);
        audit($pdo, 'complete', (int) $admin['id'], $id, ['unique_id' => $b['unique_id'], 'status' => ['confirmed', 'completed'],
            'balance' => paisa_to_decimal($t['balance']), 'balance_acknowledged' => $balance !== 0]);
        return $b['unique_id'];
    }, $pdo);
}

function cancel_booking(PDO $pdo, array $admin, int $id, int $version, $reasonRaw): string
{
    $reason = clean_reason($reasonRaw);
    if ($reason === '') {
        throw new LifecycleRefused('Enter a cancellation reason.');
    }
    return db_transaction(static function (PDO $pdo) use ($admin, $id, $version, $reason) {
        $b = lock_booking($pdo, $id, $version);
        if (!in_array($b['status'], ['draft', 'confirmed'], true)) {
            throw new LifecycleRefused("Only a draft or confirmed booking can be cancelled; this booking is {$b['status']}.");
        }
        $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancellation_reason = ?,
                              updated_by = ?, updated_at = NOW(), version = version + 1 WHERE id = ?")
            ->execute([$admin['id'], $reason, $admin['id'], $id]);
        $t = recompute_booking_totals($pdo, $id); // balance drops to 0; paid_total is the amount retained
        audit($pdo, 'cancel', (int) $admin['id'], $id, ['unique_id' => $b['unique_id'], 'status' => [$b['status'], 'cancelled'],
            'reason' => $reason, 'amount_retained' => paisa_to_decimal($t['paid_total']), 'balance' => [$b['balance'], paisa_to_decimal($t['balance'])]]);
        return $b['unique_id'];
    }, $pdo);
}

// ---------------------------------------------------------------------------
// Delete a draft (plan Section 8, "Deleting a draft")
// ---------------------------------------------------------------------------

/** @return string the deleted booking's SLA number */
function delete_draft(PDO $pdo, array $user, int $id, int $version): string
{
    [$uniqueId, $storedNames] = db_transaction(static function (PDO $pdo) use ($user, $id, $version) {
        $b = lock_booking($pdo, $id, $version);                                            // 1.
        if (!booking_allows($b, $user, 'delete')) {
            throw new LifecycleRefused('Only a draft can be deleted.');
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE booking_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            throw new LifecycleRefused('This draft has payment records (including voided ones), so it can\'t be deleted. Cancel it instead.');
        }
        $st = $pdo->prepare('SELECT stored_name, original_name FROM attachments WHERE booking_id = ?');   // 2.
        $st->execute([$id]);
        $files = $st->fetchAll();

        $venueName = null;
        if ($b['venue_id'] !== null) {
            $v = $pdo->prepare('SELECT name FROM venues WHERE id = ?');
            $v->execute([(int) $b['venue_id']]);
            $venueName = $v->fetchColumn() ?: null;
        }
        audit($pdo, 'delete_draft', (int) $user['id'], $id, [                               // 3.
            'unique_id' => $b['unique_id'], 'client_name' => $b['client_name'], 'event_date' => $b['event_date'],
            'venue' => $venueName ?? $b['venue_other'], 'grand_total' => $b['grand_total'],
            'attachments' => array_column($files, 'original_name'),
        ]);
        $pdo->prepare('DELETE FROM attachments WHERE booking_id = ?')->execute([$id]);      // 4.
        $pdo->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);                 //    (lines cascade)
        return [$b['unique_id'], array_column($files, 'stored_name')];
    }, $pdo);                                                                                // 5. committed

    foreach ($storedNames as $name) {                                                        // 6. files only after commit
        $path = APP_ROOT . '/storage/uploads/' . $name;
        if (is_file($path) && !@unlink($path)) {
            app_log("delete_draft $uniqueId: could not delete storage/uploads/$name (remove it by hand)");
        }
    }
    return $uniqueId;
}

// ---------------------------------------------------------------------------
// Saving a confirmed booking: direct edits and amendments (plan Section 8)
// ---------------------------------------------------------------------------

/**
 * Classify a save of a confirmed booking.
 *
 * @return array{changed: array, amendment_fields: array, line_changes: array, amendment_lines: array}
 */
function classify_confirmed_changes(array $old, array $fields, array $lines): array
{
    $changed = booking_field_diff($old, $fields);
    $lineChanges = [];
    $amendLines = [];
    foreach ($lines as $line) {
        if ($change = booking_line_change($line)) {
            $lineChanges[] = $change;
            if ($line['section'] !== 'ops_item') {
                $amendLines[] = $change;
            }
        }
    }
    return [
        'changed'          => $changed,
        'amendment_fields' => array_diff_key($changed, array_flip(DIRECT_EDIT_FIELDS)),
        'line_changes'     => $lineChanges,
        'amendment_lines'  => $amendLines,
    ];
}

/**
 * Save changes to a confirmed booking. Direct edits only → audited 'update'. Any amendment field →
 * reason required, signed copy of the current revision must be on file (if it was signed), venue check
 * when the venue or date moves, revision + 1, signatures cleared, audited 'amend'.
 *
 * @return array{id: int, unique_id: string, amended: bool, revision: int, changed: bool}
 * @throws BookingValidationError|BookingConflict
 */
function save_booking_confirmed(PDO $pdo, array $admin, int $id, int $version, array $fields, array $lines,
                                $amendReasonRaw, $overrideRaw): array
{
    $amendReason = trim(is_string($amendReasonRaw) ? $amendReasonRaw : '');
    $override = trim(is_string($overrideRaw) ? $overrideRaw : '');
    if (mb_strlen($amendReason) > REASON_MAX || mb_strlen($override) > REASON_MAX) {
        throw new BookingValidationError(['amend_reason' => 'Reasons can be at most ' . REASON_MAX . ' characters.']);
    }

    return db_transaction(static function (PDO $pdo) use ($admin, $id, $version, $fields, $lines, $amendReason, $override) {
        // Step 0: locks — venues (old and new, ascending) before the booking, then the vendor.
        $st = $pdo->prepare('SELECT venue_id, event_date FROM bookings WHERE id = ?');
        $st->execute([$id]);
        $pre = $st->fetch() ?: ['venue_id' => null, 'event_date' => null];
        $preVenue = $pre['venue_id'] === null ? null : (int) $pre['venue_id'];
        $moves = $preVenue !== $fields['venue_id'] || $pre['event_date'] !== $fields['event_date'];
        if ($moves) {
            lock_venues($pdo, [$preVenue, $fields['venue_id']]);
        }
        $old = lock_booking($pdo, $id, $version);
        if (($old['venue_id'] === null ? null : (int) $old['venue_id']) !== $preVenue || $old['event_date'] !== $pre['event_date']) {
            throw new BookingConflict();
        }
        if ($old['status'] !== 'confirmed' || $admin['role'] !== 'admin') {
            throw new BookingValidationError(['status' => 'This booking is no longer confirmed, so it can\'t be changed here.']);
        }
        $vendorChanges = $fields['vendor_id'] !== ($old['vendor_id'] === null ? null : (int) $old['vendor_id']);
        if ($vendorChanges) {
            $vendor = lock_active_vendor($pdo, $fields['vendor_id']);
            if ($vendor === null) {
                throw new BookingValidationError(['vendor_id' => 'Vendor: a confirmed booking needs an active, approved vendor.']);
            }
        }
        $fields = fill_vendor_snapshot($pdo, $fields);

        $c = classify_confirmed_changes($old, $fields, $lines);
        $amended = $c['amendment_fields'] || $c['amendment_lines'];
        if (!$c['changed'] && !$c['line_changes']) {
            return ['id' => $id, 'unique_id' => $old['unique_id'], 'amended' => false, 'revision' => (int) $old['revision'], 'changed' => false];
        }

        // The result must still be a valid confirmed booking.
        $chargeLines = booking_final_charge_lines($pdo, $id, $lines);
        $discount = decimal_to_paisa($fields['discount']);
        $totals = compute_totals(decimal_to_paisa($fields['per_head_rate']), (int) $fields['guests'], $discount, $chargeLines, [], 'confirmed');
        $errors = validate_totals($totals, $discount);
        $problems = confirm_requirement_problems($pdo, ['grand_total' => paisa_to_decimal($totals['grand_total'])] + $fields + $old,
            $fields['venue_id'] !== null && $fields['venue_id'] === $preVenue);
        if ($problems) {
            $errors['status'] = 'A confirmed booking must keep ' . implode(', ', $problems) . '.';
        }

        $conflicts = [];
        if ($amended) {
            if ($amendReason === '') {                                                          // 1.
                $errors['amend_reason'] = 'Amendment reason: required. This change alters the agreed terms (it raises the revision and clears the signatures).';
            }
            $wasSigned = array_filter(array_intersect_key($old, array_flip(SIGNATURE_FIELDS)), static fn($v) => $v !== null && $v !== '');
            if ($wasSigned && cfg('REQUIRE_SIGNED_COPY_FOR_AMENDMENT', true)) {                 // 2.
                $st = $pdo->prepare('SELECT COUNT(*) FROM attachments WHERE booking_id = ? AND signed_revision = ? AND voided_at IS NULL');
                $st->execute([$id, $old['revision']]);
                if ((int) $st->fetchColumn() === 0) {
                    $errors['signed_copy'] = "Upload the signed copy of Rev {$old['revision']} before amending.";
                }
            }
            if ($moves && $fields['venue_id'] !== null && $fields['event_date'] !== null) {     // 3.
                $conflicts = blocking_venue_conflicts($pdo, $id, $fields['venue_id'], $fields['event_date']);
                if ($conflicts && $override === '') {
                    $errors['venue_override'] = 'The venue is already ' . $conflicts[0]['status'] . ' for ' . $conflicts[0]['unique_id']
                        . ' on that date. Enter a venue override reason to amend anyway.';
                }
            }
        }
        if ($errors) {
            throw new BookingValidationError($errors);
        }

        $revision = (int) $old['revision'];
        $extraSet = '';
        if ($amended) {                                                                         // 4.
            foreach (SIGNATURE_FIELDS as $sig) {
                $fields[$sig] = null;
            }
            $revision++;
            $extraSet = ', revision = revision + 1, revised_at = NOW()';
        }
        $columns = array_keys($fields);                                                         // 5.
        $set = implode(', ', array_map(static fn($col) => "$col = ?", $columns));
        $st = $pdo->prepare("UPDATE bookings SET $set, updated_by = ?, updated_at = NOW(), version = version + 1$extraSet
                              WHERE id = ? AND version = ?");
        $st->execute(array_merge(array_values($fields), [$admin['id'], $id, $version]));
        if ($st->rowCount() !== 1) {
            throw new BookingConflict();
        }
        write_booking_lines($pdo, $id, $lines);
        recompute_booking_totals($pdo, $id);

        $details = ['unique_id' => $old['unique_id'], 'changed' => booking_field_diff($old, $fields), 'lines' => $c['line_changes']];
        if ($amended) {                                                                         // 6.
            $details = ['reason' => $amendReason, 'revision' => [(int) $old['revision'], $revision]] + $details;
            if ($conflicts) {
                $details['venue_override'] = ['reason' => $override, 'conflicts_with' => array_column($conflicts, 'unique_id')];
            }
        }
        audit($pdo, $amended ? 'amend' : 'update', (int) $admin['id'], $id, $details);
        return ['id' => $id, 'unique_id' => $old['unique_id'], 'amended' => $amended, 'revision' => $revision, 'changed' => true];
    }, $pdo);
}

// ---------------------------------------------------------------------------
// Page helper
// ---------------------------------------------------------------------------

const CONFLICT_MESSAGE = 'This booking was changed by someone else after you opened it, so nothing was done. '
    . 'Check the latest version below and try again.';

/** Run a lifecycle action for a page: flash the outcome. Returns true on success. */
function run_lifecycle_action(callable $action, callable $okMessage): bool
{
    try {
        flash('ok', $okMessage($action()));
        return true;
    } catch (LifecycleRefused | TryAgainException $e) {
        flash('error', $e->getMessage());
    } catch (BookingConflict $e) {
        flash('error', CONFLICT_MESSAGE);
    }
    return false;
}

/** id and version posted by an action form. */
function posted_booking_ref(): array
{
    $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : 0;
    $version = ctype_digit((string) ($_POST['version'] ?? '')) ? (int) $_POST['version'] : 0;
    return [$id, $version];
}
