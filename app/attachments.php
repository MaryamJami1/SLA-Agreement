<?php
/**
 * Attachments (plan Sections 5, 8 and 10): files kept outside the web root under random names and
 * served only through documents/download.php after load_booking_for_user().
 *
 * Upload and void both lock the booking row — the same lock the amendment's signed-copy check holds,
 * so a void can't slip in between that check and the amendment's commit.
 */
declare(strict_types=1);

const UPLOAD_MAX_BYTES = 5 * 1024 * 1024;

/** Accepted types, verified from the file's content with finfo (never from its name). */
const UPLOAD_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];

/** The upload or void is not allowed; the message is shown to the user. */
class AttachmentRefused extends RuntimeException {}

/** Vendors attach to their own drafts; the admin attaches in any status (plan Section 8, "Attachments"). */
function can_upload_attachment(array $booking, array $user): bool
{
    return $user['role'] === 'admin'
        || ((int) $booking['vendor_id'] === (int) $user['id'] && $booking['status'] === 'draft');
}

function attachment_path(string $storedName): string
{
    return APP_ROOT . '/storage/uploads/' . $storedName;
}

/** Attachments for a booking. Voided ones are for the admin only. */
function booking_attachments(PDO $pdo, int $bookingId, bool $includeVoided): array
{
    $sql = 'SELECT a.*, u.name AS uploaded_by_name, v.name AS voided_by_name
              FROM attachments a
              JOIN users u ON u.id = a.uploaded_by
         LEFT JOIN users v ON v.id = a.voided_by
             WHERE a.booking_id = ?' . ($includeVoided ? '' : ' AND a.voided_at IS NULL')
        . ' ORDER BY a.uploaded_at, a.id';
    $st = $pdo->prepare($sql);
    $st->execute([$bookingId]);
    return $st->fetchAll();
}

/**
 * Check one uploaded file and move it into storage/uploads/ under a random name.
 *
 * @param array $file one entry of $_FILES
 * @return array{stored_name: string, original_name: string, mime: string, size: int}
 * @throws AttachmentRefused (nothing is stored)
 */
function accept_uploaded_file(array $file): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new AttachmentRefused('Choose a file to upload.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        throw new AttachmentRefused('That file is too large. The limit is 5 MB.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new AttachmentRefused('The file could not be uploaded. Please try again.');
    }
    if (($file['size'] ?? 0) > UPLOAD_MAX_BYTES) {
        throw new AttachmentRefused('That file is too large. The limit is 5 MB.');
    }
    if (($file['size'] ?? 0) === 0) {
        throw new AttachmentRefused('That file is empty.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(UPLOAD_TYPES[$mime])) {
        throw new AttachmentRefused('Only PDF, JPG and PNG files can be uploaded (this file is not one of them).');
    }

    $storedName = bin2hex(random_bytes(16));
    if (!move_uploaded_file($file['tmp_name'], attachment_path($storedName))) {
        throw new AttachmentRefused('The file could not be saved. Please try again.');
    }
    return [
        'stored_name'   => $storedName,
        'original_name' => sanitize_file_name((string) ($file['name'] ?? 'upload')),
        'mime'          => $mime,
        'size'          => (int) $file['size'],
    ];
}

/** Keep a readable name for display and download, without path or control characters. */
function sanitize_file_name(string $name): string
{
    $name = str_replace(['\\', '/'], ' ', $name);
    $name = preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name);
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return mb_substr($name === '' ? 'upload' : $name, 0, 255);
}

/**
 * Record an already-stored file against a booking (plan Section 5, "Attachment writes lock the
 * booking row"). Deletes the file again if anything refuses the write.
 *
 * @param int|null $signedRevision set when the file is the signed copy of that revision
 */
function attach_file(PDO $pdo, array $user, int $bookingId, array $stored, ?int $signedRevision): int
{
    try {
        return db_transaction(static function (PDO $pdo) use ($user, $bookingId, $stored, $signedRevision) {
            $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
            $st->execute([$bookingId]);
            $booking = $st->fetch();
            if (!$booking) {
                throw new AttachmentRefused('This booking no longer exists.');
            }
            if (!can_upload_attachment($booking, $user)) {
                throw new AttachmentRefused('You can\'t attach files to this booking.');
            }
            if ($signedRevision !== null) {
                if ($booking['status'] !== 'confirmed') {
                    throw new AttachmentRefused('A signed copy can be marked only on a confirmed booking.');
                }
                if ($signedRevision !== (int) $booking['revision']) {
                    throw new AttachmentRefused("This booking is now Rev {$booking['revision']}, so the file can't be filed as the signed copy of Rev $signedRevision. Reload the page and upload it again.");
                }
            }
            $pdo->prepare('INSERT INTO attachments (booking_id, original_name, stored_name, mime, size_bytes, signed_revision, uploaded_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$bookingId, $stored['original_name'], $stored['stored_name'], $stored['mime'],
                    $stored['size'], $signedRevision, $user['id']]);
            $id = (int) $pdo->lastInsertId();
            audit($pdo, 'attachment_add', (int) $user['id'], $bookingId, [
                'unique_id' => $booking['unique_id'], 'attachment_id' => $id, 'file' => $stored['original_name'],
                'mime' => $stored['mime'], 'size_bytes' => $stored['size'], 'signed_revision' => $signedRevision,
            ]);
            return $id;
        }, $pdo);
    } catch (Throwable $e) {
        @unlink(attachment_path($stored['stored_name'])); // rolled back: the file must not be left behind
        throw $e;
    }
}

/**
 * Void an attachment (admin only, any status). The file stays on disk; the row is kept for the record
 * and no longer counts as a signed copy.
 */
function void_attachment(PDO $pdo, array $admin, int $bookingId, int $attachmentId, $reasonRaw): array
{
    $reason = trim(is_string($reasonRaw) ? $reasonRaw : '');
    if ($reason === '') {
        throw new AttachmentRefused('Enter the reason for voiding this file.');
    }
    if (mb_strlen($reason) > 255) {
        throw new AttachmentRefused('The reason can be at most 255 characters.');
    }
    return db_transaction(static function (PDO $pdo) use ($admin, $bookingId, $attachmentId, $reason) {
        $st = $pdo->prepare('SELECT * FROM bookings WHERE id = ? FOR UPDATE');
        $st->execute([$bookingId]);
        $booking = $st->fetch();
        if (!$booking) {
            throw new AttachmentRefused('This booking no longer exists.');
        }
        $st = $pdo->prepare('SELECT * FROM attachments WHERE id = ? AND booking_id = ?');
        $st->execute([$attachmentId, $bookingId]);
        $file = $st->fetch();
        if (!$file) {
            throw new AttachmentRefused('That file doesn\'t belong to this booking.');
        }
        if ($file['voided_at'] !== null) {
            throw new AttachmentRefused('This file has already been voided.');
        }
        $st = $pdo->prepare('UPDATE attachments SET voided_at = NOW(), voided_by = ?, void_reason = ?
                              WHERE id = ? AND booking_id = ? AND voided_at IS NULL');
        $st->execute([$admin['id'], $reason, $attachmentId, $bookingId]);
        if ($st->rowCount() !== 1) {
            throw new AttachmentRefused('This file has already been voided.');
        }
        audit($pdo, 'attachment_void', (int) $admin['id'], $bookingId, [
            'unique_id' => $booking['unique_id'], 'attachment_id' => $attachmentId, 'file' => $file['original_name'],
            'signed_revision' => $file['signed_revision'], 'reason' => $reason,
        ]);
        return ['file' => $file['original_name']];
    }, $pdo);
}
