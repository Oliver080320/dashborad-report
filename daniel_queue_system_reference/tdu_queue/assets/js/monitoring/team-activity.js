  // % queue worked (fetch_daily_surfaced_worked): daily, live-filtered, Per person/Total
  // toggle. "Total" recombines from
  // summed surfaced/worked counts within a queue, never averaged percentages (see combineKPIs()).
  let pctWorkedMode = 'split'; // 'split' = one line per caller, 'total' = one line per queue

  const chartPctWorked = new Chart(document.getElementById('chartPctWorked'), {
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
          max: 100,
          ticks: {
            callback: v => v + '%'
          },
        },
      },
    },
  });

  function pctOf(counts) {
    return counts && counts.surfaced > 0 ? Math.round((counts.worked / counts.surfaced) * 1000) / 10 : null;
  }

  function renderPctWorked() {
    const days = sortedDateKeys(DAILY_SURFACED_WORKED);
    chartPctWorked.data.labels = days.map(formatDateLabel);

    if (pctWorkedMode === 'total') {
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      chartPctWorked.data.datasets = queues.map(queue => {
        const members = SELECTED_CALLERS.filter(u => queueOf(u) === queue);
        return {
          label: queue,
          data: days.map(d => pctOf(members.reduce((acc, u) => {
            const c = DAILY_SURFACED_WORKED[d]?.[u];
            if (c) {
              acc.surfaced += c.surfaced;
              acc.worked += c.worked;
            }
            return acc;
          }, {
            surfaced: 0,
            worked: 0
          }))),
          borderColor: QUEUE_COLOURS[queue] || '#999',
          tension: 0.2,
        };
      });
    } else {
      chartPctWorked.data.datasets = SELECTED_CALLERS.map(uname => ({
        label: CALLERS_MAP[uname],
        data: days.map(d => pctOf(DAILY_SURFACED_WORKED[d]?.[uname])),
        borderColor: callerColour(uname),
        tension: 0.2,
      }));
    }
    chartPctWorked.update();
  }
  renderPctWorked();

  document.getElementById('aggPctWorked').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    pctWorkedMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderPctWorked();
  });

  // Calls logged (build_daily_calls_logged_trend): daily, live-filtered, Per person/Total
  // toggle. "Total" is one bar per
  // QUEUE, never mixed across India FIT + Groups & MICE. Each bar also carries a "ghost" —
  // a flat grey rectangle drawn behind it up to that day's surfaced count (DAILY_SURFACED_WORKED,
  // the same dataset the % queue worked chart uses), so the coloured bar visibly "fills" the
  // target: short of it if under, fully covering it if at or past. Drawn by the
  // CALLS_LOGGED_GHOST plugin below.
  let callsLoggedMode = 'split'; // 'total' = one bar per queue, 'split' = one bar per caller (default)

  const CALLS_LOGGED_GHOST = {
    id: 'callsLoggedGhost',
    beforeDatasetsDraw(chart) {
      const ctx = chart.ctx;
      chart.data.datasets.forEach((dataset, dsIndex) => {
        if (!dataset.targets) return;
        const meta = chart.getDatasetMeta(dsIndex);
        meta.data.forEach((bar, i) => {
          const target = dataset.targets[i];
          if (target == null) return;
          const top = chart.scales.y.getPixelForValue(target);
          const bottom = bar.base;
          const h = bottom - top;
          if (h <= 0) return;
          ctx.save();
          ctx.fillStyle = '#E2E8F0';
          ctx.fillRect(bar.x - bar.width / 2, top, bar.width, h);
          ctx.restore();
        });
      });
    }
  };

  const chartQuotesCalls = new Chart(document.getElementById('chartQuotesCalls'), {
    type: 'bar',
    data: {
      labels: [],
      datasets: []
    },
    options: {
      ...CHART_BASE_OPTIONS,
      plugins: {
        ...CHART_BASE_OPTIONS.plugins,
        tooltip: {
          ...CHART_BASE_OPTIONS.plugins.tooltip,
          callbacks: {
            ...CHART_BASE_OPTIONS.plugins.tooltip.callbacks,
            afterLabel(ctx) {
              const t = ctx.dataset.targets ? ctx.dataset.targets[ctx.dataIndex] : null;
              return t != null ? `Surfaced that day: ${t}` : '';
            }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        }
      },
    },
    plugins: [CALLS_LOGGED_GHOST],
  });

  function renderCallsLogged() {
    const days = sortedDateKeys(DAILY_CALLS_LOGGED);
    chartQuotesCalls.data.labels = days.map(formatDateLabel);
    let maxVal = 1;

    if (callsLoggedMode === 'split') {
      chartQuotesCalls.data.datasets = SELECTED_CALLERS.map(uname => {
        const data = days.map(d => DAILY_CALLS_LOGGED[d]?.[uname] ?? 0);
        const targets = days.map(d => DAILY_SURFACED_WORKED[d]?.[uname]?.surfaced ?? null);
        data.forEach(v => { if (v > maxVal) maxVal = v; });
        targets.forEach(v => { if (v != null && v > maxVal) maxVal = v; });
        return {
          label: CALLERS_MAP[uname],
          data,
          backgroundColor: callerColour(uname),
          borderRadius: 3,
          targets,
        };
      });
    } else {
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      chartQuotesCalls.data.datasets = queues.map(queue => {
        const members = SELECTED_CALLERS.filter(u => queueOf(u) === queue);
        const data = days.map(d => members.reduce((sum, u) => sum + (DAILY_CALLS_LOGGED[d]?.[u] ?? 0), 0));
        const targets = days.map(d => members.reduce((sum, u) => sum + (DAILY_SURFACED_WORKED[d]?.[u]?.surfaced ?? 0), 0));
        data.forEach(v => { if (v > maxVal) maxVal = v; });
        targets.forEach(v => { if (v > maxVal) maxVal = v; });
        return {
          label: queue,
          data,
          backgroundColor: QUEUE_COLOURS[queue] || '#999',
          borderRadius: 3,
          targets,
        };
      });
    }
    chartQuotesCalls.options.scales.y.suggestedMax = Math.ceil(maxVal * 1.15);
    chartQuotesCalls.update();
  }
  renderCallsLogged();

  document.getElementById('aggCallsLogged').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    callsLoggedMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderCallsLogged();
  });

  // Outcome mix (build_outcome_mix_totals): Per person/Total toggle, live-filtered. NOT a
  // day-by-day trend like the charts above, because date on the x-axis made an unreadable
  // stacked grid, so both modes put entity
  // name (caller/queue) on the x-axis instead, summing OUTCOME_MIX_TOTALS' rolling 14-day window.
  const OUTCOME_KEYS = ['next_call', 'no_answer_email', 'interested', 'inbound_call'];
  const OUTCOME_COLOURS = {
    next_call: PALETTE.ocNext,
    no_answer_email: PALETTE.ocNoAns,
    interested: PALETTE.ocInt,
    inbound_call: PALETTE.ocInb
  };
  // Channel mix chart's categories — matches ajax_log_outcome.php's server-side valid_channels
  // list, not the fuller channel list elsewhere in the project (linkedin, in_person aren't
  // offered by the outcome modal, so they never appear in logged data).
  const CHANNEL_KEYS = ['phone', 'whatsapp', 'email', 'other'];
  const CHANNEL_COLOURS = {
    phone: '#0F766E',
    whatsapp: '#27AE60',
    email: '#C28A3E',
    other: '#94A3B8'
  };
  let outcomeMixMode = 'split'; // 'split' = one bar per caller, 'total' = one bar per queue

  const chartOutcomeMix = new Chart(document.getElementById('chartOutcomeMix'), {
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

  function renderOutcomeMix() {
    if (outcomeMixMode === 'total') {
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      chartOutcomeMix.data.labels = queues;
      chartOutcomeMix.data.datasets = OUTCOME_KEYS.map(key => ({
        label: key,
        data: queues.map(queue => SELECTED_CALLERS
          .filter(u => queueOf(u) === queue)
          .reduce((sum, u) => sum + (OUTCOME_MIX_TOTALS[u]?.[key] ?? 0), 0)),
        backgroundColor: OUTCOME_COLOURS[key],
      }));
    } else {
      chartOutcomeMix.data.labels = SELECTED_CALLERS.map(u => CALLERS_MAP[u]);
      chartOutcomeMix.data.datasets = OUTCOME_KEYS.map(key => ({
        label: key,
        data: SELECTED_CALLERS.map(u => OUTCOME_MIX_TOTALS[u]?.[key] ?? 0),
        backgroundColor: OUTCOME_COLOURS[key],
      }));
    }
    chartOutcomeMix.update();
  }
  renderOutcomeMix();

  document.getElementById('aggOutcomeMix').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    outcomeMixMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderOutcomeMix();
  });

  // Contact rate (build_daily_contact_rate): daily, live-filtered, Per person/Total toggle,
  // mirrors the % queue worked pattern:
  // raw counts in DAILY_CONTACT_RATE, percentage derived here so "Total" recombines from summed
  // counts, never averaged.
  let contactRateMode = 'split'; // 'split' = one line per caller, 'total' = one line per queue

  const chartContactRate = new Chart(document.getElementById('chartContactRate'), {
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
          max: 100,
          ticks: {
            callback: v => v + '%'
          },
        },
      },
    },
  });

  function contactRateOf(counts) {
    const total = counts ? counts.reached + counts.not_reached : 0;
    return total > 0 ? Math.round((counts.reached / total) * 1000) / 10 : null;
  }

  function renderContactRate() {
    const days = sortedDateKeys(DAILY_CONTACT_RATE);
    chartContactRate.data.labels = days.map(formatDateLabel);

    if (contactRateMode === 'total') {
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      chartContactRate.data.datasets = queues.map(queue => {
        const members = SELECTED_CALLERS.filter(u => queueOf(u) === queue);
        return {
          label: queue,
          data: days.map(d => contactRateOf(members.reduce((acc, u) => {
            const c = DAILY_CONTACT_RATE[d]?.[u];
            if (c) {
              acc.reached += c.reached;
              acc.not_reached += c.not_reached;
            }
            return acc;
          }, {
            reached: 0,
            not_reached: 0
          }))),
          borderColor: QUEUE_COLOURS[queue] || '#999',
          tension: 0.2,
        };
      });
    } else {
      chartContactRate.data.datasets = SELECTED_CALLERS.map(uname => ({
        label: CALLERS_MAP[uname],
        data: days.map(d => contactRateOf(DAILY_CONTACT_RATE[d]?.[uname])),
        borderColor: callerColour(uname),
        tension: 0.2,
      }));
    }
    chartContactRate.update();
  }
  renderContactRate();

  document.getElementById('aggContactRate').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    contactRateMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderContactRate();
  });

  // Accepted this month (build_daily_accepted_by_owner): daily, live-filtered, Per person/Total
  // toggle, month-to-date
  // window (not the usual rolling 14 days). Counted by the quote's CURRENT owner, not whoever
  // executed the stage change; quotes with no owner among the 4 configured callers show as
  // "Unassigned (FIT)"/"Unassigned (Groups)" instead of being dropped (card__foot caveats repeat
  // both points).
  let acceptedMode = 'split'; // 'split' = one bar per caller, 'total' = one bar per queue

  const chartAccepted = new Chart(document.getElementById('chartAccepted'), {
    type: 'bar',
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
        }
      },
    },
  });

  // Unassigned buckets aren't tied to a real caller (no owner among the 4 configured callers —
  // e.g. a quote created and accepted the same day via the main CRM), so they can't be filtered
  // by the caller checkboxes the way SELECTED_CALLERS is. Instead each one rides along with its
  // queue: "Unassigned (FIT)" only shows when at least one India FIT caller is selected, etc.
  const UNASSIGNED_KEY_BY_QUEUE = { 'India FIT': 'unassigned_fit', 'Groups & MICE': 'unassigned_groups' };
  const UNASSIGNED_LABEL = { unassigned_fit: 'Unassigned (FIT)', unassigned_groups: 'Unassigned (Groups)' };
  const UNASSIGNED_COLOUR = { unassigned_fit: '#94A3B8', unassigned_groups: '#CBD5E1' };

  function renderAcceptedByOwner() {
    const days = sortedDateKeys(DAILY_ACCEPTED_BY_OWNER);
    chartAccepted.data.labels = days.map(formatDateLabel);

    const activeQueues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
    const activeUnassignedKeys = activeQueues.map(q => UNASSIGNED_KEY_BY_QUEUE[q]).filter(Boolean);

    if (acceptedMode === 'split') {
      chartAccepted.data.datasets = [
        ...SELECTED_CALLERS.map(uname => ({
          label: CALLERS_MAP[uname],
          data: days.map(d => DAILY_ACCEPTED_BY_OWNER[d]?.[uname] ?? 0),
          backgroundColor: callerColour(uname),
        })),
        ...activeUnassignedKeys.map(key => ({
          label: UNASSIGNED_LABEL[key],
          data: days.map(d => DAILY_ACCEPTED_BY_OWNER[d]?.[key] ?? 0),
          backgroundColor: UNASSIGNED_COLOUR[key],
        })),
      ];
    } else {
      chartAccepted.data.datasets = activeQueues.map(queue => {
        const members = SELECTED_CALLERS.filter(u => queueOf(u) === queue);
        const unassignedKey = UNASSIGNED_KEY_BY_QUEUE[queue];
        return {
          label: queue,
          data: days.map(d => members.reduce((sum, u) => sum + (DAILY_ACCEPTED_BY_OWNER[d]?.[u] ?? 0), 0)
                              + (unassignedKey ? (DAILY_ACCEPTED_BY_OWNER[d]?.[unassignedKey] ?? 0) : 0)),
          backgroundColor: QUEUE_COLOURS[queue] || '#999',
        };
      });
    }
    chartAccepted.update();

    // Total chip — composition by queue (FIT/Groups) plus the grand total, summed across every
    // selected caller (and each active queue's Unassigned bucket), shown regardless of
    // Per person/Total mode. Unassigned quotes are folded into their queue's own count rather
    // than listed as a separate category (e.g. "13 FIT (2 unassigned) + 2 Groups = 15 total") —
    // showing "Unassigned" as if it were a third queue reads as a chunk of quotes nobody is
    // credited for, which isn't true (they're still Accepted FIT/Groups quotes, just without one
    // of the 4 configured callers as current owner) and invites confusion over who gets credit.
    // The "(N unassigned)" note is omitted entirely when N is 0, per the user's explicit call.
    const QUEUE_SHORT_LABEL = {
      'India FIT': 'FIT',
      'Groups & MICE': 'Groups'
    };
    const perQueue = {};
    const perQueueUnassigned = {};
    let total = 0;
    days.forEach(d => {
      SELECTED_CALLERS.forEach(u => {
        const n = DAILY_ACCEPTED_BY_OWNER[d]?.[u] ?? 0;
        total += n;
        const q = queueOf(u);
        if (q) perQueue[q] = (perQueue[q] || 0) + n;
      });
      activeQueues.forEach(q => {
        const key = UNASSIGNED_KEY_BY_QUEUE[q];
        const n = key ? (DAILY_ACCEPTED_BY_OWNER[d]?.[key] ?? 0) : 0;
        total += n;
        perQueue[q] = (perQueue[q] || 0) + n;
        perQueueUnassigned[q] = (perQueueUnassigned[q] || 0) + n;
      });
    });
    const composition = Object.keys(QUEUE_GROUPS)
      .filter(q => SELECTED_CALLERS.some(u => queueOf(u) === q))
      .map(q => {
        const unassigned = perQueueUnassigned[q] || 0;
        const note = unassigned > 0 ? ` (${unassigned} unassigned)` : '';
        return `${perQueue[q] || 0} ${QUEUE_SHORT_LABEL[q] || q}${note}`;
      })
      .join(' + ');
    document.getElementById('acceptedTotalChip').textContent = `${composition} = ${total} total`;
  }
  renderAcceptedByOwner();

  document.getElementById('aggAccepted').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    acceptedMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderAcceptedByOwner();
  });

  // Quote lifecycle (build_stage_lifecycle_by_owner): entity-name axis (caller/queue),
  // month-to-date totals, same resolution as the outcome-mix chart (a stack x many days x 4
  // callers is unreadable as a daily trend). Per-caller per the
  // user's call; card's caveat chip notes it's counted by current owner, not who worked it.
  const LIFECYCLE_KEYS = ['accepted', 'rejected', 'requote'];
  const LIFECYCLE_COLOURS = {
    accepted: PALETTE.accepted,
    rejected: PALETTE.rejected,
    requote: PALETTE.requote
  };
  let stageLifecycleMode = 'split'; // 'split' = one bar per caller, 'total' = one bar per queue

  const chartStageLifecycle = new Chart(document.getElementById('chartStageLifecycle'), {
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

  function renderStageLifecycle() {
    if (stageLifecycleMode === 'total') {
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      chartStageLifecycle.data.labels = queues;
      chartStageLifecycle.data.datasets = LIFECYCLE_KEYS.map(key => ({
        label: key,
        data: queues.map(queue => SELECTED_CALLERS
          .filter(u => queueOf(u) === queue)
          .reduce((sum, u) => sum + (STAGE_LIFECYCLE_BY_OWNER[u]?.[key] ?? 0), 0)),
        backgroundColor: LIFECYCLE_COLOURS[key],
      }));
    } else {
      chartStageLifecycle.data.labels = SELECTED_CALLERS.map(u => CALLERS_MAP[u]);
      chartStageLifecycle.data.datasets = LIFECYCLE_KEYS.map(key => ({
        label: key,
        data: SELECTED_CALLERS.map(u => STAGE_LIFECYCLE_BY_OWNER[u]?.[key] ?? 0),
        backgroundColor: LIFECYCLE_COLOURS[key],
      }));
    }
    chartStageLifecycle.update();
  }
  renderStageLifecycle();

  document.getElementById('aggStageLifecycle').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    stageLifecycleMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderStageLifecycle();
  });

  // Win rate trend: business-wide only, no per-caller/queue toggle (unlike the three
  // business-outcome charts above), since a ratio is easier to misread as an individual scorecard than
  // the raw counts they show. Stays out of the live caller filter, same treatment as coverage and
  // capacity vs demand. Days with zero
  // Accepted+Rejected are left as a gap (null), same convention as pctOf().
  const chartWinRate = new Chart(document.getElementById('chartWinRate'), {
    type: 'line',
    data: {
      labels: [],
      datasets: []
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
          max: 100,
          ticks: {
            callback: v => v + '%'
          },
        },
      },
    },
  });

  function winRateOf(day) {
    const n = WIN_RATE_TREND[day];
    const resolved = n ? n.accepted + n.rejected : 0;
    return resolved > 0 ? Math.round((n.accepted / resolved) * 1000) / 10 : null;
  }

  function renderWinRate() {
    const days = sortedDateKeys(WIN_RATE_TREND);
    chartWinRate.data.labels = days.map(formatDateLabel);
    chartWinRate.data.datasets = [{
      label: 'Win rate',
      data: days.map(winRateOf),
      borderColor: PALETTE.accepted,
      tension: 0.2,
    }];
    chartWinRate.update();

    // Cumulative chip — the month's total accepted/(accepted+rejected), not an average of daily percentages.
    let accepted = 0,
      rejected = 0;
    days.forEach(d => {
      accepted += WIN_RATE_TREND[d]?.accepted ?? 0;
      rejected += WIN_RATE_TREND[d]?.rejected ?? 0;
    });
    const resolved = accepted + rejected;
    const rate = resolved > 0 ? Math.round((accepted / resolved) * 1000) / 10 : 0;
    document.getElementById('winRateTotalChip').textContent = `${rate}% (${accepted}/${resolved})`;
  }
  renderWinRate();

  // Cycle time (build_cycle_time_by_owner): entity-name axis, single series (avg days),
  // month-to-date, same event population as the Accepted chart above. "Total" recombines a
  // WEIGHTED average (sum_days/n across the queue's
  // members), not an average of averages. Tooltip shows sample size n alongside the average.
  let cycleTimeMode = 'split'; // 'split' = one bar per caller, 'total' = one bar per queue

  const chartCycleTime = new Chart(document.getElementById('chartCycleTime'), {
    type: 'bar',
    data: {
      labels: [],
      datasets: []
    },
    options: {
      ...CHART_BASE_OPTIONS,
      plugins: {
        ...CHART_BASE_OPTIONS.plugins,
        tooltip: {
          ...CHART_BASE_OPTIONS.plugins.tooltip,
          callbacks: {
            ...CHART_BASE_OPTIONS.plugins.tooltip.callbacks,
            label: ctx => `${ctx.parsed.y} days avg (n=${ctx.dataset.counts[ctx.dataIndex]})`,
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        }
      },
    },
  });

  function avgDays(entry) {
    return entry && entry.n > 0 ? Math.round((entry.sum_days / entry.n) * 10) / 10 : 0;
  }

  function renderCycleTime() {
    if (cycleTimeMode === 'total') {
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      const combined = queues.map(queue => SELECTED_CALLERS
        .filter(u => queueOf(u) === queue)
        .reduce((acc, u) => {
          const e = CYCLE_TIME_BY_OWNER[u];
          if (e) {
            acc.sum_days += e.sum_days;
            acc.n += e.n;
          }
          return acc;
        }, {
          sum_days: 0,
          n: 0
        }));
      chartCycleTime.data.labels = queues;
      chartCycleTime.data.datasets = [{
        label: 'Avg days to Accepted',
        data: combined.map(avgDays),
        counts: combined.map(c => c.n),
        backgroundColor: queues.map(q => QUEUE_COLOURS[q] || '#999'),
      }];
    } else {
      chartCycleTime.data.labels = SELECTED_CALLERS.map(u => CALLERS_MAP[u]);
      chartCycleTime.data.datasets = [{
        label: 'Avg days to Accepted',
        data: SELECTED_CALLERS.map(u => avgDays(CYCLE_TIME_BY_OWNER[u])),
        counts: SELECTED_CALLERS.map(u => CYCLE_TIME_BY_OWNER[u]?.n ?? 0),
        backgroundColor: SELECTED_CALLERS.map(callerColour),
      }];
    }
    chartCycleTime.update();
  }
  renderCycleTime();

  document.getElementById('aggCycleTime').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    cycleTimeMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderCycleTime();
  });

  // Calls by hour of day (build_calls_by_hour): 90-day rolling distribution (not a 14-day
  // trend), live-filtered, Per person/Total toggle mirroring the calls-logged chart ("Total" =
  // one bar per queue, never mixed).
  let callsByHourMode = 'split'; // 'split' = one bar per caller, 'total' = one bar per queue

  const chartCallsByHour = new Chart(document.getElementById('chartCallsByHour'), {
    type: 'bar',
    data: {
      labels: [],
      datasets: []
    },
    options: {
      ...CHART_BASE_OPTIONS,
      scales: {
        y: {
          beginAtZero: true
        }
      },
    },
  });

  // Average, not raw sum — divides each hour's total by distinct active days, so days nobody
  // worked don't drag the average down. "Total" unions the selected members' active-day sets
  // rather than summing them, to avoid double-counting shared working days.
  function avgOf(total, days) {
    return days > 0 ? Math.round((total / days) * 10) / 10 : 0;
  }

  function renderCallsByHour() {
    const hours = sortedHourKeys(CALLS_BY_HOUR);
    chartCallsByHour.data.labels = hours.map(formatHourLabel);

    if (callsByHourMode === 'total') {
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      chartCallsByHour.data.datasets = queues.map(queue => {
        const members = SELECTED_CALLERS.filter(u => queueOf(u) === queue);
        const activeDays = new Set(members.flatMap(u => CALLS_BY_HOUR_ACTIVE_DAYS[u] || [])).size;
        return {
          label: queue,
          data: hours.map(h => avgOf(members.reduce((sum, u) => sum + (CALLS_BY_HOUR[h]?.[u] ?? 0), 0), activeDays)),
          backgroundColor: QUEUE_COLOURS[queue] || '#999',
        };
      });
    } else {
      chartCallsByHour.data.datasets = SELECTED_CALLERS.map(uname => {
        const activeDays = (CALLS_BY_HOUR_ACTIVE_DAYS[uname] || []).length;
        return {
          label: CALLERS_MAP[uname],
          data: hours.map(h => avgOf(CALLS_BY_HOUR[h]?.[uname] ?? 0, activeDays)),
          backgroundColor: callerColour(uname),
        };
      });
    }
    chartCallsByHour.update();
  }
  renderCallsByHour();

  document.getElementById('aggCallsByHour').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    callsByHourMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderCallsByHour();
  });

  // Channel mix (build_channel_mix_totals): same shape as the outcome-mix chart, entity name
  // (caller/queue) on the x-axis, summing
  // CHANNEL_MIX_TOTALS' rolling 14-day window.
  let channelMixMode = 'split'; // 'split' = one bar per caller, 'total' = one bar per queue

  const chartChannelMix = new Chart(document.getElementById('chartChannelMix'), {
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

  function renderChannelMix() {
    if (channelMixMode === 'total') {
      const queues = [...new Set(SELECTED_CALLERS.map(queueOf))].filter(Boolean);
      chartChannelMix.data.labels = queues;
      chartChannelMix.data.datasets = CHANNEL_KEYS.map(key => ({
        label: key,
        data: queues.map(queue => SELECTED_CALLERS
          .filter(u => queueOf(u) === queue)
          .reduce((sum, u) => sum + (CHANNEL_MIX_TOTALS[u]?.[key] ?? 0), 0)),
        backgroundColor: CHANNEL_COLOURS[key],
      }));
    } else {
      chartChannelMix.data.labels = SELECTED_CALLERS.map(u => CALLERS_MAP[u]);
      chartChannelMix.data.datasets = CHANNEL_KEYS.map(key => ({
        label: key,
        data: SELECTED_CALLERS.map(u => CHANNEL_MIX_TOTALS[u]?.[key] ?? 0),
        backgroundColor: CHANNEL_COLOURS[key],
      }));
    }
    chartChannelMix.update();
  }
  renderChannelMix();

  document.getElementById('aggChannelMix').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    channelMixMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderChannelMix();
  });

