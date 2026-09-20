<?php
/**
 * Attachments for one booking (plan Section 5). Expects $booking, $viewer, $pdo.
 * Everyone who can view the booking sees and downloads the files; only the admin voids them,
 * and voided files are listed for the admin only.
 */
declare(strict_types=1);

$isAdmin = $viewer['role'] === 'admin';
$bid = (int) $booking['id'];
$files = booking_attachments($pdo, $bid, $isAdmin);
$canUpload = can_upload_attachment($booking, $viewer);
$rev = (int) $booking['revision'];
$signedOnFile = false;
foreach ($files as $f) {
    if ($f['voided_at'] === null && $f['signed_revision'] !== null && (int) $f['signed_revision'] === $rev) {
        $signedOnFile = true;
    }
}
$isSigned = false;
foreach (SIGNATURE_FIELDS as $sig) {
    $isSigned = $isSigned || ($booking[$sig] !== null && $booking[$sig] !== '');
}
$kb = static fn(int $bytes): string => $bytes >= 1048576
    ? number_format($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
?>
<div class="card pad attachments" id="attachments">
  <h3>ATTACHMENTS</h3>

<?php if ($booking['status'] === 'confirmed' && $isAdmin): ?>
<?php if ($isSigned && !$signedOnFile): ?>
  <p class="flash info">This agreement is signed at Rev <?= $rev ?>. Upload the scan below and tick
    “signed copy of Rev <?= $rev ?>” before making an amendment.</p>
<?php elseif ($signedOnFile): ?>
  <p class="muted">The signed copy of Rev <?= $rev ?> is on file.</p>
<?php endif; ?>
<?php endif; ?>

<?php if (!$files): ?>
  <p class="muted">No files attached yet.</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="lines-table">
    <thead><tr><th>File</th><th>Type</th><th class="num">Size</th><th>Uploaded</th><th></th></tr></thead>
    <tbody>
<?php foreach ($files as $f): $voided = $f['voided_at'] !== null; ?>
      <tr class="<?= $voided ? 'voided' : '' ?>">
        <td><a href="<?= h(url('documents/download.php?id=' . (int) $f['id'])) ?>"><?= h($f['original_name']) ?></a>
<?php if ($f['signed_revision'] !== null): ?>
          <span class="badge <?= $voided ? 'status-cancelled' : 'status-confirmed' ?>">SIGNED COPY — REV <?= (int) $f['signed_revision'] ?></span>
<?php endif; ?>
        </td>
        <td><?= h(strtoupper(UPLOAD_TYPES[$f['mime']] ?? $f['mime'])) ?></td>
        <td class="num"><?= h($kb((int) $f['size_bytes'])) ?></td>
        <td><?= h(date('d M Y H:i', strtotime($f['uploaded_at']))) ?><div class="hint"><?= h($f['uploaded_by_name']) ?></div></td>
        <td>
<?php if ($voided): ?>
          <span class="badge status-cancelled">VOIDED</span>
          <div class="hint"><?= h(date('d M Y', strtotime($f['voided_at']))) ?> by <?= h($f['voided_by_name']) ?>: <?= h($f['void_reason']) ?></div>
<?php elseif ($isAdmin): ?>
          <form method="post" action="<?= h(url('documents/attachment_void.php')) ?>" class="void-form"
                data-confirm="Void <?= h($f['original_name']) ?>? It stays on record but no longer counts as a signed copy.">
            <?= csrf_field() ?>
            <input type="hidden" name="booking_id" value="<?= $bid ?>">
            <input type="hidden" name="attachment_id" value="<?= (int) $f['id'] ?>">
            <input type="text" name="reason" placeholder="reason for voiding" maxlength="255" required aria-label="Reason for voiding">
            <button type="submit" class="rowbtn del">Void</button>
          </form>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php if ($canUpload): ?>
  <form method="post" action="<?= h(url('documents/upload.php')) ?>" enctype="multipart/form-data" class="payment-form">
    <?= csrf_field() ?>
    <input type="hidden" name="booking_id" value="<?= $bid ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= UPLOAD_MAX_BYTES ?>">
    <h4>Attach a file</h4>
    <div class="field">
      <label for="a_file">PDF, JPG or PNG (up to 5 MB)</label>
      <input type="file" id="a_file" name="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
    </div>
<?php if ($booking['status'] === 'confirmed' && $isAdmin): ?>
    <label class="check">
      <input type="checkbox" name="is_signed_copy" value="1">
      <input type="hidden" name="signed_revision" value="<?= $rev ?>">
      This is the signed copy of Rev <?= $rev ?> (required before amending a signed agreement)
    </label>
<?php endif; ?>
    <button type="submit" class="btn primary">Upload</button>
  </form>
<?php elseif ($viewer['role'] !== 'admin' && $booking['status'] !== 'draft'): ?>
  <p class="hint">Files can be attached while the booking is a draft. Ask AO Mess to add anything else.</p>
<?php endif; ?>
</div>
