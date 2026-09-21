  // Live basket by stage (build_live_basket_by_stage): a snapshot, not a trend. Business-wide,
  // no toggle. Already
  // sorted by count desc server-side, so no client-side sort needed.
  new Chart(document.getElementById('chartLiveBasket'), {
    type: 'bar',
    data: {
      labels: LIVE_BASKET_BY_STAGE.map(s => s.quotestage),
      datasets: [{
        label: 'Live quotes',
        data: LIVE_BASKET_BY_STAGE.map(s => s.cnt),
        backgroundColor: PALETTE.teal,
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
      },
      scales: {
        x: {
          beginAtZero: true,
          ticks: {
            precision: 0
          }
        },
        y: {
          // ~12 stages is tight enough to trigger Chart.js's autoSkip — force every label to render.
          ticks: {
            autoSkip: false
          }
        },
      },
    },
  });

  // build_live_basket_composition re-cuts the live basket by FIT vs Groups, within Groups by
  // GROUPS_PAX_THRESHOLD.
  // Business-wide snapshot. FIT/Groups >threshold reuse the queue colours; Groups <=threshold is
  // a lighter tint of the same purple. All stages/Created-Requote toggle just swaps which key of
  // each bucket feeds the chart — both counts already fetched, no second query.
  let liveBasketCompositionMode = 'created_requote'; // 'all' = every live stage, 'created_requote' = active Follow-up cohort only (default)

  const chartLiveBasketComposition = new Chart(document.getElementById('chartLiveBasketComposition'), {
    type: 'doughnut',
    data: {
      labels: ['FIT', `Groups >${GROUPS_PAX_THRESHOLD} pax`, `Groups <=${GROUPS_PAX_THRESHOLD} pax`],
      datasets: [{
        data: [],
        backgroundColor: [QUEUE_COLOURS['India FIT'], QUEUE_COLOURS['Groups & MICE'], '#C7BEE0'],
      }],
    },
    options: donutOptions(),
  });

  function renderLiveBasketComposition() {
    chartLiveBasketComposition.data.datasets[0].data = [
      LIVE_BASKET_COMPOSITION.fit[liveBasketCompositionMode],
      LIVE_BASKET_COMPOSITION.groups_large[liveBasketCompositionMode],
      LIVE_BASKET_COMPOSITION.groups_small[liveBasketCompositionMode],
    ];
    chartLiveBasketComposition.update();
  }
  renderLiveBasketComposition();

  document.getElementById('aggLiveBasketComposition').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    liveBasketCompositionMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderLiveBasketComposition();
  });

  // build_live_basket_destination re-cuts the live basket by destination. Only Australia/New
  // Zealand exist as real values (N/A and blank excluded server-side), so a straight 2-slice
  // donut. Same all/created_requote toggle as the composition donut above, fresh colours not
  // reused from elsewhere.
  let liveBasketDestinationMode = 'created_requote';

  const chartLiveBasketDestination = new Chart(document.getElementById('chartLiveBasketDestination'), {
    type: 'doughnut',
    data: {
      labels: ['Australia', 'New Zealand'],
      datasets: [{
        data: [],
        backgroundColor: ['#4C6EF5', '#0CA678'],
      }],
    },
    options: donutOptions(),
  });

  function renderLiveBasketDestination() {
    chartLiveBasketDestination.data.datasets[0].data = [
      LIVE_BASKET_DESTINATION.australia[liveBasketDestinationMode],
      LIVE_BASKET_DESTINATION.new_zealand[liveBasketDestinationMode],
    ];
    chartLiveBasketDestination.update();
  }
  renderLiveBasketDestination();

  document.getElementById('aggLiveBasketDestination').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    liveBasketDestinationMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderLiveBasketDestination();
  });

  // build_travel_date_horizon draws the forward pipeline by travel date, re-cutting the live
  // basket by trip month. Three-way toggle (All stages/Created-Requote/Accepted) rather than the
  // composition and destination donuts' two-way, and all three counts
  // come from one query, no second request per toggle click.
  let travelDateHorizonMode = 'created_requote';

  const chartTravelDateHorizon = new Chart(document.getElementById('chartTravelDateHorizon'), {
    type: 'bar',
    data: {
      labels: TRAVEL_DATE_HORIZON.map(m => m.label),
      datasets: [{
        label: 'Live quotes',
        data: [],
        backgroundColor: PALETTE.teal,
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

  function renderTravelDateHorizon() {
    chartTravelDateHorizon.data.datasets[0].data = TRAVEL_DATE_HORIZON.map(m => m[travelDateHorizonMode]);
    chartTravelDateHorizon.update();
  }
  renderTravelDateHorizon();

  document.getElementById('aggTravelDateHorizon').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    travelDateHorizonMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderTravelDateHorizon();
  });

  // build_pax_distribution gives passenger counts, split FIT vs Groups. Unlike the other live-basket
  // cuts above (same X axis, swap count),
  // FIT and Groups have entirely different bucket schemes, so toggling replaces both labels AND
  // data (same pattern as chartQueueComposition's Total/Per-person toggle).
  let paxDistributionMode = 'fit';

  const chartPaxDistribution = new Chart(document.getElementById('chartPaxDistribution'), {
    type: 'bar',
    data: {
      labels: [],
      datasets: [{
        label: 'Live quotes',
        data: [],
        backgroundColor: PALETTE.teal,
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

  function renderPaxDistribution() {
    const bucket = PAX_DISTRIBUTION[paxDistributionMode];
    chartPaxDistribution.data.labels = Object.keys(bucket.buckets);
    chartPaxDistribution.data.datasets[0].data = Object.values(bucket.buckets);
    chartPaxDistribution.update();
    document.getElementById('paxDistributionAvg').textContent = `· avg ${bucket.avg} pax (${bucket.count} quotes)`;
  }
  renderPaxDistribution();

  document.getElementById('aggPaxDistribution').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    paxDistributionMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderPaxDistribution();
  });

  // B.7 "Where are our quotes?" — business-wide, no caller filter. NOT the same population as
  // B.1-B.6 (Created/Requote only — see build_quote_geography()). Toggle swaps both labels and
  // data (By country/India regions), two different bucket schemes.
  let quoteGeographyMode = 'country'; // 'country' = QUOTE_GEOGRAPHY.by_country, 'region' = .by_region

  const chartQuoteGeography = new Chart(document.getElementById('chartQuoteGeography'), {
    type: 'bar',
    data: {
      labels: [],
      datasets: [{
        label: 'Quotes',
        data: [],
        backgroundColor: PALETTE.teal,
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

  function renderQuoteGeography() {
    const bucket = quoteGeographyMode === 'region' ? QUOTE_GEOGRAPHY.by_region : QUOTE_GEOGRAPHY.by_country;
    chartQuoteGeography.data.labels = Object.keys(bucket);
    chartQuoteGeography.data.datasets[0].data = Object.values(bucket);
    chartQuoteGeography.update();
  }
  renderQuoteGeography();

  document.getElementById('aggQuoteGeography').addEventListener('click', function(e) {
    const mode = e.target.dataset.mode;
    if (!mode) return;
    quoteGeographyMode = mode;
    this.querySelectorAll('button').forEach(b => b.classList.toggle('on', b.dataset.mode === mode));
    renderQuoteGeography();
  });

