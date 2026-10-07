<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

// An admin's home is the dashboard, a user's is the registry; guests go to sign-in.
redirect(current_user() === null ? 'auth/login.php' : home_path(current_user()));
