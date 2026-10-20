<?php
/**
 * Devices - register ESP32 units, show their keys, rename or remove them.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_login();

$devices  = get_devices();
$basePath = base_url();
render_header('Devices', 'devices');
?>
<div class="page-head">
  <div>
    <h1>Devices</h1>
    <p class="sub">Every ESP32 needs a unique device key. Paste it into the sketch.</p>
  </div>
  <div class="header-right">
    <div class="page-head-clock">
      <time id="liveClock" datetime=""></time>
      <div id="liveDate"></div>
    </div>
    <div class="actions">
      <button class="btn btn-primary" type="button" onclick="toggleAddForm()">+ Add device</button>
    </div>
  </div>
</div>

<div id="addForm" class="card hidden">
  <h2>Register a new light</h2>
  <form method="post" action="<?= url('devices.php') ?>">
    <?= csrf_field() ?>
    <div class="form-row">
      <label class="field">
        <span>Name</span>
        <input type="text" name="name" placeholder="Bedroom Light" required>
      </label>
      <label class="field">
        <span>Room</span>
        <input type="text" name="room" placeholder="Bedroom" required>
      </label>
    </div>
    <button class="btn btn-primary" type="submit">Create device</button>
  </form>
</div>

<?php if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_token() === ($_POST['csrf'] ?? '')): ?>
  <?php
    $name = trim((string)($_POST['name'] ?? ''));
    $room = trim((string)($_POST['room'] ?? '')) ?: 'Living Room';
    if ($name !== '') {
        $key = bin2hex(random_bytes(16));
        $stmt = db()->prepare('INSERT INTO devices (device_key, name, room) VALUES (?, ?, ?)');
        $stmt->execute([$key, $name, $room]);
        $newId = (int)db()->lastInsertId();
        ensure_state_row($newId);
        log_activity($newId, 'web', 'device_added', $name);
        $_SESSION['flash'] = 'Device "' . $name . '" created.';
        header('Location: ' . url('devices.php'));
        exit;
    }
  ?>
<?php endif; ?>


<link
  rel="stylesheet"
  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"
>


<section class="table-wrap card">
  <table class="table">
    <thead>
      <tr>
        <th>Name</th><th>Room</th><th>Status</th><th>Device key</th>
        <th>IP</th><th>Signal</th> <th>Last seen</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($devices as $d): ?>
        <tr data-device="<?= (int)$d['id'] ?>">
          <td><?= e($d['name']) ?></td>
          <td><?= e($d['room']) ?></td>
          <td>
            <span class="badge <?= $d['online'] ? 'badge-ok' : 'badge-off' ?>">
              <?= $d['online'] ? 'Online' : 'Offline' ?>
            </span>
          </td>
          <td><code class="key" data-role="key"><?= e($d['device_key']) ?></code></td>
          <td><?= e($d['ip_address'] ?: '-') ?></td>
          <td><?= (int)$d['rssi'] ?> dBm</td>
          <td><?= e(time_ago($d['last_seen'])) ?></td>
          <td class="actions">
            <button class="btn btn-sm" onclick="copyKey(this)">Copy key</button>
            <button class="btn btn-sm btn-danger"
                    onclick="if(confirm('Delete this device?')) delDevice(<?= (int)$d['id'] ?>, this)">Delete</button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$devices): ?>
        <tr><td colspan="8" class="muted center">No devices registered yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</section>

<div class="card help">
  <h2>Wiring up your ESP32</h2>
  <ol class="steps">
    <li>Copy the <b>device key</b> of the light you want to control.</li>
    <li>Open <code>esp32/smart_light/smart_light.ino</code> in the Arduino IDE.</li>
    <li>Set <code>ssid</code>, <code>password</code> and <code>DEVICE_KEY</code>.</li>
    <li>The <b>relay</b> is on <code>PIN_RELAY</code> (GPIO 26 / D26) and the
        <b>LDR</b> sensor is on <code>PIN_LDR</code> (GPIO 34 / D34).
        Most relay boards are active-LOW (<code>RELAY_ACTIVE_LOW = true</code>).</li>
    <li>Set <code>SERVER_HOST</code> to this PC's LAN IP, e.g. <code><?= e($_SERVER['SERVER_NAME'] ?: '192.168.1.10') ?></code>.</li>
    <li>Upload, then watch the Serial Monitor (115200 baud). The banner must read
        <code>Firmware: 2.3.0</code> — if it does not, the old binary is still on the board.</li>
    <li>The LDR <b>auto-calibrates</b>. Type <code>cal</code>, then cover/uncover the
        sensor — the light should flip ON when covered. If it behaves backwards, set
        <code>LDR_INVERT = true</code> and reflash. Use <code>test</code> to click the relay.</li>
  </ol>
  <p class="muted">
    Base URL used by the device:
    <code><?= e('http://' . ($_SERVER['SERVER_NAME'] ?: '192.168.1.10') . $basePath) ?></code>
  </p>
</div>

<?php render_footer(); ?>