<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/lifecycle.php';

require_post();
$user = require_login();
[$id, $version] = posted_booking_ref();
load_booking_for_user(db(), $id, $user, 'delete'); // owner vendor or admin, drafts only

$deleted = run_lifecycle_action(
    static fn() => delete_draft(db(), $user, $id, $version),
    static fn($uid) => "Draft $uid was deleted. Its history stays in the audit log; the number is not reused."
);
redirect($deleted ? 'booking/list.php' : 'booking/form.php?id=' . $id);
