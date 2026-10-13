<?php
/**
 * API: current state of all lights (used by the dashboard auto-refresh).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$devices = get_devices();

$online = 0;
$lightsOn = 0;
$totalWatts = 0.0;
foreach ($devices as $d) {
    if ($d['online']) { $online++; }
    // Count EVERY light by its desired state, exactly like index.php does on
    // the first render. Counting only online devices here made the headline
    // tiles change a few seconds after the page had already been painted.
    if ($d['is_on']) {
        $lightsOn++;
        $totalWatts += $d['watts'];
    }
}

json_response([
    'ok'         => true,
    'server_time' => date('c'),
    'summary'    => [
        'total'      => count($devices),
        'online'     => $online,
        'lights_on'  => $lightsOn,
        'total_watts' => round($totalWatts, 2),
    ],
    'devices'    => $devices,
]);