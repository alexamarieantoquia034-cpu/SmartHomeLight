<?php
/**
 * Login page.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Already signed in? Go straight to the dashboard.
if (is_logged_in()) {
    header('Location: ' . url('index.php'));
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter both a username and a password.';
    } else {
        $user = attempt_login($username, $password);
        if ($user) {
            login_user($user);
            header('Location: ' . url('index.php'));
            exit;
        }
        $error = 'Invalid username or password.';
        log_activity(null, 'system', 'login_failed', $username);
    }
}
$siteName = (string)setting('site_name', app_config('site_name'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<title>Sign in &middot; <?= e($siteName) ?></title>
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
<link rel="icon" href="<?= url('assets/favicon.svg') ?>" type="image/svg+xml">
</head>
<body class="login-body">

  <!-- ---------- brand panel ---------- -->
  <section class="login-hero">
    <span class="orb" aria-hidden="true"></span>
    <span class="orb2" aria-hidden="true"></span>

    <div class="hero-inner">
      <span class="hero-badge"><span class="led"></span> ESP32 control panel</span>
      <h1>Your home,<br><em>one tap away.</em></h1>
      <p class="lede">
        Switch lights, dim the room and automate your home &mdash;
        all from one clean dashboard.
      </p>

      <div class="hero-feats">
        <div class="hero-feat">
          <span class="hf-ico" aria-hidden="true">&#9889;</span>
          Live power &amp; brightness control
        </div>
        <div class="hero-feat">
          <span class="hf-ico" aria-hidden="true">&#127912;</span>
          9 colour presets &amp; full spectrum
        </div>
        <div class="hero-feat">
          <span class="hf-ico" aria-hidden="true">&#128340;</span>
          Timed schedules &amp; energy tracking
        </div>
      </div>
    </div>
  </section>

  <!-- ---------- form panel ---------- -->
  <main class="login-panel">
    <div class="login-wrap">
      <form class="login-card" method="post" action="<?= url('login.php') ?>">
        <div class="login-head">
          <div class="logo-big" aria-hidden="true">&#128161;</div>
          <h1>Welcome back</h1>
          <p class="muted">Sign in to control your lights</p>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-err"><?= e($error) ?></div>
        <?php endif; ?>

        <?= csrf_field() ?>

        <label class="field">
          <span>Username</span>
          <input type="text" name="username" placeholder="admin"
                 autocomplete="username" required autofocus>
        </label>

        <label class="field">
          <span>Password</span>
          <span class="pw-wrap">
            <input type="password" name="password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;"
                   autocomplete="current-password" required>
            <button class="pw-toggle" type="button" aria-label="Show password">&#9679;</button>
          </span>
        </label>

        <button class="btn btn-primary btn-block" type="submit">
          Sign in &nbsp;&rarr;
        </button>
      </form>

      <p class="login-foot">
        Protected area &middot; <?= e($siteName) ?>
      </p>
    </div>
  </main>

  <div class="toast-stack" role="status" aria-live="polite"></div>
  <script>
  window.APP_BASE = <?= json_encode(base_url()) ?>;
  window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
  </script>
  <script src="<?= url('assets/js/app.js') ?>" defer></script>
</body>
</html>