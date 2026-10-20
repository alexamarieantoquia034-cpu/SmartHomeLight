<?php
/**
 * SmartHome Light - shared helper functions.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Build a base URL that works in a subfolder or the Apache document root. */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $cfg = app_config('base_path', 'auto');
    if ($cfg !== 'auto') {
        return $base = rtrim($cfg, '/');
    }

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $scriptDir = rtrim($scriptDir, '/');
    // API files live one level deeper than the pages.
    if (substr($scriptDir, -4) === '/api') {
        $scriptDir = substr($scriptDir, 0, -4);
    }
    return $base = $scriptDir;
}

/** Absolute-path URL builder: url('api/get_state.php'). */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return base_url() . '/' . $path;
}

/** Send a JSON response and stop. */
function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/** HTML escaping. */
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Queue a command for a device.
 */
function queue_command(int $deviceId, string $command, array $payload = []): int
{
    $stmt = db()->prepare(
        'INSERT INTO commands (device_id, command, payload, status) VALUES (?, ?, ?, "pending")'
    );
    $stmt->execute([$deviceId, $command, json_encode($payload, JSON_UNESCAPED_SLASHES)]);
    return (int)db()->lastInsertId();
}

/** Write an entry to the activity log. */
function log_activity(?int $deviceId, string $source, string $action, $detail = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO activity_log (device_id, source, action, detail) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $deviceId,
        $source,
        $action,
        is_array($detail) ? json_encode($detail, JSON_UNESCAPED_SLASHES) : $detail,
    ]);
}

/**
 * Normalize History-page activity-log filters.
 * Returns q / date_from / date_to / source (invalid values silently dropped
 * so a bad bookmarked URL still renders) plus an 'active' flag used for the
 * empty-state message.
 */
function activity_log_filters(array $input): array
{
    $q = is_string($input['q'] ?? null) ? trim($input['q']) : '';
    $q = function_exists('mb_substr') ? mb_substr($q, 0, 100) : substr($q, 0, 100);

    $dateFrom = is_string($input['date_from'] ?? null) ? trim($input['date_from']) : '';
    $dateTo   = is_string($input['date_to'] ?? null) ? trim($input['date_to']) : '';
    $okFrom   = DateTime::createFromFormat('!Y-m-d', $dateFrom);
    $okTo     = DateTime::createFromFormat('!Y-m-d', $dateTo);
    if (!$okFrom || $okFrom->format('Y-m-d') !== $dateFrom) { $dateFrom = ''; }
    if (!$okTo   || $okTo->format('Y-m-d')   !== $dateTo)   { $dateTo   = ''; }

    $source = is_string($input['source'] ?? null) ? strtolower(trim($input['source'])) : '';
    if (!in_array($source, ['web', 'esp32', 'schedule', 'system'], true)) {
        $source = '';
    }

    return [
        'q'         => $q,
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
        'source'    => $source,
        'active'    => ($q !== '' || $dateFrom !== '' || $dateTo !== '' || $source !== ''),
    ];
}

/**
 * Fetch one page of the History activity log, newest first.
 *
 * The page count always comes from a COUNT(*) over the SAME filters, and the
 * requested page is clamped into [1, total_pages], so a stale or bogus ?page=
 * can never render an invalid page number. All filter values are bound as
 * prepared-statement parameters; LIMIT/OFFSET are bound as integers.
 *
 * Returns: rows (raw), page, per_page, total, total_pages, has_prev, has_next.
 */
function activity_log_page(array $filters, int $page, int $perPage = 10): array
{
    $perPage = max(5, min(100, $perPage));

    $where  = [];
    $params = [];

    if ($filters['q'] !== '') {
        // Escape LIKE wildcards; MySQL's default escape character is backslash.
        $like   = '%' . addcslashes($filters['q'], '\\%_') . '%';
        $where[] = '(l.action LIKE ? OR l.detail LIKE ? OR l.source LIKE ? OR d.name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    if ($filters['date_from'] !== '') {
        $where[]  = 'l.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if ($filters['date_to'] !== '') {
        $where[]  = 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
        $params[] = $filters['date_to'];
    }
    if ($filters['source'] !== '') {
        $where[]  = 'l.source = ?';
        $params[] = $filters['source'];
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $count = db()->prepare(
        'SELECT COUNT(*)
           FROM activity_log l
           LEFT JOIN devices d ON d.id = l.device_id
          ' . $whereSql
    );
    $count->execute($params);
    $total      = (int)$count->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = max(1, min($page, $totalPages));

    $stmt = db()->prepare(
        'SELECT l.id, l.device_id, l.source, l.action, l.detail, l.created_at,
                d.name AS device_name
           FROM activity_log l
           LEFT JOIN devices d ON d.id = l.device_id
          ' . $whereSql . '
          ORDER BY l.id DESC
          LIMIT ? OFFSET ?'
    );
    $i = 1;
    foreach ($params as $p) {
        $stmt->bindValue($i++, $p);
    }
    $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($i++, ($page - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'rows'        => $stmt->fetchAll(),
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages,
        'has_prev'    => $page > 1,
        'has_next'    => $page < $totalPages,
    ];
}

/**
 * Display-ready view of one activity_log row. Shared by history.php (initial
 * server render) and api/history_log.php (AJAX pages) so the formatting rules
 * can never drift between the two.
 */
function activity_log_view(array $l): array
{
    $detail        = (string)($l['detail'] ?? '');
    $readableDetail = $detail;

    // Convert technical details into readable messages
    if (str_contains($detail, 'is_on=1')) {
        $readableDetail = 'Light turned ON';
    } elseif (str_contains($detail, 'is_on=0')) {
        $readableDetail = 'Light turned OFF';
    }

    // Include brightness information when available
    if (preg_match('/brightness=(\d+)/', $detail, $matches)) {
        $readableDetail .= ' • Brightness set to ' . $matches[1] . '%';
    }

    // Convert schedule details
    if (str_contains($detail, 'Evening at')) {
        $readableDetail = 'Schedule set for ' . str_replace('Evening at ', '', $detail);
    }

    return [
        'id'           => (int)($l['id'] ?? 0),
        'when'         => date('M d, Y g:i A', strtotime((string)($l['created_at'] ?? ''))),
        'source'       => (string)($l['source'] ?? ''),
        'source_label' => ucfirst((string)($l['source'] ?? '')),
        'device_name'  => ($l['device_name'] ?? '-'),
        'action_label' => ucwords(str_replace('_', ' ', (string)($l['action'] ?? ''))),
        'detail'       => $readableDetail,
    ];
}

/** Ensure a light_state row exists. */
function ensure_state_row(int $deviceId): void
{
    $stmt = db()->prepare('INSERT IGNORE INTO light_state (device_id) VALUES (?)');
    $stmt->execute([$deviceId]);
}

/** Set operating mode: auto | manual | schedule. Single place of truth. */
function set_op_mode(int $deviceId, string $op): void
{
    $op = in_array($op, ['auto', 'manual', 'schedule'], true) ? $op : 'auto';
    ensure_state_row($deviceId);
    db()->prepare('UPDATE devices SET op_mode = ? WHERE id = ?')->execute([$op, $deviceId]);
    log_activity($deviceId, 'system', 'op_mode', $op);
}

/**
 * Apply a desired-state change: update light_state + queue set_state command.
 * $source: 'web' | 'schedule' (used for activity log).
 */
function apply_light_state(int $deviceId, array $target, string $source = 'web'): void
{
    ensure_state_row($deviceId);
    $fields = [];
    $params = [];
    foreach (['is_on', 'brightness', 'color_r', 'color_g', 'color_b', 'mode'] as $k) {
        if (array_key_exists($k, $target)) {
            $fields[] = "$k = ?";
            $params[] = $target[$k];
        }
    }
    if ($fields) {
        $params[] = $deviceId;
        db()->prepare('UPDATE light_state SET ' . implode(', ', $fields) . ' WHERE device_id = ?')->execute($params);
    }
    queue_command($deviceId, 'set_state', $target);
    log_activity($deviceId, $source, 'set_state', $target);
}

/**
 * Fire every schedule whose time has arrived, in the configured timezone
 * (Asia/Manila). Safe to call as often as you like - the ESP32 polls this
 * endpoint every ~5 s and Windows Task Scheduler runs cron/run_schedules.php
 * once a minute, so both can hit the same second. An atomic claim on
 * schedules.last_run guarantees each schedule fires at most once per matching
 * minute even when two runners race, which is what prevents duplicate relay
 * actions after repeated polls or an ESP32 reconnect.
 *
 * $deviceId: restrict to one device (ESP32 poll) or null for all (cron).
 * Returns how many schedules actually fired.
 */
function run_due_schedules(?int $deviceId = null): int
{
    // Current minute, evaluated in the configured (Asia/Manila) timezone.
    // date('H:i:00') zeroes the seconds, so ANY second inside the matching
    // minute still resolves the schedule - a late runner cannot skip it.
    $time        = date('H:i:00');
    $dow         = (int)date('w');                       // 0 = Sunday
    $minuteStart = date('Y-m-d H:i:00');                 // start of that minute

    $sql    = 'SELECT * FROM schedules
                WHERE enabled = 1 AND time_of_day = ?
                  AND SUBSTRING(days_of_week, ?, 1) = "1"';
    $params = [$time, $dow + 1];                          // days string is 1=Sun .. 7=Sat
    if ($deviceId !== null) {
        $sql .= ' AND device_id = ?';
        $params[] = $deviceId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $due = $stmt->fetchAll();
    if (!$due) {
        return 0;
    }

    $ran = 0;
    foreach ($due as $s) {
        // Atomic claim: only ONE runner wins this schedule for this minute.
        // rowCount 0 means cron and the ESP32 poll reached it at the same
        // time (or it already ran) - skip instead of double-executing.
        $claim = db()->prepare(
            'UPDATE schedules SET last_run = NOW()
              WHERE id = ?
                AND (last_run IS NULL OR last_run < ?)'
        );
        $claim->execute([(int)$s['id'], $minuteStart]);
        if ($claim->rowCount() !== 1) {
            continue;
        }

        $deviceIdNow = (int)$s['device_id'];
        $device      = get_device($deviceIdNow);
        if (!$device) {
            continue;
        }

        // A fired schedule owns the relay: switch to SCHEDULE mode BEFORE
        // applying the state so the LDR can never undo the timed action,
        // and so the ESP32 poll reports schedule as the active mode.
        set_op_mode($deviceIdNow, 'schedule');
        apply_light_state($deviceIdNow, [
            'is_on'      => (int)$s['is_on'],
            'brightness' => (int)$s['brightness'],
            'color_r'    => (int)$s['color_r'],
            'color_g'    => (int)$s['color_g'],
            'color_b'    => (int)$s['color_b'],
        ], 'schedule');

        if ((int)$s['is_on'] === 1 && !$device['is_on']) {
            record_energy_turn_on($deviceIdNow);
        }

        log_activity($deviceIdNow, 'schedule', 'schedule_run', $s['label']);
        $ran++;
    }

    return $ran;
}

/**
 * Full light snapshot for the dashboard.
 */
function get_devices(): array
{
    $sql = 'SELECT d.*, s.is_on, s.brightness, s.color_r, s.color_g, s.color_b, s.mode, s.updated_at
            FROM devices d
            LEFT JOIN light_state s ON s.device_id = d.id
            ORDER BY d.id';

    $devices = db()->query($sql)->fetchAll();

    $offlineAfter = (int)setting('offline_after', app_config('offline_after'));
    $watts        = (float)setting('bulb_watts', app_config('bulb_watts'));

    // Keep the stored is_online flag honest so nothing ever reports a
    // device as connected after it has actually gone quiet.
    try {
        db()->prepare(
            'UPDATE devices SET is_online = 0
              WHERE is_online = 1
                AND (last_seen IS NULL OR last_seen < (NOW() - INTERVAL ? SECOND))'
        )->execute([$offlineAfter]);
    } catch (Throwable $e) {
        // Never let housekeeping break the dashboard.
    }

    foreach ($devices as &$d) {
        $lastSeen = $d['last_seen'] ? strtotime($d['last_seen']) : null;
        // A negative age means the clock moved (or MySQL/PHP disagree).
        // Treat that as NOT online rather than permanently connected.
        $age = $lastSeen !== null ? (time() - $lastSeen) : null;
        $d['online'] = ($age !== null && $age >= 0 && $age <= $offlineAfter);

        // Desired state = what the user asked for (light_state).
        $d['is_on']  = (int)($d['is_on'] ?? 0);
        $d['brightness'] = (int)($d['brightness'] ?? 0);
        $d['color_r'] = (int)($d['color_r'] ?? 255);
        $d['color_g'] = (int)($d['color_g'] ?? 214);
        $d['color_b'] = (int)($d['color_b'] ?? 175);
        $d['mode']    = $d['mode'] ?? 'white';

        // Actual state = what the device last reported it is really doing.
        $d['actual_on']        = (int)($d['actual_on'] ?? 0);
        $d['actual_brightness'] = (int)($d['actual_brightness'] ?? 0);
        $d['actual_r'] = (int)($d['actual_r'] ?? 255);
        $d['actual_g'] = (int)($d['actual_g'] ?? 214);
        $d['actual_b'] = (int)($d['actual_b'] ?? 175);

        // Operating mode: who owns the relay right now.
        $d['op_mode'] = in_array($d['op_mode'] ?? 'auto', ['auto', 'manual', 'schedule'], true)
            ? ($d['op_mode'] ?? 'auto') : 'auto';

        // in_sync = the hardware has caught up with the command.
        $d['in_sync'] = ($d['actual_on'] === $d['is_on']
                         && $d['actual_brightness'] === $d['brightness']);

        $d['watts'] = $d['is_on'] ? round($watts * ($d['brightness'] / 100), 2) : 0.0;
    }
    unset($d);

    return $devices;
}

/** Single device by id. */
function get_device(int $id): ?array
{
    foreach (get_devices() as $d) {
        if ((int)$d['id'] === $id) {
            return $d;
        }
    }
    return null;
}

/** Look up a device by its shared secret key. */
function get_device_by_key(string $key): ?array
{
    $stmt = db()->prepare('SELECT * FROM devices WHERE device_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Credit energy to the current hourly bucket whenever a light turns on.
 */
function record_energy_turn_on(int $deviceId): void
{
    $watts = (float)setting('bulb_watts', app_config('bulb_watts'));
    $state = db()->prepare('SELECT brightness FROM light_state WHERE device_id = ?');
    $state->execute([$deviceId]);
    $brightness = (int)($state->fetchColumn() ?: 0);

    $wh = round($watts * ($brightness / 100) / 60, 3); // one hour of run
    $bucket = date('Y-m-d H:00:00');

    $sql = 'INSERT INTO energy_readings (device_id, bucket_hour, minutes_on, energy_wh)
            VALUES (?, ?, 60, ?)
            ON DUPLICATE KEY UPDATE minutes_on = minutes_on + 60, energy_wh = energy_wh + VALUES(energy_wh)';
    db()->prepare($sql)->execute([$deviceId, $bucket, $wh]);
}

/** Human friendly "time ago". */
function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return 'never';
    }
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    return floor($diff / 86400) . ' d ago';
}

/** Two-letter initials for the avatar chip (mbstring optional). */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $up    = static function (string $ch): string {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($ch, 'UTF-8')
            : strtoupper($ch);
    };
    $out = '';
    foreach ($parts as $p) {
        if ($p === '') { continue; }
        $out .= $up(function_exists('mb_substr') ? mb_substr($p, 0, 1, 'UTF-8') : $p[0]);
        if (strlen($out) >= 2) { break; }
    }
    return $out !== '' ? $out : '?';
}

/** Common page header with navigation. */
function render_header(string $title, string $active = ''): void
{
    $site  = (string)setting('site_name', app_config('site_name'));
    $user  = current_username();
    $flash = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']); // one-shot message

    $nav = [
        'dashboard' => ['index.php',     'Dashboard', "\u{1F3E0}"],
        'devices'   => ['devices.php',   'Devices',   "\u{1F4F1}"],
        'schedules' => ['schedules.php', 'Schedules', "\u{23F0}"],
        'history'   => ['history.php',   'History',   "\u{1F4CA}"],
    ];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<title><?= e($title) ?> &middot; <?= e($site) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
<link rel="icon" href="<?= url('assets/favicon.svg') ?>" type="image/svg+xml">
</head>
<body>
<header class="topbar">
  <div class="brand">
    <span class="logo" aria-hidden="true">&#128161;</span>
    <?= e($site) ?>
  </div>

  <button class="nav-toggle" type="button" aria-label="Toggle navigation">&#9776;</button>

  <nav class="nav">
    <?php foreach ($nav as $key => [$file, $label, $icon]): ?>
      <a class="<?= $active === $key ? 'active' : '' ?>"
         href="<?= url($file) ?>"
         <?= $active === $key ? 'aria-current="page"' : '' ?>>
        <span class="ico" aria-hidden="true"><?= $icon ?></span><?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="userbox">
    <div class="avatar" aria-hidden="true"><?= e(initials($user)) ?></div>
    <div class="who">
      <b><?= e($user) ?></b>
      <span>Administrator</span>
    </div>
    <a class="btn btn-ghost" href="<?= url('logout.php') ?>"><i class="fa-solid fa-door-open"></i></a>
  </div>
</header>

<main class="wrap">
    <?php if ($flash !== ''): ?>
      <div class="alert alert-ok" data-auto-dismiss><?= e($flash) ?></div>
    <?php endif; ?>
    <?php
}

function render_footer(): void
{
    ?>
</main>
<div class="toast-stack" role="status" aria-live="polite"></div>
<script>
window.APP_BASE   = <?= json_encode(base_url()) ?>;
window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
</script>
<script>
(function () {
  'use strict';
  var clockEl = document.getElementById('liveClock');
  var dateEl  = document.getElementById('liveDate');
  if (!clockEl || !dateEl) return;

  var timeOptions = {
    timeZone: 'Asia/Manila',
    hour12: true,
    hour: 'numeric',
    minute: '2-digit',
    second: '2-digit'
  };
  var dateOptions = {
    timeZone: 'Asia/Manila',
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  };

  function updateClock() {
    var now = new Date();
    clockEl.textContent = now.toLocaleTimeString('en-PH', timeOptions);
    clockEl.dateTime = now.toISOString();
    dateEl.textContent = now.toLocaleDateString('en-PH', dateOptions);
  }

  updateClock();
  setInterval(updateClock, 1000);
})();
</script>
<script src="<?= url('assets/js/app.js') ?>" defer></script>
</body>
</html>
    <?php
}

/** Clamp an integer into a range. */
function clamp_int($value, int $min, int $max): int
{
    return max($min, min($max, (int)$value));
}

/** Clamp a colour channel to 0-255. */
function clamp_channel($value): int
{
    return (int)max(0, min(255, (int)$value));
}
