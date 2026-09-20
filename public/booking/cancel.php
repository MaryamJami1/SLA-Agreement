<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/bookings.php';
require_once APP_ROOT . '/app/lifecycle.php';

require_post();
$admin = require_login();
[$id, $version] = posted_booking_ref();
load_booking_for_user(db(), $id, $admin, 'admin');

run_lifecycle_action(
    static fn() => cancel_booking(db(), $admin, $id, $version, $_POST['reason'] ?? ''),
    static fn($uid) => "$uid is cancelled."
);
redirect('booking/form.php?id=' . $id);
