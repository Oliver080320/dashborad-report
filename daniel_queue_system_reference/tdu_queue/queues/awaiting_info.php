<?php
// Awaiting Information queue — Accepted+ quotes whose booking is held up because the
// client has not supplied what the booking agents need. Own page (?view=awaiting_info),
// not a section embedded in a Follow-up page: the post-sale pair has no Follow-up queue
// of their own to embed it in, which is exactly the gap this step exists to close.
//
// Same access shape as Payment Deadline: a fixed post-sale pair
// (tdu_queue_access_view.scope = 'post_sale') plus supervisors (read-only) plus admins,
// one merged list across every region, FIT and Groups & MICE together. No per-quote
// owner, no pool, no "Pull to me" — chasing missing information is not selling, so it
// does not follow the region model's ownership; the row shows its region for context
// only.
//
// The cohort query and the entire reason-parsing/grouping layer in
// awaiting_info_builder.php (group_pause_reasons(), group_awaiting_by_org(), etc.) are
// shared with build_region_awaiting_info_queue(), the merged builder this page calls.
//
// Expects $conn, $session_user, $viewer_is_admin and $today to be set by queue.php.
if (!defined('DAILY_CAPACITY')) require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/awaiting_info_builder.php';

if (!isset($today))           $today           = date('Y-m-d');
if (!isset($session_user))    $session_user    = '';
if (!isset($viewer_is_admin)) $viewer_is_admin = false;

// Defence in depth: re-derive access here regardless of how this file was reached, same
// convention payment_deadline.php and region_queue.php follow, rather than trusting the
// router.
$is_supervisor = tdu_is_supervisor($session_user);
if (!tdu_is_post_sale($session_user) && !$is_supervisor && !$viewer_is_admin) {
    http_response_code(403);
    exit('Forbidden');
}

// Fail closed: read-only unless a branch explicitly grants the write, same rule Payment
// Deadline uses. A supervisor may look but not log, and that holds for an admin using
// "View as" on one, so the preview shows what that supervisor actually gets.
$is_readonly = true;
if (tdu_is_post_sale($session_user) || ($viewer_is_admin && !$is_supervisor)) {
    $is_readonly = false;
}

$queue    = build_region_awaiting_info_queue($conn, INDIA_REGIONS);
$rows     = group_awaiting_by_org($queue['rows']);
$unrouted = group_awaiting_by_org($queue['unrouted']);

$has_followup_queue = tdu_has_followup_queue($session_user, $viewer_is_admin);

// Page title name — same lookup payment_deadline.php uses, since the post-sale pair is in
// none of the caller/owner maps.
$caller_label = $session_user;
$name_row     = null;
if ($session_user !== '') {
    $name_res = $conn->query("
        SELECT CONCAT(first_name, ' ', last_name) AS full_name
        FROM vtiger_users
        WHERE user_name = '" . $conn->real_escape_string($session_user) . "'
        LIMIT 1
    ");
    if ($name_res) $name_row = $name_res->fetch_assoc();
}
if ($name_row && trim($name_row['full_name']) !== '') {
    $caller_label = explode(' ', trim($name_row['full_name']))[0];
} elseif ($viewer_is_admin) {
    $caller_label = 'Admin';
}

require __DIR__ . '/../views/awaiting_info_view.php';
