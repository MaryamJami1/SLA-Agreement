<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

// The registry is the home page; guests go to sign-in.
redirect(current_user() === null ? 'auth/login.php' : 'booking/list.php');
