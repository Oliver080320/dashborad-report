<?php
// Personal claim ("Pull to me") lets a supervisor who owns no region claim a Follow-up
// quote for themselves, without becoming that quote's region owner. Eligible users come
// from tdu_personal_claim_users(), not a hardcoded name.
//
// One quote at a time, no whole-company branch — this is someone reaching for a single
// quote, not a bulk reassignment. It never writes assigned_to_region, so the quote's
// region stays intact and correct_region_ownership() keeps working normally for it.
// Follow-up only (Created/Requote); Leads are out of scope.
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

$quoteid  = (int) ($_POST['quoteid'] ?? 0);
$claim_as = tdu_canonical_user($_POST['claim_as'] ?? '');

// claim_as comes from the POST body rather than being re-derived from the session, the
// same trust model ajax_transfer_quote.php already uses for to_caller — this is what lets
// an admin's "View as: Karthik" claim on his behalf. No per-quote ownership auth exists
// anywhere in this app yet (the project's long-standing known gap); this endpoint adds no
// new exposure beyond what every other write endpoint here already accepts.
$personal_claim_users = tdu_personal_claim_users();
if (!$quoteid || !array_key_exists($claim_as, $personal_claim_users)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid claim']);
    exit;
}
$fullname = $personal_claim_users[$claim_as];
$name_esc = $conn->real_escape_string($fullname);

// The claim goes in whichever CRM ownership column this claimant's own account type
// allows, never both. The other column is left as found: it is the only record of who
// held the quote before, and this claim is not the moment to erase it.
$claim_column = tdu_owner_column(tdu_person_is_external($claim_as));

$today = date('Y-m-d'); // Melbourne tz, set in config.php — matches the snapshot's own clock

$conn->begin_transaction();
$ok = $conn->query("
    UPDATE vtiger_quotes_info
    SET $claim_column = '$name_esc'
    WHERE quoteid = $quoteid
");

if ($ok) {
    // Same-day effect: only inject into today's frozen snapshot if Karthik's own personal
    // queue has already been built today (he opened his page, or the cron ran ahead of
    // this). If it hasn't, there is nothing to reopen — his own next page load builds
    // fresh from the CRM ownership just written above and picks this quote up on its own.
    $personal_region = 'personal:' . $claim_as;
    $rg_esc = $conn->real_escape_string($personal_region);
    $today_esc = $conn->real_escape_string($today);

    // Any Follow-up bucket, not just sp1 — a snapshot that built today with everything
    // landing in sp2-4 (or only carryover) is still "already built today", the same test
    // ajax_transfer_quote.php's own has_snapshot check uses via followup_buckets().
    $fb_in = "'" . implode("','", array_map(fn($b) => $conn->real_escape_string($b), followup_buckets())) . "'";
    $snapshot_res = $conn->query("
        SELECT 1 FROM tdu_region_daily_runs
        WHERE run_date = '$today_esc' AND queue_region = '$rg_esc' AND bucket IN ($fb_in)
        LIMIT 1
    ");
    $has_snapshot = $snapshot_res && $snapshot_res->num_rows > 0;
    $snapshot_res && $snapshot_res->free();

    if ($has_snapshot) {
        // Wherever this quote sits today (its old region's queue, or nowhere), it moves.
        $ok = $conn->query("
            DELETE FROM tdu_region_daily_runs
            WHERE run_date = '$today_esc' AND item_type = 'quote' AND item_id = $quoteid
        ");
        if ($ok) {
            $min_res = $conn->query("
                SELECT MIN(position) AS min_pos
                FROM tdu_region_daily_runs
                WHERE run_date = '$today_esc' AND queue_region = '$rg_esc' AND bucket = 'sp1'
            ");
            $min_row = $min_res ? $min_res->fetch_assoc() : null;
            $min_res && $min_res->free();
            $target_pos = ($min_row && $min_row['min_pos'] !== null) ? ((int) $min_row['min_pos'] - 1) : 1;

            $ok = $conn->query("
                INSERT IGNORE INTO tdu_region_daily_runs
                    (run_date, queue_region, bucket, item_type, item_id, position, owner_user_name)
                VALUES ('$today_esc', '$rg_esc', 'sp1', 'quote', $quoteid, $target_pos, '" . $conn->real_escape_string($claim_as) . "')
            ");
        }
    }
}

if ($ok) {
    $conn->commit();
} else {
    $conn->rollback();
    error_log(
        '[TDU Queue] ajax_claim_quote: failed — ' . $conn->error .
        ' | quoteid=' . $quoteid . ' | claim_as=' . $claim_as
    );
    http_response_code(500);
}

echo json_encode(['success' => (bool) $ok]);
exit;
