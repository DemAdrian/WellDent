<?php
// Sends due reminders. Schedule with Windows Task Scheduler, e.g. every 15 minutes:
//   C:\xampp\php\php.exe C:\xampp\htdocs\GitHub\WellDent\cron\send_reminders.php
if (PHP_SAPI !== 'cli') {
    exit('Run from the command line.');
}
require dirname(__DIR__) . '/includes/bootstrap.php';

[$sent, $failed] = process_due_reminders();
echo date('Y-m-d H:i:s') . " sent=$sent failed=$failed\n";
exit($failed > 0 ? 1 : 0);
