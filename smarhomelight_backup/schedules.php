<?php
/**
 * Schedules - time based automation for each light.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$devices = get_devices();

// Handle create / delete / toggle from this page.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $deviceId = (int)($_POST['device_id'] ?? 0);
        $time     = (string)($_POST['time_of_day'] ?? '');
        $days     = (string)($_POST['days_of_week'] ?? '1111111');

        if ($deviceId > 0 && preg_match('/^\d{2}:\d{2}$/', $time) && preg_match('/^[01]{7}$/', $days)) {
            $label = trim((string)($_POST['label'] ?? 'Schedule')) ?: 'Schedule';
            $stmt = db()->prepare(
                'INSERT INTO schedules (device_id, label, time_of_day, days_of_week, is_on, brightness, color_r, color_g, color_b)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $deviceId, $label, $time . ':00', $days,
                clamp_int($_POST['is_on'] ?? 1, 0, 1),
                clamp_int($_POST['brightness'] ?? 100, 0, 100),
                clamp_channel($_POST['r'] ?? 255),
                clamp_channel($_POST['g'] ?? 214),
                clamp_channel($_POST['b'] ?? 175),
            ]);
            log_activity($deviceId, 'web', 'schedule_created', "$label at $time");
        }
        header('Location: ' . url('schedules.php'));
        exit;
    }

    if ($action === 'delete') {
        $stmt = db()->prepare('DELETE FROM schedules WHERE id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        header('Location: ' . url('schedules.php'));
        exit;
    }

    if ($action === 'toggle') {
        $stmt = db()->prepare('UPDATE schedules SET enabled = 1 - enabled WHERE id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        header('Location: ' . url('schedules.php'));
        exit;
    }
}

$schedules = db()->query(
    'SELECT s.*, d.name AS device_name, d.room
       FROM schedules s
       JOIN devices d ON d.id = s.device_id
      ORDER BY s.time_of_day'
)->fetchAll();

$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
render_header('Schedules', 'schedules');
?>

<link
  rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
>

<div class="page-head">
  <div>
    <h1>Schedules</h1>
    <p class="sub">Automatically switch lights on or off at a set time.</p>
  </div>
</div>

<div class="card">
  <h2>New schedule</h2>
  <?php if ($devices): ?>
  <form method="post" action="<?= url('schedules.php') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-row">
      <label class="field">
        <span>Device</span>
        <select name="device_id" required>
          <?php foreach ($devices as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?> (<?= e($d['room']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
     <label class="field">
  <span>Time Period</span>
  <select name="label" required>
    <option value="" disabled selected>Select Time Period</option>
    <option value="Morning">Morning</option>
    <option value="Afternoon">Afternoon</option>
    <option value="Evening">Evening</option>
    <option value="Early Morning">Early Morning</option>
  </select>
</label>
      <label class="field">
        <span>Time</span>
        <input type="time" name="time_of_day" value="07:00" required>
      </label>
    </div>

    <div class="field">
      <span>Repeated days</span>
      <div class="day-picker">
        <?php for ($i = 0; $i < 7; $i++): ?>
          <label class="day">
            <input type="checkbox" class="day-box" data-index="<?= $i ?>" checked>
            <span><?= $dayNames[$i] ?></span>
          </label>
        <?php endfor; ?>
        <input type="hidden" name="days_of_week" id="daysOfWeek" value="1111111">
      </div>
    </div>

    <div class="form-row">
      <label class="field">
        <span>Action</span>
        <select name="is_on">
          <option value="" disabled selected>Select Option</option>
          <option value="1">Turn on</option>
          <option value="0">Turn off</option>
        </select>
      </label>
      <label class="field">
        <span>Brightness %</span>
        <input type="number" name="brightness" min="0" max="100" value="100">
      </label>
    </div>

    <button class="btn btn-primary" type="submit">Add schedule</button>
  </form>
  <?php else: ?>
    <p class="muted">Add a device first.</p>
  <?php endif; ?>
</div>

<section class="table-wrap card">
  <table class="table">
    <thead>
      <tr><th>Time</th><th>Label</th><th>Device</th><th>Days</th><th>Action</th><th>Brightness</th><th>Last run</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($schedules as $s): ?>
        <?php
          $days = '';
          for ($i = 0; $i < 7; $i++) {
              $days .= ($s['days_of_week'][$i] === '1') ? $dayNames[$i][0] : '.';
          }
        ?>
        <tr class="<?= $s['enabled'] ? '' : 'disabled-row' ?>">
         <td>
    <b><?= date('g:i A', strtotime($s['time_of_day'])) ?></b>
</td>
          <td><?= e($s['label']) ?></td>
          <td><?= e($s['device_name']) ?></td>
          <td class="days"><?= e($days) ?></td>
          <td>
            <span class="badge <?= $s['is_on'] ? 'badge-ok' : 'badge-off' ?>">
              <?= $s['is_on'] ? 'On' : 'Off' ?>
            </span>
          </td>
          <td><?= (int)$s['brightness'] ?>%</td>
          <td><?= e(time_ago($s['last_run'])) ?></td>
          <td class="actions">
            <button class="btn btn-sm" onclick="runNow(<?= (int)$s['id'] ?>, this)">Run now</button>
            <form method="post" action="<?= url('schedules.php') ?>" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-sm" type="submit"><?= $s['enabled'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <form method="post" action="<?= url('schedules.php') ?>" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-sm btn-danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$schedules): ?>
        <tr><td colspan="8" class="muted center">No schedules yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</section>

<div class="card help">
  <h2>Running schedules automatically</h2>
  <p class="muted">
    Schedules fire from <code>cron/run_schedules.php</code>. In Windows, add a Task Scheduler job that runs
    this command once a minute:
  </p>
  <pre class="code">"C:\xampp\php\php.exe" "C:\xampp\htdocs\smarhomelight_backup\cron\run_schedules.php"</pre>
</div>

<?php render_footer(); ?>