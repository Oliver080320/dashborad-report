<?php
// -.. .- -. .. . .-.. / .-- .- ... / .... . .-. .

// Maintenance mode, checked first, before the DB connection, so it still works even if
// the DB itself is unreachable. Flip to false and redeploy to bring the system back.
define('MAINTENANCE_MODE', false);

if (MAINTENANCE_MODE) {
    $_is_json = false;
    foreach (headers_list() as $_h) {
        if (stripos($_h, 'Content-Type:') === 0 && stripos($_h, 'application/json') !== false) {
            $_is_json = true;
            break;
        }
    }
    http_response_code(503);
    if ($_is_json) {
        echo json_encode(['success' => false, 'message' => 'System under maintenance. Please use the old system for now.']);
        exit;
    }
    // $embedded_in_dashboard is set by the CRM's own quote.php before it includes queue.php,
    // and this file sits inside that same require chain, so it is already visible here. A
    // full <!DOCTYPE html><html><body> nests a second html document inside the dashboard's
    // own one on the embedded path, which is invalid and visibly broke its header, so this
    // uses the same div-vs-full-page check queue.php's own "No queue assigned" fallback uses.
    $_emb = !empty($embedded_in_dashboard);
    $_content_style = 'display:flex;flex-direction:column;align-items:center;justify-content:center;'
                     . 'min-height:' . ($_emb ? '80vh' : '100vh') . ';font-family:Arial,sans-serif;text-align:center;color:#333;';
    $_content = '<h1 style="margin-bottom:12px;">System Under Maintenance</h1>'
              . '<p style="font-size:1.1rem;">Please use the old system for now.</p>';
    if ($_emb) {
        echo '<div style="' . $_content_style . '">' . $_content . '</div>';
    } else {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>System Under Maintenance</title></head>'
           . '<body style="margin:0;' . $_content_style . '">' . $_content . '</body></html>';
    }
    exit;
}

// Per-feature maintenance switches, independent from MAINTENANCE_MODE above (which takes
// down the whole queue). Each one only gates the feature named — checked by that feature's
// own files via tdu_maintenance_guard(), never read anywhere else.
// NOT the full list: user_manual.php (repo root) defines its own USER_MANUAL_MAINTENANCE_MODE
// locally rather than here, since that page has no other dependency on this file (no DB
// connection). Search "_MAINTENANCE_MODE" across the repo for every switch that exists.
define('MONITORING_MAINTENANCE_MODE', false); // flipped for local dev run against the CSV-seeded DB

// Shows a "being updated" message and exits, if $active; a no-op otherwise. $format 'json'
// for an AJAX endpoint, 'html' for a full page.
function tdu_maintenance_guard(bool $active, string $format = 'html', string $message = 'This section is being updated. Please check back soon.'): void
{
    if (!$active) return;
    http_response_code(503);
    if ($format === 'json') {
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Under Maintenance</title></head>'
       . '<body style="margin:0;display:flex;flex-direction:column;align-items:center;justify-content:center;'
       . 'min-height:100vh;font-family:Arial,sans-serif;text-align:center;color:#333;">'
       . '<div style="white-space:pre;font-family:monospace;font-size:0.9rem;line-height:1.2;margin:0 0 12px;color:#333;">'
       . base64_decode('ICAgIC4tLS0tLS0tLS0tLgogICAgfCAgIH5PTn4gICB8CiAgICB8ICAgX19fXyAgIHwKICAgIHwgIHwuLS0ufCAgfAogICAgfCAgfHwgIHx8ICB8CiAgICB8ICB8fF9ffHwgIHwKICAgIHwgIHx8XCBcfCAgfAogICAgfCAgfFwgXF9cICB8CiAgICB8ICB8X1xbX10gIHwKICAgIHwgICAgICAgICAgfAogICAgfCAgfk9GRn4gICB8CiAgICAnLS0tLS0tLS0tLSc=')
       . '</div>'
       . '<h1 style="margin-bottom:12px;">Under Maintenance</h1>'
       . '<p style="font-size:1.1rem;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
       . '</body></html>';
    exit;
}

require_once __DIR__ . '/../../dbconn.php';

// Fatal error during config load (caller/region lookup failed). If the caller
// already sent a JSON content-type header (every ajax_*.php does this before
// requiring config.php), respond with JSON so the frontend's res.json() doesn't
// choke on plain text. Otherwise fall back to a plain die() for page loads.
function tdu_config_fatal(string $message)
{
    $is_json = false;
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'application/json') !== false) {
            $is_json = true;
            break;
        }
    }
    if ($is_json) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    die($message);
}

// ---------------------------------------------------------------------------
// Locked constants — confirmed with the boss. Do not change without sign-off.
// ---------------------------------------------------------------------------
define('DAILY_CAPACITY',                      70);
define('INDIA_FOLLOWUP_PCT',                  0.60);
define('INDIA_ENGAGEMENT_PCT',                0.10);
define('INDIA_OUTREACH_PCT',                  0.30);
define('FOLLOWUP_CREATED_DAYS',               60);
define('FOLLOWUP_TRAVEL_DATE_WINDOW_DAYS',    90);
define('FOLLOWUP_CREATED_DATE_REMAINING_PCT', 0.60);
define('STANDARD_FOLLOWUP_PCT',               0.50);
define('ENGAGEMENT_MIN_MONTHS',               3);
define('ENGAGEMENT_MAX_MONTHS',               12);
define('OUTREACH_LONG_DORMANT_MONTHS',        12);

// ---------------------------------------------------------------------------
// Build-time / transitional parameters — not locked, tune as needed.
// ---------------------------------------------------------------------------

// Days behind (carry-over backlog age) at which the "Days behind" badge turns red.
define('CARRYOVER_RED_DAYS', 3);

// Boundary between a large and a small group (total pax: adults + children + infants).
// Reporting only, used by the monitoring dashboard to split the live Groups book. It
// used to decide which Groups caller owned a quote; ownership is count-balanced now,
// so nothing about the daily queues depends on this value.
define('GROUPS_PAX_THRESHOLD', 40);

// Max scheduled follow-up calls one caller can have on the same future date.
// Enforced in ajax_log_outcome.php before writing a new schedule_follow_up row.
define('MAX_SCHEDULED_PER_DAY_PER_CALLER', 10);

// Ratio of MAX_SCHEDULED_PER_DAY_PER_CALLER at which the calendar turns yellow
// instead of green (red starts at the cap itself).
define('SCHEDULE_CAP_WARN_RATIO', 0.6);

// Turtle Down Under's own internal organisationid (tdu_organisation) — confirmed 2026-07-21.
// Internal/test quotes raised against the company's own account, not a real client; excluded
// from any "Accepted this month" accounting (monitoring chart, CSV export).
define('TDU_INTERNAL_ORG_ID', 91445);

// Target host for ajax_log_outcome.php's internal call to quote.php (CURLOPT_RESOLVE).
// Defaults to loopback; overridable via env var for a future multi-server setup.
define('INTERNAL_APP_HOST', getenv('INTERNAL_APP_HOST') ?: '127.0.0.1');

// Payment-deadline queue: how many days before cf_1182 (the payment cancellation
// deadline) a quote first surfaces for chasing. Confirmed with the boss 2026-07-27.
define('PAYMENT_DEADLINE_WINDOW_DAYS', 3);

// Payment-deadline queue: number of calls logged AFTER the deadline has passed at
// which the row shows an "consider escalating" badge to its owner. The boss asked for
// "2 or 3 more calls"; 3 for now, may drop to 2.
define('PAYMENT_DEADLINE_ATTEMPTS_BEFORE_FLAG', 3);

// Payment-deadline queue: a quote also escalates once fewer than this many days remain
// before the trip, regardless of the deadline/call-count condition above (there's no
// time left to keep waiting on more attempts once the trip is this close). Deliberately
// the same 15-day figure compute_accepted_blocked() already uses for its own
// trip-proximity/payment-shortfall check.
define('PAYMENT_DEADLINE_ESCALATE_TRIP_DAYS', 15);

// Per-caller daily cap on scheduled future Lead calls. Confirmed with the boss: no cap at all.
// 0 is the sentinel every reader treats as "no cap"; a real cap could never legitimately be 0,
// since that would mean nobody could ever schedule a callback.
define('LEAD_MAX_SCHEDULED_PER_DAY_PER_CALLER', 0);

// The CRM quotestage that defines the Leads cohort. A Lead is an ordinary vtiger_quotes row
// sitting one step before 'Created', added so a client asking only for a rough price is not
// logged as a lost quote.
define('LEAD_STAGE_NAME', 'Lead');

// How far ahead a callback can be scheduled, per queue. Bounds the date picker, the calendar's
// per-day counts and the "next available date" search; a quote's own trip date can cut it
// shorter. Leads get a week: an unqualified enquiry goes cold long before three weeks.
define('SCHEDULE_WINDOW_DAYS', 21);
define('LEAD_SCHEDULE_WINDOW_DAYS', 7);

// Awaiting Info queue. Days blocked at which the row's age badge turns red. Tunable:
// it drives colour only, never membership or sort order.
define('AWAITING_INFO_RED_DAYS', 30);

// India regions — loaded from vtiger_groups WHERE country = 'India'. Loaded this early
// because the queue regions below are these regions: it is the CRM's own list and the
// definition of what is in scope for the region model.
$_regions_res = $conn->query("SELECT groupname FROM vtiger_groups WHERE country = 'India' ORDER BY groupname");
if (!$_regions_res) {
    error_log('[TDU Queue] Failed to load India regions from vtiger_groups: ' . $conn->error);
    tdu_config_fatal('Service temporarily unavailable. Please contact the administrator.');
}
$_india_regions = [];
while ($_row = $_regions_res->fetch_assoc()) $_india_regions[] = $_row['groupname'];
$_regions_res->free();
unset($_regions_res, $_row);
define('INDIA_REGIONS', $_india_regions);
unset($_india_regions);

if (empty(INDIA_REGIONS)) {
    error_log('[TDU Queue] No India regions in vtiger_groups: every queue would be empty.');
    tdu_config_fatal('Service temporarily unavailable. Please contact the administrator.');
}

// Regional model: one queue region per CRM region, with its daily follow-up capacity.
// Derived rather than listed, and there is no translation table any more: a tdu_region_map
// used to fold the three Mumbai sub-regions into a single 'West' queue region, which meant
// four auto-assign rules had to keep naming the same person or West (179 live quotes) lost
// its owner over a rule covering nine. Dropped 2026-09-02. The queue's regions are now
// exactly the CRM's, so nothing can disagree. Capacity stays uniform; a per-region
// exception would be an override map here, and no one has ever needed one.
define('QUEUE_REGIONS', array_fill_keys(INDIA_REGIONS, ['daily_capacity' => DAILY_CAPACITY]));

// The caller configuration that used to sit here (INDIA_CALLERS / GROUPS_CALLERS, loaded
// from tdu_callers) is gone with the queues it served. Ownership now follows the quote's
// region, resolved through REGION_OWNERS below, so there is no caller roster and no
// per-queue_type list to keep populated. Both empty-list guards went with it: they turned
// an empty tdu_callers into a system-wide 503, which made deactivating the last caller an
// outage rather than an ordinary roster change.

// ---------------------------------------------------------------------------
// Internal vs external ownership column.
// vtiger_quotes_info carries two independent ownership fields and the CRM's own edit
// form decides who may go in each: the Internal Sales Agent dropdown lists
// title IN ('sales','admin'), the External Sales Agent one lists title IN ('external','admin').
// Anyone else belongs in neither, and writing them to the internal column is the bug
// this exists to stop, so they are treated as external. 'admin' sits in both lists and
// stays internal, which is the behaviour that predates this split.
// ---------------------------------------------------------------------------
function tdu_title_is_external(?string $title): bool
{
    return !in_array(strtolower(trim((string) $title)), ['sales', 'admin'], true);
}

// The vtiger_quotes_info column a person's name may be written to and read back from.
function tdu_owner_column(bool $is_external): string
{
    return $is_external ? 'assigned_to_external_sales_agent' : 'assigned_to_sales_agent';
}

// ---------------------------------------------------------------------------
// Queue access scopes — loaded from tdu_queue_access_view. Renamed from
// READONLY_VIEWERS: that name stopped being accurate once 'supervisor' and
// 'post_sale' rows joined the original 'india_fit_viewer' scope in the same
// table, and a supervisor is not read-only. Kept separate from REGION_OWNERS so a
// scope holder is never, by itself, a candidate quote owner.
// Unlike those, a failure here is NOT fatal for everyone — it only affects
// the roles that read it, degrading to "No queue assigned" instead of a
// system-wide 503.
// ---------------------------------------------------------------------------
// Joined to vtiger_users for a full name — needed so a supervisor who owns no region
// (Karthik today) can be named on their own personal claim queue and in the exemption
// list correct_region_ownership() checks against, the same pattern REGION_OWNERS
// already uses. A missing vtiger_users row just leaves that entry out of
// QUEUE_ACCESS_FULLNAMES (LEFT JOIN), it does not drop the scope itself.
// vu.title comes along for the same reason REGION_OWNERS reads it: it decides which of
// the CRM's two ownership columns this person's name may legitimately go in.
$_scopes_res = $conn->query("
    SELECT tqav.user_name, tqav.scope, tqav.can_personal_claim,
           CONCAT(vu.first_name, ' ', vu.last_name) AS full_name,
           vu.title
    FROM tdu_queue_access_view tqav
    LEFT JOIN vtiger_users vu ON vu.user_name = tqav.user_name
    WHERE tqav.active = 1
");
$_access_scopes      = [];
$_access_fullnames   = [];
$_access_can_claim   = [];
$_access_is_external = [];
if (!$_scopes_res) {
    error_log('[TDU Queue] Failed to load queue access scopes from tdu_queue_access_view: ' . $conn->error);
} else {
    while ($_row = $_scopes_res->fetch_assoc()) {
        $_access_scopes[$_row['user_name']] = $_row['scope'];
        if (!empty($_row['full_name'])) $_access_fullnames[$_row['user_name']] = $_row['full_name'];
        $_access_can_claim[$_row['user_name']]   = !empty($_row['can_personal_claim']);
        $_access_is_external[$_row['user_name']] = tdu_title_is_external($_row['title'] ?? null);
    }
}
if ($_scopes_res) $_scopes_res->free();
unset($_scopes_res, $_row);

define('QUEUE_ACCESS_SCOPES', $_access_scopes);
define('QUEUE_ACCESS_FULLNAMES', $_access_fullnames);
// can_personal_claim (migration 010) grants the personal-claim capability explicitly,
// independent of scope or region ownership, so it survives a later change to either.
define('QUEUE_ACCESS_CAN_CLAIM', $_access_can_claim);
// user_name => true when that person's quotes live in the external ownership column.
// A missing vtiger_users row reads as external, which is the safe way round: it stops
// an unresolvable name being written into the internal column.
define('QUEUE_ACCESS_IS_EXTERNAL', $_access_is_external);
unset($_access_scopes, $_access_fullnames, $_access_can_claim, $_access_is_external);

// ---------------------------------------------------------------------------
// Region model — region ownership, loaded from tdu_auto_assign_rules.
// ---------------------------------------------------------------------------
// REGION_OWNERS: queue_region => ['user_name' => ..., 'full_name' => ...]. A region
// has exactly one owner, so this is a single value per region, never an array of owners.
// Empty is NOT fatal: an all-unowned system must still be able to build every region's
// list for the admin/supervisor view, since an empty list is indistinguishable from
// "nothing to do". A region with no owner simply has no key here, and every per-quote
// owner check fails closed on its own because it resolves the session user's regions and
// an unowned region matches nobody.
//
// The roster is the CRM's own tdu_auto_assign_rules, the same table quotes.php reads when
// it stamps ownership on a newly created quote, so the two cannot drift apart and then
// fight each other every morning. Three things that table does not guarantee, and that
// this loader therefore has to decide rather than trust:
//   - Its region column is free text, so anything that is not one of the CRM's own India
//     regions is out of scope and skipped (Philippines and Singapore rules live here too).
//   - Its username column holds a full name, not a login, so the login comes from
//     vtiger_users. A name that resolves to no user is skipped and logged.
//   - Nothing stops two rows naming two different people for the same region. That
//     region is left unowned and reported, rather than picking one: there is no
//     assignment history anywhere in this CRM, so writing the wrong owner across a whole
//     region destroys the only record of who held those quotes.
// Seasonal periods (start_m/start_d..end_m/end_d) are ignored: the CRM applies them per
// quote against its travel date, which a per-region roster cannot express. Several rows
// naming the same person are simply that one owner; different people are the conflict
// above, whatever their periods say.
$_owner_rules_res = $conn->query("
    SELECT r.region AS crm_region, r.username AS full_name, r.category, vu.user_name
    FROM tdu_auto_assign_rules r
    LEFT JOIN vtiger_users vu ON CONCAT(vu.first_name, ' ', vu.last_name) = r.username
    WHERE r.assign_type = 'region' AND r.category IN ('sales', 'external')
    ORDER BY r.auto_id
");
$_region_owners   = [];
$_owner_regions   = [];
$_owner_conflicts = [];
if (!$_owner_rules_res) {
    error_log('[TDU Queue] Failed to load region ownership from tdu_auto_assign_rules: ' . $conn->error);
} else {
    // queue_region => user_name => person. Still keyed this way, and still tolerant of a
    // region appearing on more than one rule (several seasonal periods for one person),
    // even though one region can no longer collapse several CRM regions into it.
    $_candidates = [];
    while ($_row = $_owner_rules_res->fetch_assoc()) {
        $_qregion = isset(QUEUE_REGIONS[$_row['crm_region']]) ? $_row['crm_region'] : null;
        if ($_qregion === null) continue;
        if (empty($_row['user_name'])) {
            error_log('[TDU Queue] Auto-assign rule for region "' . $_row['crm_region']
                . '" names "' . $_row['full_name'] . '", who matches no vtiger_users row. Skipped.');
            continue;
        }
        // Oldest rule wins where the same person is named twice for one region under
        // different categories, which is a data error rather than a real choice; the
        // ORDER BY above is what makes "wins" mean something instead of row order.
        if (isset($_candidates[$_qregion][$_row['user_name']])) continue;
        $_candidates[$_qregion][$_row['user_name']] = [
            'full_name' => $_row['full_name'],
            // Which ownership column this region's quotes are corrected into. Taken from
            // the rule's own category rather than from vtiger_users.title, because
            // category is what quotes.php uses to pick the column when it assigns a new
            // quote: deriving it any other way risks disagreeing with the write we are
            // trying to stay consistent with.
            'is_external' => strtolower(trim((string) $_row['category'])) === 'external',
        ];
    }
    foreach ($_candidates as $_qregion => $_people) {
        if (count($_people) > 1) {
            $_owner_conflicts[$_qregion] = $_people;
            error_log('[TDU Queue] Region "' . $_qregion . '" is claimed by more than one person in '
                . 'tdu_auto_assign_rules (' . implode(', ', array_column($_people, 'full_name'))
                . '). Left unowned until a single person is named.');
            continue;
        }
        $_uname = array_key_first($_people);
        $_region_owners[$_qregion] = [
            'user_name'   => $_uname,
            'full_name'   => $_people[$_uname]['full_name'],
            'is_external' => $_people[$_uname]['is_external'],
        ];
        $_owner_regions[$_uname][] = $_qregion;
    }
    unset($_candidates, $_people, $_uname);
}
if ($_owner_rules_res) $_owner_rules_res->free();
unset($_owner_rules_res, $_row, $_qregion);

define('REGION_OWNERS', $_region_owners);
define('OWNER_REGIONS', $_owner_regions);
// queue_region => user_name => ['full_name' => ..., 'is_external' => ...] for every region
// left unowned because its rules name more than one person. Read by the queue pages, which
// say so on screen: an owner whose region is in here would otherwise just find their queue
// gone with no explanation.
define('REGION_OWNER_CONFLICTS', $_owner_conflicts);
unset($_region_owners, $_owner_regions, $_owner_conflicts);

// A quote's CRM region as a queue region, or null when it is outside the model (a
// non-India region, or a value that matches no vtiger_groups name: 'West Harbour Mumbai '
// with a trailing space is a real one). The two are the same string since tdu_region_map
// was dropped, so this is now a scope test rather than a translation; it stays a function
// because five call sites ask the question and the answer may narrow again later.
function tdu_queue_region_for(?string $crm_region): ?string
{
    if ($crm_region === null || $crm_region === '') return null;
    return isset(QUEUE_REGIONS[$crm_region]) ? $crm_region : null;
}

// Case-insensitive on purpose: the login now comes from vtiger_users rather than from a
// seed of our own, so its casing is whatever the CRM stores, while tdu_queue_access_view
// carries a separately typed copy for anyone who is both an owner and a scope holder
// (Prajna, North's owner and a supervisor). An exact match would drop that person's
// regions on a casing difference alone.
function tdu_regions_for_user(string $user_name): array
{
    foreach (OWNER_REGIONS as $uname => $regions) {
        if (strcasecmp($uname, $user_name) === 0) return $regions;
    }
    return [];
}

// queue_region => [full_name, ...] for the regions left unowned because their auto-assign
// rules name more than one person.
function tdu_region_conflicts(): array
{
    $out = [];
    foreach (REGION_OWNER_CONFLICTS as $region => $people) {
        $out[$region] = array_values(array_column($people, 'full_name'));
    }
    return $out;
}

// The regions this person is one of the claimants of. Lets the pages tell someone whose
// queue has vanished why, instead of showing them "No queue assigned".
function tdu_region_conflicts_for_user(string $user_name): array
{
    $out = [];
    foreach (REGION_OWNER_CONFLICTS as $region => $people) {
        foreach (array_keys($people) as $uname) {
            if (strcasecmp($uname, $user_name) === 0) {
                $out[] = $region;
                break;
            }
        }
    }
    return $out;
}

// Plain text, not markup: the three queue pages and the router's fallback each wrap it in
// their own, and the wording should only exist once. Pass a region list to speak about
// those only, or nothing for every conflict there is.
function tdu_region_conflict_message(array $regions = []): string
{
    $conflicts = tdu_region_conflicts();
    if ($regions) $conflicts = array_intersect_key($conflicts, array_flip($regions));
    if (!$conflicts) return '';

    $parts = [];
    foreach ($conflicts as $region => $names) {
        $last = array_pop($names);
        $who  = $names ? implode(', ', $names) . ' and ' . $last : $last;
        $parts[] = $region . ' is assigned to ' . $who . ' at the same time.';
    }
    return 'Region ownership conflict. ' . implode(' ', $parts)
        . ' A region shared by two people has no owner, so nobody is working its quotes.'
        . ' Please contact the administrator.';
}

// The same message as a red banner, ready to render. Markup normally belongs in the
// views, but the three queue controllers and queue.php's own "no queue" fallback all need
// this identical block and config.php is the only file all four of them load.
function tdu_region_conflict_banner(array $regions = []): string
{
    $msg = tdu_region_conflict_message($regions);
    if ($msg === '') return '';
    return '<p style="background:#fff5f5;border:1px solid #fca5a5;color:#b91c1c;padding:8px 14px;'
        . 'border-radius:4px;font-size:0.85rem;margin-bottom:12px;">&#9888; '
        . htmlspecialchars($msg) . '</p>';
}

function tdu_region_owner_name(string $region): ?string
{
    return REGION_OWNERS[$region]['full_name'] ?? null;
}

// True when this region's owner is carried in the external ownership column. An unowned
// region has nothing to write either way, so the value is only meaningful alongside a
// non-null tdu_region_owner_name().
function tdu_region_owner_is_external(string $region): bool
{
    return !empty(REGION_OWNERS[$region]['is_external']);
}

// Same question for a person rather than a region, so a personal-claim holder resolves
// the same way a region owner does. Region ownership answers first: it is the roster the
// correction pass actually writes from, and it answers from the auto-assign rule's own
// category, which is what the CRM itself writes by. The fallback covers someone who holds
// a scope but owns no region (Karthik), where title is the only signal there is.
function tdu_person_is_external(string $user_name): bool
{
    foreach (REGION_OWNERS as $info) {
        if (strcasecmp($info['user_name'], $user_name) === 0) return !empty($info['is_external']);
    }
    return !empty(QUEUE_ACCESS_IS_EXTERNAL[$user_name]);
}

// user_name => full_name for every active region owner, deduplicated (Mayur owns two
// regions but appears once), for call sites that need a person-keyed map rather than
// the region-keyed REGION_OWNERS.
function tdu_region_owner_names(): array
{
    $names = [];
    foreach (REGION_OWNERS as $info) {
        $names[$info['user_name']] = $info['full_name'];
    }
    return $names;
}

function tdu_region_capacity(string $region): int
{
    return QUEUE_REGIONS[$region]['daily_capacity'] ?? 0;
}

// Which of $candidate_regions have not had today's build run yet, given the regions that
// actually have snapshot rows. Asked per owner group, never per region, because that is
// how the build happens: build_and_write_regions() always takes a person's whole group,
// from the cron and from the owner's own page alike, so one region of the group having
// rows means the group ran and a region of it with none simply had nothing to surface. A
// region with no owner is its own group of one, matching how the cron groups it.
//
// Testing per region instead reads an empty region as permanently unbuilt: it warns about
// it every day, and on the owner's own page it re-runs the whole group's build (cohort
// query and ownership correction included) on every single page load.
//
// $regions_present should be the union of the built and carry-over keys: 'built' is
// reconstructed from the SP buckets alone, so a region whose whole day sits in carry-over
// would otherwise read as never loaded.
function tdu_pending_regions(array $candidate_regions, array $regions_present): array
{
    $present = array_flip($regions_present);
    $pending = [];
    foreach ($candidate_regions as $region) {
        $owner = REGION_OWNERS[$region]['user_name'] ?? null;
        $group = $owner === null ? [$region] : (OWNER_REGIONS[$owner] ?? [$region]);
        $group_ran = false;
        foreach ($group as $g) {
            if (isset($present[$g])) { $group_ran = true; break; }
        }
        if (!$group_ran) $pending[] = $region;
    }
    return $pending;
}

function tdu_is_supervisor(string $user_name): bool
{
    return (QUEUE_ACCESS_SCOPES[$user_name] ?? null) === 'supervisor';
}

function tdu_is_post_sale(string $user_name): bool
{
    return (QUEUE_ACCESS_SCOPES[$user_name] ?? null) === 'post_sale';
}

// Whether $user_name has a Follow-up queue to go back to from a post-sale page
// (Payment Deadline, Awaiting Information): a bare post-sale holder does not, so their
// "Back to Follow-up queue" link would loop straight back to the page it's on. Shared by
// both post-sale controllers so they cannot drift on who gets the link.
function tdu_has_followup_queue(string $user_name, bool $viewer_is_admin): bool
{
    return $viewer_is_admin
        || !empty(tdu_regions_for_user($user_name))
        || tdu_is_supervisor($user_name);
}

// No tdu_can_transfer() or "Move to region" endpoint: a supervisor moving a quote
// between regions was considered and dropped. Karthik's own need (working a quote
// himself without becoming its region's owner) is tdu_personal_claim_users() below.

// Every user granted can_personal_claim in tdu_queue_access_view, independent of scope
// or region ownership, so a later change to either never silently revokes it (this used
// to be derived from "supervisor who owns no region", which broke the moment that
// supervisor was given a region). user_name => full_name, resolved from
// QUEUE_ACCESS_FULLNAMES (missing only if that user has no matching vtiger_users row).
function tdu_personal_claim_users(): array
{
    $out = [];
    foreach (QUEUE_ACCESS_CAN_CLAIM as $uname => $can_claim) {
        if (!$can_claim) continue;
        if (empty(QUEUE_ACCESS_FULLNAMES[$uname])) continue; // no full name to claim under, skip rather than guess
        $out[$uname] = QUEUE_ACCESS_FULLNAMES[$uname];
    }
    return $out;
}

// Everyone who works a region-model queue, and therefore anyone a person-keyed report or
// roster may legitimately name: every region owner, plus a supervisor who owns no region
// but holds a personal claim queue (Karthik). Region owners alone is not the same list and
// silently drops him. user_name => full_name, deduplicated by key.
function tdu_region_people(): array
{
    return array_merge(tdu_region_owner_names(), tdu_personal_claim_users());
}

// Resolves the four separate questions every region-model controller has to answer about
// the current viewer. Lives here, shared, rather than inline in each controller: this
// logic shipped broken once (see below) and a second copy is how the fix drifts.
//
// The two that are easy to collapse and must not be:
//
// 'sees_all' (which regions to display) is NOT simply "supervisor or admin". An admin
// using "View as" on a specific plain region owner must see that owner's own regions,
// not the merged view, which is what 'impersonating_owner' corrects for. That flag tests
// "not a supervisor" rather than just "owns something", because Prajna is both a
// supervisor and North's owner: a naive "admin is viewing someone who owns regions" test
// would wrongly narrow her supervisor view down to North alone.
//
// 'may_build' (may this page trigger the daily build) is keyed off 'sees_all', never off
// admin status directly. An admin impersonating a plain owner must build that owner's day
// exactly as the owner's own login would, matching the legacy india_callers.php "View as"
// behaviour; keying it off admin status left that view permanently blank for anyone not
// already built today. What must never auto-build is the merged view (a supervisor's own
// login, an admin without impersonation, or an admin impersonating a supervisor), since
// building from there would silently repair a failed cron and destroy the only signal
// anywhere that it failed.
// $prefer_merged is the caller's already-resolved own-view/merged-view toggle choice
// (read from session by the controller, see region_queue.php/region_leads.php); this
// function stays a pure resolver over its arguments, it never touches $_SESSION itself,
// same convention as every other config.php helper.
// $personal_claim_applies opts a controller into the personal-claim queue (false by
// default), so a controller with no personal-claim build path (Leads) never sees
// has_personal_claim = true and can't trigger a build it has no function for.
function tdu_resolve_region_scope(
    string $user_name,
    bool $viewer_is_admin,
    bool $prefer_merged = false,
    bool $personal_claim_applies = false
): array {
    $my_regions          = tdu_regions_for_user($user_name);
    $is_supervisor       = tdu_is_supervisor($user_name);
    $has_personal_claim  = $personal_claim_applies && array_key_exists($user_name, tdu_personal_claim_users());
    $has_own_default     = !empty($my_regions) || $has_personal_claim;
    $impersonating_owner = $viewer_is_admin && !$is_supervisor && !empty($my_regions);

    // Authority: who CAN reach the merged view. Unchanged from before except in name.
    $can_see_all = ($is_supervisor || $viewer_is_admin) && !$impersonating_owner;

    // What they're actually looking at right now: authority, AND (chose merged, OR have
    // nothing of their own to default to). A supervisor who owns a region or holds a
    // personal claim defaults to THAT instead of the merged view (confirmed 2026-08-27);
    // only an explicit prefer_merged flips it, and only when authority allows it at all.
    // A plain owner (not a supervisor, not admin) is unaffected: can_see_all is false for
    // them regardless of prefer_merged, so this collapses to the original owner behaviour.
    $sees_all = $can_see_all && (!$has_own_default || $prefer_merged);

    return [
        'my_regions'         => $my_regions,
        'is_supervisor'      => $is_supervisor,
        'has_personal_claim' => $has_personal_claim,
        'can_see_all'        => $can_see_all,
        'sees_all'           => $sees_all,
        'may_build'          => !$sees_all && $has_own_default,
        'owns_nothing'       => empty($my_regions),
    ];
}

// ---------------------------------------------------------------------------
// Username canonicalisation. The CRM login is case-insensitive (login.php
// matches via the column's _ci collation but stores the RAW typed value in
// $_SESSION['user_name']), so someone who types 'arunp' must still resolve to
// the canonical 'ArunP' key that region ownership and access scopes are keyed
// by, and that gets written downstream. This compares without case and returns
// the canonical key; anyone else (admin/manager) passes through unchanged.
// OWNER_REGIONS is used, not REGION_OWNERS: that one is keyed by region rather
// than by user_name.
// ---------------------------------------------------------------------------
function tdu_canonical_user($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    foreach (array_merge(QUEUE_ACCESS_SCOPES, OWNER_REGIONS) as $uname => $_full) {
        if (strcasecmp($uname, $raw) === 0) return $uname; // canonical casing
    }
    return $raw; // not a region owner or scope holder, leave unchanged
}

// INDIA_REGIONS is loaded near the top of this file, alongside QUEUE_REGIONS, which is
// derived from it.
