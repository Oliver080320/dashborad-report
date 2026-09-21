<?php
/** @var array  $rows               org blocks (group_awaiting_by_org()) with a resolvable region */
/** @var array  $unrouted           org blocks whose region maps to nothing, a data problem rather than a queue */
/** @var bool   $viewer_is_admin */
/** @var bool   $is_readonly        true for a supervisor: sees the same list, logs nothing */
/** @var bool   $has_followup_queue false for a post-sale holder who is nothing else */
/** @var string $caller_label */
/** @var string $today */
/** @var string $session_user */
//
// One shared list, same shape as payment_deadline_view.php: no owned/pool split, no
// "Pull to me" — everyone who reaches this page sees the same rows and, unless
// read-only, can act on all of them. Self-contained (own CSS/JS), the same reasoning
// fit_viewer_live_book.php's JS comment gives for not loading queue.js/queue.css here:
// this page doesn't share this queue's globals or DOM conventions.
$asset_base = str_replace($_SERVER['DOCUMENT_ROOT'], '', dirname(__DIR__));
$is_readonly = $is_readonly ?? true;

if (!function_exists('asset_v')) {
    function asset_v(string $rel_path): string {
        $fs_path = __DIR__ . '/../' . $rel_path;
        return $rel_path . '?v=' . (@filemtime($fs_path) ?: time());
    }
}

$total_rows     = awaiting_info_row_count_standalone($rows);
$total_unrouted = awaiting_info_row_count_standalone($unrouted);

// Local copy of queue_view.php's awaiting_info_row_count(): the arrays are org blocks, so
// a plain count() would report organisations and understate the day's work. Its own name
// to avoid a redeclare error if this page is ever required alongside queue_view.php.
function awaiting_info_row_count_standalone(array $blocks): int
{
    return array_sum(array_map('count', $blocks));
}

// One line per reason family, same as queue_view.php's render_awaiting_info_missing().
function render_awaiting_missing(array $reason_groups): void
{
    foreach ($reason_groups as $g) {
        if (($g['key'] ?? '') === 'other') {
            foreach ($g['raw'] as $raw) {
                echo '<div class="ai-reason ai-reason--other">'
                   . htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            continue;
        }
        $dates = !empty($g['days'])
            ? ' (' . htmlspecialchars(compact_day_dates($g['days'], $g['day_dates'] ?? []), ENT_QUOTES, 'UTF-8') . ')'
            : '';
        echo '<div class="ai-reason ai-reason--' . htmlspecialchars($g['severity'], ENT_QUOTES, 'UTF-8') . '">'
           . htmlspecialchars($g['label'], ENT_QUOTES, 'UTF-8') . $dates . '</div>';
    }
}

// Org blocks, same shape queue_view.php's render_awaiting_info_table() takes. No
// mine/pool distinction here (there is nothing to pull), and a Region column replaces
// the owner badge, since this merged view has no single "yours" to badge against.
function render_awaiting_table(array $blocks, bool $can_log, bool $actionable_at_all): void
{
    echo '<table class="ai-table"><thead><tr>'
       . '<th>Organisation</th><th>Quote #</th><th>Region</th><th>Stage</th><th>Trip date</th>'
       . '<th>Sales agent</th><th>Contact</th><th>Mobile</th><th>Blocked</th><th>Missing</th>'
       . '<th>Calls</th><th>Last call</th><th></th></tr></thead><tbody>';

    foreach ($blocks as $blockRows) {
        $first = true;
        foreach ($blockRows as $r) {
            $qid      = (int) $r['quoteid'];
            $quote_no = (string) ($r['quote_no'] ?? '');
            $crm_quotetype = ($r['quote_type'] === 'groups') ? 'group' : 'fit-dashboard';
            $crm_url = '/quote.php?opt=summary&sales=true&quotetype=' . urlencode($crm_quotetype) . '&quoteid=' . $qid;

            $row_classes = [];
            if ($first) $row_classes[] = 'org-row';
            if (!empty($r['called_today'])) $row_classes[] = 'ai-row--called';
            echo '<tr' . ($row_classes ? ' class="' . implode(' ', $row_classes) . '"' : '')
               . ' data-org="'  . htmlspecialchars(awaiting_org_key($r), ENT_QUOTES, 'UTF-8') . '"'
               . ' data-trip="' . htmlspecialchars((string) ($r['trip_date'] ?? ''), ENT_QUOTES, 'UTF-8') . '"'
               . '>';

            echo '<td class="ai-org"><span class="ai-org-name">'
               . htmlspecialchars($r['organization_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8')
               . '</span></td>';

            echo '<td><a href="' . htmlspecialchars($crm_url, ENT_QUOTES, 'UTF-8') . '" target="_blank">'
               . htmlspecialchars($quote_no, ENT_QUOTES, 'UTF-8') . '</a></td>';

            // Display only, same convention as Payment Deadline's Region column: an
            // unrouted row shows the raw CRM value, since that is what has to be fixed.
            if ($r['queue_region'] !== null) {
                echo '<td>' . htmlspecialchars($r['queue_region'], ENT_QUOTES, 'UTF-8') . '</td>';
            } else {
                $raw_region = trim((string) ($r['region'] ?? ''));
                echo '<td><span class="ai-unknown">'
                   . htmlspecialchars($raw_region !== '' ? $raw_region : 'no region set', ENT_QUOTES, 'UTF-8')
                   . '</span></td>';
            }

            echo '<td>' . htmlspecialchars((string) ($r['quotestage'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';

            echo '<td>' . ($r['trip_date'] ? date('d M Y', strtotime($r['trip_date'])) : '&mdash;')
               . ($r['days_to_trip'] !== null ? '<br><span class="ai-days-to-trip">' . (int) $r['days_to_trip'] . 'd</span>' : '')
               . '</td>';

            // Names the field, claims nothing about who did what — same reasoning
            // payment_deadline_view.php's own "Sales agent" column carries.
            $agent = trim((string) ($r['owner'] ?? ''));
            echo '<td>' . htmlspecialchars($agent !== '' ? $agent : '&mdash;', ENT_QUOTES, 'UTF-8') . '</td>';

            $contact = htmlspecialchars((string) ($r['contactname'] ?? ''), ENT_QUOTES, 'UTF-8');
            echo '<td>' . ($contact !== '' ? $contact : '&mdash;') . '</td>';

            $mobile = trim((string) ($r['contact_mobile'] ?? ''));
            echo '<td>' . ($mobile !== '' ? htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8') : '&mdash;') . '</td>';

            $blocked = $r['days_blocked'];
            $b_cls   = ($blocked !== null && $blocked >= AWAITING_INFO_RED_DAYS) ? ' class="ai-blocked--red"' : '';
            echo '<td' . $b_cls . '>' . ($blocked !== null ? (int) $blocked . 'd' : '&mdash;') . '</td>';

            $group_count = count($r['reason_groups']);
            echo '<td class="ai-missing"><details><summary>'
               . $group_count . ($group_count === 1 ? ' item' : ' items') . ' outstanding</summary>';
            render_awaiting_missing($r['reason_groups']);
            echo '</details></td>';

            echo '<td>' . (int) ($r['call_count'] ?? 0) . '</td>';

            if (!empty($r['last_call_time'])) {
                $by = trim((string) ($r['last_call_by'] ?? ''));
                echo '<td>' . htmlspecialchars(date('d M Y', strtotime($r['last_call_time'])), ENT_QUOTES, 'UTF-8')
                   . (!empty($r['called_today']) ? ' <span class="ai-called-today">today</span>' : '')
                   . ($by !== '' ? '<br><span class="ai-last-by">by ' . htmlspecialchars($by, ENT_QUOTES, 'UTF-8') . '</span>' : '')
                   . '</td>';
            } else {
                echo '<td class="ai-unknown">&mdash;</td>';
            }

            echo '<td class="ai-actions">';
            if ($can_log && $actionable_at_all) {
                $missing_txt = [];
                foreach ($r['reason_groups'] as $g) {
                    $missing_txt[] = ($g['key'] === 'other')
                        ? implode('; ', $g['raw'])
                        : $g['label'] . (!empty($g['days']) ? ' (' . compact_day_dates($g['days'], $g['day_dates'] ?? []) . ')' : '');
                }
                echo '<button type="button" class="ai-log-btn" data-quoteid="' . $qid . '"'
                   . ' data-quoteno="' . htmlspecialchars($quote_no, ENT_QUOTES, 'UTF-8') . '"'
                   . ' data-missing="' . htmlspecialchars(
                         (string) json_encode($missing_txt, JSON_INVALID_UTF8_SUBSTITUTE),
                         ENT_QUOTES, 'UTF-8') . '">Log</button> ';
            }
            echo '<button type="button" class="ai-hist-btn" data-quoteid="' . $qid . '">History</button>';
            echo '</td></tr>';

            echo '<tr class="ai-hist-row" id="ai-hist-' . $qid . '" style="display:none">'
               . '<td colspan="13" class="ai-hist-cell"></td></tr>';

            $first = false;
        }
    }
    echo '</tbody></table>';
}
?>
<?php if (empty($embedded_in_dashboard)): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TDU Awaiting Information Queue</title>
</head>
<body>
<?php endif; ?>
<link rel="stylesheet" href="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/css/awaiting-info-page.css'), ENT_QUOTES, 'UTF-8') ?>">
<div class="tdu-queue-wrap tdu-ai-wrap">

<div class="ai-page-header">
    <h1>Awaiting Information &mdash; <?= htmlspecialchars($caller_label, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($has_followup_queue): ?>
    <?php
    $back_params = [];
    if (!empty($embedded_in_dashboard)) $back_params['opt'] = 'sales-queue';
    if ($viewer_is_admin)               $back_params['user'] = $session_user;
    $back_href = '?' . http_build_query($back_params);
    ?>
    <a class="ai-backlink" href="<?= htmlspecialchars($back_href, ENT_QUOTES, 'UTF-8') ?>">Back to Follow-up queue</a>
    <?php endif; ?>
    <?php
    // Cross-link to the other post-sale queue — same pair of people, different job.
    $pd_params = ['view' => 'payment_deadline'];
    if (!empty($embedded_in_dashboard)) $pd_params['opt']  = 'sales-queue';
    if ($viewer_is_admin)               $pd_params['user'] = $session_user;
    ?>
    <a class="ai-backlink" href="?<?= htmlspecialchars(http_build_query($pd_params), ENT_QUOTES, 'UTF-8') ?>">Payment deadlines</a>
</div>

<p class="ai-page-subtitle">
    <?= date('l, d F Y', strtotime($today)) ?>
    &nbsp;|&nbsp; <?= $total_rows ?> blocked
    <?php if ($total_unrouted): ?>
    &nbsp;|&nbsp; <span class="ai-pool-count"><?= $total_unrouted ?> unrouted</span>
    <?php endif; ?>
</p>

<p class="ai-page-explainer">
    These quotes are confirmed. The booking cannot proceed until the client supplies what is
    listed. A row disappears once the information arrives. One shared list across every
    region, FIT and Groups &amp; MICE together.
    <?php if ($is_readonly): ?>
    <br><strong>You have view-only access to this queue.</strong> History is open on every row;
    logging a call is left to the people chasing this information.
    <?php endif; ?>
</p>

<?php if ($unrouted): ?>
<div class="ai-section ai-pool-section collapsed">
    <h4 class="ai-section-toggle"><span class="ai-caret"></span>
        <span class="ai-section-title">Unrouted</span>
        <span style="font-weight:normal;font-size:0.85rem">(<?= $total_unrouted ?> quotes)</span>
    </h4>
    <div class="ai-section-body">
        <p class="ai-hint">
            These quotes have no region the queue can resolve, so they are shown for visibility
            only and carry no Log button. Set the region on the quote in the CRM and it joins
            the list above.
        </p>
        <?php render_awaiting_table($unrouted, false, false); ?>
    </div>
</div>
<?php endif; ?>

<?php if ($rows): ?>
    <?php render_awaiting_table($rows, !$is_readonly, true); ?>
<?php else: ?>
    <p class="ai-empty">Nothing blocked right now.</p>
<?php endif; ?>

<dialog id="ai-outcome-modal">
    <form id="ai-outcome-form">
        <h3>Log Outcome &mdash; <span id="ai-modal-quote-no"></span></h3>
        <input type="hidden" name="quoteid" id="ai-modal-quoteid">

        <div id="ai-modal-missing"></div>

        <div class="form-row">
            <label for="ai-modal-outcome">Outcome</label>
            <select name="outcome" id="ai-modal-outcome" required>
                <option value="">— select —</option>
                <option value="info_no_answer">No answer</option>
                <option value="info_contacted">Contacted, still to come</option>
                <option value="info_received">Information received</option>
            </select>
        </div>

        <div class="form-row" style="align-items:flex-start">
            <label for="ai-modal-notes" style="padding-top:4px">Notes</label>
            <textarea name="notes" id="ai-modal-notes" rows="2" required
                      style="flex:1;font-size:0.875rem;padding:4px 6px;resize:vertical"
                      placeholder="What was discussed…"></textarea>
        </div>

        <div class="form-row" id="ai-modal-note-block" hidden style="align-items:flex-start">
            <label for="ai-modal-note-body" style="padding-top:4px">Information</label>
            <textarea name="note_body" id="ai-modal-note-body" rows="4"
                      style="flex:1;font-size:0.875rem;padding:4px 6px;resize:vertical"
                      placeholder="What the client provided…"></textarea>
        </div>

        <div class="form-row">
            <label for="ai-modal-channel">Channel</label>
            <select name="channel" id="ai-modal-channel">
                <option value="phone">Phone</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="email">Email</option>
                <option value="other">Other</option>
            </select>
        </div>

        <div class="dialog-actions">
            <button type="button" id="ai-modal-cancel">Cancel</button>
            &nbsp;
            <button type="submit">Save outcome</button>
        </div>
    </form>
</dialog>

</div>
<script>
var CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
var AJAX_BASE  = <?= json_encode(str_replace($_SERVER['DOCUMENT_ROOT'], '', dirname(__DIR__))) ?>;
</script>
<script src="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/js/awaiting-info-page.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php if (empty($embedded_in_dashboard)): ?>
</body>
</html>
<?php endif; ?>
