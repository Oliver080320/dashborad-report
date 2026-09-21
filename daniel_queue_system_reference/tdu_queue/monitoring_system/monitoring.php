<?php
// Monitoring dashboard router — admin-only. Read-only: no writes anywhere in this feature.
if (session_status() === PHP_SESSION_NONE) session_start();
if (($_SESSION['title'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}
if (!defined('DAILY_CAPACITY')) require_once __DIR__ . '/../config.php';
tdu_maintenance_guard(MONITORING_MAINTENANCE_MODE, 'html');
require_once __DIR__ . '/../queues/queue_builder.php';
require_once __DIR__ . '/monitoring_builder.php';

$today = date('Y-m-d');

// Cache TTL for the two still-expensive queries — see cached_call() in monitoring_builder.php.
define('MONITORING_CACHE_TTL', 900);

$callers_map = tdu_region_people(); // user_name => full_name
$callers_param = isset($_GET['callers']) ? implode(',', (array)$_GET['callers']) : '';
// Initial filter state only (?callers= for bookmarkability) — every query below still fetches
// every caller; the filter recombines client-side, see monitoring_view.php's onFilterChange().
$selected_callers = resolve_callers_filter($callers_param, $callers_map);
$all_callers      = array_keys($callers_map);

// Shared by build_kpi_by_caller() and build_backlog_age_today() — same carry-over recompute.
$carryover_quotes = cached_call("carryover_quotes_$today", MONITORING_CACHE_TTL, function () use ($conn, $today, $all_callers) {
    return load_carryover_quotes($conn, $today, $all_callers);
});
$kpi_by_caller = build_kpi_by_caller($conn, $today, $all_callers, $callers_map, $carryover_quotes);

$daily_surfaced_worked = fetch_daily_surfaced_worked($conn, $all_callers);
$daily_calls_logged = build_daily_calls_logged_trend($conn, $all_callers, $callers_map);
$outcome_mix_totals = build_outcome_mix_totals($conn, $all_callers, $callers_map);
$daily_contact_rate = build_daily_contact_rate($conn, $all_callers, $callers_map);
// Month-to-date Accepted count. Owner-agnostic scope (INDIA_REGIONS, both FIT and
// Groups & MICE) attributed in PHP to the quote's current owner, or 'unassigned_fit'/
// 'unassigned_groups' when none of the 4 configured callers owns it — see
// build_daily_accepted_by_owner()'s doc comment.
$daily_accepted_by_owner = build_daily_accepted_by_owner($conn, INDIA_REGIONS, $callers_map);
// Same owner-attribution and month-to-date window as the Accepted count above, on an
// entity-name axis rather than a daily trend.
$stage_lifecycle_by_owner = build_stage_lifecycle_by_owner($conn, $all_callers, $callers_map);
// Days-to-accept per owner, same event population as the Accepted count above; see
// build_cycle_time_by_owner()
// for why it returns SUM/COUNT rather than a pre-averaged AVG.
$cycle_time_by_owner = build_cycle_time_by_owner($conn, $all_callers, $callers_map);
// Business-wide, not per-caller — see build_win_rate_trend()'s doc comment.
$win_rate_trend = build_win_rate_trend($conn);
// Live snapshot, same Created/Requote population as B.7.
$quotes_owned_by_caller = build_quotes_owned_by_caller($conn, $all_callers, $callers_map);
$daily_backlog_size = build_backlog_size_trend($conn, $all_callers);
$backlog_age_today = build_backlog_age_today($carryover_quotes, $all_callers);
// Business-wide, unfiltered — always reflects every caller regardless of the filter.
$daily_coverage = build_daily_coverage($conn, $all_callers);
// Queue-scoped (India FIT / Groups & MICE), not caller-scoped — no caller argument.
$daily_capacity_demand = cached_call("daily_capacity_demand_$today", MONITORING_CACHE_TTL, function () use ($conn) {
    return build_daily_capacity_demand($conn);
});
// Ranked top-10 list, business-wide, not a trend.
$unresponsive_orgs = build_unresponsive_orgs($conn);
// Caller-filtered, but a 90-day distribution rather than a 14-day trend.
$calls_by_hour = build_calls_by_hour($conn, $all_callers, $callers_map);
$channel_mix_totals = build_channel_mix_totals($conn, $all_callers, $callers_map);
// Feeds both toggle modes (Total = daily trend by queue, Per person = window total by caller).
$queue_composition = build_queue_composition_trend($conn, $all_callers);
// All-time, business-wide — see build_account_insights() for the stage-bucket reasoning.
$account_insights = build_account_insights($conn);
// "Why we lose" — all-time, business-wide. Reads tdu_quote_closure_feedback (migration 006).
$rejection_reasons = build_rejection_reasons($conn);
// The five live-basket builders below all re-cut the same all-time basket (live_basket_exclusion_sql()),
// business-wide, no live-filter hook.
$live_basket_by_stage = build_live_basket_by_stage($conn);
$live_basket_composition = build_live_basket_composition($conn);
$live_basket_destination = build_live_basket_destination($conn);
$travel_date_horizon = build_travel_date_horizon($conn);
$pax_distribution = build_pax_distribution($conn);
// Different population from B.1-B.6 (Created/Requote only) — see build_quote_geography().
$quote_geography = build_quote_geography($conn);

require_once __DIR__ . '/../views/monitoring_view.php';
