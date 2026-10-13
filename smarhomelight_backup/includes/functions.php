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

/** Ensure a light_state row exists. */
function ensure_state_row(int $deviceId): void
{
    $stmt = db()->prepare('INSERT IGNORE INTO light_state (device_id) VALUES (?)');
    $stmt->execute([$deviceId]);
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
 * Update the desired state in the DB and queue a command for the device.
 */
function apply_light_state(int $deviceId, array $target, string $source = 'web'): void
{
    ensure_state_row($deviceId);

    $fields = [];
    $params = [];
    foreach (['is_on', 'brightness', 'color_r', 'color_g', 'color_b', 'mode'] as $k) {
        if (array_key_exists($k, $target)) {
            $fields[] = "`$k` = ?";
            $params[] = $target[$k];
        }
    }
    if ($fields) {
        $params[] = $deviceId;
        $sql = 'UPDATE light_state SET ' . implode(', ', $fields) . ' WHERE device_id = ?';
        db()->prepare($sql)->execute($params);
    }

    // Push the merged target to the device.
    $device = get_device($deviceId);
    if ($device) {
        // Only the LATEST target matters. Drop any not-yet-delivered
        // set_state commands first, so a burst of clicks cannot build up a
        // backlog that replays stale states on the device.
        db()->prepare(
            "DELETE FROM commands
              WHERE device_id = ? AND command = 'set_state' AND status = 'pending'"
        )->execute([$deviceId]);

        $payload = [
            'is_on'      => $device['is_on'],
            'brightness' => $device['brightness'],
            'color'      => [$device['color_r'], $device['color_g'], $device['color_b']],
            'mode'       => $device['mode'],
        ];
        queue_command($deviceId, 'set_state', $payload);
    }

    $desc = [];
    foreach (['is_on', 'brightness', 'mode'] as $k) {
        if (array_key_exists($k, $target)) {
            $desc[] = "$k=" . (is_bool($target[$k]) ? (int)$target[$k] : $target[$k]);
        }
    }
    log_activity($deviceId, $source, 'state_change', implode(' ', $desc));
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
