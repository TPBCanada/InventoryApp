<?php

// -------- Defaults / page vars --------
if (!isset($title))
  $title = 'TPB';
if (!isset($page_class))
  $page_class = '';          // e.g. 'page-take theme-glass'
if (!isset($content))
  $content = '';
if (!isset($css))
  $css = [];                 // extra stylesheets: ['/assets/css/chart.css']
if (!isset($js))
  $js = [];                  // extra scripts (deferred): ['/assets/js/chart.js']
if (!isset($footer_js))
  $footer_js = '';           // inline script HTML string
if (!isset($js))
  $js = [];
$js[] = '/js/scan-drawer.js';



// Base URL for assets (should be the application root, typically '/dev')
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
// If called from a sub-directory like /dev/utils, strip the last directory
if (basename($script_dir) === 'utils') {
    $BASE_URL = rtrim(dirname($script_dir), '/'); // Gets /dev
} else {
    $BASE_URL = rtrim($script_dir, '/'); // Gets /dev or whatever
}
// Ensure $BASE_URL is not just empty if at root
if ($BASE_URL === '') $BASE_URL = '/';
// Asset helper (cache-bust with filemtime if possible)
function asset_url(string $path, string $base): string
{
  $url = $base . $path;
  $fs = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . $url;
  if (@is_file($fs))
    $url .= '?v=' . filemtime($fs);
  return $url;
}

?>


<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="format-detection" content="telephone=no" />

  <!-- Color scheme hint for form controls -->
  <meta name="color-scheme" content="light dark" />
  <meta name="theme-color" content="#0dcaf0" />

  <title><?= htmlspecialchars($title) ?></title>

  <script>window.INVAPP_BASE = '<?= $BASE_URL ?>';</script>

  <!-- Prevent dark-mode flash: set data-theme before CSS paints -->
  <script>
    (function () {
      try {
        const saved = localStorage.getItem('invapp:theme');
        if (saved === 'dark' || saved === 'light') {
          document.documentElement.setAttribute('data-theme', saved);
          document.body && (document.body.dataset.theme = saved);
        }
      } catch (_) { }
    })();
  </script>

  <!-- Global stylesheet -->
    <link rel="stylesheet" href="<?= asset_url('/refactor_css/site.css', $BASE_URL) ?>" />
    
  <?php foreach ($css as $href): ?>
    <link rel="stylesheet" href="<?= asset_url($href, $BASE_URL) ?>" />
  <?php endforeach; ?>
</head>

<body class="<?= htmlspecialchars($page_class) ?>">

  <!-- Accessible skip link -->
  <a class="sr-only" href="#main">Skip to content</a>

  <?php include __DIR__ . '/navbar.php'; ?>

  <!-- Page Content -->
  <main id="main" class="container">

    <?= $content ?>


    <!-- Floating Scan button -->
    <button id="scanLaunchBtn" class="scan-fab" type="button" title="Open Scanner" aria-haspopup="dialog"
      aria-controls="scanDrawer" aria-expanded="false">
      SCAN 📷
    </button>

  </main>


  <!-- Global Scan Drawer -->
  <aside id="scanDrawer" class="scan-drawer" aria-hidden="true" role="dialog" aria-label="Scan & Locate SKU">
    <div class="scan-drawer__panel">
      <header class="scan-drawer__hdr">
        <h2>Scan &amp; Locate SKU</h2>
        <button class="scan-drawer__close" id="scanCloseBtn" aria-label="Close">&times;</button>
      </header>

      <section class="scan-drawer__cam">
        <div class="scan-box">
          <video id="scanVideo" autoplay playsinline muted></video>
          <div class="overlay">
            <div class="bracket" id="scanBracket"></div>
          </div>
        </div>
        <div class="scan-controls">
          <button type="button" class="btn" id="scanStartBtn">Start Camera</button>
          <button type="button" class="btn secondary" id="scanStopBtn" disabled>Stop</button>

          <input id="scanManual" type="text" placeholder="Or type/paste a code..." inputmode="numeric" />

          <button type="button" class="btn" id="scanLookupBtn">Lookup</button>
          <button type="button" class="btn tertiary" id="scanResetBtn">Reset</button> 
        </div>
        <div id="scanSearchList" class="card" hidden></div>

        <p class="scan-hint">Uses the browser's BarcodeDetector when available; otherwise use manual entry.</p>
      </section>

      <section class="scan-drawer__results" id="scanResults" hidden>
        <div class="card">
            <div class="product-image-container">
              <img id="scanProductImage" src="" alt="Product Image" style="max-width: 100%; height: auto; display: none;" />
            </div>
            <div class="sku-grid" id="scanSkuGrid"></div>
        </div>
        <div class="card">
            <div class="sku-grid" id="scanSkuGrid"></div>
        </div>
        <div class="card">
          <div class="muted" id="scanSummary"></div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Location</th>
                  <th>On-Hand</th>
                  <th>Last Movement</th>
                </tr>
              </thead>
              <tbody id="scanLocBody"></tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
    <button class="scan-drawer__backdrop" id="scanBackdrop" aria-label="Close"></button>
  </aside>





  <!-- Global JS (can be disabled per-page with $disable_global_js = true) -->
<!-- 1) Chart core + Matrix plugin (exactly once, before any chart code) -->
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script defer src="https://cdn.jsdelivr.net/npm/chartjs-chart-matrix@2"></script>

<!-- 2) Global helper (defines window.makeChart, etc.) -->
<?php if (empty($disable_global_js)): ?>
  <script defer src="<?= asset_url('/js/script.js', $BASE_URL) ?>"></script>
<?php endif; ?>

<!-- 3) Other per-page JS files (scan drawer, etc.) -->
<?php foreach ($js as $src): ?>
  <script defer src="<?= asset_url($src, $BASE_URL) ?>"></script>
<?php endforeach; ?>

<!-- 4) Page data + page scripts (DO NOT include Chart again here) -->
<?php if (!empty($footer_js)) echo $footer_js; ?>



  <!-- Theme toggle mini-API (optional; call window.InvApp.setTheme('dark'|'light'|'auto')) -->
  <script>
    window.InvApp = window.InvApp || {};
    window.InvApp.setTheme = function (mode) {
      try {
        if (mode === 'auto') {
          localStorage.removeItem('invapp:theme');
          document.documentElement.removeAttribute('data-theme');
        } else if (mode === 'dark' || mode === 'light') {
          localStorage.setItem('invapp:theme', mode);
          document.documentElement.setAttribute('data-theme', mode);
        }
      } catch (_) { }
    };
  </script>
  <script defer src="<?= asset_url('/js/scroll.js', $BASE_URL) ?>"></script>
  <script>
    (function () {
      const menu = document.getElementById('site-menu');
      const openBtn = document.getElementById('hamburgerBtn');
      const closeBtn = document.getElementById('menuCloseBtn');

      if (!menu || !openBtn || !closeBtn) return;

      let lastFocus = null;

      function openMenu() {
        lastFocus = document.activeElement;
        menu.hidden = false;
        menu.setAttribute('data-open', 'true');
        openBtn.setAttribute('aria-expanded', 'true');
        const firstLink = menu.querySelector('a,button');
        firstLink && firstLink.focus();
        document.addEventListener('keydown', onKey);
        document.addEventListener('click', onOutsideClick, true);
      }

      function closeMenu() {
        menu.removeAttribute('data-open');
        openBtn.setAttribute('aria-expanded', 'false');
        setTimeout(() => { menu.hidden = true; }, 200);
        lastFocus && lastFocus.focus();
        document.removeEventListener('keydown', onKey);
        document.removeEventListener('click', onOutsideClick, true);
      }

      function onKey(e) { if (e.key === 'Escape') closeMenu(); }

      function onOutsideClick(e) {
        // Close when clicking the dark overlay (outside the inner panel)
        if (e.target === menu) { closeMenu(); }
      }

      openBtn.addEventListener('click', () => {
        const isOpen = menu.getAttribute('data-open') === 'true';
        isOpen ? closeMenu() : openMenu();
      });

      closeBtn.addEventListener('click', closeMenu);
    })();
  </script>





  <!-- Page-specific JS -->
  <?php foreach ($js as $src): ?>
    <script defer src="<?= asset_url($src, $BASE_URL) ?>"></script>
  <?php endforeach; ?>

  <!-- Inline page JS (if provided) -->
  <?php if (!empty($footer_js))
    echo $footer_js; ?>

  <!-- No-JS notice (optional) -->
  <noscript>
    <div class="toast is-info">Some features work best with JavaScript enabled.</div>
  </noscript>
</body>

</html>