<?php
/**
 * API: ESP32 long-poll endpoint.
 *
 * GET  /api/device_poll.php?key=DEVICE_KEY&rssi=-55&ip=192.168.1.50
 *
 * The device authenticates with its device_key, sends its current state, and
 * receives any queued commands in one round-trip.
 *
 * Response JSON:
 *   { "ok":true, "server_time":"...",
 *     "commands":[ {"id":1,"command":"set_state","payload":{...}} ],
 *     "desired": { "is_on":1,"brightness":100,"color":[255,214,175],"mode":"white" } }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$key = trim((string)($_GET['key'] ?? ''));
if ($key === '') {
    json_response(['ok' => false, 'error' => 'Missing device key'], 401);
}

$device = get_device_by_key($key);
if (!$device) {
    json_response(['ok' => false, 'error' => 'Unknown device key'], 401);
}

$deviceId = (int)$device['id'];

// ---- 1. Refresh device liveness info + latest LDR reading ---------------
// The poll runs every few seconds, so storing the LDR here (when the device
// sends it) keeps the sensor value on the dashboard effectively live.
$ldrSql = '';
$ldrParams = [];
if (isset($_GET['ldr']) && $_GET['ldr'] !== '') {
    $ldrSql = ', ldr = ?';
    $ldrParams[] = clamp_int($_GET['ldr'], 0, 4095);
    if (isset($_GET['room_dark']) && $_GET['room_dark'] !== '') {
        $ldrSql .= ', room_dark = ?';
        $ldrParams[] = clamp_int($_GET['room_dark'], 0, 1);
    }
}
$stmt = db()->prepare(
    'UPDATE devices SET last_seen = NOW(), rssi = ?, ip_address = ?, is_online = 1'
    . $ldrSql . ' WHERE id = ?'
);
$stmt->execute(array_merge([
    (int)($_GET['rssi'] ?? $device['rssi']),
    substr((string)($_GET['ip'] ?? ''), 0, 45),
], $ldrParams, [$deviceId]));

// ---- 2. The device reports its CURRENT state ---------------------
// NOTE: this is deliberately NOT written to light_state. light_state holds
// the DESIRED state (what the user asked for). If we stored the device's
// status here, a device that is still catching up would erase the user's
// command before it was ever delivered. The device's real status lives in
// the devices.actual_* columns and is updated by device_heartbeat.php.

// ---- 3. Mark previously delivered commands as done ------------------------
db()->prepare("UPDATE commands SET status='done', executed_at=NOW()
              WHERE device_id = ? AND status = 'sent'")->execute([$deviceId]);

// ---- 4. Hand over any pending commands ------------------------------------
$stmt = db()->prepare(
    "SELECT id, command, payload FROM commands
      WHERE device_id = ? AND status = 'pending'
      ORDER BY id ASC LIMIT 10"
);
$stmt->execute([$deviceId]);
$commands = $stmt->fetchAll();

foreach ($commands as &$c) {
    $c['payload'] = json_decode((string)$c['payload'], true) ?: new stdClass();
}
unset($c);

if ($commands) {
    $ids = array_column($commands, 'id');
    $in  = implode(',', array_map('intval', $ids));
    db()->prepare("UPDATE commands SET status='sent' WHERE id IN ($in)")->execute();
}

// ---- 5. Return the authoritative desired state ----------------------------
ensure_state_row($deviceId);
$stmt = db()->prepare('SELECT * FROM light_state WHERE device_id = ?');
$stmt->execute([$deviceId]);
$state = $stmt->fetch() ?: [];

$desired = [
    'is_on'      => (int)($state['is_on'] ?? 0),
    'brightness' => (int)($state['brightness'] ?? 0),
    'color'      => [
        (int)($state['color_r'] ?? 255),
        (int)($state['color_g'] ?? 214),
        (int)($state['color_b'] ?? 175),
    ],
    'mode'       => $state['mode'] ?? 'white',
];

json_response([
    'ok'          => true,
    'server_time' => date('c'),
    'commands'    => $commands,
    'desired'     => $desired,
    'poll_interval' => (int)setting('poll_interval', app_config('poll_interval')),
]);