<?php
// dev/templates/layout.php

// -------- Defaults / page vars --------
if (!isset($title))       $title = 'TPB';
if (!isset($page_class))  $page_class = '';          // e.g. 'page-take theme-glass'
if (!isset($content))     $content = '';
if (!isset($css))         $css = [];                 // extra stylesheets: ['/assets/css/chart.css']
if (!isset($js))          $js = [];                  // extra scripts (deferred): ['/assets/js/chart.js']
if (!isset($footer_js))   $footer_js = '';           // inline script HTML string

// Base URL for assets (handles subdir installs)
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// Asset helper (cache-bust with filemtime if possible)
function asset_url(string $path, string $base): string {
  $url = $base . $path;
  $fs  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . $url;
  if (@is_file($fs)) $url .= '?v=' . filemtime($fs);
  return $url;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= htmlspecialchars($title) ?></title>

  <!-- Color scheme hint for form controls -->
  <meta name="color-scheme" content="light dark" />
  <meta name="theme-color" content="#0dcaf0" />

  <!-- Prevent dark-mode flash: set data-theme before CSS paints -->
  <script>
    (function () {
      try {
        const saved = localStorage.getItem('invapp:theme');
        if (saved === 'dark' || saved === 'light') {
          document.documentElement.setAttribute('data-theme', saved);
          document.body && (document.body.dataset.theme = saved); // fallback if body selector is used
        }
      } catch (_) {}
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
  </main>

    <!-- Global JS (can be disabled per-page with $disable_global_js = true) -->
  <?php if (empty($disable_global_js)): ?>
    <script defer src="<?= asset_url('/js/script.js', $BASE_URL) ?>"></script>
  <?php endif; ?>


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
      } catch (_) {}
    };
  </script>

  <!-- Page-specific JS -->
  <?php foreach ($js as $src): ?>
    <script defer src="<?= asset_url($src, $BASE_URL) ?>"></script>
  <?php endforeach; ?>

  <!-- Inline page JS (if provided) -->
  <?php if (!empty($footer_js)) echo $footer_js; ?>

  <!-- No-JS notice (optional) -->
  <noscript><div class="toast is-info">Some features work best with JavaScript enabled.</div></noscript>
</body>
</html>
