<?php
// Log a call chasing the information a blocked booking is waiting on. info_no_answer and
// info_contacted write the call log only; info_received also writes a note on the quote,
// which is what the extraction agent reads.
//
// No callback date, no snooze, no schedule cap, no schedule_follow_up row: nothing here
// defers a quote. The info_ prefix keeps these codes readable apart from the Follow-up and
// Payment ones, which share the same two tables.
include $_SERVER['DOCUMENT_ROOT'] . '/ajax_1auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once 'config.php';
require_once __DIR__ . '/queues/awaiting_info_builder.php';

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

$quoteid      = (int)  ($_POST['quoteid']   ?? 0);
$outcome      = trim($_POST['outcome']      ?? '');
$channel      = trim($_POST['channel']      ?? 'phone');
$notes_raw    = trim($_POST['notes']        ?? '');
$note_body    = trim($_POST['note_body']    ?? '');
$session_user = tdu_canonical_user($_SESSION['user_name'] ?? '');
$is_admin     = (($_SESSION['title'] ?? '') === 'admin');

$valid_outcomes = ['info_contacted', 'info_no_answer', 'info_received'];
$valid_channels = ['phone', 'whatsapp', 'email', 'other'];

if (!$quoteid || !in_array($outcome, $valid_outcomes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}
if (!in_array($channel, $valid_channels, true)) {
    $channel = 'phone';
}
// Server-side too: the textarea's `required` is bypassable by a stale page or a direct POST.
if ($notes_raw === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A note is required']);
    exit;
}
// An empty one is not a half-filled form, it is the wrong outcome.
if ($outcome === 'info_received' && $note_body === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The information is required']);
    exit;
}

$quote_row = $conn->query("
    SELECT vq.quote_no, vq.quotestage
    FROM vtiger_quotes vq
    WHERE vq.quoteid = $quoteid AND vq.deleted = 0
    LIMIT 1
")->fetch_assoc();

if (!$quote_row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Quote not found']);
    exit;
}

// A stale page or a direct POST could otherwise log against a quote nobody should chase.
if (!in_array($quote_row['quotestage'], awaiting_info_stage_list(), true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'This quote is no longer awaiting information.']);
    exit;
}

// Only the post-sale pair and admins may log here. The page itself (awaiting_info.php) is
// also open to supervisors, but read-only, so a supervisor never posts and is refused here
// if they try. Fail closed: anyone not explicitly named is rejected.
if (!tdu_is_post_sale($session_user) && !$is_admin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You cannot log against this queue.']);
    exit;
}

// Full name for created_by, same lookup ajax_log_outcome.php uses.
$created_by  = $session_user;
$user_lookup = $conn->query("
    SELECT CONCAT(first_name, ' ', last_name) AS name
    FROM vtiger_users
    WHERE user_name = '" . $conn->real_escape_string($session_user) . "'
    LIMIT 1
");
if ($user_lookup && ($user_row = $user_lookup->fetch_assoc()) && trim($user_row['name']) !== '') {
    $created_by = $user_row['name'];
}

$contact_row = $conn->query("
    SELECT
        vcd.name AS contactname,
        COALESCE(vqinfo.office_phone, '') AS office_phone,
        COALESCE(vqinfo.mobile_phone, vcd.mobile, '') AS mobile,
        COALESCE(vqinfo.email,        vcd.email,  '') AS email
    FROM vtiger_quotes vq
    LEFT JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vq.quoteid
    LEFT JOIN tdu_organisation va       ON vq.accountid = va.organizationid
    LEFT JOIN tdu_contacts vcd          ON vq.contactid = vcd.auto_id AND va.organizationid = vcd.organizationid
    WHERE vq.quoteid = $quoteid AND vq.deleted = 0
    LIMIT 1
")->fetch_assoc();

$contact_name = $conn->real_escape_string($contact_row['contactname'] ?? '');
$office_phone = $conn->real_escape_string($contact_row['office_phone'] ?? '');
$mobile       = $conn->real_escape_string($contact_row['mobile'] ?? '');
$email        = $conn->real_escape_string($contact_row['email'] ?? '');
$notes_esc    = $conn->real_escape_string($notes_raw);
$outcome_esc  = $conn->real_escape_string($outcome);
$channel_esc  = $conn->real_escape_string($channel);
$user_esc     = $conn->real_escape_string($created_by);

$conn->begin_transaction();
try {
    // Any pending schedule_follow_up row is left alone, unlike the other two endpoints:
    // it belongs to the Follow-up queue from before the sale, and closing it out here
    // would edit another queue's state off an unrelated call.
    $ok = $conn->query("
        INSERT INTO vtiger_quotes_followup
            (quoteid, followup_type, followup, calltime, next_follow_up_date,
             description, outcome, quotetype,
             travel_agent_contact_name, office_phone, mobile_phone, email, created_by)
        VALUES (
            $quoteid, 'call_info', 'checked', NOW(), NOW(),
            '$notes_esc', '$outcome_esc', 'group',
            '$contact_name', '$office_phone', '$mobile', '$email', '$user_esc'
        )
    ");
    if (!$ok) {
        throw new Exception('followup insert failed: ' . $conn->error);
    }
    $followup_id = $conn->insert_id;

    // snooze_until stays NULL: nothing defers a row in this queue.
    $ok2 = $conn->query("
        INSERT INTO tdu_quotes_followup_ext (followup_id, outcome, channel, snooze_until)
        VALUES ($followup_id, '$outcome_esc', '$channel_esc', NULL)
    ");
    if (!$ok2) {
        throw new Exception('ext insert failed: ' . $conn->error);
    }

    if ($outcome === 'info_received') {
        // Inline rather than delegated: the CRM's own handler is a single INSERT, so
        // there is no behaviour to lose and both writes stay in one transaction.
        // Category is fixed because the extraction ignores it. created_by here is the
        // login name, not the full name used above: that is what the Notes tab shows.
        $ok3 = $conn->query("
            INSERT INTO vtiger_notes (quoteid, category, subcategory, notes, created_by, attachment_path)
            VALUES ('$quoteid', 'Agent/Client', '', '" . $conn->real_escape_string($note_body) . "',
                    '" . $conn->real_escape_string($session_user) . "', '')
        ");
        if (!$ok3) {
            throw new Exception('note insert failed: ' . $conn->error);
        }
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log('[TDU Queue] ajax_log_awaiting_info failed: ' . $e->getMessage() . ' | quoteid=' . $quoteid);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save outcome']);
    exit;
}

// created_by goes back so the page can fill its "by <name>" line without a reload. It has
// to come from here: an admin logging from the manager view is recorded as the admin.
echo json_encode(['success' => true, 'followup_id' => $followup_id, 'created_by' => $created_by]);
exit;
