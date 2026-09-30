<?php
// Default settings. Put machine-specific values and secrets (DB password,
// SMS API key, Gmail app password) in config/config.local.php, which is
// git-ignored and overrides anything here.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'welldent',
        'user' => 'root',
        'pass' => '',
    ],

    'clinic' => [
        'name'     => 'Dental Wellness',
        'tagline'  => 'Orthodontics + Preventive',
        'city'     => 'Manila',
        'phone'    => '',
        'chairs'   => 1,
        'opens'    => '09:00',
        'closes'   => '17:00',
    ],

    'timezone' => 'Asia/Manila',

    'reminders' => [
        'send_time' => '08:00', // reminders go out at this time, one day before
        'template'  => 'Hi {first_name}, this is {clinic} reminding you of your {procedure} appointment on {date} at {time}. Please call us if you need to reschedule.',
    ],

    // 'log' writes messages to storage/outbox.log instead of sending them.
    'sms' => [
        'driver'      => 'log', // 'log' or 'semaphore'
        'api_key'     => '',
        'sender_name' => '',
    ],
    'email' => [
        'driver'    => 'log', // 'log' or 'mail' (PHP mail() via XAMPP sendmail)
        'from'      => '',
        'from_name' => 'Dental Wellness',
    ],
];
