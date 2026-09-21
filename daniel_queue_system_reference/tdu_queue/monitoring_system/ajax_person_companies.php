<?php
// Companies drill-down for the By person summary. Admin-only, same direct-session guard as the
// other By person endpoints — read-only, no CSRF check needed.
if (session_status() === PHP_SESSION_NONE) session_start();
if (($_SESSION['title'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
tdu_maintenance_guard(MONITORING_MAINTENANCE_MODE, 'json');
require_once __DIR__ . '/monitoring_builder.php';

$user_name = trim((string) ($_GET['user_name'] ?? ''));
$from      = trim((string) ($_GET['from'] ?? ''));
$to        = trim((string) ($_GET['to'] ?? ''));

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

$companies = build_person_companies($conn, $callers_map[$user_name], $from, $to);

echo json_encode(['success' => true, 'companies' => $companies], JSON_INVALID_UTF8_SUBSTITUTE);
exit;
