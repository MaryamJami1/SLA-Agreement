<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

require_post(); // logout is a POST (with the CSRF token), so a link on another site can't sign you out

end_login_session();
flash('ok', 'You have signed out.');
redirect('auth/login.php');
