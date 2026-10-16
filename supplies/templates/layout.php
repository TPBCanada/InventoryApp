<?php
require_once __DIR__ . '/../../utils/config_init.php';

if (session_status() == PHP_SESSION_NONE) {
  session_start();
}

// Auto-logout after inactivity
$timeout_duration = 600; // 10 minutes

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
  session_unset();
  session_destroy();
  header("Location: ../login.php?timeout=1");
  exit;
}

$_SESSION['LAST_ACTIVITY'] = time();
$session_last_activity = (int) $_SESSION['LAST_ACTIVITY'];
$session_expires_at = $session_last_activity + $timeout_duration;
$session_server_now = time();

function generateCSRFToken()
{
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

$csrfToken = generateCSRFToken();

if (!isset($title))
  $title = 'TPB';
if (!isset($page_class) || $page_class === '') 
if (!isset($content))
$content = '';
if (!isset($css))
$page_class = 'page-supplies';
  $css = [];
if (!isset($js))
  $js = [];
if (!isset($footer_js))
  $footer_js = '';

if (!isset($BASE_URL)) { 
    $script_path = $_SERVER['SCRIPT_NAME']; 
    $base = rtrim(dirname($script_path), '/\\'); 
    
    if ($base === DIRECTORY_SEPARATOR || $base === '.') { 
        $BASE_URL = ''; 
    } else { 
        $BASE_URL = $base; 
    } 
}



// Same convention as inventory/templates/layout.php: APP_ROOT_URL / $BASE_URL is THIS
// module's folder (e.g. /dev/supplies). The app root and other modules are its parent.
if (!defined('APP_ROOT_URL')) {
  define('APP_ROOT_URL', $BASE_URL);
}

$SUPPLIES_ASSET_BASE = rtrim($BASE_URL, '/');                                 // /dev/supplies
$ROOT_ASSET_BASE = rtrim(str_replace('\\', '/', dirname($SUPPLIES_ASSET_BASE)), '/'); // /dev
$INVENTORY_ASSET_BASE = $ROOT_ASSET_BASE . '/inventory';
$INSPECTION_ASSET_BASE = $ROOT_ASSET_BASE . '/inspection';
$REPORTING_ASSET_BASE = $ROOT_ASSET_BASE . '/reporting';

function asset_url(string $path, string $base): string
{
  $url = $base . $path;
  $url = str_replace('//', '/', $url);

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
  <meta name="color-scheme" content="light dark" />
  <meta name="theme-color" content="#0dcaf0" />

  <title><?= htmlspecialchars($title) ?></title>

  <script nonce="<?= $cspNonceAttr ?>">window.INVAPP_BASE = '<?= $ROOT_ASSET_BASE ?>';</script>

  <script nonce="<?= $cspNonceAttr ?>">
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
  <script nonce="<?= $cspNonceAttr ?>">
    window.InvApp = window.InvApp || {};
    window.InvApp.session = {
      lastActivity: <?= json_encode($session_last_activity) ?>,
      expiresAt: <?= json_encode($session_expires_at) ?>,
      serverNow: <?= json_encode($session_server_now) ?>,
      // supplies has no keepalive endpoint of its own; use inventory's (same session)
      keepaliveUrl: <?= json_encode($INVENTORY_ASSET_BASE . '/utils/session_keepalive.php') ?>,
      loginUrl: <?= json_encode('../login.php?timeout=1') ?>
    };
  </script>

  <script nonce="<?= $cspNonceAttr ?>">
    // Runs after the DOM is ready: the badge is in <body>, below this <head> script.
    document.addEventListener('DOMContentLoaded', function () {
      var session = window.InvApp && window.InvApp.session;
      var countdown = document.getElementById('sessionCountdown');
      var badge = document.querySelector('[data-session-badge]');
      if (!session || !countdown || !badge) return;

      // Click the badge to slide it mostly off-screen (and back)
      badge.addEventListener('click', function () {
        var isCollapsed = badge.classList.toggle('is-collapsed');
        badge.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        badge.title = isCollapsed ? 'Expand session status' : 'Collapse session status';
      });

      var expiresAtMs = (parseInt(session.expiresAt, 10) || 0) * 1000;
      var keepaliveUrl = session.keepaliveUrl;
      var loginUrl = session.loginUrl || '../login.php?timeout=1';
      var keepalivePending = false;
      var lastPingAt = 0;
      var lastCountdownText = '';

      function formatRemaining(ms) {
        var totalSeconds = Math.max(0, Math.floor(ms / 1000));
        var minutes = Math.floor(totalSeconds / 60);
        var seconds = totalSeconds % 60;
        return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
      }

      function goToLogin() {
        window.location.href = loginUrl;
      }

      function syncCountdown() {
        var remaining = expiresAtMs - Date.now();
        if (remaining <= 0) {
          countdown.textContent = '00:00';
          goToLogin();
          return;
        }
        var text = formatRemaining(remaining);
        if (text !== lastCountdownText) {
          countdown.textContent = text;
          lastCountdownText = text;
        }
      }

      function refreshSession(force) {
        if (!keepaliveUrl) return;
        var now = Date.now();
        if (!force && keepalivePending) return;
        if (!force && now - lastPingAt < 15000) return;

        keepalivePending = true;
        lastPingAt = now;

        fetch(keepaliveUrl, {
          method: 'POST',
          credentials: 'same-origin'
        }).then(function (response) {
          if (!response.ok) throw new Error('keepalive failed');
          return response.json();
        }).then(function (payload) {
          if (payload && payload.ok && payload.expiresAt) {
            expiresAtMs = parseInt(payload.expiresAt, 10) * 1000;
            session.expiresAt = payload.expiresAt;
            session.lastActivity = payload.lastActivity || session.lastActivity;
            session.serverNow = payload.serverNow || session.serverNow;
            syncCountdown();
          }
        }).catch(function () {
        }).finally(function () {
          keepalivePending = false;
        });
      }

      var activityTimer = null;
      function onActivity() {
        window.clearTimeout(activityTimer);
        activityTimer = window.setTimeout(function () {
          refreshSession(false);
        }, 500);
      }

      ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function (eventName) {
        window.addEventListener(eventName, onActivity, { passive: true });
      });

      syncCountdown();
      window.setInterval(syncCountdown, 1000);
      window.setInterval(function () {
        refreshSession(false);
      }, 60000);
    });
  </script>

  <link rel="stylesheet" href="<?= asset_url('/refactor_css/supplies.css', $BASE_URL) ?>" />
  <link rel="stylesheet" href="<?= asset_url('/../refactor_css/site.css', $BASE_URL) ?>" />
  <?php foreach ($css as $href): ?>
    <link rel="stylesheet" href="<?= asset_url($href, $BASE_URL) ?>" />
  <?php endforeach; ?>
</head>

<body class="<?= htmlspecialchars($page_class) ?>">

  <?php if (isset($_SESSION['user_id'])): ?>
    <!-- Collapsible session badge (same as inventory/templates/layout.php) -->
    <button
      type="button"
      class="dev-portal-badge"
      data-session-badge
      aria-expanded="true"
      title="Collapse session status">
      <span class="badge-content">
        <span class="badge-collapse-icon" aria-hidden="true">›</span>
        <span class="badge-dot"></span>
        <span class="badge-text">Dev Portal Active</span>
        <span class="badge-user"><?= htmlspecialchars($_SESSION['username'] ?? 'Developer') ?></span>
        <span class="badge-session">
          <span class="badge-session__label">Session</span>
          <span class="badge-session__value" id="sessionCountdown" aria-label="Session time remaining">--:--</span>
        </span>
      </span>
    </button>
  <?php endif; ?>

  <?php include __DIR__ . '/navbar.php'; ?>

  <main id="main" class="container">

    <?= $content ?>

    <a alt="scroll to top" href="#" class="btn btn-ghost">
      ▲
    </a>

    <button id="appLaunchBtn" class="app-fab" type="button" title="Open Apps Menu" aria-haspopup="menu"
      aria-controls="appDrawer" aria-expanded="false">
      <span class="app-icon-grid">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"
          class="text-gray-700 dark:text-gray-200">
          <rect x="3" y="3" width="7" height="7" rx="1.5" ry="1.5" />
          <rect x="3" y="14" width="7" height="7" rx="1.5" ry="1.5" />
          <rect x="14" y="3" width="7" height="7" rx="1.5" ry="1.5" />
          <rect x="14" y="14" width="7" height="7" rx="1.5" ry="1.5" />
        </svg>
      </span>
    </button>

  </main>

  <!---------------------------------->
  <!------ Application Button ----->
  <!---------------------------------->

  <aside id="appDrawer" class="app-drawer" aria-hidden="true" role="menu" aria-label="Application Menu">
    <div class="app-drawer__panel">
      <header class="app-drawer__hdr">
      </header>

      <section class="app-drawer__menu">
        <a href="<?= $ROOT_ASSET_BASE ?>/dashboard.php" class="app-drawer__item">
          <button><span>Main Menu</span></button>
        </a>
        <a href="<?= $INVENTORY_ASSET_BASE ?>/dashboard.php" class="app-drawer__item">
          <button><span>Inventory</span></button>
        </a>
        <a href="<?= $INSPECTION_ASSET_BASE ?>/dashboard.php" class="app-drawer__item">
          <button><span>Inspection</span></button>
        </a>
        <a href="<?= $SUPPLIES_ASSET_BASE ?>/dashboard.php" class="app-drawer__item">
          <button><span>Supplies</span></button>
        </a>
        <!-- <a href="<?= $REPORTING_ASSET_BASE ?>/dashboard.php" class="app-drawer__item">
          <button><span>Reports</span></button>
        </a> -->
      </section>
    </div>
    <button class="app-drawer__backdrop" id="appBackdrop" aria-label="Close"></button>
  </aside>



  <?php if (empty($disable_global_js)): ?>
    <script src="<?= asset_url('/js/script.js', $ROOT_ASSET_BASE) ?>"></script>
  <?php endif; ?>
  <script src="<?= asset_url('/js/http-interceptor.js', $BASE_URL) ?>"></script>

  <?php foreach ($js as $src): ?>
    <script src="<?= asset_url($src, $ROOT_ASSET_BASE) ?>"></script>
  <?php endforeach; ?>

  <?php if (!empty($footer_js))
    echo $footer_js; ?>


  <script src="<?= asset_url('/js/scroll.js', $ROOT_ASSET_BASE) ?>"></script>
  <script src="<?= asset_url('/js/app-drawer.js', $ROOT_ASSET_BASE) ?>"></script>
  <script src="<?= asset_url('/js/mobile-menu.js', $ROOT_ASSET_BASE) ?>"></script>



  <noscript>
    <div class="toast is-info">Some features work best with JavaScript enabled.</div>
  </noscript>
  <?php include __DIR__ . '/../../templates/punch_clock.php'; // punch clock FAB + PIN modal (every page) ?>
</body>

</html>



