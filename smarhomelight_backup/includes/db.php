<?php
/**
 * SmartHome Light - PDO database layer.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// Apply the configured timezone as early as possible. Without this, a script
// that never calls app_config() (e.g. the CLI scheduler) would evaluate
// date()/DateTime in the server's default zone and fire schedules at the
// wrong hour.
(static function (): void {
    $cfg = require __DIR__ . '/config.php';
    $tz  = $cfg['timezone'] ?? null;
    if (is_string($tz) && $tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
})();

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = app_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['name'],
        $cfg['charset']
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
    } catch (PDOException $e) {
        http_response_code(500);
        exit('Database connection failed. Check includes/config.php and run install.php. (' . $e->getMessage() . ')');
    }

    // Align MySQL's clock with PHP's configured timezone.
    // Without this, NOW() (from the MySQL host's OS zone) and PHP's date()
    // can differ by hours, which silently breaks "is the device still
    // online?" checks - a stale device would compute a NEGATIVE age and
    // therefore look permanently online.
    try {
        $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset)) {
            $pdo->exec("SET time_zone = '$offset'");
        }
    } catch (Throwable $e) {
        // Keep the connection usable even if this cannot be applied.
    }

    return $pdo;
}

function app_config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
        date_default_timezone_set($cfg['timezone']);
    }
    if ($key === null) {
        return $cfg;
    }
    return $cfg[$key] ?? $default;
}

/** Read a value from the settings table, falling back to config.php. */
function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}