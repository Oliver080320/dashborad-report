<?php
// Manager / admin view for the regional model. Reads
// tdu_region_daily_runs (populated by each region's own first page load, or by the
// cron) and joins with live quote details. No query re-runs the classification; the
// manager sees exactly what each region computed when its snapshot was written.
//
// Defence in depth: this combined view is admin-only, regardless of how it was reached
// (router include or a direct URL). A non-admin must never see all regions' quotes.
// Never builds a snapshot itself (see below) — same invariant region_queue.php's own
// $may_build gate holds for a supervisor.

if (session_status() === PHP_SESSION_NONE) session_start();
if (($_SESSION['title'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}
$is_readonly = false; // always — this is the full-write admin manager view

if (!defined('DAILY_CAPACITY')) require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/queue_builder.php';
if (!isset($today))        $today        = date('Y-m-d');
if (!isset($session_user)) $session_user = 'manager';

// Identity map, region => region. Not a user_name/fullname map like the legacy version
// needed — load_region_from_daily_runs() is already keyed by queue_region directly —
// kept as a map only so the rest of this file's shape (iterating keys, building
// $regions_pending) needed no other change.
$all_callers_map = array_combine(array_keys(QUEUE_REGIONS), array_keys(QUEUE_REGIONS));

$dr_data = load_region_from_daily_runs($conn, $today, array_keys($all_callers_map));

$sp1_groups = $sp2_groups = $sp3_groups = $sp4_groups = [];
$worked_today    = [];
$regions_pending = array_keys($all_callers_map); // default: all pending

if ($dr_data['found']) {
    foreach ($dr_data['built'] as $region => $built) {
        $sp1_groups = array_merge($sp1_groups, $built['sp1']);
        $sp2_groups = array_merge($sp2_groups, $built['sp2']);
        $sp3_groups = array_merge($sp3_groups, $built['sp3']);
        $sp4_groups = array_merge($sp4_groups, $built['sp4']);
    }
    $worked_today = $dr_data['worked_today'];

    // Which regions have not loaded today? Carry-over counts as evidence the queue ran
    // (same reasoning the legacy per-caller warning used): 'built' is reconstructed from
    // the SP buckets alone, so a region whose whole day sits in carry-over would
    // otherwise read as never loaded. tdu_pending_regions() then asks the question per
    // owner group rather than per region, so a region that is simply empty is not
    // reported as unbuilt for the rest of the day.
    $regions_present = array_unique(array_merge(
        array_keys($dr_data['built']),
        array_keys($dr_data['carryover'])
    ));
    $regions_pending = tdu_pending_regions(array_keys($all_callers_map), $regions_present);
}

// Carry-over — frozen in tdu_region_daily_runs since each region's first load; empty
// until it opens. Excluded from the SP build at first load, so the buckets are disjoint;
// filter_carryover_from_bucket stays as a safety net (normally a no-op).
$carryover_raw    = $dr_data['found'] ? $dr_data['carryover'] : [];
$carryover_groups = [];
foreach (array_keys($all_callers_map) as $_region) {
    if (!empty($carryover_raw[$_region])) {
        $carryover_groups = array_merge($carryover_groups, $carryover_raw[$_region]);
    }
}
if (!empty($carryover_groups)) {
    $carryover_ids = [];
    foreach ($carryover_groups as $_co_org) {
        foreach ($_co_org as $_co_q) $carryover_ids[(int)$_co_q['quoteid']] = true;
    }
    $sp1_groups = filter_carryover_from_bucket($sp1_groups, $carryover_ids);
    $sp2_groups = filter_carryover_from_bucket($sp2_groups, $carryover_ids);
    $sp3_groups = filter_carryover_from_bucket($sp3_groups, $carryover_ids);
    $sp4_groups = filter_carryover_from_bucket($sp4_groups, $carryover_ids);
}

// View config
$callers           = $all_callers_map;
$is_manager        = true;
$my_fullname       = null;
$crm_quotetype     = 'group';
$show_region_chip  = true; // always merges every region here, so the region badge always applies

$peer_fullname   = null;
$peer_sp1_groups = [];
$peer_sp2_groups = [];
$peer_sp3_groups = [];
$peer_sp4_groups = [];

// Every region is merged here, so every ownership conflict is this view's business.
$pending_warning = tdu_region_conflict_banner();

$pending_warning .= !empty($regions_pending)
    ? '<p style="background:#fff3cd;border:1px solid #ffc107;padding:8px 14px;border-radius:4px;font-size:0.85rem;margin-bottom:12px;">'
      . '&#9888; Queue not yet loaded today for region(s): <strong>'
      . implode(', ', array_map('htmlspecialchars', $regions_pending))
      . '</strong>. Their quotes will appear once the daily run has built them.</p>'
    : '';

$accepted_blocked = compute_accepted_blocked($conn, $carryover_groups, $sp1_groups, $sp2_groups, $sp3_groups, $sp4_groups);

// Awaiting Information and Payment Deadline are deliberately not wired in here (decision
// #10): both move to a fixed Hemant/Dhiraj pair, access-gated across all five regions,
// built in Steps 6-7, not part of the region-owner/admin merged view at all any more.

require_once __DIR__ . '/../views/queue_view.php';
