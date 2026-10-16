<?php
require_once __DIR__ . '/../utils/config_init.php';

if (session_status() == PHP_SESSION_NONE) {
  session_start();
}

// Auto-logout after inactivity
$timeout_duration = 600; // 10 minutes

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
  session_unset();
  session_destroy();
  header("Location: ../login.php");
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
if (!isset($page_class))
  $page_class = '';
if (!isset($content))
  $content = '';
if (!isset($css))
  $css = [];
if (!isset($js))
  $js = [];
if (!isset($footer_js))
  $footer_js = '';


if (!defined('APP_ROOT_URL')) {
  define('APP_ROOT_URL', '');
}

$ROOT_ASSET_BASE = APP_ROOT_URL;
$INVENTORY_ASSET_BASE = APP_ROOT_URL . '';
$INVENTORY_ASSET_BASE = str_replace('//', '/', $INVENTORY_ASSET_BASE);

$INSPECTION_ASSET_BASE = APP_ROOT_URL . '/inspection';
$INSPECTION_ASSET_BASE = str_replace('//', '/', $INSPECTION_ASSET_BASE);

$SUPPLIES_ASSET_BASE = APP_ROOT_URL . '/';
$SUPPLIES_ASSET_BASE = str_replace('//', '/', $SUPPLIES_ASSET_BASE);

$REPORTING_ASSET_BASE = APP_ROOT_URL . '/';
$REPORTING_ASSET_BASE = str_replace('//', '/', $REPORTING_ASSET_BASE);

if (!isset($BASE_URL)) {
  $BASE_URL = APP_ROOT_URL;
}

function asset_url(string $path, string $base): string
{
  $url = $base . $path;
  $url = str_replace('//', '/', $url);

  $fs = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . $url;
  if (@is_file($fs))
    $url .= '?v=' . filemtime($fs);
  return $url;
}

/**
 * Calculates the expected full path of the Inspection Dashboard script.
 * Note: This assumes the script is located at the server's document root path
 * appended with the calculated base URL and the script name.
 * * @return string The full path to the inspection dashboard script.
 */
function get_inspection_dashboard_path(string $base_url): string
{
  // Determine the full path on the filesystem.
  // We assume the script is named dashboard.php
  $dashboard_path = $base_url . '/dashboard.php';
  // Clean up double slashes which might occur if $base_url ends with one.
  $dashboard_path = str_replace('//', '/', $dashboard_path);
  return $dashboard_path;
}

// Get the path to the inspection dashboard for comparison
$inspection_dashboard_path = get_inspection_dashboard_path($INSPECTION_ASSET_BASE);


$current_path = strtok($_SERVER['REQUEST_URI'] ?? '', '?');


?>

<?php if (isset($_SESSION['user_id'])): ?>
  <div class="session-badge" data-session-badge>
    <div class="session-timer">
        <span class="badge-session__value" id="sessionCountdown" aria-label="Session time remaining">--:--</span>
    </div>
  </div>
<?php endif; ?>
  
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="format-detection" content="telephone=no" />
  <meta name="color-scheme" content="light dark" />
  <meta name="theme-color" content="#0dcaf0" />

  <title><?= htmlspecialchars($title) ?></title>

  <script>window.INVAPP_BASE = '<?= $BASE_URL ?>';</script>
  <script>
    window.InvApp = window.InvApp || {};
    window.InvApp.session = {
      lastActivity: <?= json_encode($session_last_activity) ?>,
      expiresAt: <?= json_encode($session_expires_at) ?>,
      serverNow: <?= json_encode($session_server_now) ?>,
      keepaliveUrl: <?= json_encode(rtrim((defined('APP_ROOT_URL') ? APP_ROOT_URL : ''), '/') . '/inspection/utils/session_keepalive.php') ?>,
      loginUrl: <?= json_encode('../login.php?timeout=1') ?>
    };
  </script>

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
  <link rel="stylesheet" href="<?= asset_url('/../refactor_css/site.css', $BASE_URL) ?>" />
  <link rel="stylesheet" href="<?= asset_url('/refactor_css/inspection.css', $INSPECTION_ASSET_BASE) ?>" />
  <?php foreach ($css as $href): ?>
    <link rel="stylesheet" href="<?= asset_url($href, $BASE_URL) ?>" />
  <?php endforeach; ?>
</head>



<body class="<?= htmlspecialchars($page_class) ?>">

  <?php include __DIR__ . '/navbar.php'; ?>

  <main id="main" class="container">

    <?= $content ?>

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

  <aside id="appDrawer" class="app-drawer" aria-hidden="true" role="menu" aria-label="Application Menu">
    <div class="app-drawer__panel">
      <header class="app-drawer__hdr">
      </header>
      <section class="app-drawer__menu">
        <a href="<?= $BASE_URL ?>/../dashboard.php" class="app-drawer__item">
          <button><span>Main Menu</span></button>
        </a>
        <a href="<?= $BASE_URL ?>/../inventory/dashboard.php" class="app-drawer__item">
          <button><span>Inventory</span></button>
        </a>
        <a href="<?= $BASE_URL ?>/dashboard.php" class="app-drawer__item">
          <button><span>Inspection</span></button>
        </a>
        <!-- <a href="<?= $SUPPLIES_ASSET_BASE ?>/dashboard.php" class="app-drawer__item">
              <button><span>SUPPLIES</span></button>
            </a>
            <a href="<?= $REPORTING_ASSET_BASE ?>/dashboard.php" class="app-drawer__item">
              <button><span>Reports</span></button>
            </a> -->
      </section>
    </div>
    <button class="app-drawer__backdrop" id="appBackdrop" aria-label="Close"></button>
  </aside>



  <?php if (empty($disable_global_js)): ?>
    <script src="<?= asset_url('/js/script.js', $ROOT_ASSET_BASE) ?>"></script>
  <?php endif; ?>

  <?php foreach ($js as $src): ?>
    <script src="<?= asset_url($src, $ROOT_ASSET_BASE) ?>"></script>
  <?php endforeach; ?>

  <?php if (!empty($footer_js))
    echo $footer_js; ?>


  <script src="<?= asset_url('/js/scroll.js', $BASE_URL) ?>"></script>
  <script src="<?= asset_url('/js/app-drawer.js', $BASE_URL) ?>"></script>
  <script src="<?= asset_url('/js/mobile-menu.js', $BASE_URL) ?>"></script>


<script>
    (function () {
      const badge = document.querySelector('[data-session-badge]');
      const timer = document.getElementById('sessionCountdown');
      const session = window.InvApp && window.InvApp.session ? window.InvApp.session : null;

      if (!badge || !timer || !session) {
        return;
      }

      let serverOffset = (Date.now() / 1000) - session.serverNow;

      window.InvApp = window.InvApp || {};
      window.InvApp.onSessionPayload = function (payload) {
        if (!payload || payload.ok === false) {
          return;
        }
        if (typeof payload.serverNow === 'number') {
          session.serverNow = payload.serverNow;
          serverOffset = (Date.now() / 1000) - session.serverNow;
        }
        if (typeof payload.expiresAt === 'number') {
          session.expiresAt = payload.expiresAt;
        }
      };

      function formatRemaining(totalSeconds) {
        const safeSeconds = Math.max(0, Math.floor(totalSeconds));
        const minutes = Math.floor(safeSeconds / 60);
        const seconds = safeSeconds % 60;
        return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
      }

      function updateCountdown() {
        const estimatedServerNow = (Date.now() / 1000) - serverOffset;
        const remaining = session.expiresAt - estimatedServerNow;

        timer.textContent = formatRemaining(remaining);
        badge.dataset.sessionState = remaining <= 60 ? 'warning' : 'normal';
        badge.classList.toggle('is-expiring', remaining <= 60);

        if (remaining <= 0) {
          window.location.href = session.loginUrl || '../login.php';
        }
      }

      updateCountdown();
      window.setInterval(updateCountdown, 1000);
    })();
  </script>

  <script>
    (function () {
      const session = window.InvApp && window.InvApp.session ? window.InvApp.session : null;

      if (!session || !session.keepaliveUrl) {
        return;
      }

      const minPingIntervalMs = 30000;
      let lastPingAt = 0;
      let pingInFlight = false;

      function applySessionPayload(payload) {
        if (!payload || payload.ok === false) {
          return;
        }

        if (typeof payload.lastActivity === 'number') {
          session.lastActivity = payload.lastActivity;
        }
        if (typeof payload.serverNow === 'number') {
          session.serverNow = payload.serverNow;
        }
        if (typeof payload.expiresAt === 'number') {
          session.expiresAt = payload.expiresAt;
        }
        if (window.InvApp && typeof window.InvApp.onSessionPayload === 'function') {
          window.InvApp.onSessionPayload(payload);
        }
      }

      async function pingSession() {
        const now = Date.now();
        if (pingInFlight || (now - lastPingAt) < minPingIntervalMs) {
          return;
        }

        pingInFlight = true;
        lastPingAt = now;

        try {
          const response = await fetch(session.keepaliveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ reason: 'user-interaction' })
          });

          if (!response.ok) {
            return;
          }

          const payload = await response.json().catch(() => null);
          applySessionPayload(payload);
        } catch (_) {
          // Keep the existing countdown if the keepalive request fails.
        } finally {
          pingInFlight = false;
        }
      }

      window.InvApp.touchSession = function () {
        return pingSession();
      };

      function onUserActivity() {
        if (document.visibilityState === 'hidden') {
          return;
        }

        void pingSession();
      }

      document.addEventListener('invapp:scan-sku', onUserActivity, { capture: true });

      ['pointerdown', 'keydown', 'click', 'input', 'change', 'touchstart', 'wheel', 'focusin'].forEach((eventName) => {
        window.addEventListener(eventName, onUserActivity, { capture: true, passive: true });
      });

      window.setInterval(function () {
        if (document.visibilityState === 'hidden') {
          return;
        }
        void pingSession();
      }, 60000);
    })();
  </script>

  <noscript>
    <div class="toast is-info">Some features work best with JavaScript enabled.</div>
  </noscript>
  <?php include __DIR__ . '/../../templates/punch_clock.php'; // punch clock FAB + PIN modal (every page) ?>
</body>

</html>
