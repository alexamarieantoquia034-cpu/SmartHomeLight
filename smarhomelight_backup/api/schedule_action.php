<?php
/**
 * API: schedules CRUD.
 * POST action = create | toggle | delete | run_now
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}
require_login();
csrf_check();

$action = (string)($_POST['action'] ?? '');

switch ($action) {

    case 'create':
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $label    = trim((string)($_POST['label'] ?? 'Schedule')) ?: 'Schedule';
        $time     = trim((string)($_POST['time_of_day'] ?? ''));
        $days     = (string)($_POST['days_of_week'] ?? '1111111');
        $isOn     = clamp_int($_POST['is_on'] ?? 1, 0, 1);

        if ($deviceId <= 0 || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            json_response(['ok' => false, 'error' => 'device_id and a valid time_of_day are required'], 400);
        }
        if (!preg_match('/^[01]{7}$/', $days)) {
            json_response(['ok' => false, 'error' => 'days_of_week must be 7 binary digits'], 400);
        }

        $stmt = db()->prepare(
            'INSERT INTO schedules (device_id, label, time_of_day, days_of_week, is_on, brightness, color_r, color_g, color_b)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deviceId,
            $label,
            $time . ':00',
            $days,
            $isOn,
            clamp_int($_POST['brightness'] ?? 100, 0, 100),
            clamp_channel($_POST['r'] ?? 255),
            clamp_channel($_POST['g'] ?? 214),
            clamp_channel($_POST['b'] ?? 175),
        ]);
        log_activity($deviceId, 'web', 'schedule_created', "$label at $time");
        json_response(['ok' => true, 'message' => 'Schedule created']);

    case 'toggle':
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('UPDATE schedules SET enabled = 1 - enabled WHERE id = ?');
        $stmt->execute([$id]);
        json_response(['ok' => true, 'message' => 'Schedule toggled']);

    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('DELETE FROM schedules WHERE id = ?');
        $stmt->execute([$id]);
        json_response(['ok' => true, 'message' => 'Schedule deleted']);

    case 'run_now':
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM schedules WHERE id = ?');
        $stmt->execute([$id]);
        $s = $stmt->fetch();
        if (!$s) {
            json_response(['ok' => false, 'error' => 'Schedule not found'], 404);
        }
        $deviceId = (int)$s['device_id'];
        $device = get_device($deviceId);

        db()->prepare("UPDATE devices SET op_mode = 'schedule' WHERE id = ?")->execute([$deviceId]);
    apply_light_state($deviceId, [
            'is_on'      => (int)$s['is_on'],
            'brightness' => (int)$s['brightness'],
            'color_r'    => (int)$s['color_r'],
            'color_g'    => (int)$s['color_g'],
            'color_b'    => (int)$s['color_b'],
        ], 'schedule');

        // A manually-run schedule owns the relay too (same as cron).
        db()->prepare("UPDATE devices SET op_mode = 'schedule' WHERE id = ? AND op_mode <> 'schedule'")
            ->execute([$deviceId]);
        // A schedule fired -> this device is now Schedule-driven
        // (LDR must not override it).
        set_op_mode($deviceId, 'schedule');

        if ((int)$s['is_on'] === 1 && $device && !$device['is_on']) {
            record_energy_turn_on($deviceId);
        }
        db()->prepare('UPDATE schedules SET last_run = NOW() WHERE id = ?')->execute([$id]);

        json_response(['ok' => true, 'message' => 'Schedule executed']);

    default:
        json_response(['ok' => false, 'error' => 'Unknown action'], 400);
}