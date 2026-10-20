<?php
/**
 * API: dashboard statistics for LightSync features.
 *
 * GET /api/stats.php
 *
 * Returns device counts, mode breakdown, power/energy overview,
 * living room device details, today's hourly usage (minutes) and the
 * current week's daily usage (hours, Mon-Sun) for the dashboard charts.
 * All power/energy figures are ESTIMATED from bulb_watts setting
 * and brightness — not measured by hardware.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['ok' => false, 'error' => 'GET required'], 405);
}

$devices = get_devices();

$total       = count($devices);
$online      = 0;
$offline     = 0;
$lightsOn    = 0;
$totalWatts  = 0.0;
$modes       = ['auto' => 0, 'manual' => 0, 'schedule' => 0];
$livingRoom  = [];

foreach ($devices as $d) {
    if ($d['online']) { $online++; } else { $offline++; }
    if ($d['is_on'])  { $lightsOn++; $totalWatts += $d['watts']; }

    $m = $d['op_mode'] ?? 'auto';
    if (array_key_exists($m, $modes)) { $modes[$m]++; }

    if (strtolower((string)($d['room'] ?? '')) === 'living room') {
        $livingRoom[] = [
            'id'        => (int)$d['id'],
            'name'      => $d['name'],
            'is_on'     => (int)$d['is_on'],
            'actual_on' => (int)$d['actual_on'],
            'brightness'=> (int)$d['brightness'],
            'watts'     => (float)$d['watts'],
            'online'    => (bool)$d['online'],
            'ldr'       => (int)($d['ldr'] ?? 0),
            'room_dark' => (bool)($d['room_dark'] ?? false),
            'rssi'      => (int)$d['rssi'],
        ];
    }
}

// Today's energy (Wh)
$stmt = db()->prepare(
    'SELECT COALESCE(SUM(energy_wh), 0) FROM energy_readings WHERE bucket_hour >= ?'
);
$stmt->execute([date('Y-m-d 00:00:00')]);
$todayWh = (float)$stmt->fetchColumn();

// ---------- Today's lighting usage per hour (minutes) ----------
$stmt = db()->prepare(
    'SELECT HOUR(bucket_hour) AS hr, SUM(minutes_on) AS mins
       FROM energy_readings
      WHERE bucket_hour >= ?
      GROUP BY HOUR(bucket_hour)'
);
$stmt->execute([date('Y-m-d 00:00:00')]);
$hourMap = [];
foreach ($stmt->fetchAll() as $r) {
    $hourMap[(int)$r['hr']] = (int)$r['mins'];
}
// Plot only the hours that have already elapsed so the line never
// dips into "future" zeros that would look like a usage decline.
$currentHour   = (int)date('G');
$todayLabels   = [];
$todayMinutes  = [];
for ($h = 0; $h <= $currentHour; $h++) {
    $todayLabels[]  = sprintf('%02d:00', $h);
    $todayMinutes[] = $hourMap[$h] ?? 0;
}
$todayTotalMin = array_sum($todayMinutes);
$todayHasData  = count($hourMap) > 0;

// ---------- Current week lighting usage per day (hours, Mon-Sun) ----------
// ISO-8601 week: walk back to Monday from today (never use
// strtotime('monday this week') here - it flips to NEXT Monday on Sundays).
$weekStart = new DateTime('today');
$weekStart->modify('-' . (((int)$weekStart->format('N')) - 1) . ' days');

$stmt = db()->prepare(
    'SELECT DATE(bucket_hour) AS day, SUM(minutes_on) AS mins
       FROM energy_readings
      WHERE bucket_hour >= ?
      GROUP BY DATE(bucket_hour)'
);
$stmt->execute([$weekStart->format('Y-m-d 00:00:00')]);
$weekMap = [];
foreach ($stmt->fetchAll() as $r) {
    $weekMap[$r['day']] = (int)$r['mins'];
}
$weekLabels = [];
$weekHours  = [];
for ($i = 0; $i < 7; $i++) {
    $day = (clone $weekStart)->modify("+{$i} days");
    $weekLabels[] = $day->format('D');
    $weekHours[]  = round(($weekMap[$day->format('Y-m-d')] ?? 0) / 60, 2);
}
$weekTotalHours = round(array_sum($weekHours), 2);
$weekHasData    = count($weekMap) > 0;

// Auto vs manual session counts from activity_log today
$stmt = db()->prepare(
    "SELECT action, COUNT(*) AS cnt
       FROM activity_log
      WHERE created_at >= ?
        AND action IN ('op_mode','turned_on','turned_off','set_state')
      GROUP BY action"
);
$stmt->execute([date('Y-m-d 00:00:00')]);
$todayActions = $stmt->fetchAll();
$actionMap = [];
foreach ($todayActions as $a) {
    $actionMap[$a['action']] = (int)$a['cnt'];
}

json_response([
    'ok'      => true,
    'summary' => [
        'total'      => $total,
        'online'     => $online,
        'offline'    => $offline,
        'lights_on'  => $lightsOn,
        'lights_off' => $total - $lightsOn,
        'total_watts'=> round($totalWatts, 2),
        'today_wh'   => round($todayWh, 2),
    ],
    'modes'       => $modes,
    'living_room' => $livingRoom,
    'today'       => [
        'labels'        => $todayLabels,
        'minutes'       => $todayMinutes,
        'total_minutes' => $todayTotalMin,
        'has_data'      => $todayHasData,
    ],
    'weekly'      => [
        'labels'      => $weekLabels,
        'hours'       => $weekHours,
        'total_hours' => $weekTotalHours,
        'has_data'    => $weekHasData,
    ],
    'actions_today' => $actionMap,
]);