// dev/js/script.js

// Run after DOM is parsed if included with defer
(function () {
  // Example: add a 'js' class to <html> so you can target CSS
  document.documentElement.classList.add('has-js');

  // Global Chart.js defaults (affects all charts)
  if (window.Chart) {
    Chart.defaults.responsive = true;
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.plugins.legend.display = false;
    Chart.defaults.animation.duration = 200;
  }

  // Simple helper to create a chart if the canvas exists
  window.makeChart = function (id, cfg) {
    var el = document.getElementById(id);
    if (!el) return null;
    return new Chart(el, cfg);
  };
})();
