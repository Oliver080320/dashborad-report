<?php
// Leads queue logic. Mirrors queue_builder.php's Follow-up cascade for quotes sitting at the
// 'Lead' stage, one step before 'Created' in the CRM's stage flow.
//
// The one structural difference: no slot budget. Every qualifying Lead surfaces every day.
// The SP1-4 tiers still control the order rows appear in, they just never cut the list off.
if (!defined('DAILY_CAPACITY')) require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/queue_builder.php';

// Classifies one caller's owned Leads into the four tiers and groups each tier by
// organisation, so all of an org's Leads are worked in one call. Same tier semantics and
// return shape as build_caller_queue(), minus the cap.
function build_lead_queue(array $owned_quotes, string $today): array
{
    $sp1 = [];
    $sp2 = [];
    $sp3 = [];
    $sp4 = [];

    foreach ($owned_quotes as $q) {
        $called       = (int) $q['call_count'] > 0;
        // Same derivation as build_caller_queue(): read the live promise (next_call_date),
        // not the snooze_until copy, which can go stale relative to an edited promise.
        $snoozed      = !empty($q['next_call_date']) && $q['next_call_date'] > $today;
        $created_days = (int) floor((strtotime($today) - strtotime($q['created_at'])) / 86400);
        $trip_days    = (int) floor((strtotime($q['trip_start_date']) - strtotime($today)) / 86400);

        $due_today            = !empty($q['next_call_date']) && $q['next_call_date'] <= $today;
        $returning_interested = ($q['last_outcome'] === 'interested');

        if ($snoozed) {
            // In cool-down, skip for today.
        } elseif ($due_today || $returning_interested) {
            $sp1[] = $q;
        } elseif (!$called && $created_days <= FOLLOWUP_CREATED_DAYS) {
            $sp2[] = $q;
        } elseif ($trip_days >= 0 && $trip_days <= FOLLOWUP_TRAVEL_DATE_WINDOW_DAYS) {
            $sp3[] = $q;
        } else {
            $sp4[] = $q;
        }
    }

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

        if ($a_due === 0 && $b_due === 0) {
            $cmp = strcmp($a['next_call_date'], $b['next_call_date']);
            if ($cmp !== 0) return $cmp;
        }

        return strcmp($a['created_at'], $b['created_at']);
    };

    usort($sp2, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    usort($sp3, fn($a, $b) => strcmp($a['trip_start_date'], $b['trip_start_date']));
    usort($sp4, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));

    // Org promotion, precedence SP1 > SP2 > SP3. An org's Leads are all worked in one call,
    // so they are pulled up to the highest tier any of them reaches. Runs before the final
    // sorts so promoted rows land in their correct position rather than at the end.
    $sp1_orgs = array_flip(array_column($sp1, 'organizationid'));
    $sp2_keep = [];
    foreach ($sp2 as $q) {
        if (isset($sp1_orgs[$q['organizationid']])) { $sp1[] = $q; } else { $sp2_keep[] = $q; }
    }
    $sp2 = $sp2_keep;

    $sp1_orgs = array_flip(array_column($sp1, 'organizationid'));
    $sp2_orgs = array_flip(array_column($sp2, 'organizationid'));
    $sp3_keep = [];
    foreach ($sp3 as $q) {
        $oid = $q['organizationid'];
        if (isset($sp1_orgs[$oid]))      { $sp1[] = $q; }
        elseif (isset($sp2_orgs[$oid]))  { $sp2[] = $q; }
        else                             { $sp3_keep[] = $q; }
    }
    $sp3 = $sp3_keep;

    $sp1_orgs = array_flip(array_column($sp1, 'organizationid'));
    $sp2_orgs = array_flip(array_column($sp2, 'organizationid'));
    $sp3_orgs = array_flip(array_column($sp3, 'organizationid'));
    $sp4_keep = [];
    foreach ($sp4 as $q) {
        $oid = $q['organizationid'];
        if (isset($sp1_orgs[$oid]))      { $sp1[] = $q; }
        elseif (isset($sp2_orgs[$oid]))  { $sp2[] = $q; }
        elseif (isset($sp3_orgs[$oid]))  { $sp3[] = $q; }
        else                             { $sp4_keep[] = $q; }
    }
    $sp4 = $sp4_keep;

    usort($sp1, $sp1_sort);
    usort($sp2, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    usort($sp3, fn($a, $b) => strcmp($a['trip_start_date'], $b['trip_start_date']));
    usort($sp4, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));

    $group_by_org = function (array $quotes): array {
        $orgs = [];
        foreach ($quotes as $q) {
            $orgs[$q['organizationid'] ?? 'unknown'][] = $q;
        }
        return array_values($orgs);
    };

    // No cap: every tier returns every org group it holds.
    return [
        'sp1' => $group_by_org($sp1),
        'sp2' => $group_by_org($sp2),
        'sp3' => $group_by_org($sp3),
        'sp4' => $group_by_org($sp4),
    ];
}

// ---------------------------------------------------------------------------
// Region model. Everything below reads and writes tdu_region_daily_runs only, never
// the old daily_runs table.
// ---------------------------------------------------------------------------

// One cohort for every region, replacing fetch_lead_cohort()'s FIT/Groups split: under the
// region model a region's owner works both, so the 'G' suffix no longer routes anything and
// survives only as the 'quote_type' display field.
//
// This is also stricter than the team version it replaces, deliberately. That one applied
// the India region filter to FIT alone and left the Groups arm as a bare "quote_no LIKE
// '%G'", so any Groups Lead reached the queue regardless of where it belonged. Requiring a
// region for every row closes that: measured on the 2026-08-20 staging copy, two Leads
// leave the India queues as a result, one against Thailand and one against the Philippines,
// both genuinely outside this team's scope rather than orphans missing a region.
function fetch_region_lead_cohort(mysqli $conn, string $today, array $india_regions): array
{
    $today_sql  = $conn->real_escape_string($today);
    $stage_sql  = $conn->real_escape_string(LEAD_STAGE_NAME);
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
            AND vq.quotestage = '$stage_sql'
            AND vqcf.cf_1162 > '$today_sql'
            AND vqinfo.assigned_to_region IN ($regions_in)
    ";

    $result = $conn->query($sql);
    if ($result === false) {
        error_log('[TDU Queue] fetch_region_lead_cohort query failed: ' . $conn->error);
        return [];
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

// Snapshot helpers, region-keyed. Same lead_* bucket names the team version uses, so
// Follow-up and Leads coexist in tdu_region_daily_runs without a schema change: bucket is
// part of uq_run, so the two never collide on the same region and day.
function write_lead_region_daily_runs(mysqli $conn, string $run_date, string $region, array $built, array $carryover = []): void
{
    $remapped = [
        'lead_sp1' => $built['sp1'] ?? [],
        'lead_sp2' => $built['sp2'] ?? [],
        'lead_sp3' => $built['sp3'] ?? [],
        'lead_sp4' => $built['sp4'] ?? [],
    ];
    write_region_daily_runs(
        $conn, $run_date, $region, $remapped, $carryover,
        ['lead_sp1', 'lead_sp2', 'lead_sp3', 'lead_sp4'], 'lead_carryover'
    );
}

// Both readers pass the Lead worked-today sources for the same reason the team versions do:
// a Lead's terminal outcomes are stage-only ('Created' = converted, 'Rejected' = dropped)
// and its rejection reasons live in tdu_lead_closure_feedback, so the Follow-up defaults
// would find neither and a worked row would come back looking untouched.
function load_lead_region_from_daily_runs(mysqli $conn, string $run_date, array $regions): array
{
    return load_region_from_daily_runs(
        $conn, $run_date, $regions,
        ['lead_sp1', 'lead_sp2', 'lead_sp3', 'lead_sp4'], 'lead_carryover',
        lead_stage_outcomes(), 'tdu_lead_closure_feedback', 'lead_rejected'
    );
}

function load_lead_region_carryover_quotes(mysqli $conn, string $today, array $regions): array
{
    return load_region_carryover_quotes(
        $conn, $today, $regions, lead_buckets(), [LEAD_STAGE_NAME],
        lead_stage_outcomes(), 'tdu_lead_closure_feedback', 'lead_rejected'
    );
}

// Composes fetch -> partition -> classify -> write -> correct for one group of regions,
// the Leads counterpart of build_and_write_regions(). Shared by region_leads.php's own
// build path and cron_daily_runs.php's per-owner loop, so the two cannot diverge.
//
// Three deliberate differences from the Follow-up version: no weight, no budget and no
// split, because Leads has no slot cap and the whole qualifying cohort surfaces every day.
// Everything else (carry-over exclusion, org grouping, the ownership correction and the
// display-owner pass) follows it exactly.
function build_and_write_lead_regions(mysqli $conn, string $today, array $regions): array
{
    if (empty($regions)) return ['built' => [], 'carryover' => []];

    $carryover_raw = load_lead_region_carryover_quotes($conn, $today, $regions);
    $carryover_ids = [];
    foreach ($carryover_raw as $_orgs) {
        foreach ($_orgs as $_og) {
            foreach ($_og as $_q) $carryover_ids[(int)$_q['quoteid']] = true;
        }
    }

    $all_rows = fetch_region_lead_cohort($conn, $today, INDIA_REGIONS);

    $rows_by_region = [];
    foreach ($all_rows as $row) {
        $region = $row['queue_region'];
        if ($region === null || !in_array($region, $regions, true)) continue;
        if (isset($carryover_ids[(int)$row['quoteid']])) continue;
        $rows_by_region[$region][] = $row;
    }

    $built           = [];
    $classified_rows = [];
    foreach ($regions as $region) {
        $region_built   = build_lead_queue($rows_by_region[$region] ?? [], $today);
        $built[$region] = $region_built;

        foreach (['sp1', 'sp2', 'sp3', 'sp4'] as $sp) {
            foreach ($region_built[$sp] as $org_quotes) {
                foreach ($org_quotes as $q) $classified_rows[] = $q;
            }
        }

        write_lead_region_daily_runs($conn, $today, $region, $region_built, $carryover_raw[$region] ?? []);
    }

    // Bounded correction, same as the Follow-up build path: today's classified Leads plus
    // carry-over. The full-cohort Lead pass runs separately in the cron, because
    // fetch_region_cohort() is Created/Requote only and so never sees a Lead at all.
    $carryover_rows_flat = [];
    foreach ($carryover_raw as $_orgs) {
        foreach ($_orgs as $_og) {
            foreach ($_og as $_q) $carryover_rows_flat[] = $_q;
        }
    }
    correct_region_ownership($conn, array_merge($classified_rows, $carryover_rows_flat));

    // Display owner, set only after correct_region_ownership() has read each row's original
    // live-CRM owner above. Overwriting any earlier would make every row look already
    // correct and the correction would never fire.
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
