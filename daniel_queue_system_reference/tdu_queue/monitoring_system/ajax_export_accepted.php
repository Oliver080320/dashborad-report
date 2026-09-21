<?php
// CSV export of this month's Accepted quotes (India FIT + Groups & MICE combined) — admin-only,
// same guard as monitoring.php. NOTE: this is a full-page GET (a plain download link, not a
// fetch/XHR call), so it uses the direct session check rather than ajax_1auth.php — that guard
// is AJAX-only and exits silently on a full-page load, giving a blank 200 (same reason
// user_manual.php avoids it).
if (session_status() === PHP_SESSION_NONE) session_start();
if (($_SESSION['title'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/../config.php';
tdu_maintenance_guard(MONITORING_MAINTENANCE_MODE, 'json');
require_once __DIR__ . '/../queues/queue_builder.php';
require_once __DIR__ . '/monitoring_builder.php';

$rows = build_accepted_this_month_list($conn, INDIA_REGIONS);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="accepted_this_month_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Quote No', 'Type', 'Organization', 'Contact', 'Mobile', 'Owner', 'Region',
    'Stage', 'Created', 'Accepted At', 'Trip Date', 'Pax',
]);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['quote_no'],
        $r['quote_type'],
        $r['organization_name'],
        $r['contactname'],
        $r['contactmobile'],
        $r['owner'],
        $r['assigned_to_region'],
        $r['quotestage'],
        $r['quote_created_at'],
        $r['accepted_at'],
        $r['trip_start_date'],
        $r['pax'],
    ]);
}
fclose($out);
exit;
