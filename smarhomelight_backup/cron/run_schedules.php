<?php
/**
 * Schedule runner - normally triggered by Windows Task Scheduler once per
 * minute (see the Schedules page for the exact command), and ALSO executed
 * server-side on every ESP32 poll by api/device_poll.php, so schedules fire
 * even when no browser is open:
 *
 *   php C:\xampp\htdocs\smarhomelight_backup\cron\run_schedules.php
 *
 * Also reachable at /cron/run_schedules.php if you point the browser at it.
 * Protect it with an optional token in includes/config.php.
 *
 * Both runners share run_due_schedules() (includes/functions.php), which
 * claims each schedule atomically via schedules.last_run - firing from the
 * cron task AND from a poll in the same minute can never double-execute.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

// Force the configured timezone to load before we read the clock.
// (db.php already applies config['timezone'] = Asia/Manila at include time.)
app_config();

// Allow running from CLI or via a token-protected URL.
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $token = (string)($_GET['token'] ?? '');
    $expected = app_config('cron_token', '');
    if ($expected !== '' && !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('Forbidden: invalid cron token');
    }
}

$ran = run_due_schedules();   // current minute, Asia/Manila, deduplicated

$msg = "Executed $ran schedule(s) at " . date('H:i:s') . '.';
if ($isCli) {
    echo $msg . PHP_EOL;
} else {
    header('Content-Type: text/plain');
    echo $msg;
}
