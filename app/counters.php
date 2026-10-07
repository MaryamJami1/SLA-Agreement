<?php
/**
 * SLA number generation (plan Section 7): SLA-{creation year}-{seq:04d}.
 * The counter upsert runs in the same transaction as the bookings INSERT, so a failed save
 * rolls the counter back and no number is lost.
 */
declare(strict_types=1);

function format_sla_id(int $year, int $seq): string
{
    return sprintf('SLA-%04d-%04d', $year, $seq);
}

/**
 * Document number for printing: 'SLA' or 'INV' prefix, plus " Rev N" after an amendment.
 * format_document_number('SLA-2026-0001', 'INV', 1) → 'INV-2026-0001 Rev 1'
 */
function format_document_number(string $uniqueId, string $prefix, int $revision): string
{
    $number = preg_replace('/^SLA-/', $prefix . '-', $uniqueId);
    return $revision > 0 ? $number . ' Rev ' . $revision : $number;
}

/**
 * Reserve the next SLA number for $year. Must be called inside the transaction that inserts
 * the booking. The year is the creation year from PHP (Asia/Karachi), never MySQL NOW().
 */
function next_sla_id(PDO $pdo, int $year): string
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('next_sla_id() must run inside the booking-insert transaction.');
    }
    $pdo->prepare('INSERT INTO counters (year_key, seq) VALUES (?, LAST_INSERT_ID(1))
                   ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)')
        ->execute([$year]);
    $seq = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($seq < 1) {
        throw new RuntimeException('SLA counter returned an invalid sequence.');
    }
    return format_sla_id($year, $seq);
}

/** Vendor invoice number: VINV-{year}-{seq:04d}. */
function format_vendor_invoice_no(int $year, int $seq): string
{
    return sprintf('VINV-%04d-%04d', $year, $seq);
}

/**
 * Reserve the next vendor invoice number for $year. Same rules as next_sla_id(): it must run inside
 * the transaction that inserts the invoice, so a failed insert gives the number back.
 */
function next_vendor_invoice_no(PDO $pdo, int $year): string
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('next_vendor_invoice_no() must run inside the invoice-insert transaction.');
    }
    $pdo->prepare('INSERT INTO vendor_invoice_counters (year_key, seq) VALUES (?, LAST_INSERT_ID(1))
                   ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)')
        ->execute([$year]);
    $seq = (int) $pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    if ($seq < 1) {
        throw new RuntimeException('Vendor invoice counter returned an invalid sequence.');
    }
    return format_vendor_invoice_no($year, $seq);
}
