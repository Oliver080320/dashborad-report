  // Unresponsive organisations (build_unresponsive_orgs): ranked top-10, not a trend, no
  // toggle, no live filter.
  // Single legend entry would be redundant with the card title, so it's hidden here.
  new Chart(document.getElementById('chartUnresponsiveOrgs'), {
    type: 'bar',
    data: {
      labels: UNRESPONSIVE_ORGS.map(o => o.organization_name),
      datasets: [{
        label: 'No answer rate',
        data: UNRESPONSIVE_ORGS.map(o => o.rate),
        backgroundColor: PALETTE.alert,
      }],
    },
    options: {
      ...CHART_BASE_OPTIONS,
      indexAxis: 'y',
      plugins: {
        ...CHART_BASE_OPTIONS.plugins,
        legend: {
          display: false
        },
        tooltip: {
          ...CHART_BASE_OPTIONS.plugins.tooltip,
          callbacks: {
            ...CHART_BASE_OPTIONS.plugins.tooltip.callbacks,
            label: (ctx) => {
              const org = UNRESPONSIVE_ORGS[ctx.dataIndex];
              return `${org.unanswered_days}/${org.total_days} contact days unanswered · ${org.quote_count} quote(s)`;
            }
          }
        },
      },
      scales: {
        x: {
          beginAtZero: true,
          max: 100,
          ticks: {
            callback: v => v + '%'
          },
        },
      },
    },
  });

  // Account insights (build_account_insights): exploratory, all-time, business-wide, no
  // onFilterChange() hook. Both
  // toggle modes read the same ACCOUNT_INSIGHTS dataset, only sort/filter differs client-side.
  // "By rejection rate" requires at least 3 resolved quotes, so one rejection can't top the list.
  let acctInsightsMode = 'volume'; // 'volume' = total desc, 'rate' = rejection % desc (min 3 resolved)
  let acctInsightsRows = []; // the currently-rendered, sorted/filtered slice — tooltip looks up by index

  const chartAccountInsights = new Chart(document.getElementById('chartAccountInsights'), {
    type: 'bar',
    data: {
      labels: [],
      datasets: []
    },
    options: {
      ...CHART_BASE_OPTIONS,
      indexAxis: 'y',
      plugins: {
        ...CHART_BASE_OPTIONS.plugins,
        tooltip: {
          ...CHART_BASE_OPTIONS.plugins.tooltip,
          callbacks: {
            ...CHART_BASE_OPTIONS.plugins.tooltip.callbacks,
            title: (items) => acctInsightsRows[items[0].dataIndex].organization_name,
            afterBody: (items) => {
              const org = acctInsightsRows[items[0].dataIndex];
              return org.resolved > 0 ? `Rejection rate: ${org.rate}% (of ${org.resolved} resolved)` : 'No resolved quotes yet';
            }
          }
        },
      },
      scales: {
        x: {
          stacked: true,
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        },
        y: {
          stacked: true
        },
      },
    },
  });

  // Long organisation names crowd the y-axis — truncate for the axis label only, tooltip title
  // still shows the full name.
  function truncateOrgName(name, max = 25) {
    return name.length > max ? name.slice(0, max - 1) + '…' : name;
  }

  function renderAccountInsights() {
    let orgs = ACCOUNT_INSIGHTS.slice();
    if (acctInsightsMode === 'rate') {
      orgs = orgs.filter(o => o.resolved >= 3);
      orgs.sort((a, b) => b.rate - a.rate);
    } else {
      orgs.sort((a, b) => b.total - a.total);
    }
    acctInsightsRows = orgs.slice(0, 10);

    chartAccountInsights.data.labels = acctInsightsRows.map(o => truncateOrgName(o.organization_name));
    chartAccountInsights.data.datasets = [{
        label: 'Accepted',
        data: acctInsightsRows.map(o => o.accepted),
        backgroundColor: PALETTE.sp3,
        stack: 'q',
      },
      {
        label: 'Rejected',
        data: acctInsightsRows.map(o => o.rejected),
        backgroundColor: PALETTE.alert,
        stack: 'q',
      },
      {
        label: 'In progress',
        data: acctInsightsRows.map(o => o.in_progress),
        backgroundColor: '#94A3B8',
        stack: 'q',
      },
    ];
    chartAccountInsights.update();
  }
  renderAccountInsights();

  document.getElementById('aggAccountInsights').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    acctInsightsMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderAccountInsights();
  });

  // "Why we lose" — business-wide, all-time, no caller filter. By category = the 4-bucket rollup
  // ajax_log_outcome.php derives server-side; By reason = the flat list, most-common first,
  // coloured by category so the grouping is still visible.
  const REJECTION_CATEGORY_LABELS = {
    company: 'Company-side (avoidable)',
    competition: 'Market / competition',
    client: 'Client-side (out of our hands)',
    other: 'Other'
  };
  const REJECTION_CATEGORY_COLOURS = {
    company: PALETTE.rejected,
    competition: PALETTE.requote,
    client: '#5C6B68',
    other: '#94A3B8'
  };
  // Matches ajax_log_outcome.php's $reason_category_map keys exactly.
  const REJECTION_REASON_LABELS = {
    lost_to_response_time: 'Lost to response time',
    too_expensive: 'Too expensive',
    went_with_competitor: 'Went with competitor',
    trip_cancelled: 'Trip cancelled',
    visa_issues: 'Visa issues',
    no_response_ghosted: 'Ghosted (no response)',
    destination_change: 'Destination change',
    not_ready_to_travel: 'Not ready to travel',
    other: 'Other'
  };
  let rejectionReasonsMode = 'category'; // 'category' = 4-bucket rollup, 'reason' = flat per-reason list
  let rejectionReasonRows = []; // currently-rendered rows in 'reason' mode — tooltip looks up by index

  const chartRejectionReasons = new Chart(document.getElementById('chartRejectionReasons'), {
    type: 'bar',
    data: {
      labels: [],
      datasets: []
    },
    options: {
      ...CHART_BASE_OPTIONS,
      indexAxis: 'y',
      plugins: {
        ...CHART_BASE_OPTIONS.plugins,
        legend: {
          display: false
        },
        tooltip: {
          ...CHART_BASE_OPTIONS.plugins.tooltip,
          callbacks: {
            ...CHART_BASE_OPTIONS.plugins.tooltip.callbacks,
            afterLabel: (ctx) => {
              if (rejectionReasonsMode !== 'reason') return '';
              const row = rejectionReasonRows[ctx.dataIndex];
              return row ? `Category: ${REJECTION_CATEGORY_LABELS[row.category] || row.category}` : '';
            },
          },
        },
      },
      scales: {
        x: {
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        },
      },
    },
  });

  function renderRejectionReasons() {
    if (rejectionReasonsMode === 'reason') {
      rejectionReasonRows = REJECTION_REASONS.by_reason.slice().sort((a, b) => b.n - a.n);
      chartRejectionReasons.data.labels = rejectionReasonRows.map(r => REJECTION_REASON_LABELS[r.reason] || r.reason);
      chartRejectionReasons.data.datasets = [{
        label: 'Rejections',
        data: rejectionReasonRows.map(r => r.n),
        backgroundColor: rejectionReasonRows.map(r => REJECTION_CATEGORY_COLOURS[r.category] || '#999'),
      }];
    } else {
      const cats = Object.keys(REJECTION_CATEGORY_LABELS);
      chartRejectionReasons.data.labels = cats.map(c => REJECTION_CATEGORY_LABELS[c]);
      chartRejectionReasons.data.datasets = [{
        label: 'Rejections',
        data: cats.map(c => REJECTION_REASONS.by_category[c] ?? 0),
        backgroundColor: cats.map(c => REJECTION_CATEGORY_COLOURS[c]),
      }];
    }
    chartRejectionReasons.update();
  }
  renderRejectionReasons();

  document.getElementById('aggRejectionReasons').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    rejectionReasonsMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderRejectionReasons();
  });

