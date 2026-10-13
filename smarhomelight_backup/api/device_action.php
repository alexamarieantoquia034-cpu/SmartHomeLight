<?php
/**
 * API: manage devices - add, rename, delete, regenerate key.
 * POST action = add | update | delete | rotate_key
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}
require_login();
csrf_check();

$action = (string)($_POST['action'] ?? '');

switch ($action) {

    case 'add':
        $name = trim((string)($_POST['name'] ?? ''));
        $room = trim((string)($_POST['room'] ?? 'Living Room'));
        if ($name === '') {
            json_response(['ok' => false, 'error' => 'Name is required'], 400);
        }

        $newKey = bin2hex(random_bytes(16));
        $pdo = db()->beginTransaction();
        try {
            $stmt = db()->prepare('INSERT INTO devices (device_key, name, room) VALUES (?, ?, ?)');
            $stmt->execute([$newKey, $name, $room ?: 'Living Room']);
            $id = (int)db()->lastInsertId();
            ensure_state_row($id);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            json_response(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        log_activity($id, 'web', 'device_added', $name);
        json_response(['ok' => true, 'id' => $id, 'device_key' => $newKey, 'message' => 'Device added']);

    case 'update':
        $id   = (int)($_POST['device_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $room = trim((string)($_POST['room'] ?? ''));
        if ($id <= 0) {
            json_response(['ok' => false, 'error' => 'device_id required'], 400);
        }
        $stmt = db()->prepare('UPDATE devices SET name = ?, room = ? WHERE id = ?');
        $stmt->execute([$name, $room, $id]);
        log_activity($id, 'web', 'device_updated', "$name / $room");
        json_response(['ok' => true, 'message' => 'Device updated']);

    case 'delete':
        $id = (int)($_POST['device_id'] ?? 0);
        $stmt = db()->prepare('DELETE FROM devices WHERE id = ?');
        $stmt->execute([$id]);
        log_activity(null, 'web', 'device_deleted', "id=$id");
        json_response(['ok' => true, 'message' => 'Device deleted']);

    case 'rotate_key':
        $id = (int)($_POST['device_id'] ?? 0);
        $newKey = bin2hex(random_bytes(16));
        $stmt = db()->prepare('UPDATE devices SET device_key = ? WHERE id = ?');
        $stmt->execute([$newKey, $id]);
        log_activity($id, 'web', 'key_rotated', null);
        json_response(['ok' => true, 'device_key' => $newKey, 'message' => 'New key generated']);

    default:
        json_response(['ok' => false, 'error' => 'Unknown action'], 400);
}