<?php
/** @var array[] $sp1_groups */
/** @var array[] $sp2_groups */
/** @var array[] $sp3_groups */
/** @var array[] $sp4_groups */
/** @var string   $today          Y-m-d — injected from queue.php */
/** @var string   $session_user */
/** @var bool     $is_manager */
/** @var string|null $my_fullname */
/** @var array    $callers        user_name => full name */
/** @var string   $crm_quotetype      quotetype param for CRM links (fit-dashboard / group) */
/** @var bool      $is_lead_queue    true = rendering the Leads queue, not Follow-up */
/** @var array[]   $lead_sp1_groups  Leads mode only: same four tiers as sp1_groups..sp4_groups */
/** @var array[]   $lead_sp2_groups */
/** @var array[]   $lead_sp3_groups */
/** @var array[]   $lead_sp4_groups */
$is_lead_queue    = $is_lead_queue    ?? false;
$lead_sp1_groups  = $lead_sp1_groups  ?? [];
$lead_sp2_groups  = $lead_sp2_groups  ?? [];
$lead_sp3_groups  = $lead_sp3_groups  ?? [];
$lead_sp4_groups  = $lead_sp4_groups  ?? [];
/** @var bool     $is_readonly        true = no Log button / priority edit (viewer role) */
$is_readonly       = $is_readonly       ?? true; // fail closed if a path forgot to set it
$crm_quotetype     = $crm_quotetype     ?? 'fit-dashboard';
$sp4_groups        = $sp4_groups        ?? [];
$pending_warning   = $pending_warning   ?? '';
$viewer_is_admin   = $viewer_is_admin   ?? false;  // only admins may switch "View as"
$carryover_groups  = $carryover_groups  ?? [];
$show_region_chip  = $show_region_chip  ?? false; // region model only — set by region_queue.php
// Toggle between a person's own queue and the merged all-region view — set by
// region_queue.php/region_leads.php only; every other controller leaves these at their
// no-op defaults.
$show_scope_toggle  = $show_scope_toggle  ?? false;
$scope_pref         = $scope_pref         ?? 'own';
$can_personal_claim = $can_personal_claim ?? false;
$can_claim_search   = $can_claim_search   ?? false; // Search Quote's own claim button, independent of the current view
$asset_base        = str_replace($_SERVER['DOCUMENT_ROOT'], '', dirname(__DIR__));

// Cache-busting: Cloudflare's edge cache ignores browser hard-refresh for static .js/.css,
// so a deploy can silently keep serving a stale asset. filemtime() ties the query string
// to the file's actual last-modified time, forcing a fresh edge-cache entry on change.
// Mirrors monitoring_view.php's asset_v() — kept local here since the two views don't
// otherwise share a common include.
function asset_v(string $rel_path): string {
    $fs_path = __DIR__ . '/../' . $rel_path;
    return $rel_path . '?v=' . (@filemtime($fs_path) ?: time());
}
?>
<?php if (empty($embedded_in_dashboard)): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TDU Daily Queue</title>
</head>
<body>
<?php endif; ?>
    <link rel="stylesheet" href="<?= $asset_base ?>/assets/vendor/flatpickr/themes/material_blue.css">
    <link rel="stylesheet" href="<?= $asset_base ?>/assets/vendor/flatpickr/plugins/confirmDate/confirmDate.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/css/queue.css')) ?>">
<div class="tdu-queue-wrap">

<?php
// $session_user and $is_manager are set in queue.php before this view is included.
// Region owners plus any supervisor holding a personal claim queue: the page title and
// the admin's "View as" dropdown both need a real name for everyone who works a queue.
$all_callers_map = tdu_region_people();
// $is_manager (region_queue.php's $sees_all) is true for a supervisor's own login too,
// not only a real admin, so the label distinguishes the two. Neither has an admin's
// actual rights (no real "View as", no Monitoring button, both gated on $viewer_is_admin).
$caller_label = $is_manager
    ? ('All Regions' . (!empty($viewer_is_admin) ? ' (Admin)' : ' (Supervisor)'))
    : explode(' ', $all_callers_map[$session_user] ?? $session_user)[0];
$carryover_count = array_sum(array_map('count', $carryover_groups));
// Same four-tier count shape in both modes now that Leads also freezes into four buckets;
// only which underlying groups feed sp1_count..sp4_count differs.
if ($is_lead_queue) {
    $sp1_count = array_sum(array_map('count', $lead_sp1_groups));
    $sp2_count = array_sum(array_map('count', $lead_sp2_groups));
    $sp3_count = array_sum(array_map('count', $lead_sp3_groups));
    $sp4_count = array_sum(array_map('count', $lead_sp4_groups));
    $count_groups = [$carryover_groups, $lead_sp1_groups, $lead_sp2_groups, $lead_sp3_groups, $lead_sp4_groups];
} else {
    $sp1_count = array_sum(array_map('count', $sp1_groups));
    $sp2_count = array_sum(array_map('count', $sp2_groups));
    $sp3_count = array_sum(array_map('count', $sp3_groups));
    $sp4_count = array_sum(array_map('count', $sp4_groups));
    $count_groups = [$carryover_groups, $sp1_groups, $sp2_groups, $sp3_groups, $sp4_groups];
}
$total_quotes = $carryover_count + $sp1_count + $sp2_count + $sp3_count + $sp4_count;
$worked_count = 0;
foreach ($count_groups as $_grp) {
    foreach ($_grp as $_org) {
        foreach ($_org as $_q) {
            if (!empty($_q['worked_today'])) $worked_count++;
        }
    }
}
$progress_pct = $total_quotes > 0 ? round($worked_count / $total_quotes * 100) : 0;

// Outside $total_quotes and the Called bar on purpose: those come off the frozen
// daily_runs snapshot, while this cohort is live and a row leaves the moment its blockers
// clear, so folding it in would let their denominator move on its own mid-day.

// Batch pre-check: which quotes cannot be marked Accepted. Computed by the controller
// (india_callers.php / groups_mice.php / all_callers.php) via compute_accepted_blocked()
// in queue_builder.php — this view only renders the result, it doesn't query the DB.
$accepted_blocked = $accepted_blocked ?? [];
?>
<div style="display:flex; align-items:baseline; gap:16px; margin-bottom:4px;">
    <h1 style="margin:0;"><?= $is_lead_queue ? 'Leads Queue' : 'Follow-up Queue' ?> &mdash; <?= htmlspecialchars($caller_label, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if (!empty($viewer_is_admin)): ?>
    <form method="get" style="display:flex; align-items:center; gap:6px; font-size:0.85rem;">
        <?php if (!empty($embedded_in_dashboard)): ?><input type="hidden" name="opt" value="sales-queue"><?php endif; ?>
        <?php if ($is_lead_queue): // keep ?view= across the switch, or it falls back to Follow-up ?><input type="hidden" name="view" value="leads"><?php endif; ?>
        <label for="user-switcher" style="color:#666;">View as:</label>
        <select id="user-switcher" name="user" onchange="this.form.submit()" style="padding:2px 6px; font-size:0.85rem;">
            <?php foreach ($all_callers_map as $cu => $full): ?>
            <option value="<?= htmlspecialchars($cu, ENT_QUOTES, 'UTF-8') ?>" <?= $session_user === $cu ? 'selected' : '' ?>><?= htmlspecialchars(explode(' ', $full)[0], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
            <option value="manager" <?= $is_manager ? 'selected' : '' ?>>Admin view</option>
        </select>
    </form>
    <?php endif; ?>
    <?php if ($show_scope_toggle):
        // Own-view/merged-view toggle for a supervisor: scope_view is read and persisted
        // by region_queue.php/region_leads.php, this link only flips it.
        $toggle_params = ['scope_view' => $scope_pref === 'all' ? 'own' : 'all'];
        if (!empty($embedded_in_dashboard)) $toggle_params['opt']  = 'sales-queue';
        if (!empty($viewer_is_admin))       $toggle_params['user'] = $session_user;
        if ($is_lead_queue)                 $toggle_params['view'] = 'leads';
    ?>
    <a href="?<?= htmlspecialchars(http_build_query($toggle_params), ENT_QUOTES, 'UTF-8') ?>"
       style="font-size:0.85rem;color:#334155;text-decoration:underline;">
        <?= $scope_pref === 'all' ? 'Back to my own view' : 'See all regions' ?>
    </a>
    <?php endif; ?>
    <button type="button" id="inbound-open-btn"
            style="margin-left:auto;padding:4px 12px;font-size:0.85rem;cursor:pointer;background:#fff;border:1px solid #334155;color:#334155;border-radius:3px;font-weight:600">
            Search quote
    </button>
    <?php if (tdu_is_post_sale($session_user) || tdu_is_supervisor($session_user) || !empty($viewer_is_admin)): ?>
    <?php
    // Payment Deadline queue — same page, ?view=payment_deadline. Carries ?opt= when
    // embedded in the dashboard and ?user= when an admin is impersonating, so neither
    // is dropped on the way across.
    // Shown to the post-sale pair, supervisors (read-only there) and admins, matching who
    // the router will actually let through. A region owner or a legacy caller seeing this
    // link would be sent straight back to the page they are already on, and the read-only
    // helper never had access (decided 2026-08-06).
    $pd_params = ['view' => 'payment_deadline'];
    if (!empty($embedded_in_dashboard)) $pd_params['opt']  = 'sales-queue';
    if (!empty($viewer_is_admin))       $pd_params['user'] = $session_user;
    ?>
    <a href="?<?= htmlspecialchars(http_build_query($pd_params), ENT_QUOTES, 'UTF-8') ?>"
       style="padding:4px 12px;font-size:0.85rem;cursor:pointer;background:#fff;border:1px solid #334155;color:#334155;border-radius:3px;font-weight:600;text-decoration:none">
        Payment deadlines
    </a>
    <?php
    // Awaiting Information is its own page (?view=awaiting_info), same access shape and
    // same reasoning as Payment deadlines above — the post-sale pair, supervisors and
    // admin have no embedded section for it on this page, so this link is their only way in.
    $ai_params = ['view' => 'awaiting_info'];
    if (!empty($embedded_in_dashboard)) $ai_params['opt']  = 'sales-queue';
    if (!empty($viewer_is_admin))       $ai_params['user'] = $session_user;
    ?>
    <a href="?<?= htmlspecialchars(http_build_query($ai_params), ENT_QUOTES, 'UTF-8') ?>"
       style="padding:4px 12px;font-size:0.85rem;cursor:pointer;background:#fff;border:1px solid #334155;color:#334155;border-radius:3px;font-weight:600;text-decoration:none">
        Awaiting information
    </a>
    <?php endif; ?>
    <?php
    // Sibling queues, same page, selected by ?view=. Shown to the read-only viewer too,
    // unlike Payment deadlines above: both queues are read-only for that role, and the link
    // only changes which buckets are read. Carries ?opt= when embedded in the dashboard and
    // ?user= when an admin is impersonating, so neither is dropped in transit.
    $sibling_params = $is_lead_queue ? [] : ['view' => 'leads'];
    if (!empty($embedded_in_dashboard)) $sibling_params['opt']  = 'sales-queue';
    if (!empty($viewer_is_admin))       $sibling_params['user'] = $session_user;
    ?>
    <a href="?<?= htmlspecialchars(http_build_query($sibling_params), ENT_QUOTES, 'UTF-8') ?>"
       style="padding:4px 12px;font-size:0.85rem;cursor:pointer;background:#fff;border:1px solid #334155;color:#334155;border-radius:3px;font-weight:600;text-decoration:none">
        <?= $is_lead_queue ? 'Follow-up queue' : 'Leads' ?>
    </a>
    <button type="button" id="manual-open-btn"
            style="padding:4px 12px;font-size:0.85rem;cursor:pointer;background:#fff;border:1px solid #334155;color:#334155;border-radius:3px;font-weight:600">
            User manual
    </button>
    <?php if (!empty($viewer_is_admin)): ?>
    <button type="button" id="monitoring-open-btn"
            style="padding:4px 12px;font-size:0.85rem;cursor:pointer;background:#fff;border:1px solid #334155;color:#334155;border-radius:3px;font-weight:600">
            Monitoring
    </button>
    <?php endif; ?>
</div>
<p class="subtitle">
    <?= date('l, d F Y', strtotime($today)) ?> &nbsp;|&nbsp;
    <?= $total_quotes ?> quotes today &nbsp;|&nbsp;
    <?php if ($carryover_count): ?><span style="color:#92400e;font-weight:600">Carry-over: <?= $carryover_count ?></span> &nbsp;<?php endif; ?>
    SP1: <?= $sp1_count ?> &nbsp;
    SP2: <?= $sp2_count ?> &nbsp;
    SP3: <?= $sp3_count ?> &nbsp;
    SP4: <?= $sp4_count ?>
    &nbsp;|&nbsp;
    <span style="display:inline-flex;align-items:center;gap:6px;vertical-align:middle">
        Called: <strong><span id="global-called-count"><?= $worked_count ?>/<?= $total_quotes ?></span></strong>
        <span style="display:inline-block;width:72px;height:7px;background:#e0e0e0;border-radius:4px;overflow:hidden">
            <span id="global-called-bar" style="display:block;height:100%;width:<?= $progress_pct ?>%;background:#334155;border-radius:4px;transition:width 0.3s"></span>
        </span>
    </span>
</p>

<?php
function render_section(array $groups, string $label, string $css_class, string $today, bool $show_call_cols = false, bool $show_pending_days = false): void {
    global $is_manager, $session_user, $callers, $crm_quotetype, $is_readonly, $is_lead_queue, $can_personal_claim;

    $total = array_sum(array_map('count', $groups));
    $worked = 0;
    foreach ($groups as $org_quotes) {
        foreach ($org_quotes as $q) {
            if (!empty($q['worked_today'])) $worked++;
        }
    }
    $pct = $total > 0 ? round($worked / $total * 100) : 0;
    $progress_bar = "<span style='display:inline-flex;align-items:center;gap:5px;margin-left:auto;font-size:0.85rem;font-weight:normal'>"
        . "Called: <span class='section-called-count'>{$worked}/{$total}</span>"
        . "<span style='display:inline-block;width:60px;height:6px;background:#e0e0e0;border-radius:4px;overflow:hidden'>"
        . "<span class='section-called-bar' style='display:block;height:100%;width:{$pct}%;background:currentColor;border-radius:4px;transition:width 0.3s'></span>"
        . "</span></span>";
    echo "<div class=\"" . htmlspecialchars($css_class, ENT_QUOTES, 'UTF-8') . " queue-section\">";
    echo "<h2 class='section-toggle'><span class='caret'></span><span class='section-title'>" . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</span> <span style='font-weight:normal;font-size:0.9rem'>($total quotes)</span>{$progress_bar}</h2>";
    echo "<div class='section-body'>";

    if (empty($groups)) {
        echo "<p class='empty'>No quotes in this section today.</p></div></div>";
        return;
    }

    echo "<table>";
    echo "<tr>
        <th>Organisation</th>
        <th>Quote #</th>
        <th>Stage</th>
        <th>Pax</th>
        <th>Trip date</th>
        <th>Contact</th>
        <th>Mobile</th>
        <th>Region</th>"
        . ($show_call_cols ? "<th>Next call date</th><th>Due</th>" : "")
        . ($show_pending_days ? "<th>Days behind</th>" : "")
        . "<th>Calls</th><th>Created</th><th>Priority</th><th>Action</th></tr>";

    // Renders one quote row.
    $render_row = function (array $q, bool $first)
        use ($show_call_cols, $show_pending_days, $today, $is_manager, $session_user, $crm_quotetype, $is_readonly, $is_lead_queue, $can_personal_claim) {

        $org_name  = htmlspecialchars($q['organization_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
        $pax       = (int)$q['adults'] + (int)$q['children'] + (int)$q['infants'];
        $trip      = $q['trip_start_date'] ? date('d M Y', strtotime($q['trip_start_date'])) : '';
        $trip_attr = $q['trip_start_date'] ? htmlspecialchars(substr($q['trip_start_date'], 0, 10), ENT_QUOTES, 'UTF-8') : '';
        $qid       = (int) $q['quoteid'];
        $orgid    = (int) ($q['organizationid'] ?? 0);

        // Display-only Lead marker, exactly as the CRM renders it: appended when the stage is
        // 'Lead', never written back to quote_no. In the Leads queue every row keeps it for the
        // whole day, even after a conversion moves the stage to 'Created', since the caller is
        // scanning for the number they saw this morning. Without this, working a row renamed it.
        $is_lead_row = (($q['quotestage'] ?? '') === LEAD_STAGE_NAME) || $is_lead_queue;
        $qno_display = ($q['quote_no'] ?? '') . ($is_lead_row ? 'L' : '');

        $call_cols = '';
        if ($show_call_cols) {
            $ncd = $q['next_call_date'] ?? null;
            if ($ncd) {
                $diff       = (int) floor((strtotime($ncd) - strtotime($today)) / 86400);
                $label_date = date('d M Y', strtotime($ncd));
                // Graduated cue (wording stays neutral — landing in SP1 is by design, e.g. a
                // Sunday callback surfacing Monday): due today is green, a few days past is
                // orange, several days past turns red — reusing the same CARRYOVER_RED_DAYS
                // cutoff the carry-over column uses. SP1 only admits next_call_date <= today,
                // so the future branch is defensive only.
                if ($diff === 0) {
                    $days_html = "<span class='days-soon'>Today</span>";
                } elseif ($diff < 0) {
                    $n         = abs($diff);
                    $cls       = $n >= CARRYOVER_RED_DAYS ? 'days-overdue' : 'days-today';
                    $days_html = "<span class='$cls'>{$n} " . ($n === 1 ? 'day' : 'days') . " ago</span>";
                } else {
                    $days_html = "<span class='days-soon'>in {$diff} " . ($diff === 1 ? 'day' : 'days') . "</span>";
                }
                $call_cols = "<td>$label_date</td><td>$days_html</td>";
            } else {
                $call_cols = "<td></td><td></td>";
            }
        }

        // Server-side worked state: set on reload from load_from_daily_runs(); absent on
        // first load (nothing worked yet). Use ?? false so first-load quotes default to not-done.
        $worked  = $q['worked_today'] ?? false;
        if ($worked) {
            $tr_attr = $first ? " class='org-row' style='opacity:0.45;background:#f9f9f9'" : " style='opacity:0.45;background:#f9f9f9'";
        } else {
            $tr_attr = $first ? " class='org-row'" : "";
        }
        echo "<tr$tr_attr>";

        // Organisation cell: name on the first row of the block, badges beside it.
        $org_cell = '';
        if ($first) {
            $org_cell = $org_name;
            // Region chip and owner-name badge are independent conditions, not one combined
            // flag: $show_region_chip answers "is more than one region on screen" (true for
            // Mayur's own West+Gujarat page, where $is_manager is false), while the owner
            // name still only means something in a genuinely merged multi-person view
            // ($is_manager). Either, both, or neither can apply.
            $badge_parts = [];
            if (!empty($show_region_chip) && !empty($q['queue_region'])) {
                $badge_parts[] = htmlspecialchars($q['queue_region'], ENT_QUOTES, 'UTF-8');
            }
            if ($is_manager) {
                // Empty owner (region model only — legacy rows are never ownerless) marks a
                // region with no owner at all, not a caller name gone missing.
                $owner_label = ($q['owner'] ?? '') !== '' ? explode(' ', $q['owner'])[0] : '(unowned)';
                $badge_parts[] = htmlspecialchars($owner_label, ENT_QUOTES, 'UTF-8');
            }
            if ($badge_parts) {
                $org_cell .= " <span style='font-weight:normal;font-size:0.72rem;color:#fff;background:#888;border-radius:3px;padding:1px 5px;margin-left:4px'>"
                          . implode(' &middot; ', $badge_parts) . "</span>";
            }
        }
        echo "<td class='org-cell'>$org_cell</td>";

        $crm_url = '/quote.php?opt=summary&sales=true&quotetype=' . urlencode($crm_quotetype) . '&quoteid=' . $qid;
        echo "<td><a href=\"" . $crm_url . "\" target=\"_blank\">"
            . htmlspecialchars($qno_display, ENT_QUOTES, 'UTF-8') . "</a></td>";
        $current_stage = $q['quotestage'] ?? '';
        echo "<td>" . htmlspecialchars($current_stage, ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>$pax</td>";
        echo "<td>$trip</td>";
        echo "<td>" . htmlspecialchars($q['contactname'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($q['contactmobile'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars($q['assigned_to_region'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        echo $call_cols;
        // Days behind — only in the carry-over section. Reuses the overdue/today colour classes.
        if ($show_pending_days) {
            $db  = (int) ($q['days_behind'] ?? 0);
            $cls = $db >= CARRYOVER_RED_DAYS ? 'days-overdue' : 'days-today';
            if ($db === 0) {
                $db_html = "<span class='days-soon'>Today</span>";
            } else {
                $db_html = "<span class='$cls'>{$db} " . ($db === 1 ? 'day' : 'days') . " ago</span>";
            }
            echo "<td>$db_html</td>";
        }
        echo "<td class='calls-cell'>" . (int)$q['call_count'] . "</td>";
        echo "<td>" . ($q['created_at'] ? date('d M Y', strtotime($q['created_at'])) : '') . "</td>";

        // Priority: editable select, or a static label where the viewer may not write.
        $priority_opts = ['' => 'Blank', 'not connected' => 'Not connected', 'low' => 'Low', 'high' => 'High'];
        if ($is_readonly) {
            echo "<td style='font-size:0.8rem;color:#777'>" . htmlspecialchars($priority_opts[$q['priority'] ?? ''] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
        } else {
            $p     = $q['priority'] ?? '';
            $p_sel = '<select class="priority-sel" data-quoteid="' . $qid . '" style="font-size:0.8rem;padding:2px 4px">';
            foreach ($priority_opts as $pval => $plabel) {
                $p_sel .= '<option value="' . htmlspecialchars($pval, ENT_QUOTES, 'UTF-8') . '"' . ($p === $pval ? ' selected' : '') . '>'
                        . htmlspecialchars($plabel, ENT_QUOTES, 'UTF-8') . '</option>';
            }
            $p_sel .= '</select>';
            echo "<td>$p_sel</td>";
        }

        // Action cell
        // NB: background is set via the .hist-btn CSS rule (not inline) so the :hover rule
        // can override it, since inline styles beat stylesheet :hover.
        $btn_style = "font-size:0.78rem;padding:2px 8px;cursor:pointer;border:1px solid #aaa;border-radius:3px;margin-left:4px;";
        echo "<td class='action-cell'>";
        {
            $qno_js = htmlspecialchars($qno_display, ENT_QUOTES, 'UTF-8');
            if ($worked) {
                $wo = $q['worked_outcome'] ?? '';
                $terminal_labels = [
                    'rejected' => 'Rejected', 'accepted' => 'Accepted',
                    'lead_rejected' => 'Rejected', 'lead_converted' => 'Converted',
                ];
                if (isset($terminal_labels[$wo])) {
                    $t_colors = [
                        'rejected' => '#c0392b', 'accepted' => '#27ae60',
                        'lead_rejected' => '#c0392b', 'lead_converted' => '#27ae60',
                    ];
                    $t_color  = $t_colors[$wo];
                    $t_label  = $terminal_labels[$wo];
                    echo "<button type='button' class='log-btn' disabled"
                       . " style='background:#fff;color:$t_color;border-color:$t_color;cursor:default'>&#10003; $t_label</button>";
                } elseif ($is_readonly) {
                    // class='log-btn' is what carries the border/padding/font-size; without it
                    // the dashboard's global button styling reaches this bare tag instead.
                    echo "<button type='button' class='log-btn' disabled"
                       . " style='background:#fff;color:#27ae60;border-color:#27ae60;cursor:default'>&#10003; Done</button>";
                } else {
                    $fid_attr = ((int) ($q['worked_followup_id'] ?? 0)) ?: '';
                    $notes_js = htmlspecialchars($q['worked_notes'] ?? '', ENT_QUOTES, 'UTF-8');
                    $wo_attr  = htmlspecialchars($wo, ENT_QUOTES, 'UTF-8');
                    $wc_attr  = htmlspecialchars($q['worked_channel'] ?? '', ENT_QUOTES, 'UTF-8');
                    $wnd_raw  = $q['worked_next_date'] ?? null;
                    $wnd_attr = ($wnd_raw && $wnd_raw !== '0000-00-00 00:00:00')
                              ? htmlspecialchars(substr(str_replace(' ', 'T', $wnd_raw), 0, 16), ENT_QUOTES, 'UTF-8')
                              : '';
                    echo "<button type='button' class='log-btn' data-quoteid='$qid' data-quoteno='$qno_js'"
                       . " data-followup-id='$fid_attr' data-notes='$notes_js'"
                       . " data-outcome='$wo_attr' data-date='$wnd_attr' data-channel='$wc_attr'"
                       . " data-stage='" . htmlspecialchars($current_stage, ENT_QUOTES, 'UTF-8') . "'"
                       . " data-trip='$trip_attr'"
                       . " style='background:#fff;color:#27ae60;border-color:#27ae60'>&#10003; Done"
                       . " <span style='font-size:0.7rem;text-decoration:underline;margin-left:4px'>edit</span></button>";
                    // A different action from correcting the existing call: no followup-id, so
                    // the modal opens blank and inserts. Own class, never 'log-btn' (that carries
                    // the edit handler and dataset this button must not have). Only reached here,
                    // so terminal/read-only rows fall through without it.
                    echo " <button type='button' class='addcall-btn' data-quoteid='$qid' data-quoteno='$qno_js'"
                       . " data-stage='" . htmlspecialchars($current_stage, ENT_QUOTES, 'UTF-8') . "'"
                       . " data-trip='$trip_attr' style='$btn_style'"
                       . " title='Record another call the client made on this quote'>+ Inbound</button>";
                }
            } elseif ($is_readonly) {
                // No placeholder button — nothing to act on, unlike the worked states below
                // which still convey status (Done / Rejected / Accepted) even if disabled.
            } else {
                echo "<button type='button' class='log-btn' data-quoteid='$qid' data-quoteno='$qno_js'"
                   . " data-stage='" . htmlspecialchars($current_stage, ENT_QUOTES, 'UTF-8') . "'"
                   . " data-trip='$trip_attr'>Log</button>";
            }
            // "Pull to me" — only for a supervisor with no region of their own, browsing
            // the merged view, on a quote that isn't already theirs (own fullname match,
            // same test correct_region_ownership() uses to know a quote is exempt).
            if ($can_personal_claim && ($q['owner'] ?? '') !== (QUEUE_ACCESS_FULLNAMES[$session_user] ?? null)) {
                echo " <button type='button' class='claim-btn' data-quoteid='$qid' data-claim-as='"
                    . htmlspecialchars($session_user, ENT_QUOTES, 'UTF-8') . "' style='$btn_style'>Pull to me</button>";
            }
            echo "<button type='button' class='hist-btn' data-quoteid='$qid' style='$btn_style'>History</button>";
        }
        echo "</td>";
        echo "</tr>";
    };

    foreach ($groups as $org_quotes) {
        $first = true;
        foreach ($org_quotes as $q) {
            $render_row($q, $first);
            $first = false;
        }
    }

    echo "</table></div></div>";
}

?>

<?php if ($pending_warning) echo $pending_warning; ?>
<?php if (!empty($carryover_groups)) render_section($carryover_groups, 'Carry-over - Unfinished (oldest first)', 'carryover', $today, false, true); ?>
<?php if ($is_lead_queue): ?>
    <?php render_section($lead_sp1_groups, 'Scheduled follow-ups', 'sp1', $today, true); ?>
    <?php render_section($lead_sp2_groups, 'Recently created',     'sp2', $today); ?>
    <?php render_section($lead_sp3_groups, 'Upcoming trips',       'sp3', $today); ?>
    <?php render_section($lead_sp4_groups, 'Further out',          'sp4', $today); ?>
<?php else: ?>
    <?php render_section($sp1_groups, 'Scheduled follow-ups', 'sp1', $today, true); ?>
    <?php render_section($sp2_groups, 'Recently created',     'sp2', $today); ?>
    <?php render_section($sp3_groups, 'Upcoming trips',       'sp3', $today); ?>
    <?php render_section($sp4_groups, 'Further out',          'sp4', $today); ?>
<?php endif; ?>


<dialog id="inbound-search-modal">
    <h3>Search Quote</h3>
    <div class="form-row">
        <label for="inbound-quote-no">Quote #</label>
        <input type="text" id="inbound-quote-no" placeholder="e.g. TDU00123" autocomplete="off">
        <button type="button" id="inbound-search-btn"
                style="flex:0 0 auto;display:inline-block;width:auto;padding:4px 12px;font-size:0.875rem;cursor:pointer;background:#fff;color:#333;border:1px solid #aaa;border-radius:3px">
            Search
        </button>
    </div>
    <div id="inbound-not-found" style="display:none;color:#c0392b;font-size:0.875rem;margin:4px 0 8px"></div>
    <div id="inbound-result"    style="display:none;margin:12px 0;padding:10px 12px;background:#f8f9fa;border-radius:4px;font-size:0.875rem"></div>
    <div class="dialog-actions">
        <button type="button" id="inbound-cancel">Cancel</button>
    </div>
</dialog>

<dialog id="outcome-modal">
    <form method="post" action="log_outcome.php">
        <h3>Log Outcome &mdash; <span id="modal-quote-no"></span></h3>
        <input type="hidden" name="quoteid"     id="modal-quoteid">
        <input type="hidden" name="followup_id" id="modal-followup-id" value="">

        <div class="form-row">
            <label for="modal-outcome">Outcome</label>
            <select name="outcome" id="modal-outcome" required>
                <option value="">— select —</option>
                <option value="next_call">Next call</option>
                <option value="no_answer_email">No answer + email sent</option>
                <option value="interested">Interested</option>
                <option value="inbound_call">Inbound call</option>
                <?php // Closing outcomes depend on the quote's own stage, not on which queue page
                      // this is: Search Quote can open a Lead from Follow-up and vice versa.
                      // applyStageMode() in queue.js unhides one group and hides the other. ?>
                <option value="lead_converted" class="oc-lead" hidden>Convert to quote</option>
                <option value="lead_rejected"  class="oc-lead" hidden>Reject lead</option>
                <option value="requote"  class="oc-followup" id="outcome-opt-requote" hidden>Requote</option>
                <option value="rejected" class="oc-followup" hidden>Rejected</option>
                <option value="accepted" class="oc-followup" hidden>Accepted</option>
            </select>
            <?php // "+ Inbound" only ever offers one outcome, so a <select> with everything else
                  // hidden still shows a dropdown arrow for a choice that doesn't exist. Swapped
                  // in by applyInboundOnly() in queue.js; the <select> stays in the DOM (display:
                  // none, not disabled) so its value keeps submitting via FormData. ?>
            <span id="modal-outcome-static" style="display:none;padding:4px 6px;font-size:0.875rem">Inbound call</span>
        </div>

        <div class="form-row" id="modal-date-row" style="display:none">
            <label for="modal-next-date" id="modal-date-label">Back in queue on</label>
            <input type="text" name="next_call_date" id="modal-next-date" autocomplete="off">
            <span id="modal-date-availability" style="flex:0 0 auto;font-size:0.8rem;margin-left:8px;white-space:nowrap"></span>
        </div>

        <div class="form-row">
            <label for="modal-channel">Channel</label>
            <select name="channel" id="modal-channel">
                <option value="phone">Phone</option>
                <option value="email">Email</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="other">Other</option>
            </select>
        </div>

        <div class="form-row" id="modal-reject-row" style="display:none">
            <label for="modal-reject-reason">Reason</label>
            <select name="feedback_reason" id="modal-reject-reason">
                <option value="">— select reason —</option>
                <?php // Both lists live in the DOM at once and applyStageMode() toggles them by
                      // class, for the same reason the outcome options above do. The Lead list
                      // describes why an enquiry went elsewhere, not how our own offer was
                      // judged, since a Lead was never quoted. A few values appear in both lists
                      // (lost_to_response_time, trip_cancelled, other), which is harmless while
                      // one group is always hidden and the selection cleared on every switch. ?>
                <optgroup label="Company — Avoidable" class="rr-lead" hidden>
                    <option value="lost_to_response_time">Lost to response time</option>
                </optgroup>
                <optgroup label="Booked with another operator" class="rr-lead" hidden>
                    <option value="competitor_cheaper">Cheaper elsewhere</option>
                    <option value="competitor_itinerary">Better itinerary or inclusions</option>
                    <option value="competitor_availability">Better availability of services</option>
                    <option value="booked_direct">Booked direct with the supplier</option>
                    <option value="competitor_unknown">Reason not given</option>
                </optgroup>
                <optgroup label="Client — Out of our hands" class="rr-lead" hidden>
                    <option value="no_response">No response after repeated attempts</option>
                    <option value="not_ready_to_travel">Not ready to travel, no dates yet</option>
                    <option value="trip_cancelled">Trip cancelled</option>
                </optgroup>
                <optgroup label="Not a real opportunity" class="rr-lead" hidden>
                    <option value="enquiry_only">Information only, no booking intent</option>
                    <option value="out_of_scope">Destination or product we do not handle</option>
                </optgroup>
                <optgroup label="Other" class="rr-lead" hidden>
                    <option value="other">Other (specify below)</option>
                </optgroup>
                <optgroup label="Company — Avoidable" class="rr-followup" hidden>
                    <option value="lost_to_response_time">Lost to response time</option>
                </optgroup>
                <optgroup label="Market / Competition" class="rr-followup" hidden>
                    <option value="too_expensive">Too expensive</option>
                    <option value="went_with_competitor">Confirmed with competitor (ITO)</option>
                </optgroup>
                <optgroup label="Client — Out of our hands" class="rr-followup" hidden>
                    <option value="trip_cancelled">Trip cancelled</option>
                    <option value="visa_issues">Visa issues</option>
                    <option value="no_response_ghosted">No response</option>
                    <option value="destination_change">Destination change</option>
                    <option value="not_ready_to_travel">Not ready to travel</option>
                </optgroup>
                <optgroup label="Other" class="rr-followup" hidden>
                    <option value="other">Other (specify below)</option>
                </optgroup>
            </select>
        </div>

        <div class="form-row" id="modal-reject-other-row" style="display:none">
            <label for="modal-reject-other">Specify</label>
            <input type="text" name="feedback_other" id="modal-reject-other"
                   placeholder="Describe the reason…" maxlength="255">
        </div>

        <div id="modal-accepted-warning" style="display:none;color:#c0392b;background:#fff5f5;border:1px solid #fca5a5;border-radius:4px;padding:8px 12px;font-size:0.85rem;margin:0 0 10px 110px">
            Passenger data or payment is incomplete — contact ops before accepting.
        </div>

        <div id="modal-notes-row" class="form-row" style="align-items:flex-start">
            <label for="modal-notes" style="padding-top:4px">Notes</label>
            <textarea name="notes" id="modal-notes" rows="3" required
                      style="flex:1;font-size:0.875rem;padding:4px 6px;resize:vertical"
                      placeholder="What was discussed…"></textarea>
        </div>

        <div class="dialog-actions">
            <button type="button" id="modal-cancel">Cancel</button>
            &nbsp;
            <button type="submit">Save outcome</button>
        </div>
    </form>
</dialog>


<script src="<?= $asset_base ?>/assets/vendor/flatpickr/flatpickr.min.js"></script>
<script src="<?= $asset_base ?>/assets/vendor/flatpickr/plugins/confirmDate/confirmDate.js"></script>
<script>
var CSRF_TOKEN          = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
var AJAX_BASE           = <?= json_encode(str_replace($_SERVER['DOCUMENT_ROOT'], '', dirname(__DIR__))) ?>;
var MANUAL_URL          = <?= json_encode(str_replace($_SERVER['DOCUMENT_ROOT'], '', dirname(dirname(__DIR__))) . '/user_manual.php') ?>;
var ACCEPTED_BLOCKED    = <?= json_encode(array_keys($accepted_blocked)) ?>;
var SCHEDULE_MAX        = <?= (int) ($is_lead_queue ? LEAD_MAX_SCHEDULED_PER_DAY_PER_CALLER : MAX_SCHEDULED_PER_DAY_PER_CALLER) ?>;
var SCHEDULE_WARN_RATIO = <?= json_encode(SCHEDULE_CAP_WARN_RATIO) ?>;
var QUEUE_TODAY          = <?= json_encode($today) ?>;
var QUEUE_DEFAULT_HOUR   = <?= (int) date('G') ?>;
var QUEUE_DEFAULT_MINUTE = <?= (int) date('i') ?>;
var QUEUE_MIN_DATE       = <?= json_encode(date('Y-m-d', strtotime($today . ' +1 day'))) ?>;
<?php // Both windows ship and the modal picks by the quote's stage, not by page: Search Quote
      // can open a Lead from Follow-up and its shorter window has to follow it there. Read off
      // the constants so the view needs nothing beyond config.php. ?>
var QUEUE_MAX_DATE       = <?= json_encode(date('Y-m-d', strtotime($today . ' +' . SCHEDULE_WINDOW_DAYS      . ' days'))) ?>;
var LEAD_MAX_DATE        = <?= json_encode(date('Y-m-d', strtotime($today . ' +' . LEAD_SCHEDULE_WINDOW_DAYS . ' days'))) ?>;
var VIEWER_IS_ADMIN      = <?= json_encode(!empty($viewer_is_admin)) ?>;
var SESSION_USER         = <?= json_encode($session_user) ?>;
var MY_FULLNAME          = <?= json_encode($my_fullname ?? '') ?>;
var IS_READONLY          = <?= json_encode(!empty($is_readonly)) ?>;
var CAN_PERSONAL_CLAIM   = <?= json_encode(!empty($can_claim_search)) ?>; // Search Quote's own "Pull to me"
// Same value the table rows' quote links use. details.php normalises 'fit-dashboard' to
// 'group' and only branches on the literal 'fit', so it is safe for any quote and stage.
var CRM_QUOTETYPE        = <?= json_encode($crm_quotetype) ?>;
</script>
<script src="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/js/queue.js')) ?>"></script>
</div><!-- .tdu-queue-wrap -->
<?php if (empty($embedded_in_dashboard)): ?>
</body>
</html>
<?php endif; ?>
