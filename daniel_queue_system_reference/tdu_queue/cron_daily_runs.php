<?php
// Daily queue pre-generation cron.
// Runs every morning before callers start so the manager view is ready immediately.
// Safe to run multiple times, write_region_daily_runs() skips if today's snapshot already exists.
//
// cPanel cron: 0 9 * * *
// (09:00 Melbourne = 03:30 India)

require_once __DIR__ . '/config.php';

$today     = date('Y-m-d');
$cron_mode = true;

if (date('N') === '7') {
    exit(0); // no run on Sundays
}

// ---------------------------------------------------------------------------
// Follow-up, per region. The two legacy caller passes that used to run first, and the
// two legacy Leads passes after them, are gone with their controllers; this writes only
// tdu_region_daily_runs. The old daily_runs table is never written to again, and is kept
// as frozen history rather than cleaned up.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/queues/queue_builder.php';

// Full-cohort correction first, once, before any region's daily build — not just the
// budget-capped subset a single build_and_write_regions() call would classify today.
// The region model has no gradual takeover: ownership is a fact derived
// from the region, corrected continuously for the whole cohort, not eased in a few
// quotes at a time the way the old count-balance assignment was.
// Every active personal-claim holder's full name (Karthik today) — exempt from this pass,
// or a quote he pulled to himself yesterday gets swept straight back to its region owner
// overnight. See correct_region_ownership()'s own comment for why this needs no separate
// "claims" table: exempt = whoever assigned_to_sales_agent already says.
$personal_claim_users = tdu_personal_claim_users();
$exempt_fullnames     = array_values($personal_claim_users);

$region_cohort  = fetch_region_cohort($conn, $today, INDIA_REGIONS);
$region_changed = correct_region_ownership($conn, $region_cohort, $exempt_fullnames);
if (!empty($region_changed)) {
    error_log('[TDU Queue] cron: corrected region ownership for ' . count($region_changed) . ' quotes.');
}

// Build every region, grouped by owner — never iterate REGION_OWNERS alone, or a region
// with no owner would never get built. A person's regions are always
// built together in one group: person_followup_budget()'s blended-budget math only makes
// sense computed across their whole day at once, so West and Gujarat must reach
// build_and_write_regions() in the same call for Mayur, never two separate ones. An
// unowned region is its own group of one.
$region_groups = []; // group key => [queue_region, ...]
foreach (array_keys(QUEUE_REGIONS) as $region) {
    $owner_uname = REGION_OWNERS[$region]['user_name'] ?? null;
    $group_key   = $owner_uname ?? ('__unowned__' . $region);
    $region_groups[$group_key][] = $region;
}
foreach ($region_groups as $regions_in_group) {
    // Plain require (not require_once): region_queue.php has to run its full body once
    // per owner group in this single process, so require_once would build only the first.
    $cron_build_regions = $regions_in_group;
    require __DIR__ . '/queues/region_queue.php';
    unset($cron_build_regions);
}

// Builds each personal-claim holder's own working queue. Follow-up only (Created/Requote) —
// there is no personal Leads queue.
foreach ($personal_claim_users as $_pc_uname => $_pc_fullname) {
    $cron_build_personal_claim = [$_pc_uname, $_pc_fullname];
    require __DIR__ . '/queues/region_queue.php';
    unset($cron_build_personal_claim);
}
unset($_pc_uname, $_pc_fullname);

// Leads, region model. Its own full-cohort correction pass, because fetch_region_cohort()
// above is Created/Requote only and so never sees a Lead: without this, a Lead's owner
// would only ever be corrected on the days it happened to surface.
require_once __DIR__ . '/queues/lead_builder.php';

$lead_cohort  = fetch_region_lead_cohort($conn, $today, INDIA_REGIONS);
// Exempt here too, defensively, even though a Lead pull isn't built: if a Lead ever ends up
// under a personal-claim holder's name by some other path, this stops it self-reverting.
$lead_changed = correct_region_ownership($conn, $lead_cohort, $exempt_fullnames);
if (!empty($lead_changed)) {
    error_log('[TDU Queue] cron: corrected region ownership for ' . count($lead_changed) . ' leads.');
}

// Same grouping as Follow-up above, and grouped for consistency rather than necessity:
// Leads has no budget to blend across a person's regions, so each group could equally be
// built one region at a time. Kept identical so the two loops cannot drift apart.
foreach ($region_groups as $regions_in_group) {
    $cron_build_lead_regions = $regions_in_group;
    require __DIR__ . '/queues/region_leads.php';
    unset($cron_build_lead_regions);
}
