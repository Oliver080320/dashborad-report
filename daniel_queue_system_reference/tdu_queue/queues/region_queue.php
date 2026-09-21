<?php
// Regional model Follow-up queue — one controller for every region owner, replacing the
// India FIT / Groups & MICE split. Merges FIT + Groups into one cohort with one budget,
// since a region owner works both. Expects $conn, $today, $session_user and
// $viewer_is_admin set by the router (queue.php), except in cron mode (see below).
//
// Both models run side by side during the transition: india_callers.php/groups_mice.php
// stay fully in place and routable for anyone not yet a region owner. This file does not
// touch daily_runs (the old table) at all — only tdu_region_daily_runs.
if (!defined('DAILY_CAPACITY')) require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/queue_builder.php';
if (!isset($today)) $today = date('Y-m-d');

// ---------------------------------------------------------------------------
// Cron entry point: cron_daily_runs.php sets $cron_build_regions (one owner's full
// region group, or a single unowned region) and requires this file directly, with no
// session/access context at all — an unowned region has no user_name to derive one
// from. Builds and writes, then stops: no view to render for a machine, and rendering
// would need a $session_user this path was never given.
// ---------------------------------------------------------------------------
if (isset($cron_build_regions)) {
    build_and_write_regions($conn, $today, $cron_build_regions);
    return;
}

// Cron entry point for a personal-claim holder's own queue. $cron_build_personal_claim
// is [$user_name, $fullname], set by cron_daily_runs.php's own loop over tdu_personal_claim_users().
if (isset($cron_build_personal_claim)) {
    [$_pc_uname, $_pc_fullname] = $cron_build_personal_claim;
    build_and_write_personal_claims($conn, $today, $_pc_uname, $_pc_fullname);
    return;
}

if (!isset($session_user))    $session_user    = '';
if (!isset($viewer_is_admin)) $viewer_is_admin = false;

// Own-view / merged-view toggle, session-persisted per session_user so the choice
// survives navigating between Follow-up and Leads without being repeated on every link.
// This is the one place $_SESSION is touched for it — tdu_resolve_region_scope() itself
// stays a pure function.
if (isset($_GET['scope_view']) && in_array($_GET['scope_view'], ['own', 'all'], true)) {
    $_SESSION['tdu_scope_view'][$session_user] = $_GET['scope_view'];
}
$scope_pref = $_SESSION['tdu_scope_view'][$session_user] ?? 'own';

// Scope: several separate questions, never collapsed to one flag. Resolved by
// tdu_resolve_region_scope() in config.php, shared with the other region controllers;
// the reasoning behind each flag, including the "View as" bug that shipped once, is
// documented there rather than repeated per controller. personal_claim_applies = true:
// this is the one controller with a build path for it (Follow-up only).
$scope              = tdu_resolve_region_scope($session_user, $viewer_is_admin, $scope_pref === 'all', true);
$my_regions         = $scope['my_regions'];
$is_supervisor      = $scope['is_supervisor'];
$has_personal_claim = $scope['has_personal_claim'];
$can_see_all        = $scope['can_see_all'];
$sees_all           = $scope['sees_all'];
$may_build          = $scope['may_build'];
$owns_nothing       = $scope['owns_nothing'];
$has_own_default    = !empty($my_regions) || $has_personal_claim;

// Defence in depth: re-derive access here regardless of how this file was reached,
// the same convention fit_viewer.php uses, rather than trusting queue.php's routing.
if ($owns_nothing && !$sees_all && !$has_personal_claim) {
    http_response_code(403);
    exit('Forbidden');
}

$is_readonly = false; // every role reaching this file (owner, supervisor, admin) may log outcomes
$is_manager  = $sees_all;

if ($sees_all) {
    $regions_to_show = array_keys(QUEUE_REGIONS);
} elseif (!empty($my_regions)) {
    $regions_to_show = $my_regions;
} elseif ($has_personal_claim) {
    $regions_to_show = ['personal:' . $session_user];
} else {
    $regions_to_show = [];
}

// Drives queue_view.php's region chip independently of $is_manager: a plain owner with
// two regions (Mayur, or an admin impersonating him) needs the chip to tell West from
// Gujarat even though $is_manager/$sees_all is false for that view.
$show_region_chip = count($regions_to_show) > 1;

// Can Karthik (or a future supervisor in the same position) pull an unclaimed quote into
// their personal queue right now? Only while actually looking at the merged view, the
// button lives on rows that aren't theirs yet, which only exist on screen there.
$can_personal_claim = $has_personal_claim && $sees_all;

// Search Quote's own claim button is not tied to which view is currently on screen: it
// looks up a quote by number regardless of what's rendered, so the capability itself
// (has_personal_claim) is what should gate it, not sees_all. Keeping it tied to
// can_personal_claim left the button invisible on Karthik's own default landing page,
// which is now his personal claim queue, not the merged view.
$can_claim_search = $has_personal_claim;

// Only render the own-view/merged-view toggle for someone who has both an "own" view to
// toggle away from and the authority to reach the merged one. Keying the second half off
// can_see_all rather than (is_supervisor || viewer_is_admin) matters for one case: an admin
// using "View as" on a plain owner has can_see_all forced false, so the merged view is
// unreachable there and the link only ever reloaded the same page.
$show_scope_toggle = $has_own_default && $can_see_all;

// ---------------------------------------------------------------------------
// Load — from tdu_region_daily_runs if already generated today, else compute fresh.
// 'built'/'carryover' are partial-data safe: some regions may not have loaded yet.
// ---------------------------------------------------------------------------
$dr_data           = load_region_from_daily_runs($conn, $today, $regions_to_show);
$region_built       = $dr_data['found'] ? $dr_data['built']      : [];
$worked_today       = $dr_data['found'] ? $dr_data['worked_today'] : [];
$carryover_raw_all  = $dr_data['found'] ? $dr_data['carryover']  : [];

// Never build the snapshot from a manager/admin/supervisor page load — that would
// silently repair a failed cron and destroy the only signal it failed. all_callers.php
// already holds this invariant; it must not get lost here. Gated on $may_build, not
// $sees_all: an admin impersonating a plain owner has $sees_all = false (narrowed to
// that owner's own regions) but must still never trigger a build.
if ($may_build) {
    // A plain region owner may cover more than one region (Mayur: West + Gujarat); build
    // only whichever of THEIR OWN regions has not loaded yet today. The budget math is
    // still computed across all of $my_regions together (build_and_write_regions takes
    // the whole group), so an already-built region's frozen slots are never revisited —
    // only the missing ones get written, via write_region_daily_runs()'s own
    // already-exists guard.
    if (!empty($my_regions)) {
        // Asked of the group, not per region (tdu_pending_regions()): this owner's regions
        // are built together in one call, so any of them having rows means the day already
        // ran. Per region, an owner covering a legitimately empty region rebuilt the whole
        // group on every page load, ownership correction included.
        $regions_present = array_unique(array_merge(array_keys($region_built), array_keys($carryover_raw_all)));
        if (!empty(tdu_pending_regions($my_regions, $regions_present))) {
            $fresh = build_and_write_regions($conn, $today, $my_regions);
            foreach ($my_regions as $region) {
                if (isset($region_built[$region])) continue;
                $region_built[$region]      = $fresh['built'][$region]      ?? ['sp1' => [], 'sp2' => [], 'sp3' => [], 'sp4' => []];
                $carryover_raw_all[$region] = $fresh['carryover'][$region]  ?? [];
            }
        }
    } elseif ($has_personal_claim) {
        // No region to build — this is Karthik's own personal claim queue instead.
        $personal_region = 'personal:' . $session_user;
        if (!isset($region_built[$personal_region])) {
            $fresh = build_and_write_personal_claims($conn, $today, $session_user, QUEUE_ACCESS_FULLNAMES[$session_user]);
            $region_built[$personal_region]      = $fresh['built'][$personal_region]      ?? ['sp1' => [], 'sp2' => [], 'sp3' => [], 'sp4' => []];
            $carryover_raw_all[$personal_region] = $fresh['carryover'][$personal_region]  ?? [];
        }
    }
}

// ---------------------------------------------------------------------------
// Build the view arrays (shared between first-load and reload)
// ---------------------------------------------------------------------------
$sp1_groups = $sp2_groups = $sp3_groups = $sp4_groups = [];
foreach ($regions_to_show as $region) {
    if (!isset($region_built[$region])) continue;
    $b = $region_built[$region];
    $sp1_groups = array_merge($sp1_groups, $b['sp1']);
    $sp2_groups = array_merge($sp2_groups, $b['sp2']);
    $sp3_groups = array_merge($sp3_groups, $b['sp3']);
    $sp4_groups = array_merge($sp4_groups, $b['sp4']);
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
    $sp1_groups = filter_carryover_from_bucket($sp1_groups, $carryover_all_ids);
    $sp2_groups = filter_carryover_from_bucket($sp2_groups, $carryover_all_ids);
    $sp3_groups = filter_carryover_from_bucket($sp3_groups, $carryover_all_ids);
    $sp4_groups = filter_carryover_from_bucket($sp4_groups, $carryover_all_ids);
}

// Which of the regions being shown have not loaded today. Iterates QUEUE_REGIONS (never
// REGION_OWNERS) so an unowned region still surfaces here instead of vanishing silently
// Only meaningful in the merged/all-region view: a plain owner's own regions are always
// fully built by this point (see the build-missing pass above).
$regions_pending = $sees_all
    ? tdu_pending_regions(
        array_keys(QUEUE_REGIONS),
        array_unique(array_merge(array_keys($region_built), array_keys($carryover_raw_all)))
      )
    : [];

// ---------------------------------------------------------------------------
// View config
// ---------------------------------------------------------------------------
// Personal-claim users merged in so admin's "View as" dropdown offers Karthik at all —
// he owns no region, so tdu_region_owner_names() alone never lists him.
$callers           = tdu_region_people();
$crm_quotetype     = 'fit-dashboard';
$my_fullname       = $my_regions
    ? tdu_region_owner_name($my_regions[0])
    : ($has_personal_claim ? (QUEUE_ACCESS_FULLNAMES[$session_user] ?? null) : null);

// A region claimed by two people in the CRM's auto-assign rules has no owner, so it is
// missing from this page entirely. Say so above the queue rather than letting it vanish:
// the merged view reports every conflict, a plain owner only the ones naming them.
$pending_warning = tdu_region_conflict_banner($sees_all ? [] : tdu_region_conflicts_for_user($session_user));

$pending_warning .= !empty($regions_pending)
    ? '<p style="background:#fff3cd;border:1px solid #ffc107;padding:8px 14px;border-radius:4px;font-size:0.85rem;margin-bottom:12px;">'
      . '&#9888; Queue not yet loaded today for region(s): <strong>'
      . implode(', ', array_map('htmlspecialchars', $regions_pending))
      . '</strong>. Their quotes will appear once the daily run has built them.</p>'
    : '';

$accepted_blocked = compute_accepted_blocked($conn, $carryover_groups, $sp1_groups, $sp2_groups, $sp3_groups, $sp4_groups);

// Awaiting Information and Payment Deadline are deliberately not wired in here (decision
// #10): both move to a fixed Hemant/Dhiraj pair, access-gated across all five regions,
// built in Steps 6-7. $awaiting_mine/$awaiting_pool are left unset, so queue_view.php's
// render_awaiting_info_section() sees two empty arrays and renders nothing.

require_once __DIR__ . '/../views/queue_view.php';
