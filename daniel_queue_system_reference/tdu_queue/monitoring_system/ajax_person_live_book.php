<?php
// Live-book quotes behind the By person tab's travel-month chart + quote table. Admin only,
// direct session guard, same pattern as the other By person endpoints. The read-only FIT
// viewer that also used to reach this is gone. Read-only, no CSRF check needed. Does not call
// accepted_stages_sql(), so it doesn't require queue_builder.php — don't add that require
// "just in case".
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
tdu_maintenance_guard(MONITORING_MAINTENANCE_MODE, 'json');

if ((($_SESSION['title'] ?? '') !== 'admin')) {
    http_response_code(403);
    exit('Forbidden');
}
header('Content-Type: application/json');

require_once __DIR__ . '/monitoring_builder.php';

$user_name = trim((string) ($_GET['user_name'] ?? ''));

$callers_map = tdu_region_people();
if (!isset($callers_map[$user_name])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown caller']);
    exit;
}

$quotes = build_person_live_book($conn, $callers_map[$user_name]);

echo json_encode(['success' => true, 'quotes' => $quotes], JSON_INVALID_UTF8_SUBSTITUTE);
exit;
