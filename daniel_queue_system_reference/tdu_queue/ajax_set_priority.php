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

$quoteid  = (int)  ($_POST['quoteid']  ?? 0);
$priority = trim($_POST['priority'] ?? '');

$valid_priorities = ['', 'not connected', 'low', 'high'];
if (!$quoteid || !in_array($priority, $valid_priorities, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$p_esc = $conn->real_escape_string($priority);
$ok = $conn->query("UPDATE vtiger_quotes_info SET priority = '$p_esc' WHERE quoteid = $quoteid");

echo json_encode(['success' => (bool) $ok]);
exit;
