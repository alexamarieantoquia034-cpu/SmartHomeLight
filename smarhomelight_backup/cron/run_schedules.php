<?php
/**
 * Schedule runner - normally triggered by Windows Task Scheduler / cron
 * once per minute:
 *
 *   php C:\xampp\htdocs\smarhomelight_backup\cron\run_schedules.php
 *
 * Also reachable at /cron/run_schedules.php if you point the browser at it.
 * Protect it with an optional token in includes/config.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

// Force the configured timezone to load before we read the clock.
app_config();

$now  = new DateTime();
$time = $now->format('H:i:00');
$dow  = (int)$now->format('w'); // 0 = Sunday

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

$stmt = db()->prepare(
    'SELECT * FROM schedules
      WHERE enabled = 1 AND time_of_day = ? AND SUBSTRING(days_of_week, ?, 1) = "1"'
);
$stmt->execute([$time, $dow + 1]);
$due = $stmt->fetchAll();

$ran = 0;
foreach ($due as $s) {
    $deviceId = (int)$s['device_id'];

    // Don't fire the same schedule twice within the same minute.
    $check = db()->prepare('SELECT last_run FROM schedules WHERE id = ?');
    $check->execute([(int)$s['id']]);
    $lastRun = $check->fetchColumn();
    if ($lastRun && (time() - strtotime($lastRun)) < 60) {
        continue;
    }

    $device = get_device($deviceId);
    if (!$device) {
        continue;
    }

    apply_light_state($deviceId, [
        'is_on'      => (int)$s['is_on'],
        'brightness' => (int)$s['brightness'],
        'color_r'    => (int)$s['color_r'],
        'color_g'    => (int)$s['color_g'],
        'color_b'    => (int)$s['color_b'],
    ], 'schedule');

    if ((int)$s['is_on'] === 1 && !$device['is_on']) {
        record_energy_turn_on($deviceId);
    }

    db()->prepare('UPDATE schedules SET last_run = NOW() WHERE id = ?')->execute([(int)$s['id']]);
    log_activity($deviceId, 'schedule', 'schedule_run', $s['label']);
    $ran++;
}

$msg = "Checked " . count($due) . " due schedule(s), executed $ran at $time.";
if ($isCli) {
    echo $msg . PHP_EOL;
} else {
    header('Content-Type: text/plain');
    echo $msg;
}