// dev/js/dashboard.js
(function () {
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
})();
