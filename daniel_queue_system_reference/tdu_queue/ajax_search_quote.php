<?php
// Auth guard — requires an active CRM login.
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

$quote_no = trim($_POST['quote_no'] ?? '');
if ($quote_no === '') {
    echo json_encode(['success' => false, 'message' => 'Quote number required']);
    exit;
}
// Tolerate the "TDU" prefix being left off (e.g. "00456" for "TDU00456").
if (stripos($quote_no, 'TDU') !== 0) {
    $quote_no = 'TDU' . $quote_no;
}
// Tolerate the trailing "L" a caller copied off the screen. The queue view appends it to a
// Lead's number at render time; it is never stored, so no real quote_no ends in L. Only the
// last character goes, leaving a Groups Lead's own "G" intact ("TDU123GL" -> "TDU123G").
if (strcasecmp(substr($quote_no, -1), 'L') === 0) {
    $quote_no = substr($quote_no, 0, -1);
}

$quote_no_esc = $conn->real_escape_string($quote_no);

$row = $conn->query("
    SELECT
        vq.quoteid,
        vq.quote_no,
        vq.quotestage,
        vq.accountid AS organizationid,
        va.organization_name,
        vcd.name AS contactname,
        COALESCE(vqinfo.mobile_phone, vcd.mobile, '') AS contactmobile,
        COALESCE(vqinfo.assigned_to_sales_agent, '') AS assigned_to_sales_agent
    FROM vtiger_quotes vq
    LEFT JOIN tdu_organisation va
        ON vq.accountid = va.organizationid
    LEFT JOIN vtiger_quotes_info vqinfo
        ON vqinfo.quoteid = vq.quoteid
    LEFT JOIN tdu_contacts vcd
        ON vq.contactid = vcd.auto_id
       AND va.organizationid = vcd.organizationid
    WHERE vq.quote_no = '$quote_no_esc'
      AND vq.deleted = 0
    LIMIT 1
")->fetch_assoc();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Quote not found']);
    exit;
}

// organization_name and contact names come from CRM free-text columns and can carry legacy
// Windows-1252 bytes; one bad byte makes json_encode() return false for the whole response.
foreach ($row as $k => $v) {
    if (is_string($v) && $v !== '' && !mb_check_encoding($v, 'UTF-8')) {
        $row[$k] = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
}

echo json_encode(['success' => true, 'quote' => $row], JSON_INVALID_UTF8_SUBSTITUTE);
exit;
