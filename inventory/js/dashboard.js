// dev/js/dashboard.js
(function () {
  var DATA = window.DASHBOARD || {};
  if (!DATA.has_history) return;

  function biAxes(horizontal) {
    if (horizontal) {
      return {
        x: {
          beginAtZero: true,
          grid: { color: 'rgba(128,128,128,0.16)' },
          ticks: { maxTicksLimit: 6 }
        },
        y: {
          beginAtZero: true,
          grid: { display: false }
        }
      };
    }
    return {
      x: {
        beginAtZero: true,
        grid: { color: 'rgba(128,128,128,0.16)' },
        ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 8 }
      },
      y: {
        beginAtZero: true,
        grid: { color: 'rgba(128,128,128,0.16)' }
      }
    };
  }

  function biPlugins() {
    return {
      legend: {
        display: true,
        position: 'top',
        align: 'start',
        labels: { boxWidth: 10, usePointStyle: true, pointStyle: 'circle' }
      },
      tooltip: {
        mode: 'index',
        intersect: false
      }
    };
  }

  // Latest Movements
  if (document.getElementById('chartLatest')) {
    window.makeChart('chartLatest', {s
      type: 'bar',
      data: {
        labels: (DATA.latest_movements && DATA.latest_movements.labels) || [],
        datasets: [{
          label: 'Units moved (abs)',
          data: (DATA.latest_movements && DATA.latest_movements.data) || [],
          borderWidth: 1,
          borderRadius: 3,
          backgroundColor: 'rgba(54, 162, 235, 0.65)',
          borderColor: 'rgba(54, 162, 235, 1)'
        }]
      },
      options: {
        indexAxis: (DATA.latest_movements && DATA.latest_movements.labels && DATA.latest_movements.labels.length > 6) ? 'y' : 'x',
        scales: biAxes((DATA.latest_movements && DATA.latest_movements.labels && DATA.latest_movements.labels.length > 6)),
        plugins: biPlugins(),
        interaction: { mode: 'index', intersect: false },
        animation: false
      }
    });
  }

  // Last 10 IN
  if (document.getElementById('chartIn')) {
    window.makeChart('chartIn', {
      type: 'bar',
      data: {
        labels: (DATA.last10_in && DATA.last10_in.labels) || [],
        datasets: [{
          label: 'Qty IN',
          data: (DATA.last10_in && DATA.last10_in.data) || [],
          borderWidth: 1,
          borderRadius: 3,
          backgroundColor: 'rgba(75, 192, 192, 0.65)',
          borderColor: 'rgba(75, 192, 192, 1)'
        }]
      },
      options: {
        indexAxis: 'y',
        scales: biAxes(true),
        plugins: biPlugins(),
        interaction: { mode: 'index', intersect: false },
        animation: false
      }
    });
  }

  // Last 10 OUT
  if (document.getElementById('chartOut')) {
    window.makeChart('chartOut', {
      type: 'bar',
      data: {
        labels: (DATA.last10_out && DATA.last10_out.labels) || [],
        datasets: [{
          label: 'Qty OUT',
          data: (DATA.last10_out && DATA.last10_out.data) || [],
          borderWidth: 1,
          borderRadius: 3,
          backgroundColor: 'rgba(255, 99, 132, 0.65)',
          borderColor: 'rgba(255, 99, 132, 1)'
        }]
      },
      options: {
        indexAxis: 'y',
        scales: biAxes(true),
        plugins: biPlugins(),
        interaction: { mode: 'index', intersect: false },
        animation: false
      }
    });
  }

  function num(v) {
    var n = Number(v);
    return Number.isFinite(n) ? n : 0;
  }

  function clamp01(v) {
    return Math.max(0, Math.min(1, v));
  }

  function activityColor(v, maxV) {
    var ratio = maxV > 0 ? clamp01(v / maxV) : 0;
    var hue = 120 - (120 * ratio); // green (120) -> red (0)
    return 'hsl(' + hue + ', 85%, 45%)';
  }

  function buildLayoutActivity(rows) {
    var byRowBay = {};
    var byRowSideBay = {};
    var byRowLevelSide = {};
    var rowSet = {};
    var max = 0;

    (rows || []).forEach(function (r) {
      var row = String(r.row_code || '').trim().toUpperCase();
      var bay = String(r.bay_num || '').trim();
      var level = String(r.level_code || '').trim();
      var side = String(r.side_code || '').trim().toUpperCase() || 'U';
      var activity = num(r.activity_units);
      if (!row) return;

      rowSet[row] = true;

      var bayKey = row + '|' + bay;
      byRowBay[bayKey] = (byRowBay[bayKey] || 0) + activity;
      if (byRowBay[bayKey] > max) max = byRowBay[bayKey];

      var sideBayKey = row + '|' + side + '|' + bay;
      byRowSideBay[sideBayKey] = (byRowSideBay[sideBayKey] || 0) + activity;
      if (byRowSideBay[sideBayKey] > max) max = byRowSideBay[sideBayKey];

      var lvlKey = row + '|' + level + '|' + side;
      byRowLevelSide[lvlKey] = (byRowLevelSide[lvlKey] || 0) + activity;
      if (byRowLevelSide[lvlKey] > max) max = byRowLevelSide[lvlKey];
    });

    var rowsSorted = Object.keys(rowSet).sort(function (a, b) { return a.localeCompare(b, undefined, { numeric: true }); });
    return { byRowBay: byRowBay, byRowSideBay: byRowSideBay, byRowLevelSide: byRowLevelSide, rowsSorted: rowsSorted, max: max };
  }

  function uniqueSorted(values) {
    var set = {};
    (values || []).forEach(function (v) { set[String(v)] = true; });
    return Object.keys(set).sort(function (a, b) { return a.localeCompare(b, undefined, { numeric: true }); });
  }

  function renderOverheadSvg(target, rows, parsed) {
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = '<div class="note">No location layout data available.</div>';
      return;
    }

    var sourceRows = DATA.location_activity_layout || [];
    var baysByRow = {};
    var sidesByRow = {};
    sourceRows.forEach(function (r) {
      var row = String(r.row_code || '').trim().toUpperCase();
      var side = String(r.side_code || '').trim().toUpperCase() || 'U';
      if (!row) return;
      baysByRow[row] = baysByRow[row] || [];
      baysByRow[row].push(String(r.bay_num || '').trim());
      sidesByRow[row] = sidesByRow[row] || {};
      sidesByRow[row][side] = true;
    });

    var maxBays = 1;
    rows.forEach(function (row) {
      var bays = uniqueSorted(baysByRow[row]);
      if (bays.length > maxBays) maxBays = bays.length;
    });

    var leftPad = 34;
    var rightPad = 12;
    var topPad = 18;
    var rowH = 48;
    var rowGapRow = 34;
    var rowGapSide = 18;
    var laneH = 10;
    var bayStep = 24;
    var innerW = Math.max(260, maxBays * bayStep);
    var svgW = leftPad + innerW + rightPad;

    var lanes = [];
    rows.forEach(function (row) {
      var sideMap = sidesByRow[row] || {};
      var rowSides = Object.keys(sideMap).filter(function (s) { return s === 'F' || s === 'B'; });
      if (!rowSides.length) rowSides = ['U'];
      if (rowSides.length === 2) {
        lanes.push({ row: row, side: 'F', group: 'FB' });
        lanes.push({ row: row, side: 'B', group: 'FB' });
      } else {
        lanes.push({ row: row, side: rowSides[0], group: 'single' });
      }
    });

    var totalGap = 0;
    for (var li = 1; li < lanes.length; li += 1) {
      var prev = lanes[li - 1];
      var curr = lanes[li];
      totalGap += (prev.row === curr.row) ? rowGapSide : rowGapRow;
    }
    var svgH = topPad + (lanes.length * rowH) + totalGap + 12;

    var out = [];
    out.push('<svg viewBox="0 0 ' + svgW + ' ' + svgH + '" role="img" aria-label="Overhead location activity heatmap">');
    out.push('<rect x="0" y="0" width="' + svgW + '" height="' + svgH + '" fill="transparent"></rect>');

    var yCursor = topPad;
    lanes.forEach(function (lane, idx) {
      var row = lane.row;
      var side = lane.side;
      var y = yCursor;
      var bays = uniqueSorted(baysByRow[row]);
      var step = bays.length > 1 ? (innerW - 20) / (bays.length - 1) : 0;
      var laneLabel = (side === 'F' || side === 'B') ? (row + ' ' + side) : row;

      out.push('<text x="6" y="' + (y + rowH / 2 + 4) + '" text-anchor="start" font-size="12" fill="currentColor">' + laneLabel + '</text>');
      out.push('<rect x="' + leftPad + '" y="' + (y + 8) + '" width="' + innerW + '" height="' + laneH + '" fill="none" stroke="currentColor" stroke-opacity="0.55"></rect>');
      out.push('<rect x="' + leftPad + '" y="' + (y + 8 + laneH + 10) + '" width="' + innerW + '" height="' + laneH + '" fill="none" stroke="currentColor" stroke-opacity="0.55"></rect>');

      bays.forEach(function (bay, bidx) {
        var x = leftPad + 10 + (bidx * step);
        var value = parsed.byRowSideBay[row + '|' + side + '|' + bay];
        if (!Number.isFinite(value)) {
          value = parsed.byRowBay[row + '|' + bay] || 0;
        }
        var color = activityColor(value, parsed.max);
        var label = laneLabel + '-' + bay + ' Activity: ' + value;

        out.push('<circle cx="' + x + '" cy="' + (y + rowH / 2) + '" r="8" fill="' + color + '"><title>' + label + '</title></circle>');
        out.push('<text x="' + x + '" y="' + (y + rowH / 2 + 4) + '" text-anchor="middle" font-size="10" fill="#fff">' + bay + '</text>');
      });

      if (idx < lanes.length - 1) {
        var next = lanes[idx + 1];
        yCursor += rowH + ((next.row === row) ? rowGapSide : rowGapRow);
      }
    });

    out.push('</svg>');
    target.innerHTML = out.join('');
  }

  function renderCrossSectionSvg(target, rows, parsed) {
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = '<div class="note">No location layout data available.</div>';
      return;
    }

    var sourceRows = DATA.location_activity_layout || [];
    var lvlsByRow = {};
    var sidesByRow = {};
    sourceRows.forEach(function (r) {
      var row = String(r.row_code || '').trim().toUpperCase();
      if (!row) return;
      lvlsByRow[row] = lvlsByRow[row] || [];
      lvlsByRow[row].push(String(r.level_code || '').trim());
      sidesByRow[row] = sidesByRow[row] || [];
      sidesByRow[row].push(String(r.side_code || '').trim().toUpperCase() || 'U');
    });

    var rowW = 190;
    var gap = 26;
    var leftPad = 12;
    var topPad = 24;
    var cellH = 36;
    var cellW = 44;
    var framePad = 8;

    var maxLevels = 1;
    rows.forEach(function (row) {
      var levels = uniqueSorted(lvlsByRow[row]);
      if (levels.length > maxLevels) maxLevels = levels.length;
    });

    var innerH = maxLevels * cellH;
    var svgW = leftPad + (rows.length * rowW) + ((rows.length - 1) * gap) + 12;
    var svgH = topPad + innerH + 34;

    var out = [];
    out.push('<svg viewBox="0 0 ' + svgW + ' ' + svgH + '" role="img" aria-label="Cross section location activity heatmap">');

    rows.forEach(function (row, idx) {
      var x = leftPad + idx * (rowW + gap);
      var levels = uniqueSorted(lvlsByRow[row]).reverse();
      var sides = uniqueSorted(sidesByRow[row]);
      var useSides = sides.slice(0, 2);
      if (!useSides.length) useSides = ['F', 'B'];
      if (useSides.length === 1) useSides.push(useSides[0] === 'F' ? 'B' : 'F');

      out.push('<text x="' + (x + rowW / 2) + '" y="14" text-anchor="middle" font-size="12" fill="currentColor">' + row + ' F/B</text>');

      var frameX = x + (rowW / 2) - ((useSides.length * cellW) / 2) - framePad;
      out.push('<rect x="' + frameX + '" y="' + (topPad - framePad) + '" width="' + ((useSides.length * cellW) + (framePad * 2)) + '" height="' + (innerH + (framePad * 2)) + '" fill="none" stroke="currentColor" stroke-opacity="0.55"></rect>');

      levels.forEach(function (lvl, li) {
        var y = topPad + li * cellH;
        useSides.forEach(function (sd, si) {
          var xCell = x + (rowW / 2) - (useSides.length * cellW / 2) + (si * cellW);
          var value = parsed.byRowLevelSide[row + '|' + lvl + '|' + sd] || 0;
          var color = activityColor(value, parsed.max);
          var label = row + ' L' + lvl + ' ' + sd + ' Activity: ' + value;
          out.push('<rect x="' + xCell + '" y="' + y + '" width="' + (cellW - 2) + '" height="' + (cellH - 2) + '" fill="' + color + '" stroke="currentColor" stroke-opacity="0.2"><title>' + label + '</title></rect>');
        });
        out.push('<text x="' + (x + rowW - 14) + '" y="' + (y + 22) + '" text-anchor="end" font-size="11" fill="currentColor">' + lvl + '</text>');
      });
    });

    out.push('</svg>');
    target.innerHTML = out.join('');
  }

  function renderLayoutHeatmaps() {
    var overheadTarget = document.getElementById('svgOverheadHeatmap');
    if (!overheadTarget) return;

    var parsed = buildLayoutActivity(DATA.location_activity_layout || []);
    renderOverheadSvg(overheadTarget, parsed.rowsSorted, parsed);
  }

  renderLayoutHeatmaps();

  function setupKpiFocus() {
    var kpis = document.querySelectorAll('.bi-kpi[data-kpi-filter]');
    if (!kpis.length) return;

    var map = {
      active: '#panelActiveLocations',
      zero: '#alertZero',
      new: '#alertNew',
      repl: '#alertRepl',
      requests: '#panelRequests',
      mywork: '#panelMyWork'
    };

    function clearFocus() {
      document.querySelectorAll('.bi-focus').forEach(function (el) { el.classList.remove('bi-focus'); });
      kpis.forEach(function (k) { k.classList.remove('bi-kpi--active'); });
    }

    function focusByKey(key) {
      clearFocus();
      var kpi = document.querySelector('.bi-kpi[data-kpi-filter="' + key + '"]');
      if (kpi) kpi.classList.add('bi-kpi--active');

      var sel = map[key];
      if (!sel) return;
      var panel = document.querySelector(sel);
      if (!panel) return;
      if (panel.tagName === 'DETAILS') panel.open = true;
      panel.classList.add('bi-focus');
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    kpis.forEach(function (kpi) {
      kpi.addEventListener('click', function () {
        focusByKey(kpi.dataset.kpiFilter || '');
      });
      kpi.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          focusByKey(kpi.dataset.kpiFilter || '');
        }
      });
    });
  }

  setupKpiFocus();

  function prepareLocationHeatmapData(rawData, fromDateStr, toDateStr, allLocationLabels) {
    const fromDate = new Date(fromDateStr);
    const toDate = new Date(toDateStr || fromDateStr);
    const movementRows = Array.isArray(rawData) ? rawData : [];
    const knownLocations = Array.isArray(allLocationLabels) ? allLocationLabels.filter(Boolean) : [];

    // Group data and calculate grand totals
    const dailyTotals = {}; // Total moves across all locations per day
    const locationData = {}; // Data grouped by location

    movementRows.forEach(d => {
      const date = d.move_date;
      const loc = d.loc_identifier;
      const moves = parseInt(d.daily_movement);

      // Calculate Period (Days out from start date)
      const dayDate = new Date(date);
      const period = Math.ceil((dayDate - fromDate) / (1000 * 60 * 60 * 24)) + 1; // Period 1 starts on day 1

      if (!dailyTotals[period]) dailyTotals[period] = 0;
      dailyTotals[period] += moves;

      if (!locationData[loc]) locationData[loc] = {};
      locationData[loc][period] = moves;
    });

    const locationLabels = (knownLocations.length ? knownLocations : Object.keys(locationData)).slice().sort();
    const allPeriods = Object.keys(dailyTotals).map(Number).sort((a, b) => a - b);
    const configuredPeriodCount = Math.max(1, Math.floor((toDate - fromDate) / (1000 * 60 * 60 * 24)) + 1);
    const maxObservedPeriod = allPeriods[allPeriods.length - 1] || 0;
    const maxPeriod = Math.max(configuredPeriodCount, maxObservedPeriod);

    const chartData = [];
    const periodLabels = Array.from({ length: maxPeriod }, (_, i) => i + 1);

    // Calculate percentages and format for Chart.js Matrix
    locationLabels.forEach(loc => {
      periodLabels.forEach(period => {
        const moves = (locationData[loc] && locationData[loc][period]) || 0;
        const dailyGrandTotal = dailyTotals[period] || 1; // Avoid division by zero

        let percentage = 0;
        if (moves > 0) {
          percentage = (moves / dailyGrandTotal);
        }

        chartData.push({
          x: period,
          y: loc,
          v: percentage, // Value is the percentage (0.0 to 1.0)
          moves: moves // Store raw moves for tooltip
        });
      });
    });

    return {
      chartData: chartData,
      yLabels: locationLabels,
      xLabels: periodLabels
    };
  }


  if (DATA.location_heatmap_data) {
    const { chartData, yLabels, xLabels } = prepareLocationHeatmapData(
      DATA.location_heatmap_data,
      DATA.meta && DATA.meta.from,
      DATA.meta && DATA.meta.to,
      DATA.all_location_labels
    );

    const heatColor = (value) => {
      const v = Math.max(0, Math.min(1, value || 0));
      const hue = 165 - (v * 65); // teal toward amber
      const alpha = 0.25 + (v * 0.6);
      return `hsla(${hue}, 65%, 48%, ${alpha})`;
    };

    window.makeChart('chartLocationHeatmap', {
      type: 'matrix',
      data: {
        datasets: [{
          label: 'Movement Percentage',
          data: chartData,
          backgroundColor: function (context) {
            const value = context.dataset.data[context.dataIndex].v;
            return heatColor(value);
          },
          borderColor: 'rgba(255, 255, 255, 0.45)',
          borderWidth: 0.6,
          borderRadius: 6,
          width: ({ chart }) => Math.max((chart.scales.x.width / xLabels.length) - 4, 10),
          height: ({ chart }) => Math.max((chart.scales.y.height / yLabels.length) - 4, 10),
          labels: {
            display: true,
            formatter: (context) => {
              const value = context.dataset.data[context.dataIndex].v;
              return value > 0 ? `${Math.round(value * 100)}%` : '';
            }
          }
        }]
      },
      options: {
        scales: {
          x: {
            type: 'category',
            labels: xLabels,
            title: { display: true, text: `Days Out (since ${DATA.meta.from})` },
            grid: { display: false, drawBorder: false }
          },
          y: {
            type: 'category',
            labels: yLabels,
            title: { display: true, text: 'Location (Row+Bay)' },
            grid: { display: false, drawBorder: false }
          }
        },
        plugins: {
          tooltip: {
            callbacks: {
              label: function (context) {
                const data = context.raw;
                const percentage = Math.round(data.v * 100);
                return `Movement: ${data.moves} units (${percentage}%)`;
              }
            }
          }
        },
        layout: {
          padding: 10
        }
      }
    });
  }

  function setupLocationHighlighting(chartInstance) {
    const locCards = document.querySelectorAll('.location-card');
    const yAxis = chartInstance.scales.y;

    locCards.forEach(card => {
      const locIdentifier = card.dataset.locId;

      // Find the index of the location in the chart's Y-axis labels
      const dataIndex = yAxis.ticks.findIndex(t => t.label === locIdentifier);

      if (dataIndex === -1) {
        // Location not found in heatmap data for the period, skip highlighting
        return;
      }

      // Mouse Enter Handler
      card.addEventListener('mouseenter', () => {
        // Highlight the card
        card.classList.add('highlight');

        // Trigger chart highlight (set active elements)
        chartInstance.setActiveElements([{
          datasetIndex: 0,
          index: dataIndex // Index on the Y-axis (vertical position)
        }]);
        chartInstance.update();
      });

      // Mouse Leave Handler
      card.addEventListener('mouseleave', () => {
        // Remove card highlight
        card.classList.remove('highlight');

        // Clear chart highlight
        chartInstance.setActiveElements([]);
        chartInstance.update();
      });
    });
  }
})();
