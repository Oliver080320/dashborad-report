<?php
// Monitoring dashboard view: HTML shell plus Chart.js. Uses the CRM dashboard's own slate palette
// (header.php, queue_view.php). Scoped under .tdu-monitoring-wrap, same isolation pattern as
// queue_view.php's .tdu-queue-wrap.

/** @var bool     $embedded_in_dashboard */
/** @var array    $callers_map            user_name => full name (tdu_region_people()) */
/** @var array    $selected_callers       user_names checked by default (?callers= initial state only — data below always covers every caller) */
/** @var array    $kpi_by_caller          user_name => [calls_today, surfaced, worked, backlog_now, backlog_oldest_days, reached, not_reached] */
/** @var array    $daily_surfaced_worked  run_date => user_name => [surfaced, worked] (last 14 days) */
/** @var array    $daily_calls_logged     run_date => user_name => count (last 14 days) */
/** @var array    $outcome_mix_totals     user_name => outcome => count (rolling last 14 days) */
/** @var array    $daily_contact_rate     run_date => user_name => [reached, not_reached] (last 14 days) */
/** @var array    $daily_accepted_by_owner run_date => user_name => count reaching Accepted, month-to-date; owner = quote's CURRENT assigned_to_sales_agent; unattributed quotes key to 'unassigned_fit'/'unassigned_groups' */
/** @var array    $stage_lifecycle_by_owner user_name => 'accepted'|'rejected'|'requote' => count, month-to-date, same owner-attribution */
/** @var array    $cycle_time_by_owner    user_name => ['sum_days' => n, 'n' => count] — Created→Accepted, month-to-date; divide sum_days/n, never pre-averaged */
/** @var array    $win_rate_trend         event_date => ['accepted' => n, 'rejected' => n], month-to-date, business-wide only */
/** @var array    $quotes_owned_by_caller user_name => count — live snapshot, Created/Requote only, every caller appears even at 0 */
/** @var array    $daily_backlog_size     run_date => user_name => count (last 14 days) */
/** @var array    $backlog_age_today      user_name => ['0-2d' => n, '3d+' => n] */
/** @var array    $daily_coverage         run_date => [qualifying, surfaced] (last 14 days, business-wide) */
/** @var array    $daily_capacity_demand  'India FIT'/'Groups & MICE' => [capacity, daily => run_date => [sp1, sp2, sp3]] */
/** @var array    $unresponsive_orgs      list of [organization_name, total_days, unanswered_days, quote_count, rate], top 10 */
/** @var array    $calls_by_hour          ['hours' => hour (9-18) => user_name => count, 'active_days' => user_name => [dates]], rolling last 90 days */
/** @var array    $channel_mix_totals     user_name => channel => count (rolling last 14 days) */
/** @var array    $queue_composition      run_date => user_name => [sp1, sp2, sp3, carryover] (last 14 days) */
/** @var array    $account_insights       list of [organization_name, accepted, rejected, in_progress, total, resolved, rate], all-time */
/** @var array    $rejection_reasons      ['by_category' => [...], 'by_reason' => list of ['reason', 'category', 'n']], all-time */
/** @var array    $live_basket_by_stage   list of [quotestage, cnt], all-time snapshot, excludes rejected-type stages */
/** @var array    $live_basket_composition ['fit'|'groups_large'|'groups_small' => ['all' => n, 'created_requote' => n]], split by GROUPS_PAX_THRESHOLD */
/** @var array    $live_basket_destination ['australia'|'new_zealand' => ['all' => n, 'created_requote' => n]] */
/** @var array    $travel_date_horizon    list of ['label', 'all', 'created_requote', 'accepted'], split by trip month, 13+ overflow bucket */
/** @var array    $pax_distribution       ['fit'|'groups' => ['buckets' => [...], 'avg' => float, 'count' => n]] */
/** @var array    $quote_geography        ['by_country' => [...], 'by_region' => [...]], Created/Requote only (different population than the live-basket charts below: stage, composition, destination, travel-date horizon, pax distribution) */
$embedded_in_dashboard = $embedded_in_dashboard ?? false;
$emb = !empty($embedded_in_dashboard);
$today = $today ?? date('Y-m-d');
if (!$emb) echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>TDU Monitoring</title>
  <link rel="icon" href="https://yt3.googleusercontent.com/M-g1p3Tcn9e6jm2uWjtBV8XG2GdvIVhy898piiw5ZZsU3DYZ147mR7sFFaB-1Oec8uBeNjmcVM4=s160-c-k-c0x00ffffff-no-rj" type="image/png">
</head>
<body>';

// Matches CALLER_COLOURS in the JS below. First three from the TDU logo (blue/gold/orange,
// estimated); fourth is plain red for Karthik. Kept separate from PALETTE.teal (Coverage).
$caller_colours = ['#1C93C4', '#F5A623', '#EF6C24', '#DC2626'];
$asset_base     = str_replace($_SERVER['DOCUMENT_ROOT'], '', dirname(__DIR__));
// Cache-busting: Cloudflare's edge cache ignores browser hard-refresh for static .js/.css, so a
// deploy can silently keep serving a stale asset. filemtime() ties the query string to the file's
// actual last-modified time, forcing a fresh edge-cache entry whenever the file changes.
function asset_v(string $rel_path): string
{
  $fs_path = __DIR__ . '/../' . $rel_path;
  return $rel_path . '?v=' . (@filemtime($fs_path) ?: time());
}
?>
<div class="tdu-monitoring-wrap">
  <link rel="stylesheet" href="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/css/monitoring.css')) ?>">

  <h1>Monitoring dashboard sales queue</h1>

  <!-- KPI values + subtitles are rendered by renderKPIs()/renderLastWorkedDayKPIs() (below), on
       load and on filter change — one place does the sum/weighted-average maths. Last working day
       sits ABOVE today's live snapshot — the daily standup reviews the previous closed day. -->
  <div class="kpi-section">
    <div class="kpi-section__label">Last working day <span id="lastWorkedDayLabel"></span></div>
    <div class="kpis">
      <div class="kpi">
        <div class="label">Calls</div>
        <div class="val" id="lwdCalls">&nbsp;</div>
        <div class="sub" id="lwdCallsSub">&nbsp;</div>
      </div>
      <div class="kpi">
        <div class="label">Queue worked</div>
        <div class="val" id="lwdPctWorked">&nbsp;</div>
        <div class="sub">of what was surfaced</div>
      </div>
      <div class="kpi">
        <div class="label">Backlog</div>
        <div class="val" id="lwdBacklog">&nbsp;</div>
        <div class="sub">carry-over quotes</div>
      </div>
      <div class="kpi">
        <div class="label">Contact rate</div>
        <div class="val" id="lwdContactRate">&nbsp;</div>
        <div class="sub">calls that reached a person</div>
      </div>
    </div>
  </div>

  <div class="kpi-section">
    <div class="kpi-section__label">Today (in progress)</div>
    <div class="kpis">
      <div class="kpi">
        <div class="label">Calls today</div>
        <div class="val" id="kpiCallsToday">&nbsp;</div>
        <div class="sub" id="kpiCallsTodaySub">&nbsp;</div>
      </div>
      <div class="kpi">
        <div class="label">Queue worked</div>
        <div class="val" id="kpiPctWorked">&nbsp;</div>
        <div class="sub">of what was surfaced</div>
      </div>
      <div class="kpi">
        <div class="label">Backlog now</div>
        <div class="val alert" id="kpiBacklogNow">&nbsp;</div>
        <div class="sub" id="kpiBacklogSub">&nbsp;</div>
      </div>
      <div class="kpi">
        <div class="label">Contact rate</div>
        <div class="val" id="kpiContactRate">&nbsp;</div>
        <div class="sub">calls that reached a person</div>
      </div>
    </div>
  </div>

  <div class="filterbar">
    <span class="filterbar__lab">Callers</span>
    <form method="get" id="callerFilterForm" onsubmit="return false" style="display:flex;gap:14px 24px;flex-wrap:wrap;">
      <?php
      // Grouped by region, a display grouping only. Checking or unchecking doesn't reload the
      // page (see onFilterChange() below), just sets initial state.
      $ci = 0;
      $queue_groups = [];
      foreach (REGION_OWNERS as $_rg => $_info) {
          $queue_groups[$_rg] = [$_info['user_name'] => $_info['full_name']];
      }
      foreach (tdu_personal_claim_users() as $_u => $_f) {
          $queue_groups['No region'][$_u] = $_f;
      }
      foreach ($queue_groups as $queue_label => $queue_callers):
        if (empty($queue_callers)) continue;
      ?>
        <div class="fgroup">
          <span class="fgroup__q"><?= htmlspecialchars($queue_label, ENT_QUOTES, 'UTF-8') ?></span>
          <?php foreach ($queue_callers as $uname => $fullname): ?>
            <label class="fchk">
              <input type="checkbox" name="callers[]" value="<?= htmlspecialchars($uname) ?>"
                <?= in_array($uname, $selected_callers, true) ? 'checked' : '' ?>>
              <span class="dot" style="background:<?= $caller_colours[$ci % count($caller_colours)] ?>"></span>
              <?= htmlspecialchars($fullname) ?>
            </label>
          <?php $ci++;
          endforeach; ?>
        </div>
      <?php endforeach; ?>
    </form>
    <a class="card__download" href="<?= htmlspecialchars($asset_base) ?>/monitoring_system/ajax_export_next_3_months.php">Download next 3 months report (CSV)</a>
  </div>

  <!-- Quote timeline search — lives here, above the family tabs, rather than inside the By
       person panel, since looking up a single quote's history is useful from any tab. -->
  <div class="filterbar" id="quoteTimelineBar">
    <span class="filterbar__lab">Quote timeline</span>
    <span class="personSearchField">
      <svg class="personSearchField__icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
        <circle cx="8.5" cy="8.5" r="6" stroke="currentColor" stroke-width="1.6" />
        <line x1="13.1" y1="13.1" x2="17.5" y2="17.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
      </svg>
      <input type="text" id="personTimelineQuoteNo" placeholder="e.g. TDU00456" autocomplete="off">
    </span>
    <button type="button" id="personTimelineSearchBtn">Search</button>
    <span class="chip" id="personTimelineSearchError" style="display:none;"></span>
  </div>

  <dialog id="personTimelineModal">
    <div class="personTimelineEyebrow">Quote timeline</div>
    <h3 id="personTimelineTitle">Quote timeline</h3>
    <div id="personTimelineBody"></div>
    <div class="dialog-actions">
      <button type="button" id="personTimelineClose">Close</button>
    </div>
  </dialog>

  <div class="familytabs" id="familyTabs">
    <button type="button" data-family="person">By person</button>
    <button type="button" data-family="all">All</button>
    <button type="button" data-family="team-activity" class="on">Team activity</button>
    <button type="button" data-family="workload">Workload &amp; capacity</button>
    <button type="button" data-family="accounts">Account intelligence</button>
    <button type="button" data-family="quote-pipeline">Quote pipeline</button>
  </div>
  <p class="familydesc" id="familyDesc"></p>

  <div class="grid">

    <article class="card feature span2" data-chart="backlog" data-family="workload">
      <div class="card__head">
        <h3 class="card__title">Backlog (carry-over) over time &amp; age</h3>
        <div class="agg" id="aggBacklog">
          <button type="button" data-mode="split" class="on">Per person</button>
          <button type="button" data-mode="total">Total</button>
        </div>
      </div>
      <p class="card__answers">Is the snowball growing or under control, and how old is the oldest unworked quote? This is our single best "are we keeping up?" signal.</p>
      <div class="card__viz"><canvas id="chartBacklogSize"></canvas></div>
      <div class="heat" id="backlogAgeHeat"></div>
      <div class="card__foot">
        <span class="chip">Red at <?= (int)CARRYOVER_RED_DAYS ?>+ days behind</span>
      </div>
    </article>

    <article class="card" data-chart="calls-logged" data-family="team-activity">
      <div class="card__head">
        <h3 class="card__title">Calls logged</h3>
        <div class="agg" id="aggCallsLogged">
          <button type="button" data-mode="split" class="on">Per person</button>
          <button type="button" data-mode="total">Total</button>
        </div>
      </div>
      <p class="card__answers">How much work is each caller putting through each day, and how much did the queue actually put in front of them?</p>
      <div class="card__viz"><canvas id="chartQuotesCalls"></canvas></div>
    </article>

    <article class="card" data-chart="pct-worked" data-family="team-activity">
      <div class="card__head">
        <h3 class="card__title">% of queue worked</h3>
        <div class="agg" id="aggPctWorked">
          <button type="button" data-mode="split" class="on">Per person</button>
          <button type="button" data-mode="total">Total</button>
        </div>
      </div>
      <p class="card__answers">Of what the system put in front of each caller, how much did they actually work?</p>
      <div class="card__viz"><canvas id="chartPctWorked"></canvas></div>
    </article>

    <article class="card" data-chart="contact-rate" data-family="team-activity">
      <div class="card__head">
        <h3 class="card__title">Contact / connect rate</h3>
        <div class="agg" id="aggContactRate">
          <button type="button" data-mode="split" class="on">Per person</button>
          <button type="button" data-mode="total">Total</button>
        </div>
      </div>
      <p class="card__answers">What share of calls actually reached a person?</p>
      <div class="card__viz"><canvas id="chartContactRate"></canvas></div>
    </article>

    <article class="card" data-chart="outcome-mix" data-family="team-activity">
      <div class="card__head">
        <h3 class="card__title">Outcome mix (last 14 days)</h3>
        <div class="agg" id="aggOutcomeMix">
          <button type="button" data-mode="split" class="on">Per person</button>
          <button type="button" data-mode="total">Total</button>
        </div>
      </div>
      <p class="card__answers">What is happening on the calls: callbacks booked, no-answer emails, interest, inbound?</p>
      <div class="card__viz"><canvas id="chartOutcomeMix"></canvas></div>
    </article>

    <article class="card" data-chart="channel-mix" data-family="team-activity">
      <div class="card__head">
        <h3 class="card__title">Channel mix</h3>
        <div class="agg" id="aggChannelMix">
          <button type="button" data-mode="split" class="on">Per person</button>
          <button type="button" data-mode="total">Total</button>
        </div>
      </div>
      <p class="card__answers">How is the team reaching people: phone, WhatsApp, email?</p>
      <div class="card__viz"><canvas id="chartChannelMix"></canvas></div>
    </article>

    <article class="card" data-chart="calls-by-hour" data-family="team-activity">
      <div class="card__head">
        <h3 class="card__title">Calls by hour of day</h3>
        <div class="agg" id="aggCallsByHour">
          <button type="button" data-mode="split" class="on">Per person</button>
          <button type="button" data-mode="total">Total</button>
        </div>
      </div>
      <p class="card__answers">When during the day does the team call? Late start? Midday gap? Early stop?</p>
      <div class="card__viz"><canvas id="chartCallsByHour"></canvas></div>
    </article>

    <article class="card" data-chart="accepted" data-family="team-activity">
      <div class="card__head">
        <h3 class="card__title">Accepted this month</h3>
        <span class="chip" id="acceptedTotalChip">0 total</span>
        <div class="agg" id="aggAccepted">
          <button type="button" data-mode="split" class="on">Per person</button>
          <button type="button" data-mode="total">Total</button>
        </div>
      </div>
      <p class="card__answers">Day by day, how many quotes reached Accepted this calendar month?</p>
      <div class="card__viz"><canvas id="chartAccepted"></canvas></div>
      <div class="card__foot">
        <span class="chip chip--caveat">Counted by the quote's current owner, not who logged the acceptance</span>
        <span class="chip chip--caveat">Unowned quotes (e.g. created and accepted same-day) show as "Unassigned"</span>
        <a class="card__download" href="<?= htmlspecialchars($asset_base) ?>/monitoring_system/ajax_export_accepted.php">Download list (CSV)</a>
      </div>
    </article>

    <article class="card" data-chart="stage-lifecycle" data-family="team-activity">
      <div class="card__head">
        <h3 class="card__title">Quote lifecycle</h3>
        <div class="agg" id="aggStageLifecycle">
          <button type="button" data-mode="split" class="on">Per person</button>
          <button type="button" data-mode="total">Total</button>
        </div>
      </div>
      <p class="card__answers">What is happening to quotes overall this month: how many accepted, rejected, requoted?</p>
      <div class="card__viz"><canvas id="chartStageLifecycle"></canvas></div>
      <div class="card__foot">
        <span class="chip chip--caveat">Counted by the quote's current owner</span>
      </div>
    </article>

    <article class="card" data-chart="win-rate" data-family="team-activity">
      <div class="card__head">
        <h3 class="card__title">Win rate (Accepted vs Rejected)</h3>
        <span class="chip" id="winRateTotalChip">0%</span>
      </div>
      <p class="card__answers">Day by day this month, what share of resolved quotes were Accepted rather than Rejected?</p>
      <div class="card__viz"><canvas id="chartWinRate"></canvas></div>
      <div class="card__foot">
        <span class="chip chip--caveat">Business-wide — never a per-caller measure</span>
      </div>
    </article>

    <article class="card" data-chart="cycle-time" data-family="team-activity">
      <div class="card__head">
        <h3 class="card__title">Cycle time: Created &rarr; Accepted</h3>
        <div class="agg" id="aggCycleTime">
          <button type="button" data-mode="split" class="on">Per person</button>
          <button type="button" data-mode="total">Total</button>
        </div>
      </div>
      <p class="card__answers">On average, how many days does it take a quote to reach Accepted?</p>
      <div class="card__viz"><canvas id="chartCycleTime"></canvas></div>
      <div class="card__foot">
        <span class="chip chip--caveat">Survivorship bias &mdash; only quotes that reached Accepted are counted</span>
      </div>
    </article>

    <article class="card span2" data-chart="coverage" data-family="workload">
      <h3 class="card__title">Coverage: are we surfacing everything?</h3>
      <p class="card__answers">Of the quotes that qualify, how many is our list actually surfacing vs not contemplating yet?</p>
      <div class="card__viz"><canvas id="chartCoverage"></canvas></div>
      <div class="card__foot">
        <span class="chip">Business-wide &middot; not filtered by caller</span>
      </div>
    </article>

    <article class="card span2" data-chart="capacity-demand" data-family="workload">
      <div class="card__head">
        <h3 class="card__title">Capacity vs demand</h3>
        <div class="agg" id="aggCapacityDemand">
          <button type="button" data-mode="Follow-up" class="on">Follow-up</button>
        </div>
      </div>
      <p class="card__answers">Of everything in the live cohort, how much fits in the daily slot budget, and how urgent is it?</p>
      <div class="card__viz"><canvas id="chartCapacityDemand"></canvas></div>
      <div class="card__foot">
        <span class="chip">Business-wide &middot; not filtered by caller</span>
      </div>
    </article>

    <article class="card" data-chart="quotes-owned" data-family="workload">
      <div class="card__head">
        <h3 class="card__title">Quotes owned right now</h3>
        <div class="agg" id="aggQuotesOwned">
          <button type="button" data-mode="split" class="on">Per person</button>
          <button type="button" data-mode="total">Total</button>
        </div>
      </div>
      <p class="card__answers">How many quotes does each caller currently have in their book?</p>
      <div class="card__viz"><canvas id="chartQuotesOwned"></canvas></div>
      <div class="card__foot">
        <span class="chip">Live snapshot, not a trend</span>
      </div>
    </article>

    <article class="card span2" data-chart="unresponsive-orgs" data-family="accounts">
      <h3 class="card__title">Unresponsive organisations</h3>
      <p class="card__answers">Which organisations ask for quotes but almost never pick up the phone?</p>
      <div class="card__viz"><canvas id="chartUnresponsiveOrgs"></canvas></div>
      <div class="card__foot">
        <span class="chip">Business-wide &middot; Created/Requote only</span>
      </div>
    </article>

    <article class="card" data-chart="queue-composition" data-family="workload">
      <div class="card__head">
        <h3 class="card__title">Queue composition over time</h3>
        <div class="agg" id="aggQueueComposition">
          <button type="button" data-mode="split">Per person</button>
          <button type="button" data-mode="total" class="on">Total</button>
        </div>
      </div>
      <p class="card__answers">What is the queue made of? Mostly due callbacks (SP1), new quotes (SP2), upcoming trips (SP3), idle-capacity backfill (SP4), or old carry-over?</p>
      <div class="card__viz"><canvas id="chartQueueComposition"></canvas></div>
      <div class="legend-rows" id="queueCompLegend"></div>
    </article>

    <article class="card" data-chart="account-insights" data-family="accounts">
      <div class="card__head">
        <h3 class="card__title">Account insights</h3>
        <div class="agg" id="aggAccountInsights">
          <button type="button" data-mode="volume" class="on">By volume</button>
          <button type="button" data-mode="rate">By rejection rate</button>
        </div>
      </div>
      <p class="card__answers">Which organisations send us the most quotes? Which send a lot but always get rejected?</p>
      <div class="card__viz"><canvas id="chartAccountInsights"></canvas></div>
      <div class="card__foot">
        <span class="chip chip--caveat">Exploratory — account intelligence, a different lens from queue monitoring</span>
      </div>
    </article>

    <article class="card" data-chart="rejection-reasons" data-family="accounts">
      <div class="card__head">
        <h3 class="card__title">Why we lose — rejection reasons</h3>
        <div class="agg" id="aggRejectionReasons">
          <button type="button" data-mode="category" class="on">By category</button>
          <button type="button" data-mode="reason">By reason</button>
        </div>
      </div>
      <p class="card__answers">Where do deals die, and is it avoidable? The reason is mandatory on every Rejected transition.</p>
      <div class="card__viz"><canvas id="chartRejectionReasons"></canvas></div>
      <div class="card__foot">
        <span class="chip">Business-wide &middot; avoidable vs out-of-our-hands</span>
      </div>
    </article>

    <article class="card span2" data-chart="live-basket" data-family="quote-pipeline">
      <h3 class="card__title">Live basket by stage</h3>
      <p class="card__answers">Of all live quotes, how many sit in each stage?</p>
      <div class="card__viz"><canvas id="chartLiveBasket"></canvas></div>
      <div class="card__foot">
        <span class="chip">Business-wide &middot; excludes Rejected/Auto Rejected</span>
      </div>
    </article>

    <article class="card" data-chart="live-basket-composition" data-family="quote-pipeline">
      <div class="card__head">
        <h3 class="card__title">Composition: FIT vs Groups</h3>
        <div class="agg" id="aggLiveBasketComposition">
          <button type="button" data-mode="all">All stages</button>
          <button type="button" data-mode="created_requote" class="on">Created/Requote</button>
        </div>
      </div>
      <p class="card__answers">How is the live book split between FIT and Groups, and within Groups, large (&gt;<?= (int)GROUPS_PAX_THRESHOLD ?> pax) vs small?</p>
      <div class="card__viz"><canvas id="chartLiveBasketComposition"></canvas></div>
      <div class="card__foot">
        <span class="chip">Business-wide &middot; live quote snapshot</span>
      </div>
    </article>

    <article class="card" data-chart="live-basket-destination" data-family="quote-pipeline">
      <div class="card__head">
        <h3 class="card__title">Destination: Australia vs New Zealand</h3>
        <div class="agg" id="aggLiveBasketDestination">
          <button type="button" data-mode="all">All stages</button>
          <button type="button" data-mode="created_requote" class="on">Created/Requote</button>
        </div>
      </div>
      <p class="card__answers">Where is the live book travelling?</p>
      <div class="card__viz"><canvas id="chartLiveBasketDestination"></canvas></div>
      <div class="card__foot">
        <span class="chip">Business-wide &middot; live quote snapshot</span>
      </div>
    </article>

    <article class="card span2" data-chart="travel-date-horizon" data-family="quote-pipeline">
      <div class="card__head">
        <h3 class="card__title">Travel-date horizon</h3>
        <div class="agg" id="aggTravelDateHorizon">
          <button type="button" data-mode="all">All stages</button>
          <button type="button" data-mode="created_requote" class="on">Created/Requote</button>
          <button type="button" data-mode="accepted">Accepted</button>
        </div>
      </div>
      <p class="card__answers">How is the live book distributed across upcoming months by trip date?</p>
      <div class="card__viz"><canvas id="chartTravelDateHorizon"></canvas></div>
      <div class="card__foot">
        <span class="chip">Business-wide &middot; live quote snapshot &middot; future trips only</span>
      </div>
    </article>

    <article class="card" data-chart="pax-distribution" data-family="quote-pipeline">
      <div class="card__head">
        <h3 class="card__title">Passenger counts</h3>
        <div class="agg" id="aggPaxDistribution">
          <button type="button" data-mode="fit" class="on">FIT</button>
          <button type="button" data-mode="groups">Groups</button>
        </div>
      </div>
      <p class="card__answers">How big are the travel parties? <span id="paxDistributionAvg"></span></p>
      <div class="card__viz"><canvas id="chartPaxDistribution"></canvas></div>
      <div class="card__foot">
        <span class="chip">Business-wide &middot; live quote snapshot &middot; FIT and Groups use different bucket sizes</span>
      </div>
    </article>

    <article class="card" data-chart="quote-geography" data-family="quote-pipeline">
      <div class="card__head">
        <h3 class="card__title">Where are our quotes?</h3>
        <div class="agg" id="aggQuoteGeography">
          <button type="button" data-mode="country" class="on">By country</button>
          <button type="button" data-mode="region">India regions</button>
        </div>
      </div>
      <p class="card__answers">How many quotes come from each country, and within India, from each sales region?</p>
      <div class="card__viz"><canvas id="chartQuoteGeography"></canvas></div>
      <div class="card__foot">
        <span class="chip">Business-wide &middot; regions/countries with 0 quotes are omitted</span>
      </div>
    </article>

  </div>

  <!-- By person — not a repeating card grid like the rest of the dashboard, so it lives outside
       .grid as its own panel; applyFamilyFilter() in core.js toggles it separately from
       `.grid > .card`. -->
  <div class="card" id="personPanel" data-family="person" style="display:none">
    <div class="card__head">
      <h3 class="card__title">By person</h3>
    </div>
    <p class="card__answers">One caller's whole book — pick anyone regardless of the caller filter above.</p>

    <div class="personControlBar">
      <div class="personControlBar__caller">
        <span class="filterbar__lab">Caller</span>
        <select id="personCallerSelect" class="personSelect">
          <?php foreach ($callers_map as $uname => $fullname): ?>
            <option value="<?= htmlspecialchars($uname) ?>"><?= htmlspecialchars($fullname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="personControlBar__range">
        <div class="agg" id="personRangeMode">
          <button type="button" data-range="month" class="on">This month</button>
          <button type="button" data-range="year">This year</button>
          <button type="button" data-range="custom">Custom range</button>
        </div>
        <span class="chip" id="personRangeSummary"></span>
      </div>
    </div>
    <div id="personCustomRange" style="display:none;margin-top:10px;">
      <label>From <input type="date" id="personRangeFrom"></label>
      <label style="margin-left:10px;">To <input type="date" id="personRangeTo"></label>
    </div>

    <!-- Summary tiles + region/priority breakdown as two side-by-side boxes rather than stacked
         full-width. 5 of the 9 tiles are clickable (data-book-filter) — Companies, Conversion
         rate, Avg. first response, and Avg. cycle time aren't (ratios/averages, not a quote list). -->
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:stretch;margin-top:16px;">
      <div class="card" style="flex:2 1 480px;">
        <div class="kpis" id="personSummaryKpis">
          <div class="personKpiGroup">
            <div class="personKpiGroup__label">Activity &middot; created in range</div>
            <div class="personKpiGroup__row" style="grid-template-columns:repeat(4,1fr);">
              <div class="kpi" data-book-filter="total" style="cursor:pointer;">
                <div class="label">Total quotes</div>
                <div class="val" id="pvTotal">&nbsp;</div>
                <div class="sub" id="pvTotalSub">&nbsp;</div>
              </div>
              <div class="kpi" data-book-filter="companies" style="cursor:pointer;">
                <div class="label">Companies</div>
                <div class="val" id="pvCompanies">&nbsp;</div>
                <div class="sub" id="pvCompaniesSub">&nbsp;</div>
              </div>
              <div class="kpi" data-book-filter="created" style="cursor:pointer;">
                <div class="label">Created</div>
                <div class="val" id="pvCreated">&nbsp;</div>
                <div class="sub" id="pvCreatedSub">&nbsp;</div>
              </div>
              <div class="kpi" data-book-filter="requote" style="cursor:pointer;">
                <div class="label">Requote</div>
                <div class="val" id="pvRequote">&nbsp;</div>
                <div class="sub" id="pvRequoteSub">&nbsp;</div>
              </div>
            </div>
          </div>

          <div class="personKpiGroup">
            <div class="personKpiGroup__label">Outcome &middot; resolved in range</div>
            <div class="personKpiGroup__row" style="grid-template-columns:repeat(4,1fr);">
              <div class="kpi" data-book-filter="accepted" style="cursor:pointer;">
                <div class="label">Accepted</div>
                <div class="val good" id="pvAccepted">&nbsp;</div>
                <div class="sub" id="pvAcceptedSub">&nbsp;</div>
              </div>
              <div class="kpi" data-book-filter="rejected" style="cursor:pointer;">
                <div class="label">Rejected</div>
                <div class="val alert" id="pvRejected">&nbsp;</div>
                <div class="sub" id="pvRejectedSub">&nbsp;</div>
              </div>
              <div class="kpi" data-book-filter="inbound" style="cursor:pointer;">
                <div class="label">Inbound calls</div>
                <div class="val" id="pvInbound">&nbsp;</div>
                <div class="sub" id="pvInboundSub">&nbsp;</div>
              </div>
              <div class="kpi">
                <div class="label">Conversion rate</div>
                <div class="val" id="pvConvRate">&nbsp;</div>
                <div class="sub" id="pvConvSub">&nbsp;</div>
              </div>
            </div>
          </div>

          <div class="personKpiGroup">
            <div class="personKpiGroup__label">Speed</div>
            <div class="personKpiGroup__row" style="grid-template-columns:repeat(2,1fr);">
              <div class="kpi">
                <div class="label">Avg. first response
                  <span class="kpi__help" tabindex="0" data-tip="Average days between a quote's created date and its first logged call. Scoped by the quote's created date falling in this range.">?</span>
                </div>
                <div class="val" id="pvFirstResponse">&nbsp;</div>
                <div class="sub" id="pvFirstResponseSub">&nbsp;</div>
              </div>
              <div class="kpi">
                <div class="label">Avg. cycle time
                  <span class="kpi__help" tabindex="0" data-tip="Average days from Created to Accepted, for quotes accepted within this range — regardless of when they were created.">?</span>
                </div>
                <div class="val" id="pvCycleTime">&nbsp;</div>
                <div class="sub" id="pvCycleTimeSub">&nbsp;</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Region/priority breakdown, same "book" population as the Total tile. Bars render into
           the same #personDrillCard below, same pattern as the companies drill. -->
      <div class="card" style="flex:1 1 280px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
          <span class="filterbar__lab">Breakdown</span>
          <div class="agg" id="personBreakdownMode">
            <button type="button" data-breakdown-mode="region" class="on">Region</button>
            <button type="button" data-breakdown-mode="priority">Priority</button>
          </div>
        </div>
        <!-- Bar list alone left this card mostly empty next to the taller tiles card. Legend
             hidden — the bars below already serve as the (clickable) legend. -->
        <div class="personBreakdownViz"><canvas id="chartPersonBreakdown"></canvas></div>
        <div id="personBreakdownBars"></div>
      </div>
    </div>

    <div class="card" style="margin-top:16px;">
      <div class="card__head">
        <h3 class="card__title">Calls by hour</h3>
      </div>
      <p class="card__answers">When this caller is logging calls, by hour of day (India time), for the picked range.</p>
      <div class="card__viz"><canvas id="chartPersonCallsByHour"></canvas></div>
    </div>

    <!-- Drill-down table, hidden until a tile is clicked. Rows open the quote timeline modal
         via personOpenTimeline(). -->
    <div id="personDrillCard" style="display:none;margin-top:16px;border-top:1px solid var(--line);padding-top:14px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <strong id="personDrillLabel"></strong>
        <span>
          <button type="button" id="personDrillBack" style="display:none;margin-right:8px;">&larr; Back to companies</button>
          <button type="button" id="personDrillClose">Close</button>
        </span>
      </div>
      <div style="overflow-x:auto;">
        <!-- thead is populated in JS — companies and quotes views have different columns,
             same table/tbody pair. -->
        <table id="personDrillTable">
          <thead></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <!-- This section ignores the date-range picker above — it's the caller's live book
         (Created/Requote) right now. -->
    <div class="personSectionDivider" style="margin-top:24px;">
      <span>Live book &mdash; always current, not scoped to the date range above</span>
    </div>

    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:stretch;margin-top:10px;">
      <div class="card" style="flex:1 1 380px;">
        <div class="card__head">
          <h3 class="card__title">Live book by travel month</h3>
          <span class="chip" id="personLiveBookTotalChip">0 total</span>
        </div>
        <p class="card__answers">Of this caller's open quotes (Created/Requote), how many travel each month? Click a bar to filter the table below.</p>
        <div class="card__viz"><canvas id="chartPersonLiveBook"></canvas></div>
      </div>

      <div class="card" style="flex:1 1 380px;">
        <div class="card__head">
          <h3 class="card__title">Upcoming follow-ups</h3>
        </div>
        <p class="card__answers">When is this caller's next round of scheduled calls due? Click a bar to filter the table below.</p>
        <div class="card__viz"><canvas id="chartPersonUpcoming"></canvas></div>
      </div>
    </div>

    <div class="card" style="margin-top:16px;">
      <div class="card__head">
        <h3 class="card__title">Quotes <span id="personLiveBookFilterLabel" style="font-weight:400;color:var(--muted);"></span></h3>
        <button type="button" id="personLiveBookClearMonth" class="csvbtn" style="display:none;">Show all quotes</button>
      </div>
      <p class="card__answers">Click a row for the full call history and timeline. Click a column header to sort.</p>
      <div class="personLiveBookFilterBar">
        <span class="personSearchField">
          <svg class="personSearchField__icon" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <circle cx="8.5" cy="8.5" r="6" stroke="currentColor" stroke-width="1.6" />
            <line x1="13.1" y1="13.1" x2="17.5" y2="17.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
          </svg>
          <input type="text" id="personLiveBookSearch" placeholder="Search organisation or quote #">
        </span>
        <select id="personLiveBookPriorityFilter" class="personSelect">
          <option value="all">All priorities</option>
          <option value="High">High only</option>
          <option value="Low">Low only</option>
          <option value="Not connected">Not connected only</option>
          <option value="Blank">Blank only</option>
        </select>
      </div>
      <div style="overflow-x:auto;">
        <table id="personLiveBookTable">
          <thead></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/vendor/chart.umd.min.js')) ?>"></script>

<script>
  const TODAY = <?= json_encode($today) ?>;
  const ASSET_BASE = <?= json_encode($asset_base) ?>;
  const CALLERS_MAP = <?= json_encode($callers_map) ?>;
  // group label => [user_names], same grouping the filter bar uses, region-keyed now that
  // one person works FIT and Groups together.
  const QUEUE_GROUPS = <?= json_encode(array_map('array_keys', $queue_groups)) ?>;
  const QUEUE_COLOURS = {};
  ['#0F766E', '#6B5B95', '#B45309', '#1D4ED8', '#9D174D', '#4B5563']
    .forEach((c, i) => { const k = Object.keys(QUEUE_GROUPS)[i]; if (k) QUEUE_COLOURS[k] = c; });

  function queueOf(uname) {
    return Object.keys(QUEUE_GROUPS).find(q => QUEUE_GROUPS[q].includes(uname)) ?? null;
  }

  // Mutable — reassigned by onFilterChange() so every render function reads the current selection.
  let SELECTED_CALLERS = <?= json_encode($selected_callers) ?>;
  const KPI_BY_CALLER = <?= json_encode($kpi_by_caller) ?>;
  const DAILY_SURFACED_WORKED = <?= json_encode($daily_surfaced_worked) ?>;
  const DAILY_CALLS_LOGGED = <?= json_encode($daily_calls_logged) ?>;
  const DAILY_CONTACT_RATE = <?= json_encode($daily_contact_rate) ?>;
  const DAILY_ACCEPTED_BY_OWNER = <?= json_encode($daily_accepted_by_owner) ?>;
  const STAGE_LIFECYCLE_BY_OWNER = <?= json_encode($stage_lifecycle_by_owner) ?>;
  const WIN_RATE_TREND = <?= json_encode($win_rate_trend) ?>;
  const CYCLE_TIME_BY_OWNER = <?= json_encode($cycle_time_by_owner) ?>;
  const QUOTES_OWNED_BY_CALLER = <?= json_encode($quotes_owned_by_caller) ?>;
  const OUTCOME_MIX_TOTALS = <?= json_encode($outcome_mix_totals) ?>;
  const DAILY_BACKLOG_SIZE = <?= json_encode($daily_backlog_size) ?>;
  const BACKLOG_AGE_TODAY = <?= json_encode($backlog_age_today) ?>;
  const DAILY_COVERAGE = <?= json_encode($daily_coverage) ?>;
  const DAILY_CAPACITY_DEMAND = <?= json_encode($daily_capacity_demand) ?>;
  // JSON_INVALID_UTF8_SUBSTITUTE is a backstop only — organization_name is already normalised in
  // utf8_safe() (monitoring_builder.php); this just guards a future unnoticed bad string from
  // blanking the WHOLE constant: json_encode() returns false for the entire structure if any
  // nested string fails UTF-8 validation, which renders here as a page-wide JS syntax error.
  const UNRESPONSIVE_ORGS = <?= json_encode($unresponsive_orgs, JSON_INVALID_UTF8_SUBSTITUTE) ?>;
  const CALLS_BY_HOUR = <?= json_encode($calls_by_hour['hours']) ?>;
  const CALLS_BY_HOUR_ACTIVE_DAYS = <?= json_encode($calls_by_hour['active_days']) ?>;
  const CHANNEL_MIX_TOTALS = <?= json_encode($channel_mix_totals) ?>;
  const QUEUE_COMPOSITION = <?= json_encode($queue_composition) ?>;
  const ACCOUNT_INSIGHTS = <?= json_encode($account_insights, JSON_INVALID_UTF8_SUBSTITUTE) ?>;
  const REJECTION_REASONS = <?= json_encode($rejection_reasons) ?>;
  const LIVE_BASKET_BY_STAGE = <?= json_encode($live_basket_by_stage) ?>;
  const LIVE_BASKET_COMPOSITION = <?= json_encode($live_basket_composition) ?>;
  const GROUPS_PAX_THRESHOLD = <?= (int)GROUPS_PAX_THRESHOLD ?>;
  const LIVE_BASKET_DESTINATION = <?= json_encode($live_basket_destination) ?>;
  const TRAVEL_DATE_HORIZON = <?= json_encode($travel_date_horizon) ?>;
  const PAX_DISTRIBUTION = <?= json_encode($pax_distribution) ?>;
  const QUOTE_GEOGRAPHY = <?= json_encode($quote_geography) ?>;
</script>
<script src="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/js/monitoring/core.js')) ?>"></script>
<script src="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/js/monitoring/team-activity.js')) ?>"></script>
<script src="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/js/monitoring/workload.js')) ?>"></script>
<script src="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/js/monitoring/accounts.js')) ?>"></script>
<script src="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/js/monitoring/quote-pipeline.js')) ?>"></script>
<script src="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/js/monitoring/by-person.js')) ?>"></script>
<?php
if (!$emb) echo '</body></html>';
