<?php
/** @var array  $rows               every routable quote in the cohort, already bucket-tagged */
/** @var array  $unrouted           quotes whose region maps to nothing, a data problem rather than a queue */
/** @var bool   $viewer_is_admin */
/** @var bool   $is_readonly        true for a supervisor: sees the same list, logs nothing */
/** @var bool   $has_followup_queue false for a post-sale holder who is nothing else */
/** @var string $caller_label */
/** @var string $today */
/** @var string $session_user */
//
// One shared list. There is no owned/pool split, no peer section and no per-caller
// admin split any more: this queue is access-gated rather than owned, so every viewer
// who reaches this page sees exactly the same rows and can act on all of them.
// "Log outcome" is wired to ajax_log_payment_outcome.php; the old "Pull to me" went
// with the ownership model it belonged to.
//
// Two things suppress the Log button, and they are separate questions: $is_readonly is
// about the viewer (a supervisor watches without chasing), and the unrouted block is
// about the row (its region has to be fixed in the CRM before it is anybody's work).
$asset_base = str_replace($_SERVER['DOCUMENT_ROOT'], '', dirname(__DIR__));

// Fail closed if a controller ever forgets to set it, matching queue_view.php's own default.
$is_readonly = $is_readonly ?? true;

// Cache-busting — Cloudflare edge-caches static .js/.css and ignores a browser hard
// refresh, so a deploy can silently keep serving a stale asset. Mirrors the same
// helper in queue_view.php / monitoring_view.php; redeclared defensively because this
// view can be included in the same request as queue_view.php is not, but a future
// router change could make that possible.
if (!function_exists('asset_v')) {
    function asset_v(string $rel_path): string {
        $fs_path = __DIR__ . '/../' . $rel_path;
        return $rel_path . '?v=' . (@filemtime($fs_path) ?: time());
    }
}

$total_rows     = count($rows);
$total_unrouted = count($unrouted);

/**
 * Splits the list (already 'bucket'-tagged by build_payment_deadline_queue()) into
 * collapsible urgency sections, same accordion shape (caret + coloured heading +
 * count + progress bar) as the Follow-up queue's Carry-over/SP1-4 tiers (queue_view.php's
 * render_section()). "Logged: X/Y" stands in for that page's "Called: X/Y" — it counts
 * rows with an active scheduled callback (pending) out of the section's total, since Payment
 * Deadline has no daily reset to measure "worked today" against. Order is most-urgent
 * first; each bucket's own rows keep the sort order build_payment_deadline_queue()
 * already gave them (pending sinks to the bottom within the bucket, not across it).
 */
function render_bucket_sections(array $rows, bool $can_log): void
{
    if (!$rows) return;

    $buckets = [
        'escalate'     => [
            'label' => 'Escalate', 'class' => 'pd-escalate',
            'hint'  => 'Deadline passed with ' . PAYMENT_DEADLINE_ATTEMPTS_BEFORE_FLAG
                     . '+ calls made and no resolution, or the trip is less than '
                     . PAYMENT_DEADLINE_ESCALATE_TRIP_DAYS
                     . ' days away — let the owner know this needs following up on.',
        ],
        'overdue'      => ['label' => 'Overdue',  'class' => 'pd-overdue',  'hint' => null],
        'due_soon'     => ['label' => 'Due soon', 'class' => 'pd-due-soon', 'hint' => null],
        'paid_waiting' => [
            'label' => 'Paid — waiting for stage change', 'class' => 'pd-paid',
            'hint'  => 'Payment already matches the amount due. Nothing to chase — just needs the CRM stage moved along.',
        ],
    ];

    foreach ($buckets as $key => $meta) {
        $subset = array_values(array_filter($rows, fn($r) => $r['bucket'] === $key));
        if (!$subset) continue;

        $total   = count($subset);
        // called_today counts as "logged" too — a no-answer with no valid pre-trip date
        // to schedule (see the called_today comment in build_payment_deadline_queue())
        // was still actioned today, it just has no future next_call_date to show pending.
        $logged  = count(array_filter($subset, fn($r) => $r['pending'] || $r['called_today']));
        $pct     = $total > 0 ? round($logged / $total * 100) : 0;

        echo '<div class="pd-section ' . $meta['class'] . '">';
        echo '<h4 class="pd-section-toggle"><span class="pd-caret"></span>'
           . '<span class="pd-section-title">' . htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') . '</span>'
           . ' <span style="font-weight:normal;font-size:0.85rem">(' . $total . ' quotes)</span>'
           . '<span class="pd-progress">Logged: <strong>' . $logged . '/' . $total . '</strong>'
           . '<span class="pd-progress-track"><span class="pd-progress-bar" style="width:' . $pct . '%"></span></span>'
           . '</span></h4>';
        echo '<div class="pd-section-body">';
        if ($meta['hint']) {
            echo '<p class="pd-hint">' . htmlspecialchars($meta['hint'], ENT_QUOTES, 'UTF-8') . '</p>';
        }
        render_payment_table($subset, $can_log);
        echo '</div></div>';
    }
}

// $actionable is false for the unrouted block (the fix is in the CRM, giving the quote a
// region, not on this page) and false for a read-only viewer. Both land on the same
// rendering: History stays, Log does not.
function render_payment_table(array $rows, bool $actionable): void
{
    echo '<table class="pd-table">';
    echo '<thead><tr>'
       . '<th>Organisation</th>'
       . '<th>Quote</th>'
       . '<th>Region</th>'
       . '<th>Stage</th>'
       . '<th>Sales agent</th>'
       . '<th>Mobile</th>'
       . '<th>Created</th>'
       . '<th>Trip</th>'
       . '<th>Deadline</th>'
       . '<th class="pd-num">Total</th>'
       . '<th class="pd-num">Paid</th>'
       . '<th class="pd-num">Outstanding</th>'
       . '<th>Status</th>'
       . '<th></th>'
       . '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $qid = (int) $r['quoteid'];
        $owed = (float) $r['amount_due'] - (float) $r['amount_received'];
        // Per row, not per section: FIT and Groups share one list now, and the legacy
        // dashboard needs the matching quotetype or the deep link opens the wrong view.
        $crm_quotetype = ($r['quote_type'] === 'groups') ? 'group' : 'fit-dashboard';
        $crm_url = '/quote.php?opt=summary&sales=true&quotetype=' . urlencode($crm_quotetype) . '&quoteid=' . $qid;

        $tr_class = 'pd-row';
        if ($r['escalate_flag']) $tr_class .= ' pd-row--escalate';
        if ($r['pending'])       $tr_class .= ' pd-row--pending';

        echo '<tr class="' . $tr_class . '" data-quoteid="' . $qid . '">';

        $org_cell = htmlspecialchars($r['organization_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
        echo '<td class="pd-org">' . $org_cell . '</td>';

        echo '<td><a href="' . $crm_url . '" target="_blank">'
           . htmlspecialchars($r['quote_no'] ?? '', ENT_QUOTES, 'UTF-8') . '</a></td>';

        // Display only: this queue is not split or owned by region. On an unrouted row
        // the raw CRM value is shown instead, since that is the thing somebody has to fix.
        if ($r['queue_region'] !== null) {
            echo '<td>' . htmlspecialchars($r['queue_region'], ENT_QUOTES, 'UTF-8') . '</td>';
        } else {
            $raw_region = trim((string) ($r['assigned_to_region'] ?? ''));
            echo '<td><span class="pd-unknown">'
               . htmlspecialchars($raw_region !== '' ? $raw_region : 'no region set', ENT_QUOTES, 'UTF-8')
               . '</span></td>';
        }

        echo '<td>' . htmlspecialchars($r['quotestage'] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';

        // assigned_to_sales_agent, which on an Accepted quote is sales attribution rather
        // than a work queue. Nobody owns rows on this page, so the header deliberately
        // names the field and claims nothing about who did what.
        //
        // Specifically NOT the user_name on the quote's first 'Change Stage to Accepted'
        // event, which is the other candidate and is a worse answer to "who do I ask about
        // this booking". Measured over the live cohort, the two disagree on 13 of 25 rows,
        // and on Groups & MICE the confirmation step is routinely executed by management,
        // so that column would read the same manager's login on nearly every Groups row.
        // Awaiting Information does read the stage-track user, but only for rows with no
        // sales agent at all, where a weak signal beats none.
        $agent = trim((string) ($r['owner'] ?? ''));
        echo '<td>' . htmlspecialchars($agent !== '' ? $agent : '—', ENT_QUOTES, 'UTF-8') . '</td>';

        $mobile = trim((string) ($r['contact_mobile'] ?? ''));
        echo '<td>' . htmlspecialchars($mobile !== '' ? $mobile : '—', ENT_QUOTES, 'UTF-8') . '</td>';

        echo '<td>' . ($r['created_date'] ? date('d M Y', strtotime($r['created_date'])) : '—') . '</td>';

        echo '<td>' . ($r['trip_date'] ? date('d M Y', strtotime($r['trip_date'])) : '—') . '</td>';

        // Deadline + how far past it we are. Overdue is the normal state here, so it
        // is styled as emphasis rather than alarm; the escalate badge carries the alarm.
        $dl_class = $r['is_overdue'] ? 'pd-deadline pd-deadline--overdue' : 'pd-deadline';
        $dl_txt   = $r['payment_deadline'] ? date('d M Y', strtotime($r['payment_deadline'])) : '—';
        $days_over = $r['is_overdue'] && $r['payment_deadline']
            ? (int) floor((strtotime(date('Y-m-d')) - strtotime($r['payment_deadline'])) / 86400)
            : 0;
        echo '<td class="' . $dl_class . '">' . $dl_txt
           . ($days_over > 0 ? '<br><span class="pd-days">' . $days_over . 'd overdue</span>' : '')
           . '</td>';

        // Split into Total/Paid/Outstanding so a caller can see whether the client has
        // paid anything at all, even on a row where Total is unknown (no initial
        // invoice) — Paid comes from a separate payment_history
        // aggregate and is always a real number regardless of amount_known. Outstanding
        // stays unknown ("—") in that case rather than showing a misleading due-minus-
        // received figure computed against an unknown/zero total.
        echo '<td class="pd-num">'
           . ($r['amount_known']
                ? number_format((float) $r['amount_due'], 2)
                : '<span class="pd-unknown">'
                    . ($r['has_initial_row'] ? 'Invoice amount missing' : 'No initial invoice')
                    . '</span>')
           . '</td>';
        echo '<td class="pd-num">' . number_format((float) $r['amount_received'], 2) . '</td>';
        echo '<td class="pd-num">'
           . ($r['amount_known'] ? number_format($owed, 2) : '<span class="pd-unknown">—</span>')
           . '</td>';

        echo '<td class="pd-badges">';
        if (!$r['amount_known']) {
            if ($r['has_initial_row']) {
                echo '<span class="pd-badge pd-badge--unknown" title="An \'initial\' row exists in vtiger_payment_history for this quote, but its total_amount is blank or zero">Initial invoice amount missing</span>';
            } else {
                echo '<span class="pd-badge pd-badge--unknown" title="No \'initial\' row in vtiger_payment_history — the Initial Invoice was never generated for this quote, so there is nothing to compare payments received against">Initial invoice not generated</span>';
            }
        }
        if (!empty($r['payment_deadline_pending'])) {
            echo '<span class="pd-badge pd-badge--pending">Change pending: '
               . date('d M Y', strtotime($r['payment_deadline_pending'])) . '</span>';
        }
        if ($r['pending']) {
            echo '<span class="pd-badge pd-badge--pending-date">Next call: '
               . date('d M Y', strtotime($r['next_call_date'])) . '</span>';
        }
        if ($r['escalate_flag']) {
            echo '<span class="pd-badge pd-badge--escalate">'
               . (int) $r['calls_since_deadline'] . ' calls since deadline</span>';
        }
        echo '</td>';

        echo '<td class="pd-actions">';
        if ($actionable) {
            if ($r['pending'] || $r['called_today']) {
                // Either a scheduled next-call date, or (called_today, no pending date) a
                // no-answer logged today with no valid pre-trip date to schedule — either
                // way, reopen the modal pre-filled so correcting it UPDATEs the existing
                // call in place (ajax_log_payment_outcome.php's $followup_id branch)
                // instead of logging a second call. No "Next call" badge shows for the
                // called_today-only case (next_call_date is null), which is the only
                // visual difference from the pending case.
                $outcome_labels = [
                    'payment_no_answer' => 'No answer',
                    'payment_next_call' => 'Next call',
                ];
                $label     = $outcome_labels[$r['last_outcome']] ?? 'Logged';
                $notes_js  = htmlspecialchars($r['last_notes'] ?? '', ENT_QUOTES, 'UTF-8');
                $outc_attr = htmlspecialchars($r['last_outcome'] ?? '', ENT_QUOTES, 'UTF-8');
                $chan_attr = htmlspecialchars($r['last_channel'] ?? '', ENT_QUOTES, 'UTF-8');
                $date_attr = htmlspecialchars(substr((string) $r['next_call_date'], 0, 10), ENT_QUOTES, 'UTF-8');
                $trip_attr = htmlspecialchars(substr((string) $r['trip_date'], 0, 10), ENT_QUOTES, 'UTF-8');
                echo '<button type="button" class="pd-log-btn pd-log-btn--pending" data-quoteid="' . $qid . '" '
                   . 'data-quoteno="' . htmlspecialchars($r['quote_no'] ?? '', ENT_QUOTES, 'UTF-8') . '" '
                   . 'data-followup-id="' . (int) $r['last_followup_id'] . '" '
                   . 'data-notes="' . $notes_js . '" data-outcome="' . $outc_attr . '" '
                   . 'data-date="' . $date_attr . '" data-channel="' . $chan_attr . '" '
                   . 'data-tripdate="' . $trip_attr . '">'
                   . '&#10003; ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                   . ' <span style="font-size:0.7rem;text-decoration:underline">edit</span></button> ';
            } else {
                $trip_attr = htmlspecialchars(substr((string) $r['trip_date'], 0, 10), ENT_QUOTES, 'UTF-8');
                echo '<button type="button" class="pd-log-btn" data-quoteid="' . $qid . '" '
                   . 'data-quoteno="' . htmlspecialchars($r['quote_no'] ?? '', ENT_QUOTES, 'UTF-8') . '" '
                   . 'data-tripdate="' . $trip_attr . '">Log</button> ';
            }
        }
        echo '<button type="button" class="pd-hist-btn" data-quoteid="' . $qid . '">History</button>';
        echo '</td>';
        echo '</tr>';

        // Collapsed history panel, filled on first click by payment-deadline.js.
        echo '<tr class="pd-hist-row" id="pd-hist-' . $qid . '" style="display:none">'
           . '<td colspan="14" class="pd-hist-cell"></td></tr>';
    }

    echo '</tbody></table>';
}
?>
<?php if (empty($embedded_in_dashboard)): ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>TDU Payment Deadline Queue</title>
</head>
<body>
<?php endif; ?>
<link rel="stylesheet" href="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/css/payment-deadline.css'), ENT_QUOTES, 'UTF-8') ?>">
<!-- Same vendored Flatpickr already used by the Follow-up queue (queue_view.php) —
     reused as-is, no new dependency. -->
<link rel="stylesheet" href="<?= $asset_base ?>/assets/vendor/flatpickr/themes/material_blue.css">
<link rel="stylesheet" href="<?= $asset_base ?>/assets/vendor/flatpickr/plugins/confirmDate/confirmDate.css">
<div class="tdu-queue-wrap tdu-payment-wrap">

<div class="pd-header">
    <h1>Payment Deadline &mdash; <?= htmlspecialchars($caller_label, ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($has_followup_queue): ?>
    <?php
    // Back to the Follow-up queue: same page, no ?view= param. Keeps ?opt= when embedded
    // in the dashboard and ?user= when an admin is impersonating, so the round trip
    // doesn't silently drop either. Hidden for a post-sale holder with no Follow-up queue
    // of their own, where it would land on the router's post-sale default and loop back
    // to this same page.
    $back_params = [];
    if (!empty($embedded_in_dashboard)) $back_params['opt'] = 'sales-queue';
    if ($viewer_is_admin)               $back_params['user'] = $session_user;
    $back_href = '?' . http_build_query($back_params);
    ?>
    <a class="pd-backlink" href="<?= htmlspecialchars($back_href, ENT_QUOTES, 'UTF-8') ?>">Back to Follow-up queue</a>
    <?php endif; ?>
    <?php
    // Cross-link to Awaiting Information — same post-sale pair and access shape, a
    // different job. Matters most for a bare post-sale holder (Dhiraj), who has no
    // Follow-up page and so no other way to reach it than from here.
    $ai_params = ['view' => 'awaiting_info'];
    if (!empty($embedded_in_dashboard)) $ai_params['opt']  = 'sales-queue';
    if ($viewer_is_admin)               $ai_params['user'] = $session_user;
    ?>
    <a class="pd-backlink" href="?<?= htmlspecialchars(http_build_query($ai_params), ENT_QUOTES, 'UTF-8') ?>">Awaiting information</a>
</div>

<p class="pd-subtitle">
    <?= date('l, d F Y', strtotime($today)) ?>
    &nbsp;|&nbsp; <?= $total_rows ?> to chase
    <?php if ($total_unrouted): ?>
    &nbsp;|&nbsp; <span class="pd-pool-count"><?= $total_unrouted ?> unrouted</span>
    <?php endif; ?>
</p>

<p class="pd-explainer">
    Accepted quotes whose payment deadline is within <?= (int) PAYMENT_DEADLINE_WINDOW_DAYS ?> days or has already
    passed, and whose trip has not happened yet. A quote leaves this list once its CRM stage moves on —
    payment status is shown for information only and no longer decides whether a quote is on this page.
    One shared list across every region, FIT and Groups &amp; MICE together, grouped by urgency:
    Escalate, Overdue, Due soon, then Paid (waiting on stage).
    <?php if ($is_readonly): ?>
    <br><strong>You have view-only access to this queue.</strong> History is open on every row;
    logging a call is left to the people chasing these payments.
    <?php endif; ?>
</p>

<?php
// Unrouted first: it is a data problem someone has to fix in the CRM, and burying it
// under the working list is how it stays unfixed. Collapsed by default all the same,
// since nothing on this page can act on it.
if ($unrouted):
?>
<div class="pd-section pd-pool collapsed">
    <h4 class="pd-section-toggle"><span class="pd-caret"></span>
        <span class="pd-section-title">Unrouted</span>
        <span style="font-weight:normal;font-size:0.85rem">(<?= $total_unrouted ?> quotes)</span>
    </h4>
    <div class="pd-section-body">
        <p class="pd-hint">
            These quotes have no region the queue can resolve, so they are shown for visibility only and
            carry no Log button. Set the region on the quote in the CRM and it joins the list above.
        </p>
        <?php render_payment_table($unrouted, false); ?>
    </div>
</div>
<?php endif; ?>

<?php if ($rows): ?>
    <?php render_bucket_sections($rows, !$is_readonly); ?>
<?php else: ?>
    <p class="pd-empty">Nothing to chase. Every accepted quote is either paid or has already travelled.</p>
<?php endif; ?>

<dialog id="pd-outcome-modal">
    <form id="pd-outcome-form">
        <h3>Log Outcome &mdash; <span id="pd-modal-quote-no"></span></h3>
        <input type="hidden" name="quoteid" id="pd-modal-quoteid">
        <input type="hidden" name="followup_id" id="pd-modal-followup-id" value="">

        <div class="form-row">
            <label for="pd-modal-outcome">Outcome</label>
            <select name="outcome" id="pd-modal-outcome" required>
                <option value="">— select —</option>
                <option value="payment_no_answer">No answer</option>
                <option value="payment_next_call">Next call</option>
            </select>
        </div>

        <p id="pd-modal-noanswer-hint" class="pd-hint" style="display:none;margin:-4px 0 10px 110px">
            This will automatically come back up tomorrow (unless the trip is only a day away).
        </p>

        <div class="form-row" id="pd-modal-date-row" style="display:none">
            <label for="pd-modal-next-call-date">Call back on</label>
            <!-- Same Flatpickr-with-time widget as the Follow-up queue's #modal-next-date
                 (queue.js) — date + time in one field, time defaults to "now" and stays
                 editable. Time isn't read by any business logic yet
                 (build_payment_deadline_queue() only reads the date-only snooze_until),
                 stored purely in the legacy echo's next_follow_up_date for possible
                 future use. -->
            <input type="text" name="next_call_date" id="pd-modal-next-call-date" autocomplete="off">
        </div>

        <div class="form-row">
            <label for="pd-modal-channel">Channel</label>
            <select name="channel" id="pd-modal-channel">
                <option value="phone">Phone</option>
                <option value="whatsapp">WhatsApp</option>
                <option value="email">Email</option>
                <option value="other">Other</option>
            </select>
        </div>

        <div class="form-row" style="align-items:flex-start">
            <label for="pd-modal-notes" style="padding-top:4px">Notes</label>
            <textarea name="notes" id="pd-modal-notes" rows="3" required
                      style="flex:1;font-size:0.875rem;padding:4px 6px;resize:vertical"
                      placeholder="What was discussed…"></textarea>
        </div>

        <div class="dialog-actions">
            <button type="button" id="pd-modal-cancel">Cancel</button>
            &nbsp;
            <button type="submit">Save outcome</button>
        </div>
    </form>
</dialog>

</div>
<script>
var CSRF_TOKEN      = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
var AJAX_BASE       = <?= json_encode(str_replace($_SERVER['DOCUMENT_ROOT'], '', dirname(__DIR__))) ?>;
var PD_MIN_CALL_DATE = <?= json_encode(date('Y-m-d', strtotime('+1 day'))) ?>;
</script>
<script src="<?= $asset_base ?>/assets/vendor/flatpickr/flatpickr.min.js"></script>
<script src="<?= $asset_base ?>/assets/vendor/flatpickr/plugins/confirmDate/confirmDate.js"></script>
<script src="<?= htmlspecialchars($asset_base . '/' . asset_v('assets/js/payment-deadline.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php if (empty($embedded_in_dashboard)): ?>
</body>
</html>
<?php endif; ?>
