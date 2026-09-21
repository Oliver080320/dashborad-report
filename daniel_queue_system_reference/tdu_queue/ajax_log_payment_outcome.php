<?php
// Log a payment-deadline outcome: payment_no_answer (no answer / touched base — auto
// snoozes to tomorrow, no date input) or payment_next_call (caller picks a specific date
// to try again — the only outcome that takes a callback date). Deliberately simpler than
// ajax_log_outcome.php: no stage-change delegation (exit from this queue is automatic —
// stage moves past the scope in payment_deadline_stage_list(), or to a rejected variant),
// no schedule-cap check.
//
// Outcome codes are deliberately namespaced with a payment_ prefix (payment_no_answer,
// payment_next_call), never the bare 'next_call' the Follow-up queue writes — both write
// to the same vtiger_quotes_followup/tdu_quotes_followup_ext tables, and a bare
// 'next_call' would let build_payment_deadline_queue() misread a pre-Accepted Follow-up
// callback promise (chasing the sale, not the payment) as an already-done payment call.
//
// Our own reappearance logic reads tdu_quotes_followup_ext.snooze_until directly
// (build_payment_deadline_queue()), never next_follow_up_date — so the second row below
// is NOT what drives our own queue. It exists purely so someone looking at this quote
// from the legacy dashboard (details.php's Follow Up tab, which only knows about
// next_follow_up_date) can see that a callback is already planned. Any previously-
// pending schedule_follow_up row is marked checked first so the legacy view doesn't
// accumulate stale reminders.
//
// Edit support (followup_id): mirrors ajax_log_outcome.php's edit-in-place pattern —
// when a row is already "pending" (snoozed into the future), the Log button reopens
// this same endpoint with the original followup_id so correcting the date/outcome
// UPDATEs the existing call_info row instead of logging a second call.
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

$quoteid       = (int)  ($_POST['quoteid']       ?? 0);
$followup_id   = (int)  ($_POST['followup_id']   ?? 0);
$outcome       = trim($_POST['outcome']          ?? '');
$channel       = trim($_POST['channel']          ?? 'phone');
$next_call_raw = trim($_POST['next_call_date']   ?? '');
$notes_raw     = trim($_POST['notes']            ?? '');
$session_user  = tdu_canonical_user($_SESSION['user_name'] ?? '');

// Only a post-sale holder or an admin may log an outcome here. Supervisors can see this
// queue but not act on it, matching payment_deadline.php's own $is_readonly.
if (!tdu_is_post_sale($session_user) && (($_SESSION['title'] ?? '') !== 'admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorised to log this outcome.']);
    exit;
}

$valid_outcomes = ['payment_no_answer', 'payment_next_call'];
$valid_channels = ['phone', 'whatsapp', 'email', 'other'];

if (!$quoteid || !in_array($outcome, $valid_outcomes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}
if (!in_array($channel, $valid_channels, true)) {
    $channel = 'phone';
}
// Required server-side, not just via the textarea's `required` attribute — the client
// check is trivially bypassable (stale cached page, direct POST) and this note is what
// makes the History panel and details.php's Follow Up tab meaningful per call.
if ($notes_raw === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A note is required']);
    exit;
}

// Trip date needed either way: to validate a caller-chosen next_call date, and to
// decide whether payment_no_answer's automatic tomorrow-snooze even makes sense.
$trip_row  = $conn->query("SELECT cf_1162 AS trip_date FROM vtiger_quotescf WHERE quoteid = $quoteid LIMIT 1")->fetch_assoc();
$trip_date = $trip_row['trip_date'] ?? null;

if ($outcome === 'payment_next_call') {
    // Flatpickr (payment-deadline.js) posts a combined 'Y-m-d\TH:i' value — normalise
    // the 'T' to a space for strtotime()/MySQL, same as ajax_log_outcome.php's
    // parse_next_date(). Date-only checks below use $next_call_raw's own leading 10
    // chars directly, which works identically whichever separator is used.
    $next_call_normalised = str_replace('T', ' ', $next_call_raw);
    if (!$next_call_raw || !strtotime($next_call_normalised)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A callback date is required']);
        exit;
    }
    // Must be strictly in the future — today or a past date isn't a real "call back
    // later" commitment, and would sink the row into 'pending' while never actually
    // resurfacing it (the queue only checks the date against CURDATE() on the next load).
    if (substr($next_call_raw, 0, 10) <= date('Y-m-d')) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Callback date must be after today.']);
        exit;
    }
    // Same "must be before the trip" principle ajax_log_outcome.php applies to
    // callback dates via reject_if_on_or_after_trip() — a callback for on/after the
    // trip date is nonsensical (nothing left to chase by then).
    if ($trip_date && substr($next_call_raw, 0, 10) >= substr($trip_date, 0, 10)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Callback date must be before the trip date.']);
        exit;
    }
    // The team doesn't work Sundays (same assumption monitoring.php's lastWorkedDayKey()
    // makes). A callback set for a Sunday would sit there untouched until Monday, which
    // is just a silent one-day slip dressed up as a scheduled call.
    if ((int) date('w', strtotime(substr($next_call_raw, 0, 10))) === 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Callback date can\'t be a Sunday. Please pick a weekday.']);
        exit;
    }
    $resolved_date = substr($next_call_raw, 0, 10);
    $snooze_sql    = "'" . $conn->real_escape_string($resolved_date) . "'";

    // Time-of-day from the same picker — not read by any business logic
    // (snooze_until, used for our own pending/sort, stays date-only above;
    // tdu_quotes_followup_ext.snooze_until is a DATE column with no room for it).
    // Threaded only into the legacy echo's next_follow_up_date below, so details.php's
    // Follow Up tab shows the time the caller actually chose.
    $resolved_datetime = $next_call_normalised;
    if (strlen($resolved_datetime) === 16) $resolved_datetime .= ':00'; // add seconds if absent
    $follow_sql = "'" . $conn->real_escape_string($resolved_datetime) . "'";
} else {
    // payment_no_answer: snooze to tomorrow automatically, no caller input. If the trip
    // is tomorrow (or sooner), skip snoozing instead — there is no useful "come back
    // later" when there is barely any window left before travel, so the row stays at
    // full priority rather than being demoted for the one day that's left.
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    // The team doesn't work Sundays, so bump straight to Monday instead of silently
    // parking the callback on a day nobody's in.
    if ((int) date('w', strtotime($tomorrow)) === 0) {
        $tomorrow = date('Y-m-d', strtotime($tomorrow . ' +1 day'));
    }
    $skip_snooze   = ($trip_date && $tomorrow >= substr($trip_date, 0, 10));
    $resolved_date = $skip_snooze ? null : $tomorrow;
    $snooze_sql    = $resolved_date ? "'" . $conn->real_escape_string($resolved_date) . "'" : 'NULL';
    // Carry the call's own time of day into the legacy echo. A date-only value stored in a
    // DATETIME column reads as 00:00 in details.php's Follow Up tab, which looks unset
    // rather than promised. payment_next_call already writes a time near the call (its
    // picker defaults to "now"), so this keeps the two payment outcomes consistent.
    //
    // Deliberately NOT the Follow-up queue's hardcoded 09:00: there that is a stand-in for
    // a date field the caller left empty, whereas this outcome has no field at all, so
    // there is no user choice to stand in for. snooze_until stays date-only either way,
    // it is a DATE column and every read of the schedule row wraps it in DATE().
    //
    // The time comes from CURTIME(), not PHP's date(), so it cannot drift from the
    // calltime = NOW() written on the call itself: both then read the same clock, whatever
    // that clock is set to. The two are supposed to agree on this server, but "supposed to"
    // is not worth depending on when the fix is free.
    $follow_sql = $resolved_date
        ? "TIMESTAMP('" . $conn->real_escape_string($resolved_date) . "', CURTIME())"
        : 'NULL';
}

// Resolve the caller's full name for created_by, matching details.php's "First Last"
// format — same lookup ajax_log_outcome.php uses.
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

if (!$contact_row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Quote not found']);
    exit;
}

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
    if ($followup_id) {
        $ok = $conn->query("
            UPDATE vtiger_quotes_followup
            SET description = '$notes_esc',
                outcome      = '$outcome_esc',
                calltime     = NOW()
            WHERE auto_id = $followup_id AND quoteid = $quoteid
        ");
        if (!$ok || $conn->affected_rows === 0) {
            throw new Exception('followup update failed: ' . $conn->error);
        }

        // Legacy-dashboard echo (see top-of-file comment) — edit-in-place case. Correct
        // the existing schedule_follow_up row written alongside the original call_info,
        // mirroring ajax_log_outcome.php's edit branch: editing must never close out and
        // replace the pending reminder, only a genuinely new call does that (else below).
        // Filtered to still-open rows (followup IS NULL) and the most recent one, so a
        // stale/already-checked row left over from elsewhere can't be mistaken for the
        // real pending reminder and get updated instead.
        $sched_row = $conn->query("
            SELECT auto_id FROM vtiger_quotes_followup
            WHERE quoteid = $quoteid
              AND auto_id > $followup_id
              AND followup_type = 'schedule_follow_up'
              AND (followup IS NULL OR followup = '')
            ORDER BY auto_id DESC
            LIMIT 1
        ")->fetch_assoc();

        if ($resolved_date !== null) {
            if ($sched_row) {
                $conn->query("
                    UPDATE vtiger_quotes_followup
                    SET next_follow_up_date = $follow_sql
                    WHERE auto_id = " . (int) $sched_row['auto_id']
                );
            } else {
                $conn->query("
                    INSERT INTO vtiger_quotes_followup
                        (quoteid, followup_type, followup, next_follow_up_date,
                         quotetype, travel_agent_contact_name, office_phone, mobile_phone, email, created_by)
                    VALUES ($quoteid, 'schedule_follow_up', NULL, $follow_sql,
                            'group', '$contact_name', '$office_phone', '$mobile', '$email', '$user_esc')
                ");
            }
        } elseif ($sched_row) {
            // Edited to an outcome with no future date (payment_no_answer, trip too close
            // to snooze) — nothing left to keep pending, close the existing reminder out.
            $conn->query("
                UPDATE vtiger_quotes_followup
                SET followup = 'checked', calltime = NOW()
                WHERE auto_id = " . (int) $sched_row['auto_id']
            );
        }

        // Upsert ext row: update if it exists, create if missing (defensive — every row
        // this endpoint writes always has one, but mirrors ajax_log_outcome.php's pattern).
        $ok2 = $conn->query("
            INSERT INTO tdu_quotes_followup_ext (followup_id, outcome, channel, snooze_until)
            VALUES ($followup_id, '$outcome_esc', '$channel_esc', $snooze_sql)
            ON DUPLICATE KEY UPDATE
                outcome      = VALUES(outcome),
                channel      = VALUES(channel),
                snooze_until = VALUES(snooze_until)
        ");
        if (!$ok2) {
            throw new Exception('ext upsert failed: ' . $conn->error);
        }
    } else {
        // Legacy-dashboard echo (see top-of-file comment) — new-call case. Close out any
        // previously-pending reminder, WITH calltime, so details.php's Follow Up tab
        // doesn't accumulate stale rows or show an unset (epoch) check time.
        $conn->query("
            UPDATE vtiger_quotes_followup
            SET followup = 'checked', calltime = NOW(), created_by = '$user_esc'
            WHERE quoteid = $quoteid
              AND followup_type = 'schedule_follow_up'
              AND (followup IS NULL OR followup = '')
            ORDER BY auto_id DESC
            LIMIT 1
        ");

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

        if ($resolved_date !== null) {
            $ok4 = $conn->query("
                INSERT INTO vtiger_quotes_followup
                    (quoteid, followup_type, followup, next_follow_up_date,
                     quotetype, travel_agent_contact_name, office_phone, mobile_phone, email, created_by)
                VALUES (
                    $quoteid, 'schedule_follow_up', NULL, $follow_sql,
                    'group', '$contact_name', '$office_phone', '$mobile', '$email', '$user_esc'
                )
            ");
            if (!$ok4) {
                throw new Exception('schedule echo insert failed: ' . $conn->error);
            }
        }

        $ok2 = $conn->query("
            INSERT INTO tdu_quotes_followup_ext (followup_id, outcome, channel, snooze_until)
            VALUES ($followup_id, '$outcome_esc', '$channel_esc', $snooze_sql)
        ");
        if (!$ok2) {
            throw new Exception('ext insert failed: ' . $conn->error);
        }
    }

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log('[TDU Queue] ajax_log_payment_outcome failed: ' . $e->getMessage() . ' | quoteid=' . $quoteid);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save outcome']);
    exit;
}

echo json_encode(['success' => true, 'followup_id' => $followup_id]);
exit;
