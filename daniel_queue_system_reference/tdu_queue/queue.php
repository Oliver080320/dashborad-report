<?php
// Router — detects the current user and loads the correct queue config.
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/config.php';

$today = date('Y-m-d');
// $today = '2026-06-16'; // uncomment to test a specific date

// Who is actually logged in, and are they an admin? (vtiger_users.title === 'admin')
$me              = tdu_canonical_user($_SESSION['user_name'] ?? '');
$viewer_is_admin = (($_SESSION['title'] ?? '') === 'admin');

// Access rule: a regular caller only ever sees their OWN queue. Only admins may view
// another caller's queue or the combined manager view via ?user= — for everyone else
// the ?user= parameter is ignored and they are pinned to their own session user.
$session_user = $me;
if ($viewer_is_admin) {
    if (!empty($_GET['user'])) {
        $_SESSION['tdu_admin_view'] = tdu_canonical_user($_GET['user']);
    }
    $session_user = $_SESSION['tdu_admin_view'] ?? 'manager';
}

// $is_readonly gates the combined view's write actions (Log/priority).
// Defaults to read-only (fail closed). Every controller below asserts its own value.
$is_readonly = true;

// Secondary view switch. ?view=payment_deadline serves the payment-chasing queue,
// ?view=awaiting_info the blocked-bookings queue and ?view=leads the Leads queue, each
// instead of Follow-up; anything else falls through to Follow-up.
$view = $_GET['view'] ?? '';

// A region owner or supervisor, including an admin impersonating one.
$is_region_user = !empty(tdu_regions_for_user($session_user)) || tdu_is_supervisor($session_user);

if ($view === 'payment_deadline'
    && (tdu_is_post_sale($session_user) || tdu_is_supervisor($session_user) || $viewer_is_admin)) {
    // Post-sale pair, supervisors and admins, not "any configured caller": chasing a
    // payment is a named role now, not something every caller does for their own book.
    // Supervisors get in read-only, which payment_deadline.php resolves itself. A region
    // owner hitting this URL deliberately falls through to their own Follow-up page below
    // rather than getting a refusal, since nothing links them here in the first place.
    require __DIR__ . '/queues/payment_deadline.php';
} elseif ($view === 'awaiting_info'
    && (tdu_is_post_sale($session_user) || tdu_is_supervisor($session_user) || $viewer_is_admin)) {
    // Same access shape as Payment Deadline, own page rather than a section: a bare
    // post-sale holder (Dhiraj) has no Follow-up page to embed this in, which is the gap
    // this branch closes. A region owner or legacy caller hitting this URL falls through
    // to their own Follow-up page below, same as the payment_deadline branch above.
    require __DIR__ . '/queues/awaiting_info.php';
} elseif ($view === 'leads' && ($is_region_user || $viewer_is_admin)) {
    // Leads. A region owner or supervisor, an admin impersonating one, and a bare admin
    // (who gets the merged all-region view) all land on the same controller, which
    // resolves scope itself from the session user.
    // region_leads.php asserts $is_readonly = false itself, so it is left fail-closed here.
    require __DIR__ . '/queues/region_leads.php';
} elseif ($is_region_user) {
    // Follow-up for a region owner or supervisor, including an admin impersonating one.
    // region_queue.php asserts $is_readonly = false itself (defence in depth); left
    // fail-closed here too, matching the convention every other branch follows.
    require __DIR__ . '/queues/region_queue.php';
} elseif ($viewer_is_admin) {
    // Admin with nobody impersonated: the combined view across every region.
    // all_callers.php asserts $is_readonly = false itself (defence in depth).
    require __DIR__ . '/queues/all_callers.php';
} elseif (tdu_is_post_sale($session_user)) {
    // Post-sale holder (hemant, Dhiraj): Payment Deadline is their home page, since they
    // own no region and so have no Follow-up queue to reach it from. Stays below the
    // region and admin branches, which is what keeps a supervisor or admin who also holds
    // post-sale access landing on their own queue rather than here.
    require __DIR__ . '/queues/payment_deadline.php';
} else {
    // Logged in as a non-admin who is not a configured caller (e.g. a sales user with no
    // queue). Never fall through to the manager view — show a neutral message instead.
    // One case gets a specific message rather than the neutral one: someone whose only
    // region is claimed by a second person in the CRM's auto-assign rules owns nothing
    // until that is resolved, and "no queue assigned" would read as their account being
    // wrong rather than as a rule needing fixing.
    $conflicts = tdu_region_conflicts_for_user($session_user);
    $emb = !empty($embedded_in_dashboard);
    if (!$emb) echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>TDU Queue</title></head><body>';
    echo '<div style="max-width:680px;margin:48px auto;padding:24px;font-family:Arial,sans-serif;text-align:center;color:#555;">';
    if ($conflicts) {
        echo '<h2 style="color:#b91c1c;margin-bottom:8px;">Region ownership conflict</h2>'
           . '<p>' . htmlspecialchars(tdu_region_conflict_message($conflicts)) . '</p>';
    } else {
        echo '<h2 style="color:#334155;margin-bottom:8px;">No queue assigned</h2>'
           . '<p>Your account does not have a follow-up queue configured. '
           . 'If you believe this is a mistake, please contact the administrator.</p>';
    }
    echo '</div>';
    if (!$emb) echo '</body></html>';
}
