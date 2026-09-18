<?php
/**
 * The audit log is APPEND-ONLY (plan Section 5). This file holds the only code that writes
 * to audit_log; everything else may only SELECT from it. There is no update or delete.
 */
declare(strict_types=1);

/**
 * Append one audit entry. Runs on the given connection, so inside a transaction it commits or
 * rolls back together with the change it records.
 *
 * @param array $details old → new values, reasons, etc.; stored as JSON text
 */
function audit(PDO $pdo, string $action, ?int $userId, ?int $bookingId = null, array $details = []): void
{
    $json = $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null;
    $pdo->prepare('INSERT INTO audit_log (user_id, booking_id, action, details, ip) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $bookingId, $action, $json, client_ip()]);
}
