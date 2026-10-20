<?php
/**
 * API: paginated History activity log.
 *
 * GET /api/history_log.php?page=1&per_page=10&q=...&date_from=YYYY-MM-DD
 *     &date_to=YYYY-MM-DD&source=web|esp32|schedule|system
 *
 * Returns one page of activity rows (newest first) already formatted for the
 * History table, plus pagination metadata computed from the actual filtered
 * record count in the database. Used by history.php so paging and filtering
 * never require a full page reload.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_response(['ok' => false, 'error' => 'GET required'], 405);
}

// Validate pagination parameters: positive integers only, anything else -> default.
$rawPage    = $_GET['page'] ?? 1;
$rawPerPage = $_GET['per_page'] ?? 10;
$page       = is_scalar($rawPage) ? filter_var($rawPage, FILTER_VALIDATE_INT) : false;
$perPage    = is_scalar($rawPerPage) ? filter_var($rawPerPage, FILTER_VALIDATE_INT) : false;
$page       = ($page !== false && $page > 0) ? $page : 1;
$perPage    = ($perPage !== false && $perPage > 0) ? $perPage : 10;

$filters = activity_log_filters($_GET);
$result  = activity_log_page($filters, $page, $perPage);

json_response([
    'ok'       => true,
    'rows'     => array_map('activity_log_view', $result['rows']),
    'filtered' => $filters['active'],
    'pagination' => [
        'page'        => $result['page'],
        'per_page'    => $result['per_page'],
        'total'       => $result['total'],
        'total_pages' => $result['total_pages'],
        'has_prev'    => $result['has_prev'],
        'has_next'    => $result['has_next'],
    ],
]);