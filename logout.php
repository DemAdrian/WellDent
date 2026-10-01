<?php
require __DIR__ . '/includes/bootstrap.php';

// Sign-out is a POST with a CSRF token, so another site can't sign people out with a link or image.
if (!current_user()) {
    redirect('login.php');
}
if (!is_post()) {
    redirect('index.php');
}
verify_csrf();
log_activity('logout');
$_SESSION = [];
session_destroy();
redirect('login.php');
