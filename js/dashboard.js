// dev/js/dashboard.js
(function () {
    if (typeof Chart === 'undefined') {
  console.error('[dashboard] Chart.js missing');
} else {
  console.log('[dashboard] Chart v' + Chart.version,
    'matrix controller:', !!(Chart.registry?.getController?.('matrix')),
    'bar controller:', !!(Chart.registry?.getController?.('bar')));
}

  var DATA = window.DASHBOARD || {};
  if (!DATA.has_history) return;

  // Latest Movements
  window.makeChart('chartLatest', {
    type: 'bar',
    data: {
      labels: (DATA.latest_movements && DATA.latest_movements.labels) || [],
      datasets: [{ label: 'Units moved (abs)', data: (DATA.latest_movements && DATA.latest_movements.data) || [] }]
    },
    options: {
      indexAxis: (DATA.latest_movements && DATA.latest_movements.labels && DATA.latest_movements.labels.length > 6) ? 'y' : 'x',
      scales: { x: { beginAtZero: true }, y: { beginAtZero: true } }
    }
  });

  // Last 10 IN
  window.makeChart('chartIn', {
    type: 'bar',
    data: {
      labels: (DATA.last10_in && DATA.last10_in.labels) || [],
      datasets: [{ label: 'Qty IN', data: (DATA.last10_in && DATA.last10_in.data) || [] }]
    },
    options: {
      indexAxis: 'y',
      scales: { x: { beginAtZero: true }, y: { beginAtZero: true } }
    }
  });

  // Last 10 OUT
  window.makeChart('chartOut', {
    type: 'bar',
    data: {
      labels: (DATA.last10_out && DATA.last10_out.labels) || [],
      datasets: [{ label: 'Qty OUT', data: (DATA.last10_out && DATA.last10_out.data) || [] }]
    },
    options: {
      indexAxis: 'y',
      scales: { x: { beginAtZero: true }, y: { beginAtZero: true } }
    }
  });

    // --- sanity checks ---
    if (!document.getElementById('chartHeatmap')) {
      console.warn('[dashboard] #chartHeatmap canvas not found');
    } else if (!DATA.heatmap || !Array.isArray(DATA.heatmap.values)) {
      console.warn('[dashboard] No heatmap payload on window.DASHBOARD.heatmap');
    } else {
      // optionally verify plugin is present
      var pluginOk = true;
      try {
        // create + destroy a tiny throwaway chart of type 'matrix'
        var ctxTest = document.createElement('canvas').getContext('2d');
        new Chart(ctxTest, {type:'matrix', data:{datasets:[]}}).destroy();
      } catch (e) {
        pluginOk = false;
        console.error('[dashboard] Matrix plugin missing (chartjs-chart-matrix).', e);
      }
      if (pluginOk) {
        // ... your existing heatmap code here ...
      }
    }


  if (DATA.heatmap && Array.isArray(DATA.heatmap.values)) {
    var days  = DATA.heatmap.days  || ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    var hours = DATA.heatmap.hours || Array.from({length:24}, (_,i)=>i);
    var vals  = DATA.heatmap.values;

    // Flatten -> [{x, y, v}]
    var flat = [];
    for (var y = 0; y < vals.length; y++) {
      var row = vals[y] || [];
      for (var x = 0; x < hours.length; x++) {
        var v = Number(row[x] || 0);
        flat.push({ x: x, y: y, v: v });
      }
    }
    var vmax = flat.reduce((m, d) => d.v > m ? d.v : m, 0) || 1;

    function heatColor(value, max) {
      var a = Math.max(0.12, value / max); // 0.12..1
      // soft cyan tint that darkens with value
      return 'rgba(13, 202, 240, ' + a + ')';
    }

// TEMP: draw directly, to rule out helper issues
if (document.getElementById('chartHeatmap')) {
  var ctx = document.getElementById('chartHeatmap').getContext('2d');
  try {
    new Chart(ctx, {
      type: 'matrix',
      data: { datasets: [{
          label: 'Volume',
          data: flat,
          parsing: false, // REQUIRED for {x,y,v}
          backgroundColor: (ctx) => heatColor(ctx.raw.v, vmax),
          borderColor: 'rgba(0,0,0,0.08)',
          borderWidth: 1,
          width:  (ctx) => ctx.chart.chartArea ? (ctx.chart.chartArea.width  / hours.length) - 2 : 10,
          height: (ctx) => ctx.chart.chartArea ? (ctx.chart.chartArea.height / days.length)  - 2 : 10
        }]
        },
      options: {
        maintainAspectRatio: false,
        scales: {
          x: { type: 'linear', offset: true, min: 0, max: hours.length - 1,
               ticks: { stepSize: 1, callback: v => Number.isInteger(v) ? (v + ':00') : '' },
               title: { display: true, text: 'Hour' } },
          y: { type: 'linear', reverse: true, offset: true, min: 0, max: days.length - 1,
               ticks: { stepSize: 1, callback: v => days[v] || '' },
               title: { display: true, text: 'Day' } }
        },
        plugins: { legend: { display: false } }
      }
    });
    console.info('[dashboard] direct matrix chart rendered');
  } catch (e) {
    console.error('[dashboard] direct matrix chart failed', e);
  }
}

    window.makeChart('chartHeatmap', {
      type: 'matrix',
      data: {
        datasets: [{
          label: 'Volume',
          data: flat,
          parsing: false,
          backgroundColor: function (ctx) { return heatColor(ctx.raw.v, vmax); },
          borderColor: 'rgba(0,0,0,0.08)',
          borderWidth: 1,
          width:  function (ctx) { var ca = ctx.chart.chartArea || {width: 0};  return (ca.width  / hours.length) - 2; },
          height: function (ctx) { var ca = ctx.chart.chartArea || {height: 0}; return (ca.height / days.length)  - 2; }
        }]
      },
      options: {
        maintainAspectRatio: false,
        scales: {
          x: {
            type: 'linear',
            offset: true,
            ticks: {
              stepSize: 1,
              callback: function (v) { return Number.isInteger(v) ? (v + ':00') : ''; }
            },
            title: { display: true, text: 'Hour' },
            min: 0, max: hours.length - 1
          },
          y: {
            type: 'linear',
            reverse: true,   // top → bottom
            offset: true,
            ticks: {
              stepSize: 1,
              callback: function (v) { return days[v] || ''; }
            },
            title: { display: true, text: 'Day' },
            min: 0, max: days.length - 1
          }
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: function (items) {
                var r = items[0].raw;
                var hh = String(hours[r.x] ?? r.x).padStart(2, '0');
                return (days[r.y] || '') + ' @ ' + hh + ':00';
              },
              label: function (item) { return 'Value: ' + (item.raw.v || 0); }
            }
          }
        },
        hover: { mode: 'nearest', intersect: true }
      }
    });
  }
})();
