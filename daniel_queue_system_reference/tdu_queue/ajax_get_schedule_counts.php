<?php
// Auth guard — requires an active CRM login.
include $_SERVER['DOCUMENT_ROOT'] . '/ajax_1auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once 'config.php';
require_once __DIR__ . '/queues/queue_builder.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$session_token = $_SESSION['csrf_token'] ?? '';
$request_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($session_token === '' || $request_token !== $session_token) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$quoteid     = (int) ($_POST['quoteid']     ?? 0);
$followup_id = (int) ($_POST['followup_id'] ?? 0);

if (!$quoteid) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

// Which scheduling pool this quote's callbacks count against. Derived from the quote's own
// stage rather than accepted from the client, since it selects which daily cap applies.
$pool_row      = $conn->query("
    SELECT quotestage FROM vtiger_quotes WHERE quoteid = $quoteid AND deleted = 0 LIMIT 1
")->fetch_assoc();
$stage_pool    = (($pool_row['quotestage'] ?? '') === LEAD_STAGE_NAME) ? 'lead' : 'followup';
$scheduled_cap = ($stage_pool === 'lead') ? LEAD_MAX_SCHEDULED_PER_DAY_PER_CALLER : MAX_SCHEDULED_PER_DAY_PER_CALLER;

[$caller_fullname, $exclude_followup_id] = resolve_caller_and_exclusion($conn, $quoteid, $followup_id);

if ($caller_fullname === '') {
    echo json_encode(['success' => true, 'counts' => new stdClass(), 'max' => $scheduled_cap]);
    exit;
}

$start_date = date('Y-m-d', strtotime('+1 day'));
$end_date   = schedule_window_end($stage_pool);

$counts = get_scheduled_counts_for_caller_range($conn, $caller_fullname, $start_date, $end_date, $exclude_followup_id, $stage_pool);

echo json_encode([
    'success' => true,
    'counts'  => $counts ?: new stdClass(),
    'max'     => $scheduled_cap,
]);
exit;
