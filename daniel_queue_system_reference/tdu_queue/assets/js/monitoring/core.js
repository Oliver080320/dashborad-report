  // Semantic colours, chosen to match queue_view.php's badge colours (sp1-4: .sp1-.sp4 h2,
  // carryover: .carryover h2) so a bucket reads the same on both pages.
  const PALETTE = {
    teal: '#0F766E',
    alert: '#C0392B',
    ocNext: '#0F766E',
    ocNoAns: '#C28A3E',
    ocInt: '#3FA34D',
    ocInb: '#6B5B95',
    sp1: '#1C93C4',
    sp2: '#F5A623',
    sp3: '#EF6C24',
    sp4: '#4A7B8C',
    carryover: '#92400e',
    accepted: '#3FA34D',
    rejected: '#C0392B',
    requote: '#C28A3E',
  };
  // Logo blue/gold/orange, plus plain red for Karthik. Must match $caller_colours in the PHP above.
  const CALLER_COLOURS = ['#1C93C4', '#F5A623', '#EF6C24', '#DC2626'];

  // Charts render inside a fixed-height .card__viz — fill it instead of Chart.js's default
  // width-driven aspect ratio, which was stretching every chart wide and flat.
  const CHART_BASE_OPTIONS = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'bottom',
        labels: {
          // Round dots to match the caller filter's own coloured dots (.fchk .dot).
          usePointStyle: true,
          pointStyle: 'circle',
          boxWidth: 12,
          font: {
            size: 11
          },
          // Line datasets only set borderColor (no fill), so the default fillStyle is empty and
          // the dot renders hollow. Read the colour off the dataset config directly instead.
          generateLabels(chart) {
            // Pie/doughnut register their own default (one entry per SLICE) via Chart.overrides —
            // hardcoding the generic (one entry per dataset) one broke the live-basket donuts.
            const defaultGenerateLabels = Chart.overrides[chart.config.type]?.plugins?.legend?.labels?.generateLabels
              || Chart.defaults.plugins.legend.labels.generateLabels;
            const items = defaultGenerateLabels(chart);
            items.forEach(item => {
              const ds = chart.data.datasets[item.datasetIndex];
              let colour = ds?.backgroundColor || ds?.borderColor;
              // Pie/doughnut keep one colour PER SLICE (item.index) in the same dataset's array,
              // unlike bar/line's one colour per dataset — pick the right one, not the whole array.
              if (Array.isArray(colour)) colour = colour[item.index] ?? colour[0];
              if (colour) item.fillStyle = colour;
            });
            return items;
          }
        }
      },
      // Circular hover markers to match the legend's round dots.
      tooltip: {
        usePointStyle: true,
        pointStyle: 'circle',
        callbacks: {
          // Same hollow-ring problem as generateLabels above — force fill and border to match.
          labelColor(ctx) {
            const ds = ctx.dataset;
            let colour = ds?.backgroundColor || ds?.borderColor;
            // Pie/doughnut tooltips index by ctx.dataIndex (the slice), not one dataset colour.
            if (Array.isArray(colour)) colour = colour[ctx.dataIndex] ?? colour[0];
            return {
              backgroundColor: colour,
              borderColor: colour,
            };
          }
        }
      }
    },
  };

  // Shared by every donut on this dashboard (the live-basket cuts): right-side legend with each
  // entry's raw count and percentage-of-total appended to its text (e.g. "FIT — 45 (60%)").
  // Local to donuts only — reuses CHART_BASE_OPTIONS' already-fixed generateLabels for the base
  // items/colours, then layers the count/pct text on top, so no other chart type is affected.
  function donutOptions() {
    return {
      ...CHART_BASE_OPTIONS,
      plugins: {
        ...CHART_BASE_OPTIONS.plugins,
        legend: {
          ...CHART_BASE_OPTIONS.plugins.legend,
          position: 'right',
          labels: {
            ...CHART_BASE_OPTIONS.plugins.legend.labels,
            generateLabels(chart) {
              const items = CHART_BASE_OPTIONS.plugins.legend.labels.generateLabels(chart);
              const values = chart.data.datasets[0].data;
              const total = values.reduce((sum, v) => sum + v, 0);
              items.forEach((item, i) => {
                const pct = total > 0 ? Math.round((values[i] / total) * 100) : 0;
                item.text = `${item.text} — ${values[i]} (${pct}%)`;
              });
              return items;
            },
          },
        },
      },
    };
  }

  // Shared by every trend chart below — just "YYYY-MM-DD" string keys to sort. Sunday is never a
  // real working day (the cron skips it, but a manual page load can still write a snapshot),
  // so a stray manual-load
  // snapshot is excluded rather than plotted as a misleading dip. Parsed from Y/M/D components,
  // not `new Date(isoDate)`, to avoid a UTC-parse day shift.
  function isSunday(isoDate) {
    const [y, m, d] = isoDate.split('-').map(Number);
    return new Date(y, m - 1, d).getDay() === 0;
  }

  function sortedDateKeys(obj) {
    return Object.keys(obj).filter(d => !isSunday(d)).sort();
  }

  // Labels drop the year: "1 Jun" not "2026-06-01". Parsed as plain strings, not Date(), to
  // sidestep timezone shifting the day by one.
  const MONTH_ABBR = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

  function formatDateLabel(isoDate) {
    const [, m, d] = isoDate.split('-').map(Number);
    return `${d} ${MONTH_ABBR[m - 1]}`;
  }

  // The calls-by-hour keys need a NUMERIC sort (json_encode makes them strings), since a string sort would
  // put "10" before "9".
  function sortedHourKeys(obj) {
    return Object.keys(obj).map(Number).sort((a, b) => a - b);
  }

  function formatHourLabel(hour) {
    const h = hour % 12 || 12;
    return `${h}${hour < 12 ? 'am' : 'pm'}`;
  }

  function callerColour(uname) {
    const idx = SELECTED_CALLERS.indexOf(uname);
    return CALLER_COLOURS[idx % CALLER_COLOURS.length];
  }

  // KPI strip — combines KPI_BY_CALLER's raw per-caller counts for the current filter selection.
  // Percentages are never averaged across callers, always re-derived from summed counts.
  function combineKPIs(selected) {
    const totals = {
      calls_today: 0,
      surfaced: 0,
      worked: 0,
      backlog_now: 0,
      backlog_oldest_days: 0,
      reached: 0,
      not_reached: 0
    };
    selected.forEach(uname => {
      const k = KPI_BY_CALLER[uname];
      if (!k) return;
      totals.calls_today += k.calls_today;
      totals.surfaced += k.surfaced;
      totals.worked += k.worked;
      totals.backlog_now += k.backlog_now;
      totals.backlog_oldest_days = Math.max(totals.backlog_oldest_days, k.backlog_oldest_days);
      totals.reached += k.reached;
      totals.not_reached += k.not_reached;
    });
    const pctWorked = totals.surfaced > 0 ? Math.round((totals.worked / totals.surfaced) * 1000) / 10 : 0;
    const contactRate = (totals.reached + totals.not_reached) > 0 ?
      Math.round((totals.reached / (totals.reached + totals.not_reached)) * 1000) / 10 : 0;
    return {
      ...totals,
      pctWorked,
      contactRate
    };
  }

  function callsTodaySub(selected) {
    if (selected.length === 1) return 'for ' + (CALLERS_MAP[selected[0]] || selected[0]);
    if (selected.length === Object.keys(CALLERS_MAP).length) return `across all ${selected.length} callers`;
    return `across ${selected.length} selected callers`;
  }

  function backlogSub(backlogNow, backlogOldestDays) {
    return backlogNow > 0 ? `carry-over quotes · oldest ${backlogOldestDays}d` : 'carry-over quotes';
  }

  function renderKPIs() {
    const c = combineKPIs(SELECTED_CALLERS);
    document.getElementById('kpiCallsToday').textContent = c.calls_today;
    document.getElementById('kpiCallsTodaySub').textContent = callsTodaySub(SELECTED_CALLERS);
    document.getElementById('kpiPctWorked').textContent = c.pctWorked + '%';
    document.getElementById('kpiBacklogNow').textContent = c.backlog_now;
    document.getElementById('kpiBacklogSub').textContent = backlogSub(c.backlog_now, c.backlog_oldest_days);
    document.getElementById('kpiContactRate').textContent = c.contactRate + '%';
  }

  // "Last working day" KPI row — reuses data already loaded for the daily trend charts, no new
  // query. Most recent date strictly before TODAY across the union of those four datasets, not
  // just "yesterday" — naturally skips an empty Sunday instead of hardcoding a weekday rule.
  function lastWorkedDayKey() {
    const keys = new Set([
      ...Object.keys(DAILY_CALLS_LOGGED),
      ...Object.keys(DAILY_SURFACED_WORKED),
      ...Object.keys(DAILY_CONTACT_RATE),
      ...Object.keys(DAILY_BACKLOG_SIZE),
    ]);
    return [...keys].filter(d => d < TODAY && !isSunday(d)).sort().pop() || null;
  }

  function renderLastWorkedDayKPIs() {
    const day = lastWorkedDayKey();
    const ids = ['lwdCalls', 'lwdPctWorked', 'lwdBacklog', 'lwdContactRate'];
    if (!day) {
      document.getElementById('lastWorkedDayLabel').textContent = '(no activity yet)';
      ids.forEach(id => document.getElementById(id).textContent = '—');
      return;
    }
    document.getElementById('lastWorkedDayLabel').textContent = `(${formatDateLabel(day)})`;

    let calls = 0,
      surfaced = 0,
      worked = 0,
      backlog = 0,
      reached = 0,
      notReached = 0;
    SELECTED_CALLERS.forEach(uname => {
      calls += DAILY_CALLS_LOGGED[day]?.[uname] ?? 0;
      backlog += DAILY_BACKLOG_SIZE[day]?.[uname] ?? 0;
      const sw = DAILY_SURFACED_WORKED[day]?.[uname];
      if (sw) {
        surfaced += sw.surfaced;
        worked += sw.worked;
      }
      const cr = DAILY_CONTACT_RATE[day]?.[uname];
      if (cr) {
        reached += cr.reached;
        notReached += cr.not_reached;
      }
    });
    const pctWorked = surfaced > 0 ? Math.round((worked / surfaced) * 1000) / 10 : 0;
    const contactRate = (reached + notReached) > 0 ?
      Math.round((reached / (reached + notReached)) * 1000) / 10 : 0;

    document.getElementById('lwdCalls').textContent = calls;
    document.getElementById('lwdCallsSub').textContent = callsTodaySub(SELECTED_CALLERS);
    document.getElementById('lwdPctWorked').textContent = pctWorked + '%';
    document.getElementById('lwdBacklog').textContent = backlog;
    document.getElementById('lwdContactRate').textContent = contactRate + '%';
  }

  renderKPIs();
  renderLastWorkedDayKPIs();

  // Caller filter — no reload. Re-reads checked boxes, updates the URL (replaceState, no
  // navigation), re-renders every chart wired to the live filter. Coverage and win rate
  // are excluded by design — business-wide, not filtered by caller.
  function onFilterChange() {
    SELECTED_CALLERS = Array.from(
      document.querySelectorAll('#callerFilterForm input[name="callers[]"]:checked')
    ).map(el => el.value);

    const url = new URL(window.location.href);
    url.searchParams.set('callers', SELECTED_CALLERS.join(','));
    window.history.replaceState({}, '', url.toString());

    renderKPIs();
    renderLastWorkedDayKPIs();
    renderBacklogSize();
    renderBacklogAgeHeat();
    renderPctWorked();
    renderCallsLogged();
    renderOutcomeMix();
    renderContactRate();
    renderAcceptedByOwner();
    renderStageLifecycle();
    renderCycleTime();
    renderQuotesOwned();
    renderCallsByHour();
    renderChannelMix();
    renderQueueComposition();
  }

  document.getElementById('callerFilterForm').addEventListener('change', function(e) {
    if (e.target.name === 'callers[]') onFilterChange();
  });

  // Family tabs — pure display filter, no data re-fetch. Single-select, bookmarkable via
  // ?family=, same replaceState-no-reload pattern as the caller filter.
  const FAMILIES = ['all', 'team-activity', 'workload', 'accounts', 'quote-pipeline', 'person'];

  // A card can carry a SECOND, space-separated family (e.g. "workload team-activity") so it lives
  // in both tabs without duplicating the DOM node/chart.
  const FAMILY_DESCRIPTIONS = {
    all: 'Every chart, unfiltered.',
    'team-activity': 'What the team is doing day to day — volume, call outcomes, and quote results.',
    workload: 'Is the backlog under control, and does capacity match demand?',
    accounts: 'Which organisations are behind the quotes — who won\'t answer, who sends the most, why deals are lost.',
    'quote-pipeline': 'What the live quote book is made of — stage, type, destination, travel dates, group size.',
    person: 'One caller\'s whole book — pick anyone regardless of the caller filter above.',
  };

  function applyFamilyFilter(family) {
    document.querySelectorAll('.grid > .card').forEach(card => {
      const families = card.dataset.family.split(' ');
      card.style.display = (family === 'all' || families.includes(family)) ? '' : 'none';
    });
    // "person" isn't part of the .grid card layout — a standalone panel scoped to one caller,
    // deliberately excluded from "All" (an unfiltered business-wide view, not one person's book).
    const personPanel = document.getElementById('personPanel');
    if (personPanel) personPanel.style.display = (family === 'person') ? '' : 'none';
    document.querySelectorAll('#familyTabs button').forEach(b => {
      b.classList.toggle('on', b.dataset.family === family);
    });
    document.getElementById('familyDesc').textContent = FAMILY_DESCRIPTIONS[family] || '';
  }

  const urlFamily = new URLSearchParams(window.location.search).get('family');
  const initialFamily = FAMILIES.includes(urlFamily) ? urlFamily : 'team-activity';
  applyFamilyFilter(initialFamily);

  document.getElementById('familyTabs').addEventListener('click', function(e) {
    const family = e.target.dataset.family;
    if (!family) return;
    applyFamilyFilter(family);

    const url = new URL(window.location.href);
    url.searchParams.set('family', family);
    window.history.replaceState({}, '', url.toString());
  });
