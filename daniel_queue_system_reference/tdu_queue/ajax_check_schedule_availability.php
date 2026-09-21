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
$date_raw    = trim($_POST['date'] ?? '');

if (!$quoteid || !$date_raw || !strtotime($date_raw)) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}
$date = date('Y-m-d', strtotime($date_raw));

// Which scheduling pool this quote's callbacks count against. Derived from the quote's own
// stage rather than accepted from the client, since it selects which daily cap applies.
$pool_row      = $conn->query("
    SELECT quotestage FROM vtiger_quotes WHERE quoteid = $quoteid AND deleted = 0 LIMIT 1
")->fetch_assoc();
$stage_pool    = (($pool_row['quotestage'] ?? '') === LEAD_STAGE_NAME) ? 'lead' : 'followup';
$scheduled_cap = ($stage_pool === 'lead') ? LEAD_MAX_SCHEDULED_PER_DAY_PER_CALLER : MAX_SCHEDULED_PER_DAY_PER_CALLER;

// Read-only preview of the same cap ajax_log_outcome.php enforces — lets the modal
// show the agent how many slots are left for a date before they try to save.
[$caller_fullname, $exclude_followup_id] = resolve_caller_and_exclusion($conn, $quoteid, $followup_id);

if ($caller_fullname === '') {
    echo json_encode([
        'success' => true, 'count' => 0, 'max' => $scheduled_cap,
        'full' => false, 'next_available_date' => null,
    ]);
    exit;
}

$count = count_scheduled_for_caller_date($conn, $caller_fullname, $date, $exclude_followup_id, $stage_pool);
// $scheduled_cap <= 0 is the "no cap" sentinel (Leads today): never full, whatever the count.
$full  = $scheduled_cap > 0 && $count >= $scheduled_cap;

$trip_row  = $conn->query("
    SELECT cf_1162 AS trip_date
    FROM vtiger_quotescf
    WHERE quoteid = $quoteid
    LIMIT 1
")->fetch_assoc();
$trip_date = $trip_row['trip_date'] ?? null;

// If the requested date is full, walk forward day by day to the next date this caller can
// actually use, so the agent does not guess-and-check one date at a time. Bounded by this
// pool's scheduling window and by the quote's own trip date, whichever comes first.
$next_available_date = null;
if ($full) {
    $window_end = schedule_window_end($stage_pool);
    if ($trip_date) {
        $day_before_trip = date('Y-m-d', strtotime(substr($trip_date, 0, 10) . ' -1 day'));
        if ($day_before_trip < $window_end) $window_end = $day_before_trip;
    }
    $candidate  = date('Y-m-d', strtotime($date . ' +1 day'));
    while ($candidate <= $window_end) {
        if ($scheduled_cap <= 0 || count_scheduled_for_caller_date($conn, $caller_fullname, $candidate, null, $stage_pool) < $scheduled_cap) {
            $next_available_date = $candidate;
            break;
        }
        $candidate = date('Y-m-d', strtotime($candidate . ' +1 day'));
    }
}

echo json_encode([
    'success'              => true,
    'count'                => $count,
    'max'                  => $scheduled_cap,
    'full'                 => $full,
    'next_available_date'  => $next_available_date,
]);
exit;
