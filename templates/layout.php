<?php

if (session_status() == PHP_SESSION_NONE) {
  session_start();
}

// Auto-logout after inactivity
$timeout_duration = 600; // 10 minutes

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
  session_destroy();
  header("Location: login.php");
  exit;
}

$_SESSION['LAST_ACTIVITY'] = time();
$session_last_activity = (int) $_SESSION['LAST_ACTIVITY'];
$session_expires_at = $session_last_activity + $timeout_duration;
$session_server_now = time();

$isDevPortal = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/dev/') !== false;

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
  $page_class = 'page-welcome';
if (!isset($content))
  $content = '';
if (!isset($css))
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

if (!isset($BASE_URL)) {
  $BASE_URL = '';
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
?>

<?php

?>


<!DOCTYPE html>
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

  <script>
    window.InvApp = window.InvApp || {};
    window.InvApp.session = {
      timeoutSeconds: <?= (int) $timeout_duration ?>,
      lastActivity: <?= (int) $session_last_activity ?>,
      expiresAt: <?= (int) $session_expires_at ?>,
      serverNow: <?= (int) $session_server_now ?>,
      loginUrl: <?= json_encode('login.php') ?>,
      keepaliveUrl: <?= json_encode(rtrim($BASE_URL, '/') . '/utils/session_keepalive.php') ?>
    };
  </script>

  <style>
    .badge-content {
      cursor: pointer;
      transition: transform 300ms cubic-bezier(0.16, 1, 0.3, 1);
      user-select: none;
    }
    .badge-content.is-collapsed {
      transform: translateX(calc(100% - 18px));
    }
  </style>
  <link rel="stylesheet" href="<?= asset_url('/refactor_css/site.css', $BASE_URL) ?>" />
  <link rel="stylesheet" href="<?= asset_url('/refactor_css/dashboard.css', $BASE_URL) ?>" />
  <link rel="stylesheet" href="<?= asset_url('/refactor_css/welcome.css', $BASE_URL) ?>" />
  <link rel="stylesheet" href="<?= asset_url('/refactor_css/help.css', $BASE_URL) ?>" />
  <?php foreach ($css as $href): ?>
    <link rel="stylesheet" href="<?= asset_url($href, $BASE_URL) ?>" />
  <?php endforeach; ?>

</head>

<body class="<?= htmlspecialchars($page_class) ?>">

  <?php if (isset($_SESSION['user_id'])): ?>
    <div class="dev-portal-badge" data-session-badge>
      <div class="badge-content">

        <?php if ($isDevPortal): ?>
          <span class="badge-dot"></span>
          <span class="badge-text">Dev Portal Active</span>
          <span class="badge-user">
            <?= htmlspecialchars($_SESSION['username'] ?? 'Developer') ?>
          </span>
        <?php endif; ?>

        <span class="badge-session">
          <span class="badge-session__label">Session</span>
          <span class="badge-session__value" id="sessionCountdown" aria-label="Session time remaining">--:--</span>
        </span>

      </div>
    </div>
  <?php endif; ?>


  <?php include __DIR__ . '/navbar.php'; ?>
  <?php include __DIR__ . '/../utils/calculator.php'; ?>

  <!-- Feature Discovery Modal -->
  <div id="featureDiscoveryOverlay" class="fd-overlay">
    <div class="fd-card">
      <h2 class="fd-title">October 2026 Updates</h2>
      <div class="fd-scroll-area">

        <section class="fd-section">
          <h3>Order Operations</h3>
          <ul class="fd-list">
            <li><strong>Exclude Users:</strong> Filter any picker or packer out of every total, average, and chart from the operations dashboard.</li>
            <li><strong>SKU Filter:</strong> Narrow charts and KPIs to single-SKU or multi-SKU orders only.</li>
            <li><strong>Per-Active-Hour Rates:</strong> Charts now show orders per clock hour where work actually happened.</li>
          </ul>
        </section>

        <section class="fd-section">
          <h3>Session & Security</h3>
          <ul class="fd-list">
            <li><strong>Session Keepalive:</strong> Session timer now resets on activity — no more surprise logouts while working.</li>
            <li><strong>Collapsible Badge:</strong> Click the session badge to tuck it away; it remembers your preference.</li>
          </ul>
        </section>

        <section class="fd-section">
          <h3>Dashboard & UI</h3>
          <ul class="fd-list">
            <li><strong>Redesigned Dash:</strong> A cleaner, faster overview of your warehouse.</li>
            <li><strong>Enhanced Tables:</strong> Optimized layouts for better readability.</li>
            <li><strong>Lot Management:</strong> Full control to create, edit, or remove product lots.</li>
          </ul>
        </section>

      </div>
      <button id="acknowledgeFeaturesBtn" class="fd-primary-btn">
        Start Exploring
      </button>
    </div>
  </div>

  <main id="main" class="container">

    <?= $content ?>

    <button id="calcLaunchBtn" class="calc-fab" type="button" title="Open Calculator" aria-haspopup="dialog"
      aria-controls="calcDrawer" aria-expanded="false">🔢</button>

    <button id="messageLaunchBtn" class="message-fab" type="button" title="Open Message Center" aria-haspopup="dialog"
      aria-controls="messageDrawer" aria-expanded="false">
      <span id="messageCount" class="message-fab__count">0</span>
      <span id="messageCountBadge" class="message-fab__badge" hidden>0</span>
    </button>

    <button id="helpButton" class="help-fab" type="button" title="Open help" aria-haspopup="dialog"
      aria-controls="helpDrawer" aria-expanded="false">
      <span aria-hidden="true">HELP</span>
      <span class="sr-only">Open help and tutorials</span>
    </button>

    <div class="mobile-fab-menu" id="mobileFabMenu" data-open="false">
      <button type="button" class="mobile-fab-menu__toggle" id="mobileFabMenuToggle" aria-controls="mobileFabPanel"
        aria-expanded="false" aria-label="Open quick actions menu">
        <span aria-hidden="true">+</span>
      </button>

      <div class="mobile-fab-menu__panel" id="mobileFabPanel" hidden>
        <button type="button" class="mobile-fab-menu__item" data-fab-target="calcLaunchBtn">
          <span class="mobile-fab-menu__icon" aria-hidden="true">CALC</span>
          <span>Calculator</span>
        </button>

        <button type="button" class="mobile-fab-menu__item" data-fab-target="helpButton">
          <span class="mobile-fab-menu__icon" aria-hidden="true">HELP</span>
          <span>Help</span>
        </button>

        <button type="button" class="mobile-fab-menu__item" data-fab-target="messageLaunchBtn"
          aria-haspopup="dialog" aria-controls="messageDrawer">
          <span class="mobile-fab-menu__icon" aria-hidden="true">MSG</span>
          <span>Message Center</span>
        </button>
      </div>
    </div>

  </main>

  <!-- Help Modal -->
  <div id="helpDrawer" class="help-modal" role="dialog" aria-modal="true" aria-label="Application Help"
    aria-hidden="true">
    <div class="help-modal__backdrop" id="helpBackdrop" tabindex="-1"></div>
    <div class="help-modal__dialog" role="document" tabindex="-1">
      <button class="help-modal__close" id="helpDrawerClose" aria-label="Close help dialog">
        &times;
      </button>
      <header class="help-modal__header">
        <h3>Hey, how can I help?</h3>
        <input type="text" id="helpSearchInput" placeholder="Ask a question or describe an issue" autocomplete="off" />
      </header>
      <div class="help-modal__content" role="list">
        <!-- <a class="help-modal__tile" href="#" role="listitem">
          <strong>Account Settings</strong>
          <span>Update profiles, passwords, and permissions.</span>
        </a> -->
        <a class="help-modal__tile" href="#" role="listitem"
          data-preview-image="<?= asset_url('/img/time_off_request.png', $BASE_URL) ?>"
          data-preview-heading="Submit a calendar request"
          data-preview-copy="Follow these steps to create a time-off request inside the calendar workflow.">
          <strong>Calendar Use</strong>
          <span>View pick/inspection schedules and sync calendars.</span>
        </a>
        <a class="help-modal__tile" href="#" role="listitem"
          data-preview-image="<?= asset_url('/img/update_password.png', $BASE_URL) ?>"
          data-preview-heading="Change your password"
          data-preview-copy="Use the security settings page to rotate your password and keep multi-factor authentication enabled.">
          <strong>Change Password</strong>
          <span>Secure your account with a fresh password.</span>
        </a>
      </div>
      <div class="help-modal__preview" aria-live="polite">
        <div class="help-modal__preview-inner">
          <h4 id="helpPreviewHeading"></h4>
          <img id="helpCalendarImage" src="" alt="" />
          <p id="helpCalendarCopy"></p>
        </div>
      </div>
      <footer class="help-modal__footer">
        <p>Need something more specific?</p>
        <a class="help-modal__footer-link" href="mailto:support@inventoryapp.local">support@inventoryapp.local</a>
      </footer>
    </div>
  </div>

  <!-- ============================= -->
  <!-- Message Center -->
  <!-- ============================= -->

  <aside id="messageDrawer" class="message-drawer" aria-hidden="true" role="dialog" aria-label="Message Center">
    <div class="message-drawer__panel">
      <header class="message-drawer__header">
        <div>
          <div class="message-drawer__eyebrow">MESSAGE CENTER</div>
          <h2>Messages</h2>
          <div class="message-drawer__meta">
            <strong><?= htmlspecialchars((string) ($_SESSION['username'] ?? 'User')) ?></strong>
            <span aria-hidden="true">•</span>
            <span id="messageCounter">0 messages</span>
          </div>
        </div>
        <button id="messageCloseBtn" class="message-drawer__close" type="button" aria-label="Close">&times;</button>
      </header>

      <section class="message-drawer__body">
        <div id="messageStatus" class="message-drawer__status" aria-live="polite">Loading messages...</div>
        <div id="messageList"></div>
      </section>
    </div>

    <button id="messageBackdrop" class="message-drawer__backdrop" type="button" aria-label="Close">
    </button>
  </aside>


  <?php if (empty($disable_global_js)): ?>
    <script defer src="<?= asset_url('/js/script.js', $BASE_URL) ?>"></script>
  <?php endif; ?>

  <?php foreach ($js as $src): ?>
    <script defer src="<?= asset_url($src, $BASE_URL) ?>"></script>
  <?php endforeach; ?>

  <?php if (!empty($footer_js))
    echo $footer_js; ?>

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

  <!-- Feature Discovery Modal -->
  <script>
    document.addEventListener("DOMContentLoaded", () => {
      const featureOverlay = document.getElementById("featureDiscoveryOverlay");
      const ackButton = document.getElementById("acknowledgeFeaturesBtn");

      const FEATURE_VERSION = "release_2026_10_20";

      const hasSeenUpdate = localStorage.getItem(FEATURE_VERSION);

      if (!hasSeenUpdate) {
        // Show after a 1-second delay for a smoother entry
        setTimeout(() => {
          featureOverlay.style.display = "flex";
        }, 1000);
      }

      ackButton.addEventListener("click", () => {
        featureOverlay.style.display = "none";
        localStorage.setItem(FEATURE_VERSION, "true");
      });
    });
  </script>

  <script>
    (function () {
      const badge = document.querySelector('[data-session-badge]');
      const timer = document.getElementById('sessionCountdown');
      const session = window.InvApp && window.InvApp.session ? window.InvApp.session : null;

      if (!badge || !timer || !session) {
        return;
      }

      let serverOffset = (Date.now() / 1000) - session.serverNow;

      window.InvApp.onSessionPayload = function (payload) {
        if (!payload || payload.ok === false) return;
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
          window.location.href = session.loginUrl || 'login.php';
        }
      }

      updateCountdown();
      window.setInterval(updateCountdown, 1000);

      const content = badge.classList.contains('badge-content') ? badge : badge.querySelector('.badge-content');
      if (content) {
        const BADGE_KEY = 'invapp:badge-collapsed';
        if (localStorage.getItem(BADGE_KEY) === '1') content.classList.add('is-collapsed');
        content.addEventListener('click', () => {
          content.classList.toggle('is-collapsed');
          localStorage.setItem(BADGE_KEY, content.classList.contains('is-collapsed') ? '1' : '0');
        });
      }
    })();
  </script>

  <script>
    (function () {
      const session = window.InvApp && window.InvApp.session ? window.InvApp.session : null;

      if (!session || !session.keepaliveUrl) return;

      const minPingIntervalMs = 30000;
      let lastPingAt = 0;
      let pingInFlight = false;

      function applySessionPayload(payload) {
        if (!payload || payload.ok === false) return;
        if (typeof payload.lastActivity === 'number') session.lastActivity = payload.lastActivity;
        if (typeof payload.serverNow === 'number') session.serverNow = payload.serverNow;
        if (typeof payload.expiresAt === 'number') session.expiresAt = payload.expiresAt;
        if (window.InvApp && typeof window.InvApp.onSessionPayload === 'function') {
          window.InvApp.onSessionPayload(payload);
        }
      }

      async function pingSession() {
        const now = Date.now();
        if (pingInFlight || (now - lastPingAt) < minPingIntervalMs) return;

        pingInFlight = true;
        lastPingAt = now;

        try {
          const response = await fetch(session.keepaliveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ reason: 'user-interaction' })
          });
          if (response.ok) {
            const payload = await response.json().catch(() => null);
            applySessionPayload(payload);
          }
        } catch (_) {
        } finally {
          pingInFlight = false;
        }
      }

      window.InvApp.touchSession = () => pingSession();

      function onUserActivity() {
        if (document.visibilityState === 'hidden') return;
        void pingSession();
      }

      ['pointerdown', 'keydown', 'click', 'input', 'change', 'touchstart', 'wheel', 'focusin'].forEach(evt => {
        window.addEventListener(evt, onUserActivity, { capture: true, passive: true });
      });

      window.setInterval(function () {
        if (document.visibilityState !== 'hidden') void pingSession();
      }, 60000);
    })();
  </script>

  <script defer src="<?= asset_url('/js/scroll.js', $BASE_URL) ?>"></script>
  <script defer src="<?= asset_url('/js/settings-drawer.js', $BASE_URL) ?>"></script>
  <script nonce="<?= $cspNonceAttr ?>">window.MESSAGE_CENTER_ENDPOINT = <?= json_encode(rtrim($ROOT_ASSET_BASE, '/') . '/utils/message_center.php') ?>;</script>
  <script defer src="<?= asset_url('/js/mobile-menu.js', $BASE_URL) ?>"></script>
  <script defer src="<?= asset_url('/js/mobile-fab-menu.js', $BASE_URL) ?>"></script>
  <script defer src="<?= asset_url('/js/calc-drawer.js', $BASE_URL) ?>"></script>
  <script defer src="<?= asset_url('/js/help-drawer.js', $BASE_URL) ?>"></script>
  <script defer src="<?= asset_url('/js/message-center.js', $BASE_URL) ?>"></script>

  <noscript>
    <div class="toast is-info">Some features work best with JavaScript enabled.</div>
  </noscript>
  <?php include __DIR__ . '/punch_clock.php'; // punch clock FAB + PIN modal (every page) ?>
</body>

</html>
