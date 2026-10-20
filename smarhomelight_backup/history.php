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

// Activity log: search / date filters + database pagination (newest first).
// activity_log_page() computes the page count from a COUNT(*) over the SAME
// filters and clamps the requested page into [1, total_pages], so a stale or
// bogus ?page= can never render an invalid page number.
$logFilters = activity_log_filters($_GET);
$rawPage    = $_GET['page'] ?? 1;
$rawPerPage = $_GET['per_page'] ?? 10;
$logPage    = is_scalar($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT) : false;
$logPerPage = is_scalar($rawPerPage) ? filter_var($rawPerPage, FILTER_VALIDATE_INT) : false;
$logPage    = $logPage !== false && $logPage > 0 ? $logPage : 1;
$logPerPage = $logPerPage !== false && $logPerPage > 0 ? $logPerPage : 10;
$log        = activity_log_page($logFilters, $logPage, $logPerPage);
$logs       = array_map('activity_log_view', $log['rows']);

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
  <div class="header-right">
    <div class="page-head-clock">
      <time id="liveClock" datetime=""></time>
      <div id="liveDate"></div>
    </div>
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

<section class="card">
  <h2>Activity log</h2>

  <form class="log-filters" id="logFilters" autocomplete="off">
    <input type="search" name="q" id="logQ"
           placeholder="Search action, detail or device…"
           value="<?= e($logFilters['q']) ?>" aria-label="Search history">
    <input type="date" name="date_from" id="logFrom"
           value="<?= e($logFilters['date_from']) ?>" aria-label="From date">
    <input type="date" name="date_to" id="logTo"
           value="<?= e($logFilters['date_to']) ?>" aria-label="To date">
    <select name="source" id="logSource" aria-label="Event source">
      <option value="">All sources</option>
      <?php foreach (['web', 'esp32', 'schedule', 'system'] as $src): ?>
        <option value="<?= $src ?>"<?= $logFilters['source'] === $src ? ' selected' : '' ?>><?= ucfirst($src) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">Apply</button>
    <button type="button" class="btn btn-ghost" id="logReset">Reset</button>
  </form>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr><th>When</th><th>Source</th><th>Device</th><th>Action</th><th>Detail</th></tr>
      </thead>
   
<tbody id="logBody">
  <?php foreach ($logs as $l): ?>
    <tr>
      <td><?= e($l['when']) ?></td>

      <td>
        <span class="badge src-<?= e($l['source']) ?>"><?= e($l['source_label']) ?></span>
      </td>

      <td><?= e($l['device_name']) ?></td>

      <td><?= e($l['action_label']) ?></td>

      <td class="muted"><?= e($l['detail']) ?></td>
    </tr>
  <?php endforeach; ?>

  <?php if (!$logs): ?>
    <tr>
      <td colspan="5" class="muted center"><?= $logFilters['active'] ? 'No records match your filters.' : 'Nothing logged yet.' ?></td>
    </tr>
  <?php endif; ?>
</tbody>

    </table>
  </div>

  <!-- Pagination: data-* seed the JS; buttons come pre-disabled from the DB
       counts so the control is correct even before app.js runs. -->
  <div class="pager" id="logPager"
       data-page="<?= (int)$log['page'] ?>"
       data-per-page="<?= (int)$log['per_page'] ?>">
    <button type="button" class="btn pager-btn" id="logPrev"
            <?= $log['has_prev'] ? '' : 'disabled' ?>>&lt; Previous</button>
    <span class="pager-info" id="logInfo">
      <?php if ($log['total'] > 0): ?>
        Page <strong><?= (int)$log['page'] ?></strong> of <?= (int)$log['total_pages'] ?>
        <span class="pager-count"><?= number_format($log['total']) ?> records</span>
      <?php else: ?>
        No records
      <?php endif; ?>
    </span>
    <button type="button" class="btn pager-btn" id="logNext"
            <?= $log['has_next'] ? '' : 'disabled' ?>>Next &gt;</button>
  </div>
</section>

<?php render_footer(); ?>