<?php
// Auth guard — requires an active CRM login; cuts the request if nobody is logged in.
// Lives in public_html root (CRM); anchored to the document root so it resolves from our
// internal folder. Runs first so it starts the session before our own guard below.
include $_SERVER['DOCUMENT_ROOT'] . '/ajax_1auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once 'config.php';

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

$quoteid = (int) ($_POST['quoteid'] ?? 0);
if (!$quoteid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid quoteid']);
    exit;
}

// follow_date takes the next schedule row after the call by position, so a call logged
// without a date of its own shows the following call's date. Accepted: the date shown is
// still the quote's real next call. Fixing it needs a check that no other call sits between.
$result = $conn->query("
    SELECT
        vqf.auto_id,
        DATE_FORMAT(vqf.calltime, '%d %b %Y %H:%i')           AS call_date,
        vqf.created_by,
        vqf.outcome,
        ext.channel,
        vqf.description                                       AS notes,
        DATE_FORMAT(
            (SELECT s.next_follow_up_date
               FROM vtiger_quotes_followup s
              WHERE s.quoteid = vqf.quoteid AND s.auto_id > vqf.auto_id
                AND s.followup_type = 'schedule_follow_up'
              ORDER BY s.auto_id LIMIT 1),
            '%d %b %Y %H:%i'
        ) AS follow_date
    FROM vtiger_quotes_followup vqf
    LEFT JOIN tdu_quotes_followup_ext ext ON ext.followup_id = vqf.auto_id
    WHERE vqf.quoteid = $quoteid
      AND vqf.followup_type = 'call_info'
    ORDER BY vqf.auto_id DESC
");

if ($result === false) {
    error_log('[TDU Queue] ajax_get_history query failed: ' . $conn->error);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load history. Please try again.']);
    exit;
}

$history = $result->fetch_all(MYSQLI_ASSOC);
$result->free();

// Legacy CRM notes can contain Windows-1252 bytes (e.g. 0x92 = curly apostrophe) that
// are invalid UTF-8. json_encode() then returns false and the browser reports a
// "Network error". Normalise every string field to valid UTF-8 before encoding.
foreach ($history as &$row) {
    foreach ($row as $k => $v) {
        if (is_string($v) && $v !== '' && !mb_check_encoding($v, 'UTF-8')) {
            $row[$k] = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
        }
    }
}
unset($row);

echo json_encode(['success' => true, 'history' => $history], JSON_INVALID_UTF8_SUBSTITUTE);
exit;
