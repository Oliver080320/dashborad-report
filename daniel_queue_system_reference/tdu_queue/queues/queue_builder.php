<?php
// -.. .- -. .. . .-.. / .-- .- ... / .... . .-. .

// Shared queue builder — used by every queue config (India FIT, Groups & MICE).
// Encapsulates SP1/SP2/SP3 classification, sort, org-promotion, grouping, and the
// cascade cap for ONE caller's owned quotes. $followup_slots is the per-caller budget:
// floor(DAILY_CAPACITY * <config follow-up pct>) — 42 for India callers, 35 for G&M.
// Returns terminal-outcome entries from a closure-feedback table for the given date and
// comma-separated quoteid list. Table and outcome are parameters because each queue keeps
// its own: tdu_quote_closure_feedback for Follow-up, tdu_lead_closure_feedback for Leads.
function load_closure_worked_today(
    mysqli $conn,
    string $date,
    string $ids_in,
    string $table   = 'tdu_quote_closure_feedback',
    string $outcome = 'rejected'
): array
{
    $result = [];
    if (empty($ids_in)) return $result;
    $table_esc = $conn->real_escape_string($table);
    $cl_res = $conn->query("
        SELECT quoteid, channel
        FROM $table_esc
        WHERE DATE(created_at) = '$date'
          AND quoteid IN ($ids_in)
    ");
    if ($cl_res) {
        while ($row = $cl_res->fetch_assoc()) {
            $result[(int) $row['quoteid']] = [
                'followup_id' => null,
                'notes'       => '',
                'outcome'     => $outcome,
                'next_date'   => null,
                'channel'     => $row['channel'] ?? '',
            ];
        }
        $cl_res->free();
    }
    return $result;
}

// Stage-only outcomes write nothing to vtiger_quotes_followup and, apart from rejections,
// have no extension table, so vtiger_quote_stage_track is their only record. Its 'stage'
// column is a general changelog, so we match details.php's literal 'Change Stage to <Stage>'
// format. Reads any writer's rows, not just ours, consistent with call_count counting both
// systems. Latest row wins if a quote toggled stage twice today. $stage_outcomes is the
// queue's own map (followup_stage_outcomes() / lead_stage_outcomes()), Follow-up by default.
function load_stage_change_worked_today(
    mysqli $conn,
    string $date,
    string $ids_in,
    ?array $stage_outcomes = null
): array
{
    $result = [];
    $stage_outcomes ??= followup_stage_outcomes();
    if (empty($ids_in) || empty($stage_outcomes)) return $result;

    // Map keys are bare stage names; the log stores them prefixed.
    $prefix   = 'Change Stage to ';
    $stage_in = implode(',', array_map(
        fn($s) => "'" . $conn->real_escape_string($prefix . $s) . "'",
        array_keys($stage_outcomes)
    ));

    $st_res = $conn->query("
        SELECT quoteid, stage, created_at
        FROM vtiger_quote_stage_track
        WHERE DATE(created_at) = '$date'
          AND quoteid IN ($ids_in)
          AND stage IN ($stage_in)
        ORDER BY created_at ASC
    ");
    if ($st_res) {
        while ($row = $st_res->fetch_assoc()) {
            $stage_name = substr($row['stage'], strlen($prefix));
            if (!isset($stage_outcomes[$stage_name])) continue; // defensive: IN list can't return others
            $result[(int) $row['quoteid']] = [
                'followup_id' => null,
                'notes'       => '',
                'outcome'     => $stage_outcomes[$stage_name],
                'next_date'   => null,
                'channel'     => '',
            ];
        }
        $st_res->free();
    }
    return $result;
}

// The daily_runs.bucket values each queue owns. daily_runs is shared by every queue that
// freezes a morning snapshot, so the write guard and every read must be scoped to one
// queue's buckets, or whichever queue a caller opens first blocks the other from writing
// its snapshot all day.
function followup_buckets(): array
{
    return ['sp1', 'sp2', 'sp3', 'sp4', 'carryover'];
}

function lead_buckets(): array
{
    return ['lead_sp1', 'lead_sp2', 'lead_sp3', 'lead_sp4', 'lead_carryover'];
}

// Maps the stage a quote was moved to today back to our outcome code. Terminal outcomes
// write nothing to vtiger_quotes_followup, so this is the only way a reload knows the row
// was worked. Per queue: 'Rejected' is 'rejected' in Follow-up, 'lead_rejected' in Leads.
// Inverse of $outcome_to_stage in ajax_log_outcome.php, and the pair has to stay in step:
// a new terminal outcome missing here saves fine and then renders as untouched.
// Keys are bare stage names; the 'Change Stage to ' prefix is added at the query.
function followup_stage_outcomes(): array
{
    // Deliberately no 'Rejected': Follow-up rejections are detected from
    // tdu_quote_closure_feedback instead, which also carries the channel.
    return ['Accepted' => 'accepted', 'Requote' => 'requote'];
}

function lead_stage_outcomes(): array
{
    return ['Created' => 'lead_converted', 'Rejected' => 'lead_rejected'];
}

// Which quote stages each scheduling pool covers. The per-caller daily cap on scheduled
// future calls is counted separately for Follow-up and Leads; a quote is either at 'Lead'
// or at 'Created'/'Requote', never both, so the two pools cannot overlap.
function schedule_pool_stages(string $pool): array
{
    return $pool === 'lead' ? ['Lead'] : ['Created', 'Requote'];
}

// How many days ahead each pool may schedule a callback.
function schedule_window_days(string $pool): int
{
    return $pool === 'lead' ? LEAD_SCHEDULE_WINDOW_DAYS : SCHEDULE_WINDOW_DAYS;
}

// Last date each pool may schedule a callback on, as 'Y-m-d'.
function schedule_window_end(string $pool, ?string $from = null): string
{
    $from = $from ?: date('Y-m-d');
    return date('Y-m-d', strtotime($from . ' +' . schedule_window_days($pool) . ' days'));
}

function build_caller_queue(array $owned_quotes, string $today, int $followup_slots): array
{
    // --- Classify each quote into SP1, SP2, SP3, or SP4 ---
    $sp1 = [];
    $sp2 = [];
    $sp3 = [];
    $sp4 = [];

    foreach ($owned_quotes as $q) {
        $called       = (int) $q['call_count'] > 0;
        // Reads the live open schedule_follow_up row (next_call_date), not the snooze_until
        // copy cached on the latest call. That copy can go stale: a dateless call inherits an
        // earlier promise's cool-down, and if the promise is later edited to a different date,
        // nothing updates the copy. Reading the promise itself has no such gap.
        $snoozed      = !empty($q['next_call_date']) && $q['next_call_date'] > $today;
        $created_days = (int) floor((strtotime($today) - strtotime($q['created_at'])) / 86400);
        $trip_days    = (int) floor((strtotime($q['trip_start_date']) - strtotime($today)) / 86400);

        // SP1: scheduled call due today or overdue (any age — carry-over snowball preserves
        // pending quotes; slot budget controls how much backlog surfaces per day).
        $due_today = !empty($q['next_call_date']) && $q['next_call_date'] <= $today;

        // Quote returning from "interested": no next_call_date, reappears in SP1 above
        // all other called quotes once its cool-down expires.
        $returning_interested = ($q['last_outcome'] === 'interested');

        if ($snoozed) {
            // skip — in cool-down (covers interested quotes whose cool-down hasn't expired)
        } elseif ($due_today || $returning_interested) {
            $sp1[] = $q;  // scheduled for today, recently missed, or returning from interested
        } elseif (!$called && $created_days <= FOLLOWUP_CREATED_DAYS) {
            $sp2[] = $q;  // first contact — new quote, never called
        } elseif ($trip_days >= 0 && $trip_days <= FOLLOWUP_TRAVEL_DATE_WINDOW_DAYS) {
            $sp3[] = $q;  // urgency — trip approaching, not scheduled today
        } else {
            $sp4[] = $q;  // idle capacity — matches none of SP1-3; fills leftover slots only
        }
    }

    // --- Sort each SP ---

    // SP1: interested first, then due callbacks (most overdue first), then oldest quote
    $is_due = function (array $q) use ($today): bool {
        return !empty($q['next_call_date']) && $q['next_call_date'] <= $today;
    };

    $sp1_sort = function ($a, $b) use ($is_due) {
        $a_int = ($a['last_outcome'] === 'interested') ? 0 : 1;
        $b_int = ($b['last_outcome'] === 'interested') ? 0 : 1;
        if ($a_int !== $b_int) return $a_int - $b_int;

        $a_due = $is_due($a) ? 0 : 1;
        $b_due = $is_due($b) ? 0 : 1;
        if ($a_due !== $b_due) return $a_due - $b_due;

        // Within the due tier, drain the backlog oldest callback first.
        if ($a_due === 0 && $b_due === 0) {
            $cmp = strcmp($a['next_call_date'], $b['next_call_date']);
            if ($cmp !== 0) return $cmp;
        }

        return strcmp($a['created_at'], $b['created_at']);
    };

    // SP2: newest first
    usort($sp2, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

    // SP3: closest trip first
    usort($sp3, fn($a, $b) => strcmp($a['trip_start_date'], $b['trip_start_date']));

    // SP4: oldest quote first — longest-neglected quotes surface first when there's spare time
    usort($sp4, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));

    // --- Promote quotes to highest-priority SP by organisation ---
    $sp1_orgs = array_flip(array_column($sp1, 'organizationid'));
    $sp2_keep = [];
    foreach ($sp2 as $q) {
        if (isset($sp1_orgs[$q['organizationid']])) {
            $sp1[] = $q;
        } else {
            $sp2_keep[] = $q;
        }
    }
    $sp2 = $sp2_keep;

    $sp1_orgs = array_flip(array_column($sp1, 'organizationid'));
    $sp2_orgs = array_flip(array_column($sp2, 'organizationid'));
    $sp3_keep = [];
    foreach ($sp3 as $q) {
        $oid = $q['organizationid'];
        if (isset($sp1_orgs[$oid])) {
            $sp1[] = $q;
        } elseif (isset($sp2_orgs[$oid])) {
            $sp2[] = $q;
        } else {
            $sp3_keep[] = $q;
        }
    }
    $sp3 = $sp3_keep;

    // SP4 promotes into whichever tier its org already occupies, precedence SP1 > SP2 > SP3
    // (same rule SP3 uses above). Must run after the SP2/SP3 promotion passes above (uses
    // their resolved output) and before the usort() calls below (so promoted items land
    // in the correct sort position, not appended at the end).
    $sp1_orgs = array_flip(array_column($sp1, 'organizationid'));
    $sp2_orgs = array_flip(array_column($sp2, 'organizationid'));
    $sp3_orgs = array_flip(array_column($sp3, 'organizationid'));
    $sp4_keep = [];
    foreach ($sp4 as $q) {
        $oid = $q['organizationid'];
        if (isset($sp1_orgs[$oid])) {
            $sp1[] = $q;
        } elseif (isset($sp2_orgs[$oid])) {
            $sp2[] = $q;
        } elseif (isset($sp3_orgs[$oid])) {
            $sp3[] = $q;
        } else {
            $sp4_keep[] = $q;
        }
    }
    $sp4 = $sp4_keep;

    usort($sp1, $sp1_sort);
    usort($sp2, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    // sp3 previously never received post-sort promotions; SP4 promotion can now add to it,
    // so it needs re-sorting too (same comparator as its initial sort above).
    usort($sp3, fn($a, $b) => strcmp($a['trip_start_date'], $b['trip_start_date']));

    // --- Group each SP by organisation ---
    $group_by_org = function (array $quotes): array {
        $orgs = [];
        foreach ($quotes as $q) {
            $orgs[$q['organizationid'] ?? 'unknown'][] = $q;
        }
        return array_values($orgs);
    };

    // --- Apply caps in cascade order: SP1 -> SP2 -> SP3 -> SP4 (org groups never split) ---
    $sp1_all_groups = $group_by_org($sp1);
    $sp1_groups     = [];
    $sp1_slot_count = 0;
    foreach ($sp1_all_groups as $org_quotes) {
        $n = count($org_quotes);
        if ($sp1_slot_count + $n > $followup_slots) continue;
        $sp1_groups[]   = $org_quotes;
        $sp1_slot_count += $n;
    }

    $sp2_max        = max(0, (int) floor(($followup_slots - $sp1_slot_count) * FOLLOWUP_CREATED_DATE_REMAINING_PCT));
    $sp2_all_groups = $group_by_org($sp2);
    $sp2_groups     = [];
    $sp2_slot_count = 0;
    foreach ($sp2_all_groups as $org_quotes) {
        $n = count($org_quotes);
        if ($sp2_slot_count + $n > $sp2_max) continue;
        $sp2_groups[]   = $org_quotes;
        $sp2_slot_count += $n;
    }

    $sp3_max        = max(0, $followup_slots - $sp1_slot_count - $sp2_slot_count);
    $sp3_all_groups = $group_by_org($sp3);
    $sp3_groups     = [];
    $sp3_slot_count = 0;
    foreach ($sp3_all_groups as $org_quotes) {
        $n = count($org_quotes);
        if ($sp3_slot_count + $n > $sp3_max) continue;
        $sp3_groups[]   = $org_quotes;
        $sp3_slot_count += $n;
    }

    $sp4_max        = max(0, $followup_slots - $sp1_slot_count - $sp2_slot_count - $sp3_slot_count);
    $sp4_all_groups = $group_by_org($sp4);
    $sp4_groups     = [];
    $sp4_slot_count = 0;
    foreach ($sp4_all_groups as $org_quotes) {
        $n = count($org_quotes);
        if ($sp4_slot_count + $n > $sp4_max) continue;
        $sp4_groups[]   = $org_quotes;
        $sp4_slot_count += $n;
    }

    return ['sp1' => $sp1_groups, 'sp2' => $sp2_groups, 'sp3' => $sp3_groups, 'sp4' => $sp4_groups];
}

// A logged call counts as "worked" only if it left a call-back date behind, shared by both
// worked-today reads so today's appearance and tomorrow's backlog age never disagree. Legacy
// rows carry the zero date. resolve_first_pending() enforces the same rule independently via
// its own EXISTS subquery; keep the two in sync by hand.
function worked_date_is_set(?string $next_date): bool
{
    return !empty($next_date) && substr($next_date, 0, 10) !== '0000-00-00';
}

// Shared by load_carryover_quotes and load_from_daily_runs so the two can never compute a
// different "Days behind" anchor for the same quote. Given each candidate quote's prior
// daily_runs appearances (ascending run_date strings, run_date < today) and today's date,
// resets the pending streak at the quote's last logged call (outcome IS NOT NULL, on a day
// before today) and returns the earliest appearance after that reset point. A quote worked
// after every one of its prior appearances maps to null (not currently pending).
// Returns qid => 'Y-m-d'|null.
function resolve_first_pending(mysqli $conn, string $today, array $run_dates_by_qid): array
{
    if (empty($run_dates_by_qid)) return [];
    $rd     = $conn->real_escape_string($today);
    $ids_in = implode(',', array_keys($run_dates_by_qid));

    // Only a call that scheduled something resets the streak: a dateless call leaves the quote
    // owing what it owed before, so its backlog age keeps running instead of the "Days behind"
    // badge restarting on an unmet commitment. No-op on existing data (every past call wrote a
    // reminder).
    $last_worked = [];
    $res = $conn->query("
        SELECT f.quoteid, MAX(f.calltime) AS lw
        FROM vtiger_quotes_followup f
        WHERE f.outcome IS NOT NULL
          AND f.calltime < '$rd 00:00:00'
          AND f.quoteid IN ($ids_in)
          AND EXISTS (
              SELECT 1 FROM vtiger_quotes_followup s
              WHERE s.quoteid = f.quoteid
                AND s.followup_type = 'schedule_follow_up'
                AND s.auto_id > f.auto_id
          )
        GROUP BY f.quoteid
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) $last_worked[(int)$row['quoteid']] = substr($row['lw'], 0, 10);
        $res->free();
    }

    $first_pending = [];
    foreach ($run_dates_by_qid as $qid => $dates) {
        $lw     = $last_worked[$qid] ?? null;
        $streak = ($lw === null) ? $dates : array_values(array_filter($dates, fn($d) => $d > $lw));
        $first_pending[$qid] = $streak[0] ?? null;
    }
    return $first_pending;
}

// Returns carry-over quotes (the "snowball" backlog) per caller, org-grouped.
// A quote is carry-over if it appeared in daily_runs on any PRIOR day (run_date < today),
// was NOT worked since that appearance (the staleness clock resets when an outcome is
// logged), and is still in the active cohort: stage listed in $stages, trip date still
// ahead, not deleted. $stages defaults to the Follow-up cohort; the Leads queue passes its
// own stage so its carry-over is not silently dropped. This persists a pending quote
// until it is worked or leaves the cohort — independent of whether it still qualifies for
// an SP bucket today. Each quote carries $q['days_behind'] (today − the EARLIER of its first
// pending run_date and its promised next_call_date — see "pending since" note in step 6)
// and $q['worked_today']. Keyed by user_name (login). Additive — NOT capped by the slot
// budget; callers must exclude these quoteids from build_caller_queue so fresh slots stack
// on top (see india_callers.php / groups_mice.php).
function load_carryover_quotes(
    mysqli $conn,
    string $today,
    array $user_names,
    array $buckets = ['sp1', 'sp2', 'sp3', 'sp4', 'carryover'],
    array $stages  = ['Created', 'Requote'],
    ?array $stage_outcomes  = null,
    string $closure_table   = 'tdu_quote_closure_feedback',
    string $closure_outcome = 'rejected'
): array
{
    if (empty($user_names)) return [];

    $users_esc = implode(',', array_map(fn($u) => "'" . $conn->real_escape_string($u) . "'", $user_names));
    $rd        = $conn->real_escape_string($today);
    $b_in      = "'" . implode("','", array_map(fn($b) => $conn->real_escape_string($b), $buckets)) . "'";
    $stages_in = "'" . implode("','", array_map(fn($s) => $conn->real_escape_string($s), $stages)) . "'";

    // user_name of the latest run reflects ownership after any transfers.
    $res = $conn->query("
        SELECT user_name, item_id, run_date
        FROM daily_runs
        WHERE run_date < '$rd'
          AND user_name IN ($users_esc)
          AND item_type = 'quote'
          AND bucket IN ($b_in)
        ORDER BY run_date
    ");
    if (!$res) return [];

    $run_dates_by_qid = [];   // qid => [run_date, ...] (ascending)
    $owner_by_qid     = [];   // qid => user_name of latest prior run_date
    while ($row = $res->fetch_assoc()) {
        $qid = (int)$row['item_id'];
        $run_dates_by_qid[$qid][] = $row['run_date'];
        $owner_by_qid[$qid]       = $row['user_name']; // ORDER BY run_date → last wins
    }
    $res->free();
    if (empty($run_dates_by_qid)) return [];

    $ids_in = implode(',', array_keys($run_dates_by_qid));
    if (empty($ids_in)) return [];

    // Pending streak — see resolve_first_pending(); reset at the last logged call before today.
    $first_pending_by_qid = resolve_first_pending($conn, $today, $run_dates_by_qid);

    // Same shape as load_from_daily_runs()'s worked-today read, including the fallback and the
    // no-date skip, so the two must agree or a row's state flips between the first load and
    // every reload after it.
    $worked_today = [];
    $res3 = $conn->query("
        SELECT f.quoteid, f.auto_id AS followup_id, f.description AS notes,
               f.outcome AS outcome,
               COALESCE(
                 (SELECT s.next_follow_up_date
                    FROM vtiger_quotes_followup s
                   WHERE s.quoteid = f.quoteid AND s.auto_id > f.auto_id
                     AND s.followup_type = 'schedule_follow_up'
                   ORDER BY s.auto_id LIMIT 1),
                 (SELECT s2.next_follow_up_date
                    FROM vtiger_quotes_followup s2
                   WHERE s2.quoteid = f.quoteid AND s2.auto_id > lw.min_id
                     AND s2.followup_type = 'schedule_follow_up'
                   ORDER BY s2.auto_id LIMIT 1)
               ) AS next_date,
               e.channel AS channel
        FROM vtiger_quotes_followup f
        LEFT JOIN tdu_quotes_followup_ext e ON e.followup_id = f.auto_id
        INNER JOIN (
            SELECT c.quoteid, MAX(c.auto_id) AS max_id, MIN(c.auto_id) AS min_id,
                   MAX(CASE WHEN x.schedule_followup_id IS NOT NULL THEN c.auto_id END) AS owner_id
            FROM vtiger_quotes_followup c
            LEFT JOIN tdu_quotes_followup_ext x ON x.followup_id = c.auto_id
            WHERE c.calltime >= '$rd 00:00:00'
              AND c.calltime < DATE_ADD('$rd 00:00:00', INTERVAL 1 DAY)
              AND c.outcome IS NOT NULL
              AND c.quoteid IN ($ids_in)
            GROUP BY c.quoteid
        ) lw ON f.auto_id = COALESCE(lw.owner_id, lw.max_id)
    ");
    if ($res3) {
        while ($row = $res3->fetch_assoc()) {
            if (!worked_date_is_set($row['next_date'] ?? null)) continue;
            $worked_today[(int)$row['quoteid']] = [
                'followup_id' => (int) $row['followup_id'],
                'notes'       => $row['notes'] ?? '',
                'outcome'     => $row['outcome'] ?? '',
                'next_date'   => $row['next_date'] ?? null,
                'channel'     => $row['channel'] ?? '',
            ];
        }
        $res3->free();
    }

    // Terminal outcomes do not write to vtiger_quotes_followup, so check the closure table
    // and the stage-change log, both scoped to this queue's own tables and stage map. The
    // cohort re-filter below would drop these anyway; scoping them keeps the two consistent.
    // Uses $ids_in (full carryover candidate set) — $pending_in is not yet computed at this point.
    foreach (load_closure_worked_today($conn, $today, $ids_in, $closure_table, $closure_outcome) as $qid => $entry) {
        if (!isset($worked_today[$qid])) $worked_today[$qid] = $entry;
    }
    foreach (load_stage_change_worked_today($conn, $today, $ids_in, $stage_outcomes) as $qid => $entry) {
        if (!isset($worked_today[$qid])) $worked_today[$qid] = $entry;
    }

    // days_behind re-anchored to next_call_date in the loop below.
    $pending = [];   // qid => ['first_pending' => 'Y-m-d', 'owner' => user_name]
    foreach ($first_pending_by_qid as $qid => $fp) {
        if ($fp === null) continue; // worked after its last appearance → not pending
        $pending[$qid] = ['first_pending' => $fp, 'owner' => $owner_by_qid[$qid]];
    }
    if (empty($pending)) return [];

    $pending_in = implode(',', array_keys($pending));

    // Reapply the full cohort filter — quotes that left (stage change, trip passed, deleted) drop silently.
    $res4 = $conn->query("
        SELECT
            vq.quoteid, vq.quote_no, vq.subject, vq.quotestage,
            vq.adults, vq.children, vq.infants, vq.created_at,
            va.organization_name, va.organizationid,
            vcd.name  AS contactname,
            vcd.mobile AS contactmobile,
            vqcf.cf_1162 AS trip_start_date,
            vqinfo.assigned_to_sales_agent AS owner,
            vqinfo.assigned_to_region,
            vqinfo.priority,
            COALESCE(vqf_cnt.call_count, 0) AS call_count,
            sched.next_call_date
        FROM vtiger_quotes vq
        LEFT JOIN vtiger_quotescf vqcf        ON vq.quoteid = vqcf.quoteid
        LEFT JOIN vtiger_quotes_info vqinfo   ON vq.quoteid = vqinfo.quoteid
        LEFT JOIN tdu_organisation va         ON vq.accountid = va.organizationid
        LEFT JOIN tdu_contacts vcd            ON vq.contactid = vcd.auto_id
            AND va.organizationid = vcd.organizationid
        LEFT JOIN (
            SELECT quoteid, COUNT(*) AS call_count
            FROM vtiger_quotes_followup
            WHERE followup_type = 'call_info'
              AND quoteid IN ($pending_in)
            GROUP BY quoteid
        ) vqf_cnt ON vqf_cnt.quoteid = vq.quoteid
        LEFT JOIN (
            SELECT f.quoteid, DATE(f.next_follow_up_date) AS next_call_date
            FROM vtiger_quotes_followup f
            INNER JOIN (
                SELECT quoteid, MAX(auto_id) AS max_id
                FROM vtiger_quotes_followup
                WHERE followup_type = 'schedule_follow_up'
                  AND (followup IS NULL OR followup != 'checked')
                  AND quoteid IN ($pending_in)
                GROUP BY quoteid
            ) ls ON f.auto_id = ls.max_id
        ) sched ON sched.quoteid = vq.quoteid
        WHERE vq.quoteid IN ($pending_in)
          AND vq.deleted = 0
          AND vq.quotestage IN ($stages_in)
          AND vqcf.cf_1162 > '$rd'
    ");
    $details = [];
    if ($res4) {
        while ($row = $res4->fetch_assoc()) $details[(int)$row['quoteid']] = $row;
        $res4->free();
    }

    $by_user = [];   // user_name => [qid => quote]
    foreach ($pending as $qid => $meta) {
        if (!isset($details[$qid])) continue; // left the cohort → drop silently
        $q = $details[$qid];

        // "Pending since" (re-anchored): count from the EARLIER of when we first surfaced the
        // quote and the date we had promised to call (next_call_date). During backlog cleanup an
        // old scheduled callback surfaces only now, so first_pending alone would understate how
        // long the client has really been waiting; anchoring to next_call_date shows the true
        // overdue. Once data is clean the two anchors coincide and this collapses to first_pending.
        $anchor = $meta['first_pending'];
        $ncd    = $q['next_call_date'] ?? null;
        if ($ncd && $ncd !== '0000-00-00' && $ncd < $anchor) $anchor = $ncd;
        $days_behind = (int) floor((strtotime($today) - strtotime($anchor)) / 86400);

        $q['days_behind']  = max(0, $days_behind);
        $q['worked_today']       = isset($worked_today[$qid]);
        $q['worked_followup_id'] = $worked_today[$qid]['followup_id'] ?? null;
        $q['worked_notes']       = $worked_today[$qid]['notes'] ?? '';
        $q['worked_outcome']     = $worked_today[$qid]['outcome'] ?? '';
        $q['worked_next_date']   = $worked_today[$qid]['next_date'] ?? null;
        $q['worked_channel']     = $worked_today[$qid]['channel'] ?? '';
        $by_user[$meta['owner']][$qid] = $q;
    }

    $result = [];
    foreach ($by_user as $uname => $quotes) {
        // Group by org, then order orgs by their most-stale quote (days_behind desc).
        $by_org = [];
        foreach ($quotes as $q) {
            $by_org[$q['organizationid'] ?? 'unknown'][] = $q;
        }
        foreach ($by_org as &$grp) {
            usort($grp, fn($a, $b) => $b['days_behind'] <=> $a['days_behind']);
        }
        unset($grp);
        uasort($by_org, function ($a, $b) {
            return max(array_column($b, 'days_behind')) <=> max(array_column($a, 'days_behind'));
        });
        $result[$uname] = array_values($by_org);
    }
    return $result;
}

// Removes quotes whose IDs are in $carryover_ids from an SP bucket's org groups.
// Carry-over section "owns" pending quotes so they don't appear twice in the same page.
function filter_carryover_from_bucket(array $groups, array $carryover_ids): array
{
    $out = [];
    foreach ($groups as $org_quotes) {
        $filtered = array_values(array_filter($org_quotes, fn($q) => !isset($carryover_ids[(int)$q['quoteid']])));
        if (!empty($filtered)) $out[] = $filtered;
    }
    return $out;
}

// Batch pre-check for which quotes in a rendered queue cannot be marked Accepted.
// Covers the two most common blockers (missing/incomplete pax data, insufficient payment
// close to travel date) so the "Accepted" button can be disabled client-side with a
// warning; the server still runs the full validation on submit regardless. Moved out of
// queue_view.php (was querying $conn directly inside the view) so the queue controllers
// (india_callers.php / groups_mice.php / all_callers.php) own the DB access and the view
// just renders the result, same pattern as $sp1_groups etc.
function compute_accepted_blocked(mysqli $conn, array $carryover_groups, array $sp1_groups, array $sp2_groups, array $sp3_groups, array $sp4_groups): array
{
    $accepted_blocked = [];
    $all_qids = [];
    foreach ([$carryover_groups, $sp1_groups, $sp2_groups, $sp3_groups, $sp4_groups] as $grp) {
        foreach ($grp as $org_quotes) {
            foreach ($org_quotes as $q) {
                $all_qids[] = (int) $q['quoteid'];
            }
        }
    }
    if (empty($all_qids)) return $accepted_blocked;

    $qids_sql = implode(',', $all_qids);

    // Quotes WITH pax in hotel — those absent from this set have no pax → block Accepted.
    $pax_res = $conn->query("SELECT DISTINCT quoteid FROM vtiger_pax_in_hotel WHERE quoteid IN ($qids_sql)");
    $has_pax = [];
    if ($pax_res) {
        while ($r = $pax_res->fetch_assoc()) $has_pax[(int)$r['quoteid']] = true;
    }
    foreach ($all_qids as $qid) {
        if (!isset($has_pax[$qid])) $accepted_blocked[$qid] = true;
    }

    // Quotes with pax rows but incomplete passenger data (empty surname/given_name/phone).
    $incomplete_res = $conn->query("
        SELECT DISTINCT quoteid FROM vtiger_pax_in_hotel
        WHERE quoteid IN ($qids_sql)
          AND (surname = '' OR surname IS NULL
               OR given_name = '' OR given_name IS NULL
               OR phone = '' OR phone IS NULL)
    ");
    if ($incomplete_res) {
        while ($r = $incomplete_res->fetch_assoc()) $accepted_blocked[(int)$r['quoteid']] = true;
    }

    // Quotes with payment issues: trip within 15 days and payment short by >$30.
    $pay_res = $conn->query("
        SELECT vq.quoteid
        FROM vtiger_quotes vq
        LEFT JOIN vtiger_quotescf vqcf ON vq.quoteid = vqcf.quoteid
        LEFT JOIN vtiger_payment_history vph ON vq.quoteid = vph.quoteid
        WHERE vq.quoteid IN ($qids_sql)
          AND (vq.quotestage = 'Created' OR vq.quotestage = 'Requote')
          AND vqcf.cf_1162 <= DATE_ADD(CURDATE(), INTERVAL 15 DAY)
          AND (vph.source != 'final' OR vph.source IS NULL)
        GROUP BY vq.quoteid
        HAVING SUM(CAST(vph.trams_received_amount AS DECIMAL(10,2))) < SUM(CAST(vph.total_amount AS DECIMAL(10,2))) - 30
            OR SUM(CAST(vph.total_amount AS DECIMAL(10,2))) <= 0
            OR SUM(CAST(vph.total_amount AS DECIMAL(10,2))) IS NULL
            OR SUM(CAST(vph.trams_received_amount AS DECIMAL(10,2))) IS NULL
    ");
    if ($pay_res) {
        while ($r = $pay_res->fetch_assoc()) $accepted_blocked[(int)$r['quoteid']] = true;
    }

    return $accepted_blocked;
}

// The full name of whoever works this quote today, across both CRM ownership columns.
// '' when nobody does.
//
// Reading one column stopped being enough once a region owner can be external: the two
// columns can name two different people on the same quote, and picking the wrong one
// charges the daily schedule cap to somebody who does not own the quote. Answered from
// the region model rather than by reading whichever column looks filled, so a column
// still holding a departed agent (any quote the correction pass has not reached yet)
// never wins. In order:
//   1. a personal claim holder named in either column, who is working it right now
//      whatever the region says;
//   2. this quote's region owner, which is who the correction pass will settle it on;
//   3. the internal column, for anything outside the region model.
function resolve_quote_owner_fullname(mysqli $conn, int $quoteid): string
{
    $row = $conn->query("
        SELECT assigned_to_region, assigned_to_sales_agent, assigned_to_external_sales_agent
        FROM vtiger_quotes_info
        WHERE quoteid = $quoteid
        LIMIT 1
    ")->fetch_assoc();
    if (!$row) return '';

    $internal = trim((string) ($row['assigned_to_sales_agent'] ?? ''));
    $external = trim((string) ($row['assigned_to_external_sales_agent'] ?? ''));

    $claim_holders = array_flip(tdu_personal_claim_users());
    if ($internal !== '' && isset($claim_holders[$internal])) return $internal;
    if ($external !== '' && isset($claim_holders[$external])) return $external;

    $region = tdu_queue_region_for($row['assigned_to_region'] ?? null);
    if ($region !== null) {
        $owner = tdu_region_owner_name($region);
        if ($owner !== null) return $owner;
    }

    return $internal;
}

// Resolves a quote's owning caller and, if editing an existing call_info row, the
// auto_id of its paired schedule_follow_up row to exclude from schedule-cap counts.
// Shared by ajax_check_schedule_availability.php and ajax_get_schedule_counts.php.
// caller_fullname is '' when the quote has no owner yet.
function resolve_caller_and_exclusion(mysqli $conn, int $quoteid, int $followup_id): array
{
    $caller_fullname = resolve_quote_owner_fullname($conn, $quoteid);

    $exclude_followup_id = null;
    if ($followup_id) {
        $sched_row = $conn->query("
            SELECT auto_id FROM vtiger_quotes_followup
            WHERE quoteid = $quoteid
              AND auto_id > $followup_id
              AND followup_type = 'schedule_follow_up'
            ORDER BY auto_id
            LIMIT 1
        ")->fetch_assoc();
        if ($sched_row) {
            $exclude_followup_id = (int) $sched_row['auto_id'];
        }
    }

    return [$caller_fullname, $exclude_followup_id];
}

// Counts pending schedule_follow_up rows for one caller on one future date — used to
// enforce MAX_SCHEDULED_PER_DAY_PER_CALLER before a new/edited call outcome is written.
// Counts ALL pending rows for that owner+date regardless of who wrote them (this queue
// or the legacy dashboard) — same "read counts all" principle already used for
// call_count. Pass $exclude_followup_id when editing an existing call_info row so its
// own paired schedule row (about to be updated, not inserted fresh) doesn't count
// against itself.
// Matches the name against both CRM ownership columns, since an external agent's quotes
// are carried in the external one: "this person's quotes, wherever their name sits". A
// quote naming them in both still counts once, and another person's stale name in the
// other column can never match the name being counted.
function count_scheduled_for_caller_date(mysqli $conn, string $caller_fullname, string $date, ?int $exclude_followup_id = null, string $stage_pool = 'followup'): int
{
    $caller_esc  = $conn->real_escape_string($caller_fullname);
    $date_esc    = $conn->real_escape_string($date);
    $exclude_sql = $exclude_followup_id ? "AND vqf.auto_id != " . (int) $exclude_followup_id : '';
    $stages_in   = "'" . implode("','", array_map(
        fn($s) => $conn->real_escape_string($s), schedule_pool_stages($stage_pool)
    )) . "'";

    $row = $conn->query("
        SELECT COUNT(*) AS cnt
        FROM vtiger_quotes_followup vqf
        JOIN vtiger_quotes vq ON vq.quoteid = vqf.quoteid
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vqf.quoteid
        WHERE vqf.followup_type = 'schedule_follow_up'
          AND (vqf.followup IS NULL OR vqf.followup != 'checked')
          AND DATE(vqf.next_follow_up_date) = '$date_esc'
          AND (vqinfo.assigned_to_sales_agent = '$caller_esc'
               OR vqinfo.assigned_to_external_sales_agent = '$caller_esc')
          AND vq.quotestage IN ($stages_in)
          AND vq.deleted = 0
          $exclude_sql
    ")->fetch_assoc();

    return (int) $row['cnt'];
}

// Same semantics as count_scheduled_for_caller_date(), grouped over a date range in
// one query — feeds the outcome modal's calendar with per-day load up front.
function get_scheduled_counts_for_caller_range(mysqli $conn, string $caller_fullname, string $start_date, string $end_date, ?int $exclude_followup_id = null, string $stage_pool = 'followup'): array
{
    $caller_esc  = $conn->real_escape_string($caller_fullname);
    $start_esc   = $conn->real_escape_string($start_date);
    $end_esc     = $conn->real_escape_string($end_date);
    $exclude_sql = $exclude_followup_id ? "AND vqf.auto_id != " . (int) $exclude_followup_id : '';
    $stages_in   = "'" . implode("','", array_map(
        fn($s) => $conn->real_escape_string($s), schedule_pool_stages($stage_pool)
    )) . "'";

    $result = $conn->query("
        SELECT DATE(vqf.next_follow_up_date) AS sched_date, COUNT(*) AS cnt
        FROM vtiger_quotes_followup vqf
        JOIN vtiger_quotes vq ON vq.quoteid = vqf.quoteid
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vqf.quoteid
        WHERE vqf.followup_type = 'schedule_follow_up'
          AND (vqf.followup IS NULL OR vqf.followup != 'checked')
          AND DATE(vqf.next_follow_up_date) BETWEEN '$start_esc' AND '$end_esc'
          AND (vqinfo.assigned_to_sales_agent = '$caller_esc'
               OR vqinfo.assigned_to_external_sales_agent = '$caller_esc')
          AND vq.quotestage IN ($stages_in)
          AND vq.deleted = 0
          $exclude_sql
        GROUP BY DATE(vqf.next_follow_up_date)
    ");

    $counts = [];
    while ($row = $result->fetch_assoc()) {
        $counts[$row['sched_date']] = (int) $row['cnt'];
    }
    return $counts;
}

// Derived-table fragment shared by every "Accepted this month" reader — detects the
// 'Change Stage to Accepted' stage-track event within the current calendar month.
// Bounded on both ends (not just >=) so the range stays sargable, since wrapping the column in
// DATE() disables the index, and so it never picks up a stray future row.
function accepted_stage_events_this_month_sql(): string
{
    return "
        SELECT quoteid, created_at,
               TRIM(SUBSTRING(stage, LENGTH('Change Stage to ') + 1)) AS stage_name
        FROM vtiger_quote_stage_track
        WHERE stage LIKE 'Change Stage to %'
          AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND created_at <  DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
    ";
}

// Shared "Accepted this month" population for the India cohort — used by the queue widget's
// monthly-goal count and by the monitoring dashboard's Accepted chart and CSV export, so
// the three can't drift apart
// again. Deliberately owner-agnostic: a quote created and accepted the same day via the main
// CRM can land with no owner among the configured callers at all (confirmed 2026-07-21), which
// silently dropped it from monitoring's old owner-filtered query. Split FIT vs Groups by the
// same quote_no suffix the daily queues themselves use (india_callers.php / groups_mice.php),
// both scoped to the same India regions those queues use. Excludes TDU_INTERNAL_ORG_ID (Turtle
// Down Under) — internal/test quotes, not real clients.
// $quote_type: 'fit' or 'groups'. Assumes the caller has already joined vtiger_quotes AS vq and
// vtiger_quotes_info AS vqinfo against the accepted_stage_events_this_month_sql() derived table
// (aliased t).
// Canonical "still counts as Accepted" stage list — Accepted plus everything downstream that
// hasn't since been cancelled. Single source of truth: accepted_this_month_scope_sql() below
// used to count a quote as Accepted the moment the event fired, with no check for a later
// reversal to Rejected After Confrmation. monitoring_builder.php's accepted_stages_sql() now
// sources this same list instead of keeping its own copy.
// Casing matters: vtiger_quotestage stores 'PRE QA - pending' and 'PRE QA - completed'
// lower-case. Both are only ever interpolated into SQL IN (...), where the collation is
// case-insensitive, so the capitalised spelling this list carried until 2026-08-14 was
// masked. It would fail silently in any PHP comparison.
function accepted_stage_names(): array
{
    return [
        'Accepted', 'PRE QA - pending', 'PRE QA - completed',
        'Payment Received - Release Vouchers', 'Final QA', 'Delivered',
        'On Ground', 'Accounts - Reconciliation', 'Completed (Accounts)',
    ];
}

function accepted_this_month_scope_sql(mysqli $conn, array $india_regions, string $quote_type): string
{
    if (!$india_regions) return '1=0';
    $regions_list = implode(',', array_map(
        fn($r) => "'" . $conn->real_escape_string($r) . "'",
        $india_regions
    ));
    $quote_no_condition = $quote_type === 'groups'
        ? "vq.quote_no LIKE '%G'"
        : "vq.quote_no NOT LIKE '%G'";
    // A quote whose Accepted event fired this month but has since been reverted to Rejected
    // After Confrmation no longer counts — see accepted_stage_names() above.
    $accepted_in = implode(',', array_map(
        fn($s) => "'" . $conn->real_escape_string($s) . "'",
        accepted_stage_names()
    ));

    return "
        t.stage_name = 'Accepted'
        AND vq.quotestage IN ($accepted_in)
        AND vq.deleted = 0
        AND $quote_no_condition
        AND vq.accountid != " . TDU_INTERNAL_ORG_ID . "
        AND vqinfo.assigned_to_region IN ($regions_list)
    ";
}

// ---------------------------------------------------------------------------
// Payment-deadline queue — Accepted+ quotes that still owe money
// ---------------------------------------------------------------------------

// Post-Accepted stages this queue tracks — mirrors quotes.php's payment-due highlight,
// minus 'Payment Received - Release Vouchers' (that stage renders green there, not red).
// In practice this narrows to Accepted only once the unpaid-balance/future-trip filters
// below apply; the later stages are kept anyway since they cost nothing to check.
function payment_deadline_stage_list(): array
{
    return [
        'Accepted', 'Requote After Confirmation',
        'PRE QA - pending', 'PRE QA - completed',
    ];
}

// Builds the whole payment-deadline cohort as one list: FIT and Groups & MICE together,
// every region at once, split only into 'rows' (routable) and 'unrouted'.
//
// There is no owner split any more. This queue is access-gated rather than owned: a
// fixed post-sale pair works every row, so "who does this belong to" has no answer to
// compute. 'unrouted' is NOT the old unclaimed pool wearing a new name: it means the
// quote's CRM region resolves to no queue region (blank, missing vtiger_quotes_info row,
// or a value that is not one of the CRM's own India regions). That is a data problem to fix in the CRM, not
// work to hand somebody, which is why the page shows those rows without a Log button.
//
// No daily_runs snapshot, unlike the Follow-up queue: membership here is a pure date +
// balance comparison that stays true on every later day once it first becomes true, so
// there is nothing to freeze and no carry-over concept to maintain.
//
// Two membership conditions, both load-bearing:
//   1. cf_1182 within PAYMENT_DEADLINE_WINDOW_DAYS (or already past) — the trigger.
//   2. Trip still ahead — a *cancellation* deadline means nothing once the client has
//      travelled. Without this, ~91% of the cohort had already flown.
// Exit is by STAGE ONLY — a quote leaves once its quotestage falls outside
// payment_deadline_stage_list()'s 4 stages, regardless of payment status.
// amount_due/amount_received (the ph join below) are still fetched and returned for the
// Total/Paid/Outstanding display columns, but no longer gate membership — a quote with
// money still outstanding does not get an extra reason to stay, and a quote that has
// been fully paid does not get an extra reason to leave; only the stage does.
function build_payment_deadline_queue(mysqli $conn, array $india_regions): array
{
    if (!$india_regions) return ['rows' => [], 'unrouted' => []];

    $stages    = payment_deadline_stage_list();
    $stage_in  = "'" . implode("','", array_map(fn($s) => $conn->real_escape_string($s), $stages)) . "'";
    $region_in = "'" . implode("','", array_map(fn($r) => $conn->real_escape_string($r), $india_regions)) . "'";

    // Scoped to India, with no FIT/Groups suffix filter: both types belong to the same
    // list here, and each row carries its own quote_type below for the CRM deep link.
    //
    // The region test is NULL-tolerant on purpose: a quote with no vtiger_quotes_info
    // row, or a blank region, must not be dropped — those are exactly the orphans the
    // unrouted block exists to surface. A plain `region IN (...)` against a LEFT JOINed
    // table silently behaves as an INNER JOIN and loses them.
    $window = (int) PAYMENT_DEADLINE_WINDOW_DAYS;

    $result = $conn->query("
        SELECT
            vq.quoteid, vq.quote_no, vq.quotestage, vq.created_at AS created_date,
            va.organization_name,
            vcd.mobile AS contact_mobile,
            vqcf.cf_1162 AS trip_date,
            vqcf.cf_1182 AS payment_deadline,
            vqcf.cf_1182_pending AS payment_deadline_pending,
            vqinfo.assigned_to_region,
            vqinfo.assigned_to_sales_agent AS owner,
            COALESCE(ph.amount_due, 0)      AS amount_due,
            COALESCE(ph.amount_received, 0) AS amount_received,
            CASE WHEN ph.amount_due > 0 THEN 1 ELSE 0 END AS amount_known,
            COALESCE(ph.has_initial_row, 0)      AS has_initial_row,
            COALESCE(pc.calls_since_deadline, 0) AS calls_since_deadline,
            pc.last_auto_id        AS last_followup_id,
            last_ext.outcome       AS last_outcome,
            last_ext.snooze_until  AS last_next_date,
            last_ext.channel       AS last_channel,
            last_call.description  AS last_notes,
            last_call.calltime     AS last_call_time
        FROM vtiger_quotes vq
        LEFT JOIN tdu_organisation va       ON vq.accountid = va.organizationid
        LEFT JOIN tdu_contacts vcd          ON vq.contactid = vcd.auto_id
                                           AND va.organizationid = vcd.organizationid
        LEFT JOIN vtiger_quotescf vqcf      ON vq.quoteid = vqcf.quoteid
        LEFT JOIN vtiger_quotes_info vqinfo ON vq.quoteid = vqinfo.quoteid
        LEFT JOIN (
            SELECT quoteid,
                   SUM(CASE WHEN source = 'initial' THEN total_amount + 0 ELSE 0 END) AS amount_due,
                   SUM(CASE WHEN source = '' OR source IS NULL THEN trams_received_amount + 0 ELSE 0 END) AS amount_received,
                   MAX(CASE WHEN source = 'initial' THEN 1 ELSE 0 END) AS has_initial_row
            FROM vtiger_payment_history
            WHERE source != 'final' OR source IS NULL
            GROUP BY quoteid
        ) ph ON ph.quoteid = vq.quoteid
        -- Payment-chasing call stats, one pass over the (small) set of calls this tool
        -- itself logged. Returns the count since the deadline AND the id of the latest
        -- call, so last_ext below can fetch that row's outcome and promised date with a
        -- single primary-key lookup. Deliberately not window functions: this is MariaDB
        -- 11.4 on staging, but production's version has never been confirmed, and
        -- MAX(auto_id) + a PK join works on every version.
        LEFT JOIN (
            SELECT vqf.quoteid,
                   SUM(CASE WHEN vqf.calltime >= cf.cf_1182 THEN 1 ELSE 0 END) AS calls_since_deadline,
                   MAX(vqf.auto_id) AS last_auto_id
            FROM vtiger_quotes_followup vqf
            JOIN tdu_quotes_followup_ext ext ON ext.followup_id = vqf.auto_id
            JOIN vtiger_quotescf cf          ON cf.quoteid = vqf.quoteid
            WHERE vqf.followup_type = 'call_info'
              -- Deliberately NOT the bare 'next_call' the Follow-up queue writes to this
              -- same shared table — a stale pre-Accepted Follow-up callback promise would
              -- otherwise be misread as an already-done payment-chasing call. Payment
              -- Deadline outcomes are namespaced (payment_no_answer, payment_next_call)
              -- precisely so the two queues' history can't collide.
              AND ext.outcome IN ('payment_no_answer', 'payment_next_call')
            GROUP BY vqf.quoteid
        ) pc ON pc.quoteid = vq.quoteid
        LEFT JOIN tdu_quotes_followup_ext last_ext ON last_ext.followup_id = pc.last_auto_id
        LEFT JOIN vtiger_quotes_followup  last_call ON last_call.auto_id   = pc.last_auto_id
        WHERE vq.deleted = 0
          AND vq.quotestage IN ($stage_in)
          AND vqcf.cf_1182 IS NOT NULL
          AND vqcf.cf_1182 <= DATE_ADD(CURDATE(), INTERVAL $window DAY)
          AND vqcf.cf_1162 > CURDATE()
          AND (vqinfo.assigned_to_region IN ($region_in)
               OR vqinfo.assigned_to_region IS NULL
               OR vqinfo.assigned_to_region = '')
    ");

    if ($result === false) {
        error_log('[TDU Queue] build_payment_deadline_queue query failed: ' . $conn->error);
        return ['rows' => [], 'unrouted' => []];
    }

    $rows     = [];
    $unrouted = [];
    $today    = date('Y-m-d');

    while ($row = $result->fetch_assoc()) {
        // Derived from the suffix rather than filtered on it: one list, two quote types.
        // Only drives the CRM deep link's quotetype; nothing about priority reads it.
        $row['quote_type']   = (strcasecmp(substr((string) $row['quote_no'], -1), 'G') === 0)
            ? 'groups' : 'fit';
        // Display only. null = the CRM region maps to no queue region, which is what puts
        // the row in 'unrouted' below.
        $row['queue_region'] = tdu_queue_region_for($row['assigned_to_region']);

        $row['amount_known']         = (bool) $row['amount_known'];
        $row['has_initial_row']      = (bool) $row['has_initial_row'];
        $row['calls_since_deadline'] = (int) $row['calls_since_deadline'];
        $row['is_overdue']           = $row['payment_deadline'] < $today;
        $days_until_trip             = $row['trip_date']
            ? (int) floor((strtotime($row['trip_date']) - strtotime($today)) / 86400)
            : null;
        // Escalates on either condition: the original rule (deadline passed + enough
        // unanswered attempts) OR a hard upper boundary — fewer than
        // PAYMENT_DEADLINE_ESCALATE_TRIP_DAYS left before the trip, regardless of call
        // count. Once the trip is that close there's no time left to wait for more
        // attempts, even if the deadline itself hasn't technically passed yet.
        $row['escalate_flag'] = ($row['is_overdue']
                && $row['calls_since_deadline'] >= PAYMENT_DEADLINE_ATTEMPTS_BEFORE_FLAG)
            || ($days_until_trip !== null && $days_until_trip < PAYMENT_DEADLINE_ESCALATE_TRIP_DAYS);

        // Display-only grouping — membership in this queue is stage-only now (see the
        // function comment above), but the page still groups rows by urgency so a caller
        // can tell "needs escalating" apart from "already paid, just waiting on the CRM
        // stage to catch up". Same $30 tolerance the old membership filter used, now
        // purely informational.
        $row['paid_waiting'] = $row['amount_known']
            && $row['amount_received'] >= $row['amount_due'] - 30;
        if ($row['paid_waiting']) {
            $row['bucket'] = 'paid_waiting';
        } elseif ($row['escalate_flag']) {
            $row['bucket'] = 'escalate';
        } elseif ($row['is_overdue']) {
            $row['bucket'] = 'overdue';
        } else {
            $row['bucket'] = 'due_soon';
        }

        // A scheduled next-call/no-answer-snooze only demotes the row while it is both
        // the most recent outcome and still in the future. Once its date passes, or a
        // later call supersedes it, the quote falls straight back into the normal tiers.
        // Bare 'next_call' deliberately excluded — see the pc subquery's comment above
        // for why treating it as this queue's own would misattribute a Follow-up queue
        // callback promise as a payment-chasing one.
        $pending_outcomes    = ['payment_no_answer', 'payment_next_call'];
        $row['last_followup_id'] = (int) ($row['last_followup_id'] ?? 0);
        $row['next_call_date']   = (in_array($row['last_outcome'], $pending_outcomes, true))
            ? $row['last_next_date']
            : null;
        $row['pending']          = $row['next_call_date'] !== null
            && $row['next_call_date'] > $today;

        // A no-answer call logged today with no future date to schedule (trip too close
        // to snooze past — see ajax_log_payment_outcome.php's skip_snooze) still needs to
        // show as "already tried today", or the row looks untouched and a caller could
        // call it again believing nobody has. Deliberately doesn't carry the pd-row--pending
        // opacity/sort demotion pending gets — the quote is still fully due for action,
        // just with nothing left to show a "next call" date for.
        $row['called_today'] = !empty($row['last_call_time'])
            && substr($row['last_call_time'], 0, 10) === $today;

        // Legacy CRM free text can carry Windows-1252 bytes. The view escapes with an
        // explicit UTF-8 charset, which renders an invalid string as empty — normalise
        // here so an org name never silently vanishes from the page.
        foreach (['organization_name', 'last_notes'] as $f) {
            if (isset($row[$f]) && is_string($row[$f]) && $row[$f] !== ''
                && !mb_check_encoding($row[$f], 'UTF-8')) {
                $row[$f] = mb_convert_encoding($row[$f], 'UTF-8', 'Windows-1252');
            }
        }

        if ($row['queue_region'] !== null) {
            $rows[] = $row;
        } else {
            $unrouted[] = $row;
        }
    }
    $result->free();

    // Sort: pending rows sink to the bottom (soonest next-call date first); otherwise
    // most-overdue-deadline first, then soonest trip date as a tie-break. (Previously
    // also factored in invoice-known status and shortfall size — dropped since
    // deadline/trip date is what actually drives urgency; still visible via the
    // Outstanding column's own badge.)
    $sorter = function (array $a, array $b): int {
        if ($a['pending'] !== $b['pending']) {
            return $a['pending'] <=> $b['pending'];
        }
        if ($a['pending']) {
            return $a['next_call_date'] <=> $b['next_call_date'];
        }
        if ($a['payment_deadline'] !== $b['payment_deadline']) {
            return $a['payment_deadline'] <=> $b['payment_deadline'];
        }
        return $a['trip_date'] <=> $b['trip_date'];
    };
    usort($rows,     $sorter);
    usort($unrouted, $sorter);

    return ['rows' => $rows, 'unrouted' => $unrouted];
}

// ---------------------------------------------------------------------------
// Regional model. Every function below reads and writes tdu_region_daily_runs
// exclusively. load_carryover_quotes() above (the only survivor of the old
// per-caller snapshot trio) is unrelated to this: it now serves only
// monitoring_builder.php's business-wide charts.
// ---------------------------------------------------------------------------

// Unified India FIT + Groups & MICE cohort, region-scoped instead of team-scoped.
// Same shape as india_callers.php's/groups_mice.php's own queries (which differed only
// by quote_no LIKE/NOT LIKE '%G'), merged into one query with quote_type derived from
// the suffix instead of filtered by it. Adds 'quote_type' and 'queue_region' (null =
// unroutable, a region value that is not one of the CRM's own India regions) to every row.
function fetch_region_cohort(mysqli $conn, string $today, array $india_regions): array
{
    $today_sql  = $conn->real_escape_string($today);
    $regions_in = "'" . implode("','", array_map(fn($r) => $conn->real_escape_string($r), $india_regions)) . "'";

    $sql = "
        SELECT
            vq.quoteid,
            vq.quote_no,
            vq.subject,
            vq.quotestage,
            vq.adults,
            vq.children,
            vq.infants,
            vq.created_at,
            vqcf.cf_1162                       AS trip_start_date,
            va.organizationid,
            va.organization_name,
            vcd.name                           AS contactname,
            vcd.mobile                         AS contactmobile,
            vqinfo.assigned_to_region,
            vqinfo.assigned_to_sales_agent          AS owner,
            vqinfo.assigned_to_external_sales_agent AS external_owner,
            vqinfo.priority,
            COALESCE(cc.call_count, 0)         AS call_count,
            sched.next_call_date,
            ext.snooze_until,
            call_rec.outcome                   AS last_outcome
        FROM vtiger_quotes vq
        LEFT JOIN vtiger_quotescf vqcf        ON vq.quoteid = vqcf.quoteid
        LEFT JOIN vtiger_quotes_info vqinfo   ON vq.quoteid = vqinfo.quoteid
        LEFT JOIN tdu_organisation va         ON vq.accountid = va.organizationid
        LEFT JOIN tdu_contacts vcd            ON vq.contactid = vcd.auto_id
            AND va.organizationid = vcd.organizationid
        LEFT JOIN (
            SELECT quoteid, COUNT(*) AS call_count, MAX(auto_id) AS max_id
            FROM vtiger_quotes_followup
            WHERE followup_type = 'call_info'
            GROUP BY quoteid
        ) cc ON cc.quoteid = vq.quoteid
        LEFT JOIN vtiger_quotes_followup call_rec ON call_rec.auto_id = cc.max_id
        LEFT JOIN tdu_quotes_followup_ext ext     ON ext.followup_id = cc.max_id
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
        WHERE vq.deleted = 0
            AND vq.quotestage IN ('Created', 'Requote')
            AND vqcf.cf_1162 > '$today_sql'
            AND vqinfo.assigned_to_region IN ($regions_in)
    ";

    $result = $conn->query($sql);
    if ($result === false) {
        error_log('[TDU Queue] fetch_region_cohort query failed: ' . $conn->error);
        tdu_config_fatal('Service temporarily unavailable. Please contact the administrator.');
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();

    foreach ($rows as &$row) {
        $row['quote_type']   = (substr($row['quote_no'], -1) === 'G') ? 'groups' : 'fit';
        $row['queue_region'] = tdu_queue_region_for($row['assigned_to_region']);
    }
    unset($row);

    return $rows;
}

// Weight = count of live, non-snoozed quotes in the region's slice of the cohort.
// Floor of 1 so a region with nothing due today still gets a share of its owner's
// budget rather than being invisible to split_budget_across_regions().
// $snoozed matches build_caller_queue()'s own derivation exactly (next_call_date in
// the future) — anything else would give this weight a different idea of "actionable"
// than the classification that actually fills the queue.
function region_actionable_weight(array $region_rows, string $today): int
{
    $count = 0;
    foreach ($region_rows as $q) {
        $snoozed = !empty($q['next_call_date']) && $q['next_call_date'] > $today;
        if (!$snoozed) $count++;
    }
    return max(1, $count);
}

// One blended follow-up budget for a person covering one or more regions — never a sum
// per region, a single number (confirmed: Mayur covering West + Gujarat gets one budget,
// not two). Each region's daily_capacity is weighted by its own actionable weight before
// blending, so a quiet region doesn't drag the budget down as hard as a busy one.
// INDIA_FOLLOWUP_PCT, not STANDARD_FOLLOWUP_PCT: confirmed with the user 2026-08-25 — the
// region model merges FIT+Groups into one cohort and the two legacy queues used different
// percentages, so this needed an explicit call rather than picking one silently.
function person_followup_budget(array $regions, array $weights): int
{
    if (empty($regions)) return 0;

    $weighted_capacity_sum = 0;
    $weight_sum            = 0;
    foreach ($regions as $region) {
        $w = $weights[$region] ?? 1;
        $weighted_capacity_sum += $w * tdu_region_capacity($region);
        $weight_sum            += $w;
    }
    if ($weight_sum <= 0) return 0;

    $blended_capacity = $weighted_capacity_sum / $weight_sum;
    return (int) floor($blended_capacity * INDIA_FOLLOWUP_PCT);
}

// Splits one person's blended budget back across their regions, proportional to each
// region's own weight. Largest-remainder method: floor every share first, then hand the
// leftover slots one at a time to the regions with the biggest fractional remainder, so
// the total always lands on exactly $budget (plain proportional rounding can over- or
// under-shoot by a slot or two).
function split_budget_across_regions(int $budget, array $weights): array
{
    $result = array_fill_keys(array_keys($weights), 0);
    $weight_sum = array_sum($weights);
    if ($budget <= 0 || $weight_sum <= 0) return $result;

    $remainders = [];
    $floor_sum  = 0;
    foreach ($weights as $region => $w) {
        $exact  = $budget * $w / $weight_sum;
        $floor  = (int) floor($exact);
        $result[$region]    = $floor;
        $remainders[$region] = $exact - $floor;
        $floor_sum += $floor;
    }

    $leftover = $budget - $floor_sum;
    arsort($remainders); // biggest fractional remainder first
    $regions_by_remainder = array_keys($remainders);
    for ($i = 0; $i < $leftover && $i < count($regions_by_remainder); $i++) {
        $result[$regions_by_remainder[$i]]++;
    }

    return $result;
}

// Writes each quote's region owner into the CRM ownership column that owner belongs in,
// wherever it currently disagrees. A quote whose region has no owner is left untouched
// (fail-closed), since there is nothing correct to write. Batched into CASE WHEN updates
// chunked at 200 ids per query. Returns quoteid => previous owner for every row actually
// changed, so a caller can log what moved.
//
// Two columns, never both: vtiger_quotes_info carries assigned_to_sales_agent and
// assigned_to_external_sales_agent as genuinely independent fields, and an owner belongs
// in exactly one of them (tdu_title_is_external()). The column that is not this owner's
// is left exactly as found, whatever it holds: it is the only surviving record of whoever
// held the quote before, and correcting a field we are not responsible for would erase it.
//
// $exempt_fullnames: a quote whose CURRENT owner is in this list is left alone even if it
// disagrees with its region's owner. This is how a supervisor's personal claim survives
// the nightly correction pass without a separate "claims" table: "claimed" is derived from
// whoever the ownership columns already say, nothing more. Checked against both columns,
// so a claim by an external claimant is honoured the same way an internal one is.
function correct_region_ownership(mysqli $conn, array $rows, array $exempt_fullnames = []): array
{
    $exempt  = array_flip($exempt_fullnames);
    $changed = [];
    $updates = []; // column => [quoteid => new owner full name]
    foreach ($rows as $row) {
        $region = $row['queue_region'] ?? null;
        if ($region === null) continue; // unroutable, nothing to correct it to
        $owner_fullname = tdu_region_owner_name($region);
        if ($owner_fullname === null) continue; // unowned region, write nothing

        $column  = tdu_owner_column(tdu_region_owner_is_external($region));
        $current = ($column === 'assigned_to_external_sales_agent')
            ? ($row['external_owner'] ?? '')
            : ($row['owner'] ?? '');

        if ($current === $owner_fullname) continue; // already correct
        // Either column naming a claim holder means the quote is claimed, whichever
        // column that holder's own name lives in.
        if (isset($exempt[$row['owner'] ?? ''])) continue;
        if (isset($exempt[$row['external_owner'] ?? ''])) continue;

        $qid = (int) $row['quoteid'];
        $updates[$column][$qid] = $owner_fullname;
        $changed[$qid] = $current;
    }
    if (empty($updates)) return $changed;

    foreach ($updates as $column => $column_updates) {
        foreach (array_chunk($column_updates, 200, true) as $chunk) {
            $cases = [];
            $ids   = [];
            foreach ($chunk as $qid => $fullname) {
                $name_esc = $conn->real_escape_string($fullname);
                $cases[]  = "WHEN $qid THEN '$name_esc'";
                $ids[]    = $qid;
            }
            $conn->query(
                "UPDATE vtiger_quotes_info
                    SET $column = CASE quoteid " . implode(' ', $cases) . " END
                  WHERE quoteid IN (" . implode(',', $ids) . ")"
            );
        }
    }

    return $changed;
}

// Region equivalent of write_daily_runs(): same frozen-snapshot, first-load-only guard,
// same bucket/position shape, writing to tdu_region_daily_runs instead. owner_user_name
// is a point-in-time snapshot of the region's owner at the moment this row is written
// (NULL if the region has no owner) — not a live join, so a later ownership change never
// rewrites what this row says it was. Nothing rewrites old daily_runs; the two tables
// stay fully independent, so the column holding "who" is never ambiguous between a
// person and a region.
function write_region_daily_runs(
    mysqli $conn,
    string $run_date,
    string $region,
    array $built,
    array $carryover = [],
    array $sp_buckets = ['sp1', 'sp2', 'sp3', 'sp4'],
    string $carryover_bucket = 'carryover',
    ?string $owner_uname_override = null
): void
{
    $rg = $conn->real_escape_string($region);
    $rd = $conn->real_escape_string($run_date);

    $own_buckets = array_merge($sp_buckets, [$carryover_bucket]);
    $b_in = "'" . implode("','", array_map(fn($b) => $conn->real_escape_string($b), $own_buckets)) . "'";
    $check = $conn->query("SELECT 1 FROM tdu_region_daily_runs WHERE run_date = '$rd' AND queue_region = '$rg' AND bucket IN ($b_in) LIMIT 1");
    if ($check && $check->num_rows > 0) { $check->free(); return; }
    $check && $check->free();

    // REGION_OWNERS is keyed by real queue regions only; a personal-claim sentinel
    // ('personal:<user>') has no entry there, so its owner is passed in explicitly.
    $owner_uname = $owner_uname_override ?? (REGION_OWNERS[$region]['user_name'] ?? null);
    $owner_sql   = $owner_uname === null ? 'NULL' : "'" . $conn->real_escape_string($owner_uname) . "'";

    $conn->begin_transaction();

    $pos = 1;
    foreach ($sp_buckets as $bucket) {
        foreach ($built[$bucket] as $org_quotes) {
            foreach ($org_quotes as $q) {
                $qid = (int) $q['quoteid'];
                $ok = $conn->query(
                    "INSERT IGNORE INTO tdu_region_daily_runs
                        (run_date, queue_region, bucket, item_type, item_id, position, owner_user_name)
                     VALUES ('$rd', '$rg', '" . $conn->real_escape_string($bucket) . "', 'quote', $qid, $pos, $owner_sql)"
                );
                if (!$ok) {
                    $conn->rollback();
                    error_log('[TDU Queue] write_region_daily_runs INSERT failed for quoteid ' . $qid . ': ' . $conn->error);
                    tdu_config_fatal('Service temporarily unavailable. Please contact the administrator.');
                }
                $pos++;
            }
        }
    }

    foreach ($carryover as $org_quotes) {
        foreach ($org_quotes as $q) {
            $qid = (int) $q['quoteid'];
            $ok = $conn->query(
                "INSERT IGNORE INTO tdu_region_daily_runs
                    (run_date, queue_region, bucket, item_type, item_id, position, owner_user_name)
                 VALUES ('$rd', '$rg', '" . $conn->real_escape_string($carryover_bucket) . "', 'quote', $qid, $pos, $owner_sql)"
            );
            if (!$ok) {
                $conn->rollback();
                error_log('[TDU Queue] write_region_daily_runs carryover INSERT failed for quoteid ' . $qid . ': ' . $conn->error);
                tdu_config_fatal('Service temporarily unavailable. Please contact the administrator.');
            }
            $pos++;
        }
    }

    $conn->commit();
}

// Region equivalent of load_from_daily_runs(). Keyed by queue_region, not by fullname:
// a person can own two regions (Mayur: West + Gujarat) that would share one fullname key
// and silently collide if this were keyed the way the old per-caller version is.
// $stage_outcomes/$closure_table/$closure_outcome default to Follow-up's own, same as the
// old function. Leads passes its own, so this one function serves both queues,
// distinguished only by which buckets/table/map they pass in.
function load_region_from_daily_runs(
    mysqli $conn,
    string $run_date,
    array $regions,
    array $sp_buckets = ['sp1', 'sp2', 'sp3', 'sp4'],
    string $carryover_bucket = 'carryover',
    ?array $stage_outcomes = null,
    string $closure_table   = 'tdu_quote_closure_feedback',
    string $closure_outcome = 'rejected'
): array
{
    if (empty($regions)) return ['found' => false, 'built' => [], 'worked_today' => [], 'carryover' => []];

    $regions_in  = "'" . implode("','", array_map(fn($r) => $conn->real_escape_string($r), $regions)) . "'";
    $rd          = $conn->real_escape_string($run_date);
    $own_buckets = array_merge($sp_buckets, [$carryover_bucket]);
    $b_in        = "'" . implode("','", array_map(fn($b) => $conn->real_escape_string($b), $own_buckets)) . "'";

    $result = $conn->query("
        SELECT queue_region, bucket, item_id AS quoteid, position
        FROM tdu_region_daily_runs
        WHERE run_date = '$rd'
          AND queue_region IN ($regions_in)
          AND item_type = 'quote'
          AND bucket IN ($b_in)
        ORDER BY queue_region, bucket, position
    ");
    if (!$result || $result->num_rows === 0) {
        $result && $result->free();
        return ['found' => false, 'built' => [], 'worked_today' => [], 'carryover' => []];
    }
    $runs    = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    $sp_runs = array_values(array_filter($runs, fn($r) => in_array($r['bucket'], $sp_buckets, true)));
    $co_runs = array_values(array_filter($runs, fn($r) => $r['bucket'] === $carryover_bucket));

    $ids_in = implode(',', array_map(fn($r) => (int)$r['quoteid'], $runs));
    if (empty($ids_in)) return ['found' => false, 'built' => [], 'worked_today' => [], 'carryover' => []];

    // Live quote details — identical shape to load_from_daily_runs's own query.
    $result = $conn->query("
        SELECT
            vq.quoteid,
            vq.quote_no,
            vq.subject,
            vq.quotestage,
            vq.adults,
            vq.children,
            vq.infants,
            vq.created_at,
            vqcf.cf_1162                            AS trip_start_date,
            va.organizationid,
            va.organization_name,
            vcd.name                                AS contactname,
            vcd.mobile                              AS contactmobile,
            vqinfo.assigned_to_region,
            vqinfo.assigned_to_sales_agent          AS db_owner,
            vqinfo.priority,
            COALESCE(cc.call_count, 0)              AS call_count,
            sched.next_call_date,
            ext.snooze_until,
            call_rec.outcome                        AS last_outcome
        FROM vtiger_quotes vq
        LEFT JOIN vtiger_quotescf vqcf       ON vq.quoteid = vqcf.quoteid
        LEFT JOIN vtiger_quotes_info vqinfo  ON vq.quoteid = vqinfo.quoteid
        LEFT JOIN tdu_organisation va        ON vq.accountid = va.organizationid
        LEFT JOIN tdu_contacts vcd           ON vq.contactid = vcd.auto_id
            AND va.organizationid = vcd.organizationid
        LEFT JOIN (
            SELECT quoteid, COUNT(*) AS call_count, MAX(auto_id) AS max_id
            FROM vtiger_quotes_followup
            WHERE followup_type = 'call_info'
            GROUP BY quoteid
        ) cc ON cc.quoteid = vq.quoteid
        LEFT JOIN vtiger_quotes_followup call_rec ON call_rec.auto_id = cc.max_id
        LEFT JOIN tdu_quotes_followup_ext ext      ON ext.followup_id = cc.max_id
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
        WHERE vq.quoteid IN ($ids_in)
          AND vq.deleted = 0
    ");
    $quotes_by_id = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $quotes_by_id[(int)$row['quoteid']] = $row;
        }
        $result->free();
    }

    // worked_today — identical query/shape to load_from_daily_runs's own.
    $worked_today = [];
    $result = $conn->query("
        SELECT f.quoteid, f.auto_id AS followup_id, f.description AS notes,
               f.outcome AS outcome,
               COALESCE(
                 (SELECT s.next_follow_up_date
                    FROM vtiger_quotes_followup s
                   WHERE s.quoteid = f.quoteid AND s.auto_id > f.auto_id
                     AND s.followup_type = 'schedule_follow_up'
                   ORDER BY s.auto_id LIMIT 1),
                 (SELECT s2.next_follow_up_date
                    FROM vtiger_quotes_followup s2
                   WHERE s2.quoteid = f.quoteid AND s2.auto_id > lw.min_id
                     AND s2.followup_type = 'schedule_follow_up'
                   ORDER BY s2.auto_id LIMIT 1)
               ) AS next_date,
               e.channel AS channel
        FROM vtiger_quotes_followup f
        LEFT JOIN tdu_quotes_followup_ext e ON e.followup_id = f.auto_id
        INNER JOIN (
            SELECT c.quoteid, MAX(c.auto_id) AS max_id, MIN(c.auto_id) AS min_id,
                   MAX(CASE WHEN x.schedule_followup_id IS NOT NULL THEN c.auto_id END) AS owner_id
            FROM vtiger_quotes_followup c
            LEFT JOIN tdu_quotes_followup_ext x ON x.followup_id = c.auto_id
            WHERE DATE(c.calltime) = '$rd'
              AND c.outcome IS NOT NULL
              AND c.quoteid IN ($ids_in)
            GROUP BY c.quoteid
        ) lw ON f.auto_id = COALESCE(lw.owner_id, lw.max_id)
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            if (!worked_date_is_set($row['next_date'] ?? null)) continue;
            $worked_today[(int)$row['quoteid']] = [
                'followup_id' => (int) $row['followup_id'],
                'notes'       => $row['notes'] ?? '',
                'outcome'     => $row['outcome'] ?? '',
                'next_date'   => $row['next_date'] ?? null,
                'channel'     => $row['channel'] ?? '',
            ];
        }
        $result->free();
    }

    foreach (load_closure_worked_today($conn, $rd, $ids_in, $closure_table, $closure_outcome) as $qid => $entry) {
        if (!isset($worked_today[$qid])) $worked_today[$qid] = $entry;
    }
    foreach (load_stage_change_worked_today($conn, $rd, $ids_in, $stage_outcomes) as $qid => $entry) {
        if (!isset($worked_today[$qid])) $worked_today[$qid] = $entry;
    }

    $runs_indexed = [];
    foreach ($sp_runs as $run) {
        $qid = (int)$run['quoteid'];
        $runs_indexed[$run['queue_region']][$run['bucket']][] = $qid;
    }

    $built = [];
    foreach ($runs_indexed as $region => $buckets) {
        $owner_fullname = tdu_region_owner_name($region);
        $built[$region] = ['sp1' => [], 'sp2' => [], 'sp3' => [], 'sp4' => []];

        foreach ($sp_buckets as $bucket) {
            if (empty($buckets[$bucket])) continue;

            $org_order  = [];
            $org_groups = [];
            foreach ($buckets[$bucket] as $qid) {
                $q = $quotes_by_id[$qid] ?? null;
                if ($q === null) continue; // quote deleted from CRM since morning load

                // Empty, not a db_owner fallback, when the region has no owner: an unowned
                // region must read as visibly unowned, not show whatever
                // stale name happens to sit in the CRM's own assigned_to_sales_agent.
                $q['owner']        = $owner_fullname ?? '';
                $q['queue_region'] = $region;
                $q['worked_today']       = isset($worked_today[$qid]);
                $q['worked_followup_id'] = $worked_today[$qid]['followup_id'] ?? null;
                $q['worked_notes']       = $worked_today[$qid]['notes'] ?? '';
                $q['worked_outcome']     = $worked_today[$qid]['outcome'] ?? '';
                $q['worked_next_date']   = $worked_today[$qid]['next_date'] ?? null;
                $q['worked_channel']     = $worked_today[$qid]['channel'] ?? '';

                $orgid = $q['organizationid'] ?? 'unknown';
                if (!isset($org_groups[$orgid])) {
                    $org_order[]        = $orgid;
                    $org_groups[$orgid] = [];
                }
                $org_groups[$orgid][] = $q;
            }

            $built[$region][$bucket] = array_map(fn($oid) => $org_groups[$oid], $org_order);
        }
    }

    // Reconstruct frozen carry-over, keyed by region — same "days_behind" re-anchoring
    // as load_from_daily_runs, reading tdu_region_daily_runs instead of daily_runs.
    $carryover_by_region = [];
    if (!empty($co_runs)) {
        $co_qids_by_region = [];
        foreach ($co_runs as $run) {
            $co_qids_by_region[$run['queue_region']][] = (int)$run['quoteid'];
        }
        $all_co_qids = implode(',', array_unique(array_merge(...array_values($co_qids_by_region))));
        if (!empty($all_co_qids)) {
            $run_dates_by_qid = [];
            $rd_res = $conn->query("
                SELECT item_id, run_date
                FROM tdu_region_daily_runs
                WHERE run_date < '$rd'
                  AND item_type = 'quote'
                  AND item_id IN ($all_co_qids)
                ORDER BY run_date
            ");
            if ($rd_res) {
                while ($row = $rd_res->fetch_assoc()) $run_dates_by_qid[(int)$row['item_id']][] = $row['run_date'];
                $rd_res->free();
            }
            $first_pending = resolve_first_pending($conn, $run_date, $run_dates_by_qid);

            foreach ($co_qids_by_region as $region => $qids) {
                $owner_fullname = tdu_region_owner_name($region);
                $by_org = [];
                foreach ($qids as $qid) {
                    $q = $quotes_by_id[$qid] ?? null;
                    if ($q === null) continue;

                    $anchor = $first_pending[$qid] ?? $rd;
                    $ncd    = $q['next_call_date'] ?? null;
                    if ($ncd && $ncd !== '0000-00-00' && $ncd < $anchor) $anchor = $ncd;
                    $days_behind = max(0, (int) floor((strtotime($rd) - strtotime($anchor)) / 86400));

                    $q['days_behind']        = $days_behind;
                    $q['worked_today']       = isset($worked_today[$qid]);
                    $q['worked_followup_id'] = $worked_today[$qid]['followup_id'] ?? null;
                    $q['worked_notes']       = $worked_today[$qid]['notes'] ?? '';
                    $q['worked_outcome']     = $worked_today[$qid]['outcome'] ?? '';
                    $q['worked_next_date']   = $worked_today[$qid]['next_date'] ?? null;
                    $q['worked_channel']     = $worked_today[$qid]['channel'] ?? '';
                    // Current owner, not the frozen owner_user_name column on this row (that
                    // point-in-time snapshot exists for later historical/audit reads, not
                    // wired into display yet — nothing is lost, it stays in the table).
                    // Empty, not a db_owner fallback, when the region is unowned right now.
                    $q['owner']              = $owner_fullname ?? '';
                    $q['queue_region']       = $region;

                    $orgid = $q['organizationid'] ?? 'unknown';
                    $by_org[$orgid][] = $q;
                }
                $carryover_by_region[$region] = array_values($by_org);
            }
        }
    }

    return ['found' => true, 'built' => $built, 'worked_today' => $worked_today, 'carryover' => $carryover_by_region];
}

// Region equivalent of load_carryover_quotes(). Keyed by queue_region throughout, reading
// tdu_region_daily_runs instead of daily_runs. Same snowball semantics: a quote is
// carry-over if it appeared on any PRIOR day, was not worked since, and is still in the
// active cohort. $q['owner'] is left as the live CRM value (vqinfo.assigned_to_sales_agent)
// exactly as the original does — not overridden to a region's owner name, since a stale
// carry-over row must still show who the CRM says owns it today.
function load_region_carryover_quotes(
    mysqli $conn,
    string $today,
    array $regions,
    array $buckets = ['sp1', 'sp2', 'sp3', 'sp4', 'carryover'],
    array $stages  = ['Created', 'Requote'],
    ?array $stage_outcomes  = null,
    string $closure_table   = 'tdu_quote_closure_feedback',
    string $closure_outcome = 'rejected'
): array
{
    if (empty($regions)) return [];

    $regions_esc = implode(',', array_map(fn($r) => "'" . $conn->real_escape_string($r) . "'", $regions));
    $rd          = $conn->real_escape_string($today);
    $b_in        = "'" . implode("','", array_map(fn($b) => $conn->real_escape_string($b), $buckets)) . "'";
    $stages_in   = "'" . implode("','", array_map(fn($s) => $conn->real_escape_string($s), $stages)) . "'";

    $res = $conn->query("
        SELECT queue_region, item_id, run_date
        FROM tdu_region_daily_runs
        WHERE run_date < '$rd'
          AND queue_region IN ($regions_esc)
          AND item_type = 'quote'
          AND bucket IN ($b_in)
        ORDER BY run_date
    ");
    if (!$res) return [];

    $run_dates_by_qid = [];
    $region_by_qid    = [];
    while ($row = $res->fetch_assoc()) {
        $qid = (int)$row['item_id'];
        $run_dates_by_qid[$qid][] = $row['run_date'];
        $region_by_qid[$qid]      = $row['queue_region']; // ORDER BY run_date → last wins
    }
    $res->free();
    if (empty($run_dates_by_qid)) return [];

    $ids_in = implode(',', array_keys($run_dates_by_qid));
    if (empty($ids_in)) return [];

    $first_pending_by_qid = resolve_first_pending($conn, $today, $run_dates_by_qid);

    // Same shape as load_carryover_quotes()'s own worked-today read.
    $worked_today = [];
    $res3 = $conn->query("
        SELECT f.quoteid, f.auto_id AS followup_id, f.description AS notes,
               f.outcome AS outcome,
               COALESCE(
                 (SELECT s.next_follow_up_date
                    FROM vtiger_quotes_followup s
                   WHERE s.quoteid = f.quoteid AND s.auto_id > f.auto_id
                     AND s.followup_type = 'schedule_follow_up'
                   ORDER BY s.auto_id LIMIT 1),
                 (SELECT s2.next_follow_up_date
                    FROM vtiger_quotes_followup s2
                   WHERE s2.quoteid = f.quoteid AND s2.auto_id > lw.min_id
                     AND s2.followup_type = 'schedule_follow_up'
                   ORDER BY s2.auto_id LIMIT 1)
               ) AS next_date,
               e.channel AS channel
        FROM vtiger_quotes_followup f
        LEFT JOIN tdu_quotes_followup_ext e ON e.followup_id = f.auto_id
        INNER JOIN (
            SELECT c.quoteid, MAX(c.auto_id) AS max_id, MIN(c.auto_id) AS min_id,
                   MAX(CASE WHEN x.schedule_followup_id IS NOT NULL THEN c.auto_id END) AS owner_id
            FROM vtiger_quotes_followup c
            LEFT JOIN tdu_quotes_followup_ext x ON x.followup_id = c.auto_id
            WHERE c.calltime >= '$rd 00:00:00'
              AND c.calltime < DATE_ADD('$rd 00:00:00', INTERVAL 1 DAY)
              AND c.outcome IS NOT NULL
              AND c.quoteid IN ($ids_in)
            GROUP BY c.quoteid
        ) lw ON f.auto_id = COALESCE(lw.owner_id, lw.max_id)
    ");
    if ($res3) {
        while ($row = $res3->fetch_assoc()) {
            if (!worked_date_is_set($row['next_date'] ?? null)) continue;
            $worked_today[(int)$row['quoteid']] = [
                'followup_id' => (int) $row['followup_id'],
                'notes'       => $row['notes'] ?? '',
                'outcome'     => $row['outcome'] ?? '',
                'next_date'   => $row['next_date'] ?? null,
                'channel'     => $row['channel'] ?? '',
            ];
        }
        $res3->free();
    }

    foreach (load_closure_worked_today($conn, $today, $ids_in, $closure_table, $closure_outcome) as $qid => $entry) {
        if (!isset($worked_today[$qid])) $worked_today[$qid] = $entry;
    }
    foreach (load_stage_change_worked_today($conn, $today, $ids_in, $stage_outcomes) as $qid => $entry) {
        if (!isset($worked_today[$qid])) $worked_today[$qid] = $entry;
    }

    $pending = [];
    foreach ($first_pending_by_qid as $qid => $fp) {
        if ($fp === null) continue;
        $pending[$qid] = ['first_pending' => $fp, 'region' => $region_by_qid[$qid]];
    }
    if (empty($pending)) return [];

    $pending_in = implode(',', array_keys($pending));

    $res4 = $conn->query("
        SELECT
            vq.quoteid, vq.quote_no, vq.subject, vq.quotestage,
            vq.adults, vq.children, vq.infants, vq.created_at,
            va.organization_name, va.organizationid,
            vcd.name  AS contactname,
            vcd.mobile AS contactmobile,
            vqcf.cf_1162 AS trip_start_date,
            vqinfo.assigned_to_sales_agent AS owner,
            vqinfo.assigned_to_region,
            vqinfo.priority,
            COALESCE(vqf_cnt.call_count, 0) AS call_count,
            sched.next_call_date
        FROM vtiger_quotes vq
        LEFT JOIN vtiger_quotescf vqcf        ON vq.quoteid = vqcf.quoteid
        LEFT JOIN vtiger_quotes_info vqinfo   ON vq.quoteid = vqinfo.quoteid
        LEFT JOIN tdu_organisation va         ON vq.accountid = va.organizationid
        LEFT JOIN tdu_contacts vcd            ON vq.contactid = vcd.auto_id
            AND va.organizationid = vcd.organizationid
        LEFT JOIN (
            SELECT quoteid, COUNT(*) AS call_count
            FROM vtiger_quotes_followup
            WHERE followup_type = 'call_info'
              AND quoteid IN ($pending_in)
            GROUP BY quoteid
        ) vqf_cnt ON vqf_cnt.quoteid = vq.quoteid
        LEFT JOIN (
            SELECT f.quoteid, DATE(f.next_follow_up_date) AS next_call_date
            FROM vtiger_quotes_followup f
            INNER JOIN (
                SELECT quoteid, MAX(auto_id) AS max_id
                FROM vtiger_quotes_followup
                WHERE followup_type = 'schedule_follow_up'
                  AND (followup IS NULL OR followup != 'checked')
                  AND quoteid IN ($pending_in)
                GROUP BY quoteid
            ) ls ON f.auto_id = ls.max_id
        ) sched ON sched.quoteid = vq.quoteid
        WHERE vq.quoteid IN ($pending_in)
          AND vq.deleted = 0
          AND vq.quotestage IN ($stages_in)
          AND vqcf.cf_1162 > '$rd'
    ");
    $details = [];
    if ($res4) {
        while ($row = $res4->fetch_assoc()) $details[(int)$row['quoteid']] = $row;
        $res4->free();
    }

    $by_region = [];
    foreach ($pending as $qid => $meta) {
        if (!isset($details[$qid])) continue; // left the cohort → drop silently
        $q = $details[$qid];

        $anchor = $meta['first_pending'];
        $ncd    = $q['next_call_date'] ?? null;
        if ($ncd && $ncd !== '0000-00-00' && $ncd < $anchor) $anchor = $ncd;
        $days_behind = (int) floor((strtotime($today) - strtotime($anchor)) / 86400);

        $q['days_behind']        = max(0, $days_behind);
        $q['worked_today']       = isset($worked_today[$qid]);
        $q['worked_followup_id'] = $worked_today[$qid]['followup_id'] ?? null;
        $q['worked_notes']       = $worked_today[$qid]['notes'] ?? '';
        $q['worked_outcome']     = $worked_today[$qid]['outcome'] ?? '';
        $q['worked_next_date']   = $worked_today[$qid]['next_date'] ?? null;
        $q['worked_channel']     = $worked_today[$qid]['channel'] ?? '';
        $q['queue_region']       = $meta['region'];
        $by_region[$meta['region']][$qid] = $q;
    }

    $result = [];
    foreach ($by_region as $region => $quotes) {
        $by_org = [];
        foreach ($quotes as $q) {
            $by_org[$q['organizationid'] ?? 'unknown'][] = $q;
        }
        foreach ($by_org as &$grp) {
            usort($grp, fn($a, $b) => $b['days_behind'] <=> $a['days_behind']);
        }
        unset($grp);
        uasort($by_org, function ($a, $b) {
            return max(array_column($b, 'days_behind')) <=> max(array_column($a, 'days_behind'));
        });
        $result[$region] = array_values($by_org);
    }
    return $result;
}

// Builds and freezes a group of regions as one unit: fetch -> partition -> weight ->
// blended budget -> split back -> classify -> write -> correct ownership. "One unit"
// matters — a group is always one person's full set of regions (or, for an unowned
// region, a group of one), because the budget math in person_followup_budget() only
// makes sense computed across a person's whole day at once, never region by region.
// Shared by region_queue.php's own first-load path and cron_daily_runs.php, which calls
// this once per owner (grouping their regions together) plus once per unowned region
// (a group of one, where the split degenerates to that region keeping its own full share).
// Returns ['built' => region => build_caller_queue() result, 'carryover' => region => ...].
function build_and_write_regions(mysqli $conn, string $today, array $regions): array
{
    if (empty($regions)) return ['built' => [], 'carryover' => []];

    $carryover_raw = load_region_carryover_quotes($conn, $today, $regions);
    $carryover_ids = [];
    foreach ($carryover_raw as $_orgs) {
        foreach ($_orgs as $_og) {
            foreach ($_og as $_q) $carryover_ids[(int)$_q['quoteid']] = true;
        }
    }

    $all_rows = fetch_region_cohort($conn, $today, INDIA_REGIONS);

    $rows_by_region = [];
    foreach ($all_rows as $row) {
        $region = $row['queue_region'];
        if ($region === null || !in_array($region, $regions, true)) continue;
        if (isset($carryover_ids[(int)$row['quoteid']])) continue;
        $rows_by_region[$region][] = $row;
    }

    $weights = [];
    foreach ($regions as $region) {
        $weights[$region] = region_actionable_weight($rows_by_region[$region] ?? [], $today);
    }
    $budget = person_followup_budget($regions, $weights);
    $slots  = split_budget_across_regions($budget, $weights);

    $built           = [];
    $classified_rows = [];
    foreach ($regions as $region) {
        $region_built = build_caller_queue($rows_by_region[$region] ?? [], $today, $slots[$region] ?? 0);
        $built[$region] = $region_built;

        foreach (['sp1', 'sp2', 'sp3', 'sp4'] as $sp) {
            foreach ($region_built[$sp] as $org_quotes) {
                foreach ($org_quotes as $q) $classified_rows[] = $q;
            }
        }

        write_region_daily_runs($conn, $today, $region, $region_built, $carryover_raw[$region] ?? []);
    }

    // correct_region_ownership bounded to today's classified quoteids plus carry-over — the
    // full-cohort pass runs separately in the cron, before anyone opens the page.
    $carryover_rows_flat = [];
    foreach ($carryover_raw as $_orgs) {
        foreach ($_orgs as $_og) {
            foreach ($_og as $_q) $carryover_rows_flat[] = $_q;
        }
    }
    // Always exempt every personal-claim holder's own name, whether this call came from the
    // cron or an interactive page load: a region's own build must never claw back a quote
    // Karthik pulled to himself just because it still carries that region's queue_region.
    correct_region_ownership($conn, array_merge($classified_rows, $carryover_rows_flat), array_values(tdu_personal_claim_users()));

    // Display owner, set only now — after correct_region_ownership() has already read
    // and compared each row's original live-CRM owner above. Overwriting any earlier
    // would make every row look "already correct" and the correction would never fire.
    // Matches load_region_from_daily_runs()'s reload display exactly: the region's
    // current configured owner, empty (not a stale CRM name) when unowned.
    foreach ($regions as $region) {
        $owner_fullname = tdu_region_owner_name($region);
        foreach (['sp1', 'sp2', 'sp3', 'sp4'] as $sp) {
            foreach ($built[$region][$sp] as &$org_quotes) {
                foreach ($org_quotes as &$q) {
                    $q['owner'] = $owner_fullname ?? '';
                }
                unset($q);
            }
            unset($org_quotes);
        }
    }

    return ['built' => $built, 'carryover' => $carryover_raw];
}

// A supervisor-with-no-region's personal working queue. Same shape as
// build_and_write_regions() for one region, minus the multi-region weight/budget/split
// machinery: one person, one flat 42-slot budget, no blending needed. Written into the
// same tdu_region_daily_runs table
// under the sentinel queue_region 'personal:<user_name>', so load_region_from_daily_runs()
// and load_region_carryover_quotes() serve it back with no changes of their own — both
// already treat queue_region as an opaque string.
// Cohort = fetch_region_cohort()'s full India Created/Requote cohort, filtered in PHP to
// rows whose CURRENT owner is this person's full name — reused rather than re-queried,
// since "claimed" is derived from assigned_to_sales_agent, not a separate table. Follow-up
// only (Leads pull is out of scope, confirmed 2026-08-27): a Lead never appears here.
function build_and_write_personal_claims(mysqli $conn, string $today, string $user_name, string $fullname): array
{
    $region = 'personal:' . $user_name;

    $carryover_raw = load_region_carryover_quotes($conn, $today, [$region]);
    $carryover_ids = [];
    foreach ($carryover_raw[$region] ?? [] as $_og) {
        foreach ($_og as $_q) $carryover_ids[(int)$_q['quoteid']] = true;
    }

    // Which column carries this claimant's name depends on their own CRM account type,
    // the same rule ajax_claim_quote.php writes by. Reading the wrong one returns an
    // empty queue with no error rather than a visible failure.
    $claim_field = tdu_person_is_external($user_name) ? 'external_owner' : 'owner';

    $all_rows = fetch_region_cohort($conn, $today, INDIA_REGIONS);
    $my_rows  = [];
    foreach ($all_rows as $row) {
        if (($row[$claim_field] ?? '') !== $fullname) continue;
        if (isset($carryover_ids[(int)$row['quoteid']])) continue;
        $my_rows[] = $row;
    }

    $budget = (int) floor(DAILY_CAPACITY * INDIA_FOLLOWUP_PCT); // same 42 every region owner gets, confirmed 2026-08-27
    $built  = build_caller_queue($my_rows, $today, $budget);

    write_region_daily_runs($conn, $today, $region, $built, $carryover_raw[$region] ?? [], ['sp1', 'sp2', 'sp3', 'sp4'], 'carryover', $user_name);

    // No correct_region_ownership() call here: a personal claim's owner IS whatever
    // assigned_to_sales_agent already says (that is the exemption's whole premise), so
    // there is nothing to correct on this pass. The region-side correction passes are what
    // keep everyone ELSE's ownership in line while this claim stays exempt.

    foreach (['sp1', 'sp2', 'sp3', 'sp4'] as $sp) {
        foreach ($built[$sp] as &$org_quotes) {
            foreach ($org_quotes as &$q) $q['owner'] = $fullname;
            unset($q);
        }
        unset($org_quotes);
    }

    return ['built' => [$region => $built], 'carryover' => [$region => $carryover_raw[$region] ?? []]];
}
