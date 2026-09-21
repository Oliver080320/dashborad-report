<?php
// Standalone switch for this page only — mirrors config.php's MONITORING_MAINTENANCE_MODE,
// but kept local rather than shared: this page has no other dependency on config.php (no DB
// connection, no queue functions) and adding one just for this flag would break that.
define('USER_MANUAL_MAINTENANCE_MODE', false);
if (USER_MANUAL_MAINTENANCE_MODE) {
    http_response_code(503);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>TDU Sales Queue — User Manual</title>
</head>
<body style="margin:0;display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;font-family:Arial,sans-serif;text-align:center;color:#333;">
<div style="white-space:pre;font-family:monospace;font-size:0.9rem;line-height:1.2;margin:0 0 12px;color:#333;"><?php echo base64_decode('ICAgIC4tLS0tLS0tLS0tLgogICAgfCAgIH5PTn4gICB8CiAgICB8ICAgX19fXyAgIHwKICAgIHwgIHwuLS0ufCAgfAogICAgfCAgfHwgIHx8ICB8CiAgICB8ICB8fF9ffHwgIHwKICAgIHwgIHx8XCBcfCAgfAogICAgfCAgfFwgXF9cICB8CiAgICB8ICB8X1xbX10gIHwKICAgIHwgICAgICAgICAgfAogICAgfCAgfk9GRn4gICB8CiAgICAnLS0tLS0tLS0tLSc='); ?></div>
<h1 style="margin-bottom:12px;">Under Maintenance</h1>
<p style="font-size:1.1rem;">This section is being updated. Please check back soon.</p>
</body>
</html>
    <?php
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['user_name'])) {
    http_response_code(403);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TDU Sales Queue — User Manual</title>
</head>
<body style="margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;">
<div style="max-width:680px;margin:48px auto;padding:24px;text-align:center;color:#555;">
  <h2 style="color:#334155;margin-bottom:8px;">Please log in</h2>
  <p>You need to be logged in to view the user manual. Please sign in to the dashboard and try again.</p>
</div>
</body>
</html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>TDU Sales Queue — User Manual</title>
<link rel="icon" href="https://yt3.googleusercontent.com/M-g1p3Tcn9e6jm2uWjtBV8XG2GdvIVhy898piiw5ZZsU3DYZ147mR7sFFaB-1Oec8uBeNjmcVM4=s160-c-k-c0x00ffffff-no-rj" type="image/png">
<style>
/* ── Base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  font-size: 15px;
  line-height: 1.65;
  color: #1a1a2e;
  background: #f0f2f5;
}

/* ── Layout ── */
.manual-wrap { max-width: 1500px; margin: 0 auto; padding: 32px 24px 60px; }

/* ── Cover ── */
.cover {
  background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
  color: white;
  border-radius: 12px;
  padding: 56px 48px;
  margin-bottom: 40px;
  position: relative;
  overflow: hidden;
}
.cover::before {
  content: '';
  position: absolute;
  top: -40px; right: -40px;
  width: 200px; height: 200px;
  background: rgba(255,255,255,0.04);
  border-radius: 50%;
}
.cover h1 { font-size: 2.1rem; font-weight: 800; margin-bottom: 8px; letter-spacing: -0.02em; }
.cover .subtitle { font-size: 1.05rem; color: #94a3b8; margin-bottom: 28px; }
.cover .meta-row { display: flex; gap: 24px; flex-wrap: wrap; }
.cover .meta-item { font-size: 0.82rem; color: #64748b; }
.cover .meta-item strong { color: #cbd5e1; display: block; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.07em; margin-bottom: 1px; }

/* ── Benefits ── */
.benefits {
  background: white;
  border-radius: 10px;
  padding: 26px 30px;
  margin-bottom: 32px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.07);
}
.benefits-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: #94a3b8; font-weight: 700; margin-bottom: 16px; }
.benefits-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; }
.benefit-item { display: flex; gap: 10px; align-items: flex-start; }
.benefit-tick {
  flex-shrink: 0; width: 22px; height: 22px; border-radius: 50%;
  background: #dcfce7; color: #166534; font-size: 0.8rem; font-weight: 700;
  display: flex; align-items: center; justify-content: center; margin-top: 1px;
}
.benefit-item strong { display: block; font-size: 0.9rem; color: #1e293b; margin-bottom: 2px; }
.benefit-item p { font-size: 0.82rem; color: #64748b; margin: 0; }

/* ── TOC ── */
.toc {
  background: white;
  border-radius: 10px;
  padding: 26px 30px;
  margin-bottom: 32px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.07);
}
.toc-title { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: #94a3b8; font-weight: 700; margin-bottom: 14px; }
.toc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 32px; }
.toc-grid a { color: #334155; text-decoration: none; font-size: 0.875rem; padding: 3px 0; display: block; }
.toc-grid a:hover { color: #2980b9; text-decoration: underline; }
.toc-grid .toc-num { color: #94a3b8; margin-right: 6px; }

/* ── Section card ── */
.section {
  background: white;
  border-radius: 10px;
  padding: 30px 32px;
  margin-bottom: 28px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.07);
}
.section h2 {
  font-size: 1.3rem;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 16px;
  padding-bottom: 14px;
  border-bottom: 2px solid #f1f5f9;
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.section h3 {
  font-size: 1rem;
  font-weight: 700;
  color: #334155;
  margin: 22px 0 10px;
}
p { margin-bottom: 12px; color: #374151; }

/* ── Role badges ── */
.role-badge {
  display: inline-block;
  font-size: 0.68rem;
  font-weight: 700;
  padding: 2px 9px;
  border-radius: 20px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.role-owner      { background: #dbeafe; color: #1d4ed8; }
.role-supervisor { background: #fce7f3; color: #9d174d; }
.role-postsale   { background: #fef3c7; color: #92400e; }
.role-admin      { background: #dcfce7; color: #166534; }

/* ── Note boxes ── */
.note, .tip, .warn {
  border-radius: 0 6px 6px 0;
  padding: 11px 15px;
  margin: 14px 0;
  font-size: 0.875rem;
}
.note { background: #eff6ff; border-left: 4px solid #3b82f6; color: #1e40af; }
.tip  { background: #f0fdf4; border-left: 4px solid #22c55e; color: #166534; }
.warn { background: #fffbeb; border-left: 4px solid #f59e0b; color: #92400e; }
.note strong, .tip strong, .warn strong { display: inline; }

/* ── Callout grid ── */
.callout-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 16px 0; }
.callout-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; }
.callout-color { display: inline-block; width: 11px; height: 11px; border-radius: 3px; margin-right: 6px; vertical-align: middle; }
.callout-item strong { font-size: 0.9rem; color: #1e293b; }
.callout-item p { font-size: 0.82rem; color: #64748b; margin: 5px 0 0; }

/* ── Mockup container ── (replicates the live page, which uses Arial) ── */
.mockup { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin: 18px 0; font-family: Arial, sans-serif; }
.mockup-label {
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  padding: 5px 14px;
  font-size: 0.7rem;
  color: #94a3b8;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
.mockup-inner { padding: 14px; }

/* ── Queue page header (white background, like the dashboard-embedded page) ── */
.q-panel { background: #fff; padding: 16px 18px 8px; }
.q-title-row { display: flex; align-items: baseline; gap: 14px; margin-bottom: 4px; flex-wrap: wrap; }
.q-title-row h1 { font-size: 1.4rem; font-weight: bold; color: #1a1a2e; margin: 0; }
.view-as-inline { display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #666; }
.view-as-inline select { font-size: 0.85rem; padding: 2px 6px; }
.btn-inbound {
  margin-left: auto; background: #fff; color: #334155; border: 1px solid #334155;
  padding: 4px 12px; border-radius: 3px; font-size: 0.85rem; font-weight: 600; cursor: pointer;
}
.q-meta { font-size: 0.9rem; color: #666; }
.q-meta .pending { color: #92400e; font-weight: 600; }

/* ── Section heading — coloured bold text + full-width underline in the same colour ── */
.section-toggle-mock {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 4px 4px; margin: 22px 0 0;
  border-bottom: 2px solid currentColor;
  font-size: 1.1rem;
  user-select: none;
}
.section-toggle-mock .sect-title  { font-weight: bold; }
.section-toggle-mock .sect-count  { font-weight: normal; font-size: 0.9rem; }
.caret-mock {
  width: 0; height: 0;
  border-top: 6px solid transparent;
  border-bottom: 6px solid transparent;
  border-left: 9px solid currentColor;
  transform: rotate(90deg);          /* expanded → points down */
  flex-shrink: 0;
}
.caret-mock.collapsed { transform: rotate(0deg); }  /* collapsed → points right */
.sect-carryover { color: #92400e; }
.sect-sp1       { color: #1C93C4; }
.sect-sp2       { color: #F5A623; }
.sect-sp3       { color: #EF6C24; }
.sect-sp4       { color: #4A7B8C; }

/* ── Section progress bar (Called: X/Y) ── */
.sect-progress { margin-left: auto; font-size: 0.85rem; font-weight: normal; display: inline-flex; align-items: center; gap: 5px; }
.sect-progress-bar { display: inline-block; width: 60px; height: 6px; background: #e0e0e0; border-radius: 4px; overflow: hidden; }
.sect-progress-fill { display: block; height: 100%; border-radius: 4px; }

/* ── Queue table (matches .tdu-queue-wrap table) ── */
.q-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.q-table th {
  background: #f5f5f5; text-align: left; padding: 7px 10px;
  border-bottom: 2px solid #ddd; font-size: 0.85rem; white-space: nowrap;
}
.q-table td { padding: 6px 10px; border-bottom: 1px solid #eee; font-size: 0.875rem; vertical-align: top; }
.q-table .org-row td { background: #fafafa; font-weight: bold; }
/* Action column (always the last cell) must never wrap Log/History onto separate
   lines — matches .action-cell in the live app's queue_view.php. */
.q-table td:last-child { white-space: nowrap; }
.q-table a { color: #1d4ed8; text-decoration: none; }
.overflow-x { overflow-x: auto; }

/* ── In-table buttons (match live styling) ── */
.log-btn {
  background: #334155; color: #fff; border: 1px solid #334155; font-weight: 600;
  font-size: 0.78rem; padding: 2px 8px; border-radius: 3px; cursor: pointer; white-space: nowrap;
}
.log-btn.done   { background: #fff; color: #27ae60; border-color: #27ae60; }
.hist-btn,
.inbound-add-btn { background: #fff; color: #333; border: 1px solid #aaa;
                  font-size: 0.78rem; padding: 2px 8px; border-radius: 3px; cursor: pointer; margin-left: 4px; }
.btn-save       { background: #334155; color: white; border: none; padding: 6px 14px; border-radius: 5px; font-size: 0.82rem; cursor: pointer; }
.btn-cancel     { background: #475569; color: white; border: 1px solid #475569; padding: 6px 14px; border-radius: 5px; font-size: 0.82rem; cursor: pointer; }

/* ── Due / days-behind coloured text (no pill, matches live) ── */
.days-overdue { color: #c0392b; font-weight: bold; }
.days-today   { color: #e67e22; font-weight: bold; }
.days-soon    { color: #27ae60; }

/* ── Done rows ── */
.done-row td  { opacity: 0.45; background: #f9f9f9 !important; }

/* ── Priority select (in-table) ── */
.prio-sel { font-size: 0.8rem; padding: 2px 4px; }

/* ── Owner badge (manager view — grey, inline next to org name) ── */
.owner-badge {
  display: inline-block; font-weight: normal; font-size: 0.72rem;
  color: #fff; background: #888; border-radius: 3px; padding: 1px 5px; margin-left: 4px;
}

/* ── Outcome modal ── */
.modal-mock {
  background: white; border: 1px solid #ccc; border-radius: 6px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.18); max-width: 500px; margin: 0 auto;
  font-size: 0.875rem; padding: 20px 24px;
}
.modal-mock h3 { font-size: 1rem; margin: 0 0 16px; }
.form-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.form-row label { width: 100px; flex-shrink: 0; font-size: 0.875rem; color: #333; }
.form-row select, .form-row input {
  flex: 1; padding: 4px 6px; border: 1px solid #ccc; border-radius: 3px; font-size: 0.875rem;
}
.form-row textarea {
  flex: 1; padding: 4px 6px; border: 1px solid #ccc; border-radius: 3px;
  font-size: 0.875rem; resize: vertical; height: 60px;
}
.dialog-actions { margin-top: 16px; text-align: right; display: flex; justify-content: flex-end; gap: 8px; }

/* ── Calendar mockup (colour-coded schedule-load calendar — Flatpickr, material_blue theme) ── */
.cal-mock {
  width: 300px; margin: 0 auto; font-family: Arial, sans-serif;
  background: #fff; border-radius: 8px; overflow: hidden;
  box-shadow: 0 2px 12px rgba(0,0,0,0.16);
}
.cal-mock-head {
  background: #4a90d9; color: #fff; padding: 10px 12px;
  display: flex; align-items: center; justify-content: space-between;
}
.cal-mock-head .nav-arrow { font-size: 0.95rem; opacity: 0.9; width: 20px; text-align: center; }
.cal-mock-head .month-pill {
  background: rgba(255,255,255,0.16); border-radius: 4px; padding: 3px 9px;
  font-weight: 700; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 4px;
}
.cal-mock-head .month-pill::after { content: '\25BE'; font-size: 0.6rem; opacity: 0.85; }
.cal-mock-head .cal-year { font-weight: 700; font-size: 1.05rem; }
.cal-mock-dow-row {
  display: grid; grid-template-columns: repeat(7, 1fr);
  padding: 8px 6px 2px; background: #fff;
}
.cal-mock-dow { text-align: center; font-size: 0.7rem; font-weight: 700; color: #4a90d9; }
.cal-mock-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; padding: 2px 6px 6px; }
.cal-day-mock { display: flex; flex-direction: column; align-items: center; padding-top: 3px; height: 40px; }
.cal-day-mock .num {
  display: inline-flex; align-items: center; justify-content: center;
  width: 23px; height: 23px; border-radius: 50%; font-size: 0.8rem; color: #333;
}
.cal-day-mock .cnt { font-size: 0.54rem; margin-top: 2px; color: #aaa; }
.cal-day-safe .num { background: #e3f2e6; color: #2e7d32; }
.cal-day-safe .cnt { color: #2e7d32; }
.cal-day-warn .num { background: #fdf3d8; color: #a06b00; }
.cal-day-warn .cnt { color: #a06b00; font-weight: bold; }
.cal-day-full .num { background: #fbe1e0; color: #c0392b; }
.cal-day-full .cnt { color: #c0392b; font-weight: bold; }
.cal-day-mock.cal-day-nextmonth { opacity: 0.55; }
.cal-day-off .num { color: #d8d8d8; }
.cal-day-off .cnt { visibility: hidden; }
.cal-mock-time {
  display: flex; align-items: center; justify-content: center; gap: 5px;
  padding: 9px 0; border-top: 1px solid #eee; font-size: 1rem; color: #333;
}
.cal-mock-time .tbox {
  width: 28px; text-align: center; border: none; background: #f5f5f5; border-radius: 3px; padding: 3px 0;
}
.cal-mock-time .sep { color: #999; }
.cal-mock-time .ampm {
  background: #eef4fc; color: #4a90d9; font-weight: 700; font-size: 0.8rem;
  border-radius: 3px; padding: 3px 7px; margin-left: 4px;
}
.cal-mock-confirm {
  display: block; width: 100%; margin: 0;
  background: #4a90d9; color: #fff; font-weight: 700; border: none;
  border-radius: 0 0 8px 8px;
  padding: 10px 0; text-align: center; font-size: 0.9rem;
}
.cal-legend { display: flex; gap: 14px; margin-top: 10px; font-size: 0.78rem; color: #555; flex-wrap: wrap; }
.cal-legend .dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }

/* ── Reason dropdown mockup (native <select> shown expanded) ── */
.reason-drop { max-width: 340px; margin: 0 auto; font-family: Arial, sans-serif; font-size: 0.85rem; }
.reason-drop-closed {
  border: 1px solid #767676; border-radius: 2px; padding: 5px 8px;
  display: flex; justify-content: space-between; align-items: center; color: #333; background: #fff;
}
.reason-drop-closed::after { content: '\25BE'; color: #555; font-size: 0.75rem; }
.reason-drop-list {
  background: #fff; box-shadow: 0 6px 18px rgba(0,0,0,0.25); margin-top: -1px;
  max-width: 340px; padding: 4px 0;
}
.reason-drop-selected { background: #3f66c9; color: #fff; padding: 4px 10px; }
.reason-drop-group { font-weight: 700; color: #111; padding: 5px 10px 2px; }
.reason-drop-option { color: #222; padding: 3px 10px 3px 20px; }

/* ── History panel ── */
.hist-panel-mock { background: #fafcff; padding: 4px 10px 10px 30px; }
.hist-panel-mock table { width: 100%; border-collapse: collapse; font-size: 0.8rem; margin: 6px 0; }
.hist-panel-mock th {
  background: #eef2ff; text-align: left; padding: 4px 8px; font-weight: 600; font-size: 0.78rem;
}
.hist-panel-mock td { padding: 3px 8px; border-bottom: 1px solid #eef2f7; }

/* ── Outcome badges (illustrative, manual only) ── */
.ob { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 0.68rem; font-weight: 700; white-space: nowrap; }
.ob-next       { background: #dbeafe; color: #1d4ed8; }
.ob-noanswer   { background: #f3f4f6; color: #374151; }
.ob-interested { background: #dcfce7; color: #166534; }
.ob-inbound    { background: #fce7f3; color: #9d174d; }
.ob-requote    { background: #dbeafe; color: #2980b9; }
.ob-rejected   { background: #fee2e2; color: #c0392b; }
.ob-accepted   { background: #dcfce7; color: #27ae60; }

/* ── Search result ── */
.search-result-mock { background: #f8f9fa; border-radius: 4px; padding: 10px 12px; margin: 12px 0; font-size: 0.875rem; }
.search-result-mock table { width: 100%; }
.search-result-mock td:first-child { color: #666; width: 90px; }

/* ── Outcome reference table (manual only) ── */
.outcome-ref { width: 100%; border-collapse: collapse; margin: 14px 0; font-size: 0.875rem; }
.outcome-ref th { background: #f8fafc; padding: 8px 12px; text-align: left; border-bottom: 2px solid #e2e8f0; font-size: 0.78rem; color: #555; }
.outcome-ref td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: top; color: #374151; }
.outcome-ref tr:last-child td { border-bottom: none; }

/* ── Warning banner (matches the merged view's pending-region notice) ── */
.warning-banner {
  background: #fffbeb; border: 1px solid #fcd34d; border-radius: 5px;
  padding: 9px 13px; font-size: 0.85rem; color: #92400e; margin: 8px 0;
}

/* ── Print ── */
@media print {
  body { background: white; }
  .manual-wrap { padding: 0; max-width: 100%; }
  .section { box-shadow: none; border: 1px solid #e2e8f0; margin-bottom: 20px; page-break-inside: avoid; }
  .cover { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  a { color: inherit; text-decoration: none; }
}

/* ── Responsive ── */
@media (max-width: 680px) {
  .callout-grid, .benefits-grid { grid-template-columns: 1fr; }
  .toc-grid     { grid-template-columns: 1fr; }
  .section      { padding: 20px 18px; }
  .cover        { padding: 36px 24px; }
}
</style>
</head>
<body>
<div class="manual-wrap">

<!-- ══ COVER ══ -->
<div class="cover">
  <h1>TDU Sales Queue</h1>
  <div class="subtitle">User Manual: Follow-up, Leads, Payment Deadline and Awaiting Information</div>
  <div class="meta-row">
    <div class="meta-item"><strong>Team</strong>TDU Sales</div>
    <div class="meta-item"><strong>Model</strong>Regional (one owner per region)</div>
    <div class="meta-item"><strong>Updated</strong>September 2026</div>
  </div>
</div>

<!-- ══ WHY THIS HELPS YOU ══ -->
<div class="benefits">
  <div class="benefits-title">Why this system helps you</div>
  <div class="benefits-grid">
    <div class="benefit-item">
      <span class="benefit-tick">&#10003;</span>
      <div><strong>Prioritises high-value opportunities</strong><p>The clients most likely to convert rise to the top automatically — you don't have to guess who to call next.</p></div>
    </div>
    <div class="benefit-item">
      <span class="benefit-tick">&#10003;</span>
      <div><strong>Reduces missed follow-ups</strong><p>Every promise you make to call back is tracked and resurfaces on the right day, so nothing slips through the cracks.</p></div>
    </div>
    <div class="benefit-item">
      <span class="benefit-tick">&#10003;</span>
      <div><strong>Eliminates manual spreadsheet tracking</strong><p>No more updating a shared spreadsheet by hand. The queue reads live CRM data and keeps itself up to date.</p></div>
    </div>
    <div class="benefit-item">
      <span class="benefit-tick">&#10003;</span>
      <div><strong>Provides consistent workload management</strong><p>A fixed daily capacity keeps your call list realistic, whether it's a quiet week or a busy one.</p></div>
    </div>
    <div class="benefit-item">
      <span class="benefit-tick">&#10003;</span>
      <div><strong>Improves quote conversion potential</strong><p>Structured outcomes and reminders mean warm leads get followed up consistently, not just when someone remembers to.</p></div>
    </div>
  </div>
</div>

<!-- ══ TABLE OF CONTENTS ══ -->
<div class="toc">
  <div class="toc-title">Contents</div>
  <div class="toc-grid">
    <?php // Two-column grid filled by ROWS, so source order interleaves the columns:
          // left column is 1-7, right column is 8-14, hence 1, 8, 2, 9, 3, 10 and so on.
          // Adding an entry means re-interleaving the whole list, not appending to it. ?>
    <a href="#overview"><span class="toc-num">1.</span> Overview</a>
    <a href="#done-state"><span class="toc-num">8.</span> The Done State</a>
    <a href="#access"><span class="toc-num">2.</span> How to Access</a>
    <a href="#history"><span class="toc-num">9.</span> Call History</a>
    <a href="#regions"><span class="toc-num">3.</span> Regions &amp; Ownership</a>
    <a href="#search-quote"><span class="toc-num">10.</span> Search Quote</a>
    <a href="#main-screen"><span class="toc-num">4.</span> Main Screen</a>
    <a href="#leads"><span class="toc-num">11.</span> Leads Queue</a>
    <a href="#queue-sections"><span class="toc-num">5.</span> Queue Sections</a>
    <a href="#payment-deadline"><span class="toc-num">12.</span> Payment Deadline Queue</a>
    <a href="#grouping"><span class="toc-num">6.</span> Organisation Grouping</a>
    <a href="#awaiting-info"><span class="toc-num">13.</span> Awaiting Information</a>
    <a href="#logging"><span class="toc-num">7.</span> Logging an Outcome</a>
    <a href="#manager-view"><span class="toc-num">14.</span> Supervisor &amp; Manager View</a>
  </div>
</div>

<!-- ══ 1. OVERVIEW ══ -->
<div class="section" id="overview">
  <h2>1. Overview</h2>
  <p>The TDU Sales Queue is your daily call list. Every morning it reads the CRM and builds a queue just for you, sorted so the most urgent calls sit at the top and newer enquiries sit below.</p>
  <p>Your queue is built once a day, the first time you open the page, and it stays exactly as it is for the rest of the day. Refreshing the page won't reshuffle anything — the order stays fixed. Only small live details (stage, call count, and whether you've already worked a quote today) update as you go.</p>

  <p><strong>Your list is your regions.</strong> Every quote belongs to whoever owns the region it sits in, so what you see is everything live in your own patch, with standard quotes and Groups &amp; MICE together in one list rather than split into two. See <a href="#regions" style="color:#2980b9;">&sect;3</a> for how ownership is decided.</p>

  <div class="callout-grid">
    <div class="callout-item">
      <strong>Follow-up <span class="role-badge role-owner">Region owner</span></strong>
      <p>Your main list: live quotes still in play. Up to 42 fresh quotes a day, plus anything carried over from previous days on top.</p>
    </div>
    <div class="callout-item">
      <strong>Leads <span class="role-badge role-owner">Region owner</span></strong>
      <p>Enquiries that haven't been quoted yet, worked the same way but with no daily cap (<a href="#leads" style="color:#2980b9;">&sect;11</a>).</p>
    </div>
    <div class="callout-item">
      <strong>Payment Deadline <span class="role-badge role-postsale">Post-sale</span></strong>
      <p>Chasing money on sales already won, one shared list across every region (<a href="#payment-deadline" style="color:#2980b9;">&sect;12</a>).</p>
    </div>
    <div class="callout-item">
      <strong>Awaiting Information <span class="role-badge role-postsale">Post-sale</span></strong>
      <p>Confirmed bookings held up waiting on details from the client (<a href="#awaiting-info" style="color:#2980b9;">&sect;13</a>).</p>
    </div>
  </div>
  <div class="note">The last two are <strong>not</strong> part of a region owner's day. They belong to the post-sale team, and the links to them only appear for the people who work them.</div>
</div>

<!-- ══ 2. HOW TO ACCESS ══ -->
<div class="section" id="access">
  <h2>2. How to Access</h2>
  <p>The queue lives inside your existing sales dashboard. Log in as normal, then go to:</p>
  <div class="note"><strong>Quote menu → Sales Queue</strong><br>This link only shows up for <code>sales</code> and <code>admin</code> users.</div>
  <p>You don't need a separate login: the queue recognises you from your dashboard session. Where you land depends on what your account holds:</p>
  <ul style="padding-left:20px; margin-bottom:12px; color:#374151;">
    <li style="margin-bottom:5px;"><strong>You own at least one region</strong>: your Follow-up queue for those regions.</li>
    <li style="margin-bottom:5px;"><strong>You are a supervisor with regions of your own</strong>: those regions, same as any other owner, with a link to the merged view across all of them (<a href="#manager-view" style="color:#2980b9;">&sect;14</a>).</li>
    <li style="margin-bottom:5px;"><strong>You hold the personal-claim permission and own no region</strong>: your own personal list, which starts empty and fills as you pull quotes into it (<a href="#manager-view" style="color:#2980b9;">&sect;14</a>).</li>
    <li style="margin-bottom:5px;"><strong>You are a supervisor or admin with neither of those</strong>: the merged view across every region.</li>
    <li style="margin-bottom:5px;"><strong>You are on the post-sale team and own no region</strong>: the Payment Deadline queue, which is your home page (<a href="#payment-deadline" style="color:#2980b9;">&sect;12</a>).</li>
    <li><strong>None of the above</strong>: a "No queue assigned" message rather than an empty queue.</li>
  </ul>
  <div class="warn"><strong>"Region ownership conflict" instead of a queue?</strong> That means the CRM's Auto-Assign Rules name two different people as owner of the same region, so the system refuses to guess and leaves it unowned. It is a rule to fix, not a problem with your account. See <a href="#regions" style="color:#2980b9;">&sect;3</a>.</div>
  <div class="tip"><strong>Open it early.</strong> Your queue for the day is locked in the moment you first open the page, so open it before you start calling to make sure it's ready.</div>
</div>

<!-- ══ 3. REGIONS & OWNERSHIP ══ -->
<div class="section" id="regions">
  <h2>3. Regions &amp; Ownership</h2>
  <p>Quotes are not shared out by count, and they are not split by quote type. <strong>A quote belongs to whoever owns its region</strong>, and nothing else decides it. That is why your Follow-up list mixes standard quotes and Groups &amp; MICE together: you are responsible for everything live in your patch, whatever shape it takes.</p>

  <h3>One owner per region</h3>
  <p>A person can own several regions. A region can never have several owners. If you cover more than one, they are worked as a single list against a single daily budget, and each row carries a small grey <span class="owner-badge">region</span> chip next to the organisation name so you can tell them apart.</p>
  <div class="note"><strong>Covering more regions does not give you a bigger day.</strong> The 42-quote budget is per person, not per region, and it is divided across whichever regions you hold, weighted by how much each one actually has to work. A busy region takes a larger share of the 42 than a quiet one, but the total is still 42.</div>

  <h3>Where the roster is kept</h3>
  <p>Who owns which region is maintained in the CRM itself, on the <strong>Auto-Assign Rules</strong> screen, the same place that already decides who a new quote gets stamped with. The queue reads it every morning. There is no separate list to keep in step here, and no setting inside the queue to change: if ownership needs to move, it moves in the CRM. The queue reads the new roster straight away, but each person's day is already frozen by then (<a href="#queue-sections" style="color:#2980b9;">&sect;5</a>), so the quotes themselves change hands on the next morning's build rather than mid-afternoon.</p>
  <p>Every morning the queue also writes that ownership onto the quotes themselves, so the region's owner shows as the assigned agent in the normal CRM screens too.</p>

  <h3>When two rules disagree</h3>
  <p>The Auto-Assign Rules screen can't stop two rules naming two different people for the same region. When that happens the queue <strong>does not pick one</strong>. It leaves the region unowned, says so in a red banner above the queue, and waits.</p>
  <p>That is deliberate. The CRM keeps no history of who a quote was assigned to, so the name on a quote today is the only record that exists. Writing the wrong owner across a whole region would overwrite that record permanently, with nothing to recover it from. The banner names the region and the people claiming it; fix the rule in the CRM and the region comes back on the next load.</p>
  <div class="warn">Quotes in an unowned region are not lost. They stay visible to supervisors and admins in the merged view, marked <span class="owner-badge">(unowned)</span>. Nobody can work them from their own page until the rule is fixed.</div>

  <h3>Internal and external agents</h3>
  <p>The CRM stores its own staff and its external sales agents in two separate fields, and region owners exist on both sides. Your ownership is written to whichever of the two you belong to, and the other field is left exactly as it was.</p>
  <p>So if you own a region as an external agent, you will often see <strong>the same name in both fields</strong> on a quote. That is expected, not a fault: the older value is the only surviving trace of who held the quote before, so the system never clears it.</p>
</div>

<!-- ══ 4. MAIN SCREEN ══ -->
<div class="section" id="main-screen">
  <h2>4. Main Screen</h2>
  <p>The queue sits below the dashboard's own navigation bar. At the top you'll see a one-line summary of your day, followed by the colour-coded sections underneath.</p>

  <div class="mockup">
    <div class="mockup-label">Main screen, region owner covering West and Gujarat</div>
    <div class="mockup-inner" style="padding:0;">
      <div class="q-panel">
        <div class="q-title-row">
          <h1>Follow-up Queue &mdash; Meera</h1>
          <button class="btn-inbound">Search quote</button>
          <button class="btn-inbound" style="margin-left:6px;">Leads</button>
          <button class="btn-inbound" style="margin-left:6px;">User manual</button>
        </div>
        <div class="q-meta">Tuesday, 23 June 2026 &nbsp;|&nbsp; 47 quotes today &nbsp;|&nbsp; <span class="pending">Carry-over: 5</span> &nbsp; SP1: 12 &nbsp; SP2: 14 &nbsp; SP3: 7 &nbsp; SP4: 9 &nbsp;|&nbsp; Called: <strong>10/47</strong> <span style="display:inline-block;width:72px;height:7px;background:#e0e0e0;border-radius:4px;overflow:hidden;vertical-align:middle"><span style="display:block;height:100%;width:21%;background:#334155;border-radius:4px"></span></span></div>
        <div class="section-toggle-mock sect-carryover"><span class="caret-mock collapsed"></span><span class="sect-title">Carry-over - Unfinished (oldest first)</span> <span class="sect-count">(5 quotes)</span><span class="sect-progress">Called: 1/5 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:20%;background:currentColor"></span></span></span></div>
        <div class="section-toggle-mock sect-sp1"><span class="caret-mock collapsed"></span><span class="sect-title">Scheduled follow-ups</span> <span class="sect-count">(12 quotes)</span><span class="sect-progress">Called: 4/12 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:33%;background:currentColor"></span></span></span></div>
        <div class="section-toggle-mock sect-sp2"><span class="caret-mock collapsed"></span><span class="sect-title">Recently created</span> <span class="sect-count">(14 quotes)</span><span class="sect-progress">Called: 3/14 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:21%;background:currentColor"></span></span></span></div>
        <div class="section-toggle-mock sect-sp3"><span class="caret-mock collapsed"></span><span class="sect-title">Upcoming trips</span> <span class="sect-count">(7 quotes)</span><span class="sect-progress">Called: 2/7 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:29%;background:currentColor"></span></span></span></div>
        <div class="section-toggle-mock sect-sp4"><span class="caret-mock collapsed"></span><span class="sect-title">Further out</span> <span class="sect-count">(9 quotes)</span><span class="sect-progress">Called: 0/9 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:0%;background:currentColor"></span></span></span></div>
      </div>
    </div>
  </div>

  <h3>Header elements</h3>
  <ul style="padding-left:20px; margin-bottom:12px; color:#374151;">
    <li style="margin-bottom:5px;"><strong>Queue title</strong> — your first name and today's date</li>
    <li style="margin-bottom:5px;"><strong>Quote counts</strong>: total and per-section breakdown, followed by a <strong>Called</strong> progress bar for the day</li>
    <li style="margin-bottom:5px;"><strong>Search quote</strong>: find any quote by number to log a call (for example an unsolicited incoming call) or review its history (see <a href="#search-quote" style="color:#2980b9;">&sect;10</a>)</li>
    <li style="margin-bottom:5px;"><strong>Leads</strong>: switches to the Leads queue, the enquiries that haven't been quoted yet (see <a href="#leads" style="color:#2980b9;">&sect;11</a>). The same button there brings you back.</li>
    <li style="margin-bottom:5px;"><strong>User manual</strong> — opens this guide in a new browser tab; your queue stays open and untouched behind it</li>
  </ul>
  <p>The rest of the controls appear only for the accounts that need them, so a plain region owner never sees them:</p>
  <ul style="padding-left:20px; margin-bottom:12px; color:#374151;">
    <li style="margin-bottom:5px;"><strong>Payment deadlines</strong> and <strong>Awaiting information</strong>: the post-sale team, supervisors and admins (<a href="#payment-deadline" style="color:#2980b9;">&sect;12</a>, <a href="#awaiting-info" style="color:#2980b9;">&sect;13</a>).</li>
    <li style="margin-bottom:5px;"><strong>See all regions</strong>: supervisors, to switch between their own queue and the merged one (<a href="#manager-view" style="color:#2980b9;">&sect;14</a>).</li>
    <li><strong>View as</strong> and <strong>Monitoring</strong>: admins only.</li>
  </ul>

  <p>Click any <strong>section heading</strong> to collapse or expand it. The coloured triangle points right when collapsed and down when expanded.</p>

  <h3>The grey chip beside an organisation</h3>
  <p>When more than one region is on screen, each organisation block carries a small grey chip with its <strong>region</strong>. In a merged supervisor or admin view the chip also carries the <strong>owner's first name</strong>, the two separated by a dot, so a row reads <span class="owner-badge">West &middot; Meera</span>. A region nobody owns shows <span class="owner-badge">West &middot; (unowned)</span> instead of a name.</p>
  <p>If you own a single region the chip doesn't appear at all: every row on screen is from the same place, so repeating it would only add noise.</p>
</div>

<!-- ══ 5. QUEUE SECTIONS ══ -->
<div class="section" id="queue-sections">
  <h2>5. Queue Sections</h2>
  <p>Quotes are sorted into five sections, in priority order. <strong>Work from top to bottom</strong> each day: Carry-over first, then Scheduled (SP1), Recently created (SP2), Upcoming trips (SP3), and Further out (SP4).</p>

  <div class="callout-grid">
    <div class="callout-item">
      <span class="callout-color" style="background:#92400e;"></span>
      <strong style="color:#92400e;">Carry-over</strong>
      <p>Quotes from previous days that never got worked. Oldest first. A "Days behind" column shows how long each has been waiting.</p>
    </div>
    <div class="callout-item">
      <span class="callout-color" style="background:#1C93C4;"></span>
      <strong style="color:#1C93C4;">Scheduled follow-ups (SP1)</strong>
      <p>A specific callback date was set, and that date has arrived (or passed). You or the client committed to this date.</p>
    </div>
    <div class="callout-item">
      <span class="callout-color" style="background:#F5A623;"></span>
      <strong style="color:#F5A623;">Recently created (SP2)</strong>
      <p>New quotes (created in the last 60 days) that have never been called. First contact. Newest first.</p>
    </div>
    <div class="callout-item">
      <span class="callout-color" style="background:#EF6C24;"></span>
      <strong style="color:#EF6C24;">Upcoming trips (SP3)</strong>
      <p>Trip date within 90 days, didn't qualify for SP1 or SP2. Closest trip date first.</p>
    </div>
    <div class="callout-item">
      <span class="callout-color" style="background:#4A7B8C;"></span>
      <strong style="color:#4A7B8C;">Further out (SP4)</strong>
      <p>Didn't qualify for SP1, SP2, or SP3 — already called, past the 60-day creation window, and no trip within 90 days. Backfills whatever's left of your daily budget. Oldest-neglected first.</p>
    </div>
  </div>

  <!-- Carry-over -->
  <h3 style="color:#92400e;">Carry-over</h3>
  <p>These are quotes that showed up in your queue on an earlier day but didn't get an outcome logged. The <strong>Days behind</strong> value turns <span style="color:#c0392b;font-weight:bold;">red</span> once it hits 3 days or more (orange before that).</p>

  <div class="mockup">
    <div class="mockup-label">Carry-over — example</div>
    <div class="mockup-inner" style="padding:0;">
      <div class="q-panel" style="padding:8px 14px;">
        <div class="section-toggle-mock sect-carryover" style="margin-top:0;"><span class="caret-mock"></span><span class="sect-title">Carry-over - Unfinished (oldest first)</span> <span class="sect-count">(3 quotes)</span><span class="sect-progress">Called: 1/3 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:33%;background:currentColor"></span></span></span></div>
      </div>
      <div class="overflow-x" style="padding:0 14px 14px;">
      <table class="q-table">
        <thead><tr>
          <th>Organisation</th><th>Quote #</th><th>Stage</th><th>Pax</th><th>Trip date</th><th>Contact</th><th>Mobile</th><th>Region</th><th>Days behind</th><th>Calls</th><th>Created</th><th>Priority</th><th>Action</th>
        </tr></thead>
        <tbody>
          <tr class="org-row">
            <td>Royal Tours India</td>
            <td><a href="#">TDU00312</a></td>
            <td>Created</td><td>4</td><td>10 Aug 2026</td>
            <td>Amit Sharma</td><td>+91 98765 43210</td><td>North</td>
            <td><span class="days-overdue">5 days ago</span></td><td>0</td><td>12 May 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
          <tr>
            <td></td>
            <td><a href="#">TDU00313</a></td>
            <td>Requote</td><td>2</td><td>10 Aug 2026</td>
            <td>Amit Sharma</td><td>+91 98765 43210</td><td>North</td>
            <td><span class="days-overdue">5 days ago</span></td><td>1</td><td>14 May 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
          <tr class="org-row">
            <td>Heritage Holidays</td>
            <td><a href="#">TDU00285</a></td>
            <td>Created</td><td>6</td><td>22 Sep 2026</td>
            <td>Priya Nair</td><td>+91 98765 11111</td><td>South</td>
            <td><span class="days-today">2 days ago</span></td><td>0</td><td>20 May 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <!-- SP1 -->
  <h3 style="color:#1C93C4;">Scheduled follow-ups (SP1)</h3>
  <p>A specific callback date was set (via the <em>Next call</em> or <em>Inbound call</em> outcome) and that date has arrived or passed. The <strong>Due</strong> column shows how overdue the call is:</p>
  <ul style="padding-left:20px; margin-bottom:12px; color:#374151;">
    <li style="margin-bottom:4px;"><span class="days-soon">Green</span> — due today</li>
    <li style="margin-bottom:4px;"><span class="days-today">Orange</span> — 1–2 days overdue</li>
    <li><span class="days-overdue">Red</span> — 3+ days overdue</li>
  </ul>

  <div class="mockup">
    <div class="mockup-label">Scheduled follow-ups — example</div>
    <div class="mockup-inner" style="padding:0;">
      <div class="q-panel" style="padding:8px 14px;">
        <div class="section-toggle-mock sect-sp1" style="margin-top:0;"><span class="caret-mock"></span><span class="sect-title">Scheduled follow-ups</span> <span class="sect-count">(3 quotes)</span><span class="sect-progress">Called: 1/3 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:33%;background:currentColor"></span></span></span></div>
      </div>
      <div class="overflow-x" style="padding:0 14px 14px;">
      <table class="q-table">
        <thead><tr>
          <th>Organisation</th><th>Quote #</th><th>Stage</th><th>Pax</th><th>Trip date</th><th>Contact</th><th>Mobile</th><th>Region</th><th>Next call date</th><th>Due</th><th>Calls</th><th>Created</th><th>Priority</th><th>Action</th>
        </tr></thead>
        <tbody>
          <tr class="org-row">
            <td>Sunrise Travel Agency</td>
            <td><a href="#">TDU00456</a></td>
            <td>Created</td><td>2</td><td>15 Jul 2026</td>
            <td>Rajesh Kumar</td><td>+91 98765 00001</td><td>North</td>
            <td>23 Jun 2026</td><td><span class="days-soon">Today</span></td><td>3</td><td>02 Jun 2026</td>
            <td><select class="prio-sel"><option>Blank</option><option>Not connected</option><option>Low</option><option selected>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
          <tr class="org-row">
            <td>Blue Horizon Tours</td>
            <td><a href="#">TDU00441</a></td>
            <td>Requote</td><td>3</td><td>05 Aug 2026</td>
            <td>Sunita Verma</td><td>+91 98765 00002</td><td>West</td>
            <td>21 Jun 2026</td><td><span class="days-today">2 days ago</span></td><td>2</td><td>28 May 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
          <tr class="org-row">
            <td>Golden Gate Travels</td>
            <td><a href="#">TDU00398</a></td>
            <td>Created</td><td>5</td><td>30 Jul 2026</td>
            <td>Vikram Singh</td><td>+91 98765 00003</td><td>South</td>
            <td>14 Jun 2026</td><td><span class="days-overdue">9 days ago</span></td><td>1</td><td>20 May 2026</td>
            <td><select class="prio-sel"><option>Blank</option><option selected>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <!-- SP2 -->
  <h3 style="color:#F5A623;">Recently created (SP2)</h3>
  <p>Quotes created in the last 60 days with <strong>no calls on record</strong>. These are first-contact quotes, so the newest enquiries sit at the top.</p>

  <div class="mockup">
    <div class="mockup-label">Recently created — example</div>
    <div class="mockup-inner" style="padding:0;">
      <div class="q-panel" style="padding:8px 14px;">
        <div class="section-toggle-mock sect-sp2" style="margin-top:0;"><span class="caret-mock"></span><span class="sect-title">Recently created</span> <span class="sect-count">(2 quotes)</span><span class="sect-progress">Called: 0/2 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:0%;background:currentColor"></span></span></span></div>
      </div>
      <div class="overflow-x" style="padding:0 14px 14px;">
      <table class="q-table">
        <thead><tr>
          <th>Organisation</th><th>Quote #</th><th>Stage</th><th>Pax</th><th>Trip date</th><th>Contact</th><th>Mobile</th><th>Region</th><th>Calls</th><th>Created</th><th>Priority</th><th>Action</th>
        </tr></thead>
        <tbody>
          <tr class="org-row">
            <td>Dream Destinations</td>
            <td><a href="#">TDU00501</a></td>
            <td>Created</td><td>2</td><td>12 Sep 2026</td>
            <td>Meena Patel</td><td>+91 98765 00010</td><td>North</td>
            <td>0</td><td>20 Jun 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
          <tr class="org-row">
            <td>Ocean Breeze Travels</td>
            <td><a href="#">TDU00498</a></td>
            <td>Created</td><td>4</td><td>25 Aug 2026</td>
            <td>Anand Rao</td><td>+91 98765 00011</td><td>West</td>
            <td>0</td><td>18 Jun 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <!-- SP3 -->
  <h3 style="color:#EF6C24;">Upcoming trips (SP3)</h3>
  <p>Trip start date within the next 90 days, and the quote didn't already qualify for SP1 or SP2. Time-sensitive, since the client's travel date is getting close. Closest trip date first.</p>

  <div class="mockup">
    <div class="mockup-label">Upcoming trips — example</div>
    <div class="mockup-inner" style="padding:0;">
      <div class="q-panel" style="padding:8px 14px;">
        <div class="section-toggle-mock sect-sp3" style="margin-top:0;"><span class="caret-mock"></span><span class="sect-title">Upcoming trips</span> <span class="sect-count">(2 quotes)</span><span class="sect-progress">Called: 0/2 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:0%;background:currentColor"></span></span></span></div>
      </div>
      <div class="overflow-x" style="padding:0 14px 14px;">
      <table class="q-table">
        <thead><tr>
          <th>Organisation</th><th>Quote #</th><th>Stage</th><th>Pax</th><th>Trip date</th><th>Contact</th><th>Mobile</th><th>Region</th><th>Calls</th><th>Created</th><th>Priority</th><th>Action</th>
        </tr></thead>
        <tbody>
          <tr class="org-row">
            <td>Wanderlust India</td>
            <td><a href="#">TDU00420</a></td>
            <td>Requote</td><td>3</td><td>05 Jul 2026</td>
            <td>Kavya Iyer</td><td>+91 98765 00020</td><td>South</td>
            <td>2</td><td>10 Apr 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
          <tr class="org-row">
            <td>Taj Travel House</td>
            <td><a href="#">TDU00435</a></td>
            <td>Created</td><td>6</td><td>19 Jul 2026</td>
            <td>Ravi Menon</td><td>+91 98765 00021</td><td>East</td>
            <td>1</td><td>15 Apr 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <!-- SP4 -->
  <h3 style="color:#4A7B8C;">Further out (SP4)</h3>
  <p>Everything left over: didn't match SP1 (nothing due), SP2 (already called, or older than the 60-day creation window), or SP3 (no trip within 90 days). It fills whatever's left of your daily budget once the sections above are full, so these quotes don't sit forgotten. Oldest quote first.</p>

  <div class="mockup">
    <div class="mockup-label">Further out — example</div>
    <div class="mockup-inner" style="padding:0;">
      <div class="q-panel" style="padding:8px 14px;">
        <div class="section-toggle-mock sect-sp4" style="margin-top:0;"><span class="caret-mock"></span><span class="sect-title">Further out</span> <span class="sect-count">(2 quotes)</span><span class="sect-progress">Called: 0/2 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:0%;background:currentColor"></span></span></span></div>
      </div>
      <div class="overflow-x" style="padding:0 14px 14px;">
      <table class="q-table">
        <thead><tr>
          <th>Organisation</th><th>Quote #</th><th>Stage</th><th>Pax</th><th>Trip date</th><th>Contact</th><th>Mobile</th><th>Region</th><th>Calls</th><th>Created</th><th>Priority</th><th>Action</th>
        </tr></thead>
        <tbody>
          <tr class="org-row">
            <td>Silver Line Travels</td>
            <td><a href="#">TDU00201</a></td>
            <td>Created</td><td>2</td><td>—</td>
            <td>Neha Joshi</td><td>+91 98765 00050</td><td>West</td>
            <td>1</td><td>02 Feb 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
          <tr class="org-row">
            <td>Coastal Journeys</td>
            <td><a href="#">TDU00187</a></td>
            <td>Requote</td><td>3</td><td>—</td>
            <td>Anil Kapoor</td><td>+91 98765 00051</td><td>North</td>
            <td>2</td><td>18 Jan 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>

</div>

<!-- ══ 6. ORGANISATION GROUPING ══ -->
<div class="section" id="grouping">
  <h2>6. Organisation Grouping</h2>
  <p>If an organisation has more than one qualifying quote, <strong>all their quotes appear together as a block</strong>, anchored at the highest priority any of their quotes reaches. That way you handle everything for that client in a single phone call, logging each quote separately.</p>
  <p>The organisation name (in bold) only shows on the first row of the block; the rows underneath leave that cell empty. Each quote still gets its own <button class="log-btn" style="font-size:0.72rem;">Log</button> button and counts as one call slot in your daily budget. (See the Carry-over example above: TDU00312 and TDU00313 are two quotes for the same org, Royal Tours India.)</p>
  <div class="note"><strong>One call, multiple quotes.</strong> If an org has one quote in SP1 and another in SP2, both appear together in the SP1 block. Call the client once, then log two separate outcomes.</div>
  <div class="note"><strong>Grouping happens within a region.</strong> If you cover several regions and an agency has quotes in two of them, you'll see that agency as two separate blocks, one per region, each with its own chip (<a href="#regions" style="color:#2980b9;">&sect;3</a>). They are still one phone call: deal with both blocks together while you have the agency on the line.</div>
</div>

<!-- ══ 7. LOGGING AN OUTCOME ══ -->
<div class="section" id="logging">
  <h2>7. Logging an Outcome</h2>
  <p>After you call (or try to call) a client, click the <button class="log-btn" style="font-size:0.82rem;">Log</button> button on that quote's row. A dialog opens where you record what happened.</p>

  <div class="mockup">
    <div class="mockup-label">Log Outcome dialog</div>
    <div class="mockup-inner">
      <div class="modal-mock">
        <h3>Log Outcome &mdash; TDU27033G</h3>
        <div class="form-row">
          <label>Outcome</label>
          <select>
            <option selected>Next call</option>
            <option>No answer + email sent</option>
            <option>Interested</option>
            <option>Inbound call</option>
            <option>Requote</option>
            <option>Rejected</option>
            <option>Accepted</option>
          </select>
        </div>
        <div class="form-row" style="flex-wrap:wrap;">
          <label>Call back on</label>
          <input type="text" value="24/06/2026 03:59 PM" style="flex:0 0 auto;width:160px;" readonly>
          <span style="flex:0 0 auto;font-size:0.8rem;margin-left:8px;color:#666;white-space:nowrap;">6/10 scheduled</span>
        </div>
        <div class="form-row">
          <label>Channel</label>
          <select><option selected>Phone</option><option>Email</option><option>WhatsApp</option><option>Other</option></select>
        </div>
        <div class="form-row" style="align-items:flex-start;">
          <label style="padding-top:4px;">Notes</label>
          <textarea placeholder="What was discussed…"></textarea>
        </div>
        <div class="dialog-actions">
          <button class="btn-cancel">Cancel</button>
          <button class="btn-save">Save outcome</button>
        </div>
      </div>
    </div>
  </div>

  <p>There are three kinds of outcome. <strong>Call outcomes</strong> (Next call, No answer + email sent, Interested, Inbound call) log the contact attempt and schedule the quote's return to the queue, without touching the CRM stage. <strong>Terminal outcomes</strong> (Rejected, Accepted) close the quote out for good and change its stage in the CRM — use these once a call has actually settled the quote's fate, not for a routine "keeping in touch" update. <strong>Requote</strong> sits in between: it changes the CRM stage immediately like a terminal outcome, but also schedules the quote's return like a call outcome, so it needs a callback date and notes and the quote stays alive in your queue.</p>

  <h3>Call outcomes</h3>
  <table class="outcome-ref">
    <thead><tr><th style="width:170px;">Outcome</th><th>When to use</th><th>What happens next</th></tr></thead>
    <tbody>
      <tr>
        <td><span class="ob ob-next">Next call</span></td>
        <td>You spoke to the client and agreed on a specific callback date.</td>
        <td>Quote re-enters <strong>Scheduled (SP1)</strong> on the chosen date. <em>Date required.</em></td>
      </tr>
      <tr>
        <td><span class="ob ob-noanswer">No answer + email sent</span></td>
        <td>No one answered. You sent a follow-up email.</td>
        <td>Quote returns in <strong>1 day</strong> (auto-filled). Channel auto-set to Email. Adjust if needed.</td>
      </tr>
      <tr>
        <td><span class="ob ob-interested">Interested</span></td>
        <td>Positive contact — client is interested but no date was set.</td>
        <td>Quote returns in <strong>2 days</strong> (auto-filled), sorted to the top of SP1. Priority is auto-set to High.</td>
      </tr>
      <tr>
        <td><span class="ob ob-inbound">Inbound call</span></td>
        <td>The client rang you unsolicited.</td>
        <td>If you agreed on a callback date, the quote re-enters <strong>SP1</strong> on that date, same as Next call. If not, leave the date blank: nothing about the quote's due date changes, and the row stays exactly as it was. <em>Date is optional.</em></td>
      </tr>
    </tbody>
  </table>

  <div class="tip"><strong>Tip:</strong> The date field auto-fills based on the outcome (No answer +1 day, Interested +2 days). For <em>Next call</em> and <em>Inbound call</em> you pick the date yourself, or for Inbound call leave it blank, you can always adjust it before saving. The field also renames itself: it reads <em>Call back on</em> when you promised a call, and <em>Back in queue on</em> for No answer and Interested, where the date is just when the quote resurfaces.</div>
  <div class="note"><strong>Callback date limit:</strong> The date picker won't let you schedule a callback more than 3 weeks ahead. If a client asks to be contacted further out than that, set the date to 3 weeks and add a note explaining why.</div>

  <h3>Daily schedule cap</h3>
  <p>At most <strong>10 calls can be scheduled for the same future date</strong> on one person's book, which keeps any one day from becoming overloaded. It applies to every outcome that carries a callback date: <em>Next call</em>, <em>No answer + email sent</em>, <em>Interested</em>, <em>Inbound call</em>, and <em>Requote</em>. The cap follows <strong>the quote's owner</strong>, so it never blocks you because of somebody else's schedule, and if you log a call on a quote from another region the slot is counted against that region's owner, not against you.</p>

  <h3>Calendar view</h3>
  <p>Clicking into the date field opens a calendar instead of the browser's own date picker, so you can see the load across every day at a glance before choosing one. Each day is coloured by how full it already is <strong>for whoever owns the quote</strong>, with a small count underneath. On your own quotes that is your load; on a quote you looked up from another region it is that owner's:</p>

  <div class="mockup">
    <div class="mockup-label">Call back on — calendar view (colour-coded by schedule load)</div>
    <div class="mockup-inner">
      <div class="cal-mock">
        <div class="cal-mock-head">
          <span class="nav-arrow">&lsaquo;</span>
          <span class="month-pill">July</span>
          <span class="cal-year">2026</span>
          <span class="nav-arrow">&rsaquo;</span>
        </div>
        <div class="cal-mock-dow-row">
          <div class="cal-mock-dow">Mon</div><div class="cal-mock-dow">Tue</div><div class="cal-mock-dow">Wed</div><div class="cal-mock-dow">Thu</div><div class="cal-mock-dow">Fri</div><div class="cal-mock-dow">Sat</div><div class="cal-mock-dow">Sun</div>
        </div>
        <div class="cal-mock-grid">
          <!-- Week 1 — June overflow + before minDate (today+1) -->
          <div class="cal-day-mock cal-day-off"><span class="num">29</span><span class="cnt">&nbsp;</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">30</span><span class="cnt">&nbsp;</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">1</span><span class="cnt">&nbsp;</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">2</span><span class="cnt">&nbsp;</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">3</span><span class="cnt">&nbsp;</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">4</span><span class="cnt">&nbsp;</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">5</span><span class="cnt">&nbsp;</span></div>
          <!-- Week 2 — 6-10 still before minDate; 11 is the first selectable day; 12 is Sunday -->
          <div class="cal-day-mock cal-day-off"><span class="num">6</span><span class="cnt">&nbsp;</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">7</span><span class="cnt">&nbsp;</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">8</span><span class="cnt">&nbsp;</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">9</span><span class="cnt">&nbsp;</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">10</span><span class="cnt">&nbsp;</span></div>
          <div class="cal-day-mock cal-day-safe"><span class="num">11</span><span class="cnt">4/10</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">12</span><span class="cnt">&nbsp;</span></div>
          <!-- Week 3 -->
          <div class="cal-day-mock cal-day-full"><span class="num">13</span><span class="cnt">10/10</span></div>
          <div class="cal-day-mock cal-day-safe"><span class="num">14</span><span class="cnt">2/10</span></div>
          <div class="cal-day-mock cal-day-warn"><span class="num">15</span><span class="cnt">6/10</span></div>
          <div class="cal-day-mock cal-day-safe"><span class="num">16</span><span class="cnt">0/10</span></div>
          <div class="cal-day-mock cal-day-safe"><span class="num">17</span><span class="cnt">2/10</span></div>
          <div class="cal-day-mock cal-day-safe"><span class="num">18</span><span class="cnt">4/10</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">19</span><span class="cnt">&nbsp;</span></div>
          <!-- Week 4 -->
          <div class="cal-day-mock cal-day-safe"><span class="num">20</span><span class="cnt">4/10</span></div>
          <div class="cal-day-mock cal-day-warn"><span class="num">21</span><span class="cnt">6/10</span></div>
          <div class="cal-day-mock cal-day-safe"><span class="num">22</span><span class="cnt">2/10</span></div>
          <div class="cal-day-mock cal-day-safe"><span class="num">23</span><span class="cnt">0/10</span></div>
          <div class="cal-day-mock cal-day-warn"><span class="num">24</span><span class="cnt">8/10</span></div>
          <div class="cal-day-mock cal-day-safe"><span class="num">25</span><span class="cnt">2/10</span></div>
          <div class="cal-day-mock cal-day-off"><span class="num">26</span><span class="cnt">&nbsp;</span></div>
          <!-- Week 5 — 1-2 Aug overflow, still within range so still coloured, just dimmer -->
          <div class="cal-day-mock cal-day-full"><span class="num">27</span><span class="cnt">10/10</span></div>
          <div class="cal-day-mock cal-day-safe"><span class="num">28</span><span class="cnt">0/10</span></div>
          <div class="cal-day-mock cal-day-full"><span class="num">29</span><span class="cnt">10/10</span></div>
          <div class="cal-day-mock cal-day-safe"><span class="num">30</span><span class="cnt">2/10</span></div>
          <div class="cal-day-mock cal-day-safe"><span class="num">31</span><span class="cnt">4/10</span></div>
          <div class="cal-day-mock cal-day-safe cal-day-nextmonth"><span class="num">1</span><span class="cnt">0/10</span></div>
          <div class="cal-day-mock cal-day-off cal-day-nextmonth"><span class="num">2</span><span class="cnt">&nbsp;</span></div>
          <!-- Week 6 — August overflow -->
          <div class="cal-day-mock cal-day-safe cal-day-nextmonth"><span class="num">3</span><span class="cnt">2/10</span></div>
          <div class="cal-day-mock cal-day-safe cal-day-nextmonth"><span class="num">4</span><span class="cnt">0/10</span></div>
          <div class="cal-day-mock cal-day-full cal-day-nextmonth"><span class="num">5</span><span class="cnt">10/10</span></div>
          <div class="cal-day-mock cal-day-safe cal-day-nextmonth"><span class="num">6</span><span class="cnt">2/10</span></div>
          <div class="cal-day-mock cal-day-safe cal-day-nextmonth"><span class="num">7</span><span class="cnt">0/10</span></div>
          <div class="cal-day-mock cal-day-safe cal-day-nextmonth"><span class="num">8</span><span class="cnt">2/10</span></div>
          <div class="cal-day-mock cal-day-off cal-day-nextmonth"><span class="num">9</span><span class="cnt">&nbsp;</span></div>
        </div>
        <div class="cal-mock-time">
          <input class="tbox" value="12" readonly><span class="sep">:</span><input class="tbox" value="13" readonly>
          <span class="ampm">PM</span>
        </div>
        <div class="cal-mock-confirm">OK &#10003;</div>
      </div>
      <div class="cal-legend">
        <span><span class="dot" style="background:#3f9142;"></span>Plenty of room</span>
        <span><span class="dot" style="background:#c99a1c;"></span>Filling up</span>
        <span><span class="dot" style="background:#c0392b;"></span>Full — can't select</span>
        <span><span class="dot" style="background:#d8d8d8;"></span>Sunday, or outside the bookable range</span>
      </div>
    </div>
  </div>

  <p>The calendar is docked directly under the date field — clicking into it opens this view instead of the browser's own picker. It includes its own time picker and an <strong>OK</strong> button at the bottom to confirm the selection — that's the only "OK", there's no separate one outside the calendar. Full days (and Sundays) simply can't be clicked — there's nothing to undo, since the calendar won't let you pick them in the first place. Underneath the calendar, a small live counter also confirms the exact load for whichever date and time you've selected, for example <strong>6/10 scheduled</strong>:</p>

  <div class="mockup">
    <div class="mockup-label">Live counter next to the field — day already full</div>
    <div class="mockup-inner">
      <div class="modal-mock" style="max-width:420px;">
        <div class="form-row" style="margin-bottom:0;flex-wrap:wrap;">
          <label>Call back on</label>
          <input type="text" value="15/07/2026 09:00 AM" style="flex:0 0 auto;width:160px;" readonly>
          <span style="flex:0 0 100%;margin-left:110px;font-size:0.8rem;color:#c0392b;">10/10 scheduled — next available: 2026-07-16</span>
        </div>
      </div>
    </div>
  </div>

  <p>If a full date does get submitted anyway, the save is blocked and you'll see an error. Pick a different date and save again.</p>
  <div class="warn"><strong>Notes are required</strong> for every call outcome, and for Requote too. Write a brief summary of what was discussed or why you couldn't reach the client.</div>

  <h3>Requote, Rejected &amp; Accepted — outcomes that change the CRM stage</h3>
  <p>These are how you now change a quote's stage — there is no separate stage dropdown on the row. Pick the matching outcome in the same Log dialog and the stage updates in the CRM when you save.</p>

  <table class="outcome-ref">
    <thead><tr><th style="width:170px;">Outcome</th><th>When to use</th><th>What happens next</th></tr></thead>
    <tbody>
      <tr>
        <td><span class="ob ob-requote">Requote</span></td>
        <td>The client wants changes to the quote. Only appears as an option when the quote's current stage is <em>Created</em>.</td>
        <td>Stage changes to <strong>Requote</strong> straight away. Also schedules the quote's return like <em>Next call</em> — pick a <strong>callback date</strong> (subject to the same daily schedule cap) and write <strong>notes</strong> on what the client asked for. <em>Date and notes required.</em> The row stays editable ("✓ Done (edit)") — not locked.</td>
      </tr>
      <tr>
        <td><span class="ob ob-rejected">Rejected</span></td>
        <td>The client has decided not to go ahead.</td>
        <td>Stage changes to <strong>Rejected</strong>. You must pick a <strong>reason</strong> (see below) — this feeds reporting on why quotes are lost.</td>
      </tr>
      <tr>
        <td><span class="ob ob-accepted">Accepted</span></td>
        <td>The client has confirmed and paid (or booking is otherwise ready to proceed).</td>
        <td>Stage changes to <strong>Accepted</strong>. May be blocked with a warning if passenger or payment details are incomplete — see below.</td>
      </tr>
    </tbody>
  </table>

  <p>Rejected and Accepted don't need notes, a channel, or a callback date, so the row simply shows the new stage as a locked, done badge that can't be edited (see <a href="#done-state" style="color:#2980b9;">&sect;8</a>). Requote is the exception: because it also has to reschedule the quote's return, it behaves like a call outcome in the dialog (date, channel, and notes all apply, and it counts against your daily schedule cap) while still changing the CRM stage the moment you save.</p>

  <div class="mockup">
    <div class="mockup-label">Rejected — reason required</div>
    <div class="mockup-inner">
      <div class="modal-mock">
        <h3>Log Outcome &mdash; TDU00285</h3>
        <div class="form-row">
          <label>Outcome</label>
          <select><option>Next call</option><option>No answer + email sent</option><option>Interested</option><option>Inbound call</option><option selected>Rejected</option><option>Accepted</option></select>
        </div>
        <div class="form-row">
          <label>Channel</label>
          <select><option selected>Phone</option><option>Email</option><option>WhatsApp</option><option>Other</option></select>
        </div>
        <div class="form-row">
          <label>Reason</label>
          <select>
            <option selected>&mdash; select reason &mdash;</option>
            <optgroup label="Company — Avoidable">
              <option>Lost to response time</option>
            </optgroup>
            <optgroup label="Market / Competition">
              <option>Too expensive</option>
              <option>Confirmed with competitor (ITO)</option>
            </optgroup>
            <optgroup label="Client — Out of our hands">
              <option>Trip cancelled</option>
              <option>Visa issues</option>
              <option>No response</option>
              <option>Destination change</option>
              <option>Not ready to travel</option>
            </optgroup>
            <optgroup label="Other">
              <option>Other (specify below)</option>
            </optgroup>
          </select>
        </div>
        <div class="dialog-actions">
          <button class="btn-cancel">Cancel</button>
          <button class="btn-save">Save outcome</button>
        </div>
      </div>
    </div>
  </div>

  <p>The reason list is grouped into four categories, shown as headings inside the dropdown itself:</p>

  <div class="mockup">
    <div class="mockup-label">Reason dropdown — expanded</div>
    <div class="mockup-inner">
      <div class="reason-drop">
        <div class="reason-drop-closed">&mdash; select reason &mdash;</div>
        <div class="reason-drop-list">
          <div class="reason-drop-selected">&mdash; select reason &mdash;</div>
          <div class="reason-drop-group">Company &mdash; Avoidable</div>
          <div class="reason-drop-option">Lost to response time</div>
          <div class="reason-drop-group">Market / Competition</div>
          <div class="reason-drop-option">Too expensive</div>
          <div class="reason-drop-option">Confirmed with competitor (ITO)</div>
          <div class="reason-drop-group">Client &mdash; Out of our hands</div>
          <div class="reason-drop-option">Trip cancelled</div>
          <div class="reason-drop-option">Visa issues</div>
          <div class="reason-drop-option">No response</div>
          <div class="reason-drop-option">Destination change</div>
          <div class="reason-drop-option">Not ready to travel</div>
          <div class="reason-drop-group">Other</div>
          <div class="reason-drop-option">Other (specify below)</div>
        </div>
      </div>
    </div>
  </div>

  <p><strong>Company — Avoidable</strong> covers a slow response on our side; <strong>Market / Competition</strong> covers price and losing to a competitor; <strong>Client — Out of our hands</strong> covers trip cancellations, visa issues, no response, destination changes, and not being ready to travel; <strong>Other</strong> opens a free-text box to describe the reason yourself.</p>

  <div class="warn"><strong>Accepted can be blocked.</strong> If passenger details or payment are incomplete, selecting <em>Accepted</em> shows a warning and you should contact ops before proceeding rather than forcing it through.</div>
</div>

<!-- ══ 8. THE DONE STATE ══ -->
<div class="section" id="done-state">
  <h2>8. The Done State</h2>
  <p>Once you log a <strong>call outcome</strong> on a quote, its row turns grey and the button changes to <button class="log-btn done" style="font-size:0.82rem;">&#10003; Done <span style="font-size:0.7rem;text-decoration:underline;margin-left:4px;">edit</span></button>. This sticks for the rest of the day, even if you reload the page.</p>
  <p>Clicking <strong>Done</strong> reopens the dialog with your previous notes pre-filled, so you can correct or update the entry instead of creating a duplicate.</p>

  <div class="mockup">
    <div class="mockup-label">Done row — example (greyed out after logging a call outcome)</div>
    <div class="mockup-inner" style="padding:14px;">
      <div class="overflow-x">
      <table class="q-table">
        <thead><tr>
          <th>Organisation</th><th>Quote #</th><th>Stage</th><th>Pax</th><th>Trip date</th><th>Contact</th><th>Mobile</th><th>Region</th><th>Calls</th><th>Created</th><th>Priority</th><th>Action</th>
        </tr></thead>
        <tbody>
          <tr class="done-row org-row">
            <td>Sunrise Travel Agency</td>
            <td><a href="#">TDU00456</a></td>
            <td>Created</td><td>2</td><td>15 Jul 2026</td>
            <td>Rajesh Kumar</td><td>+91 98765 00001</td><td>North</td>
            <td>4</td><td>02 Jun 2026</td>
            <td><select class="prio-sel"><option>Blank</option><option>Not connected</option><option>Low</option><option selected>High</option></select></td>
            <td><button class="log-btn done">&#10003; Done <span style="font-size:0.7rem;text-decoration:underline;margin-left:4px;">edit</span></button><button class="inbound-add-btn">+ Inbound</button><button class="hist-btn">History</button></td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <p>A <strong>terminal outcome</strong> (Rejected or Accepted) looks different: the button becomes a fixed, coloured label showing the outcome, and it's <strong>not clickable</strong> — the stage change is final and can't be corrected from here. <strong>Requote is not terminal</strong> — even though it also changes the CRM stage, its row shows the normal green "✓ Done (edit)" button, and clicking it reopens the dialog with your callback date and notes pre-filled, same as any call outcome.</p>

  <h3>Logging a second call the same day</h3>
  <p>A worked row shows a small <button class="inbound-add-btn" style="margin:0;">+ Inbound</button> button next to Done. It's for one thing only: the client called you again the same day. Clicking it opens a stripped-down dialog with the outcome fixed to <em>Inbound call</em> and no date field, so it just records that a second contact happened. It always adds a new entry rather than touching the first one, and it never affects the row's Done state or what the quote owes.</p>
  <p>If you need to correct anything about a call you already logged, including its callback date, use <strong>Edit</strong> on the Done button, not <strong>+ Inbound</strong>. Edit always opens the call that actually holds the quote's callback date, even on a day with more than one call logged, and its notes and date are the ones you can change. A quick "client called again" entry logged through <strong>+ Inbound</strong> is not itself editable afterwards: if you typed something wrong there, log another one rather than trying to fix it.</p>

  <div class="mockup">
    <div class="mockup-label">Done row — example (after a stage outcome)</div>
    <div class="mockup-inner" style="padding:14px;">
      <div class="overflow-x">
      <table class="q-table">
        <thead><tr>
          <th>Organisation</th><th>Quote #</th><th>Stage</th><th>Pax</th><th>Trip date</th><th>Contact</th><th>Mobile</th><th>Region</th><th>Calls</th><th>Created</th><th>Priority</th><th>Action</th>
        </tr></thead>
        <tbody>
          <tr class="done-row org-row">
            <td>Heritage Holidays</td>
            <td><a href="#">TDU00285</a></td>
            <td>Rejected</td><td>6</td><td>22 Sep 2026</td>
            <td>Priya Nair</td><td>+91 98765 11111</td><td>South</td>
            <td>2</td><td>20 May 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn" disabled style="background:#fff;color:#c0392b;border-color:#c0392b;cursor:default">&#10003; Rejected</button><button class="hist-btn">History</button></td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>

<!-- ══ 9. CALL HISTORY ══ -->
<div class="section" id="history">
  <h2>9. Call History</h2>
  <p>Every quote keeps a full call history. Click <button class="hist-btn" style="font-size:0.82rem;margin:0;">History</button> on any row to open a panel showing all past interactions with that client. Click it again to close it.</p>

  <div class="mockup">
    <div class="mockup-label">History panel — expanded below a quote row</div>
    <div class="mockup-inner" style="padding:14px;">
      <div class="overflow-x">
      <table class="q-table">
        <tbody>
          <tr class="org-row">
            <td>Sunrise Travel Agency</td>
            <td><a href="#">TDU00456</a></td>
            <td>Created</td><td>2</td><td>15 Jul 2026</td>
            <td>Rajesh Kumar</td><td>+91 98765 00001</td><td>North</td><td>3</td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
          <tr>
            <td colspan="10" class="hist-panel-mock">
              <table>
                <thead><tr>
                  <th>Called on</th><th>Description</th><th>Agent</th><th>Outcome</th><th>Channel</th><th>Next call</th>
                </tr></thead>
                <tbody>
                  <tr>
                    <td>20 Jun 2026 10:30</td>
                    <td>Client interested in Sydney package, wants revised quote with airport transfers</td>
                    <td>MeeraR</td><td>Interested</td><td>Phone</td><td>22 Jun 2026 09:00</td>
                  </tr>
                  <tr>
                    <td>18 Jun 2026 14:15</td>
                    <td>No answer — sent email with query</td>
                    <td>MeeraR</td><td>No answer, email sent</td><td>Email</td><td>19 Jun 2026 09:00</td>
                  </tr>
                  <tr>
                    <td>15 Jun 2026 11:00</td>
                    <td>Quote sent. Waiting for the client updates.</td>
                    <td>MeeraR</td><td>Next call scheduled</td><td>Phone</td><td>18 Jun 2026 09:00</td>
                  </tr>
                </tbody>
              </table>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <div class="note"><strong>The "Called on" and "Next call" columns both show date and time</strong>: the exact time of the call, and the exact time the quote is due back in the queue.</div>
  <div class="note"><strong>History is shared.</strong> You see every call ever logged on the quote, whoever made it. That matters under the regional model: a quote you now own may have been worked for months by whoever held the region before you, and their calls are all here. Calls logged in the existing CRM follow-up tab also appear.</div>
  <div class="tip"><strong>Stays up to date automatically.</strong> If you already have a quote's history panel open and then log a new outcome on it, the panel refreshes itself with the new call — no need to close and reopen it.</div>
</div>

<!-- ══ 10. SEARCH QUOTE ══ -->
<div class="section" id="search-quote">
  <h2>10. Search Quote</h2>
  <p>Need to find a specific quote that isn't sitting in front of you right now, most often because a client rang in out of the blue? Use the <button class="btn-inbound" style="font-size:0.82rem;margin:0;">Search quote</button> button at the top of the page. It finds any quote by number, whatever region it sits in and whoever owns it.</p>

  <div class="mockup">
    <div class="mockup-label">Step 1 — Search by quote number</div>
    <div class="mockup-inner">
      <div class="modal-mock">
        <h3>Search Quote</h3>
        <div class="form-row">
          <label>Quote #</label>
          <input type="text" value="TDU00456" placeholder="e.g. TDU00123">
          <button class="hist-btn" style="margin:0;">Search</button>
        </div>
        <div class="dialog-actions">
          <button class="btn-cancel">Cancel</button>
        </div>
      </div>
    </div>
  </div>
  <div class="tip">You can leave off the "TDU" prefix: searching "00456" finds the same quote as "TDU00456". A trailing <strong>L</strong> is ignored too, so a Lead number copied straight off the screen ("TDU00456L") finds the right quote. See <a href="#leads" style="color:#2980b9;">&sect;11</a> for what that L means.</div>

  <div class="mockup">
    <div class="mockup-label">Step 2 — Quote found; review details and choose next action</div>
    <div class="mockup-inner">
      <div class="modal-mock">
        <h3>Search Quote</h3>
        <div class="search-result-mock">
          <table>
            <tr><td>Quote</td><td><a href="#">TDU00456</a></td></tr>
            <tr><td>Org</td><td>Sunrise Travel Agency</td></tr>
            <tr><td>Stage</td><td>Created</td></tr>
            <tr><td>Contact</td><td>Rajesh Kumar</td></tr>
            <tr><td>Mobile</td><td>+91 98765 00001</td></tr>
            <tr><td>Owner</td><td>Meera Raghavan</td></tr>
          </table>
          <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px;">
            <button class="hist-btn" style="margin:0;">History</button>
            <button class="btn-save">Log outcome</button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="note"><strong>Owner</strong> here reads the CRM's <em>internal</em> Sales Agent field. For most quotes that is the region's owner. For a region whose owner is an <em>external</em> agent, ownership is kept in the separate external field instead (<a href="#regions" style="color:#2980b9;">&sect;3</a>), so this line can still show an earlier agent. When you need a definite answer to "whose is this", go by the region, not by this name.</div>
  <div class="note">Finding somebody else's quote is a heads-up, not a block. You can read the history and log what the client told you either way.</div>
  <div class="note"><strong>Pull this quote to me</strong> <span class="role-badge role-supervisor">Supervisor</span> appears only for an account that has been granted the <strong>personal-claim</strong> permission, which today is one supervisor who sells without owning a region, and only on the <strong>Follow-up</strong> queue: the Leads page has no personal list and never shows the button. It is how they take a quote into their personal list. Region ownership is not moved from here, or from anywhere else in the queue: it follows the CRM's Auto-Assign Rules (<a href="#regions" style="color:#2980b9;">&sect;3</a>). See <a href="#manager-view" style="color:#2980b9;">&sect;14</a>.</div>

  <div class="mockup">
    <div class="mockup-label">Step 2b — History expanded (click History to toggle)</div>
    <div class="mockup-inner">
      <div class="modal-mock" style="max-width:780px;">
        <h3>Search Quote</h3>
        <div class="search-result-mock">
          <table>
            <tr><td>Quote</td><td><a href="#">TDU00456</a></td></tr>
            <tr><td>Org</td><td>Sunrise Travel Agency</td></tr>
            <tr><td>Stage</td><td>Created</td></tr>
            <tr><td>Contact</td><td>Rajesh Kumar</td></tr>
            <tr><td>Mobile</td><td>+91 98765 00001</td></tr>
            <tr><td>Owner</td><td>Meera Raghavan</td></tr>
          </table>
          <div style="margin-top:8px;overflow-x:auto;">
            <table style="width:100%;font-size:0.875rem;border-collapse:collapse;margin:6px 0">
              <thead><tr style="background:#eef2ff">
                <th style="text-align:left;padding:6px 10px;white-space:nowrap">Called on</th>
                <th style="text-align:left;padding:6px 10px">Description</th>
                <th style="text-align:left;padding:6px 10px;white-space:nowrap">Agent</th>
                <th style="text-align:left;padding:6px 10px;white-space:nowrap">Outcome</th>
                <th style="text-align:left;padding:6px 10px;white-space:nowrap">Channel</th>
                <th style="text-align:left;padding:6px 10px;white-space:nowrap">Next call</th>
              </tr></thead>
              <tbody>
                <tr>
                  <td style="padding:5px 10px;white-space:nowrap">20 Jun 2026 10:30</td>
                  <td style="padding:5px 10px">Client interested in Sydney package, wants revised quote with airport transfers</td>
                  <td style="padding:5px 10px">MeeraR</td>
                  <td style="padding:5px 10px">Interested</td>
                  <td style="padding:5px 10px">Phone</td>
                  <td style="padding:5px 10px;white-space:nowrap">22 Jun 2026 09:00</td>
                </tr>
                <tr>
                  <td style="padding:5px 10px;white-space:nowrap">18 Jun 2026 14:15</td>
                  <td style="padding:5px 10px">No answer — sent follow-up email with itinerary</td>
                  <td style="padding:5px 10px">MeeraR</td>
                  <td style="padding:5px 10px">No answer, email sent</td>
                  <td style="padding:5px 10px">Email</td>
                  <td style="padding:5px 10px;white-space:nowrap">19 Jun 2026 09:00</td>
                </tr>
                <tr>
                  <td style="padding:5px 10px;white-space:nowrap">15 Jun 2026 11:00</td>
                  <td style="padding:5px 10px">Quote sent. Waiting for client to confirm group size.</td>
                  <td style="padding:5px 10px">MeeraR</td>
                  <td style="padding:5px 10px">Next call scheduled</td>
                  <td style="padding:5px 10px">Phone</td>
                  <td style="padding:5px 10px;white-space:nowrap">18 Jun 2026 09:00</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px;">
            <button class="hist-btn" style="margin:0;">Hide history</button>
            <button class="btn-save">Log outcome</button>
          </div>
        </div>
        <div class="dialog-actions">
          <button class="btn-cancel">Cancel</button>
        </div>
      </div>
    </div>
  </div>

  <p>Click the <strong>quote number</strong> to open the quote in the main CRM, exactly like the quote numbers in your queue table. Handy when you need the full detail rather than just the summary above.</p>

  <p>After reviewing the history, click <strong>Log outcome</strong> to open the standard outcome dialog, same as logging from your queue table. Pick whichever outcome actually applies, including <strong>Inbound call</strong> if that's why you searched. <strong>Logging never changes who owns the quote.</strong> If it belongs to another region, your call is recorded on it and the quote stays where it is, on its owner's list, which is exactly what you want: they will see your notes in the history next time they ring.</p>

  <div class="note"><strong>No Log outcome button?</strong> The dialog only offers it while the quote is still one you can act on: a <strong>Lead</strong> being qualified, or a <strong>Created</strong> or <strong>Requote</strong> quote being followed up. For anything else (Accepted, Rejected, Delivered and so on) the button is replaced by a short line saying why. The quote isn't in any queue at that point, so there's no outcome left to log. History and the quote number still work as normal.</div>

  <h3>If the quote is already on your page and already worked</h3>
  <p>When the quote you searched for is sitting in today's own queue and you have already logged something on it, the result says so with a <strong>Today &nbsp;&#10003; Already worked</strong> line, and offers <strong>two</strong> buttons instead of one:</p>
  <ul style="padding-left:20px; margin-bottom:12px; color:#374151;">
    <li style="margin-bottom:6px;"><strong>Edit</strong>: reopens the call that holds the quote's callback date, so you can correct it. Same thing the row's own <em>Done (edit)</em> button does.</li>
    <li><strong>Log inbound call</strong>: adds a second, dateless entry recording that the client rang again. It changes nothing about what the quote owes, exactly like <strong>+ Inbound</strong> on the row (<a href="#done-state" style="color:#2980b9;">&sect;8</a>).</li>
  </ul>
  <div class="tip">That line only appears when the quote is on the page in front of you. A quote from another region isn't rendered here, so the dialog says nothing about it rather than guessing that nobody has worked it.</div>

  <div class="note">The dialog matches itself to the quote you actually found, not to the page you happened to be on. Search up a Lead from your Follow-up queue and you get the Lead outcomes and the shorter Lead callback window; search up an ordinary quote from the Leads queue and you get the normal ones.</div>
  <div class="tip">The history panel opens instantly after the first time you load it. If the quote is already in today's queue, its row updates to "&#10003; Done" automatically once you save, no page reload needed.</div>
</div>

<!-- ══ 11. LEADS QUEUE ══ -->
<div class="section" id="leads">
  <h2>11. Leads Queue <span class="role-badge role-owner">Region owner</span></h2>
  <p>A <strong>Lead</strong> is an enquiry that hasn't been quoted yet. It sits at the <strong>Lead</strong> stage in the CRM, one step before <strong>Created</strong>. Someone has asked for a rough price or made contact, but no real quote exists yet. The Leads queue is where you work those, so an enquiry that never turned into a quote doesn't quietly get counted as a lost sale.</p>
  <p>It works almost exactly like your Follow-up queue: same four sections in the same order, same carry-over, same organisation grouping, same call outcomes. The differences are listed below, and there are only a few.</p>

  <div class="tip"><strong>How to get there:</strong> use the <button class="btn-inbound" style="font-size:0.82rem;margin:0;">Leads</button> button at the top of your Follow-up queue. The same button on the Leads page brings you back. The two queues are independent: each is locked in for the day on its own, so opening one doesn't affect the other, and you can move between them as often as you like.</div>

  <h3>The L on every quote number</h3>
  <p>Quote numbers in this queue carry an <strong>L</strong> at the end: <strong>TDU00512L</strong>. It is a marker, not part of the number. It tells you at a glance the row is a Lead, and the CRM shows it the same way.</p>
  <div class="note">Once you convert a Lead, its row <strong>keeps the L for the rest of the day</strong>, even though its stage cell now reads Created. That's normal. Tomorrow it turns up as an ordinary quote number in your Follow-up queue. Searching for it works either way, with or without the L.</div>

  <h3>Everything qualifying shows up, every day</h3>
  <p>Your Follow-up queue has a daily budget and stops at 42 quotes. <strong>The Leads queue has no budget.</strong> Every Lead that qualifies appears, every day. The four sections still set the order you should work through them in, they just never cut the list off.</p>
  <p>Same thing for scheduling: there's <strong>no daily limit</strong> on how many Lead callbacks you can book on the same date. The calendar still counts them, so each day reads "<em>N scheduled</em>" rather than the "N/10" you get in Follow-up, and every day stays green. It shows you the load so you can spread callbacks out; it will never block a date.</p>

  <h3>Two extra outcomes</h3>
  <p>The four call outcomes (Next call, No answer + email sent, Interested, Inbound call) are exactly the same as in Follow-up. What changes is how a Lead is closed out. Instead of Rejected / Accepted / Requote you get two options:</p>
  <ul style="padding-left:20px; margin-bottom:12px; color:#374151;">
    <li style="margin-bottom:6px;"><strong>Convert to quote</strong> &nbsp;The enquiry is real and is being quoted. Moves the stage to Created. From tomorrow it appears in your normal Follow-up queue and is worked from there.</li>
    <li><strong>Reject lead</strong> &nbsp;The enquiry is going nowhere. Moves the stage to Rejected and asks you for a reason.</li>
  </ul>

  <div class="warn"><strong>Both are one-way.</strong> Once you save either one the stage has moved in the CRM and there is no undo button in this queue. You'll get a confirmation dialog before it saves, so read it rather than clicking through. If you get it wrong, it has to be fixed from the quote's own page in the CRM.</div>

  <h3>Why the reject reasons are different</h3>
  <p>A Lead was never priced, so the Follow-up reasons in <a href="#logging" style="color:#2980b9;">&sect;7</a> don't fit. "Too expensive" makes no sense for something nobody quoted. The Leads list is shorter, and it has one category Follow-up doesn't. Here it is in full:</p>

  <div class="mockup">
    <div class="mockup-label">Reason dropdown &mdash; expanded (Reject lead)</div>
    <div class="mockup-inner">
      <div class="reason-drop">
        <div class="reason-drop-closed">&mdash; select reason &mdash;</div>
        <div class="reason-drop-list">
          <div class="reason-drop-selected">&mdash; select reason &mdash;</div>
          <div class="reason-drop-group">Company &mdash; Avoidable</div>
          <div class="reason-drop-option">Lost to response time</div>
          <div class="reason-drop-group">Booked with another operator</div>
          <div class="reason-drop-option">Cheaper elsewhere</div>
          <div class="reason-drop-option">Better itinerary or inclusions</div>
          <div class="reason-drop-option">Better availability of services</div>
          <div class="reason-drop-option">Booked direct with the supplier</div>
          <div class="reason-drop-option">Reason not given</div>
          <div class="reason-drop-group">Client &mdash; Out of our hands</div>
          <div class="reason-drop-option">No response after repeated attempts</div>
          <div class="reason-drop-option">Not ready to travel, no dates yet</div>
          <div class="reason-drop-option">Trip cancelled</div>
          <div class="reason-drop-group">Not a real opportunity</div>
          <div class="reason-drop-option">Information only, no booking intent</div>
          <div class="reason-drop-option">Destination or product we do not handle</div>
          <div class="reason-drop-group">Other</div>
          <div class="reason-drop-option">Other (specify below)</div>
        </div>
      </div>
    </div>
  </div>

  <p>Twelve reasons in five groups. <strong>Company &mdash; Avoidable</strong> covers a slow response on our side; <strong>Booked with another operator</strong> covers losing the enquiry to someone else, with five options for what they had that we didn't; <strong>Client &mdash; Out of our hands</strong> covers the client going quiet, having no dates yet, or cancelling; <strong>Not a real opportunity</strong> covers enquiries that were never going to become a booking; <strong>Other</strong> opens a free-text box to describe the reason yourself.</p>

  <p><strong>Use "Not a real opportunity" when there was nothing to win in the first place</strong>, not when we tried and lost. A lot of what gets rejected at Lead stage was never going to become a booking, and keeping those separate stops them counting as sales we lost.</p>

  <h3>Choosing inside "Booked with another operator"</h3>
  <p><strong>Better availability of services</strong> means the other operator could confirm hotels, transfers or tours that we couldn't, whether because they were sold out or the dates didn't work. <strong>Booked direct with the supplier</strong> is for the agent who skipped the ground operator entirely and went straight to the hotel or supplier. <strong>Reason not given</strong> is a real answer, not a last resort: at Lead stage most agents just say they've booked, and picking it honestly is far better than guessing at a cause.</p>

  <div class="tip"><strong>If we lost it because we were slow, use "Lost to response time"</strong> even if the agent went on to book with someone else. That group exists to count what we could have prevented, so anything avoidable belongs there. The operator group is for when we did our part and they still chose someone else.</div>

  <div class="tip">The list does not carry over from Follow-up: there is no "Visa issues" and no "Destination change", and note that "Cheaper elsewhere" is not the same as Follow-up's "Too expensive". We never priced a Lead, so it records what the agent found elsewhere, not a verdict on a quote of ours. If the reason you need isn't there, pick <strong>Other (specify below)</strong> and type it in. Those free-text answers are how we work out what's actually missing before adding more options.</div>

  <h3>Callbacks are capped at 7 days out</h3>
  <p>In the Follow-up queue you can schedule a callback up to 3 weeks ahead. <strong>In Leads the calendar stops at 7 days.</strong> A fresh enquiry goes cold quickly, so a callback three weeks away isn't a real plan to chase it. As always, the quote's own travel date can cut that shorter still, and Sundays are never selectable.</p>
  <div class="note">The 7 days follow <strong>the Lead itself</strong>, not the page you're on. Find a Lead through <a href="#search-quote" style="color:#2980b9;">Search quote</a> while you're sitting in your Follow-up queue and you'll still get the 7-day calendar and the Lead outcomes.</div>

  <h3>What's the same</h3>
  <ul style="padding-left:20px; color:#374151;">
    <li style="margin-bottom:5px;">The four sections and what each one means (see <a href="#queue-sections" style="color:#2980b9;">&sect;5</a>).</li>
    <li style="margin-bottom:5px;"><strong>Carry-over</strong>: a Lead you don't get to today comes back tomorrow with a Days behind badge (see <a href="#queue-sections" style="color:#2980b9;">&sect;5</a>).</li>
    <li style="margin-bottom:5px;">All of an organisation's Leads stay together as one block, so you deal with them in a single call (see <a href="#grouping" style="color:#2980b9;">&sect;6</a>).</li>
    <li style="margin-bottom:5px;">Logging, the Done state, call history and priority all behave exactly as described in &sect;7 to &sect;10.</li>
    <li><strong>Ownership works the same way too.</strong> A Lead belongs to whoever owns its region, so your Leads queue is your own regions and nobody else's (<a href="#regions" style="color:#2980b9;">&sect;3</a>).</li>
  </ul>
</div>

<!-- ══ 12. PAYMENT DEADLINE QUEUE ══ -->
<div class="section" id="payment-deadline">
  <h2>12. Payment Deadline Queue <span class="role-badge role-postsale">Post-sale</span> <span class="role-badge role-supervisor">Supervisor (view only)</span></h2>
  <p>A separate queue for chasing payment on quotes that are already <strong>Accepted</strong>. It lists every quote whose payment (cancellation) deadline is within 3 days or has already passed, as long as the trip itself hasn't happened yet.</p>

  <div class="note"><strong>This is not part of a region owner's day.</strong> Chasing money on a sale that is already won is a job of its own, so it belongs to the post-sale team rather than to whoever owns the region. Owners never see the link and never see the list.</div>

  <p><strong>One list, every region, both quote types.</strong> There is no per-person split here and no ownership to resolve: everyone who can see this page sees exactly the same rows, with standard quotes and Groups &amp; MICE together. Each row shows its region, but only so you know where the booking came from.</p>
  <p>Unlike the Follow-up queue, this one isn't locked in for the day. It reads live and updates every time you open it. There's no daily budget and no carry-over: a quote stays on the list until its CRM stage moves on, which is the only thing that takes it off.</p>
  <div class="tip"><strong>Payment status no longer decides membership.</strong> How much has been paid is shown on every row, but a quote is on this page because of its stage and its deadline, not because of what is outstanding.</div>

  <div class="tip"><strong>How to get there:</strong> click <button class="btn-inbound" style="font-size:0.82rem;margin:0;">Payment deadlines</button> at the top of the Follow-up queue. If your account has no Follow-up queue of its own, this page <em>is</em> your home page and opens straight away. The <strong>Awaiting information</strong> link at the top switches to the other post-sale list (<a href="#awaiting-info" style="color:#2980b9;">&sect;13</a>).</div>

  <div class="mockup">
    <div class="mockup-label">Payment Deadline — page header</div>
    <div class="mockup-inner" style="padding:0;">
      <div class="q-panel">
        <div class="q-title-row">
          <h1>Payment Deadline &mdash; Ravi</h1>
          <button class="btn-inbound">Awaiting information</button>
        </div>
        <div class="q-meta">Wednesday, 2 September 2026 &nbsp;|&nbsp; 18 to chase &nbsp;|&nbsp; <span class="pending">2 unrouted</span></div>
      </div>
    </div>
  </div>

  <h3>The four urgency sections</h3>
  <p>Rows are grouped by how urgent they have become, worked top to bottom:</p>

  <div class="callout-grid">
    <div class="callout-item">
      <span class="callout-color" style="background:#991b1b;"></span>
      <strong style="color:#991b1b;">Escalate</strong>
      <p>Deadline passed with 3 or more calls made and no resolution, <em>or</em> the trip is less than 15 days away whatever the call count. Let the sales agent know it needs following up on.</p>
    </div>
    <div class="callout-item">
      <span class="callout-color" style="background:#b45309;"></span>
      <strong style="color:#b45309;">Overdue</strong>
      <p>The payment deadline has already passed, but not yet enough calls (and the trip isn't close enough) to escalate.</p>
    </div>
    <div class="callout-item">
      <span class="callout-color" style="background:#1C93C4;"></span>
      <strong style="color:#1C93C4;">Due soon</strong>
      <p>Deadline is within 3 days but hasn't passed yet.</p>
    </div>
    <div class="callout-item">
      <span class="callout-color" style="background:#166534;"></span>
      <strong style="color:#166534;">Paid &mdash; waiting for stage change</strong>
      <p>Payment already matches the amount due. Nothing to chase, it just needs the CRM stage moved along.</p>
    </div>
  </div>

  <div class="mockup">
    <div class="mockup-label">An urgency section, expanded — example</div>
    <div class="mockup-inner" style="padding:0;">
      <div class="q-panel" style="padding:8px 14px;">
        <div class="section-toggle-mock" style="color:#991b1b;margin-top:0;"><span class="caret-mock"></span><span class="sect-title">Escalate</span> <span class="sect-count">(1 quote)</span><span class="sect-progress">Logged: 0/1 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:0%;background:currentColor"></span></span></span></div>
      </div>
      <div class="overflow-x" style="padding:0 14px 14px;">
      <table class="q-table">
        <thead><tr>
          <th>Organisation</th><th>Quote</th><th>Region</th><th>Stage</th><th>Sales agent</th><th>Mobile</th><th>Created</th><th>Trip</th><th>Deadline</th><th>Total</th><th>Paid</th><th>Outstanding</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
          <tr class="org-row">
            <td>Coral Bay Resorts</td>
            <td><a href="#">TDU00512</a></td>
            <td>West</td>
            <td>Accepted</td>
            <td>Meera Raghavan</td><td>+91 98765 00060</td>
            <td>07 Jul 2026</td><td>02 Sep 2026</td>
            <td><span style="color:#b45309;font-weight:600">29 Aug 2026</span><br><span style="color:#b45309;font-size:0.75rem;">4d overdue</span></td>
            <td>1,480.00</td><td>0.00</td><td>1,480.00</td>
            <td><span class="ob" style="background:#fee2e2;color:#991b1b;font-weight:700;">3 calls since deadline</span></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <p>Two columns are worth calling out. <strong>Region</strong> is context only: it tells you which part of the business the booking came from, it does not split the list or decide who chases it. <strong>Sales agent</strong> is whoever is assigned to the quote in the CRM, which is who to talk to if the client disputes something about the booking itself.</p>
  <p>Each section's heading shows a <strong>Logged: X/Y</strong> count with a progress bar, the same idea as the Follow-up queue's Called count. It counts quotes with a callback already scheduled, or any call already logged today, out of that section's total.</p>

  <h3>The Unrouted block</h3>
  <p>Above the working list there may be a collapsed <strong>Unrouted</strong> block. Those are quotes whose region the queue cannot resolve at all, usually because the region field on the quote is blank or holds something unexpected. They carry <strong>History but no Log button</strong>, because the fix isn't a phone call.</p>
  <div class="warn"><strong>Unrouted is a data problem, not a queue.</strong> Set the region on the quote in the CRM and it joins the normal list on the next load. It sits at the top rather than the bottom precisely so it gets fixed instead of quietly ignored.</div>

  <h3>Logging an outcome</h3>
  <p>Only two outcomes here. This queue doesn't change the CRM stage, so there is nothing like Requote, Rejected or Accepted. A quote leaves this list automatically once its stage moves on, never because of something you logged.</p>

  <table class="outcome-ref">
    <thead><tr><th style="width:170px;">Outcome</th><th>When to use</th><th>What happens next</th></tr></thead>
    <tbody>
      <tr>
        <td><span class="ob ob-noanswer">No answer</span></td>
        <td>No one answered, or you touched base but there's nothing new to report.</td>
        <td>Comes back up <strong>tomorrow</strong> automatically, no date to pick. If tomorrow is a <strong>Sunday</strong> it comes back on the Monday instead. And if the trip has arrived by then, it stays on today's list rather than snoozing at all.</td>
      </tr>
      <tr>
        <td><span class="ob ob-next">Next call</span></td>
        <td>You spoke to the client and agreed on a specific date to try again.</td>
        <td>Row shows <strong>&#10003; Next call, edit</strong> until that date. <em>Date required: must be after today, before the trip date, and not a Sunday.</em></td>
      </tr>
    </tbody>
  </table>

  <div class="warn"><strong>Notes are required</strong> for both outcomes. "No answer" starts you off with a suggested note ("Called, no answer.") that you're free to edit or replace.</div>

  <div class="note"><strong>About the callback calendar:</strong> Sundays are greyed out and can't be picked, same as in the Follow-up queue. Dates are also capped at the day before the trip, so for a quote travelling soon only a handful of days are selectable, and most of them may sit in <em>next</em> month. Use the <strong>&rsaquo;</strong> arrow to reach them: days in mid-grey belong to the next month but are still selectable, while genuinely unavailable days are much fainter and don't respond when clicked. Unlike the Follow-up queue there is <strong>no daily cap</strong> on payment calls for the same date, so this calendar has no green/yellow/red day colouring.</div>

  <h3>Status badges</h3>
  <ul style="padding-left:20px; margin-bottom:12px; color:#374151;">
    <li style="margin-bottom:5px;"><span class="ob" style="background:#f1f5f9;color:#475569;">Initial invoice not generated / Invoice amount missing</span>: Total is unknown; Paid still shows a real number if anything's come in.</li>
    <li style="margin-bottom:5px;"><span class="ob" style="background:#e0e7ff;color:#3730a3;">Change pending: date</span>: the payment deadline itself has a pending change in the CRM.</li>
    <li style="margin-bottom:5px;"><span class="ob" style="background:#dcfce7;color:#166534;">Next call: date</span>: a callback has already been logged for this quote.</li>
    <li><span class="ob" style="background:#fee2e2;color:#991b1b;font-weight:700;">N calls since deadline</span>: counts the calls logged since the deadline passed. Note it can read <strong>0 calls</strong>: a quote also escalates purely because the trip is under 15 days away, and in that case nobody has necessarily called it yet.</li>
  </ul>

  <div class="tip"><strong>The section heading is what tells you the job.</strong> A row under <em>Paid</em> needs no chasing, whatever else is on it; a row under <em>Escalate</em> needs someone told. Work the sections in order and the row styling takes care of itself.</div>

  <h3>Supervisors: view only</h3>
  <p>A supervisor opening this page gets the same list with no Log buttons and a line at the top saying so. History is open on every row. The point is to see whether payments are being chased, not to chase them.</p>
</div>

<!-- ══ 13. AWAITING INFORMATION ══ -->
<div class="section" id="awaiting-info">
  <h2>13. Awaiting Information <span class="role-badge role-postsale">Post-sale</span> <span class="role-badge role-supervisor">Supervisor (view only)</span></h2>
  <p>These quotes are <strong>already confirmed</strong>. Nobody needs to be convinced of anything. The booking simply cannot be made, because the client hasn't given us something we need: the traveller's contact number, the guest names for the rooming list, flight details, which hotels they're staying in on which nights, or dietary requirements.</p>
  <p>The job here is one phone call to the agency to get whatever is missing, and then to write it down. Nothing else.</p>

  <div class="tip"><strong>How to get there:</strong> the <button class="btn-inbound" style="font-size:0.82rem;margin:0;">Awaiting information</button> button at the top of the Follow-up queue, or the matching link on the Payment Deadline page. <strong>Back to Follow-up queue</strong> brings you back, if your account has one.</div>

  <div class="note">Same audience as the Payment Deadline queue: the post-sale team logs the calls, supervisors and admins can look. It is <strong>one merged list across every region</strong>, with no owner per row and nothing to claim. A region owner does not see it.</div>

  <div class="mockup">
    <div class="mockup-label">Awaiting Information, page header</div>
    <div class="mockup-inner" style="padding:0;">
      <div class="q-panel">
        <div class="q-title-row">
          <h1>Awaiting Information &mdash; Ravi</h1>
          <button class="btn-inbound">Payment deadlines</button>
        </div>
        <div class="q-meta">Wednesday, 2 September 2026 &nbsp;|&nbsp; 24 blocked &nbsp;|&nbsp; <span class="pending">1 unrouted</span></div>
      </div>
    </div>
  </div>

  <h3>Reading a row</h3>
  <p>Two columns matter more than the rest.</p>
  <ul style="padding-left:20px; margin-bottom:12px; color:#374151;">
    <li style="margin-bottom:6px;"><strong>Missing</strong> tells you what to ask for. It's collapsed to save space, so click <em>N items outstanding</em> to open it. Anything tied to particular days shows you <strong>the real dates</strong>, not day numbers, so you can read them straight off the screen and onto the phone.</li>
    <li><strong>Blocked</strong> is how many days this booking has been stuck. It turns red past 30 days.</li>
  </ul>
  <p>There is also a <strong>Region</strong> column and a <strong>Sales agent</strong> column. Both are context: the region says where the booking came from, the sales agent is who sold it and is worth knowing if the agency starts asking about the trip itself rather than about the details you're chasing.</p>
  <p>The rows are ordered by trip date, soonest first, because that's the best guess we have at how much time is left to make the booking. As everywhere else in the queue, all of one agency's quotes sit together as a block, so you resolve them in a single call.</p>

  <div class="note">You may notice the trip dates don't run in a perfect order down the page. That's deliberate. A block sits where its <em>most urgent</em> quote puts it, and its other quotes come along for the ride, exactly like the SP1 to SP4 sections. If it were any other way, working one quote would make its neighbours jump around the page.</div>

  <h3>What "requires the hotel" really means</h3>
  <p>If the Missing list shows three lines asking for the hotel on three different dates, that is <strong>not</strong> three hotels. It's three nights that need covering. One hotel booked across all three dates answers all three lines at once. Ask the agency which hotels cover those dates and write down however many they give you.</p>

  <h3>Logging the call</h3>
  <p>The <button class="log-btn" style="font-size:0.82rem;cursor:default;">Log</button> button works like everywhere else, with three outcomes:</p>
  <ul style="padding-left:20px; margin-bottom:12px; color:#374151;">
    <li style="margin-bottom:6px;"><strong>No answer</strong> &nbsp;Couldn't reach them.</li>
    <li style="margin-bottom:6px;"><strong>Contacted, still to come</strong> &nbsp;You spoke to them, they'll send it.</li>
    <li><strong>Information received</strong> &nbsp;You got something. This one opens a second box for what you were given.</li>
  </ul>
  <p><strong>There is no callback date here</strong>, and that's on purpose. The row doesn't disappear because you promised to ring back. It disappears when the information actually arrives.</p>
  <div class="note">This list is never frozen for the day, unlike Follow-up and Leads. It is rebuilt every time you open the page, so a row leaves the moment its blockers clear rather than sitting there until tomorrow.</div>

  <h3>Writing the information down</h3>
  <p>This is the part that matters, and it's worth getting right the first time. What you type into the <strong>Information</strong> box is read automatically by the system that makes the bookings. It looks for the actual values, so write the details themselves, not a sentence about them.</p>
  <p>"The client will fly in on the 8th" tells it nothing. This does:</p>

  <div class="mockup" style="font-family:Consolas,'Courier New',monospace; font-size:0.82rem; white-space:pre; line-height:1.6;">Meridian Hotel Sydney
Check In - 23 Aug 2026
Check Out - 26 Aug 2026

JQ534 08SEP MEL SYD 1105 1230
AI301 10SEP SYD DEL 2035 0500</div>

  <div class="warn"><strong>Every entry needs its dates.</strong> A hotel name with no check-in and check-out, or a flight number with no date, does nothing at all: it's read, and then ignored, and the row stays exactly where it was. If the agency gives you a hotel name but can't tell you the nights, you haven't got the answer yet, so ask.</div>

  <p><strong>You don't need everything at once.</strong> If you got the flights but not the guest names, log what you have now. Your note is added to the quote, it isn't overwritten, so next time you write a second one with the rest. Choosing "Information received" means "I got something new and wrote it down", not "this booking is now unblocked".</p>

  <div class="note">You'll often find a row is still there after you logged the information. That's expected: the booking system re-reads the quote on its own schedule, so give it a little time. It's also fine to ring the same agency again the same day, and the Log button stays available for exactly that.</div>

  <h3>After you log</h3>
  <p>The row dims and moves down to a block of already-called rows at the bottom of the list. It stays where you can still click it. Nothing is hidden from you, it's just moved out of the way so what's left to do stays at the top.</p>
  <p><strong>Only that row moves.</strong> The agency's other quotes stay exactly where they were, still in their original position on the page. That is on purpose: working an agency's most urgent quote shouldn't drag the rest of their quotes down the page with it.</p>

  <h3>The Unrouted block</h3>
  <p>Same idea as on the Payment Deadline page: quotes whose region the queue cannot resolve are listed separately at the top, for visibility only, with <strong>History but no Log button</strong>. Set the region on the quote in the CRM and it joins the main list.</p>
  <div class="tip">History is worth a click on those rows before anything else: it tells you whether somebody has already been chasing this agency.</div>
</div>

<!-- ══ 14. SUPERVISOR & MANAGER VIEW ══ -->
<div class="section" id="manager-view">
  <h2>14. Supervisor &amp; Manager View <span class="role-badge role-supervisor">Supervisor</span> <span class="role-badge role-admin">Admin</span></h2>
  <p>Two roles see more than their own patch, and they see it in the same place: one merged view with every region on the page at once.</p>

  <h3>Supervisors</h3>
  <p>A supervisor who also owns regions lands on <strong>their own queue</strong> first, exactly like any other owner, and that is the normal case rather than an oddity. A <strong>See all regions</strong> link next to the title switches to the merged view, and <strong>Back to my own view</strong> switches back. Your choice sticks as you move between Follow-up and Leads, so you don't have to keep re-selecting it.</p>
  <p>In the merged view every organisation carries the grey chip described in <a href="#main-screen" style="color:#2980b9;">&sect;4</a>, showing <span class="owner-badge">region &middot; owner</span>, so you can always tell whose quote you are looking at. A region with no owner reads <span class="owner-badge">region &middot; (unowned)</span>.</p>
  <p>Supervisors can log outcomes on any quote, in Follow-up and in Leads. On the two post-sale queues they are <strong>view only</strong> (<a href="#payment-deadline" style="color:#2980b9;">&sect;12</a>, <a href="#awaiting-info" style="color:#2980b9;">&sect;13</a>).</p>

  <div class="mockup">
    <div class="mockup-label">Merged view: every region on one page, region and owner on each block</div>
    <div class="mockup-inner" style="padding:0;">
      <div class="q-panel">
        <div class="q-title-row">
          <h1>Follow-up Queue &mdash; All Regions (Supervisor)</h1>
          <span class="view-as-inline"><a href="#" style="color:#334155;">Back to my own view</a></span>
          <button class="btn-inbound">Search quote</button>
          <button class="btn-inbound" style="margin-left:6px;">Leads</button>
          <button class="btn-inbound" style="margin-left:6px;">User manual</button>
        </div>
        <div class="q-meta">Tuesday, 23 June 2026 &nbsp;|&nbsp; 161 quotes today &nbsp;|&nbsp; SP1: 74 &nbsp; SP2: 42 &nbsp; SP3: 29 &nbsp; SP4: 16 &nbsp;|&nbsp; Called: <strong>18/161</strong> <span style="display:inline-block;width:72px;height:7px;background:#e0e0e0;border-radius:4px;overflow:hidden;vertical-align:middle"><span style="display:block;height:100%;width:11%;background:#334155;border-radius:4px"></span></span></div>
        <div class="warning-banner">&#9888; Queue not yet loaded today for region(s): <strong>South</strong>. Their quotes will appear once the daily run has built them.</div>
        <div class="section-toggle-mock sect-sp1" style="margin-top:14px;"><span class="caret-mock"></span><span class="sect-title">Scheduled follow-ups</span> <span class="sect-count">(74 quotes)</span><span class="sect-progress">Called: 18/74 <span class="sect-progress-bar"><span class="sect-progress-fill" style="width:24%;background:currentColor"></span></span></span></div>
      </div>
      <div class="overflow-x" style="padding:0 14px 14px;">
      <table class="q-table">
        <thead><tr>
          <th>Organisation</th><th>Quote #</th><th>Stage</th><th>Pax</th><th>Trip date</th><th>Contact</th><th>Mobile</th><th>Region</th><th>Next call date</th><th>Due</th><th>Calls</th><th>Created</th><th>Priority</th><th>Action</th>
        </tr></thead>
        <tbody>
          <tr class="org-row">
            <td>Royal Tours India <span class="owner-badge">North &middot; Anjali</span></td>
            <td><a href="#">TDU00312</a></td>
            <td>Created</td><td>4</td><td>10 Aug 2026</td>
            <td>Amit Sharma</td><td>+91 98765 43210</td><td>North</td>
            <td>22 Jun 2026</td><td><span class="days-today">1 day ago</span></td><td>0</td><td>12 May 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
          <tr class="done-row org-row">
            <td>Sunrise Travel Agency <span class="owner-badge">West &middot; Meera</span></td>
            <td><a href="#">TDU00456</a></td>
            <td>Created</td><td>2</td><td>15 Jul 2026</td>
            <td>Rajesh Kumar</td><td>+91 98765 00001</td><td>West</td>
            <td>23 Jun 2026</td><td><span class="days-soon">Today</span></td><td>3</td><td>02 Jun 2026</td>
            <td><select class="prio-sel"><option>Blank</option><option>Not connected</option><option>Low</option><option selected>High</option></select></td>
            <td><button class="log-btn done">&#10003; Done <span style="font-size:0.7rem;text-decoration:underline;margin-left:4px;">edit</span></button><button class="hist-btn">History</button></td>
          </tr>
          <tr class="org-row">
            <td>Elite Group Tours <span class="owner-badge">Gujarat &middot; (unowned)</span></td>
            <td><a href="#">TDU00601G</a></td>
            <td>Created</td><td>22</td><td>02 Sep 2026</td>
            <td>Deepa Krishnan</td><td>+91 98765 00040</td><td>Gujarat</td>
            <td>21 Jun 2026</td><td><span class="days-today">2 days ago</span></td><td>1</td><td>30 Apr 2026</td>
            <td><select class="prio-sel"><option selected>Blank</option><option>Not connected</option><option>Low</option><option>High</option></select></td>
            <td><button class="log-btn">Log</button><button class="hist-btn">History</button></td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <div class="note"><strong>About the "Queue not yet loaded today" banner.</strong> Opening the merged view deliberately never builds a region's list for it, so that the page always reflects what each region actually did. A region named in that banner simply hasn't been built yet, either by its owner opening their queue or by the overnight run. Its quotes are untouched and appear as soon as it is.</div>

  <h3>The personal list</h3>
  <p>Selling and owning a region are separate things, so an account can be granted a <strong>personal-claim</strong> permission that lets it work quotes without holding a region. Today that is one supervisor who sells but owns no region. The permission is granted explicitly per account, not worked out from whether someone happens to own a region, so giving that person a region later would not take it away from them.</p>
  <p>With no region there is nothing to build a daily list from, so the queue gives them a <strong>personal list</strong> instead, and it starts empty.</p>
  <div class="note"><strong>It is meant to start empty.</strong> The list fills as quotes are pulled into it: <strong>Pull to me</strong> on any row in the merged view, or <strong>Pull this quote to me</strong> in the Search Quote result (<a href="#search-quote" style="color:#2980b9;">&sect;10</a>). From then on those quotes come back every day like anyone else's, with the same sections, carry-over and outcomes.</div>
  <p>This is the only place in the queue where a person takes a quote for themselves. Everywhere else ownership follows the region and is changed in the CRM (<a href="#regions" style="color:#2980b9;">&sect;3</a>).</p>
  <div class="warn"><strong>The personal list is Follow-up only.</strong> There is no personal Leads queue and no Pull button anywhere on the Leads page. Clicking <strong>Leads</strong> from a personal list shows the merged all-region Leads view instead, which is a different thing from your own list and is worth knowing before it surprises you.</div>

  <h3>Admins</h3>
  <p>Admins get everything a supervisor does, plus two things of their own.</p>
  <ul style="padding-left:20px; margin-bottom:12px; color:#374151;">
    <li style="margin-bottom:6px;"><strong>View as</strong>: a dropdown next to the title listing every region owner, anyone holding a personal list, and <em>Admin view</em> for the merged page. Choosing a person shows exactly what that person sees, including their own access: preview a supervisor on the Payment Deadline queue and it is view-only, because that is what they get.</li>
    <li><strong>Monitoring</strong>: opens the reporting dashboard in a new tab.</li>
  </ul>
  <div class="note"><strong>Monitoring is temporarily unavailable</strong> while it is brought into line with the regional model, so the button currently opens an "Under Maintenance" page. The queues themselves are unaffected.</div>
</div>

</div><!-- /.manual-wrap -->
</body>
</html>
