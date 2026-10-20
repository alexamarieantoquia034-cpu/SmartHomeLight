<?php
/**
 * SmartHome Light - application configuration.
 * Edit these values to match your XAMPP setup.
 */
declare(strict_types=1);

return [
    // ---------- Database ----------
    'db' => [
        'host'   => '127.0.0.1',
        'port'   => 3306,
        'name'   => 'smartlight',
        'user'   => 'root',
        'pass'   => '',           // XAMPP default is an empty root password
        'charset'=> 'utf8mb4',
    ],

    // ---------- Site ----------
    // Leave 'auto' to detect the folder automatically, or set e.g. '/smarhomelight_backup'
    'base_path'   => 'auto',
    'site_name'   => 'SmartHome Light',
    // Schedule evaluation timezone. Asia/Manila is what the project runs on
    // (UTC+08:00) - db.php applies this at include time AND pushes the same
    // offset to MySQL, so NOW(), date() and the dashboard clock all agree.
    'timezone'    => 'Asia/Manila',

    // ---------- Session ----------
    'session_name' => 'smarthome_light_sess',

    // ---------- Device behaviour ----------
    // Mark a device offline if no heartbeat/poll for this many seconds.
    // 90 s tolerates brief Wi-Fi dropouts (common on extenders) without the
    // dashboard flapping to OFFLINE, while still detecting a truly dead board.
    'offline_after'  => 90,
    // How often (seconds) the ESP32 should poll for commands.
    'poll_interval'  => 5,
    // Rated power of the LED bulb in watts (used for energy estimates).
    'bulb_watts'     => 9.0,

    // ---------- Cron ----------
    // Optional shared secret for hitting cron/run_schedules.php over HTTP.
    // Leave empty ('') to allow unauthenticated requests (fine for localhost).
    'cron_token'     => '',
];