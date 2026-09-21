<?php
// Payment Deadline queue — Accepted+ quotes whose payment cancellation deadline
// (cf_1182) is within the trigger window or already passed, trip still ahead.
// Membership is stage-only; payment status is shown per row but no longer gates
// whether a quote appears here.
//
// Deliberately independent of the Follow-up/Engagement/Outreach machinery: no slot
// budget, no daily_runs snapshot, no carry-over — membership is a pure date/stage
// comparison that stays true on every later day once it first becomes true, so
// there is nothing to freeze.
//
// Access-gated, not owned. This queue sits outside the regional model: a fixed
// post-sale pair (tdu_queue_access_view.scope = 'post_sale') plus admins share one
// list across every region, with no per-quote owner to resolve and no per-caller or
// per-team split. Chasing a payment is not selling, so it does not follow the sale's
// region; the row shows its region for context only.
//
// Supervisors get the same list read-only: they oversee every region and need to see
// whether payments are being chased, but chasing them is not their job.
//
// Expects $conn, $session_user, $viewer_is_admin and $today to be set by queue.php.
if (!defined('DAILY_CAPACITY')) require_once __DIR__ . '/../config.php'; // just a "config.php already loaded?" marker, unrelated to this queue having no slot budget
require_once __DIR__ . '/queue_builder.php';

if (!isset($today))           $today           = date('Y-m-d');
if (!isset($session_user))    $session_user    = '';
if (!isset($viewer_is_admin)) $viewer_is_admin = false;

// Defence in depth: re-derive access here regardless of how this file was reached,
// the same convention region_queue.php and fit_viewer.php follow, rather than
// trusting the router.
$is_supervisor = tdu_is_supervisor($session_user);
if (!tdu_is_post_sale($session_user) && !$is_supervisor && !$viewer_is_admin) {
    http_response_code(403);
    exit('Forbidden');
}

// Fail closed: read-only unless a branch explicitly grants the write. A supervisor may
// look but not log, and that holds for an admin using "View as" on one, so the preview
// shows what that supervisor actually gets rather than the admin's own rights.
$is_readonly = true;
if (tdu_is_post_sale($session_user) || ($viewer_is_admin && !$is_supervisor)) {
    $is_readonly = false;
}

// One merged cohort: FIT and Groups & MICE together, every region at once. The team
// split went with the per-caller split. Nobody chasing a payment acts on whether the
// quote was FIT or Groups, they act on how close the deadline is, and each row still
// carries its own quote_type for the CRM deep link.
$queue = build_payment_deadline_queue($conn, INDIA_REGIONS);
$rows     = $queue['rows'];
$unrouted = $queue['unrouted'];

$has_followup_queue = tdu_has_followup_queue($session_user, $viewer_is_admin);

// Page title name. Read from vtiger_users rather than from a caller/owner map: the
// post-sale pair is in none of them, and the roster maps this used to resolve against
// retire with the legacy controllers.
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
    // Whoever is being viewed, including an admin's "View as" target, so the preview is
    // named after the person it previews rather than after the admin doing it.
    $caller_label = explode(' ', trim($name_row['full_name']))[0];
} elseif ($viewer_is_admin) {
    // No real user behind the session: an admin with nobody impersonated, whose
    // $session_user is the 'manager' sentinel and matches no vtiger_users row.
    $caller_label = 'Admin';
}

require __DIR__ . '/../views/payment_deadline_view.php';
