<?php
/**
 * SmartHome Light - session authentication + CSRF helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Start the session with a hardened cookie. */
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name(app_config('session_name'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Verify credentials; returns the user row on success. */
function attempt_login(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    return $user;
}

function login_user(array $user): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int)$user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
}

function logout_user(): void
{
    start_session();
    $_SESSION = [];
    session_regenerate_id(true);
    session_destroy();
}

function is_logged_in(): bool
{
    start_session();
    return isset($_SESSION['user_id']);
}

function current_username(): string
{
    start_session();
    $name = $_SESSION['full_name'] ?: ($_SESSION['username'] ?? 'User');
    // Normalize legacy display name for consistent user-facing text.
    if ($name === 'System Administrator') {
        $name = 'Administrator';
    }
    return $name;
}

/** Page guard - redirect to login when not authenticated. */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: ' . url('login.php'));
        exit;
    }
}

/** CSRF token for forms. */
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/** Validate the CSRF token from a POST request; exits on mismatch. */
function csrf_check(): void
{
    start_session();
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$sent)) {
        json_response(['ok' => false, 'error' => 'Invalid CSRF token'], 419);
    }
}