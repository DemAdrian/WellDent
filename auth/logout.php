<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

// Sign-out is a POST with a CSRF token, so another site can't sign people out with a link or image.
if (!current_user()) {
    redirect(url('auth/login.php'));
}
if (!is_post()) {
    redirect(url());
}
verify_csrf();
log_activity('logout');
$_SESSION = [];
session_destroy();
redirect(url('auth/login.php'));
