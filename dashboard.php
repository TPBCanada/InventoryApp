<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/security.php';
include 'dbinv.php';
require_once __DIR__ . '/templates/access_control.php';

if (!isset($_SESSION['username'])) {
  header("Location: login.php");
  exit;
}

$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'];
$role_id = $_SESSION['role_id'] ?? 0;
$firstName = $_SESSION['firstName'] ?? $_SESSION['firstname'] ?? $username ?? 'Admin';

$title = 'Dashboard';
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$css = [
  '/refactor_css/dashboard.css',
  '/refactor_css/calendar.css?v=' . @filemtime(__DIR__ . '/refactor_css/calendar.css'),
];
$page_class = "page-welcome";

$modules = [
  [
    'key' => 'inventory/dashboard.php',
    'title' => 'Inventory',
    'desc' => 'Receive, move, and manage stock and locations.',
    'footer' => 'Inventory actions',
    'href' => "{$BASE_URL}/inventory/dashboard.php",
    'accent' => '#0f7a42',
    'accent_bg' => '#e8f6ed',
    'icon' => '&#128230;' // boxes
  ],
  [
    'key' => 'inspection/dashboard.php',
    'title' => 'Inspection',
    'desc' => 'Run warehouse, lift truck, and safety inspections.',
    'footer' => 'Inspection hub',
    'href' => "{$BASE_URL}/inspection/dashboard.php",
    'accent' => '#c91f1f',
    'accent_bg' => '#fdeaea',
    'icon' => '&#9937;' // helmet
  ],
  [
    'key' => 'supplies/dashboard.php',
    'title' => 'Supplies',
    'desc' => 'Track and reorder warehouse and office supplies.',
    'footer' => 'Supplies & requests',
    'href' => "{$BASE_URL}/supplies/dashboard.php",
    'accent' => '#ee9f00',
    'accent_bg' => '#fff5e6',
    'icon' => '&#128221;' // clipboard
  ],
];

// Hide modules the current role cannot access
$modules = array_values(array_filter($modules, function ($mod) use ($role_id, $page_permissions_by_role) {
  // Only show if a rule exists for this module key and the role is allowed.
  if (!array_key_exists($mod['key'], $page_permissions_by_role)) {
    return false;
  }
  $allowed = $page_permissions_by_role[$mod['key']];
  return is_array($allowed) && in_array($role_id, $allowed, true);
}));

ob_start();
?>

<section class="dash-hero">
<h1 style="font-size: 5rem; font-weight: 800; text-transform: uppercase; letter-spacing: 2px; color: #e01414; margin: 2rem 0; font-family: system-ui, sans-serif;">
  DEV Portal
</h1>
  <h1>Welcome, <?= htmlspecialchars($firstName) ?></h1>
  <p>Choose a module to get started. You can jump into inventory, inspections, picking, or pull quick reports.</p>

  <div class="module-grid">
    <?php foreach ($modules as $mod): ?>
      <a class="module-card" href="<?= htmlspecialchars($mod['href']) ?>"
        style="--accent: <?= htmlspecialchars($mod['accent']) ?>; --accent-bg: <?= htmlspecialchars($mod['accent_bg']) ?>;">
        <div class="module-card__icon" aria-hidden="true"><?= $mod['icon'] ?></div>
        <div class="module-card__title"><?= htmlspecialchars($mod['title']) ?></div>
        <p class="module-card__desc"><?= htmlspecialchars($mod['desc']) ?></p>
        <div class="module-card__footer">
          <span><?= htmlspecialchars($mod['footer']) ?></span>
          <span class="module-card__arrow">&rarr;</span>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<div id="mgmtQueue" class="mgmt-queue"></div>

<!-- <section class="card card--wide dash-calendar">
  <h2>Calendar</h2>
  <div id="dashboardCalendar" class="calendar-block" aria-live="polite"></div>
</section> -->

<section class="card card--wide dash-calendar">
  <h2>Calendar</h2>
  <div id="dashboardCalendar" class="cal">
    <div class="cal__body">
      <aside class="cal__aside">
        <div class="cal__panel">
          <h4>Time Off Requests</h4>

          <label>Request type
            <select id="calRequestType">
              <option value="timeoff">Time Off Request</option>
              <option value="message">Message</option>
              <option value="holiday">Holiday</option>
            </select>
          </label>

          <label>Repeat
            <select id="calRecurring">
              <option value="none">Do not repeat</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
              <option value="yearly">Yearly</option>
            </select>
          </label>

          <label>Start date
            <input id="calStart" type="date">
          </label>

          <label>End date
            <input id="calEnd" type="date">
          </label>

          <label>Note
            <textarea id="calNote" placeholder="Optional note for your manager"></textarea>
          </label>

          <label>Send email to
            <select id="calEmailSelect"></select>
          </label>

          <label id="calCustomEmailWrap" style="display:none;">
            Custom email
            <input id="calCustomEmail" type="email" placeholder="Enter a custom email address">
          </label>

          <button id="calSubmit" type="button">Submit</button>
        </div>

        <div class="cal__panel">
          <h4>Legend</h4>
          <div class="legend-item">
            <div class="legend-swatch swatch-pending"></div>
            <span>Pending Request</span>
          </div>
          <div class="legend-item">
            <div class="legend-swatch swatch-approved"></div>
            <span>Approved Time Off</span>
          </div>
          <div class="legend-item">
            <div class="legend-swatch swatch-bday"></div>
            <span>Birthday</span>
          </div>
          <div class="legend-item">
            <div class="legend-swatch swatch-holiday"></div>
            <span>Stat Holiday</span>
          </div>
          <div class="legend-item">
            <div class="legend-swatch swatch-note"></div>
            <span>Message Info</span>
          </div>
        </div>
      </aside>

      <main class="cal__main">
        <div class="cal__header">
          <button id="calPrev" type="button">←</button>
          <div id="calLabel"></div>
          <button id="calNext" type="button">→</button>
        </div>

        <div id="calGrid" class="cal__grid"></div>

        <section class="cal__message-center">
          <details id="calMsgCenterToggle" class="cal__msg-toggle" open>
            <summary>
              <h4>Message Center</h4>
              <span class="cal__msg-toggle-btn" aria-hidden="true"></span>
            </summary>

            <div class="cal__msg-body">
              <div class="cal__msg-section">
                <h5>Notes</h5>
                <div id="calNotesList"></div>
              </div>

              <div class="cal__msg-section">
                <h5>Important Dates</h5>
                <div id="calDatesList"></div>
              </div>

              <div id="calMgrTools" style="display:none;">
                <button id="calAddDateToggle" type="button">Add / Import Dates</button>
                <div id="calAddDatePanel" style="display:none;"></div>
              </div>
            </div>
          </details>
        </section>
      </main>
    </div>
  </div>
</section>

<button id="calApprovalTrigger" class="cal-approval-trigger" type="button" aria-expanded="false" aria-controls="calApprovalDrawer">
  <span class="cal-approval-trigger__icon" aria-hidden="true">📥</span>
  <span class="cal-approval-trigger__text">No pending requests</span>
  <span class="cal-approval-trigger__badge" aria-label="Pending request count">0</span>
  <span class="cal-approval-trigger__chevron" aria-hidden="true">⌃</span>
</button>

<div id="calDayModal" class="cal-modal" aria-hidden="true">
  <div class="cal-modal__backdrop" data-cal-day-modal-close></div>
  <div class="cal-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="calDayModalTitle">
    <div class="cal-modal__header">
      <div>
        <h3 id="calDayModalTitle">Day Details</h3>
        <p id="calDayModalDate"></p>
      </div>
      <button id="calDayModalClose" type="button" class="cal-modal__close" aria-label="Close day details">&times;</button>
    </div>

    <div id="calDayModalBody" class="cal-modal__body"></div>

    <div class="cal-modal__footer">
      <button id="calDayModalUseDate" type="button" class="cal-modal__primary">Use this date</button>
    </div>
  </div>
</div>

<div id="calApprovalDrawer" class="cal-drawer" aria-hidden="true">
  <div class="cal-drawer__backdrop" data-cal-approval-drawer-close></div>
  <aside class="cal-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="calApprovalDrawerTitle">
    <div class="cal-drawer__handle" aria-hidden="true"></div>
    <div class="cal-drawer__header">
      <div>
        <h3 id="calApprovalDrawerTitle">Pending Approvals Dashboard</h3>
        <p id="calApprovalDrawerDate"></p>
      </div>
      <button id="calApprovalDrawerClose" type="button" class="cal-drawer__close" aria-label="Close approval drawer">&times;</button>
    </div>

    <div id="calApprovalDrawerBody" class="cal-drawer__body"></div>
  </aside>
</div>

<div id="calCancelConfirmModal" class="cal-confirm-modal" aria-hidden="true">
  <div class="cal-confirm-modal__backdrop" data-cal-cancel-confirm-close></div>
  <div class="cal-confirm-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="calCancelConfirmTitle">
    <div class="cal-confirm-modal__header">
      <div>
        <h3 id="calCancelConfirmTitle">Cancel approved time off?</h3>
        <p id="calCancelConfirmText"></p>
      </div>
      <button id="calCancelConfirmClose" type="button" class="cal-confirm-modal__close" aria-label="Close cancel confirmation">&times;</button>
    </div>

    <div class="cal-confirm-modal__body">
      <p>This action changes the request state and keeps an audit trail in the calendar until the view refreshes.</p>
    </div>

    <div class="cal-confirm-modal__footer">
      <button id="calCancelKeepBtn" type="button" class="cal-confirm-modal__secondary">Keep Request</button>
      <button id="calCancelYesBtn" type="button" class="cal-confirm-modal__danger">Yes, Cancel</button>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
$calendarConfig = [
  'baseUrl' => $BASE_URL,
  'birthdaysEndpoint' => $BASE_URL . '/utils/get_birthdays.php',
  // 'datesEndpoint' => $BASE_URL . '/utils/calendar_dates.php',
  'notesEndpoint' => $BASE_URL . '/utils/calendar_notes.php',
  'adminEmail' => $_SESSION['adminEmail'] ?? '',
  'defaultEmail' => 'ray@turningpointbrands.ca',
  'emailEndpoint' => $BASE_URL . '/utils/timeoff_email.php',
  'managerEndpoint' => $BASE_URL . '/utils/timeoff_managers.php',
  'requestsEndpoint' => $BASE_URL . '/utils/timeoff_requests.php',
  'emailOptions' => [
    ['value' => 'jen@turningpointbrands.ca', 'label' => 'jen@turningpointbrands.ca'],
    ['value' => 'sean@turningpointbrands.ca', 'label' => 'sean@turningpointbrands.ca'],
    ['value' => 'kavita@turningpointbrands.ca', 'label' => 'kavita@turningpointbrands.ca'],
  ],
  'currentUser' => [
    'id' => (int) ($_SESSION['user_id'] ?? 0),
    'username' => $_SESSION['username'] ?? '',
    'name' => trim(($firstName ?? '') . ' ' . ($_SESSION['lastName'] ?? $_SESSION['lastname'] ?? '')),
    'roleId' => (int) ($role_id ?? 0)
  ]
];
$footer_js = '<script nonce="' . $cspNonceAttr . '">window.dashboardCalendarConfig = ' . json_encode($calendarConfig) . ';</script>';
$footer_js .= '<script defer src="' . $BASE_URL . '/js/dashboard-calendar.js?v=' . @filemtime(__DIR__ . '/js/dashboard-calendar.js') . '"></script>';
$footer_js .= '<script nonce="' . $cspNonceAttr . '">(function(){var d=document.getElementById("calMsgCenterToggle");if(!d)return;var k="dashMsgCenterOpen";try{if(localStorage.getItem(k)==="0")d.open=false;}catch(e){}d.addEventListener("toggle",function(){try{localStorage.setItem(k,d.open?"1":"0");}catch(e){}});})();</script>';

include __DIR__ . '/templates/layout.php';
