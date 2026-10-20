<?php
/**
 * Dashboard - one card per light with on/off, brightness and colour control.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$devices = get_devices();
$siteName = (string)setting('site_name', app_config('site_name'));

// Headline stats
$totalOnline = 0;
$lightsOn    = 0;
$totalWatts  = 0.0;
foreach ($devices as $d) {
    if ($d['online']) { $totalOnline++; }
    if ($d['is_on']) { $lightsOn++; $totalWatts += $d['watts']; }
}

// Today's energy (Wh)
$stmt = db()->prepare(
    'SELECT COALESCE(SUM(energy_wh), 0) FROM energy_readings WHERE bucket_hour >= ?'
);
$stmt->execute([date('Y-m-d 00:00:00')]);
$todayWh = (float)$stmt->fetchColumn();

$wattsPerBulb = (float)setting('bulb_watts', app_config('bulb_watts'));

// Colour presets offered on every light card.
$presets = [
    'Warm white'  => '#ffd6af',
    'Cool white'  => '#ffffff',
    'Red'         => '#ff4d4d',
    'Green'       => '#4dff88',
    'Blue'        => '#4d8cff',
    'Purple'      => '#b44dff',
    'Cyan'        => '#4dffff',
    'Orange'      => '#ff9f43',
    'Party'       => '#ff00ff',
];

$hour   = (int)date('G');
$greet  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$userNm = current_username();

render_header('Dashboard', 'dashboard');
?>
<!-- Font Awesome Icons -->

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

<!-- Chart.js for usage statistics charts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<div class="page-head">
  <div class="greeting-col">
    <div class="greeting-row">
      <h1 id="liveGreet"><?= e($greet) ?>, <?= e($userNm) ?></h1>
    </div>
    <p class="sub">Here's what's happening with your lights right now.</p>
  </div>
  <div class="header-right">
    <div class="page-head-clock">
      <time id="liveClock" datetime=""></time>
      <div id="liveDate"></div>
    </div>
    <div class="actions">
      <div class="pill <?= $totalOnline ? 'pill-ok' : 'pill-warn' ?>" id="connStatus">
        <?= $totalOnline ?> / <?= count($devices) ?> device<?= count($devices) === 1 ? '' : 's' ?> online
      </div>
      <?php if ($devices): ?>
        <button class="btn" type="button" onclick="bulkToggle(false)">All off</button>
        <button class="btn btn-primary" type="button" onclick="bulkToggle(true)">All on</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<section class="stats">
  <div class="stat stat-warn">
    <span class="stat-ico" aria-hidden="true">&#128161;</span>
    <span class="stat-body">
      <span class="stat-label">Lights on</span>
      <span class="stat-value"><span id="statOn"><?= $lightsOn ?></span><small>/ <?= count($devices) ?></small></span>
    </span>
  </div>

  <div class="stat stat-blue">
    <span class="stat-ico" aria-hidden="true">&#9889;</span>
    <span class="stat-body">
      <span class="stat-label">Power now</span>
      <span class="stat-value"><span id="statWatts"><?= number_format($totalWatts, 1) ?></span><small>W</small></span>
    </span>
  </div>

  <div class="stat stat-ok">
    <span class="stat-ico" aria-hidden="true">&#128200;</span>
    <span class="stat-body">
      <span class="stat-label">Energy today</span>
      <span class="stat-value"><span id="statWh"><?= number_format($todayWh, 2) ?></span><small>Wh</small></span>
    </span>
  </div>

  <!--<div class="stat">
    <span class="stat-ico" aria-hidden="true">&#128176;</span>
    <span class="stat-body">
      <span class="stat-label">Est. cost / day</span>
      <span class="stat-value"><?= number_format($todayWh * 0.15 / 1000, 2) ?><small>IDR</small></span>
    </span>
  </div>-->
</section>

<?php if (!$devices): ?>
  <div class="card empty">
    <div class="logo-big" aria-hidden="true">&#128161;</div>
    <h2>No devices yet</h2>
    <p class="muted">Register your first ESP32 and control it from anywhere on your network.</p>
    <a class="btn btn-primary" href="<?= url('devices.php') ?>">Add a device</a>
  </div>
<?php else: ?>
  <section class="grid">
    <?php foreach ($devices as $d): ?>
      <?php
        $rgb  = $d['color_r'] . ',' . $d['color_g'] . ',' . $d['color_b'];
        $dim  = 0.32 + 0.68 * ($d['brightness'] / 100);
        $bg   = $d['is_on']
            ? "background:rgba($rgb,$dim);box-shadow:0 0 46px rgba($rgb,.55), inset 0 0 26px rgba($rgb,.35)"
            : 'background:rgba(120,130,150,.10);box-shadow:none';
        $wash = $d['is_on'] ? "rgba($rgb,.16)" : 'transparent';
        $hexv = sprintf('#%02x%02x%02x', $d['color_r'], $d['color_g'], $d['color_b']);
        // Initial STATE readout = the hardware's LAST REPORTED state
        // (actual_on, written by the ESP32 heartbeat) - not the desired one.
        $shownOn = (!empty($d['reported_at']))
            ? (bool)$d['actual_on'] : (bool)$d['is_on'];
      ?>
      <article class="card light-card<?= $d['is_on'] ? ' is-on' : '' ?><?= $d['online'] ? '' : ' is-offline' ?>"
               data-device="<?= (int)$d['id'] ?>"
               style="--wash:<?= $wash ?>">

        <header class="light-head">
          <div class="light-id">
            <div>
              <h2><?= e($d['name']) ?></h2>
              <span class="room"><?= e($d['room']) ?></span>
            </div>
          </div>
          <span class="status-dot <?= $d['online'] ? 'on' : 'off' ?>"
                title="<?= $d['online'] ? 'Online' : 'Offline' ?>"></span>
        </header>

        <div class="bulb-stage">
          <div class="bulb" style="<?= $bg ?>">
            <span class="bulb-icon"><?= $d['is_on'] ? '&#128161;' : '&#127769;' ?></span>
          </div>
        </div>

        <div class="light-meta">
          <span data-role="state"><?= $shownOn ? 'ON' : 'OFF' ?></span>
          <span class="dot">&bull;</span>
          <span data-role="brightness"><?= $d['brightness'] ?>%</span>
          <span class="dot">&bull;</span>
          <span data-role="power"><?= number_format($d['watts'], 1) ?> W</span>
          <span class="dot">&bull;</span>
          <span data-role="ldr" title="Light sensor (LDR) - raw reading and dark/bright">&#9788; <?= (int)($d['ldr'] ?? 0) ?> &middot; <?= !empty($d['room_dark']) ? 'dark' : 'bright' ?></span>
        </div>

        <div class="sync-note" data-role="sync">&mdash;</div>

        <div class="mode-row" role="group" aria-label="Operating mode">
          <button class="mode-pill<?= $d['op_mode'] === 'auto' ? ' active' : '' ?>"
                  type="button" data-action="mode-pill" data-mode="auto"
                  title="LDR sensor owns the relay">&#9889; Auto</button>
          <button class="mode-pill<?= $d['op_mode'] === 'manual' ? ' active' : '' ?>"
                  type="button" data-action="mode-pill" data-mode="manual"
                  title="Dashboard owns the relay - LDR is ignored">&#9998; Manual</button>
          <button class="mode-pill<?= $d['op_mode'] === 'schedule' ? ' active' : '' ?>"
                  type="button" data-action="mode-pill" data-mode="schedule"
                  title="Schedules own the relay - LDR is ignored">&#9200; Schedule</button>
        </div>

        <div class="power-row">
          <button class="btn btn-power <?= $d['is_on'] ? 'on' : '' ?>" type="button" data-action="toggle">
            <?= $d['is_on'] ? 'Turn off' : 'Turn on' ?>
          </button>
          <button class="btn btn-test" type="button" data-action="test"
                  title="Flash the LED to check the wiring" aria-label="Test blink">&#128267;</button>
        </div>

        <label class="field slider-field">
          <span>Brightness <b data-role="brightness-label"><?= $d['brightness'] ?>%</b></span>
          <input type="range" min="0" max="100" value="<?= $d['brightness'] ?>"
                 style="--pct:<?= $d['brightness'] ?>%"
                 data-action="brightness" aria-label="Brightness">
        </label>

        <footer class="light-foot">
          <span title="Signal">&#128246; <?= (int)$d['rssi'] ?> dBm</span>
          <span title="IP">&#127760; <?= e($d['ip_address'] ?: 'no ip') ?></span>
          <span title="Last seen"><?= e(time_ago($d['last_seen'])) ?></span>
        </footer>
      </article>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<?php if ($devices): ?>

<!-- Statistics & Usage Charts Section -->
  <section class="stats-charts">
    <h2 class="section-title">Usage Statistics</h2>
    <div class="charts-grid">
      <div class="chart-card">
        <div class="chart-head">
          <h3>Today's Usage</h3>
          <span class="chart-total" id="todayTotal">&mdash;</span>
        </div>
        <canvas id="energyChart" height="200"></canvas>
        <p class="chart-empty" id="energyEmpty" hidden>No usage recorded yet today.</p>
      </div>
      <div class="chart-card">
        <div class="chart-head">
          <h3>Weekly Overview</h3>
          <span class="chart-total" id="weekTotal">&mdash;</span>
        </div>
        <canvas id="weeklyChart" height="200"></canvas>
        <p class="chart-empty" id="weeklyEmpty" hidden>No usage recorded this week.</p>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php render_footer(); ?>