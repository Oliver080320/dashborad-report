// By person tab — date-range control, quote timeline modal, summary tiles, drill-down tables,
// breakdown donut, and live book for one caller's whole quote book.

let personRangeMode = 'month';

// These state vars live at the top of the file, not near where each is used, because
// personRenderRangeSummary() below runs immediately on load and reaches code using them before a
// later `let`/`const` would be initialized ("temporal dead zone") — this caused a real
// "Cannot access before initialization" crash. Function declarations don't have this problem
// since they're fully hoisted.
let personBookFilter = null; // null | 'total' | 'created' | 'requote' | 'accepted' | 'rejected' | 'companies'
let personDrillOrg = null; // set when a company row is clicked; null = no org drilled into yet
let personDrillRegion = null; // set when a region/priority breakdown bar is clicked
let personDrillPriority = null;
let personBreakdownMode = 'region'; // 'region' | 'priority'
// This caller's live book (Created/Requote), fetched once per caller change and reused by both
// the travel-month chart and the table below it; ignores the date-range picker entirely.
let PERSON_LIVE_BOOK = [];
let personLiveBookMonthFilter = null; // null | a bucket label from PERSON_LIVE_BOOK_MONTHS
let personLiveBookUpcomingFilter = null; // null | a bucket label from personUpcomingBuckets() — mutually exclusive with the month filter above
let personLiveBookSort = { key: 'travel_date', dir: 1 };
const PERSON_DRILL_LABELS = {
  total: 'All quotes, every status',
  created: 'Created, awaiting first move',
  requote: 'Requote',
  accepted: 'Accepted',
  rejected: 'Rejected',
  inbound: 'Inbound calls received',
  companies: 'Companies',
};

function personFmt(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

// Display-only reformat to DD/MM/YYYY for the range chip (matches queue.js's flatpickr format)
// — the YYYY-MM-DD bounds sent to the endpoints are untouched.
function personFmtSlash(ymd) {
  const [y, m, d] = ymd.split('-');
  return `${d}/${m}/${y}`;
}

function personComputeBounds(mode) {
  const now = new Date(TODAY + 'T00:00:00');
  if (mode === 'month') {
    return { from: personFmt(new Date(now.getFullYear(), now.getMonth(), 1)), to: personFmt(now) };
  }
  if (mode === 'year') {
    return { from: personFmt(new Date(now.getFullYear(), 0, 1)), to: personFmt(now) };
  }
  // custom — read directly from the two date inputs, falling back to today if either is unset
  const fromInput = document.getElementById('personRangeFrom').value;
  const toInput = document.getElementById('personRangeTo').value;
  return { from: fromInput || personFmt(now), to: toInput || personFmt(now) };
}

// Recomputed on every call (cheap — just date arithmetic) so it's always current.
function personRangeBounds() {
  return personComputeBounds(personRangeMode);
}

function personRenderRangeSummary() {
  const bounds = personRangeBounds();
  document.getElementById('personRangeSummary').textContent =
    'Range: ' + personFmtSlash(bounds.from) + ' to ' + personFmtSlash(bounds.to);
  // personRenderSummary() is hoisted, so it's callable here even on this file's first load.
  personRenderSummary();
}

document.getElementById('personRangeMode').addEventListener('click', function (e) {
  const mode = e.target.dataset.range;
  if (!mode) return;
  personRangeMode = mode;
  document.querySelectorAll('#personRangeMode button').forEach(function (b) {
    b.classList.toggle('on', b.dataset.range === mode);
  });
  document.getElementById('personCustomRange').style.display = (mode === 'custom') ? '' : 'none';
  personRenderRangeSummary();
});

document.getElementById('personRangeFrom').addEventListener('change', personRenderRangeSummary);
document.getElementById('personRangeTo').addEventListener('change', personRenderRangeSummary);

personRenderRangeSummary();

// Individual quote timeline modal — opened via the quote search box in the dashboard header
// (#quoteTimelineBar in monitoring_view.php, not #personPanel, since a quote lookup is useful
// from any tab) or via personOpenTimeline(quoteid) from a drill-down row.

function personEscapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s == null ? '' : String(s);
  return d.innerHTML;
}

const PERSON_TIMELINE_MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

// Queue-appearance colours match the caller queue's own sp1-sp4/carryover colours (queue.css);
// everything else is a fixed type colour.
const PERSON_TIMELINE_BUCKET_COLOURS = {
  sp1: '#1C93C4', sp2: '#F5A623', sp3: '#EF6C24', sp4: '#4A7B8C', carryover: '#92400e',
};
const PERSON_TIMELINE_TYPE_COLOURS = {
  created: '#64748B', call: '#16A34A', stage: '#4F46E5', rejection: '#C0392B',
};

// raw is a MySQL DATETIME/DATE string, parsed as local time (fine for display, since the DB
// server's Melbourne clock only matters for time-of-day maths, not for a calendar date).
function personFormatEventDate(raw) {
  const hasTime = raw.length > 10;
  const dt = new Date((hasTime ? raw.replace(' ', 'T') : raw + 'T00:00:00'));
  const datePart = String(dt.getDate()).padStart(2, '0') + ' ' + PERSON_TIMELINE_MONTHS[dt.getMonth()] + ' ' + dt.getFullYear();
  if (!hasTime) return datePart;
  return datePart + ', ' + String(dt.getHours()).padStart(2, '0') + ':' + String(dt.getMinutes()).padStart(2, '0');
}

function personEventSortKey(raw) {
  return raw.length > 10 ? raw.replace(' ', 'T') : raw + 'T00:00:00';
}

function personDaysBetween(fromDate, toDate) {
  const a = new Date(fromDate + 'T00:00:00');
  const b = new Date(toDate + 'T00:00:00');
  return Math.round((b - a) / 86400000);
}

// "26–29 Jun 2026" (same month), "29 Jun – 2 Jul 2026" (crosses a month), "29 Dec 2025 – 2 Jan
// 2026" (crosses a year). A single-day range (startDate === endDate) falls through to the plain
// single-date format instead of repeating the date.
function personFormatDateRange(startDate, endDate) {
  if (startDate === endDate) return personFormatEventDate(startDate);
  const a = new Date(startDate + 'T00:00:00');
  const b = new Date(endDate + 'T00:00:00');
  const startDay = String(a.getDate()).padStart(2, '0');
  const endFull = String(b.getDate()).padStart(2, '0') + ' ' + PERSON_TIMELINE_MONTHS[b.getMonth()] + ' ' + b.getFullYear();
  if (a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth()) {
    return startDay + '–' + endFull;
  }
  if (a.getFullYear() === b.getFullYear()) {
    return startDay + ' ' + PERSON_TIMELINE_MONTHS[a.getMonth()] + ' – ' + endFull;
  }
  return startDay + ' ' + PERSON_TIMELINE_MONTHS[a.getMonth()] + ' ' + a.getFullYear() + ' – ' + endFull;
}

// Collapses consecutive daily_runs rows into one range when the bucket stays the same and the
// days are back-to-back. A gap (worked, then came back) breaks the run — that's two separate
// stretches, not one.
function personGroupQueueAppearances(appearances) {
  const groups = [];
  appearances.forEach(function (a) {
    const last = groups[groups.length - 1];
    if (last && last.bucket === a.bucket && personDaysBetween(last.endDate, a.run_date) === 1) {
      last.endDate = a.run_date;
    } else {
      groups.push({ bucket: a.bucket, startDate: a.run_date, endDate: a.run_date });
    }
  });
  return groups;
}

// Merges every event type into one chronological list — four separate lists didn't read as an
// actual timeline. Each event carries a short `kind`, rendered as its own badge in
// personRenderTimeline(), so labels below drop the redundant type prefix.
function personBuildTimelineEvents(data) {
  const events = [];
  const q = data.quote;

  events.push({
    date: q.created_at, colour: PERSON_TIMELINE_TYPE_COLOURS.created,
    kind: 'Created', label: 'Quote entered the system',
  });

  personGroupQueueAppearances(data.queue_appearances).forEach(function (g) {
    events.push({
      date: g.startDate, displayDate: personFormatDateRange(g.startDate, g.endDate),
      colour: PERSON_TIMELINE_BUCKET_COLOURS[g.bucket] || PERSON_TIMELINE_TYPE_COLOURS.created,
      kind: 'Queue', label: 'Surfaced as ' + g.bucket.toUpperCase(),
    });
  });

  data.calls.forEach(function (c) {
    events.push({
      date: c.call_date, colour: PERSON_TIMELINE_TYPE_COLOURS.call,
      kind: 'Call',
      label: (c.outcome || 'No outcome recorded') +
        (c.channel ? ' (' + c.channel + ')' : '') + (c.notes ? ': ' + c.notes : '') +
        (c.created_by ? ' — ' + c.created_by : ''),
    });
  });

  data.stage_changes.forEach(function (s) {
    events.push({
      date: s.created_at, colour: PERSON_TIMELINE_TYPE_COLOURS.stage,
      kind: 'Stage',
      label: 'Changed to ' + s.stage_name + (s.user_name ? ' — ' + s.user_name : ''),
    });
  });

  data.rejection_reasons.forEach(function (r) {
    events.push({
      date: r.created_at, colour: PERSON_TIMELINE_TYPE_COLOURS.rejection,
      kind: 'Rejected',
      label: r.category + ' / ' + r.reason + (r.other_reason ? ': ' + r.other_reason : ''),
    });
  });

  events.sort(function (a, b) { return personEventSortKey(a.date) < personEventSortKey(b.date) ? -1 : 1; });
  return events;
}

function personRenderTimeline(data) {
  const q = data.quote;
  document.getElementById('personTimelineTitle').textContent =
    q.quote_no + ' · ' + (q.organization_name || 'No organisation');

  const parts = [];

  // Owner/Stage/Travel date/Next call as small tags — replaced a middot-joined line that read as
  // one run-on sentence. Year is shown for travel date since it can fall in a different year
  // than today.
  const metaItems = [];
  if (q.owner) metaItems.push({ label: 'Owner', value: q.owner });
  metaItems.push({ label: 'Stage', value: q.quotestage });
  if (q.trip_start_date) metaItems.push({ label: 'Travel date', value: personFormatEventDate(q.trip_start_date) });
  // The only forward-looking fact here — everything else is a snapshot of now.
  if (q.next_call_date) metaItems.push({ label: 'Next call', value: personFormatEventDate(q.next_call_date) });
  parts.push('<div class="personTimelineMeta">' + metaItems.map(function (m) {
    return '<span class="personTimelineMeta__item"><span class="personTimelineMeta__label">' + m.label + '</span>' +
      personEscapeHtml(m.value) + '</span>';
  }).join('') + '</div>');

  if (data.reversed_after_acceptance) {
    parts.push('<p class="chip" style="color:var(--alert);">This quote was Accepted and later ' +
      'reverted. No reason is captured anywhere for that reversal — structured rejection ' +
      'reasons are only recorded on a direct Rejected close.</p>');
  }

  parts.push('<h4>Timeline</h4>');
  const events = personBuildTimelineEvents(data);
  parts.push('<ul class="personTimeline">' + events.map(function (e) {
    return '<li class="personTimelineEvent">' +
      '<span class="personTimelineEvent__dot" style="background:' + e.colour + '"></span>' +
      '<span class="personTimelineEvent__date">' + personEscapeHtml(e.displayDate || personFormatEventDate(e.date)) + '</span>' +
      '<span class="personTimelineEvent__kind" style="color:' + e.colour + '">' + e.kind + '</span>' +
      '<span class="personTimelineEvent__label">' + personEscapeHtml(e.label) + '</span>' +
      '</li>';
  }).join('') + '</ul>');

  document.getElementById('personTimelineBody').innerHTML = parts.join('');
}

// Call directly with a known quoteid from a drill-down table row.
function personOpenTimeline(quoteid) {
  fetch(ASSET_BASE + '/monitoring_system/ajax_quote_timeline.php?quoteid=' + encodeURIComponent(quoteid))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.success) {
        alert(data.message || 'Quote not found');
        return;
      }
      personRenderTimeline(data);
      document.getElementById('personTimelineModal').showModal();
    })
    .catch(function () {
      alert('Unable to load quote timeline. Please try again.');
    });
}

document.getElementById('personTimelineSearchBtn').addEventListener('click', function () {
  const raw = document.getElementById('personTimelineQuoteNo').value.trim();
  const errEl = document.getElementById('personTimelineSearchError');
  errEl.style.display = 'none';
  if (!raw) return;

  fetch(ASSET_BASE + '/monitoring_system/ajax_quote_timeline.php?quote_no=' + encodeURIComponent(raw))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.success) {
        errEl.textContent = data.message || 'Quote not found';
        errEl.style.display = '';
        return;
      }
      personRenderTimeline(data);
      document.getElementById('personTimelineModal').showModal();
    })
    .catch(function () {
      errEl.textContent = 'Unable to load quote timeline. Please try again.';
      errEl.style.display = '';
    });
});

document.getElementById('personTimelineClose').addEventListener('click', function () {
  document.getElementById('personTimelineModal').close();
});

// Caller dropdown + period summary tiles.

function personSetText(id, text) {
  document.getElementById(id).textContent = text;
}

function personRenderSummary() {
  const uname = document.getElementById('personCallerSelect').value;
  const bounds = personRangeBounds();

  fetch(ASSET_BASE + '/monitoring_system/ajax_person_summary.php?user_name=' + encodeURIComponent(uname) +
    '&from=' + encodeURIComponent(bounds.from) + '&to=' + encodeURIComponent(bounds.to))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.success) return;
      const s = data.summary;
      // Short qualifiers only — the date range is already shown above, so repeating it on every
      // tile made the row too wide.
      const createdTxt  = 'created';
      const resolvedTxt = 'resolved';

      personSetText('pvTotal', s.total);
      personSetText('pvTotalSub', createdTxt);
      personSetText('pvCompanies', s.companies);
      personSetText('pvCompaniesSub', createdTxt);
      personSetText('pvCreated', s.created);
      personSetText('pvCreatedSub', 'awaiting to be confirmed');
      personSetText('pvRequote', s.requote);
      personSetText('pvRequoteSub', 'sent back');
      personSetText('pvAccepted', s.accepted);
      personSetText('pvAcceptedSub', resolvedTxt);
      personSetText('pvRejected', s.rejected);
      personSetText('pvRejectedSub', resolvedTxt);
      personSetText('pvInbound', s.inbound_calls);
      personSetText('pvInboundSub', 'received');
      personSetText('pvConvRate', s.conversion_rate === null ? 'n/a' : s.conversion_rate + '%');
      personSetText('pvConvSub', s.conversion_rate === null
        ? 'no resolved quotes yet'
        : s.accepted + ' of ' + (s.accepted + s.rejected) + ' resolved');
      personSetText('pvFirstResponse', s.avg_first_response_days === null ? '—' : s.avg_first_response_days + 'd');
      personSetText('pvFirstResponseSub', createdTxt);
      personSetText('pvCycleTime', s.avg_cycle_time_days === null ? '—' : s.avg_cycle_time_days + 'd');
      personSetText('pvCycleTimeSub', resolvedTxt);
    })
    .catch(function () {
      ['pvTotal', 'pvCompanies', 'pvCreated', 'pvRequote', 'pvAccepted', 'pvRejected', 'pvInbound', 'pvConvRate', 'pvFirstResponse', 'pvCycleTime'].forEach(function (id) {
        personSetText(id, '—');
      });
    });

  // Keeps an already-open drill in sync with the caller/range that triggered this refresh; a
  // no-op when no drill is open.
  personRenderDrill();
  // The breakdown bars also always reflect the current caller/range, independent of any open drill.
  personRenderBreakdown();
  personRenderCallsByHour();
}

document.getElementById('personCallerSelect').addEventListener('change', personRenderSummary);
document.getElementById('personCallerSelect').addEventListener('change', personRenderLiveBook);

// Clicking a summary tile drills into the matching quote list (personBookFilter/
// PERSON_DRILL_LABELS declared near the top of this file).

// Sortable drill table, matching the live-book table's click-header sorting below.
// #personDrillTable renders two shapes (quotes vs companies) from one <table>, so
// personDrillRows/personDrillTableType/personDrillEmptyText cache the last fetch, letting the
// click handler re-render without a second request.
let personDrillSort = { key: null, dir: 1 };
let personDrillRows = [];
let personDrillTableType = null; // 'quotes' | 'companies'
let personDrillEmptyText = '';
// Which $filter the quotes list was fetched with — build_person_quotes() returns one generic
// `key_date` column meaning created_at for total/created/requote but the accept/reject event
// date for accepted/rejected, so the header label is picked per filter instead of a generic
// "Key date".
let personDrillFilter = null;
const PERSON_DRILL_KEY_DATE_LABELS = {
  total: 'Created', created: 'Created', requote: 'Created',
  accepted: 'Accepted on', rejected: 'Rejected on', inbound: 'Received on',
};

const PERSON_DRILL_QUOTES_SORT_FIELDS = {
  quote_no: function (r) { return r.quote_no; },
  organization_name: function (r) { return r.organization_name || ''; },
  assigned_to_region: function (r) { return r.assigned_to_region || ''; },
  priority: function (r) { return r.priority || ''; },
  quotestage: function (r) { return r.quotestage || ''; },
  key_date: function (r) { return r.key_date || ''; },
};

const PERSON_DRILL_COMPANIES_SORT_FIELDS = {
  organization_name: function (r) { return r.organization_name || ''; },
  assigned_to_region: function (r) { return r.assigned_to_region || ''; },
  quote_count: function (r) { return r.quote_count; },
  days_since_contact: function (r) { return r.days_since_contact; },
};

function personDrillSortArrow(key) {
  return personDrillSort.key === key ? (personDrillSort.dir === 1 ? ' ▲' : ' ▼') : '';
}

// A key with no matching field for the current table (e.g. a leftover 'priority' sort while
// viewing companies) is a safe no-op — rows come back unsorted, no arrow shown.
function personSortedRows(rows, fields) {
  const extract = personDrillSort.key && fields[personDrillSort.key];
  if (!extract) return rows;
  const sorted = rows.slice();
  sorted.sort(function (a, b) {
    const va = extract(a), vb = extract(b);
    if (va < vb) return -1 * personDrillSort.dir;
    if (va > vb) return 1 * personDrillSort.dir;
    return 0;
  });
  return sorted;
}

function personRenderQuotesTable(quotes, onEmptyText, filter) {
  personDrillTableType = 'quotes';
  personDrillRows = quotes;
  personDrillEmptyText = onEmptyText;
  personDrillFilter = filter;
  const keyDateLabel = PERSON_DRILL_KEY_DATE_LABELS[filter] || 'Key date';

  const tbody = document.querySelector('#personDrillTable tbody');
  document.querySelector('#personDrillTable thead').innerHTML = '<tr>' +
    '<th data-sort="quote_no">Quote #' + personDrillSortArrow('quote_no') + '</th>' +
    '<th data-sort="organization_name">Organisation' + personDrillSortArrow('organization_name') + '</th>' +
    '<th data-sort="assigned_to_region">Region' + personDrillSortArrow('assigned_to_region') + '</th>' +
    '<th data-sort="priority">Priority' + personDrillSortArrow('priority') + '</th>' +
    '<th data-sort="quotestage">Status' + personDrillSortArrow('quotestage') + '</th>' +
    '<th data-sort="key_date">' + keyDateLabel + personDrillSortArrow('key_date') + '</th>' +
    '</tr>';
  if (!quotes.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="color:var(--faint);font-style:italic;">' + onEmptyText + '</td></tr>';
    return;
  }
  tbody.innerHTML = personSortedRows(quotes, PERSON_DRILL_QUOTES_SORT_FIELDS).map(function (q) {
    return '<tr class="personDrillRow" data-quoteid="' + q.quoteid + '">' +
      '<td>' + personEscapeHtml(q.quote_no) + '</td>' +
      '<td>' + personEscapeHtml(q.organization_name || '—') + '</td>' +
      '<td>' + personEscapeHtml(q.assigned_to_region || '—') + '</td>' +
      '<td>' + personEscapeHtml(q.priority || '—') + '</td>' +
      '<td>' + personEscapeHtml(q.quotestage) + '</td>' +
      '<td>' + personEscapeHtml(personFormatEventDate(q.key_date)) + '</td>' +
      '</tr>';
  }).join('');
  Array.prototype.forEach.call(tbody.querySelectorAll('.personDrillRow'), function (tr) {
    tr.addEventListener('click', function () {
      personOpenTimeline(tr.dataset.quoteid);
    });
  });
}

function personRenderCompaniesTable(companies) {
  personDrillTableType = 'companies';
  personDrillRows = companies;

  const tbody = document.querySelector('#personDrillTable tbody');
  document.querySelector('#personDrillTable thead').innerHTML = '<tr>' +
    '<th data-sort="organization_name">Organisation' + personDrillSortArrow('organization_name') + '</th>' +
    '<th data-sort="assigned_to_region">Region' + personDrillSortArrow('assigned_to_region') + '</th>' +
    '<th data-sort="quote_count">Quotes' + personDrillSortArrow('quote_count') + '</th>' +
    '<th data-sort="days_since_contact">Days since contact' + personDrillSortArrow('days_since_contact') + '</th>' +
    '</tr>';
  if (!companies.length) {
    tbody.innerHTML = '<tr><td colspan="4" style="color:var(--faint);font-style:italic;">No companies in this range for this caller.</td></tr>';
    return;
  }
  tbody.innerHTML = personSortedRows(companies, PERSON_DRILL_COMPANIES_SORT_FIELDS).map(function (c) {
    return '<tr class="personCompanyRow" data-org="' + personEscapeHtml(c.organization_name) + '">' +
      '<td>' + personEscapeHtml(c.organization_name) + '</td>' +
      '<td>' + personEscapeHtml(c.assigned_to_region || '—') + '</td>' +
      '<td>' + c.quote_count + '</td>' +
      '<td>' + c.days_since_contact + '</td>' +
      '</tr>';
  }).join('');
  Array.prototype.forEach.call(tbody.querySelectorAll('.personCompanyRow'), function (tr) {
    tr.addEventListener('click', function () {
      personDrillOrg = tr.dataset.org;
      personRenderDrill();
    });
  });
}

// Delegated on the <table> itself, not its thead (which gets replaced on every render), so one
// listener covers both shapes. Re-renders from the cached rows rather than refetching.
document.querySelector('#personDrillTable').addEventListener('click', function (e) {
  const th = e.target.closest('[data-sort]');
  if (!th) return;
  const key = th.dataset.sort;
  if (personDrillSort.key === key) { personDrillSort.dir *= -1; } else { personDrillSort = { key: key, dir: 1 }; }
  if (personDrillTableType === 'quotes') {
    personRenderQuotesTable(personDrillRows, personDrillEmptyText, personDrillFilter);
  } else if (personDrillTableType === 'companies') {
    personRenderCompaniesTable(personDrillRows);
  }
});

// Shared by the bar list and the donut slices — both are just two views onto the same
// click-to-drill action.
function personBreakdownToggle(key) {
  const current = personBreakdownMode === 'region' ? personDrillRegion : personDrillPriority;
  const closing = current === key;
  personDrillOrg = null;
  personDrillRegion = null;
  personDrillPriority = null;
  if (closing) {
    // Clicking the same bucket again closes the drill entirely.
    personBookFilter = null;
  } else {
    if (personBreakdownMode === 'region') { personDrillRegion = key; } else { personDrillPriority = key; }
    personBookFilter = 'total';
  }
  personRenderDrill();
}

// Pulled from this dashboard's existing PALETTE/CALLER_COLOURS rather than new hex values —
// buckets are arbitrary strings with no fixed identity, so colour is just assigned by position.
const PERSON_BREAKDOWN_COLOURS = ['#1C93C4', '#F5A623', '#EF6C24', '#6B5B95', '#3FA34D', '#C0392B', '#4A7B8C', '#92400e'];

let personBreakdownChart = null;

// The bar list alone left this card mostly empty next to the taller tiles card. Legend is off —
// the bars below already are the legend; showing both would repeat label/count/pct. The drilled
// slice (if any) is popped via Chart.js's `offset` so it stays in sync with the bar list's
// .is-active.
function personRenderBreakdownChart(rows) {
  const current = personBreakdownMode === 'region' ? personDrillRegion : personDrillPriority;
  const labels = rows.map(function (r) { return r.bucket; });
  const data = rows.map(function (r) { return r.count; });
  const colours = rows.map(function (r, i) { return PERSON_BREAKDOWN_COLOURS[i % PERSON_BREAKDOWN_COLOURS.length]; });
  const offsets = rows.map(function (r) { return r.bucket === current ? 10 : 0; });

  if (!personBreakdownChart) {
    personBreakdownChart = new Chart(document.getElementById('chartPersonBreakdown'), {
      type: 'doughnut',
      data: { labels: labels, datasets: [{ data: data, backgroundColor: colours, offset: offsets }] },
      options: Object.assign({}, CHART_BASE_OPTIONS, {
        cutout: '62%',
        plugins: Object.assign({}, CHART_BASE_OPTIONS.plugins, { legend: { display: false } }),
        onClick: function (evt, els) {
          if (!els.length) return;
          personBreakdownToggle(rows[els[0].index].bucket);
        },
      }),
    });
  } else {
    personBreakdownChart.data.labels = labels;
    personBreakdownChart.data.datasets[0].data = data;
    personBreakdownChart.data.datasets[0].backgroundColor = colours;
    personBreakdownChart.data.datasets[0].offset = offsets;
    personBreakdownChart.update();
  }
}

function personRenderBreakdown() {
  const uname = document.getElementById('personCallerSelect').value;
  const bounds = personRangeBounds();
  const container = document.getElementById('personBreakdownBars');
  const vizEl = document.getElementById('chartPersonBreakdown').closest('.personBreakdownViz');

  fetch(ASSET_BASE + '/monitoring_system/ajax_person_breakdown.php?user_name=' + encodeURIComponent(uname) +
    '&from=' + encodeURIComponent(bounds.from) + '&to=' + encodeURIComponent(bounds.to) +
    '&mode=' + encodeURIComponent(personBreakdownMode))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      const rows = data.success ? data.breakdown : [];
      if (!rows.length) {
        vizEl.style.display = 'none';
        container.innerHTML = '<span style="color:var(--faint);font-style:italic;">No quotes in this range.</span>';
        return;
      }
      vizEl.style.display = '';
      personRenderBreakdownChart(rows);

      const total = rows.reduce(function (sum, r) { return sum + r.count; }, 0);
      const current = personBreakdownMode === 'region' ? personDrillRegion : personDrillPriority;
      container.innerHTML = rows.map(function (r, i) {
        const pct = Math.round(r.count / total * 100);
        const active = r.bucket === current ? ' is-active' : '';
        const colour = PERSON_BREAKDOWN_COLOURS[i % PERSON_BREAKDOWN_COLOURS.length];
        return '<div class="personBreakdownBar' + active + '" data-breakdown-key="' + personEscapeHtml(r.bucket) + '">' +
          '<div class="personBreakdownBar__label">' + personEscapeHtml(r.bucket) + '</div>' +
          '<div class="personBreakdownBar__track"><div class="personBreakdownBar__fill" style="width:' + pct + '%;background:' + colour + '"></div></div>' +
          '<div class="personBreakdownBar__count">' + r.count + ' (' + pct + '%)</div>' +
          '</div>';
      }).join('');
      Array.prototype.forEach.call(container.querySelectorAll('[data-breakdown-key]'), function (el) {
        el.addEventListener('click', function () { personBreakdownToggle(el.dataset.breakdownKey); });
      });
    })
    .catch(function () {
      vizEl.style.display = 'none';
      container.innerHTML = '<span style="color:var(--faint);font-style:italic;">Unable to load.</span>';
    });
}

let personCallsByHourChart = null;

function personRenderCallsByHourChart(hours) {
  const hourKeys = sortedHourKeys(hours);
  const labels = hourKeys.map(formatHourLabel);
  const data = hourKeys.map(function (h) { return hours[h] || 0; });

  if (!personCallsByHourChart) {
    personCallsByHourChart = new Chart(document.getElementById('chartPersonCallsByHour'), {
      type: 'bar',
      data: { labels: labels, datasets: [{ data: data, backgroundColor: PALETTE.teal, borderRadius: 3 }] },
      options: Object.assign({}, CHART_BASE_OPTIONS, {
        plugins: Object.assign({}, CHART_BASE_OPTIONS.plugins, { legend: { display: false } }),
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
      }),
    });
  } else {
    personCallsByHourChart.data.labels = labels;
    personCallsByHourChart.data.datasets[0].data = data;
    personCallsByHourChart.update();
  }
}

// Scoped by the date-range picker, unlike Team activity's calls-by-hour chart
// (build_calls_by_hour, a fixed 90-day window), since a single
// caller with one explicit range doesn't need that wider net.
function personRenderCallsByHour() {
  const uname = document.getElementById('personCallerSelect').value;
  const bounds = personRangeBounds();

  fetch(ASSET_BASE + '/monitoring_system/ajax_person_calls_by_hour.php?user_name=' + encodeURIComponent(uname) +
    '&from=' + encodeURIComponent(bounds.from) + '&to=' + encodeURIComponent(bounds.to))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      personRenderCallsByHourChart(data.success ? data.hours : {});
    })
    .catch(function () { personRenderCallsByHourChart({}); });
}

document.getElementById('personBreakdownMode').addEventListener('click', function (e) {
  const btn = e.target.closest('[data-breakdown-mode]');
  if (!btn) return;
  personBreakdownMode = btn.dataset.breakdownMode;
  document.querySelectorAll('#personBreakdownMode button').forEach(function (b) {
    b.classList.toggle('on', b.dataset.breakdownMode === personBreakdownMode);
  });
  personRenderBreakdown();
});

function personRenderDrill() {
  // Marks which tile the open drill-down belongs to — previously there was no visual indicator
  // of which tile was clicked.
  Array.prototype.forEach.call(document.querySelectorAll('#personSummaryKpis [data-book-filter]'), function (el) {
    el.classList.toggle('is-active', el.dataset.bookFilter === personBookFilter);
  });

  const card = document.getElementById('personDrillCard');
  if (!personBookFilter) { card.style.display = 'none'; return; }
  card.style.display = '';

  const uname = document.getElementById('personCallerSelect').value;
  const bounds = personRangeBounds();
  const backBtn = document.getElementById('personDrillBack');

  if (personDrillRegion || personDrillPriority) {
    // A breakdown-bar drill — no Back target (bars aren't a nested list like companies→org),
    // only Close applies.
    backBtn.style.display = 'none';
    const mode = personDrillRegion ? 'Region' : 'Priority';
    const value = personDrillRegion || personDrillPriority;
    document.getElementById('personDrillLabel').textContent = mode + ': ' + value;
    const qs = personDrillRegion
      ? '&region=' + encodeURIComponent(personDrillRegion)
      : '&priority=' + encodeURIComponent(personDrillPriority);
    fetch(ASSET_BASE + '/monitoring_system/ajax_person_quotes.php?user_name=' + encodeURIComponent(uname) +
      '&from=' + encodeURIComponent(bounds.from) + '&to=' + encodeURIComponent(bounds.to) +
      '&filter=total' + qs)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        personRenderQuotesTable(data.success ? data.quotes : [], 'Unable to load.', 'total');
      })
      .catch(function () { personRenderQuotesTable([], 'Unable to load.', 'total'); });
    return;
  }

  if (personDrillOrg) {
    // That org's own quotes, one level deeper than the companies list — reuses the same quotes
    // endpoint, scoped with &org=.
    backBtn.style.display = '';
    document.getElementById('personDrillLabel').textContent = 'Organisation: ' + personDrillOrg;
    fetch(ASSET_BASE + '/monitoring_system/ajax_person_quotes.php?user_name=' + encodeURIComponent(uname) +
      '&from=' + encodeURIComponent(bounds.from) + '&to=' + encodeURIComponent(bounds.to) +
      '&filter=total&org=' + encodeURIComponent(personDrillOrg))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        personRenderQuotesTable(data.success ? data.quotes : [], 'Unable to load.', 'total');
      })
      .catch(function () { personRenderQuotesTable([], 'Unable to load.', 'total'); });
    return;
  }

  backBtn.style.display = 'none';
  document.getElementById('personDrillLabel').textContent = PERSON_DRILL_LABELS[personBookFilter] || personBookFilter;

  if (personBookFilter === 'companies') {
    fetch(ASSET_BASE + '/monitoring_system/ajax_person_companies.php?user_name=' + encodeURIComponent(uname) +
      '&from=' + encodeURIComponent(bounds.from) + '&to=' + encodeURIComponent(bounds.to))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        personRenderCompaniesTable(data.success ? data.companies : []);
      })
      .catch(function () { personRenderCompaniesTable([]); });
    return;
  }

  fetch(ASSET_BASE + '/monitoring_system/ajax_person_quotes.php?user_name=' + encodeURIComponent(uname) +
    '&from=' + encodeURIComponent(bounds.from) + '&to=' + encodeURIComponent(bounds.to) +
    '&filter=' + encodeURIComponent(personBookFilter))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      personRenderQuotesTable(data.success ? data.quotes : [], 'No quotes in this bucket for this range.', personBookFilter);
    })
    .catch(function () { personRenderQuotesTable([], 'Unable to load.', personBookFilter); });
}

document.getElementById('personSummaryKpis').addEventListener('click', function (e) {
  const tile = e.target.closest('[data-book-filter]');
  if (!tile) return;
  const key = tile.dataset.bookFilter;
  // Reopening any tile starts fresh, never mid-company or mid-breakdown from a previous look.
  personDrillOrg = null;
  personDrillRegion = null;
  personDrillPriority = null;
  personBookFilter = (personBookFilter === key) ? null : key;
  personRenderDrill();
});

document.getElementById('personDrillBack').addEventListener('click', function () {
  personDrillOrg = null;
  personRenderDrill();
});

document.getElementById('personDrillClose').addEventListener('click', function () {
  personBookFilter = null;
  personDrillOrg = null;
  personDrillRegion = null;
  personDrillPriority = null;
  personRenderDrill();
});

// Live book by travel month, same bucket shape as build_travel_date_horizon(): current
// month + next 11, then a "13+ months" overflow. Computed client-side from PERSON_LIVE_BOOK
// since the same rows back the table below.
function personLiveBookMonths() {
  const months = [];
  const cursor = new Date(TODAY + 'T00:00:00');
  cursor.setDate(1);
  for (let i = 0; i < 12; i++) {
    months.push(cursor.toLocaleString('en-US', { month: 'short' }) + ' ' + cursor.getFullYear());
    cursor.setMonth(cursor.getMonth() + 1);
  }
  months.push('13+ months');
  return months;
}
const PERSON_LIVE_BOOK_MONTHS = personLiveBookMonths();

// Returns null for no travel_date or a date before this month — excluded from the chart's
// buckets, though it still belongs in the table.
function personLiveBookMonthKey(travelDate) {
  if (!travelDate) return null;
  const d = new Date(travelDate + 'T00:00:00');
  const today = new Date(TODAY + 'T00:00:00');
  const diff = (d.getFullYear() - today.getFullYear()) * 12 + (d.getMonth() - today.getMonth());
  if (diff < 0) return null;
  return diff < 12 ? PERSON_LIVE_BOOK_MONTHS[diff] : '13+ months';
}

let personLiveBookChart = null;

function personRenderLiveBookChart() {
  const counts = PERSON_LIVE_BOOK_MONTHS.map(function (m) {
    return PERSON_LIVE_BOOK.filter(function (r) { return personLiveBookMonthKey(r.travel_date) === m; }).length;
  });
  document.getElementById('personLiveBookTotalChip').textContent = PERSON_LIVE_BOOK.length + ' total';
  if (!personLiveBookChart) {
    personLiveBookChart = new Chart(document.getElementById('chartPersonLiveBook'), {
      type: 'bar',
      data: {
        labels: PERSON_LIVE_BOOK_MONTHS,
        datasets: [{ data: counts, backgroundColor: PALETTE.teal, borderRadius: 3 }],
      },
      options: Object.assign({}, CHART_BASE_OPTIONS, {
        plugins: Object.assign({}, CHART_BASE_OPTIONS.plugins, { legend: { display: false } }),
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        onClick: function (evt, els) {
          if (!els.length) return;
          const label = PERSON_LIVE_BOOK_MONTHS[els[0].index];
          personLiveBookUpcomingFilter = null;
          personLiveBookMonthFilter = (personLiveBookMonthFilter === label) ? null : label;
          personRenderLiveBookTable();
        },
      }),
    });
  } else {
    personLiveBookChart.data.datasets[0].data = counts;
    personLiveBookChart.update();
  }
}

// Upcoming follow-ups — this caller's next_call_date distribution, computed client-side from the
// same PERSON_LIVE_BOOK rows as the travel-month chart above (no second fetch). Near-term days
// get their own bar (the actionable window a caller actually plans around); everything past a
// week folds into "Next week"/"2+ weeks" so the axis doesn't turn into a month of near-empty bars.
function personUpcomingBuckets() {
  const buckets = ['Overdue', 'Today', 'Tomorrow'];
  const cursor = new Date(TODAY + 'T00:00:00');
  for (let i = 2; i <= 6; i++) {
    const d = new Date(cursor);
    d.setDate(d.getDate() + i);
    buckets.push(formatDateLabel(personFmt(d)));
  }
  buckets.push('Next week', '2+ weeks', 'Not scheduled');
  return buckets;
}
const PERSON_UPCOMING_BUCKETS = personUpcomingBuckets();

function personUpcomingBucketKey(nextCallDate) {
  if (!nextCallDate) return 'Not scheduled';
  const d = new Date(nextCallDate + 'T00:00:00');
  const today = new Date(TODAY + 'T00:00:00');
  const diffDays = Math.round((d - today) / 86400000);
  if (diffDays < 0) return 'Overdue';
  if (diffDays === 0) return 'Today';
  if (diffDays === 1) return 'Tomorrow';
  if (diffDays <= 6) return formatDateLabel(nextCallDate);
  if (diffDays <= 13) return 'Next week';
  return '2+ weeks';
}

let personUpcomingChart = null;

function personRenderUpcomingChart() {
  const counts = PERSON_UPCOMING_BUCKETS.map(function (b) {
    return PERSON_LIVE_BOOK.filter(function (r) { return personUpcomingBucketKey(r.next_call_date) === b; }).length;
  });
  // Overdue is the one bucket that actually needs calling now — coloured to stand out rather
  // than blending into the rest of the forward-looking bars.
  const colours = PERSON_UPCOMING_BUCKETS.map(function (b) { return b === 'Overdue' ? PALETTE.alert : PALETTE.teal; });

  if (!personUpcomingChart) {
    personUpcomingChart = new Chart(document.getElementById('chartPersonUpcoming'), {
      type: 'bar',
      data: {
        labels: PERSON_UPCOMING_BUCKETS,
        datasets: [{ data: counts, backgroundColor: colours, borderRadius: 3 }],
      },
      options: Object.assign({}, CHART_BASE_OPTIONS, {
        plugins: Object.assign({}, CHART_BASE_OPTIONS.plugins, { legend: { display: false } }),
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        onClick: function (evt, els) {
          if (!els.length) return;
          const label = PERSON_UPCOMING_BUCKETS[els[0].index];
          personLiveBookMonthFilter = null;
          personLiveBookUpcomingFilter = (personLiveBookUpcomingFilter === label) ? null : label;
          personRenderLiveBookTable();
        },
      }),
    });
  } else {
    personUpcomingChart.data.datasets[0].data = counts;
    personUpcomingChart.data.datasets[0].backgroundColor = colours;
    personUpcomingChart.update();
  }
}

const PERSON_LIVE_BOOK_SORT_FIELDS = {
  quote_no: function (r) { return r.quote_no; },
  organization_name: function (r) { return r.organization_name || ''; },
  priority: function (r) { return r.priority || 'Blank'; },
  travel_date: function (r) { return r.travel_date || '9999-99-99'; },
  last_call_at: function (r) { return r.last_call_at || ''; },
  call_count: function (r) { return r.call_count; },
  next_call_date: function (r) { return r.next_call_date || '9999-99-99'; },
  days_since_contact: function (r) { return r.days_since_contact; },
};

function personLiveBookSortArrow(key) {
  return personLiveBookSort.key === key ? (personLiveBookSort.dir === 1 ? ' ▲' : ' ▼') : '';
}

function personRenderLiveBookTable() {
  let rows;
  if (personLiveBookMonthFilter) {
    rows = PERSON_LIVE_BOOK.filter(function (r) { return personLiveBookMonthKey(r.travel_date) === personLiveBookMonthFilter; });
  } else if (personLiveBookUpcomingFilter) {
    rows = PERSON_LIVE_BOOK.filter(function (r) { return personUpcomingBucketKey(r.next_call_date) === personLiveBookUpcomingFilter; });
  } else {
    rows = PERSON_LIVE_BOOK.slice();
  }

  const search = (document.getElementById('personLiveBookSearch').value || '').toLowerCase();
  const prioFilter = document.getElementById('personLiveBookPriorityFilter').value;
  rows = rows.filter(function (r) {
    if (search) {
      const org = (r.organization_name || '').toLowerCase();
      const qn = (r.quote_no || '').toLowerCase();
      if (org.indexOf(search) === -1 && qn.indexOf(search) === -1) return false;
    }
    if (prioFilter !== 'all') {
      const p = r.priority || 'Blank';
      if (p !== prioFilter) return false;
    }
    return true;
  });

  const extract = PERSON_LIVE_BOOK_SORT_FIELDS[personLiveBookSort.key];
  rows.sort(function (a, b) {
    const va = extract(a), vb = extract(b);
    if (va < vb) return -1 * personLiveBookSort.dir;
    if (va > vb) return 1 * personLiveBookSort.dir;
    return 0;
  });

  const activeChartFilter = personLiveBookMonthFilter || personLiveBookUpcomingFilter;
  document.getElementById('personLiveBookFilterLabel').textContent = activeChartFilter ? ('— ' + activeChartFilter) : '';
  document.getElementById('personLiveBookClearMonth').style.display = activeChartFilter ? '' : 'none';

  const thead = document.querySelector('#personLiveBookTable thead');
  const tbody = document.querySelector('#personLiveBookTable tbody');
  thead.innerHTML = '<tr>' +
    '<th data-sort="quote_no">Quote #' + personLiveBookSortArrow('quote_no') + '</th>' +
    '<th data-sort="organization_name">Organisation' + personLiveBookSortArrow('organization_name') + '</th>' +
    '<th data-sort="priority">Priority' + personLiveBookSortArrow('priority') + '</th>' +
    '<th data-sort="travel_date">Travel date' + personLiveBookSortArrow('travel_date') + '</th>' +
    '<th data-sort="last_call_at">Last call' + personLiveBookSortArrow('last_call_at') + '</th>' +
    '<th data-sort="call_count">Times contacted' + personLiveBookSortArrow('call_count') + '</th>' +
    '<th data-sort="next_call_date">Next call' + personLiveBookSortArrow('next_call_date') + '</th>' +
    '<th data-sort="days_since_contact">Days since contact' + personLiveBookSortArrow('days_since_contact') + '</th>' +
    '</tr>';

  if (!rows.length) {
    let filterNote = '';
    if (personLiveBookMonthFilter) filterNote = ' travelling ' + personLiveBookMonthFilter;
    else if (personLiveBookUpcomingFilter) filterNote = ' due "' + personLiveBookUpcomingFilter + '"';
    tbody.innerHTML = '<tr><td colspan="8" style="color:var(--faint);font-style:italic;">No live quotes' +
      filterNote + ' for this caller.</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map(function (r) {
    return '<tr class="personLiveBookRow" data-quoteid="' + r.quoteid + '">' +
      '<td>' + personEscapeHtml(r.quote_no) + '</td>' +
      '<td>' + personEscapeHtml(r.organization_name || '—') + '</td>' +
      '<td>' + personEscapeHtml(r.priority || 'Blank') + '</td>' +
      '<td>' + (r.travel_date ? personEscapeHtml(personFormatEventDate(r.travel_date)) : '<span style="color:var(--faint);font-style:italic;">Not set</span>') + '</td>' +
      '<td>' + (r.last_call_at ? personEscapeHtml(personFormatEventDate(r.last_call_at)) : '<span style="color:var(--faint);font-style:italic;">Never contacted</span>') + '</td>' +
      '<td>' + r.call_count + '</td>' +
      '<td>' + (r.next_call_date ? personEscapeHtml(personFormatEventDate(r.next_call_date)) : '<span style="color:var(--faint);">Not scheduled</span>') + '</td>' +
      '<td>' + r.days_since_contact + '</td>' +
      '</tr>';
  }).join('');

  Array.prototype.forEach.call(tbody.querySelectorAll('.personLiveBookRow'), function (tr) {
    tr.addEventListener('click', function () { personOpenTimeline(tr.dataset.quoteid); });
  });
}

function personRenderLiveBook() {
  const uname = document.getElementById('personCallerSelect').value;
  personLiveBookMonthFilter = null;
  personLiveBookUpcomingFilter = null;
  fetch(ASSET_BASE + '/monitoring_system/ajax_person_live_book.php?user_name=' + encodeURIComponent(uname))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      PERSON_LIVE_BOOK = data.success ? data.quotes : [];
      personRenderLiveBookChart();
      personRenderUpcomingChart();
      personRenderLiveBookTable();
    })
    .catch(function () {
      PERSON_LIVE_BOOK = [];
      personRenderLiveBookChart();
      personRenderUpcomingChart();
      personRenderLiveBookTable();
    });
}

document.getElementById('personLiveBookClearMonth').addEventListener('click', function () {
  personLiveBookMonthFilter = null;
  personLiveBookUpcomingFilter = null;
  personRenderLiveBookTable();
});
document.getElementById('personLiveBookSearch').addEventListener('input', personRenderLiveBookTable);
document.getElementById('personLiveBookPriorityFilter').addEventListener('change', personRenderLiveBookTable);
document.querySelector('#personLiveBookTable thead').addEventListener('click', function (e) {
  const th = e.target.closest('[data-sort]');
  if (!th) return;
  const key = th.dataset.sort;
  if (personLiveBookSort.key === key) { personLiveBookSort.dir *= -1; } else { personLiveBookSort = { key: key, dir: 1 }; }
  personRenderLiveBookTable();
});

personRenderLiveBook();
