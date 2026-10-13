<?php
/**
 * API: change a light's state from the web UI.
 * POST: device_id, action (on|off|toggle), brightness, r, g, b, mode, csrf
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

require_login();
csrf_check();

$deviceId = (int)($_POST['device_id'] ?? 0);
if ($deviceId <= 0) {
    json_response(['ok' => false, 'error' => 'device_id required'], 400);
}

$device = get_device($deviceId);
if (!$device) {
    json_response(['ok' => false, 'error' => 'Device not found'], 404);
}

$target = [];

switch ($_POST['action'] ?? '') {
    case 'on':
        $target['is_on'] = 1;
        // Turning on at brightness 0 would look broken; default to 100.
        if (($device['brightness'] ?? 0) === 0) {
            $target['brightness'] = 100;
        }
        break;

    case 'off':
        $target['is_on'] = 0;
        break;

    case 'toggle':
        $target['is_on'] = $device['is_on'] ? 0 : 1;
        if ($target['is_on'] && $device['brightness'] === 0) {
            $target['brightness'] = 100;
        }
        break;

    case 'brightness':
        $target['brightness'] = clamp_int($_POST['brightness'] ?? 0, 0, 100);
        // Moving the slider on implies the light should be lit.
        if ($target['brightness'] > 0) {
            $target['is_on'] = 1;
        }
        break;

    case 'color':
        $target['color_r'] = clamp_channel($_POST['r'] ?? 255);
        $target['color_g'] = clamp_channel($_POST['g'] ?? 214);
        $target['color_b'] = clamp_channel($_POST['b'] ?? 175);
        $target['mode']    = 'color';
        $target['is_on']   = 1;
        break;

    case 'mode':
        $mode = ($_POST['mode'] ?? 'white') === 'color' ? 'color' : 'white';
        $target['mode'] = $mode;
        break;

    case 'test':
        // Blink the physical LED without changing its stored state.
        queue_command($deviceId, 'test', []);
        log_activity($deviceId, 'web', 'test_blink', 'requested');
        json_response([
            'ok'      => true,
            'device'  => get_device($deviceId),
            'message' => 'Test blink sent - the light should flash R/G/B/white',
        ]);

    default:
        json_response(['ok' => false, 'error' => 'Unknown action'], 400);
}

apply_light_state($deviceId, $target, 'web');

if (!empty($target['is_on']) && empty($device['is_on'])) {
    record_energy_turn_on($deviceId);
}

json_response([
    'ok'      => true,
    'device'  => get_device($deviceId),
    'message' => 'Command queued',
]);