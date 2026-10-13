<?php
/**
 * History - energy usage and the activity log.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

// Energy per day for the last 7 days.
$stmt = db()->prepare(
    "SELECT DATE(bucket_hour) AS day,
            SUM(energy_wh)   AS wh,
            SUM(minutes_on)  AS minutes
       FROM energy_readings
      WHERE bucket_hour >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
      GROUP BY DATE(bucket_hour)
      ORDER BY day"
);
$stmt->execute();
$energy = $stmt->fetchAll();

$map = [];
foreach ($energy as $row) {
    $map[$row['day']] = $row;
}

// Build a continuous 7-day series (missing days = zero).
$series = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $series[] = [
        'day'     => $day,
        'label'   => date('D', strtotime($day)),
        'wh'      => (float)($map[$day]['wh'] ?? 0),
        'minutes' => (int)($map[$day]['minutes'] ?? 0),
    ];
}
$maxWh = 0.0;
foreach ($series as $s) { $maxWh = max($maxWh, $s['wh']); }
$maxWh = $maxWh > 0 ? $maxWh : 1.0;

$totalWh = array_sum(array_column($series, 'wh'));
$totalMin = array_sum(array_column($series, 'minutes'));

// Recent activity
$logs = db()->query(
    'SELECT l.*, d.name AS device_name
       FROM activity_log l
       LEFT JOIN devices d ON d.id = l.device_id
      ORDER BY l.id DESC LIMIT 60'
)->fetchAll();

render_header('History', 'history');
?>

<link
  rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
>

<div class="page-head">
  <div>
    <h1>History</h1>
    <p class="sub">Estimated energy use and recent activity (last 7 days).</p>
  </div>
</div>

<section class="stats">
  <div class="stat stat-ok">
    <span class="stat-ico" aria-hidden="true">&#128200;</span>
    <span class="stat-body">
      <span class="stat-label">Energy (7 days)</span>
      <span class="stat-value"><?= number_format($totalWh, 2) ?><small>Wh</small></span>
    </span>
  </div>

  <div class="stat stat-blue">
    <span class="stat-ico" aria-hidden="true">&#9200;</span>
    <span class="stat-body">
      <span class="stat-label">Time on</span>
      <span class="stat-value"><?= number_format($totalMin / 60, 1) ?><small>h</small></span>
    </span>
  </div>

  <div class="stat stat-warn">
    <span class="stat-ico" aria-hidden="true">&#9878;</span>
    <span class="stat-body">
      <span class="stat-label">Avg / day</span>
      <span class="stat-value"><?= number_format($totalWh / 7, 2) ?><small>Wh</small></span>
    </span>
  </div>

  <!--<div class="stat">
    <span class="stat-ico" aria-hidden="true">&#128176;</span>
    <span class="stat-body">
      <span class="stat-label">Est. cost (7d)</span>
      <span class="stat-value"><?= number_format($totalWh * 0.15 / 1000, 2) ?><small>IDR</small></span>
    </span>
  </div>-->
</section>

<div class="card">
  <h2>Daily consumption</h2>
  <?php if ($totalWh > 0): ?>
    <div class="chart">
      <?php foreach ($series as $s): ?>
        <div class="bar-wrap" title="<?= $s['day'] ?>: <?= number_format($s['wh'], 2) ?> Wh">
          <div class="bar" style="height: <?= max(3, (int)round(($s['wh'] / $maxWh) * 150)) ?>px"></div>
          <span class="bar-label"><?= e($s['label']) ?></span>
          <span class="bar-val"><?= number_format($s['wh'], 1) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="muted">No energy recorded yet. Turn a light on to start collecting data.</p>
  <?php endif; ?>
</div>

<section class="table-wrap card">
  <h2>Activity log</h2>
  <table class="table">
    <thead>
      <tr><th>When</th><th>Source</th><th>Device</th><th>Action</th><th>Detail</th></tr>
    </thead>
   
<tbody>
  <?php foreach ($logs as $l): ?>
    <?php
      $detail = $l['detail'] ?? '';
      $readableDetail = $detail;

      // Convert technical details into readable messages
      if (str_contains($detail, 'is_on=1')) {
          $readableDetail = 'Light turned ON';
      } elseif (str_contains($detail, 'is_on=0')) {
          $readableDetail = 'Light turned OFF';
      }

      // Include brightness information when available
      if (preg_match('/brightness=(\d+)/', $detail, $matches)) {
          $brightness = $matches[1];
          $readableDetail .= ' • Brightness set to ' . $brightness . '%';
      }

      // Convert schedule details
      if (str_contains($detail, 'Evening at')) {
          $readableDetail = 'Schedule set for ' .
              str_replace('Evening at ', '', $detail);
      }
    ?>
    <tr>
      <td>
  <?= date('M d, Y g:i A', strtotime($l['created_at'])) ?>
</td>

      <td>
        <span class="badge src-<?= e($l['source']) ?>">
          <?= e(ucfirst($l['source'])) ?>
        </span>
      </td>

      <td><?= e($l['device_name'] ?? '-') ?></td>

      <td><?= e(ucwords(str_replace('_', ' ', $l['action']))) ?></td>

      <td class="muted"><?= e($readableDetail) ?></td>
    </tr>
  <?php endforeach; ?>

  <?php if (!$logs): ?>
    <tr>
      <td colspan="5" class="muted center">Nothing logged yet.</td>
    </tr>
  <?php endif; ?>
</tbody>

  </table>
</section>

<?php render_footer(); ?>