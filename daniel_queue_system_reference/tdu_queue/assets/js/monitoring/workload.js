  // "Quotes owned right now" — live snapshot (no date axis), entity-name x-axis, Per person/Total
  // toggle. Live-filtered — connected to onFilterChange() below.
  let quotesOwnedMode = 'split'; // 'split' = one bar per caller, 'total' = one bar per queue

  const chartQuotesOwned = new Chart(document.getElementById('chartQuotesOwned'), {
    type: 'bar',
    data: {
      labels: [],
      datasets: [{
        label: 'Quotes owned',
        data: [],
        backgroundColor: [],
      }],
    },
    options: {
      ...CHART_BASE_OPTIONS,
      plugins: {
        ...CHART_BASE_OPTIONS.plugins,
        legend: {
          display: false
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        },
      },
    },
  });

  function renderQuotesOwned() {
    if (quotesOwnedMode === 'total') {
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      chartQuotesOwned.data.labels = queues;
      chartQuotesOwned.data.datasets[0].data = queues.map(queue => SELECTED_CALLERS
        .filter(u => queueOf(u) === queue)
        .reduce((sum, u) => sum + (QUOTES_OWNED_BY_CALLER[u] ?? 0), 0));
      chartQuotesOwned.data.datasets[0].backgroundColor = queues.map(q => QUEUE_COLOURS[q] || '#999');
    } else {
      chartQuotesOwned.data.labels = SELECTED_CALLERS.map(u => CALLERS_MAP[u]);
      chartQuotesOwned.data.datasets[0].data = SELECTED_CALLERS.map(u => QUOTES_OWNED_BY_CALLER[u] ?? 0);
      chartQuotesOwned.data.datasets[0].backgroundColor = SELECTED_CALLERS.map(callerColour);
    }
    chartQuotesOwned.update();
  }
  renderQuotesOwned();

  document.getElementById('aggQuotesOwned').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    quotesOwnedMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderQuotesOwned();
  });

  // Backlog size (build_backlog_size_trend): line only, no area fill (would just add noise
  // under 2-4 overlapping
  // lines). Wired to both the live filter and the Per person/Total toggle; renderBacklogSize()
  // rebuilds datasets from SELECTED_CALLERS + backlogMode.
  let backlogMode = 'split'; // 'split' = one line per caller, 'total' = one line per queue

  const chartBacklogSize = new Chart(document.getElementById('chartBacklogSize'), {
    type: 'line',
    data: {
      labels: [],
      datasets: []
    },
    options: {
      ...CHART_BASE_OPTIONS,
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        },
      },
    },
  });

  function renderBacklogSize() {
    const days = sortedDateKeys(DAILY_BACKLOG_SIZE);
    chartBacklogSize.data.labels = days.map(formatDateLabel);

    if (backlogMode === 'total') {
      // One line per QUEUE, never mixed together — different populations.
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      chartBacklogSize.data.datasets = queues.map(queue => {
        const members = SELECTED_CALLERS.filter(u => queueOf(u) === queue);
        return {
          label: queue,
          data: days.map(d => members.reduce((sum, u) => sum + (DAILY_BACKLOG_SIZE[d]?.[u] ?? 0), 0)),
          borderColor: QUEUE_COLOURS[queue] || '#999',
          tension: 0.2,
        };
      });
    } else {
      chartBacklogSize.data.datasets = SELECTED_CALLERS.map(uname => ({
        label: CALLERS_MAP[uname],
        data: days.map(d => DAILY_BACKLOG_SIZE[d]?.[uname] ?? 0),
        borderColor: callerColour(uname),
        tension: 0.2,
      }));
    }
    chartBacklogSize.update();
  }
  renderBacklogSize();

  document.getElementById('aggBacklog').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    backlogMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderBacklogSize();
  });

  // Backlog age heat-strip (build_backlog_age_today): TODAY ONLY. One cell pair per selected
  // caller: green for 0-2d,
  // red for 3d+ (matches CARRYOVER_RED_DAYS). Live-filtered — re-run from onFilterChange() below.
  const AGE_COLOURS = {
    '0-2d': '#27ae60',
    '3d+': '#C0392B'
  };

  function renderBacklogAgeHeat() {
    const el = document.getElementById('backlogAgeHeat');
    el.innerHTML = SELECTED_CALLERS.map(uname => {
      const ages = BACKLOG_AGE_TODAY[uname] ?? {
        '0-2d': 0,
        '3d+': 0
      };
      const cells = ['0-2d', '3d+'].map(bucket =>
        `<div class="cell" style="background:${AGE_COLOURS[bucket]}">${bucket}: ${ages[bucket] ?? 0}</div>`
      ).join('');
      return `<div>
      <div class="who">${CALLERS_MAP[uname]}</div>
      <div class="cells">${cells}</div>
    </div>`;
    }).join('');
  }
  renderBacklogAgeHeat();

  // Coverage (build_daily_coverage): daily % trend, business-wide/single line, no
  // per-caller/queue split. Stays
  // outside the live caller filter, rendered once here (not from onFilterChange()).
  const coverageDays = sortedDateKeys(DAILY_COVERAGE);
  new Chart(document.getElementById('chartCoverage'), {
    type: 'line',
    data: {
      labels: coverageDays.map(formatDateLabel),
      datasets: [{
        label: 'Coverage',
        data: coverageDays.map(d => {
          const c = DAILY_COVERAGE[d];
          return c && c.qualifying > 0 ? Math.round((c.surfaced / c.qualifying) * 1000) / 10 : null;
        }),
        borderColor: PALETTE.teal,
        tension: 0.2,
      }],
    },
    options: {
      ...CHART_BASE_OPTIONS,
      scales: {
        y: {
          beginAtZero: true,
          max: 100,
          ticks: {
            callback: v => v + '%'
          },
        },
      },
    },
  });

  // Capacity vs demand (build_daily_capacity_demand): one merged Follow-up queue, since a
  // region owner works FIT and Groups against a single budget. Stacked SP1-4 bars against a
  // dashed reference line for the daily slot budget. Business-wide, stays outside the live
  // caller filter like coverage above.
  let capacityDemandQueue = 'Follow-up';

  const chartCapacityDemand = new Chart(document.getElementById('chartCapacityDemand'), {
    type: 'bar',
    data: {
      labels: [],
      datasets: []
    },
    options: {
      ...CHART_BASE_OPTIONS,
      scales: {
        x: {
          stacked: true
        },
        y: {
          stacked: true,
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        },
      },
    },
  });

  function renderCapacityDemand() {
    const q = DAILY_CAPACITY_DEMAND[capacityDemandQueue];
    const days = sortedDateKeys(q.daily);
    chartCapacityDemand.data.labels = days.map(formatDateLabel);
    chartCapacityDemand.data.datasets = [{
        label: 'SP1',
        data: days.map(d => q.daily[d].sp1),
        backgroundColor: PALETTE.sp1,
        stack: 'demand',
      },
      {
        label: 'SP2',
        data: days.map(d => q.daily[d].sp2),
        backgroundColor: PALETTE.sp2,
        stack: 'demand',
      },
      {
        label: 'SP3',
        data: days.map(d => q.daily[d].sp3),
        backgroundColor: PALETTE.sp3,
        stack: 'demand',
      },
      {
        label: 'SP4',
        data: days.map(d => q.daily[d].sp4),
        backgroundColor: PALETTE.sp4,
        stack: 'demand',
      },
      {
        type: 'line',
        label: 'Daily slot budget',
        data: days.map(() => q.capacity),
        borderColor: '#334155',
        borderDash: [6, 4],
        borderWidth: 2,
        pointRadius: 0,
        tension: 0,
      },
    ];
    chartCapacityDemand.update();
  }
  renderCapacityDemand();

  document.getElementById('aggCapacityDemand').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    capacityDemandQueue = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderCapacityDemand();
  });

  // Queue composition (build_queue_composition_trend): the two toggle modes use DIFFERENT axes.
  // "Total" is a daily trend (mirrors capacity vs demand's stacked shape, each selected queue its
  // own stack). "Per person" puts caller name on the x-axis, summing each caller's rows across
  // the window into one total per bucket (the same rolling-window collapse the outcome-mix and
  // channel-mix charts use). Both fed by one query, QUEUE_COMPOSITION.
  const SP_BUCKET_KEYS = ['sp1', 'sp2', 'sp3', 'sp4', 'carryover'];
  const SP_BUCKET_LABELS = {
    sp1: 'SP1',
    sp2: 'SP2',
    sp3: 'SP3',
    sp4: 'SP4',
    carryover: 'Carry-over'
  };
  let queueCompMode = 'total'; // 'total' = daily trend by queue, 'split' = per-caller rolling total

  const chartQueueComposition = new Chart(document.getElementById('chartQueueComposition'), {
    type: 'bar',
    data: {
      labels: [],
      datasets: []
    },
    options: {
      ...CHART_BASE_OPTIONS,
      // Chart.js's own legend is disabled — renderQueueCompLegend() below replaces it with a
      // custom HTML legend grouped by queue (guaranteed grouping, not text-width-dependent).
      plugins: {
        ...CHART_BASE_OPTIONS.plugins,
        legend: {
          display: false
        },
      },
      scales: {
        x: {
          stacked: true
        },
        y: {
          stacked: true,
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        },
      },
    },
  });

  // Custom legend for 4.3 — one row per queue in Total mode, a single row in Per person mode.
  // Regenerated on every render since mode/selection determine the rows and labels.
  function renderQueueCompLegend() {
    const el = document.getElementById('queueCompLegend');
    const dot = key => `<span class="legend-dot" style="background:${PALETTE[key]}"></span>`;

    if (queueCompMode === 'split') {
      el.innerHTML = `<div class="legend-row">${SP_BUCKET_KEYS.map(key =>
        `<span class="legend-item">${dot(key)}${SP_BUCKET_LABELS[key]}</span>`
      ).join('')}</div>`;
    } else {
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      el.innerHTML = queues.map(queue => `<div class="legend-row">${SP_BUCKET_KEYS.map(key =>
        `<span class="legend-item">${dot(key)}${queue} — ${SP_BUCKET_LABELS[key]}</span>`
      ).join('')}</div>`).join('');
    }
  }

  function renderQueueComposition() {
    if (queueCompMode === 'split') {
      chartQueueComposition.data.labels = SELECTED_CALLERS.map(u => CALLERS_MAP[u]);
      chartQueueComposition.data.datasets = SP_BUCKET_KEYS.map(key => ({
        label: SP_BUCKET_LABELS[key],
        data: SELECTED_CALLERS.map(u => {
          let total = 0;
          for (const day in QUEUE_COMPOSITION) total += QUEUE_COMPOSITION[day]?.[u]?.[key] ?? 0;
          return total;
        }),
        backgroundColor: PALETTE[key],
      }));
    } else {
      const days = sortedDateKeys(QUEUE_COMPOSITION);
      chartQueueComposition.data.labels = days.map(formatDateLabel);
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      chartQueueComposition.data.datasets = queues.flatMap(queue => {
        const members = SELECTED_CALLERS.filter(u => queueOf(u) === queue);
        return SP_BUCKET_KEYS.map(key => ({
          label: `${queue} — ${SP_BUCKET_LABELS[key]}`,
          data: days.map(d => members.reduce((sum, u) => sum + (QUEUE_COMPOSITION[d]?.[u]?.[key] ?? 0), 0)),
          backgroundColor: PALETTE[key],
          stack: queue,
        }));
      });
    }
    chartQueueComposition.update();
    renderQueueCompLegend();
  }
  renderQueueComposition();

  document.getElementById('aggQueueComposition').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    queueCompMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderQueueComposition();
  });

