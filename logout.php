<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    log_activity('logout');
}
$_SESSION = [];
session_destroy();
redirect('login.php');
