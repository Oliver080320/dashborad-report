<?php
// Monitoring dashboard — shared SQL/aggregation logic. One function per chart/section,
// mirroring queue_builder.php's pattern. No SQL lives in monitoring.php or the view.

// Resolves ?callers= (comma-separated user_names) against the configured caller map.
// Empty/invalid input returns everyone (safe default, mirrors queue.php's ?user= fallback).
function resolve_callers_filter(string $requested_csv, array $callers_map): array
{
    $requested_csv = trim($requested_csv);
    if ($requested_csv === '') return array_keys($callers_map);

    $requested = array_filter(array_map('trim', explode(',', $requested_csv)));
    $valid     = array_values(array_intersect($requested, array_keys($callers_map)));

    return $valid ?: array_keys($callers_map);
}

// Bulk-fetches "worked" (quoteid, date) pairs across the three tables that can record a worked
// outcome (call outcome, rejection, accept/requote stage change), for dates in
// [$date_from_sql, $date_to_sql] (raw SQL date literals/expressions). Returns a lookup set keyed
// 'quoteid|Y-m-d' => true. Fetches each table ONCE rather than a per-row correlated EXISTS
// (the old worked_exists_fragment() approach), which measured ~40ms/row across a date range and
// blew past the gateway timeout — see git history if reviving that pattern.
function fetch_worked_set(mysqli $conn, string $date_from_sql, string $date_to_sql): array
{
    $set = [];
    $sources = [
        "SELECT DISTINCT quoteid, DATE(calltime) AS d FROM vtiger_quotes_followup
         WHERE outcome IS NOT NULL AND DATE(calltime) BETWEEN $date_from_sql AND $date_to_sql",
        "SELECT DISTINCT quoteid, DATE(created_at) AS d FROM tdu_quote_closure_feedback
         WHERE DATE(created_at) BETWEEN $date_from_sql AND $date_to_sql",
        "SELECT DISTINCT quoteid, DATE(created_at) AS d FROM vtiger_quote_stage_track
         WHERE stage IN ('Change Stage to Accepted', 'Change Stage to Requote')
           AND DATE(created_at) BETWEEN $date_from_sql AND $date_to_sql",
    ];
    foreach ($sources as $sql) {
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) $set[$row['quoteid'] . '|' . $row['d']] = true;
            $res->free();
        }
    }
    return $set;
}

// Builds a safely-escaped SQL IN (...) value list, without the surrounding parens (callers
// write `IN ($list)`).
function sql_in_list(mysqli $conn, array $values): string
{
    return "'" . implode("','", array_map(fn($v) => $conn->real_escape_string((string)$v), $values)) . "'";
}

// Some organisation_name values carry legacy Windows-1252 bytes that are invalid UTF-8, which
// makes json_encode() return false for the WHOLE structure, not just the bad row. Normalise at
// read time so the name renders correctly instead of relying on JSON_INVALID_UTF8_SUBSTITUTE alone.
function utf8_safe(?string $value): ?string
{
    if ($value === null || $value === '' || mb_check_encoding($value, 'UTF-8')) return $value;
    return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
}

// File-based cache for the two builders that stayed expensive after query tuning (capacity vs
// demand, and the carry-over recompute behind the KPI strip and the backlog trend, both of which
// walk a large calltime range). Only wraps THIS
// dashboard's own calls — never the live caller queue's load_carryover_quotes(), which must stay
// fresh. Fails open: any filesystem problem just runs $fn() live instead of breaking the page.
function cached_call(string $key, int $ttl_seconds, callable $fn)
{
    $dir  = __DIR__ . '/cache';
    $file = $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.json';

    if (is_file($file) && (time() - filemtime($file)) < $ttl_seconds) {
        $cached = @file_get_contents($file);
        if ($cached !== false) {
            $data = json_decode($cached, true);
            if (json_last_error() === JSON_ERROR_NONE) return $data;
        }
    }

    $result = $fn();

    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($file, json_encode($result), LOCK_EX);
    }

    return $result;
}

// Translates login user_names to full-name form for querying created_by (stores "First Last",
// never the login). Returns the escaped IN (...) list AND the reverse lookup (full_name => login).
function resolve_fullnames_for_query(mysqli $conn, array $user_names, array $callers_map): array
{
    $fullnames = array_map(fn($u) => $callers_map[$u], $user_names);
    return [
        'in_list'          => sql_in_list($conn, $fullnames),
        'fullname_to_user' => array_flip($callers_map),
    ];
}

// KPI strip: today's snapshot, broken down PER CALLER so the browser can recombine it for
// whatever the caller filter has selected, with no reload. $user_names is every configured
// caller, not just the current filter selection. $carryover_quotes is the live-recomputed set
// from load_carryover_quotes(), shared with build_backlog_age_today(). Returns raw counts, not
// pct_worked/contact_rate — percentages can't be recombined by averaging, the browser derives
// them from the summed counts.
function build_kpi_by_caller(mysqli $conn, string $today, array $user_names, array $callers_map, array $carryover_quotes): array
{
    $out = [];
    foreach ($user_names as $uname) {
        $out[$uname] = ['calls_today' => 0, 'surfaced' => 0, 'worked' => 0, 'backlog_now' => 0,
                         'backlog_oldest_days' => 0, 'reached' => 0, 'not_reached' => 0];
    }
    if (empty($user_names)) return $out;

    $fullnames_resolved = resolve_fullnames_for_query($conn, $user_names, $callers_map);
    $fullnames_in       = $fullnames_resolved['in_list'];
    $fullname_to_user   = $fullnames_resolved['fullname_to_user'];
    $names_in           = sql_in_list($conn, $user_names);
    $td                 = $conn->real_escape_string($today);

    // Calls made today, per caller.
    $res = $conn->query("
        SELECT created_by, COUNT(*) AS n FROM vtiger_quotes_followup
        WHERE followup_type = 'call_info' AND DATE(calltime) = '$td' AND created_by IN ($fullnames_in)
        GROUP BY created_by
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uname = $fullname_to_user[$row['created_by']] ?? null;
            if ($uname !== null) $out[$uname]['calls_today'] = (int)$row['n'];
        }
        $res->free();
    }

    // Surfaced (all buckets, including carry-over) vs worked today, per caller.
    $worked_set = fetch_worked_set($conn, "'$td'", "'$td'");
    $res = $conn->query("
        SELECT user_name, item_id FROM daily_runs
        WHERE run_date = '$td' AND user_name IN ($names_in) AND item_type = 'quote'
          AND bucket IN (" . sql_in_list($conn, followup_buckets()) . ")
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uname = $row['user_name'];
            if (!isset($out[$uname])) continue;
            $out[$uname]['surfaced']++;
            if (isset($worked_set[$row['item_id'] . '|' . $today])) $out[$uname]['worked']++;
        }
        $res->free();
    }

    // Backlog now: count + oldest age per caller, from the live carry-over recompute (same
    // data build_backlog_age_today() uses) rather than a separate daily_runs COUNT.
    foreach ($carryover_quotes as $uname => $org_groups) {
        if (!isset($out[$uname])) continue;
        foreach ($org_groups as $quotes) {
            foreach ($quotes as $q) {
                $out[$uname]['backlog_now']++;
                $out[$uname]['backlog_oldest_days'] = max($out[$uname]['backlog_oldest_days'], (int)($q['days_behind'] ?? 0));
            }
        }
    }

    // Contact rate inputs: reached / not-reached among today's calls, per caller.
    $res = $conn->query("
        SELECT created_by, outcome, COUNT(*) AS n FROM vtiger_quotes_followup
        WHERE followup_type = 'call_info' AND DATE(calltime) = '$td'
          AND created_by IN ($fullnames_in) AND outcome IS NOT NULL
        GROUP BY created_by, outcome
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uname = $fullname_to_user[$row['created_by']] ?? null;
            if ($uname === null) continue;
            if (in_array($row['outcome'], ['interested', 'next_call', 'inbound_call'], true)) $out[$uname]['reached'] += (int)$row['n'];
            elseif ($row['outcome'] === 'no_answer_email') $out[$uname]['not_reached'] += (int)$row['n'];
        }
        $res->free();
    }

    return $out;
}

// % queue worked, DAILY variant: surfaced vs worked per day, last $days_back days (default 14,
// matching the backlog trend's granularity). The calls-logged chart's "quotes worked" bar stays
// on the weekly fetch above; only this one moved to daily. Same shape, bucketed by run_date
// instead of week_start.
function fetch_daily_surfaced_worked(mysqli $conn, array $user_names, int $days_back = 14): array
{
    if (empty($user_names)) return [];
    $names_in = sql_in_list($conn, $user_names);
    $worked_set = fetch_worked_set($conn, "DATE_SUB(CURDATE(), INTERVAL $days_back DAY)", 'CURDATE()');

    $res = $conn->query("
        SELECT run_date, user_name, item_id
        FROM daily_runs
        WHERE item_type = 'quote'
          AND user_name IN ($names_in)
          AND bucket IN (" . sql_in_list($conn, followup_buckets()) . ")
          AND run_date >= DATE_SUB(CURDATE(), INTERVAL $days_back DAY)
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $day = $row['run_date'];
            $uname = $row['user_name'];
            if (!isset($out[$day][$uname])) $out[$day][$uname] = ['surfaced' => 0, 'worked' => 0];
            $out[$day][$uname]['surfaced']++;
            if (isset($worked_set[$row['item_id'] . '|' . $row['run_date']])) $out[$day][$uname]['worked']++;
        }
        $res->free();
    }
    return $out;
}

// Calls logged, DAILY variant: per caller per day, last $days_back days. "Quotes worked" in
// Total mode reuses fetch_daily_surfaced_worked() rather than duplicating that query.
function build_daily_calls_logged_trend(mysqli $conn, array $user_names, array $callers_map, int $days_back = 14): array
{
    if (empty($user_names)) return [];
    $resolved         = resolve_fullnames_for_query($conn, $user_names, $callers_map);
    $fullnames_in     = $resolved['in_list'];
    $fullname_to_user = $resolved['fullname_to_user'];

    $res = $conn->query("
        SELECT
            DATE(calltime) AS call_date,
            created_by,
            COUNT(*) AS n
        FROM vtiger_quotes_followup
        WHERE followup_type = 'call_info'
          AND created_by IN ($fullnames_in)
          AND calltime >= DATE_SUB(CURDATE(), INTERVAL $days_back DAY)
        GROUP BY call_date, created_by
        ORDER BY call_date, created_by
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uname = $fullname_to_user[$row['created_by']] ?? $row['created_by'];
            $out[$row['call_date']][$uname] = (int)$row['n'];
        }
        $res->free();
    }
    return $out;
}

// Accepted quotes per day, month-to-date, credited to the quote's CURRENT owner
// (assigned_to_sales_agent), not whoever executed the stage change — "how many acceptances does
// the team have day by day", not a per-agent productivity score. 'Accepted' is the literal stage
// name (every later stage necessarily passed through it first). Owners outside the configured
// callers are excluded entirely, not bucketed as "Other".
// $india_regions: same INDIA_REGIONS list the queues themselves use — defines the scope
// (both FIT and Groups & MICE quotes carry assigned_to_region within these, confirmed
// against groups_mice.php's own cohort filter). Owner-agnostic scope, same reasoning as
// accepted_this_month_scope_sql() in queue_builder.php: a quote created and accepted the
// same day via the main CRM can have no owner among the 4 configured callers at all, so
// filtering by owner in SQL (the old approach) silently dropped it. Instead every quote in
// scope is counted, then attributed in PHP — to its current owner if one of the 4 configured
// callers, otherwise to a pseudo-bucket 'unassigned_fit' / 'unassigned_groups' depending on
// quote type, so the totals are never short. Summing a caller's own India FIT callers plus
// 'unassigned_fit' for a given day reproduces get_monthly_accepted_count() exactly (2026-07-21).
function build_daily_accepted_by_owner(mysqli $conn, array $india_regions, array $callers_map): array
{
    if (!$india_regions) return [];
    $fullname_to_user = array_flip($callers_map);
    $fit_scope    = accepted_this_month_scope_sql($conn, $india_regions, 'fit');
    $groups_scope = accepted_this_month_scope_sql($conn, $india_regions, 'groups');

    // Each subselect collapses to one row per quoteid (MIN(created_at) as its accepted date)
    // BEFORE the outer GROUP BY buckets by day — otherwise a quote accepted twice this month
    // (e.g. bounced through Requote and back) would be counted once per day it was accepted on,
    // diverging from get_monthly_accepted_count()'s COUNT(DISTINCT quoteid) (discovered 2026-07-21
    // comparing the two totals: monitoring read 1 higher than the widget for exactly this reason).
    $res = $conn->query("
        SELECT accept_date, owner, quote_type, COUNT(*) AS n
        FROM (
            SELECT vq.quoteid,
                   MIN(DATE(t.created_at)) AS accept_date,
                   vqinfo.assigned_to_sales_agent AS owner,
                   'fit' AS quote_type
            FROM (" . accepted_stage_events_this_month_sql() . ") t
            JOIN vtiger_quotes vq ON vq.quoteid = t.quoteid
            JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = t.quoteid
            WHERE $fit_scope
            GROUP BY vq.quoteid, vqinfo.assigned_to_sales_agent
        ) dedup
        GROUP BY accept_date, owner, quote_type
        UNION ALL
        SELECT accept_date, owner, quote_type, COUNT(*) AS n
        FROM (
            SELECT vq.quoteid,
                   MIN(DATE(t.created_at)) AS accept_date,
                   vqinfo.assigned_to_sales_agent AS owner,
                   'groups' AS quote_type
            FROM (" . accepted_stage_events_this_month_sql() . ") t
            JOIN vtiger_quotes vq ON vq.quoteid = t.quoteid
            JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = t.quoteid
            WHERE $groups_scope
            GROUP BY vq.quoteid, vqinfo.assigned_to_sales_agent
        ) dedup
        GROUP BY accept_date, owner, quote_type
        ORDER BY accept_date
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $owner = $row['owner'];
            $uname = ($owner !== null && $owner !== '') ? ($fullname_to_user[$owner] ?? null) : null;
            if ($uname === null) {
                $uname = $row['quote_type'] === 'groups' ? 'unassigned_groups' : 'unassigned_fit';
            }
            $date = $row['accept_date'];
            $out[$date][$uname] = ($out[$date][$uname] ?? 0) + (int)$row['n'];
        }
        $res->free();
    }
    return $out;
}

// Row-level detail behind build_daily_accepted_by_owner()'s totals (both FIT and Groups), for
// the admin-only CSV export:
// same scope as build_daily_accepted_by_owner()/get_monthly_accepted_count(), one row per quote
// (a quote accepted twice this month — e.g. bounced through Requote and back — shows its
// earliest acceptance this month via MIN()). organization_name passed through utf8_safe() per
// the same rule used everywhere else (legacy Windows-1252 bytes have broken json_encode(); CSV
// isn't JSON-encoded here, but the same invalid bytes would still corrupt the file).
function build_accepted_this_month_list(mysqli $conn, array $india_regions): array
{
    if (!$india_regions) return [];
    $fit_scope    = accepted_this_month_scope_sql($conn, $india_regions, 'fit');
    $groups_scope = accepted_this_month_scope_sql($conn, $india_regions, 'groups');

    $res = $conn->query("
        SELECT
            vq.quoteid,
            vq.quote_no,
            'FIT' AS quote_type,
            va.organization_name,
            vcd.name                       AS contactname,
            vcd.mobile                     AS contactmobile,
            vq.created_at                  AS quote_created_at,
            vqcf.cf_1162                   AS trip_start_date,
            (vq.adults + vq.children + vq.infants) AS pax,
            vq.quotestage,
            vqinfo.assigned_to_region,
            vqinfo.assigned_to_sales_agent AS owner,
            MIN(t.created_at)              AS accepted_at
        FROM (" . accepted_stage_events_this_month_sql() . ") t
        JOIN vtiger_quotes vq ON vq.quoteid = t.quoteid
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = t.quoteid
        LEFT JOIN vtiger_quotescf vqcf ON vq.quoteid = vqcf.quoteid
        LEFT JOIN tdu_organisation va  ON vq.accountid = va.organizationid
        LEFT JOIN tdu_contacts vcd     ON vq.contactid = vcd.auto_id AND va.organizationid = vcd.organizationid
        WHERE $fit_scope
        GROUP BY vq.quoteid, vq.quote_no, va.organization_name, vcd.name, vcd.mobile,
                 vq.created_at, vqcf.cf_1162, vq.adults, vq.children, vq.infants,
                 vq.quotestage, vqinfo.assigned_to_region, vqinfo.assigned_to_sales_agent
        UNION ALL
        SELECT
            vq.quoteid,
            vq.quote_no,
            'Groups' AS quote_type,
            va.organization_name,
            vcd.name                       AS contactname,
            vcd.mobile                     AS contactmobile,
            vq.created_at                  AS quote_created_at,
            vqcf.cf_1162                   AS trip_start_date,
            (vq.adults + vq.children + vq.infants) AS pax,
            vq.quotestage,
            vqinfo.assigned_to_region,
            vqinfo.assigned_to_sales_agent AS owner,
            MIN(t.created_at)              AS accepted_at
        FROM (" . accepted_stage_events_this_month_sql() . ") t
        JOIN vtiger_quotes vq ON vq.quoteid = t.quoteid
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = t.quoteid
        LEFT JOIN vtiger_quotescf vqcf ON vq.quoteid = vqcf.quoteid
        LEFT JOIN tdu_organisation va  ON vq.accountid = va.organizationid
        LEFT JOIN tdu_contacts vcd     ON vq.contactid = vcd.auto_id AND va.organizationid = vcd.organizationid
        WHERE $groups_scope
        GROUP BY vq.quoteid, vq.quote_no, va.organization_name, vcd.name, vcd.mobile,
                 vq.created_at, vqcf.cf_1162, vq.adults, vq.children, vq.infants,
                 vq.quotestage, vqinfo.assigned_to_region, vqinfo.assigned_to_sales_agent
        ORDER BY quote_type, quoteid
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['organization_name'] = utf8_safe($row['organization_name']);
            $row['contactname']       = utf8_safe($row['contactname']);
            $out[] = $row;
        }
        $res->free();
    }
    return $out;
}

// Quote lifecycle (Accepted/Rejected/Requote counts), same owner-attribution and month-to-date
// window as build_daily_accepted_by_owner(). Per-caller per the user's explicit call, despite the "not a
// performance measure" caveat (repeated on the card). Entity-name axis with totals, not a daily
// trend, because a 3-segment stack x many days x 4 callers is unreadable, the same reason the outcome
// mix chart uses an entity-name axis. Only
// the three literal terminal-outcome transitions count; the post-acceptance "...After
// Confirmation" flow is out of scope, being a transient stage rather than an outcome.
function build_stage_lifecycle_by_owner(mysqli $conn, array $user_names, array $callers_map): array
{
    if (empty($user_names)) return [];
    $resolved         = resolve_fullnames_for_query($conn, $user_names, $callers_map);
    $fullnames_in     = $resolved['in_list'];
    $fullname_to_user = $resolved['fullname_to_user'];

    // Excludes an Accepted event whose quote has since reverted to Rejected After Confrmation —
    // Rejected/Requote counts are untouched, only the Accepted bucket gains this condition.
    $accepted_in = accepted_stages_sql($conn);

    $res = $conn->query("
        SELECT vqinfo.assigned_to_sales_agent AS owner, t.stage_name, COUNT(*) AS n
        FROM (
            SELECT quoteid,
                   TRIM(SUBSTRING(stage, LENGTH('Change Stage to ') + 1)) AS stage_name
            FROM vtiger_quote_stage_track
            WHERE stage LIKE 'Change Stage to %'
              AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        ) t
        JOIN vtiger_quotes vq ON vq.quoteid = t.quoteid
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = t.quoteid
        WHERE t.stage_name IN ('Accepted', 'Rejected', 'Requote')
          AND (t.stage_name != 'Accepted' OR vq.quotestage IN ($accepted_in))
          AND vq.deleted = 0
          AND vqinfo.assigned_to_sales_agent IN ($fullnames_in)
        GROUP BY owner, t.stage_name
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uname = $fullname_to_user[$row['owner']] ?? $row['owner'];
            $out[$uname][strtolower($row['stage_name'])] = (int)$row['n'];
        }
        $res->free();
    }
    return $out;
}

// Win rate trend: Accepted vs Rejected, business-wide, NOT per-caller (unlike the accepted,
// lifecycle and cycle-time builders), because
// a ratio is an even easier thing to misread as an individual scorecard than raw counts. Same
// stage-transition detection as build_daily_accepted_by_owner(), month-to-date, but grouped by
// date only (no owner join). Requote is excluded — it isn't a resolved win or loss.
function build_win_rate_trend(mysqli $conn): array
{
    // Same reversal exclusion as build_stage_lifecycle_by_owner() — Rejected counts are
    // untouched, only Accepted gains the condition.
    $accepted_in = accepted_stages_sql($conn);

    $res = $conn->query("
        SELECT DATE(t.created_at) AS event_date, t.stage_name, COUNT(*) AS n
        FROM (
            SELECT quoteid, created_at,
                   TRIM(SUBSTRING(stage, LENGTH('Change Stage to ') + 1)) AS stage_name
            FROM vtiger_quote_stage_track
            WHERE stage LIKE 'Change Stage to %'
              AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        ) t
        JOIN vtiger_quotes vq ON vq.quoteid = t.quoteid
        WHERE t.stage_name IN ('Accepted', 'Rejected')
          AND (t.stage_name != 'Accepted' OR vq.quotestage IN ($accepted_in))
          AND vq.deleted = 0
        GROUP BY event_date, t.stage_name
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[$row['event_date']][strtolower($row['stage_name'])] = (int)$row['n'];
        }
        $res->free();
    }
    return $out;
}

// Cycle time (Created → Accepted), same owner-attribution and month-to-date event population as
// build_daily_accepted_by_owner() (events, not deduped per quote). Per-caller per the user's
// call; survivorship-bias
// caveat (only quotes that reached Accepted count) repeated on the card. Returns SUM(days)/COUNT,
// not a pre-averaged AVG, so the view can compute a weighted average for "Total" mode.
function build_cycle_time_by_owner(mysqli $conn, array $user_names, array $callers_map): array
{
    if (empty($user_names)) return [];
    $resolved         = resolve_fullnames_for_query($conn, $user_names, $callers_map);
    $fullnames_in     = $resolved['in_list'];
    $fullname_to_user = $resolved['fullname_to_user'];

    // Same reversal exclusion — this function only ever counts Accepted events, so the
    // condition applies unconditionally.
    $accepted_in = accepted_stages_sql($conn);

    $res = $conn->query("
        SELECT vqinfo.assigned_to_sales_agent AS owner,
               SUM(DATEDIFF(t.created_at, vq.created_at)) AS sum_days,
               COUNT(*) AS n
        FROM (
            SELECT quoteid, created_at,
                   TRIM(SUBSTRING(stage, LENGTH('Change Stage to ') + 1)) AS stage_name
            FROM vtiger_quote_stage_track
            WHERE stage LIKE 'Change Stage to %'
              AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        ) t
        JOIN vtiger_quotes vq ON vq.quoteid = t.quoteid
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = t.quoteid
        WHERE t.stage_name = 'Accepted'
          AND vq.quotestage IN ($accepted_in)
          AND vq.deleted = 0
          AND vqinfo.assigned_to_sales_agent IN ($fullnames_in)
        GROUP BY owner
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uname = $fullname_to_user[$row['owner']] ?? $row['owner'];
            $out[$uname] = ['sum_days' => (int)$row['sum_days'], 'n' => (int)$row['n']];
        }
        $res->free();
    }
    return $out;
}

// "Quotes owned" — live snapshot (no date filter), how many quotes each caller currently has in
// their book. Same population as B.7 (Created/Requote only) — active workload, not total
// historical portfolio. Every configured caller gets an entry even at 0, unlike B.7.
function build_quotes_owned_by_caller(mysqli $conn, array $user_names, array $callers_map): array
{
    if (empty($user_names)) return [];
    $resolved         = resolve_fullnames_for_query($conn, $user_names, $callers_map);
    $fullnames_in     = $resolved['in_list'];
    $fullname_to_user = $resolved['fullname_to_user'];

    $res = $conn->query("
        SELECT vqinfo.assigned_to_sales_agent AS owner, COUNT(*) AS n
        FROM vtiger_quotes vq
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vq.quoteid
        WHERE vq.deleted = 0
          AND vq.quotestage IN ('Created', 'Requote')
          AND vqinfo.assigned_to_sales_agent IN ($fullnames_in)
        GROUP BY owner
    ");
    $out = [];
    foreach ($user_names as $uname) $out[$uname] = 0;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uname = $fullname_to_user[$row['owner']] ?? $row['owner'];
            $out[$uname] = (int)$row['n'];
        }
        $res->free();
    }
    return $out;
}

// "Why we lose" — rejection reasons, business-wide, all-time (rolling window would starve the
// sample, the same reasoning build_account_insights() applies). Every row IS a rejection with a
// mandatory reason, so no
// stage/outcome filter needed. Returns both the category rollup and the per-reason breakdown from
// one query, so the view's By category/By reason toggle needs no second fetch.
function build_rejection_reasons(mysqli $conn): array
{
    $res = $conn->query("
        SELECT category, reason, COUNT(*) AS n
        FROM tdu_quote_closure_feedback
        GROUP BY category, reason
        ORDER BY n DESC
    ");
    $by_category = ['company' => 0, 'competition' => 0, 'client' => 0, 'other' => 0];
    $by_reason = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cat = $row['category'];
            if (isset($by_category[$cat])) $by_category[$cat] += (int)$row['n'];
            $by_reason[] = ['reason' => $row['reason'], 'category' => $cat, 'n' => (int)$row['n']];
        }
        $res->free();
    }
    return ['by_category' => $by_category, 'by_reason' => $by_reason];
}

// Outcome mix, live-filter variant: outcome counts per caller summed over a rolling window (last
// $days_back days), NOT a day-by-day trend — date on the x-axis of a 4-segment stacked bar made
// an unreadable grid, so Per person/Total both use entity name (caller/queue) instead.
function build_outcome_mix_totals(mysqli $conn, array $user_names, array $callers_map, int $days_back = 14): array
{
    if (empty($user_names)) return [];
    $resolved         = resolve_fullnames_for_query($conn, $user_names, $callers_map);
    $fullnames_in     = $resolved['in_list'];
    $fullname_to_user = $resolved['fullname_to_user'];

    $res = $conn->query("
        SELECT created_by, outcome, COUNT(*) AS n
        FROM vtiger_quotes_followup
        WHERE followup_type = 'call_info'
          AND outcome IS NOT NULL
          AND created_by IN ($fullnames_in)
          AND calltime >= DATE_SUB(CURDATE(), INTERVAL $days_back DAY)
        GROUP BY created_by, outcome
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uname = $fullname_to_user[$row['created_by']] ?? $row['created_by'];
            $out[$uname][$row['outcome']] = (int)$row['n'];
        }
        $res->free();
    }
    return $out;
}

// Contact rate, live-filter variant: DAILY reached/not-reached counts per caller, last $days_back days.
// Raw counts, not a pre-computed percentage, so "Total" recombines from summed counts rather than
// averaging per-caller percentages. reached = interested + next_call + inbound_call; not reached
// = no_answer_email.
function build_daily_contact_rate(mysqli $conn, array $user_names, array $callers_map, int $days_back = 14): array
{
    if (empty($user_names)) return [];
    $resolved         = resolve_fullnames_for_query($conn, $user_names, $callers_map);
    $fullnames_in     = $resolved['in_list'];
    $fullname_to_user = $resolved['fullname_to_user'];

    $res = $conn->query("
        SELECT DATE(calltime) AS call_date, created_by, outcome, COUNT(*) AS n
        FROM vtiger_quotes_followup
        WHERE followup_type = 'call_info'
          AND outcome IS NOT NULL
          AND created_by IN ($fullnames_in)
          AND calltime >= DATE_SUB(CURDATE(), INTERVAL $days_back DAY)
        GROUP BY call_date, created_by, outcome
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uname = $fullname_to_user[$row['created_by']] ?? $row['created_by'];
            $day   = $row['call_date'];
            if (!isset($out[$day][$uname])) $out[$day][$uname] = ['reached' => 0, 'not_reached' => 0];
            if (in_array($row['outcome'], ['interested', 'next_call', 'inbound_call'], true)) {
                $out[$day][$uname]['reached'] += (int)$row['n'];
            } elseif ($row['outcome'] === 'no_answer_email') {
                $out[$day][$uname]['not_reached'] += (int)$row['n'];
            }
        }
        $res->free();
    }
    return $out;
}

// Backlog, DAILY size trend, default last 14 days. Each point is that day's actual carry-over
// bucket size. A day with no daily_runs rows (e.g. a Sunday nobody opened the queue — see
// the cron skips Sundays) simply has no entry, not a carried-forward number.
function build_backlog_size_trend(mysqli $conn, array $user_names, int $days_back = 14): array
{
    if (empty($user_names)) return [];
    $names_in = sql_in_list($conn, $user_names);

    $res = $conn->query("
        SELECT run_date, user_name, COUNT(*) AS n
        FROM daily_runs
        WHERE bucket = 'carryover' AND item_type = 'quote' AND user_name IN ($names_in)
          AND run_date >= DATE_SUB(CURDATE(), INTERVAL $days_back DAY)
        GROUP BY run_date, user_name
        ORDER BY run_date
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[$row['run_date']][$row['user_name']] = (int)$row['n'];
        }
        $res->free();
    }
    return $out;
}

// Backlog age heat-strip, TODAY ONLY. Takes the same carry-over set build_kpi_by_caller() uses
// (load_carryover_quotes(), queue_builder.php) so the days_behind logic is never duplicated.
function build_backlog_age_today(array $carryover_quotes, array $user_names): array
{
    $out = [];
    foreach ($user_names as $uname) $out[$uname] = ['0-2d' => 0, '3d+' => 0];

    foreach ($carryover_quotes as $uname => $org_groups) {
        foreach ($org_groups as $quotes) {
            foreach ($quotes as $q) {
                $bucket = ((int)$q['days_behind'] >= CARRYOVER_RED_DAYS) ? '3d+' : '0-2d';
                $out[$uname][$bucket]++;
            }
        }
    }
    return $out;
}

// Coverage, daily trend variant: coverage % over the last $days_back days, business-wide (stays
// outside the live caller filter). "Qualifying" for a past day is recomputed against TODAY's
// stage/region data rather than a true historical snapshot — the same simplification is applied
// consistently to every day. Fetches every qualifying trip date ONCE and buckets in PHP.
// Days with no daily_runs snapshot (e.g. Sundays) are left out entirely rather than shown as a
// misleading 0% dip.
function build_daily_coverage(mysqli $conn, array $user_names, int $days_back = 14): array
{
    $regions_in = sql_in_list($conn, INDIA_REGIONS);

    $trip_dates = [];
    $res = $conn->query("
        SELECT vqcf.cf_1162 AS trip_date
        FROM vtiger_quotes vq
        LEFT JOIN vtiger_quotescf vqcf     ON vq.quoteid = vqcf.quoteid
        LEFT JOIN vtiger_quotes_info vqinfo ON vq.quoteid = vqinfo.quoteid
        WHERE vq.deleted = 0
          AND vq.quotestage IN ('Created', 'Requote')
          AND vqcf.cf_1162 IS NOT NULL
          AND vqinfo.assigned_to_region IN ($regions_in)
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) $trip_dates[] = $row['trip_date'];
        $res->free();
    }

    $out = [];
    if (!empty($user_names)) {
        $names_in = sql_in_list($conn, $user_names);
        $res = $conn->query("
            SELECT run_date, COUNT(DISTINCT item_id) AS n
            FROM daily_runs
            WHERE item_type = 'quote'
              AND user_name IN ($names_in)
              AND bucket IN (" . sql_in_list($conn, followup_buckets()) . ")
              AND run_date >= DATE_SUB(CURDATE(), INTERVAL $days_back DAY)
            GROUP BY run_date
        ");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $day = $row['run_date'];
                $qualifying = 0;
                foreach ($trip_dates as $td) {
                    if ($td > $day) $qualifying++;
                }
                $out[$day] = ['qualifying' => $qualifying, 'surfaced' => (int)$row['n']];
            }
            $res->free();
        }
    }
    return $out;
}

// Capacity vs demand, split by queue (India FIT / Groups & MICE never combined). Doesn't
// touch daily_runs: "demand" mirrors build_caller_queue()'s SP1-4 classification (minus cap/sort/
// org-grouping), recomputed as if each of the last $days_back days were "today" — so, unlike
// coverage, every calendar day gets a real number, none skipped. call_count/next_call_date/
// snooze_until/last_outcome reflect TODAY's history projected backward (created_at/trip date are
// exact). Capacity is derived from config, never hardcoded. One queue, not two: a region owner
// works FIT and Groups together against a single budget, so the old per-team split would
// compare each half's demand against a capacity that covers both.
function build_daily_capacity_demand(mysqli $conn, int $days_back = 14): array
{
    $regions_in = sql_in_list($conn, INDIA_REGIONS);

    $total_capacity = 0;
    foreach (array_keys(QUEUE_REGIONS) as $_region) {
        $total_capacity += (int) floor(tdu_region_capacity($_region) * INDIA_FOLLOWUP_PCT);
    }
    $capacities = ['Follow-up' => $total_capacity];

    $rows_by_queue = ['Follow-up' => []];
    $res = $conn->query("
        SELECT
            vq.quote_no,
            vq.created_at,
            vqcf.cf_1162                AS trip_date,
            COALESCE(cc.call_count, 0)  AS call_count,
            sched.next_call_date,
            ext.snooze_until,
            call_rec.outcome            AS last_outcome
        FROM vtiger_quotes vq
        LEFT JOIN vtiger_quotescf vqcf       ON vq.quoteid = vqcf.quoteid
        LEFT JOIN vtiger_quotes_info vqinfo  ON vq.quoteid = vqinfo.quoteid
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
        WHERE vq.deleted = 0
          AND vq.quotestage IN ('Created', 'Requote')
          AND vqcf.cf_1162 IS NOT NULL
          AND vqcf.cf_1162 > DATE_SUB(CURDATE(), INTERVAL $days_back DAY)
          AND vqinfo.assigned_to_region IN ($regions_in)
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows_by_queue['Follow-up'][] = $row;
        }
        $res->free();
    }

    $out = [];
    foreach ($rows_by_queue as $queue_label => $rows) {
        // created_at/trip_date don't change across the simulated days below — precompute once
        // per row instead of re-parsing on every day iteration (14x redundant strtotime() calls).
        foreach ($rows as &$r) {
            $r['created_ts'] = strtotime($r['created_at']);
            $r['trip_ts']    = strtotime($r['trip_date']);
        }
        unset($r);

        $daily = [];
        for ($i = $days_back - 1; $i >= 0; $i--) {
            $day    = date('Y-m-d', strtotime("-$i day"));
            $day_ts = strtotime($day);
            $sp1 = $sp2 = $sp3 = $sp4 = 0;
            foreach ($rows as $r) {
                // Cohort gate: trip must be strictly future relative to the day being simulated.
                if ($r['trip_date'] <= $day) continue;

                // Live promise (next_call_date), not the snooze_until copy. See
                // build_caller_queue()'s $snoozed derivation for why.
                $snoozed = !empty($r['next_call_date']) && $r['next_call_date'] > $day;
                if ($snoozed) continue; // in cool-down — not demand on this day

                $due_today            = !empty($r['next_call_date']) && $r['next_call_date'] <= $day;
                $returning_interested = ($r['last_outcome'] === 'interested');
                $created_days = (int) floor(($day_ts - $r['created_ts']) / 86400);
                $trip_days    = (int) floor(($r['trip_ts'] - $day_ts) / 86400);

                if ($due_today || $returning_interested) {
                    $sp1++;
                } elseif ((int)$r['call_count'] === 0 && $created_days <= FOLLOWUP_CREATED_DAYS) {
                    $sp2++;
                } elseif ($trip_days >= 0 && $trip_days <= FOLLOWUP_TRAVEL_DATE_WINDOW_DAYS) {
                    $sp3++;
                } else {
                    // Idle-capacity backfill — matches none of SP1-3. Mirrors queue_builder.php's
                    // build_caller_queue() SP4 arm.
                    $sp4++;
                }
            }
            $daily[$day] = ['sp1' => $sp1, 'sp2' => $sp2, 'sp3' => $sp3, 'sp4' => $sp4];
        }

        $out[$queue_label] = ['capacity' => $capacities[$queue_label], 'daily' => $daily];
    }
    return $out;
}

// Unresponsive organisations: they ask for quotes but don't pick up the phone. Business-wide, scoped to
// Created/Requote only. Ranked top-$limit, not a trend. A ROLLING window ($window_days), not
// all-time, so a long history of answered calls doesn't dilute a recent run of no-answers below
// the threshold. Counts DISTINCT CONTACT DAYS per org, not raw call_info rows, so an org with
// several quotes called in one sitting isn't over-weighted. A day counts as "unanswered" only if
// EVERY row logged that day was no_answer_email — one positive outcome means the org was reached.
function build_unresponsive_orgs(
    mysqli $conn,
    int $window_days = 90,
    int $min_days = 3,
    float $min_rate = 0.80,
    int $limit = 10
): array {
    $res = $conn->query("
        SELECT
            days.organization_name,
            days.total_days,
            days.unanswered_days,
            qc.quote_count
        FROM (
            SELECT
                organizationid,
                organization_name,
                COUNT(*) AS total_days,
                SUM(CASE WHEN day_reached = 0 THEN 1 ELSE 0 END) AS unanswered_days,
                SUM(CASE WHEN day_reached = 0 THEN 1 ELSE 0 END) / COUNT(*) AS no_answer_rate
            FROM (
                SELECT
                    va.organizationid,
                    va.organization_name,
                    DATE(f.calltime) AS call_day,
                    MAX(CASE WHEN f.outcome IN ('interested', 'next_call', 'inbound_call') THEN 1 ELSE 0 END) AS day_reached
                FROM vtiger_quotes_followup f
                INNER JOIN vtiger_quotes vq    ON vq.quoteid = f.quoteid
                INNER JOIN tdu_organisation va ON vq.accountid = va.organizationid
                WHERE f.followup_type = 'call_info'
                  AND f.outcome IS NOT NULL
                  AND f.calltime >= DATE_SUB(CURDATE(), INTERVAL $window_days DAY)
                  AND vq.deleted = 0
                  AND vq.quotestage IN ('Created', 'Requote')
                GROUP BY va.organizationid, va.organization_name, DATE(f.calltime)
            ) per_day
            GROUP BY organizationid, organization_name
        ) days
        INNER JOIN (
            SELECT va.organizationid, COUNT(DISTINCT vq.quoteid) AS quote_count
            FROM vtiger_quotes_followup f
            INNER JOIN vtiger_quotes vq    ON vq.quoteid = f.quoteid
            INNER JOIN tdu_organisation va ON vq.accountid = va.organizationid
            WHERE f.followup_type = 'call_info'
              AND f.outcome IS NOT NULL
              AND f.calltime >= DATE_SUB(CURDATE(), INTERVAL $window_days DAY)
              AND vq.deleted = 0
              AND vq.quotestage IN ('Created', 'Requote')
            GROUP BY va.organizationid
        ) qc ON qc.organizationid = days.organizationid
        WHERE days.total_days >= $min_days
          AND days.no_answer_rate >= $min_rate
        ORDER BY days.no_answer_rate DESC, days.total_days DESC
        LIMIT $limit
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $total = (int) $row['total_days'];
            $noAns = (int) $row['unanswered_days'];
            $out[] = [
                'organization_name' => utf8_safe($row['organization_name']),
                'total_days'        => $total,
                'unanswered_days'   => $noAns,
                'quote_count'       => (int) $row['quote_count'],
                'rate'              => $total > 0 ? round(($noAns / $total) * 100, 1) : 0.0,
            ];
        }
        $res->free();
    }
    return $out;
}

// Calls by hour of day. Unlike every other chart, this aggregates a DISTRIBUTION (the
// shape of the working day) rather than a trend over time, so it uses a longer window
// ($days_back = 90 vs the 14 days every daily-trend chart uses above) — a shorter window would
// leave individual hour buckets too sparse to read a clear pattern. Restricted to
// $hour_from-$hour_to (default 9-18 inclusive) to avoid empty bars for hours nobody calls; the
// range is a fixed default, not derived from the data (no DB access to inspect the real
// distribution before shipping this) — adjust here once the real data is seen live.
// Pre-seeds every hour in range with an empty array (even if it never got any calls) so the chart
// always shows the full fixed axis, unlike the daily trend functions above which only emit a key
// when there's a row to iterate over.
//
// Timezone: `calltime` is written with MySQL NOW() in the DB SERVER's local time — confirmed
// Australia/Melbourne, with the MySQL session tz left at SYSTEM, so NOW()/CURDATE() match PHP
// date(). The callers physically work in India (Asia/Kolkata), so this
// is the one chart on the dashboard where that gap actually matters — every other chart buckets by
// calendar DATE, where a ~5hr offset only risks misfiling a call within a few hours of midnight;
// this one buckets by HOUR OF DAY, where the same offset would show the wrong part of the day
// entirely. Melbourne observes DST and India doesn't, so the offset isn't constant across the
// year — a fixed hour-shift would drift wrong twice a year. Converted per-row here via
// DateTime/DateTimeZone (which knows Melbourne's DST rules) rather than MySQL's CONVERT_TZ,
// because shared cPanel hosting isn't guaranteed to have the mysql.time_zone_name tables loaded —
// CONVERT_TZ silently returns NULL when they aren't, which would quietly zero out every bucket
// instead of erroring.
// Returns ['hours' => hour => user_name => total call count, 'active_days' => user_name =>
// [distinct 'Y-m-d' dates (India tz) that caller made a 9am-6pm call in the window]]. The view
// divides hours by active_days.length to show an average per active day rather than a raw 90-day
// sum, so a caller who joined recently or was on leave isn't diluted. "Total" mode unions the
// active_days arrays client-side so overlapping working days aren't double-counted.
function build_calls_by_hour(mysqli $conn, array $user_names, array $callers_map, int $days_back = 90, int $hour_from = 9, int $hour_to = 18): array
{
    $out = [];
    for ($h = $hour_from; $h <= $hour_to; $h++) $out[$h] = [];
    $active_days = [];
    if (empty($user_names)) return ['hours' => $out, 'active_days' => $active_days];

    $resolved         = resolve_fullnames_for_query($conn, $user_names, $callers_map);
    $fullnames_in     = $resolved['in_list'];
    $fullname_to_user = $resolved['fullname_to_user'];

    $server_tz = new DateTimeZone('Australia/Melbourne');
    $india_tz  = new DateTimeZone('Asia/Kolkata');

    $res = $conn->query("
        SELECT calltime, created_by
        FROM vtiger_quotes_followup
        WHERE followup_type = 'call_info'
          AND created_by IN ($fullnames_in)
          AND calltime >= DATE_SUB(CURDATE(), INTERVAL $days_back DAY)
    ");
    $active_day_sets = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uname = $fullname_to_user[$row['created_by']] ?? $row['created_by'];
            $dt = new DateTime($row['calltime'], $server_tz);
            $dt->setTimezone($india_tz);
            $hour = (int) $dt->format('G');
            if ($hour < $hour_from || $hour > $hour_to) continue;
            $out[$hour][$uname] = ($out[$hour][$uname] ?? 0) + 1;
            $active_day_sets[$uname][$dt->format('Y-m-d')] = true;
        }
        $res->free();
    }
    foreach ($active_day_sets as $uname => $days) $active_days[$uname] = array_keys($days);
    return ['hours' => $out, 'active_days' => $active_days];
}

// Channel mix. Same shape as build_outcome_mix_totals(): entity-name axis with a
// rolling window, not a daily trend. Reads channel from tdu_quotes_followup_ext. INNER JOIN is
// safe: every outcome-logged row always has a matching ext row, written in the same transaction
// (see ajax_log_outcome.php's write contract).
function build_channel_mix_totals(mysqli $conn, array $user_names, array $callers_map, int $days_back = 14): array
{
    if (empty($user_names)) return [];
    $resolved         = resolve_fullnames_for_query($conn, $user_names, $callers_map);
    $fullnames_in     = $resolved['in_list'];
    $fullname_to_user = $resolved['fullname_to_user'];

    $res = $conn->query("
        SELECT vqf.created_by, ext.channel, COUNT(*) AS n
        FROM vtiger_quotes_followup vqf
        INNER JOIN tdu_quotes_followup_ext ext ON ext.followup_id = vqf.auto_id
        WHERE vqf.followup_type = 'call_info'
          AND vqf.outcome IS NOT NULL
          AND vqf.created_by IN ($fullnames_in)
          AND vqf.calltime >= DATE_SUB(CURDATE(), INTERVAL $days_back DAY)
        GROUP BY vqf.created_by, ext.channel
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $uname = $fullname_to_user[$row['created_by']] ?? $row['created_by'];
            $out[$uname][$row['channel']] = (int)$row['n'];
        }
        $res->free();
    }
    return $out;
}

// Queue composition over time (SP1-4/carry-over). Unlike capacity vs demand (simulated demand), this
// reads what was actually surfaced straight from daily_runs.bucket — same source/shape as
// build_backlog_size_trend(), just not narrowed to one bucket. Feeds BOTH toggle modes: "Total"
// sums a queue's members per day; "Per person" collapses the window into one total per caller per
// bucket (avoids a caller x day x bucket grid).
function build_queue_composition_trend(mysqli $conn, array $user_names, int $days_back = 14): array
{
    if (empty($user_names)) return [];
    $names_in = sql_in_list($conn, $user_names);

    $res = $conn->query("
        SELECT run_date, user_name, bucket, COUNT(*) AS n
        FROM daily_runs
        WHERE item_type = 'quote'
          AND bucket IN ('sp1', 'sp2', 'sp3', 'sp4', 'carryover')
          AND user_name IN ($names_in)
          AND run_date >= DATE_SUB(CURDATE(), INTERVAL $days_back DAY)
        GROUP BY run_date, user_name, bucket
        ORDER BY run_date
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[$row['run_date']][$row['user_name']][$row['bucket']] = (int) $row['n'];
        }
        $res->free();
    }
    return $out;
}

// Shared "accepted" stage bucket — everything downstream of Accepted that hasn't since been
// cancelled (confirmed against details.php's code). Sources the literal list from
// queue_builder.php's accepted_stage_names() rather than keeping a second copy — that file is
// always require_once'd before this one.
function accepted_stages_sql(mysqli $conn): string
{
    return sql_in_list($conn, accepted_stage_names());
}

// By person tab — one caller's period summary. Total/Companies/Created/Requote are scoped by
// when the quote was CREATED (created_at in [from, to]); Accepted/Rejected are scoped by when
// they were RESOLVED (the stage-track event date in [from, to]), independent of creation date.
// Conversion rate is deliberately computed from these same resolved-date Accepted/Rejected
// counts, not recomputed from the created-date cohort — that would show a percentage that
// doesn't reconcile with the two count tiles next to it.
function build_person_summary(mysqli $conn, string $owner_fullname, string $from, string $to): array
{
    $owner_esc = $conn->real_escape_string($owner_fullname);
    $from_esc  = $conn->real_escape_string($from);
    $to_esc    = $conn->real_escape_string($to);
    // Sargable range, not DATE(col) = ..., since wrapping the column in DATE() disables the index
    // (applies to any indexed datetime column).
    $from_sql = "'$from_esc 00:00:00'";
    $to_sql   = "DATE_ADD('$to_esc', INTERVAL 1 DAY)";

    $book = $conn->query("
        SELECT
            COUNT(*) AS total,
            COUNT(DISTINCT vq.accountid) AS companies,
            SUM(CASE WHEN vq.quotestage = 'Created' THEN 1 ELSE 0 END) AS created,
            SUM(CASE WHEN vq.quotestage = 'Requote' THEN 1 ELSE 0 END) AS requote
        FROM vtiger_quotes vq
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vq.quoteid
        WHERE vqinfo.assigned_to_sales_agent = '$owner_esc'
          AND vq.deleted = 0
          AND vq.created_at >= $from_sql
          AND vq.created_at < $to_sql
    ")->fetch_assoc();

    // Accepted — deduped to one row per quoteid (MIN event date) and reconfirmed against the
    // live stage, same reversal-safety principle applied elsewhere. Also returns sum_days
    // (Created -> Accepted) in the same query, so avg_cycle_time_days below needs no second fetch.
    $accepted_in = accepted_stages_sql($conn);
    $accepted_row = $conn->query("
        SELECT COUNT(*) AS n, SUM(DATEDIFF(t.event_at, vq.created_at)) AS sum_days
        FROM (
            SELECT quoteid, MIN(created_at) AS event_at
            FROM vtiger_quote_stage_track
            WHERE stage = 'Change Stage to Accepted'
            GROUP BY quoteid
        ) t
        JOIN vtiger_quotes vq ON vq.quoteid = t.quoteid
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = t.quoteid
        WHERE vqinfo.assigned_to_sales_agent = '$owner_esc'
          AND vq.deleted = 0
          AND vq.quotestage IN ($accepted_in)
          AND t.event_at >= $from_sql
          AND t.event_at < $to_sql
    ")->fetch_assoc();
    $accepted = (int) $accepted_row['n'];

    // Rejected — same literal 3-stage bucket used throughout this file: a loss even if it
    // passed through Accepted first. Deduped by MAX(created_at) per quoteid (latest resolution
    // wins), reconfirmed against the live stage.
    $rejected_stages_in = sql_in_list($conn, [
        'Rejected', 'Rejected After Confrmation QA pending', 'Rejected After Confrmation QA completed',
    ]);
    $rejected = (int) ($conn->query("
        SELECT COUNT(*) AS n
        FROM (
            SELECT quoteid, MAX(created_at) AS event_at
            FROM vtiger_quote_stage_track
            WHERE stage LIKE 'Change Stage to %'
              AND TRIM(SUBSTRING(stage, LENGTH('Change Stage to ') + 1)) IN ($rejected_stages_in)
            GROUP BY quoteid
        ) t
        JOIN vtiger_quotes vq ON vq.quoteid = t.quoteid
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = t.quoteid
        WHERE vqinfo.assigned_to_sales_agent = '$owner_esc'
          AND vq.deleted = 0
          AND vq.quotestage IN ($rejected_stages_in)
          AND t.event_at >= $from_sql
          AND t.event_at < $to_sql
    ")->fetch_assoc()['n']);

    $resolved = $accepted + $rejected;

    // Avg first-response time — scoped the same way as the Total/Created/Requote tiles above
    // (created_at in [from, to]), across every such quote with at least one logged call.
    $fr = $conn->query("
        SELECT AVG(DATEDIFF(fc.first_call_at, vq.created_at)) AS avg_days
        FROM vtiger_quotes vq
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vq.quoteid
        JOIN (
            SELECT quoteid, MIN(calltime) AS first_call_at
            FROM vtiger_quotes_followup
            WHERE followup_type = 'call_info'
            GROUP BY quoteid
        ) fc ON fc.quoteid = vq.quoteid
        WHERE vqinfo.assigned_to_sales_agent = '$owner_esc'
          AND vq.deleted = 0
          AND vq.created_at >= $from_sql
          AND vq.created_at < $to_sql
    ")->fetch_assoc();

    $sum_days = (int) ($accepted_row['sum_days'] ?? 0);

    // Inbound calls received — scoped by calltime (when the call happened), not the quote's own
    // created_at, since this is a call-activity count, not a quote-population tile.
    $inbound_calls = (int) ($conn->query("
        SELECT COUNT(*) AS n
        FROM vtiger_quotes_followup
        WHERE followup_type = 'call_info'
          AND outcome = 'inbound_call'
          AND created_by = '$owner_esc'
          AND calltime >= $from_sql
          AND calltime < $to_sql
    ")->fetch_assoc()['n']);

    return [
        'total'                   => (int) $book['total'],
        'companies'               => (int) $book['companies'],
        'created'                 => (int) $book['created'],
        'requote'                 => (int) $book['requote'],
        'accepted'                => $accepted,
        'rejected'                => $rejected,
        'inbound_calls'           => $inbound_calls,
        'conversion_rate'         => $resolved > 0 ? (int) round($accepted / $resolved * 100) : null,
        'avg_first_response_days' => $fr['avg_days'] !== null ? round((float) $fr['avg_days'], 1) : null,
        'avg_cycle_time_days'     => $accepted > 0 ? round($sum_days / $accepted, 1) : null,
    ];
}

// By person tab — this caller's calls-by-hour distribution for the picked date range. Unlike
// build_calls_by_hour() (a fixed 90-day rolling window across multiple callers), a single
// caller with one explicit range needs no active-day averaging — raw per-hour counts are enough.
function build_person_calls_by_hour(mysqli $conn, string $owner_fullname, string $from, string $to, int $hour_from = 9, int $hour_to = 18): array
{
    $owner_esc = $conn->real_escape_string($owner_fullname);
    $from_esc  = $conn->real_escape_string($from);
    $to_esc    = $conn->real_escape_string($to);
    $from_sql  = "'$from_esc 00:00:00'";
    $to_sql    = "DATE_ADD('$to_esc', INTERVAL 1 DAY)";

    $out = [];
    for ($h = $hour_from; $h <= $hour_to; $h++) $out[$h] = 0;

    // Same Melbourne -> Kolkata conversion as build_calls_by_hour(), for the same reason: the DB
    // server's clock is Melbourne and the callers work in India.
    $server_tz = new DateTimeZone('Australia/Melbourne');
    $india_tz  = new DateTimeZone('Asia/Kolkata');

    $res = $conn->query("
        SELECT calltime
        FROM vtiger_quotes_followup
        WHERE followup_type = 'call_info'
          AND created_by = '$owner_esc'
          AND calltime >= $from_sql
          AND calltime < $to_sql
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $dt = new DateTime($row['calltime'], $server_tz);
            $dt->setTimezone($india_tz);
            $hour = (int) $dt->format('G');
            if ($hour < $hour_from || $hour > $hour_to) continue;
            $out[$hour]++;
        }
        $res->free();
    }
    return $out;
}

// Row-level quote list behind each By person summary tile. Mirrors build_person_summary()'s
// exact created-vs-resolved date semantics per $filter, so a tile's count and this list stay in
// sync. $filter is validated by the caller (ajax_person_quotes.php) against a hard whitelist first.
function build_person_quotes(mysqli $conn, string $owner_fullname, string $from, string $to, string $filter, ?string $org = null, ?string $region = null, ?string $priority = null): array
{
    $owner_esc = $conn->real_escape_string($owner_fullname);
    $from_esc  = $conn->real_escape_string($from);
    $to_esc    = $conn->real_escape_string($to);
    $from_sql  = "'$from_esc 00:00:00'";
    $to_sql    = "DATE_ADD('$to_esc', INTERVAL 1 DAY)";
    // Optional single-organisation scope (the Companies tile's drill-one-level-deeper view). va
    // is already joined in both branches below, so this is just one more condition.
    $org_condition = $org !== null && $org !== '' ? "AND va.organization_name = '" . $conn->real_escape_string($org) . "'" : '';
    // Optional region/priority breakdown-bar scope — vqinfo is already joined in both branches,
    // same reasoning as $org_condition.
    $region_condition = $region !== null && $region !== '' ? "AND vqinfo.assigned_to_region = '" . $conn->real_escape_string($region) . "'" : '';
    // $priority arrives as the canonical label build_person_breakdown() buckets by ('High',
    // 'Low', 'Not connected', 'Blank'), not the raw lowercase value vqinfo.priority stores
    // (see ajax_set_priority.php's $valid_priorities) — map back before matching.
    $priority_raw_map = ['High' => 'high', 'Low' => 'low', 'Not connected' => 'not connected', 'Blank' => ''];
    $priority_condition = '';
    if ($priority !== null && $priority !== '' && array_key_exists($priority, $priority_raw_map)) {
        $priority_condition = $priority_raw_map[$priority] === ''
            ? "AND (vqinfo.priority IS NULL OR vqinfo.priority = '')"
            : "AND vqinfo.priority = '" . $conn->real_escape_string($priority_raw_map[$priority]) . "'";
    }

    if ($filter === 'accepted' || $filter === 'rejected') {
        // Same reversal-safe, deduped-by-quoteid shape build_person_summary() uses for its
        // accepted/rejected counts, selecting rows instead of COUNT(*).
        if ($filter === 'accepted') {
            $stage_match = "stage = 'Change Stage to Accepted'";
            $agg         = 'MIN';
            $live_in     = accepted_stages_sql($conn);
        } else {
            $live_in = sql_in_list($conn, [
                'Rejected', 'Rejected After Confrmation QA pending', 'Rejected After Confrmation QA completed',
            ]);
            $stage_match = "stage LIKE 'Change Stage to %' AND TRIM(SUBSTRING(stage, LENGTH('Change Stage to ') + 1)) IN ($live_in)";
            $agg         = 'MAX';
        }
        $res = $conn->query("
            SELECT vq.quoteid, vq.quote_no, va.organization_name, vqinfo.assigned_to_region,
                   CASE vqinfo.priority
                       WHEN 'high' THEN 'High'
                       WHEN 'low' THEN 'Low'
                       WHEN 'not connected' THEN 'Not connected'
                       ELSE ''
                   END AS priority,
                   vq.quotestage, t.event_at AS key_date
            FROM (
                SELECT quoteid, $agg(created_at) AS event_at
                FROM vtiger_quote_stage_track
                WHERE $stage_match
                GROUP BY quoteid
            ) t
            JOIN vtiger_quotes vq ON vq.quoteid = t.quoteid
            JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = t.quoteid
            LEFT JOIN tdu_organisation va ON vq.accountid = va.organizationid
            WHERE vqinfo.assigned_to_sales_agent = '$owner_esc'
              AND vq.deleted = 0
              AND vq.quotestage IN ($live_in)
              AND t.event_at >= $from_sql
              AND t.event_at < $to_sql
              $org_condition
              $region_condition
              $priority_condition
            ORDER BY t.event_at DESC
        ");
    } elseif ($filter === 'inbound') {
        // Inbound calls received — one row per call (a quote can appear more than once if
        // called in more than once), scoped by calltime like the summary tile's count.
        $res = $conn->query("
            SELECT vq.quoteid, vq.quote_no, va.organization_name, vqinfo.assigned_to_region,
                   CASE vqinfo.priority
                       WHEN 'high' THEN 'High'
                       WHEN 'low' THEN 'Low'
                       WHEN 'not connected' THEN 'Not connected'
                       ELSE ''
                   END AS priority,
                   vq.quotestage, vqf.calltime AS key_date
            FROM vtiger_quotes_followup vqf
            JOIN vtiger_quotes vq ON vq.quoteid = vqf.quoteid
            JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vq.quoteid
            LEFT JOIN tdu_organisation va ON vq.accountid = va.organizationid
            WHERE vqf.followup_type = 'call_info'
              AND vqf.outcome = 'inbound_call'
              AND vqf.created_by = '$owner_esc'
              AND vq.deleted = 0
              AND vqf.calltime >= $from_sql
              AND vqf.calltime < $to_sql
              $org_condition
              $region_condition
              $priority_condition
            ORDER BY vqf.calltime DESC
        ");
    } else {
        // total/created/requote — created-date scoped, same as build_person_summary()'s $book
        // query, plus a live-stage filter for created/requote.
        $stage_condition = '';
        if ($filter === 'created') $stage_condition = "AND vq.quotestage = 'Created'";
        if ($filter === 'requote') $stage_condition = "AND vq.quotestage = 'Requote'";
        $res = $conn->query("
            SELECT vq.quoteid, vq.quote_no, va.organization_name, vqinfo.assigned_to_region,
                   CASE vqinfo.priority
                       WHEN 'high' THEN 'High'
                       WHEN 'low' THEN 'Low'
                       WHEN 'not connected' THEN 'Not connected'
                       ELSE ''
                   END AS priority,
                   vq.quotestage, vq.created_at AS key_date
            FROM vtiger_quotes vq
            JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vq.quoteid
            LEFT JOIN tdu_organisation va ON vq.accountid = va.organizationid
            WHERE vqinfo.assigned_to_sales_agent = '$owner_esc'
              AND vq.deleted = 0
              AND vq.created_at >= $from_sql
              AND vq.created_at < $to_sql
              $stage_condition
              $org_condition
              $region_condition
              $priority_condition
            ORDER BY vq.created_at DESC
        ");
    }

    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($rows as &$r) { $r['organization_name'] = utf8_safe($r['organization_name']); }
    unset($r);
    return $rows;
}

// Companies drill-down — one row per organisation among this caller's "book" (same population
// as the Total tile: every quote, any status, created in [from, to]). "Days since contact" is
// the org's most recent activity across any of its quotes — the latest logged call, or the
// quote's own created_at if never called. Quotes with no linked organisation are excluded
// (inner JOIN, not LEFT) — a company drill-down needs a company.
function build_person_companies(mysqli $conn, string $owner_fullname, string $from, string $to): array
{
    $owner_esc = $conn->real_escape_string($owner_fullname);
    $from_esc  = $conn->real_escape_string($from);
    $to_esc    = $conn->real_escape_string($to);
    $from_sql  = "'$from_esc 00:00:00'";
    $to_sql    = "DATE_ADD('$to_esc', INTERVAL 1 DAY)";

    $res = $conn->query("
        SELECT
            va.organization_name,
            vqinfo.assigned_to_region,
            COUNT(*) AS quote_count,
            MAX(COALESCE(lc.last_call_at, vq.created_at)) AS last_contact_at
        FROM vtiger_quotes vq
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vq.quoteid
        JOIN tdu_organisation va ON vq.accountid = va.organizationid
        LEFT JOIN (
            SELECT quoteid, MAX(calltime) AS last_call_at
            FROM vtiger_quotes_followup
            WHERE followup_type = 'call_info'
            GROUP BY quoteid
        ) lc ON lc.quoteid = vq.quoteid
        WHERE vqinfo.assigned_to_sales_agent = '$owner_esc'
          AND vq.deleted = 0
          AND vq.created_at >= $from_sql
          AND vq.created_at < $to_sql
        GROUP BY va.organizationid, va.organization_name, vqinfo.assigned_to_region
        ORDER BY quote_count DESC
    ");

    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $now = time();
    foreach ($rows as &$r) {
        $r['organization_name']  = utf8_safe($r['organization_name']);
        $r['quote_count']        = (int) $r['quote_count'];
        $r['days_since_contact'] = (int) floor(($now - strtotime($r['last_contact_at'])) / 86400);
        unset($r['last_contact_at']);
    }
    unset($r);
    return $rows;
}

// Region/priority breakdown — same "book" population as the Total tile (every quote, any
// status, created in [from, to]), grouped by assigned_to_region or priority. Region stays sorted
// by count desc; priority is reordered in PHP to the fixed canonical order High/Low/Not
// connected/Blank rather than by count, with zero-count buckets simply absent. Blank/NULL values
// are normalised to a literal 'Blank'/'Unassigned' label instead of an empty string.
function build_person_breakdown(mysqli $conn, string $owner_fullname, string $from, string $to, string $mode): array
{
    $owner_esc = $conn->real_escape_string($owner_fullname);
    $from_esc  = $conn->real_escape_string($from);
    $to_esc    = $conn->real_escape_string($to);
    $from_sql  = "'$from_esc 00:00:00'";
    $to_sql    = "DATE_ADD('$to_esc', INTERVAL 1 DAY)";

    $bucket_expr = $mode === 'priority'
        ? "CASE vqinfo.priority
               WHEN 'high' THEN 'High'
               WHEN 'low' THEN 'Low'
               WHEN 'not connected' THEN 'Not connected'
               ELSE 'Blank'
           END"
        : "COALESCE(NULLIF(vqinfo.assigned_to_region, ''), 'Unassigned')";

    $res = $conn->query("
        SELECT $bucket_expr AS bucket, COUNT(*) AS n
        FROM vtiger_quotes vq
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vq.quoteid
        WHERE vqinfo.assigned_to_sales_agent = '$owner_esc'
          AND vq.deleted = 0
          AND vq.created_at >= $from_sql
          AND vq.created_at < $to_sql
        GROUP BY bucket
        ORDER BY n DESC
    ");

    $counts = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) { $counts[$row['bucket']] = (int) $row['n']; }
    }

    if ($mode === 'priority') {
        $ordered = [];
        foreach (['High', 'Low', 'Not connected', 'Blank'] as $p) {
            if (isset($counts[$p])) { $ordered[$p] = $counts[$p]; unset($counts[$p]); }
        }
        // Any unexpected value (schema drift) still shows up, just after the canonical four.
        $counts = $ordered + $counts;
    }

    $out = [];
    foreach ($counts as $bucket => $count) { $out[] = ['bucket' => $bucket, 'count' => $count]; }
    return $out;
}

// Live-book travel-month chart + quote table. Deliberately takes no $from/$to — this section
// ignores the date-range picker, it's this caller's live book right now. Returns every live
// quote (Created/Requote, deleted = 0) this caller owns, with enough detail to drive both the
// travel-month chart (bucketed client-side) and the sortable/searchable quote table from one
// query. call_count/next_call_date/last_call_at follow the same sched/cc subquery shape as
// queue_builder.php's build_caller_queue().
function build_person_live_book(mysqli $conn, string $owner_fullname): array
{
    $owner_esc = $conn->real_escape_string($owner_fullname);

    $res = $conn->query("
        SELECT
            vq.quoteid,
            vq.quote_no,
            va.organization_name,
            CASE vqinfo.priority
                WHEN 'high' THEN 'High'
                WHEN 'low' THEN 'Low'
                WHEN 'not connected' THEN 'Not connected'
                ELSE ''
            END AS priority,
            vqcf.cf_1162 AS travel_date,
            vq.created_at,
            call_rec.calltime AS last_call_at,
            COALESCE(cc.call_count, 0) AS call_count,
            sched.next_call_date
        FROM vtiger_quotes vq
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vq.quoteid
        LEFT JOIN vtiger_quotescf vqcf ON vq.quoteid = vqcf.quoteid
        LEFT JOIN tdu_organisation va ON vq.accountid = va.organizationid
        LEFT JOIN (
            SELECT quoteid, COUNT(*) AS call_count, MAX(auto_id) AS max_id
            FROM vtiger_quotes_followup
            WHERE followup_type = 'call_info'
            GROUP BY quoteid
        ) cc ON cc.quoteid = vq.quoteid
        LEFT JOIN vtiger_quotes_followup call_rec ON call_rec.auto_id = cc.max_id
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
        WHERE vqinfo.assigned_to_sales_agent = '$owner_esc'
          AND vq.deleted = 0
          AND vq.quotestage IN ('Created', 'Requote')
    ");

    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $now = time();
    foreach ($rows as &$r) {
        $r['organization_name']    = utf8_safe($r['organization_name']);
        $r['call_count']           = (int) $r['call_count'];
        $reference                 = $r['last_call_at'] ?? $r['created_at'];
        $r['days_since_contact']   = (int) floor(($now - strtotime($reference)) / 86400);
        unset($r['created_at']);
    }
    unset($r);
    return $rows;
}

// Account insights (exploratory, a different lens from queue monitoring). All-time,
// business-wide. Returns every qualifying org's raw counts unsorted/unlimited — the view's By
// volume/By rejection rate toggle sorts client-side against this one dataset.
//
// Stage buckets (literal spelling confirmed against vtiger_quotestage, incl. the CRM's own
// "Confrmation" typo, missing the i, in the two Rejected After ... stages):
// - accepted: Accepted + everything downstream that hasn't since been cancelled (PRE QA,
//   Payment Received, Final QA, Delivered, On Ground, Accounts - Reconciliation, Completed).
// - rejected: Rejected + both "Rejected After Confrmation QA ..." stages (a loss even if it
//   passed through Accepted first).
// - in_progress: Created, Requote only.
// Auto Rejected and Requote After Confirmation are excluded from all three buckets: Auto
// Rejected is a FIT-to-Group migration artefact (editing a FIT quote whose adults + children
// exceed 10 clones it into a new Group quote and marks the old record Auto Rejected), not a
// real rejection, and Requote After Confirmation is transient.
function build_account_insights(mysqli $conn): array
{
    $rejected_stages = [
        'Rejected', 'Rejected After Confrmation QA pending', 'Rejected After Confrmation QA completed',
    ];
    $in_progress_stages = ['Created', 'Requote'];

    $accepted_in    = accepted_stages_sql($conn);
    $rejected_in    = sql_in_list($conn, $rejected_stages);
    $in_progress_in = sql_in_list($conn, $in_progress_stages);

    $res = $conn->query("
        SELECT
            va.organization_name,
            SUM(CASE WHEN vq.quotestage IN ($accepted_in) THEN 1 ELSE 0 END)    AS accepted,
            SUM(CASE WHEN vq.quotestage IN ($rejected_in) THEN 1 ELSE 0 END)    AS rejected,
            SUM(CASE WHEN vq.quotestage IN ($in_progress_in) THEN 1 ELSE 0 END) AS in_progress
        FROM vtiger_quotes vq
        INNER JOIN tdu_organisation va ON vq.accountid = va.organizationid
        WHERE vq.deleted = 0
          AND vq.quotestage IN ($accepted_in, $rejected_in, $in_progress_in)
        GROUP BY va.organizationid, va.organization_name
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $accepted    = (int) $row['accepted'];
            $rejected    = (int) $row['rejected'];
            $in_progress = (int) $row['in_progress'];
            $resolved    = $accepted + $rejected;
            $out[] = [
                'organization_name' => utf8_safe($row['organization_name']),
                'accepted'          => $accepted,
                'rejected'          => $rejected,
                'in_progress'       => $in_progress,
                'total'             => $accepted + $rejected + $in_progress,
                'resolved'          => $resolved,
                'rate'              => $resolved > 0 ? round(($rejected / $resolved) * 100, 1) : null,
            ];
        }
        $res->free();
    }
    return $out;
}

// Shared by every "live basket" chart. The live quote book is `deleted = 0 AND quotestage
// NOT IN (...)`, this being the "..." half. Same excluded stages as build_account_insights()'s
// rejected bucket, plus Auto Rejected.
function live_basket_exclusion_sql(mysqli $conn): string
{
    return sql_in_list($conn, [
        'Rejected', 'Auto Rejected',
        'Rejected After Confrmation QA pending', 'Rejected After Confrmation QA completed',
        // Leads sit before 'Created' and are not part of the live quote basket these charts
        // describe. Excluded here rather than in each chart, since all five share this list.
        'Lead',
    ]);
}

// Live basket by stage, the spine of the quote basket and pipeline view. Business-wide, all-time snapshot:
// of every live quote, how many sit in each stage right now.
function build_live_basket_by_stage(mysqli $conn): array
{
    $excluded_in = live_basket_exclusion_sql($conn);

    $res = $conn->query("
        SELECT quotestage, COUNT(*) AS cnt
        FROM vtiger_quotes
        WHERE deleted = 0
          AND quotestage NOT IN ($excluded_in)
        GROUP BY quotestage
        ORDER BY cnt DESC
    ");
    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'quotestage' => $row['quotestage'],
                'cnt'        => (int) $row['cnt'],
            ];
        }
        $res->free();
    }
    return $out;
}

// Re-cuts the live basket by FIT vs Groups, and within Groups by GROUPS_PAX_THRESHOLD
// (reused from groups_mice.php, not re-hardcoded). Each bucket carries both an 'all' and a
// 'created_requote' count from one query, so the view's toggle needs no second fetch.
function build_live_basket_composition(mysqli $conn): array
{
    $excluded_in = live_basket_exclusion_sql($conn);
    $threshold = (int) GROUPS_PAX_THRESHOLD;
    $cr = "quotestage IN ('Created','Requote')";

    $res = $conn->query("
        SELECT
            SUM(CASE WHEN quote_no NOT LIKE '%G' THEN 1 ELSE 0 END) AS fit_all,
            SUM(CASE WHEN quote_no NOT LIKE '%G' AND $cr THEN 1 ELSE 0 END) AS fit_cr,
            SUM(CASE WHEN quote_no LIKE '%G' AND (adults + children + infants) > $threshold THEN 1 ELSE 0 END) AS groups_large_all,
            SUM(CASE WHEN quote_no LIKE '%G' AND (adults + children + infants) > $threshold AND $cr THEN 1 ELSE 0 END) AS groups_large_cr,
            SUM(CASE WHEN quote_no LIKE '%G' AND (adults + children + infants) <= $threshold THEN 1 ELSE 0 END) AS groups_small_all,
            SUM(CASE WHEN quote_no LIKE '%G' AND (adults + children + infants) <= $threshold AND $cr THEN 1 ELSE 0 END) AS groups_small_cr
        FROM vtiger_quotes
        WHERE deleted = 0
          AND quotestage NOT IN ($excluded_in)
    ");
    $row = $res ? $res->fetch_assoc() : null;
    if ($res) $res->free();

    return [
        'fit'          => ['all' => (int) ($row['fit_all'] ?? 0), 'created_requote' => (int) ($row['fit_cr'] ?? 0)],
        'groups_large' => ['all' => (int) ($row['groups_large_all'] ?? 0), 'created_requote' => (int) ($row['groups_large_cr'] ?? 0)],
        'groups_small' => ['all' => (int) ($row['groups_small_all'] ?? 0), 'created_requote' => (int) ($row['groups_small_cr'] ?? 0)],
    ];
}

// Re-cuts the live basket by destination (vtiger_quotes.country). Live values are only
// Australia/New Zealand/N/A/blank, so this is a straight AU vs NZ split; N/A and blank are
// excluded via the `country IN (...)` filter. Same all/created_requote shape as
// build_live_basket_composition().
function build_live_basket_destination(mysqli $conn): array
{
    $excluded_in = live_basket_exclusion_sql($conn);
    $cr = "quotestage IN ('Created','Requote')";

    $res = $conn->query("
        SELECT
            SUM(CASE WHEN country = 'Australia' THEN 1 ELSE 0 END) AS australia_all,
            SUM(CASE WHEN country = 'Australia' AND $cr THEN 1 ELSE 0 END) AS australia_cr,
            SUM(CASE WHEN country = 'New Zealand' THEN 1 ELSE 0 END) AS nz_all,
            SUM(CASE WHEN country = 'New Zealand' AND $cr THEN 1 ELSE 0 END) AS nz_cr
        FROM vtiger_quotes
        WHERE deleted = 0
          AND quotestage NOT IN ($excluded_in)
          AND country IN ('Australia', 'New Zealand')
    ");
    $row = $res ? $res->fetch_assoc() : null;
    if ($res) $res->free();

    return [
        'australia'    => ['all' => (int) ($row['australia_all'] ?? 0), 'created_requote' => (int) ($row['australia_cr'] ?? 0)],
        'new_zealand'  => ['all' => (int) ($row['nz_all'] ?? 0), 'created_requote' => (int) ($row['nz_cr'] ?? 0)],
    ];
}

// Forward pipeline by travel date, distinct from queue composition (follow-up call scheduling, not
// travel dates). Window: current month + next 11 (12 bars), plus a "13+ months" overflow bucket
// (LEAST(..., 12) caps the offset). Only cf_1162 > CURDATE() counts — production auto-rejects
// past-dated quotes via cron, staging doesn't, so a few stale rows may surface there only.
// Three-way split (All / Created-Requote / Accepted, the last reusing accepted_stages_sql()) from
// one query, toggled client-side.
function build_travel_date_horizon(mysqli $conn): array
{
    $excluded_in = live_basket_exclusion_sql($conn);
    $accepted_in = accepted_stages_sql($conn);
    $cr = "vq.quotestage IN ('Created','Requote')";

    $res = $conn->query("
        SELECT
            LEAST(PERIOD_DIFF(DATE_FORMAT(vqcf.cf_1162, '%Y%m'), DATE_FORMAT(CURDATE(), '%Y%m')), 12) AS month_offset,
            COUNT(*) AS all_cnt,
            SUM(CASE WHEN $cr THEN 1 ELSE 0 END) AS cr_cnt,
            SUM(CASE WHEN vq.quotestage IN ($accepted_in) THEN 1 ELSE 0 END) AS accepted_cnt
        FROM vtiger_quotes vq
        LEFT JOIN vtiger_quotescf vqcf ON vq.quoteid = vqcf.quoteid
        WHERE vq.deleted = 0
          AND vq.quotestage NOT IN ($excluded_in)
          AND vqcf.cf_1162 > CURDATE()
        GROUP BY month_offset
    ");

    $by_offset = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $by_offset[(int) $row['month_offset']] = [
                'all'             => (int) $row['all_cnt'],
                'created_requote' => (int) $row['cr_cnt'],
                'accepted'        => (int) $row['accepted_cnt'],
            ];
        }
        $res->free();
    }

    $empty = ['all' => 0, 'created_requote' => 0, 'accepted' => 0];
    $out = [];
    $cursor = new DateTime('first day of this month');
    for ($offset = 0; $offset <= 11; $offset++) {
        $out[] = array_merge(['label' => $cursor->format('M Y')], $by_offset[$offset] ?? $empty);
        $cursor->modify('+1 month');
    }
    $out[] = array_merge(['label' => '13+ months'], $by_offset[12] ?? $empty);

    return $out;
}

// Passenger counts per live quote, toggled FIT vs Groups (not the usual All/Created-
// Requote toggle — comparing group-size composition between quote types). Different bucket
// schemes since pax ranges don't overlap: FIT tops out ~10 pax (above that the CRM auto-migrates
// it to a Group quote), so FIT buckets are granular (1/2/3-4/5-6/
// 7-10); Groups buckets are wide (1-25/26-50/51-100/101-200/200+, 1-25/26-50 lining up with
// GROUPS_PAX_THRESHOLD). 0-pax quotes are silently excluded. One query returns bucket counts plus
// each side's SUM/COUNT for its average, no second fetch on toggle.
function build_pax_distribution(mysqli $conn): array
{
    $excluded_in = live_basket_exclusion_sql($conn);
    $pax = '(adults + children + infants)';
    $fit = "quote_no NOT LIKE '%G'";
    $grp = "quote_no LIKE '%G'";

    $res = $conn->query("
        SELECT
            SUM(CASE WHEN $fit AND $pax = 1 THEN 1 ELSE 0 END) AS fit_1,
            SUM(CASE WHEN $fit AND $pax = 2 THEN 1 ELSE 0 END) AS fit_2,
            SUM(CASE WHEN $fit AND $pax BETWEEN 3 AND 4 THEN 1 ELSE 0 END) AS fit_3_4,
            SUM(CASE WHEN $fit AND $pax BETWEEN 5 AND 6 THEN 1 ELSE 0 END) AS fit_5_6,
            SUM(CASE WHEN $fit AND $pax BETWEEN 7 AND 10 THEN 1 ELSE 0 END) AS fit_7_10,
            SUM(CASE WHEN $fit AND $pax >= 1 THEN $pax ELSE 0 END) AS fit_pax_sum,
            SUM(CASE WHEN $fit AND $pax >= 1 THEN 1 ELSE 0 END) AS fit_count,

            SUM(CASE WHEN $grp AND $pax BETWEEN 1 AND 25 THEN 1 ELSE 0 END) AS groups_1_25,
            SUM(CASE WHEN $grp AND $pax BETWEEN 26 AND 50 THEN 1 ELSE 0 END) AS groups_26_50,
            SUM(CASE WHEN $grp AND $pax BETWEEN 51 AND 100 THEN 1 ELSE 0 END) AS groups_51_100,
            SUM(CASE WHEN $grp AND $pax BETWEEN 101 AND 200 THEN 1 ELSE 0 END) AS groups_101_200,
            SUM(CASE WHEN $grp AND $pax > 200 THEN 1 ELSE 0 END) AS groups_200_plus,
            SUM(CASE WHEN $grp AND $pax >= 1 THEN $pax ELSE 0 END) AS groups_pax_sum,
            SUM(CASE WHEN $grp AND $pax >= 1 THEN 1 ELSE 0 END) AS groups_count
        FROM vtiger_quotes
        WHERE deleted = 0
          AND quotestage NOT IN ($excluded_in)
    ");
    $row = $res ? $res->fetch_assoc() : null;
    if ($res) $res->free();

    $fit_count = (int) ($row['fit_count'] ?? 0);
    $groups_count = (int) ($row['groups_count'] ?? 0);

    return [
        'fit' => [
            'buckets' => [
                '1'    => (int) ($row['fit_1'] ?? 0),
                '2'    => (int) ($row['fit_2'] ?? 0),
                '3-4'  => (int) ($row['fit_3_4'] ?? 0),
                '5-6'  => (int) ($row['fit_5_6'] ?? 0),
                '7-10' => (int) ($row['fit_7_10'] ?? 0),
            ],
            'avg'   => $fit_count > 0 ? round(((int) $row['fit_pax_sum']) / $fit_count, 1) : 0,
            'count' => $fit_count,
        ],
        'groups' => [
            'buckets' => [
                '1-25'    => (int) ($row['groups_1_25'] ?? 0),
                '26-50'   => (int) ($row['groups_26_50'] ?? 0),
                '51-100'  => (int) ($row['groups_51_100'] ?? 0),
                '101-200' => (int) ($row['groups_101_200'] ?? 0),
                '200+'    => (int) ($row['groups_200_plus'] ?? 0),
            ],
            'avg'   => $groups_count > 0 ? round(((int) $row['groups_pax_sum']) / $groups_count, 1) : 0,
            'count' => $groups_count,
        ],
    ];
}

// B.7 — "Where are our quotes": by country, and for India by the 8 real sales regions. A
// DIFFERENT population from B.1-B.6: Created/Requote only, not the wider live basket, no future-
// trip-date filter. Two data-quality fixes: (1) case-only duplicates (e.g. 'Phillippines' vs
// 'PHILIPPINES') normalised with UPPER(TRIM()) before GROUP BY; (2) assigned_to_region is free
// text with no FK, resolved against vtiger_groups.groupname (also normalised) — a region matching
// nothing there is silently excluded, not bucketed as "unknown". vtiger_groups (~16 rows) is
// loaded into a PHP lookup rather than a SQL JOIN since both sides need normalising.
function build_quote_geography(mysqli $conn): array
{
    $groups_res = $conn->query('SELECT groupname, country FROM vtiger_groups');
    $group_lookup = []; // normalised groupname => ['label' => groupname, 'country' => country]
    if ($groups_res) {
        while ($row = $groups_res->fetch_assoc()) {
            $group_lookup[strtoupper(trim($row['groupname']))] = [
                'label'   => $row['groupname'],
                'country' => $row['country'],
            ];
        }
        $groups_res->free();
    }

    $res = $conn->query("
        SELECT UPPER(TRIM(vqinfo.assigned_to_region)) AS region_key, COUNT(*) AS n
        FROM vtiger_quotes vq
        JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vq.quoteid
        WHERE vq.deleted = 0
          AND vq.quotestage IN ('Created', 'Requote')
          AND vqinfo.assigned_to_region IS NOT NULL
          AND vqinfo.assigned_to_region != ''
        GROUP BY region_key
    ");

    $by_region  = []; // India only — real region label => n (only regions with data appear)
    $by_country = []; // country label => n ('India' rolled up from all matching India regions)
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $n = (int)$row['n'];
            $group = $group_lookup[$row['region_key']] ?? null;
            if ($group === null) continue; // no matching vtiger_groups row — can't classify
            if ($group['country'] === 'India') {
                $by_region[$group['label']] = ($by_region[$group['label']] ?? 0) + $n;
                $by_country['India'] = ($by_country['India'] ?? 0) + $n;
            } else {
                $country = $group['country'] ?: $group['label'];
                $by_country[$country] = ($by_country[$country] ?? 0) + $n;
            }
        }
        $res->free();
    }
    arsort($by_region);
    arsort($by_country);
    return ['by_country' => $by_country, 'by_region' => $by_region];
}

// Row-level export behind the "Download next 3 months report (CSV)" button (see
// ajax_export_next_3_months.php) — raw data for the user's own analysis in Excel, not a chart.
// India regions only, FIT and Groups & MICE both (no quote_no LIKE '%G' split — the user's own
// report layout groups all callers together by design). Window is the current month
// plus the next two full months, recomputed on every call rather than fixed dates, so the export
// always covers "now + 2". stage_bucket reuses accepted_stages_sql() (same literal list as
// account insights and the travel-date horizon use) so this can't drift from the dashboard's
// own Accepted definition.
function build_next_3_months_rows(mysqli $conn, array $india_regions): array
{
    if (!$india_regions) return [];
    $regions_in  = sql_in_list($conn, $india_regions);
    $accepted_in = accepted_stages_sql($conn);
    $rejected_in = sql_in_list($conn, [
        'Rejected', 'Rejected After Confrmation QA pending', 'Rejected After Confrmation QA completed',
    ]);

    $res = $conn->query("
        SELECT
            vq.quoteid,
            vq.quote_no,
            va.organization_name,
            vqinfo.assigned_to_sales_agent,
            vqinfo.assigned_to_region,
            vq.quotestage,
            CASE
                WHEN vq.quotestage = 'Created' THEN 'Created'
                WHEN vq.quotestage = 'Requote' THEN 'Requote'
                WHEN vq.quotestage IN ($accepted_in) THEN 'Accepted'
                WHEN vq.quotestage IN ($rejected_in) THEN 'Rejected'
                ELSE 'Other'
            END AS stage_bucket,
            vqinfo.priority,
            CASE vqinfo.priority
                WHEN 'high' THEN 'High'
                WHEN 'low' THEN 'Low'
                WHEN 'not connected' THEN 'Not connected'
                ELSE 'Blank'
            END AS priority_label,
            vq.created_at,
            vqcf.cf_1162 AS trip_date,
            DATE_FORMAT(vqcf.cf_1162, '%Y-%m') AS trip_month
        FROM vtiger_quotes vq
        LEFT JOIN tdu_organisation va ON vq.accountid = va.organizationid
        LEFT JOIN vtiger_quotescf vqcf ON vq.quoteid = vqcf.quoteid
        LEFT JOIN vtiger_quotes_info vqinfo ON vq.quoteid = vqinfo.quoteid
        WHERE vq.deleted = 0
          AND vqinfo.assigned_to_region IN ($regions_in)
          AND vqcf.cf_1162 >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
          AND vqcf.cf_1162 <  DATE_FORMAT(CURDATE() + INTERVAL 3 MONTH, '%Y-%m-01')
        ORDER BY vqinfo.assigned_to_sales_agent, trip_date
    ");

    $out = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['organization_name'] = utf8_safe($row['organization_name']);
            $out[] = $row;
        }
        $res->free();
    }
    return $out;
}
