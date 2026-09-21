<?php
// Row-level quote list behind a By person summary tile click. Admin-only, same direct-session
// guard as the other By person endpoints — read-only, no CSRF check needed.
if (session_status() === PHP_SESSION_NONE) session_start();
if (($_SESSION['title'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
tdu_maintenance_guard(MONITORING_MAINTENANCE_MODE, 'json');
// Required because build_person_quotes() calls accepted_stages_sql(), which calls
// accepted_stage_names() — defined here, not in monitoring_builder.php. Dropping this require
// caused ajax_person_summary.php to 500 once already — don't repeat it.
require_once __DIR__ . '/../queues/queue_builder.php';
require_once __DIR__ . '/monitoring_builder.php';

$user_name = trim((string) ($_GET['user_name'] ?? ''));
$from      = trim((string) ($_GET['from'] ?? ''));
$to        = trim((string) ($_GET['to'] ?? ''));
$filter    = trim((string) ($_GET['filter'] ?? ''));
$org       = trim((string) ($_GET['org'] ?? ''));
$region    = trim((string) ($_GET['region'] ?? ''));
$priority  = trim((string) ($_GET['priority'] ?? ''));

$callers_map = tdu_region_people();
if (!isset($callers_map[$user_name])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown caller']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid date range']);
    exit;
}
if (!in_array($filter, ['total', 'created', 'requote', 'accepted', 'rejected', 'inbound'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid filter']);
    exit;
}

$quotes = build_person_quotes(
    $conn, $callers_map[$user_name], $from, $to, $filter,
    $org !== '' ? $org : null,
    $region !== '' ? $region : null,
    $priority !== '' ? $priority : null
);

echo json_encode(['success' => true, 'quotes' => $quotes], JSON_INVALID_UTF8_SUBSTITUTE);
exit;
