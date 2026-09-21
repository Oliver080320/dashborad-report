<?php
// Regional model Leads queue: one controller for every region owner, replacing the
// per-team split in leads.php. Expects $conn, $today, $session_user and $viewer_is_admin
// set by the router (queue.php), except in cron mode (see below).
//
// A separate file rather than a mode flag on region_queue.php, and the reasoning matters
// because the project's own precedent points the other way: fit_viewer.php took Leads as a
// flag, under "split on authority, share on data". Authority is identical here, so the
// split rests on cost, and it genuinely does. fit_viewer.php's Leads mode only swapped
// which snapshot keys it read and never built anything, while this queue's whole build
// pipeline differs: a different cohort, a different stage, and no budget, weight or split
// at all, since every qualifying Lead surfaces every day.
//
// Both models run side by side during the transition: leads.php stays fully in place and
// routable for a legacy caller who is not yet a region owner. This does not touch daily_runs
// (the old table) at all, only tdu_region_daily_runs, under its own lead_* buckets.
if (!defined('DAILY_CAPACITY')) require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/lead_builder.php';
if (!isset($today)) $today = date('Y-m-d');

// ---------------------------------------------------------------------------
// Cron entry point: cron_daily_runs.php sets $cron_build_lead_regions (one owner's full
// region group, or a single unowned region) and requires this file directly, with no
// session/access context at all, since an unowned region has no user_name to derive one from.
// Builds and writes, then stops: no view to render for a machine, and rendering would
// need a $session_user this path was never given. The sentinel is deliberately named
// differently from region_queue.php's, so a stale one from an earlier loop in the same
// process can never make one file build the other's queue.
// ---------------------------------------------------------------------------
if (isset($cron_build_lead_regions)) {
    build_and_write_lead_regions($conn, $today, $cron_build_lead_regions);
    return;
}

if (!isset($session_user))    $session_user    = '';
if (!isset($viewer_is_admin)) $viewer_is_admin = false;

// Own-view / merged-view toggle. Same session key region_queue.php uses, keyed per
// session_user, so a supervisor's choice carries across Follow-up and Leads without
// being repeated. There is no personal Leads queue (pull is Follow-up only), so
// tdu_resolve_region_scope()'s personal_claim_applies stays at its false default here.
if (isset($_GET['scope_view']) && in_array($_GET['scope_view'], ['own', 'all'], true)) {
    $_SESSION['tdu_scope_view'][$session_user] = $_GET['scope_view'];
}
$scope_pref = $_SESSION['tdu_scope_view'][$session_user] ?? 'own';

// Scope: several separate questions, never collapsed to one flag. Resolved by
// tdu_resolve_region_scope() in config.php, shared with the other region controllers;
// the reasoning behind each flag, including the "View as" bug that shipped once, is
// documented there rather than repeated per controller.
$scope           = tdu_resolve_region_scope($session_user, $viewer_is_admin, $scope_pref === 'all');
$my_regions      = $scope['my_regions'];
$is_supervisor   = $scope['is_supervisor'];
$can_see_all     = $scope['can_see_all'];
$sees_all        = $scope['sees_all'];
$may_build       = $scope['may_build'];
$owns_nothing    = $scope['owns_nothing'];
$has_own_default = !empty($my_regions);

// Defence in depth: re-derive access here regardless of how this file was reached, the
// same convention region_queue.php and fit_viewer.php use, rather than trusting routing.
if ($owns_nothing && !$sees_all) {
    http_response_code(403);
    exit('Forbidden');
}

$is_readonly = false; // every role reaching this file (owner, supervisor, admin) may log outcomes
$is_manager  = $sees_all;

$regions_to_show = $sees_all ? array_keys(QUEUE_REGIONS) : $my_regions;

// No personal-claim pull on Leads, so the only person with an own view to toggle away from
// here is a supervisor who owns a region (Prajna). can_see_all, not (is_supervisor ||
// viewer_is_admin), for the same reason as region_queue.php: an admin using "View as" on a
// plain owner cannot reach the merged view, so the link would only reload the same page.
$show_scope_toggle  = $has_own_default && $can_see_all;
$can_personal_claim = false;
$can_claim_search   = false; // no personal-claim queue exists for Leads

// Drives queue_view.php's region chip independently of $is_manager: a plain owner with two
// regions (Mayur) needs the chip to tell West from Gujarat on his own page too.
$show_region_chip = count($regions_to_show) > 1;

// load_region_from_daily_runs() keys each tier by its literal bucket name while still
// initialising sp1..sp4 empty, so reading 'sp1' here would silently yield an empty queue
// with no error. Normalise both paths to sp1..sp4: a fresh build returns those keys from
// build_lead_queue(), a reload returns lead_sp1..lead_sp4.
$normalise_lead_built = function (array $b): array {
    return [
        'sp1' => $b['lead_sp1'] ?? $b['sp1'] ?? [],
        'sp2' => $b['lead_sp2'] ?? $b['sp2'] ?? [],
        'sp3' => $b['lead_sp3'] ?? $b['sp3'] ?? [],
        'sp4' => $b['lead_sp4'] ?? $b['sp4'] ?? [],
    ];
};

// ---------------------------------------------------------------------------
// Load. From tdu_region_daily_runs if already generated today, else compute fresh.
// 'built'/'carryover' are partial-data safe: some regions may not have loaded yet.
// ---------------------------------------------------------------------------
$dr_data           = load_lead_region_from_daily_runs($conn, $today, $regions_to_show);
$region_built      = $dr_data['found'] ? $dr_data['built']        : [];
$worked_today      = $dr_data['found'] ? $dr_data['worked_today'] : [];
$carryover_raw_all = $dr_data['found'] ? $dr_data['carryover']    : [];

// Never build the snapshot from a manager/admin/supervisor page load, which would silently
// repair a failed cron and destroy the only signal it failed. Gated on $may_build, which
// already accounts for an admin impersonating a plain owner (see config.php).
if ($may_build) {
    // Asked of this owner's whole group, not per region (tdu_pending_regions()), since
    // build_and_write_lead_regions() takes the group: per region, an owner covering a
    // region with no Leads rebuilt the group on every page load.
    // write_lead_region_daily_runs()'s own already-exists guard protects any region that
    // has already been written.
    $regions_present = array_unique(array_merge(array_keys($region_built), array_keys($carryover_raw_all)));
    if (!empty(tdu_pending_regions($my_regions, $regions_present))) {
        $fresh = build_and_write_lead_regions($conn, $today, $my_regions);
        foreach ($my_regions as $region) {
            if (isset($region_built[$region])) continue;
            $region_built[$region]      = $fresh['built'][$region]     ?? ['sp1' => [], 'sp2' => [], 'sp3' => [], 'sp4' => []];
            $carryover_raw_all[$region] = $fresh['carryover'][$region] ?? [];
        }
    }
}

// ---------------------------------------------------------------------------
// Build the view arrays (shared between first-load and reload)
// ---------------------------------------------------------------------------
$lead_sp1_groups = $lead_sp2_groups = $lead_sp3_groups = $lead_sp4_groups = [];
foreach ($regions_to_show as $region) {
    if (!isset($region_built[$region])) continue;
    $b = $normalise_lead_built($region_built[$region]);
    $lead_sp1_groups = array_merge($lead_sp1_groups, $b['sp1']);
    $lead_sp2_groups = array_merge($lead_sp2_groups, $b['sp2']);
    $lead_sp3_groups = array_merge($lead_sp3_groups, $b['sp3']);
    $lead_sp4_groups = array_merge($lead_sp4_groups, $b['sp4']);
}

$carryover_all_ids = [];
foreach ($carryover_raw_all as $_orgs) {
    foreach ($_orgs as $_og) {
        foreach ($_og as $_q) $carryover_all_ids[(int)$_q['quoteid']] = true;
    }
}
$carryover_groups = [];
foreach ($regions_to_show as $region) {
    if (!empty($carryover_raw_all[$region])) {
        $carryover_groups = array_merge($carryover_groups, $carryover_raw_all[$region]);
    }
}
if (!empty($carryover_all_ids)) {
    $lead_sp1_groups = filter_carryover_from_bucket($lead_sp1_groups, $carryover_all_ids);
    $lead_sp2_groups = filter_carryover_from_bucket($lead_sp2_groups, $carryover_all_ids);
    $lead_sp3_groups = filter_carryover_from_bucket($lead_sp3_groups, $carryover_all_ids);
    $lead_sp4_groups = filter_carryover_from_bucket($lead_sp4_groups, $carryover_all_ids);
}

// Which of the regions being shown have not loaded today. Iterates QUEUE_REGIONS (never
// REGION_OWNERS) so an unowned region still surfaces instead of vanishing silently. Only
// meaningful in the merged view; a plain owner's own regions are always built by now.
$regions_pending = $sees_all
    ? tdu_pending_regions(
        array_keys(QUEUE_REGIONS),
        array_unique(array_merge(array_keys($region_built), array_keys($carryover_raw_all)))
      )
    : [];

// ---------------------------------------------------------------------------
// View config. $is_lead_queue switches the view into Leads mode: different outcome
// options, the 'L' badge held for the whole day, the Leads scheduling window, and the
// Accepted-blocked machinery skipped entirely (a Lead cannot be marked Accepted, its only
// stage exits are Created and Rejected).
// ---------------------------------------------------------------------------
$is_lead_queue          = true;
// Personal-claim users merged in for the same reason region_queue.php does: Karthik owns
// no region, so he'd otherwise be missing from admin's "View as" dropdown entirely. He has
// no personal Leads queue of his own here, just falls back to the merged view like today.
$callers                = tdu_region_people();
$crm_quotetype          = 'fit-dashboard'; // one merged cohort, same choice region_queue.php makes
$my_fullname            = $my_regions ? tdu_region_owner_name($my_regions[0]) : null;
$accepted_blocked       = [];

// Same conflict banner region_queue.php shows: a region claimed by two people in the
// auto-assign rules has no owner and so is absent from this page.
$pending_warning = tdu_region_conflict_banner($sees_all ? [] : tdu_region_conflicts_for_user($session_user));

$pending_warning .= !empty($regions_pending)
    ? '<p style="background:#fff3cd;border:1px solid #ffc107;padding:8px 14px;border-radius:4px;font-size:0.85rem;margin-bottom:12px;">'
      . '&#9888; Leads queue not yet loaded today for region(s): <strong>'
      . implode(', ', array_map('htmlspecialchars', $regions_pending))
      . '</strong>. Their leads will appear once the daily run has built them.</p>'
    : '';

require_once __DIR__ . '/../views/queue_view.php';
