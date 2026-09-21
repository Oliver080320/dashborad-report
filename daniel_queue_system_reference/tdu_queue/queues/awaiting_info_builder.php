<?php
// Builder for the Awaiting Info queue: Accepted+ quotes whose bookings are held up
// because the client has not supplied information the booking AI needs.
//
// No slot budget, no daily_runs snapshot, no carry-over. Membership is a live read of
// oskar_pause_reasons, so a quote leaves the moment its reason list empties.

// The five stages the pre-validation cron itself scans. Wider than what carries reasons
// today, on purpose: scoping narrower than the system we read would silently miss rows
// the day its behaviour shifts. The lowercase 'pending'/'completed' are correct, verified
// against vtiger_quotestage.
function awaiting_info_stage_list(): array
{
    return [
        'Accepted', 'PRE QA - pending', 'PRE QA - completed',
        'Payment Received - Release Vouchers', 'Final QA',
    ];
}

// Match on a stable fragment, never the whole sentence: these strings are another team's
// display copy, not a contract. Order here is display order. 'contact' and 'rooming' hold
// every booking on the quote; the rest hold only the products of the day they name.
function awaiting_info_families(): array
{
    return [
        'contact' => [
            'label'    => 'Contact number (traveller)',
            'severity' => 'total',
            'match'    => 'contact number is missing',
            'day'      => false,
        ],
        'rooming' => [
            'label'    => 'Guest names (rooming list)',
            'severity' => 'total',
            'match'    => 'rooming pax list',
            'day'      => false,
        ],
        'flights' => [
            'label'    => 'Flight details',
            'severity' => 'partial',
            'match'    => 'requires flight details',
            'day'      => true,
        ],
        'hotels' => [
            'label'    => 'Hotel they stay',
            'severity' => 'partial',
            'match'    => 'requires the hotel they stay',
            'day'      => true,
        ],
        'dietary' => [
            'label'    => 'Dietary requirements',
            'severity' => 'partial',
            'match'    => 'dietary information is missing',
            'day'      => false,
        ],
    ];
}

// Collapses a sorted day list into readable ranges: [1,2,3,5,6,9] => '1-3, 5-6, 9'.
function compact_days(array $days): string
{
    if (!$days) return '';
    sort($days, SORT_NUMERIC);
    $days   = array_values(array_unique($days));
    $out    = [];
    $start  = $days[0];
    $prev   = $days[0];
    foreach (array_slice($days, 1) as $d) {
        if ($d === $prev + 1) { $prev = $d; continue; }
        $out[] = ($start === $prev) ? (string) $start : "$start-$prev";
        $start = $prev = $d;
    }
    $out[] = ($start === $prev) ? (string) $start : "$start-$prev";
    return implode(', ', $out);
}

// Same run-detection as compact_days(), but prints the itinerary's own dates: the day
// number is the one part a caller cannot use on the phone or in a note. $day_dates maps
// day number => raw 'DD-Mon-YYYY'. Falls back to 'day N' when a run has no date on
// record, which no observed reason string has hit.
function compact_day_dates(array $days, array $day_dates): string
{
    if (!$days) return '';
    sort($days, SORT_NUMERIC);
    $days = array_values(array_unique($days));

    $format_run = function (int $start, int $end) use ($day_dates): string {
        $start_ts = isset($day_dates[$start]) ? strtotime($day_dates[$start]) : false;
        $end_ts   = isset($day_dates[$end]) ? strtotime($day_dates[$end]) : false;

        if ($start_ts === false) return ($start === $end) ? "day $start" : "day $start-$end";
        if ($start === $end || $end_ts === false) return date('j M Y', $start_ts);

        $same_year  = date('Y', $start_ts) === date('Y', $end_ts);
        $same_month = $same_year && date('m', $start_ts) === date('m', $end_ts);

        if ($same_month) return date('j', $start_ts) . '-' . date('j M Y', $end_ts);
        if ($same_year)  return date('j M', $start_ts) . ' - ' . date('j M Y', $end_ts);
        return date('j M Y', $start_ts) . ' - ' . date('j M Y', $end_ts);
    };

    $out   = [];
    $start = $days[0];
    $prev  = $days[0];
    foreach (array_slice($days, 1) as $d) {
        if ($d === $prev + 1) { $prev = $d; continue; }
        $out[] = $format_run($start, $prev);
        $start = $prev = $d;
    }
    $out[] = $format_run($start, $prev);
    return implode(', ', $out);
}

// The source emits one reason per product per day, so a quote can carry 19 strings that
// are really four asks. Anything matching no family is kept verbatim under 'other' rather
// than dropped: an unrecognised string still means work.
function group_pause_reasons(array $reasons): array
{
    $families = awaiting_info_families();
    $buckets  = [];
    $other    = [];

    foreach ($reasons as $reason) {
        if (!is_string($reason)) continue;
        $reason = trim($reason);
        if ($reason === '') continue;

        $lower   = strtolower($reason);
        $matched = null;
        foreach ($families as $key => $spec) {
            if (strpos($lower, $spec['match']) !== false) { $matched = $key; break; }
        }

        if ($matched === null) { $other[] = $reason; continue; }

        if (!isset($buckets[$matched])) {
            $buckets[$matched] = [
                'key'       => $matched,
                'label'     => $families[$matched]['label'],
                'severity'  => $families[$matched]['severity'],
                'days'      => [],
                'day_dates' => [],
                'raw'       => [],
            ];
        }
        $buckets[$matched]['raw'][] = $reason;

        // Extracted separately from the family match, so a wording change upstream costs
        // the day list at worst, never the family.
        if ($families[$matched]['day'] && preg_match('/\bday\s+(\d+)\s*\(([^)]*)\)/i', $reason, $m)) {
            $day = (int) $m[1];
            $buckets[$matched]['days'][] = $day;
            $date = trim($m[2]);
            if ($date !== '' && !isset($buckets[$matched]['day_dates'][$day])) {
                $buckets[$matched]['day_dates'][$day] = $date;
            }
        } elseif ($families[$matched]['day'] && preg_match('/\bday\s+(\d+)\b/i', $reason, $m)) {
            $buckets[$matched]['days'][] = (int) $m[1];
        }
    }

    // Emit in the fixed order of awaiting_info_families(), absent families simply skipped.
    $out = [];
    foreach (array_keys($families) as $key) {
        if (!isset($buckets[$key])) continue;
        $b = $buckets[$key];
        sort($b['days'], SORT_NUMERIC);
        $b['days'] = array_values(array_unique($b['days']));
        $out[] = $b;
    }
    if ($other) {
        $out[] = [
            'key'      => 'other',
            'label'    => 'Other',
            // Conservative: something unclassified must not inflate a quote's urgency
            // past quotes whose blockers we do understand.
            'severity' => 'partial',
            'days'     => [],
            'raw'      => $other,
        ];
    }
    return $out;
}

// Soonest trip first: the closest proxy for when a booking stops being possible. Blocked
// age is deliberately NOT the key. Measured on the live cohort the two are uncorrelated,
// and sorting by age puts a quote travelling in two days below one travelling in 113.
//
// Exported so all_callers.php can restore the order after merging two teams' lists, which
// a plain array_merge() of two sorted lists destroys.
function awaiting_info_sorter(): callable
{
    return function (array $a, array $b): int {
        if ($a['trip_date'] !== $b['trip_date']) {
            return $a['trip_date'] <=> $b['trip_date'];
        }
        if ($a['has_total_blocker'] !== $b['has_total_blocker']) {
            return $b['has_total_blocker'] <=> $a['has_total_blocker'];
        }
        return $a['quote_no'] <=> $b['quote_no'];
    };
}

// The grouping key, shared with the view so the browser can regroup rows the same way
// (awaiting-info.js reads it off data-org). Falls back to the quote id so a row with no
// organisation stays its own block instead of collapsing into one shared "" bucket.
function awaiting_org_key(array $row): string
{
    return ($row['organization_name'] ?? '') !== ''
        ? 'org:' . $row['organization_name']
        : 'quote:' . $row['quoteid'];
}

// Groups a row list into per-organisation blocks, the same list-of-lists shape
// render_section() consumes for SP1-4, ordered by $anchor_rank rather than by the rows
// present here. See group_awaiting_by_org() for where that rank comes from.
function awaiting_blocks_by_org(array $rows, array $anchor_rank): array
{
    $blocks = [];
    foreach ($rows as $row) {
        $blocks[awaiting_org_key($row)][] = $row;
    }
    uksort($blocks, fn($a, $b) => ($anchor_rank[$a] ?? PHP_INT_MAX) <=> ($anchor_rank[$b] ?? PHP_INT_MAX));
    return array_values($blocks);
}

// Rows already called today are split off BEFORE grouping, so a quote that is done drops
// to the bottom on its own instead of waiting for the rest of its organisation. What has
// to stay together is the work still outstanding for an agency, and that stays together
// in the top half. An org with some of each simply appears in both halves.
//
// Each organisation's position is fixed once, over ALL of its rows, and both halves reuse
// it. Deriving it from the rows left in a half would move an agency's remaining quotes
// down the page the moment its soonest one was worked, which reads as the row vanishing
// rather than as progress. It is the same rule SP1-4 already follow, where an org's quotes
// sit at its highest sub-priority even when some of them are far less urgent.
//
// The input arrives sorted soonest-trip-first, so first appearance is that rank.
function group_awaiting_by_org(array $rows): array
{
    $anchor_rank = [];
    foreach ($rows as $row) {
        $key = awaiting_org_key($row);
        if (!isset($anchor_rank[$key])) $anchor_rank[$key] = count($anchor_rank);
    }

    $open = [];
    $done = [];
    foreach ($rows as $row) {
        if (!empty($row['called_today'])) { $done[] = $row; } else { $open[] = $row; }
    }
    return array_merge(
        awaiting_blocks_by_org($open, $anchor_rank),
        awaiting_blocks_by_org($done, $anchor_rank)
    );
}

// ---------------------------------------------------------------------------
// Regional model version.
// ---------------------------------------------------------------------------

// One merged list, access-gated rather than owned: FIT and Groups & MICE together, no
// owned/pool split, since nobody chasing missing information "owns" a region here — the
// post-sale pair and supervisors see everything. Same shape as
// build_payment_deadline_queue(), including the 'rows'/'unrouted' split by resolvable
// region.
function build_region_awaiting_info_queue(mysqli $conn, array $india_regions): array
{
    if (!$india_regions) return ['rows' => [], 'unrouted' => []];

    $stage_in  = "'" . implode("','", array_map(fn($s) => $conn->real_escape_string($s), awaiting_info_stage_list())) . "'";
    $region_in = "'" . implode("','", array_map(fn($r) => $conn->real_escape_string($r), $india_regions)) . "'";

    // No suffix filter: FIT and Groups share one list now. quote_type is still derived
    // from the suffix below, purely for the CRM deep link.
    //
    // The region test stays NULL-tolerant on purpose: a blank region, or no
    // vtiger_quotes_info row at all, must not be dropped. Those are exactly the rows
    // the 'unrouted' block exists to surface.
    $result = $conn->query("
        SELECT
            vq.quoteid, vq.quote_no, vq.quotestage,
            va.organization_name,
            vcd.name                 AS contactname,
            vcd.mobile               AS contact_mobile,
            vqcf.cf_1162             AS trip_date,
            vqinfo.assigned_to_sales_agent AS owner,
            vqinfo.assigned_to_region      AS region,
            pr.allUniqueReasons      AS reasons_json,
            st.created_at            AS accepted_at,
            st.user_name             AS accepted_by,
            ic.call_count, ic.last_auto_id,
            last_call.calltime       AS last_call_time,
            last_call.description    AS last_notes,
            last_call.outcome        AS last_outcome,
            last_call.created_by     AS last_call_by
        FROM oskar_pause_reasons pr
        JOIN vtiger_quotes vq               ON vq.quoteid = pr.quoteid AND vq.deleted = 0
        LEFT JOIN tdu_organisation va       ON vq.accountid = va.organizationid
        LEFT JOIN tdu_contacts vcd          ON vq.contactid = vcd.auto_id
                                           AND va.organizationid = vcd.organizationid
        LEFT JOIN vtiger_quotescf vqcf      ON vqcf.quoteid = vq.quoteid
        LEFT JOIN vtiger_quotes_info vqinfo ON vqinfo.quoteid = vq.quoteid
        LEFT JOIN (
            SELECT quoteid, MIN(auto_id) AS first_accepted_auto_id
            FROM vtiger_quote_stage_track
            WHERE stage = 'Change Stage to Accepted'
            GROUP BY quoteid
        ) st_min ON st_min.quoteid = vq.quoteid
        LEFT JOIN vtiger_quote_stage_track st ON st.auto_id = st_min.first_accepted_auto_id
        LEFT JOIN (
            SELECT vqf.quoteid, COUNT(*) AS call_count, MAX(vqf.auto_id) AS last_auto_id
            FROM vtiger_quotes_followup vqf
            WHERE vqf.followup_type = 'call_info'
              AND vqf.outcome IN ('info_contacted', 'info_no_answer', 'info_received')
            GROUP BY vqf.quoteid
        ) ic ON ic.quoteid = vq.quoteid
        LEFT JOIN vtiger_quotes_followup last_call ON last_call.auto_id = ic.last_auto_id
        WHERE vq.quotestage IN ($stage_in)
          AND vqcf.cf_1162 > CURDATE()
          AND pr.allUniqueReasons IS NOT NULL
          AND TRIM(pr.allUniqueReasons) NOT IN ('', '[]')
          AND (vqinfo.assigned_to_region IN ($region_in)
               OR vqinfo.assigned_to_region IS NULL
               OR vqinfo.assigned_to_region = '')
    ");

    if ($result === false) {
        error_log('[TDU Queue] build_region_awaiting_info_queue query failed: ' . $conn->error);
        return ['rows' => [], 'unrouted' => []];
    }

    $rows     = [];
    $unrouted = [];
    $today    = date('Y-m-d');

    while ($row = $result->fetch_assoc()) {
        $decoded = json_decode((string) $row['reasons_json'], true);
        if (!is_array($decoded)) {
            error_log('[TDU Queue] region_awaiting_info: undecodable reasons for quoteid ' . $row['quoteid']);
            $decoded = ['Outstanding information could not be read. Check this quote in the ops view.'];
        }
        $row['reason_groups'] = group_pause_reasons(array_values($decoded));
        if (!$row['reason_groups']) continue;

        $row['reason_count']      = count(array_filter($decoded, 'is_string'));
        $row['has_total_blocker'] = false;
        foreach ($row['reason_groups'] as $g) {
            if ($g['severity'] === 'total') { $row['has_total_blocker'] = true; break; }
        }

        $row['days_to_trip'] = $row['trip_date']
            ? (int) floor((strtotime($row['trip_date']) - strtotime($today)) / 86400)
            : null;
        $row['days_blocked'] = $row['accepted_at']
            ? (int) floor((strtotime($today) - strtotime(substr($row['accepted_at'], 0, 10))) / 86400)
            : null;

        $row['call_count']   = (int) ($row['call_count'] ?? 0);
        $row['called_today'] = !empty($row['last_call_time'])
            && substr($row['last_call_time'], 0, 10) === $today;

        // Display only, for the CRM deep link — nothing about priority reads it.
        $row['quote_type'] = (strcasecmp(substr((string) $row['quote_no'], -1), 'G') === 0)
            ? 'groups' : 'fit';
        // Display only. null = the CRM region maps to no queue region, which puts the row
        // in 'unrouted' below, same convention build_payment_deadline_queue() uses.
        $row['queue_region'] = tdu_queue_region_for($row['region']);

        foreach (['organization_name', 'contactname', 'last_notes'] as $f) {
            if (isset($row[$f]) && is_string($row[$f]) && $row[$f] !== ''
                && !mb_check_encoding($row[$f], 'UTF-8')) {
                $row[$f] = mb_convert_encoding($row[$f], 'UTF-8', 'Windows-1252');
            }
        }

        unset($row['reasons_json'], $row['last_auto_id']);

        if ($row['queue_region'] !== null) {
            $rows[] = $row;
        } else {
            $unrouted[] = $row;
        }
    }
    $result->free();

    $sorter = awaiting_info_sorter();
    usort($rows,     $sorter);
    usort($unrouted, $sorter);

    return ['rows' => $rows, 'unrouted' => $unrouted];
}
