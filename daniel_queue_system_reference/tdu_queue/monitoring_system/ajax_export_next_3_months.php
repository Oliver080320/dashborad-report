<?php
// CSV export of quotes travelling in the next 3 months (India regions, current month + next 2 —
// see build_next_3_months_rows()) — admin-only, same guard as ajax_export_accepted.php
// (ajax_1auth.php is AJAX-only and would silently 200 on a plain download link, since this is a
// full-page GET, not a fetch call). Row-level data only — the user builds their own report/
// analysis in Excel from this.
if (session_status() === PHP_SESSION_NONE) session_start();
if (($_SESSION['title'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/../config.php';
tdu_maintenance_guard(MONITORING_MAINTENANCE_MODE, 'json');
require_once __DIR__ . '/../queues/queue_builder.php';
require_once __DIR__ . '/monitoring_builder.php';

$rows = build_next_3_months_rows($conn, INDIA_REGIONS);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="next_3_months_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Quote ID', 'Quote No', 'Organization', 'Owner', 'Region', 'Stage', 'Stage Bucket',
    'Priority', 'Priority Label', 'Created', 'Trip Date', 'Trip Month',
]);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['quoteid'],
        $r['quote_no'],
        $r['organization_name'],
        $r['assigned_to_sales_agent'],
        $r['assigned_to_region'],
        $r['quotestage'],
        $r['stage_bucket'],
        $r['priority'],
        $r['priority_label'],
        $r['created_at'],
        $r['trip_date'],
        $r['trip_month'],
    ]);
}
fclose($out);
exit;
