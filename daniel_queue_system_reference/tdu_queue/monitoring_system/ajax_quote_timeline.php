<?php
// Individual quote timeline. Admin only, direct session check, same pattern as
// ajax_export_accepted.php, not ajax_1auth.php (that's for logged-in-caller pages). The
// read-only FIT viewer that also used to reach this is gone. Read-only, so no CSRF check
// either. Not scoped by quote type or owner beyond the role check, which is the same
// lookup-any-quote scope admin already has here.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
tdu_maintenance_guard(MONITORING_MAINTENANCE_MODE, 'json');

if ((($_SESSION['title'] ?? '') !== 'admin')) {
    http_response_code(403);
    exit('Forbidden');
}
header('Content-Type: application/json');

require_once __DIR__ . '/monitoring_builder.php';

// Accepts either quoteid (drill-down tables already have it) or quote_no (the quote-number
// search). Same "TDU" prefix tolerance as ajax_search_quote.php.
$quoteid  = (int) ($_GET['quoteid'] ?? 0);
$quote_no = trim((string) ($_GET['quote_no'] ?? ''));

if (!$quoteid && $quote_no !== '') {
    if (stripos($quote_no, 'TDU') !== 0) {
        $quote_no = 'TDU' . $quote_no;
    }
    $quote_no_esc = $conn->real_escape_string($quote_no);
    $lookup = $conn->query("
        SELECT quoteid FROM vtiger_quotes
        WHERE quote_no = '$quote_no_esc' AND deleted = 0
        LIMIT 1
    ")->fetch_assoc();
    $quoteid = $lookup ? (int) $lookup['quoteid'] : 0;
}

if (!$quoteid) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Quote not found']);
    exit;
}

$header = $conn->query("
    SELECT
        vq.quoteid,
        vq.quote_no,
        vq.quotestage,
        vq.created_at,
        va.organization_name,
        vcd.name AS contactname,
        vqinfo.assigned_to_sales_agent AS owner,
        vqcf.cf_1162 AS trip_start_date,
        sched.next_call_date
    FROM vtiger_quotes vq
    LEFT JOIN tdu_organisation va ON vq.accountid = va.organizationid
    LEFT JOIN tdu_contacts vcd
        ON vq.contactid = vcd.auto_id AND va.organizationid = vcd.organizationid
    LEFT JOIN vtiger_quotes_info vqinfo ON vq.quoteid = vqinfo.quoteid
    LEFT JOIN vtiger_quotescf vqcf ON vq.quoteid = vqcf.quoteid
    LEFT JOIN (
        SELECT f.quoteid, DATE(f.next_follow_up_date) AS next_call_date
        FROM vtiger_quotes_followup f
        INNER JOIN (
            SELECT quoteid, MAX(auto_id) AS max_id
            FROM vtiger_quotes_followup
            WHERE followup_type = 'schedule_follow_up'
              AND (followup IS NULL OR followup != 'checked')
            GROUP BY quoteid
        ) ls ON f.auto_id = ls.max_id
    ) sched ON sched.quoteid = vq.quoteid
    WHERE vq.quoteid = $quoteid
      AND vq.deleted = 0
    LIMIT 1
")->fetch_assoc();

if (!$header) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Quote not found']);
    exit;
}
$header['organization_name'] = utf8_safe($header['organization_name']);
$header['contactname']       = utf8_safe($header['contactname']);

// Queue appearances — which bucket (sp1-sp4, carryover) the quote landed in each day it was
// surfaced. item_type/item_id is daily_runs' polymorphic FK (item_type = 'quote' in Phase 1).
$queue_appearances = [];
$res = $conn->query("
    SELECT run_date, bucket
    FROM daily_runs
    WHERE item_type = 'quote' AND item_id = $quoteid
    ORDER BY run_date ASC
");
if ($res) { $queue_appearances = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }

// Calls — same shape ajax_get_history.php reads, but not session-scoped: that file is per-caller,
// this is admin-only for any quote. call_date is returned raw (not DATE_FORMAT'd) since the
// frontend merges it into one chronological timeline and formats it client-side.
$calls = [];
$res = $conn->query("
    SELECT
        vqf.auto_id,
        vqf.calltime AS call_date,
        vqf.created_by,
        vqf.outcome,
        ext.channel,
        vqf.description AS notes
    FROM vtiger_quotes_followup vqf
    LEFT JOIN tdu_quotes_followup_ext ext ON ext.followup_id = vqf.auto_id
    WHERE vqf.quoteid = $quoteid
      AND vqf.followup_type = 'call_info'
    ORDER BY vqf.auto_id ASC
");
if ($res) { $calls = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }
foreach ($calls as &$c) { $c['notes'] = utf8_safe($c['notes']); }
unset($c);

// Stage changes — same normalisation as build_stage_lifecycle_by_owner(): vtiger_quote_stage_track
// is a general changelog; actual stage changes are logged as 'Change Stage to <Stage>' free text.
$stage_changes = [];
$res = $conn->query("
    SELECT
        created_at,
        user_name,
        TRIM(SUBSTRING(stage, LENGTH('Change Stage to ') + 1)) AS stage_name
    FROM vtiger_quote_stage_track
    WHERE quoteid = $quoteid
      AND stage LIKE 'Change Stage to %'
    ORDER BY created_at ASC
");
if ($res) { $stage_changes = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }

// Rejection reason(s), if any — structured reasons only exist on the plain Rejected path; a
// reversal from Accepted has no reason captured (see the honesty note below).
$rejection_reasons = [];
$res = $conn->query("
    SELECT category, reason, other_reason, channel, created_at
    FROM tdu_quote_closure_feedback
    WHERE quoteid = $quoteid
    ORDER BY created_at ASC
");
if ($res) { $rejection_reasons = $res->fetch_all(MYSQLI_ASSOC); $res->free(); }
foreach ($rejection_reasons as &$r) { $r['other_reason'] = utf8_safe($r['other_reason']); }
unset($r);

// If the quote was Accepted at some point but its live stage is now one of the two "Rejected
// After Confrmation" reversal stages, no reason was ever captured for it. Spelling matches the
// confirmed CRM typo used throughout monitoring_builder.php.
$was_accepted = false;
foreach ($stage_changes as $sc) {
    if ($sc['stage_name'] === 'Accepted') { $was_accepted = true; break; }
}
$reversed_after_acceptance = $was_accepted && in_array($header['quotestage'], [
    'Rejected After Confrmation QA pending',
    'Rejected After Confrmation QA completed',
], true);

echo json_encode([
    'success'                   => true,
    'quote'                     => $header,
    'queue_appearances'         => $queue_appearances,
    'calls'                     => $calls,
    'stage_changes'             => $stage_changes,
    'rejection_reasons'         => $rejection_reasons,
    'reversed_after_acceptance' => $reversed_after_acceptance,
], JSON_INVALID_UTF8_SUBSTITUTE);
exit;
