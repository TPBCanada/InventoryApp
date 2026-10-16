<?php
declare(strict_types=1);
require_once __DIR__ . '/../utils/config_init.php';
require_once __DIR__ . '/../../lib/security.php';

$timeout_duration = 600;

if (
  isset($_SESSION['LAST_ACTIVITY']) &&
  (time() - (int) $_SESSION['LAST_ACTIVITY']) > $timeout_duration
) {
  $_SESSION = [];

  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params['path'],
      $params['domain'],
      $params['secure'],
      $params['httponly']
    );
  }

  session_destroy();

  header('Location: ../login.php?timeout=1');
  exit;
}

$_SESSION['LAST_ACTIVITY'] = time();

$session_last_activity = (int) $_SESSION['LAST_ACTIVITY'];
$session_expires_at = $session_last_activity + $timeout_duration;
$session_server_now = time();

$csrfToken = app_csrf_token();
$isDevPortal = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/dev/') !== false;


// function generateCSRFToken()
// {
//   if (empty($_SESSION['csrf_token'])) {
//     $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
//   }
//   return $_SESSION['csrf_token'];
// }

// $csrfToken = generateCSRFToken();

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

if (!isset($BASE_URL) || $BASE_URL === '') {
  $script_path = $_SERVER['SCRIPT_NAME'] ?? '';
  $BASE_URL = rtrim(dirname($script_path), '/\\');
  if ($BASE_URL === '.' || $BASE_URL === DIRECTORY_SEPARATOR) {
    $BASE_URL = '';
  }
}

$INVENTORY_ASSET_BASE = rtrim($BASE_URL, '/');
if ($INVENTORY_ASSET_BASE === '') {
  $INVENTORY_ASSET_BASE = APP_ROOT_URL . '/inventory';
}
$INVENTORY_ASSET_BASE = str_replace('//', '/', $INVENTORY_ASSET_BASE);

$ROOT_ASSET_BASE = APP_ROOT_URL;
if ($ROOT_ASSET_BASE === '') {
  $ROOT_ASSET_BASE = preg_replace('~/inventory$~', '', $INVENTORY_ASSET_BASE);
}
$ROOT_ASSET_BASE = rtrim(str_replace('//', '/', (string) $ROOT_ASSET_BASE), '/');

if (!isset($BASE_URL)) {
  $BASE_URL = APP_ROOT_URL;
}

function asset_url(string $path, string $base): string
{
  $url = $base . $path;
  $url = str_replace('//', '/', $url);

  $fs = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '') . $url;
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

  <script>window.INVAPP_BASE = '<?= $BASE_URL ?>';</script>
  <script nonce="<?= $cspNonceAttr ?>">
    window.InvApp = window.InvApp || {};
    window.InvApp.session = {
      timeoutSeconds: <?= (int) $timeout_duration ?>,
      lastActivity: <?= (int) $session_last_activity ?>,
      expiresAt: <?= (int) $session_expires_at ?>,
      serverNow: <?= (int) $session_server_now ?>,
      loginUrl: <?= json_encode('../login.php') ?>,
      keepaliveUrl: <?= json_encode(rtrim($BASE_URL, '/') . '/utils/session_keepalive.php') ?>
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
  <link rel="stylesheet" href="<?= asset_url('/refactor_css/inventory.css', $BASE_URL) ?>" />
  <?php foreach ($css as $href): ?>
    <link rel="stylesheet" href="<?= asset_url($href, $BASE_URL) ?>" />
  <?php endforeach; ?>

  <?= $extra_css ?? '' ?>
</head>

<body class="<?= htmlspecialchars($page_class) ?>">



  <a class="sr-only" href="#main">Skip to content</a>

  <?php if (isset($_SESSION['user_id'])): ?>

    <button 
      type="button" 
      class="dev-portal-badge" 
      data-session-badge 
      aria-expanded="true"
      title="Collapse session status">
      
      <span class="badge-content">

        <span class="badge-collapse-icon" aria-hidden="true">›</span>

        <?php if ($isDevPortal): ?>
          <span class="badge-dot"></span>
          <span class="badge-text">Dev Portal Active</span>
          <span class="badge-user"><?= htmlspecialchars($_SESSION['username'] ?? 'Developer') ?></span>
        <?php endif; ?>

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
    <?php include __DIR__ . '/../utils/barcode_modal.php'; ?>
    <a alt="scroll to top" href="#" class="btn btn-ghost scroll-top" id="scrollTopBtn" aria-label="Scroll to top">
      ▲
    </a>


    <button id="calcLaunchBtn" class="calc-fab" type="button" title="Open Calculator" aria-haspopup="dialog"
      aria-controls="calcDrawer" aria-expanded="false">🔢</button>

    <button id="scanLaunchBtn" class="scan-fab" type="button" title="Open Scanner" aria-haspopup="dialog"
      aria-controls="scanDrawer" aria-expanded="false">🔍</button>

    <!-- <button id="countLaunchBtn" class="count-fab" type="button" title="Open My Count List" aria-haspopup="dialog"
      aria-controls="countDrawer" aria-expanded="false">✓</button> -->

    <!-- <button id="ordersLaunchBtn" class="orders-fab" type="button" title="Open Sales Orders Feed" aria-haspopup="dialog"
      aria-controls="ordersDrawer" aria-expanded="false">📝</button> -->

    <button id="messageLaunchBtn" class="message-fab" type="button" title="Open Message Center" aria-haspopup="dialog"
      aria-controls="messageDrawer" aria-expanded="false">
      <span id="messageCount" class="message-fab__count">0</span>
      <span id="messageCountBadge" class="message-fab__badge" hidden>0</span>
    </button>

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

        <button type="button" class="mobile-fab-menu__item" data-fab-target="scanLaunchBtn">
          <span class="mobile-fab-menu__icon" aria-hidden="true">SCAN</span>
          <span>Scanner</span>
        </button>

        <button type="button" class="mobile-fab-menu__item" data-fab-target="countLaunchBtn">
          <span class="mobile-fab-menu__icon" aria-hidden="true">COUNT</span>
          <span>My Counts</span>
        </button>

        <!-- <button type="button" class="mobile-fab-menu__item" data-fab-target="ordersLaunchBtn">
          <span class="mobile-fab-menu__icon" aria-hidden="true">ORD</span>
          <span>Orders</span>
        </button> -->

        <button type="button" class="mobile-fab-menu__item" data-fab-target="messageLaunchBtn">
          <span class="mobile-fab-menu__icon" aria-hidden="true">MSG</span>
          <span>Messages</span>
        </button>

        <button type="button" class="mobile-fab-menu__item" data-fab-target="appLaunchBtn">
          <span class="mobile-fab-menu__icon" aria-hidden="true">APPS</span>
          <span>Apps</span>
        </button>
      </div>
    </div>
  </main>


  <!---------------------------------->
  <!------ Inventory Count User View ----->
  <!---------------------------------->
  <aside id="countDrawer" class="my-counts-drawer" aria-hidden="true" role="dialog"
    aria-label="My Inventory Count List">
    
    <div class="my-counts-drawer__panel">
      
      <header class="my-counts-drawer__hdr">
        <div>
          <div class="my-counts-drawer__eyebrow">USERS VIEW</div>
          <h2>Inventory Count List</h2>
          <div class="my-counts-drawer__meta">
            Worker: <strong><?= htmlspecialchars((string) ($_SESSION['username'] ?? 'User')) ?></strong>
            <span aria-hidden="true">•</span>
            <span id="countDrawerCounter">List: 0 items total</span>
          </div>
        </div>
        <button class="my-counts-drawer__close" id="countCloseBtn" type="button" aria-label="Close">&times;</button>
      </header>

      <section class="my-counts-drawer__body">
        <div id="countDrawerStatus" class="my-counts-drawer__status" aria-live="polite">Open the list to load assigned
          counts.</div>
        <div id="countDrawerGroups"></div>
      </section>

    </div>

    <button class="my-counts-drawer__backdrop" id="countBackdrop" type="button" aria-label="Close"></button>

  </aside>

  <style>
    .count-fab {
      position: fixed;
      right: 24px;
      bottom: 176px;
      z-index: 900;
      width: 52px;
      height: 52px;
      border: 0;
      border-radius: 50%;
      background: #0f8a73;
      color: #fff;
      font-size: 24px;
      font-weight: 800;
      box-shadow: 0 8px 24px rgba(15, 23, 42, .22);
      cursor: pointer;
    }

    .count-fab:hover {
      filter: brightness(.95);
    }

    .my-counts-drawer {
      position: fixed;
      inset: 0;
      z-index: 10050;
      pointer-events: none;
    }

    .my-counts-drawer[aria-hidden="true"] {
      display: none;
    }

    .my-counts-drawer[aria-hidden="false"] {
      pointer-events: auto;
    }

    .my-counts-drawer__backdrop {
      position: absolute;
      inset: 0;
      border: 0;
      background: rgba(15, 23, 42, .20);
      opacity: 0;
      transition: opacity .18s ease;
    }

    .my-counts-drawer[aria-hidden="false"] .my-counts-drawer__backdrop {
      opacity: 1;
    }

    .my-counts-drawer__panel {
      position: absolute;
      z-index: 1;
      right: 20px;
      bottom: 20px;
      width: min(360px, calc(100vw - 24px));
      height: min(600px, calc(100vh - 40px));
      display: flex;
      flex-direction: column;
      overflow: hidden;
      border: 1px solid #d7dee8;
      border-radius: 22px;
      background: #f8fafc;
      box-shadow: 0 24px 70px rgba(15, 23, 42, .30);
      transform: translateY(24px) scale(.98);
      opacity: 0;
      transition: transform .18s ease, opacity .18s ease;
    }

    .my-counts-drawer[aria-hidden="false"] .my-counts-drawer__panel {
      transform: translateY(0) scale(1);
      opacity: 1;
    }

    .my-counts-drawer__hdr {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      padding: 16px 16px 13px;
      background: #fff;
      border-bottom: 1px solid #e2e8f0;
    }

    .my-counts-drawer__eyebrow {
      color: #0f8a73;
      font-size: .72rem;
      font-weight: 900;
      letter-spacing: .09em;
    }

    .my-counts-drawer__hdr h2 {
      margin: 2px 0 3px;
      color: #0f172a;
      font-size: 1.1rem;
    }

    .my-counts-drawer__meta {
      display: flex;
      flex-wrap: wrap;
      gap: 5px;
      color: #64748b;
      font-size: .78rem;
    }

    .my-counts-drawer__close {
      flex: 0 0 36px;
      width: 36px;
      height: 36px;
      border: 1px solid #d7dee8;
      border-radius: 10px;
      background: #fff;
      color: #334155;
      font-size: 24px;
      line-height: 1;
      cursor: pointer;
    }

    .my-counts-drawer__body {
      flex: 1;
      overflow-y: auto;
      padding: 12px;
    }

    .my-counts-drawer__status {
      padding: 12px;
      color: #64748b;
      text-align: center;
      font-size: .88rem;
    }

    .count-group {
      margin-bottom: 14px;
    }

    .count-group__title {
      display: flex;
      justify-content: space-between;
      gap: 8px;
      margin: 2px 2px 8px;
      color: #334155;
      font-size: .78rem;
      font-weight: 800;
    }

    .count-user-card {
      display: grid;
      grid-template-columns: minmax(0, 1fr) 78px 42px;
      gap: 8px;
      align-items: end;
      margin-bottom: 8px;
      padding: 11px;
      border: 1px solid #dbe3ec;
      border-radius: 13px;
      background: #fff;
      transition: border-color .15s ease, background .15s ease;
    }

    .count-user-card.is-completed {
      border-color: #8fd2c4;
      background: #edf9f6;
    }

    .count-user-card__sku {
      color: #0f172a;
      font-weight: 900;
      font-size: .92rem;
    }

    .count-user-card__desc {
      margin-top: 2px;
      color: #475569;
      font-size: .80rem;
      line-height: 1.25;
    }

    .count-user-card__loc {
      margin-top: 5px;
      color: #64748b;
      font-size: .72rem;
      line-height: 1.25;
    }

    .count-user-card__count label {
      display: block;
      margin-bottom: 3px;
      color: #64748b;
      font-size: .68rem;
      font-weight: 800;
    }

    .count-user-card__count input {
      width: 100%;
      height: 38px;
      border: 1px solid #cbd5e1;
      border-radius: 9px;
      background: #fff;
      color: #0f172a;
      text-align: center;
      font-size: 1rem;
      font-weight: 800;
    }

    .count-user-card__done {
      width: 42px;
      height: 38px;
      border: 0;
      border-radius: 9px;
      background: #0f8a73;
      color: #fff;
      font-size: 20px;
      font-weight: 900;
      cursor: pointer;
    }

    .count-user-card__done:disabled {
      background: #94a3b8;
      cursor: default;
    }

    @media (max-width: 640px) {
      .count-fab {
        display: none;
      }

      .my-counts-drawer__panel {
        right: 8px;
        bottom: 8px;
        width: calc(100vw - 16px);
        height: calc(100vh - 16px);
      }
    }
  </style>

  <!---------------------------------->
  <!------ Application Button ----->
  <!---------------------------------->

  <aside id="appDrawer" class="app-drawer" aria-hidden="true" role="menu" aria-label="Application Menu">
    <div class="app-drawer__panel">
      <header class="app-drawer__hdr">
      </header>

      <section class="app-drawer__menu">
        <a href="<?= $BASE_URL ?>/../dashboard.php" class="app-drawer__item">
          <button><span>Main Menu</span></button>
        </a>
        <a href="<?= $BASE_URL ?>/dashboard.php" class="app-drawer__item">
          <button><span>Inventory</span></button>
        </a>
        <a href="<?= $BASE_URL ?>/../inspection/dashboard.php" class="app-drawer__item">
          <button><span>Inspection</span></button>
        </a>
      </section>
    </div>
    <button class="app-drawer__backdrop" id="appBackdrop" aria-label="Close"></button>
  </aside>



  <!---------------------------------->
  <!------ Sales Orders Button ----->
  <!---------------------------------->

  <aside id="ordersDrawer" class="orders-drawer" aria-hidden="true" role="dialog" aria-label="Sales Orders Feed">
    <div class="orders-drawer__panel">
      <header class="orders-drawer__hdr">
        <h2>Sales Orders Feed</h2>
        <button class="orders-drawer__close" id="ordersCloseBtn" aria-label="Close">&times;</button>
      </header>

      <section class="orders-drawer__body">
        <div class="orders-toolbar card">
          <div class="orders-toolbar__actions">
            <button type="button" class="btn btn-primary" id="ordersRefreshBtn">Refresh</button>
            <span class="muted" id="ordersStatus" aria-live="polite">Not loaded.</span>
          </div>
        </div>

        <div class="orders-table-wrap card" id="ordersTableWrap" hidden>
          <table id="ordersTable">
            <thead id="ordersHead"></thead>
            <tbody id="ordersBody"></tbody>
          </table>
        </div>

        <div class="orders-empty card muted" id="ordersEmpty">No rows yet.</div>
      </section>
    </div>
    <button class="orders-drawer__backdrop" id="ordersBackdrop" aria-label="Close"></button>
  </aside>


  <!---------------------------------->
  <!------ Calculator Button ----->
  <!---------------------------------->

  <aside id="calcDrawer" class="calc-drawer" aria-hidden="true" role="dialog" aria-label="Quick Calculator">
    <div class="calc-drawer__panel">
      <header class="calc-drawer__hdr">
        <h2>Quick Calculator</h2>
        <button class="calc-drawer__close" id="calcCloseBtn" aria-label="Close">&times;</button>
      </header>

      <section class="calc-drawer__body">
        <div class="calc-shell" aria-label="Calculator">
          <div id="calcDisplay" class="calc-display" role="status" aria-live="polite" tabindex="0">0</div>

          <div class="calc-grid" id="calcGrid">
            <button type="button" class="calc-btn is-utility" data-action="clear">C/E</button>
            <button type="button" class="calc-btn is-utility" data-action="backspace"
              aria-label="Backspace">Bksp</button>
            <button type="button" class="calc-btn is-utility" data-action="sign">+/-</button>
            <button type="button" class="calc-btn is-operator" data-action="operator" data-operator="divide">/</button>

            <button type="button" class="calc-btn" data-action="digit" data-value="7">7</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="8">8</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="9">9</button>
            <button type="button" class="calc-btn is-operator" data-action="operator"
              data-operator="multiply">*</button>

            <button type="button" class="calc-btn" data-action="digit" data-value="4">4</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="5">5</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="6">6</button>
            <button type="button" class="calc-btn is-operator" data-action="operator" data-operator="minus">-</button>

            <button type="button" class="calc-btn" data-action="digit" data-value="1">1</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="2">2</button>
            <button type="button" class="calc-btn" data-action="digit" data-value="3">3</button>
            <button type="button" class="calc-btn is-operator" data-action="operator" data-operator="plus">+</button>

            <button type="button" class="calc-btn span-2" data-action="digit" data-value="0">0</button>
            <button type="button" class="calc-btn" data-action="dot">.</button>
            <button type="button" class="calc-btn is-equals" data-action="equals">=</button>

            <button type="button" class="calc-btn is-utility" data-action="unary" data-unary="reciprocal">1/x</button>
            <button type="button" class="calc-btn is-utility" data-action="unary" data-unary="sqrt">sqrt</button>
            <button type="button" class="calc-btn is-utility" data-action="unary" data-unary="square">x^2</button>
            <button type="button" class="calc-btn is-operator" data-action="operator" data-operator="power">y^x</button>
          </div>
        </div>
      </section>
    </div>
    <button class="calc-drawer__backdrop" id="calcBackdrop" aria-label="Close"></button>
  </aside>

  <!---------------------------------->
  <!------ Scan Button ----->
  <!---------------------------------->

  <aside id="scanDrawer" class="scan-drawer" aria-hidden="true" role="dialog" aria-label="Scan & Locate SKU">
    <div class="scan-drawer__panel">
      <header class="scan-drawer__hdr">
        <h2>Seach &amp; Locate SKU</h2>
        <div class="scan-drawer__hdr-actions" style="display: flex; align-items: center; gap: 8px;">
          <!-- <button type="button" class="btn tertiary" id="scanCamToggleBtn" aria-expanded="true">
            Minimize Camera
          </button> -->
          <button type="button" class="scan-drawer__close" id="scanCloseBtn" aria-label="Close">&times;</button>
        </div>
      </header>

      <section class="scan-drawer__cam">
        <div class="scan-box" id="scanCameraBox">
          <!--<video id="scanVideo" autoplay playsinline muted></video>-->
          <!-- <div class="overlay">
            <div class="bracket" id="scanBracket"></div>
          </div> -->
        </div>
        <div class="scan-controls">
          <!-- <button type="button" class="btn" id="scanStartBtn">Start Camera</button>
          <button type="button" class="btn secondary" id="scanStopBtn" disabled>Stop</button> -->

          <input id="scanManual" type="text" placeholder="Or type/paste a code..." inputmode="text" />

          <button type="button" class="btn" id="scanLookupBtn">Lookup</button>
          <button type="button" class="btn tertiary" id="scanResetBtn">Reset</button>
        </div>
        <div id="scanSearchList" class="card" hidden></div>

      </section>

      <section class="scan-drawer__results" id="scanResults" hidden>
        <!-- <div class="scan-actions" style="display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 10px;">
          <button type="button" class="btn tertiary" id="scanCopyBtn" disabled>Copy Results</button>
        </div> -->

        <div class="card">
          <div class="product-image-container">
            <img id="scanProductImage" src="" alt="Product Image"
              style="max-width: 100%; height: auto; display: none;" />
          </div>
          <div class="sku-grid scan-sku-grid" id="scanSkuGrid"></div>
          <button type="button" class="addUpcBtn">UPC</button>
        </div>

        <div class="card">
          <h3 class="h6 mb-2" id="scanPalletHeading" hidden>Location Inventory</h3>
          <!-- <button>Add UPC</button> -->
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
        <div class="message-drawer__tabs">
          <button type="button" id="messageOpenTab" class="message-drawer__tab is-active">
            Open
          </button>

          <button type="button" id="messageReadTab" class="message-drawer__tab">
            Read
          </button>

        </div>

        <div id="messageStatus" class="message-drawer__status" aria-live="polite">
          Loading messages...
        </div>
        <div id="messageList"></div>
      </section>

    </div>

    <button id="messageBackdrop" class="message-drawer__backdrop" type="button" aria-label="Close"></button>
  </aside>



  <script nonce="<?= $cspNonceAttr ?>">
    (function () {
      const drawer = document.getElementById('countDrawer');
      const launch = document.getElementById('countLaunchBtn');
      const closeBtn = document.getElementById('countCloseBtn');
      const backdrop = document.getElementById('countBackdrop');
      const groupsEl = document.getElementById('countDrawerGroups');
      const statusEl = document.getElementById('countDrawerStatus');
      const counterEl = document.getElementById('countDrawerCounter');

      if (!drawer || !launch || !groupsEl || !statusEl || !counterEl) return;

      const endpoint = <?= json_encode(rtrim($BASE_URL, '/') . '/../utils/count_api.php') ?>;
      const csrfToken = <?= json_encode($csrfToken) ?>;
      let loaded = false;

      function setOpen(open) {
        drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        launch.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
          loadCounts();
        }
      }

      function escapeHtml(value) {
        return String(value ?? '')
          .replaceAll('&', '&amp;')
          .replaceAll('<', '&lt;')
          .replaceAll('>', '&gt;')
          .replaceAll('"', '&quot;')
          .replaceAll("'", '&#039;');
      }

      function render(payload) {
        const groups = Array.isArray(payload.groups) ? payload.groups : [];
        const total = groups.reduce((sum, group) => sum + (Array.isArray(group.items) ? group.items.length : 0), 0);
        counterEl.textContent = `List: ${total} item${total === 1 ? '' : 's'} total`;

        if (!groups.length) {
          statusEl.hidden = false;
          statusEl.textContent = 'No count blocks are currently assigned to you.';
          groupsEl.innerHTML = '';
          return;
        }

        statusEl.hidden = true;
        groupsEl.innerHTML = groups.map(group => {
          const items = Array.isArray(group.items) ? group.items : [];
          const completed = items.filter(item => item.status === 'COMPLETED').length;

          return `
            <section class="count-group" data-group-id="${Number(group.id)}">
              <div class="count-group__title">
                <span>${escapeHtml(group.group_name || `Count block #${group.id}`)}</span>
                <span>${completed}/${items.length} completed</span>
              </div>
              ${items.map(item => `
                <article class="count-user-card ${item.status === 'COMPLETED' ? 'is-completed' : ''}" data-item-id="${Number(item.id)}">
                  <div>
                    <div class="count-user-card__sku">${escapeHtml(item.sku_num)}</div>
                    <div class="count-user-card__desc">${escapeHtml(item.description)}</div>
                    <div class="count-user-card__loc">${escapeHtml(item.locations || 'No stocked location')}</div>
                  </div>
                  <div class="count-user-card__count">
                    <label>Count:</label>
                    <input
                      type="number"
                      min="0"
                      step="1"
                      inputmode="numeric"
                      value="${item.observed_total === null ? '' : Number(item.observed_total)}"
                      ${item.status === 'COMPLETED' ? 'disabled' : ''}
                      aria-label="Observed count for ${escapeHtml(item.sku_num)}"
                    >
                  </div>
                  <button
                    type="button"
                    class="count-user-card__done"
                    title="${item.status === 'COMPLETED' ? 'Completed' : 'Complete count'}"
                    ${item.status === 'COMPLETED' ? 'disabled' : ''}
                  >✓</button>
                </article>
              `).join('')}
            </section>
          `;
        }).join('');
      }

      async function readCountResponse(response) {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
          throw new Error('Unable to reach the count service. Reload the page and try again.');
        }
        return response.json();
      }

      async function loadCounts(force = false) {
        if (loaded && !force) return;
        statusEl.hidden = false;
        statusEl.textContent = 'Loading assigned counts...';

        try {
          const response = await fetch(endpoint, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
          });
          const payload = await readCountResponse(response);
          if (!response.ok || payload.ok === false) {
            throw new Error(payload.error || 'Unable to load count list.');
          }
          loaded = true;
          render(payload);
        } catch (error) {
          statusEl.hidden = false;
          statusEl.textContent = error && error.message ? error.message : 'Unable to load count list.';
        }
      }

      async function completeItem(card) {
        const input = card.querySelector('input[type="number"]');
        const button = card.querySelector('.count-user-card__done');
        const raw = input ? input.value.trim() : '';

        if (!/^\d+$/.test(raw)) {
          input && input.focus();
          return;
        }

        button.disabled = true;

        const body = new URLSearchParams();
        body.set('action', 'complete_item');
        body.set('item_id', card.dataset.itemId || '');
        body.set('observed_total', raw);
        body.set('csrf_token', csrfToken);

        try {
          const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
          });

          const payload = await readCountResponse(response);
          if (!response.ok || payload.ok === false) {
            throw new Error(payload.error || 'Unable to save count.');
          }

          loaded = false;
          await loadCounts(true);
        } catch (error) {
          button.disabled = false;
          window.alert(error && error.message ? error.message : 'Unable to save count.');
        }
      }

      launch.addEventListener('click', () => setOpen(drawer.getAttribute('aria-hidden') === 'true'));
      closeBtn && closeBtn.addEventListener('click', () => setOpen(false));
      backdrop && backdrop.addEventListener('click', () => setOpen(false));

      groupsEl.addEventListener('click', (event) => {
        const button = event.target.closest('.count-user-card__done');
        if (!button || button.disabled) return;
        const card = button.closest('.count-user-card');
        if (card) completeItem(card);
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && drawer.getAttribute('aria-hidden') === 'false') {
          setOpen(false);
        }
      });

      document.addEventListener('inventory-counts:refresh', () => {
        loaded = false;
        if (drawer.getAttribute('aria-hidden') === 'false') {
          loadCounts(true);
        }
      });
    })();
  </script>

  <?php if (empty($disable_global_js)): ?>
    <script src="<?= asset_url('/js/script.js', $ROOT_ASSET_BASE) ?>"></script>
  <?php endif; ?>

  <?php foreach ($js as $src): ?>
    <script src="<?= asset_url($src, $ROOT_ASSET_BASE) ?>"></script>
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

  <script nonce="<?= $cspNonceAttr ?>">
    (function () {
      const badge = document.querySelector('[data-session-badge]');
      const timer = document.getElementById('sessionCountdown');
      const session = window.InvApp && window.InvApp.session ? window.InvApp.session : null;

      if (!badge || !timer || !session) {
        return;
      }

      session.clientSyncedAt = Date.now();

      function formatRemaining(totalSeconds) {
        const safeSeconds = Math.max(0, Math.floor(totalSeconds));
        const minutes = Math.floor(safeSeconds / 60);
        const seconds = safeSeconds % 60;
        return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
      }

      badge.addEventListener('click', function () {
        const isCollapsed = badge.classList.toggle('is-collapsed');
        badge.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        badge.title = isCollapsed ? 'Expand session status' : 'Collapse session status';
      });

      function updateCountdown() {
        const elapsedSinceSync = (Date.now() - session.clientSyncedAt) / 1000;
        const estimatedServerNow = session.serverNow + elapsedSinceSync;
        const remaining = session.expiresAt - estimatedServerNow;

        timer.textContent = formatRemaining(remaining);
        badge.dataset.sessionState = remaining <= 60 ? 'warning' : 'normal';
        badge.classList.toggle('is-expiring', remaining <= 60);

        if (remaining <= 0) {
          window.location.href = session.loginUrl || '../login.php';
        }
      }

      window.InvApp.refreshSessionCountdown = function () {
        console.log('[timer] Refreshing countdown with:', {
          expiresAt: window.InvApp.session.expiresAt,
          serverNow: window.InvApp.session.serverNow,
          localSessionExpiresAt: session?.expiresAt,
          localSessionServerNow: session?.serverNow
        });
        session.clientSyncedAt = Date.now();
        updateCountdown();
      };

      updateCountdown();
      window.setInterval(updateCountdown, 1000);
    })();
  </script>

  <script nonce="<?= $cspNonceAttr ?>">
    (function () {
      const session = window.InvApp && window.InvApp.session ? window.InvApp.session : null;

      if (!session || !session.keepaliveUrl) {
        return;
      }

      const keepaliveIntervalMs = 60000;
      const activityWindowMs = 90000;
      const heartbeatIntervalMs = 30000;

      let lastUserActivity = Date.now();
      let lastPingAt = 0;
      let pingInFlight = false;

      function applySessionPayload(payload) {
        if (!payload || payload.ok === false) {
          return false;
        }

        if (typeof payload.lastActivity === 'number') {
          window.InvApp.session.lastActivity = payload.lastActivity;
        }

        if (typeof payload.serverNow === 'number') {
          window.InvApp.session.serverNow = payload.serverNow;
        }

        if (typeof payload.expiresAt === 'number') {
          window.InvApp.session.expiresAt = payload.expiresAt;
        }

        window.InvApp.session.clientSyncedAt = Date.now();

        if (typeof window.InvApp.refreshSessionCountdown === 'function') {
          window.InvApp.refreshSessionCountdown();
        }

        return true;
      }

      async function pingSession(force = false) {
        const now = Date.now();

        if (pingInFlight) {
          return false;
        }

        if (!force && (now - lastPingAt) < keepaliveIntervalMs) {
          return false;
        }

        pingInFlight = true;

        try {
          const response = await fetch(session.keepaliveUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ reason: 'user-interaction' })
          });

          if (!response.ok) {
            console.warn('[session] Keepalive failed with status', response.status);
            return false;
          }

          const payload = await response.json().catch(() => null);

          if (!applySessionPayload(payload)) {
            console.warn('[session] Keepalive returned an invalid payload.');
            return false;
          }

          lastPingAt = Date.now();
          return true;
        } catch (error) {
          console.error('[session] Keepalive request failed.', error);
          return false;
        } finally {
          pingInFlight = false;
        }
      }

      function markActivity() {
        lastUserActivity = Date.now();
      }

      [
        'pointerdown',
        'keydown',
        'input',
        'change',
        'touchstart',
        'wheel',
        'focusin'
      ].forEach(function (eventName) {
        document.addEventListener(eventName, markActivity, {
          capture: true,
          passive: true
        });
      });

      document.addEventListener('invapp:scan-sku', markActivity, {
        capture: true
      });

      window.setInterval(function () {
        if (document.visibilityState !== 'visible') {
          return;
        }

        const recentlyActive = (Date.now() - lastUserActivity) <= activityWindowMs;

        if (recentlyActive) {
          void pingSession();
        }
      }, heartbeatIntervalMs);

      document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
          markActivity();
          void pingSession();
        }
      });

      window.InvApp.touchSession = function () {
        markActivity();
        return pingSession(true);
      };
    })();
  </script>

  <script>
    (function () {
      const scanStartBtn = document.getElementById('scanStartBtn');
      const scanStopBtn = document.getElementById('scanStopBtn');
      const scanCameraBox = document.getElementById('scanCameraBox');
      const scanDrawer = document.getElementById('scanDrawer');
      const scanCloseBtn = document.getElementById('scanCloseBtn');
      const scanBackdrop = document.getElementById('scanBackdrop');
      const scanLaunchBtn = document.getElementById('scanLaunchBtn');

      if (scanStartBtn && scanCameraBox) {
        scanStartBtn.addEventListener('click', function () {
          scanCameraBox.hidden = false;
        });
      }

      if (scanStopBtn && scanCameraBox) {
        scanStopBtn.addEventListener('click', function () {
          scanCameraBox.hidden = true;
        });
      }

      function closeScanDrawer() {
        if (!scanDrawer) {
          return;
        }

        scanDrawer.hidden = true;
        scanDrawer.dataset.open = 'false';
        scanDrawer.setAttribute('aria-hidden', 'true');
        if (scanLaunchBtn) {
          scanLaunchBtn.setAttribute('aria-expanded', 'false');
        }
        document.body.dataset.scanOpen = 'false';
      }

      if (scanCloseBtn) {
        scanCloseBtn.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          closeScanDrawer();
        }, true);
      }

      if (scanBackdrop) {
        scanBackdrop.addEventListener('click', function () {
          closeScanDrawer();
        }, true);
      }
    })();
  </script>

  <script src="<?= asset_url('/../js/sku_search.js', $ROOT_ASSET_BASE) ?>"></script>
  <script src="<?= asset_url('/js/scroll.js', $ROOT_ASSET_BASE) ?>"></script>
  <?php $messageCenterBase = preg_replace('~/inventory$~', '', $INVENTORY_ASSET_BASE); ?>
  <script nonce="<?= $cspNonceAttr ?>">window.MESSAGE_CENTER_ENDPOINT = <?= json_encode($messageCenterBase . '/utils/message_center.php') ?>;</script>
  <script defer src="<?= asset_url('/js/message-center.js', $messageCenterBase) ?>"></script>
  <script src="<?= asset_url('/js/mobile-menu.js', $ROOT_ASSET_BASE) ?>"></script>
  <script src="<?= asset_url('/js/mobile-fab-menu.js', $INVENTORY_ASSET_BASE) ?>"></script>
  <script src="<?= asset_url('/js/jsbarcode.js', $INVENTORY_ASSET_BASE) ?>"></script>
  <script src="<?= asset_url('/js/scan-drawer.js', $ROOT_ASSET_BASE) ?>"></script>
  <script src="<?= asset_url('/js/calc-drawer.js', $ROOT_ASSET_BASE) ?>"></script>
  <script src="<?= asset_url('/js/table-filter.js', $INVENTORY_ASSET_BASE) ?>"></script>
  <script src="<?= asset_url('/js/suggest.js', $INVENTORY_ASSET_BASE) ?>"></script>

  <noscript>
    <div class="toast is-info">Some features work best with JavaScript enabled.</div>
  </noscript>
  <?php include __DIR__ . '/../../templates/punch_clock.php'; // punch clock FAB + PIN modal (every page) ?>
</body>

</html>
