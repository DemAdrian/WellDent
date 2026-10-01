<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['config'] = require APP_ROOT . '/config/config.php';
if (is_file(APP_ROOT . '/config/config.local.php')) {
    $GLOBALS['config'] = array_replace_recursive($GLOBALS['config'], require APP_ROOT . '/config/config.local.php');
}
date_default_timezone_set($GLOBALS['config']['timezone']);

// Errors go to storage/php-errors.log, never to the screen, so a crash can't show SQL, file
// paths or patient details. Set security.debug to true while developing to see them again.
$debug = (bool) ($GLOBALS['config']['security']['debug'] ?? false);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/php-errors.log');
ini_set('zend.exception_ignore_args', '1'); // keep argument values (patient data) out of stack traces
if (!$debug && PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $ex): void {
        error_log((string) $ex);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo 'Something went wrong. Go back and try again; if it keeps happening, ask your administrator to check storage/php-errors.log.';
    });
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/appointments.php';
require __DIR__ . '/reminders.php';
require __DIR__ . '/layout.php';

if (PHP_SAPI !== 'cli') {
    if (config('security.force_https') && !is_https()) {
        redirect('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    }
    header_remove('X-Powered-By');

    $idle = (int) config('security.idle_minutes', 30);
    ini_set('session.use_strict_mode', '1'); // ignore session IDs the server never issued
    if ($idle > 0) {
        ini_set('session.gc_maxlifetime', (string) max(1440, $idle * 60));
    }
    session_name('welldent');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    session_start();
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
}
