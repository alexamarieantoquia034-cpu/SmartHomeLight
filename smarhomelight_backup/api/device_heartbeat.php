<?php
/**
 * API: ESP32 heartbeat - reports real hardware state after acting.
 *
 * POST /api/device_heartbeat.php
 * Body (JSON or form): key, is_on, brightness, r, g, b, mode, rssi, ip,
 *                       firmware, free_heap, uptime_s
 *
 * Used to confirm what the ESP32 actually did (rather than what was asked)
 * and to trigger energy accounting on an off -> on transition.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input') ?: '';
$in  = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $in = $decoded;
    }
}
$in = array_merge($_GET, $in);

$key = trim((string)($in['key'] ?? ''));
if ($key === '') {
    json_response(['ok' => false, 'error' => 'Missing device key'], 401);
}

$device = get_device_by_key($key);
if (!$device) {
    json_response(['ok' => false, 'error' => 'Unknown device key'], 401);
}

$deviceId = (int)$device['id'];

// Record telemetry.
$stmt = db()->prepare(
    'UPDATE devices
        SET last_seen = NOW(), is_online = 1,
            ip_address = COALESCE(NULLIF(?, ""), ip_address),
            firmware   = COALESCE(NULLIF(?, ""), firmware),
            rssi       = ?,
            free_heap  = ?
      WHERE id = ?'
);
$stmt->execute([
    substr((string)($in['ip'] ?? ''), 0, 45),
    substr((string)($in['firmware'] ?? ''), 0, 40),
    (int)($in['rssi'] ?? $device['rssi']),
    (int)($in['free_heap'] ?? 0),
    $deviceId,
]);

// Was the light already reporting ON before this heartbeat?
$wasOn = (int)($device['actual_on'] ?? 0);

ensure_state_row($deviceId);
$isOn = clamp_int($in['is_on'] ?? 0, 0, 1);

// Store the device's REAL status in devices.actual_* - never in light_state,
// which holds the desired state the user asked for.
$mode = (string)($in['mode'] ?? 'white');
$stmt = db()->prepare(
    'UPDATE devices
        SET actual_on = ?, actual_brightness = ?, actual_r = ?, actual_g = ?,
            actual_b = ?, actual_mode = ?,
            ldr = ?, room_dark = ?, reported_at = NOW()
      WHERE id = ?'
);
$stmt->execute([
    $isOn,
    clamp_int($in['brightness'] ?? 0, 0, 100),
    clamp_channel($in['r'] ?? 255),
    clamp_channel($in['g'] ?? 214),
    clamp_channel($in['b'] ?? 175),
    in_array($mode, ['white', 'color'], true) ? $mode : 'white',
    clamp_int($in['ldr'] ?? 0, 0, 4095),
    isset($in['room_dark']) ? clamp_int($in['room_dark'], 0, 1) : null,
    $deviceId,
]);

// Off -> On transition: start accruing energy.
if ($isOn === 1 && $wasOn === 0) {
    record_energy_turn_on($deviceId);
    log_activity($deviceId, 'esp32', 'turned_on', 'brightness=' . (int)($in['brightness'] ?? 0));
}

// When the ESP32 reports an AUTO (LDR-driven) change, mirror it into the
// desired state too, so the dashboard's toggle/bulb follows the sensor.
// Manual web commands are unaffected (they never set auto=1).
if (!empty($in['auto'])) {
    $autoBri = clamp_int($in['brightness'] ?? 0, 0, 100);
    db()->prepare(
        'UPDATE light_state SET is_on = ?, brightness = ? WHERE device_id = ?'
    )->execute([$isOn, $autoBri, $deviceId]);
    log_activity($deviceId, 'esp32', 'auto_' . ($isOn ? 'on' : 'off'),
        'ldr=' . (int)($in['ldr'] ?? 0) . ' room_dark=' . (int)($in['room_dark'] ?? 0));
}

// Acknowledge any command the device has finished executing.
db()->prepare("UPDATE commands SET status='done', executed_at=NOW()
              WHERE device_id = ? AND status='sent'")->execute([$deviceId]);

json_response([
    'ok'      => true,
    'ack'     => true,
    'device'  => get_device($deviceId),
]);