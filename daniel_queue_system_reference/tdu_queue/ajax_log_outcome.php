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

// Read ALL POST params up front.
$quoteid         = (int)  ($_POST['quoteid']        ?? 0);
$followup_id     = (int)  ($_POST['followup_id']    ?? 0);
$outcome         = trim($_POST['outcome']         ?? '');
$channel         = trim($_POST['channel']         ?? 'phone');
$next_date       = trim($_POST['next_call_date']  ?? '');
$notes_raw       = trim($_POST['notes']           ?? '');
$feedback_reason = trim($_POST['feedback_reason'] ?? '');
$feedback_other  = trim($_POST['feedback_other']  ?? '');
$session_user    = tdu_canonical_user($_SESSION['user_name'] ?? '');
$today           = date('Y-m-d');

// requote changes stage like terminal outcomes AND schedules a reappearance like a
// call outcome, so it belongs to neither group and gets its own branch below.
$terminal_outcomes    = ['rejected', 'accepted', 'lead_converted', 'lead_rejected'];
$staged_call_outcomes = ['requote'];
$call_outcomes        = ['next_call', 'no_answer_email', 'interested', 'inbound_call'];
$valid_channels       = ['phone', 'whatsapp', 'email', 'other'];
// The stages a queue can still act on. Everything else is closed as far as we are concerned.
$workable_stages      = [LEAD_STAGE_NAME, 'Created', 'Requote'];

if (!$quoteid || !$outcome) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}
if (!in_array($outcome, array_merge($terminal_outcomes, $staged_call_outcomes, $call_outcomes), true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}
if (!in_array($channel, $valid_channels, true)) {
    $channel = 'phone';
}

// Resolve the caller's full name for created_by, matching details.php's "First Last"
// format. Falls back to the session user_name if no matching CRM user is found.
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

// Trip date guard — read once, reused by both the requote branch below and the
// call-outcome branch further down. A callback/reschedule date must be strictly
// before the client's trip date, or the quote is stuck showing a future call
// after the client has already travelled.
$trip_row  = $conn->query("
    SELECT cf_1162 AS trip_date
    FROM vtiger_quotescf
    WHERE quoteid = $quoteid
    LIMIT 1
")->fetch_assoc();
$trip_date = $trip_row['trip_date'] ?? null;

// Current stage, read once and used by both branches below: the stage-change branch validates
// the transition against it, the call-outcome branch picks the scheduling pool from it. Read
// from the DB, not the client, since it decides which cap is enforced.
$stage_row = $conn->query("
    SELECT quotestage FROM vtiger_quotes
    WHERE quoteid = $quoteid AND deleted = 0
    LIMIT 1
")->fetch_assoc();
if (!$stage_row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Quote not found']);
    exit;
}
$current_stage = $stage_row['quotestage'];

// Follow-up and Leads keep separate daily allowances for scheduled calls. A quote is at
// 'Lead' or at 'Created'/'Requote', never both, so its own stage decides the pool.
$stage_pool     = ($current_stage === LEAD_STAGE_NAME) ? 'lead' : 'followup';
$scheduled_cap  = ($stage_pool === 'lead') ? LEAD_MAX_SCHEDULED_PER_DAY_PER_CALLER : MAX_SCHEDULED_PER_DAY_PER_CALLER;

// -------------------------------------------------------------------------
// STAGE-CHANGE BRANCH — rejected / accepted / requote
// rejected/accepted exit here (pure terminal, no vtiger_quotes_followup write).
// requote falls through into the CALL OUTCOME BRANCH below instead of exiting.
// -------------------------------------------------------------------------
if (in_array($outcome, array_merge($terminal_outcomes, $staged_call_outcomes), true)) {

    // Validate date + schedule cap BEFORE the stage-change delegation below — that
    // change can't be rolled back, so this must be a hard gate, not a post-write check.
    $requote_follow_date  = null;
    $requote_snooze_until = null;
    if ($outcome === 'requote') {
        if (!$next_date || !strtotime($next_date)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A return date is required for Requote']);
            exit;
        }
        [$requote_follow_date, $requote_snooze_until] = resolve_dates($today, $next_date, 0);
        reject_if_on_or_after_trip($trip_date, $requote_follow_date);

        $requote_caller_fullname = resolve_quote_owner_fullname($conn, $quoteid);

        if ($requote_caller_fullname !== '') {
            $requote_target_date     = substr($requote_follow_date, 0, 10);
            $requote_scheduled_count = count_scheduled_for_caller_date($conn, $requote_caller_fullname, $requote_target_date, null, $stage_pool);
            // $scheduled_cap <= 0 is the "no cap" sentinel (Leads today). Requote can never
            // run against a Lead, but the check stays cap-aware rather than assume that.
            if ($scheduled_cap > 0 && $requote_scheduled_count >= $scheduled_cap) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'This date already has ' . $scheduled_cap . ' scheduled calls for this agent. Please choose another date.',
                ]);
                exit;
            }
        }
    }

    // Which stages each outcome may act on. Keyed by outcome, not by current stage: 'rejected'
    // and 'lead_rejected' both target 'Rejected', so a stage-keyed whitelist cannot tell them
    // apart and would let a Lead be closed with the Follow-up reason list, writing the reason
    // to the wrong table. A Lead is absent from 'accepted' and 'requote' on purpose: it was
    // never quoted, so its only exits are promotion to a real quote or being dropped.
    $allowed_from_stage = [
        'rejected'       => ['Created', 'Requote'],
        'accepted'       => ['Created', 'Requote'],
        'requote'        => ['Created'],
        'lead_converted' => [LEAD_STAGE_NAME],
        'lead_rejected'  => [LEAD_STAGE_NAME],
    ];

    $outcome_to_stage = [
        'rejected'       => 'Rejected',
        'accepted'       => 'Accepted',
        'requote'        => 'Requote',
        'lead_converted' => 'Created',
        'lead_rejected'  => 'Rejected',
    ];
    $new_stage = $outcome_to_stage[$outcome];

    if (!in_array($current_stage, $allowed_from_stage[$outcome], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Transition not allowed']);
        exit;
    }

    $reason_category_map = [
        'lost_to_response_time' => 'company',
        'too_expensive'         => 'competition',
        'went_with_competitor'  => 'competition',
        'trip_cancelled'        => 'client',
        'visa_issues'           => 'client',
        'no_response_ghosted'   => 'client',
        'destination_change'    => 'client',
        'not_ready_to_travel'   => 'client',
        'other'                 => 'other',
    ];

    // A Lead was never quoted, so the Follow-up reasons above do not transfer: price-based ones
    // cannot apply, and many Lead closures are disqualifications rather than losses. The extra
    // 'not_opportunity' category keeps those out of the loss count. Every category but
    // 'competition' is kept short so the 'Other' free text shows what is missing.
    $lead_reason_category_map = [
        'lost_to_response_time'   => 'company',
        // No "they answered faster" here on purpose: that is our own response time, and it
        // stays under 'company' so the avoidable count has a single home. booked_direct drops
        // the prefix because a supplier is not a competing operator.
        'competitor_cheaper'      => 'competition',
        'competitor_itinerary'    => 'competition',
        'competitor_availability' => 'competition',
        'booked_direct'           => 'competition',
        'competitor_unknown'      => 'competition',
        'no_response'             => 'client',
        'not_ready_to_travel'     => 'client',
        'trip_cancelled'          => 'client',
        'enquiry_only'            => 'not_opportunity',
        'out_of_scope'            => 'not_opportunity',
        'other'                   => 'other',
    ];

    // Resolved once: the validation below and the feedback write after the stage change
    // both need these.
    $is_rejection      = ($outcome === 'rejected' || $outcome === 'lead_rejected');
    $active_reason_map = ($outcome === 'lead_rejected') ? $lead_reason_category_map : $reason_category_map;
    $feedback_table    = ($outcome === 'lead_rejected') ? 'tdu_lead_closure_feedback' : 'tdu_quote_closure_feedback';

    if ($is_rejection) {
        if (!isset($active_reason_map[$feedback_reason])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'A rejection reason is required']);
            exit;
        }
        if ($feedback_reason === 'other' && $feedback_other === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Please provide details for "Other"']);
            exit;
        }
    }

    // No pre-check for lead_converted/lead_rejected: the pax and payment gates below are
    // Accepted-specific and a Lead was never quoted. The far side is not ungated: quote.php's
    // update handler only lets a quote leave 'Lead' for exactly 'Created', and only for an
    // admin or sales user. That guard, not this file, is what enforces it.
    if ($outcome === 'accepted') {
        // Extend the include path so dictionaries.php can resolve its own relative requires.
        set_include_path(get_include_path() . PATH_SEPARATOR . $_SERVER['DOCUMENT_ROOT']);
        require_once $_SERVER['DOCUMENT_ROOT'] . '/dictionaries.php';

        if (shouldDisableAccepted($conn, $quoteid)) {
            echo json_encode(['success' => false, 'message' => 'Cannot mark as Accepted: passenger data or room allocation is incomplete.']);
            exit;
        }
        if (isPaymentReceivedToAccepted($conn, $quoteid)) {
            echo json_encode(['success' => false, 'message' => 'Cannot mark as Accepted: payment is insufficient for a trip within 15 days.']);
            exit;
        }
    }

    // Delegate the actual stage change to details.php's 'update_info' handler (via
    // quote.php below) instead of reimplementing it — that handler already owns the
    // vtiger_quote_stage_track log, the vendor mailbox queue insert on Created->Accepted,
    // ops/QA auto-assignment, and createTaskWithMessage(). It does NOT itself enforce
    // shouldDisableAccepted() / isPaymentReceivedToAccepted() server-side, so we keep
    // running those ourselves above.
    //
    // It also does ON DUPLICATE KEY UPDATE on assigned_to_region / assigned_to_sales_agent /
    // assigned_to_external_sales_agent / priority alongside the stage, so we pass their
    // current values through unchanged below or it will blank them.
    $info_row = $conn->query("
        SELECT assigned_to_region, assigned_to_sales_agent, assigned_to_external_sales_agent, priority
        FROM vtiger_quotes_info
        WHERE quoteid = $quoteid
        LIMIT 1
    ")->fetch_assoc() ?: [];

    // quote.php is the router (public_html root, same pattern as our own queue.php ->
    // queues/*.php) — it includes details.php internally based on ?opt=. details.php
    // itself is not directly web-reachable (confirmed: direct request 404s).
    $scheme       = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $internal_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/quote.php'
        . '?opt=summary&sales=true&quoteid=' . $quoteid . '&quotetype=group';

    $post_fields = [
        'update_info'                      => '1',
        'quoteid'                          => $quoteid,
        'quotetype'                        => 'group',
        'assigned_to_region'               => $info_row['assigned_to_region'] ?? '',
        'assigned_to_sales_agent'          => $info_row['assigned_to_sales_agent'] ?? '',
        'assigned_to_external_sales_agent' => $info_row['assigned_to_external_sales_agent'] ?? '',
        'priority'                         => $info_row['priority'] ?? '',
        'quote_stage'                      => $new_stage,
    ];

    // Release our session file lock before calling quote.php with the same session
    // cookie (below) — otherwise its session_start() blocks waiting for us to finish,
    // while we're waiting on its response: a self-deadlock. Safe since we've already
    // read everything we need from $_SESSION.
    session_write_close();

    // Forward the caller's session cookie so quote.php authenticates as them — no
    // separate login needed. CURLOPT_RESOLVE connects straight to INTERNAL_APP_HOST
    // (loopback by default — see config.php) instead of the public hostname, since a
    // same-server request round-tripping through the public domain (CDN/proxy) can
    // hang; SNI/Host/cert validation still use the real hostname, so this doesn't
    // weaken TLS verification.
    $host = $_SERVER['HTTP_HOST'];
    $port = $scheme === 'https' ? 443 : 80;
    $ch = curl_init($internal_url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post_fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_RESOLVE        => ["$host:$port:" . INTERNAL_APP_HOST],
        CURLOPT_COOKIE         => session_name() . '=' . session_id(),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $call_started   = microtime(true);
    $response_body  = curl_exec($ch);
    $call_duration  = round(microtime(true) - $call_started, 2);
    $curl_errno     = curl_errno($ch);
    $curl_err_msg   = curl_error($ch);
    $http_code      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($curl_errno) {
        error_log("[TDU Queue] ajax_log_outcome: internal call to quote.php failed after {$call_duration}s: $curl_err_msg");
    }
    // No curl_close() — PHP 8's CurlHandle closes automatically when $ch goes out of scope.

    // details.php renders a full HTML page, not JSON, and we don't trust it either
    // way — re-read the stage ourselves to confirm the write actually landed.
    $confirm_row = $conn->query("
        SELECT quotestage FROM vtiger_quotes WHERE quoteid = $quoteid AND deleted = 0 LIMIT 1
    ")->fetch_assoc();

    if (!$confirm_row || $confirm_row['quotestage'] !== $new_stage) {
        error_log(sprintf(
            '[TDU Queue] ajax_log_outcome: stage change via quote.php did not take effect for quoteid %d '
                . '(after %ss, curl_errno=%d "%s", http_code=%d, confirmed_stage=%s, expected_stage=%s)',
            $quoteid,
            $call_duration,
            $curl_errno,
            $curl_err_msg,
            $http_code,
            $confirm_row['quotestage'] ?? 'null',
            $new_stage
        ));
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'An error occurred while saving. Please try again.']);
        exit;
    }

    // Our own extension data — details.php has no concept of this, so it stays a
    // separate write on our own connection, after the stage change is confirmed.
    if ($is_rejection) {
        $category_val = $conn->real_escape_string($active_reason_map[$feedback_reason]);
        $reason_esc   = $conn->real_escape_string($feedback_reason);
        $chan_esc     = $conn->real_escape_string($channel);
        $user_esc     = $conn->real_escape_string($created_by);
        $other_sql    = ($feedback_other !== '')
            ? "'" . $conn->real_escape_string($feedback_other) . "'"
            : 'NULL';

        $ok2 = $conn->query("
            INSERT INTO $feedback_table
                (quoteid, category, reason, other_reason, channel, created_by, created_at)
            VALUES ($quoteid, '$category_val', '$reason_esc', $other_sql, '$chan_esc', '$user_esc', NOW())
        ");
        if (!$ok2) {
            error_log('[TDU Queue] ajax_log_outcome: closure feedback insert failed: ' . $conn->error);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Stage was changed, but saving the rejection reason failed. Please contact support.']);
            exit;
        }
    }

    if (in_array($outcome, $terminal_outcomes, true)) {
        echo json_encode(['success' => true, 'stage' => $new_stage, 'terminal' => true]);
        exit;
    }
    // requote falls through to the call-outcome write below (two-row pattern, like next_call).
}

// -------------------------------------------------------------------------
// CALL OUTCOME BRANCH — next_call / no_answer_email / interested / inbound_call
// -------------------------------------------------------------------------

if (!in_array($outcome, array_merge($call_outcomes, $staged_call_outcomes), true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

// A call only makes sense while the quote is still workable: a Lead being qualified, or a
// Created/Requote quote being followed up. The modal hides the button past that; this is the
// same rule server-side, so a stale page or a direct POST cannot log against a closed quote
// and leave behind a schedule_follow_up row no queue ever reads or closes.
if (in_array($outcome, $call_outcomes, true) && !in_array($current_stage, $workable_stages, true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'This quote is no longer in an active stage (' . $current_stage . '). '
                   . 'Refresh the queue to see its current state.',
    ]);
    exit;
}

// inbound_call is the only call outcome that may skip a date: the client rang in with
// nothing to promise yet.
if (in_array($outcome, ['next_call', 'requote'], true) && (!$next_date || !strtotime($next_date))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Call back date is required']);
    exit;
}

$contact_row = $conn->query("
    SELECT
        vcd.name AS contactname,
        COALESCE(vqinfo.office_phone, '') AS office_phone,
        COALESCE(vqinfo.mobile_phone, vcd.mobile, '') AS mobile,
        COALESCE(vqinfo.email,        vcd.email,  '') AS email
    FROM vtiger_quotes vq
    LEFT JOIN vtiger_quotes_info vqinfo
        ON vqinfo.quoteid = vq.quoteid
    LEFT JOIN tdu_organisation va
        ON vq.accountid = va.organizationid
    LEFT JOIN tdu_contacts vcd
        ON vq.contactid = vcd.auto_id
        AND va.organizationid = vcd.organizationid
    WHERE
        vq.quoteid = $quoteid
        AND vq.deleted = 0
    LIMIT 1
")->fetch_assoc();

if (!$contact_row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Quote not found']);
    exit;
}

$contact_name = $conn->real_escape_string($contact_row['contactname']  ?? '');
$office_phone = $conn->real_escape_string($contact_row['office_phone'] ?? '');
$mobile       = $conn->real_escape_string($contact_row['mobile']       ?? '');
$email        = $conn->real_escape_string($contact_row['email']        ?? '');

// snooze_until = return_date − 1 day so the quote reappears exactly on the return date.
// next_follow_up_date stores the full datetime so details.php Follow Up tab shows it correctly.
$snooze_until = null;
$follow_date  = null;

// Normalise datetime-local value (YYYY-MM-DDTHH:MM) to MySQL datetime (YYYY-MM-DD HH:MM:00).
// Extract only the date part for snooze_until comparisons.
function parse_next_date(string $raw): array {
    $raw = trim($raw);
    if (!$raw) return [null, null];
    // datetime-local sends YYYY-MM-DDTHH:MM or YYYY-MM-DDTHH:MM:SS
    $normalised = str_replace('T', ' ', $raw);
    if (strlen($normalised) === 16) $normalised .= ':00'; // add seconds if absent
    $date_part  = substr($normalised, 0, 10);
    if (!strtotime($normalised)) return [null, null];
    return [$normalised, $date_part];
}

// Returns [$follow_date (datetime string), $snooze_until (date string)].
// Uses $next_date if supplied; falls back to today + $cooldown_days (locked value).
// snooze_until = return_date - 1 day so the quote reappears on the return date.
function resolve_dates(string $today, string $next_date, int $cooldown_days): array {
    [$follow_date, $date_part] = parse_next_date($next_date);
    if (!$follow_date) {
        $date_part   = date('Y-m-d', strtotime('+' . $cooldown_days . ' days', strtotime($today)));
        $follow_date = $date_part . ' 09:00:00';
    }
    $snooze_until = date('Y-m-d', strtotime('-1 day', strtotime($date_part)));
    return [$follow_date, $snooze_until];
}

// A dateless call must not wipe out a live promise: the queue hides a quote off the LATEST
// call's snooze_until, so writing NULL would un-hide a quote due next week. It inherits the
// open reminder's date instead.
//
// find_open_pending_row() is the single definition of "the quote's live reminder" (highest
// auto_id, still open), shared by snooze inheritance, the edit branch's update target, and
// the cap exclusion. They used to differ, invisibly, until a quote had more than one open
// reminder (24 of 426 in the live cohort).
function find_open_pending_row(mysqli $conn, int $quoteid): ?array {
    return $conn->query("
        SELECT auto_id, next_follow_up_date
        FROM vtiger_quotes_followup
        WHERE quoteid = $quoteid
          AND followup_type = 'schedule_follow_up'
          AND (followup IS NULL OR followup != 'checked')
        ORDER BY auto_id DESC
        LIMIT 1
    ")->fetch_assoc() ?: null;
}

function inherit_snooze_from_pending(mysqli $conn, int $quoteid): ?string {
    $row      = find_open_pending_row($conn, $quoteid);
    $promised = $row['next_follow_up_date'] ?? null;
    // Legacy rows can carry NULL or the zero date; strtotime('') would resolve to 1969.
    if (!$promised || substr($promised, 0, 10) === '0000-00-00') return null;
    return date('Y-m-d', strtotime('-1 day', strtotime(substr($promised, 0, 10))));
}

// Shared by the requote branch (validated before the irreversible stage-change
// delegation) and the call-outcome branch below — same rule, same message, two
// call sites. No-ops if either date is unavailable (defensive only: the live
// Follow-up cohort always has a future cf_1162).
function reject_if_on_or_after_trip(?string $trip_date, ?string $candidate_date): void {
    if (!$trip_date || !$candidate_date) return;
    if (substr($candidate_date, 0, 10) >= substr($trip_date, 0, 10)) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Call-back date must be before the trip date (' . date('d M Y', strtotime($trip_date)) . ').',
        ]);
        exit;
    }
}

switch ($outcome) {
    case 'next_call':
        [$follow_date, $snooze_until] = resolve_dates($today, $next_date, 1);
        break;
    case 'no_answer_email':
        [$follow_date, $snooze_until] = resolve_dates($today, $next_date, 1); // locked: 1 day
        break;
    case 'interested':
        [$follow_date, $snooze_until] = resolve_dates($today, $next_date, 2); // locked: 2 days
        break;
    case 'inbound_call':
        if (!$next_date || !strtotime($next_date)) {
            // No date promised: write no reminder of our own, and keep the quote hidden
            // for as long as an existing reminder still says so.
            $follow_date  = null;
            $snooze_until = inherit_snooze_from_pending($conn, $quoteid);
        } else {
            [$follow_date, $snooze_until] = resolve_dates($today, $next_date, 0);
        }
        break;
    case 'requote':
        // Reuse the date already validated + cap-checked above — don't recompute.
        $follow_date  = $requote_follow_date;
        $snooze_until = $requote_snooze_until;
        break;
}

$notes_esc   = $conn->real_escape_string($notes_raw);
$outcome_esc = $conn->real_escape_string($outcome);
$channel_esc = $conn->real_escape_string($channel);
$user_esc    = $conn->real_escape_string($created_by);
$follow_sql  = $follow_date  ? "'" . $conn->real_escape_string($follow_date)  . "'" : 'NULL';
$snooze_sql  = $snooze_until ? "'" . $conn->real_escape_string($snooze_until) . "'" : 'NULL';

// Trip-date guard and daily schedule cap are both skipped for requote here — it
// already validated both above, before its (irreversible) stage delegation.
if ($outcome !== 'requote') {
    reject_if_on_or_after_trip($trip_date, $follow_date);
}

// Daily schedule cap — per-agent, per-date. Read-only check before any write.
// Skipped for requote: already validated above, before the (irreversible) stage change.
$caller_fullname = resolve_quote_owner_fullname($conn, $quoteid);

// $follow_date === null is a dateless inbound_call: nothing is being scheduled, so there is
// no date to count against the cap and substr() below would be handed a null.
if ($outcome !== 'requote' && $caller_fullname !== '' && $follow_date !== null) {
    // If editing an existing call_info row, find its paired schedule_follow_up row so
    // the count below excludes it (it's being updated in place, not inserted fresh —
    // see the identical lookup inside the transaction further down).
    $exclude_followup_id = null;
    if ($followup_id) {
        $sched_row = find_open_pending_row($conn, $quoteid);
        if ($sched_row) {
            $exclude_followup_id = (int) $sched_row['auto_id'];
        }
    }

    $target_date     = substr($follow_date, 0, 10);
    $scheduled_count = count_scheduled_for_caller_date($conn, $caller_fullname, $target_date, $exclude_followup_id, $stage_pool);
    // $scheduled_cap <= 0 is the "no cap" sentinel (Leads today): never blocked.
    if ($scheduled_cap > 0 && $scheduled_count >= $scheduled_cap) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'This date already has ' . $scheduled_cap . ' scheduled calls for this agent. Please choose another date.',
        ]);
        exit;
    }
}

$conn->begin_transaction();
try {
    if ($followup_id) {
        $ok = $conn->query("
            UPDATE vtiger_quotes_followup
            SET description         = '$notes_esc',
                outcome             = '$outcome_esc',
                calltime            = NOW(),
                next_follow_up_date = NOW()
            WHERE auto_id = $followup_id AND quoteid = $quoteid
        ");
        if (!$ok || $conn->affected_rows === 0) {
            throw new Exception('followup update failed: ' . $conn->error);
        }

        // Deliberately not restricted to rows written after this call: once a quote can carry
        // several calls a day, the open reminder usually belongs to an earlier one, so an
        // auto_id > $followup_id lookup would find nothing and leave that reminder open forever.
        // Skipped when the edit leaves no date, since writing NULL over a live reminder is the
        // same corruption the insert path already guards against.
        //
        // $schedule_link_id stays NULL here only when this edit didn't touch a schedule row,
        // not when the call owns none: the upsert below keeps any existing claim rather than
        // clearing it, since dropping a date doesn't cancel the reminder.
        $schedule_link_id = null;
        if ($follow_sql !== 'NULL') {
            $sched_row = find_open_pending_row($conn, $quoteid);

            if ($sched_row) {
                $conn->query("
                    UPDATE vtiger_quotes_followup
                    SET next_follow_up_date = $follow_sql
                    WHERE auto_id = " . (int)$sched_row['auto_id']
                );
                $schedule_link_id = (int) $sched_row['auto_id'];
            } else {
                $conn->query("
                    INSERT INTO vtiger_quotes_followup
                        (quoteid, followup_type, followup, calltime, next_follow_up_date,
                         quotetype, travel_agent_contact_name, office_phone, mobile_phone, email, created_by)
                    VALUES ($quoteid, 'schedule_follow_up', NULL, NULL, $follow_sql,
                            'group', '$contact_name', '$office_phone', '$mobile', '$email', '$user_esc')
                ");
                $schedule_link_id = $conn->insert_id;
            }
        }
        $schedule_link_sql = $schedule_link_id ? (int) $schedule_link_id : 'NULL';

        // Upsert ext row: update if it exists, create if missing (legacy calls have none).
        $ok2 = $conn->query("
            INSERT INTO tdu_quotes_followup_ext (followup_id, outcome, channel, snooze_until, schedule_followup_id)
            VALUES ($followup_id, '$outcome_esc', '$channel_esc', $snooze_sql, $schedule_link_sql)
            ON DUPLICATE KEY UPDATE
                outcome               = VALUES(outcome),
                channel               = VALUES(channel),
                snooze_until          = VALUES(snooze_until),
                schedule_followup_id  = COALESCE(VALUES(schedule_followup_id), schedule_followup_id)
        ");
        if (!$ok2) {
            throw new Exception('ext upsert failed: ' . $conn->error);
        }
    } else {
        // Mark the previous pending schedule_follow_up as completed: the caller honoured the
        // commitment regardless of whether the client answered. A call with no new date leaves
        // it alone instead, since closing it would cancel a callback that's still owed.
        if ($follow_date !== null
            && in_array($outcome, ['next_call', 'no_answer_email', 'interested', 'inbound_call', 'requote'], true)) {
            $conn->query("
                UPDATE vtiger_quotes_followup
                SET followup = 'checked', calltime = NOW(), created_by = '$user_esc'
                WHERE quoteid = $quoteid
                  AND followup_type = 'schedule_follow_up'
                  AND (followup IS NULL OR followup != 'checked')
                ORDER BY auto_id DESC
                LIMIT 1
            ");
        }

        $ok = $conn->query("
            INSERT INTO vtiger_quotes_followup
                (quoteid, followup_type, followup, calltime, next_follow_up_date,
                 description, outcome, quotetype,
                 travel_agent_contact_name, office_phone, mobile_phone, email, created_by)
            VALUES (
                $quoteid,
                'call_info',
                'checked',
                NOW(),
                NOW(),
                '$notes_esc',
                '$outcome_esc',
                'group',
                '$contact_name', '$office_phone', '$mobile', '$email',
                '$user_esc'
            )
        ");
        if (!$ok) {
            throw new Exception('followup insert failed: ' . $conn->error);
        }
        $followup_id = $conn->insert_id;

        // Row 2: the pending reminder, only when a return date exists. A dateless call
        // (inherited snooze or nothing to inherit) creates none, so $schedule_link_id stays
        // NULL below.
        $schedule_link_id = null;
        if ($follow_sql !== 'NULL') {
            $ok_sched = $conn->query("
                INSERT INTO vtiger_quotes_followup
                    (quoteid, followup_type, followup, calltime, next_follow_up_date,
                     quotetype, travel_agent_contact_name, office_phone, mobile_phone, email, created_by)
                VALUES (
                    $quoteid,
                    'schedule_follow_up',
                    NULL,
                    NULL,
                    $follow_sql,
                    'group',
                    '$contact_name', '$office_phone', '$mobile', '$email',
                    '$user_esc'
                )
            ");
            if (!$ok_sched) {
                throw new Exception('schedule insert failed: ' . $conn->error);
            }
            $schedule_link_id = $conn->insert_id;
        }
        $schedule_link_sql = $schedule_link_id ? (int) $schedule_link_id : 'NULL';

        // Ext record links to the call_info row (Row 1). schedule_followup_id is set only when
        // this request created the schedule row above, never an inherited promise's id: that is
        // what decides which call the row's Edit button corrects once a day holds more than one.
        $ok2 = $conn->query("
            INSERT INTO tdu_quotes_followup_ext (followup_id, outcome, channel, snooze_until, schedule_followup_id)
            VALUES ($followup_id, '$outcome_esc', '$channel_esc', $snooze_sql, $schedule_link_sql)
        ");
        if (!$ok2) {
            throw new Exception('ext insert failed: ' . $conn->error);
        }
    }

    if ($outcome === 'interested') {
        $conn->query("UPDATE vtiger_quotes_info SET priority = 'high' WHERE quoteid = $quoteid");
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log('[TDU Queue] ajax_log_outcome transaction failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred while saving. Please try again.']);
    exit;
}

// has_commitment: whether this call left a call-back date, decided server-side since a
// dateless call can still inherit an existing promise. schedule_followup_id: whether this call
// now owns a schedule row of its own, which is what lets the page re-point the row's Edit
// target live.
echo json_encode([
    'success'              => true,
    'followup_id'          => $followup_id,
    'notes'                => $notes_raw,
    'has_commitment'       => ($follow_date !== null),
    'schedule_followup_id' => $schedule_link_id ?: null,
]);
exit;
