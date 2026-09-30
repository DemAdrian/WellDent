<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['config'] = require APP_ROOT . '/config/config.php';
if (is_file(APP_ROOT . '/config/config.local.php')) {
    $GLOBALS['config'] = array_replace_recursive($GLOBALS['config'], require APP_ROOT . '/config/config.local.php');
}
date_default_timezone_set($GLOBALS['config']['timezone']);

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/reminders.php';
require __DIR__ . '/layout.php';

if (PHP_SAPI !== 'cli') {
    session_name('welldent');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
}
