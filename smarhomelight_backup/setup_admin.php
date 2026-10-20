<?php
/**
 * SmartHome Light - Admin account recovery.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';

$adminUser  = 'admin';
$adminPass  = 'admin123';
$adminName  = 'Administrator';

try {
    $row = (static function (PDO $db): string {
        $st = $db->query('SELECT DATABASE() AS db');
        return $st->fetchColumn() ?: '';
    })(db());
    if ($row !== 'smartlight') {
        fwrite(STDERR, "ERROR: expected database 'smartlight', got '{$row}'\n");
        exit(1);
    }

    $st = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $st->execute([$adminUser]);
    $count = (int) $st->fetchColumn();

    if ($count > 0) {
        echo "Admin user '{$adminUser}' already exists. No change made.\n";
        exit(0);
    }

    $hash = password_hash($adminPass, PASSWORD_DEFAULT);

    $sql = 'INSERT INTO users (username, password_hash, full_name, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)';
    $stmt = db()->prepare($sql);
    $stmt->execute([$adminUser, $hash, $adminName]);

    echo "Admin account created: username='{$adminUser}' password='{$adminPass}'\n";
    echo "Full name: {$adminName}\n";
    echo "Hash algorithm: " . password_get_info($hash)['algoName'] . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
    exit(1);
}