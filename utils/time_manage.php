<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
include __DIR__ . '/../dbinv.php';
require_once __DIR__ . '/../templates/access_control.php';

if (!isset($_SESSION['username'])) { header('Location: ../login.php'); exit; }

$role_id = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($role_id, $page_permissions_by_role['timeclock/manage.php'] ?? [], true)) {
  header('Location: ../dashboard.php'); exit;
}

$title = 'Time Records';
$BASE_URL = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$css = [
  '/refactor_css/calendar.css?v=' . @filemtime(__DIR__ . '/../refactor_css/calendar.css'), // reuses cal-modal
];
// timeclock-manage.css/js are not built yet; only load them once they exist.
if (is_file(__DIR__ . '/../refactor_css/timeclock-manage.css')) {
  $css[] = '/refactor_css/timeclock-manage.css?v=' . filemtime(__DIR__ . '/../refactor_css/timeclock-manage.css');
}
$page_class = 'page-timeclock';

ob_start();
?>
<section class="card card--wide tc">
  <div class="tc__head">
    <div>
      <h2>Time records</h2>
      <p class="tc__sub">Review, correct, and export employee punches. Every change is logged with a reason.</p>
    </div>
    <div class="tc__head-actions">
      <button id="tcExport" type="button" class="btn">Export CSV</button>
      <button id="tcAdd" type="button" class="btn btn--primary">Add punch</button>
    </div>
  </div>

  <form id="tcFilters" class="tc__filters" onsubmit="return false;">
    <label>From <input id="tcFrom" type="date"></label>
    <label>To <input id="tcTo" type="date"></label>
    <label>Employee <select id="tcUser"><option value="">All employees</option></select></label>
    <label class="tc__check"><input id="tcOpen" type="checkbox"> Still punched in</label>
    <div class="tc__presets">
      <button type="button" data-range="today">Today</button>
      <button type="button" data-range="week">This week</button>
      <button type="button" data-range="lastweek">Last week</button>
    </div>
  </form>

  <div id="tcSummary" class="tc__summary" aria-live="polite"></div>

  <div class="tc__table-wrap">
    <table class="tc__table">
      <thead>
        <tr>
          <th scope="col">Employee</th>
          <th scope="col">Date</th>
          <th scope="col">In</th>
          <th scope="col">Out</th>
          <th scope="col" class="num">Hours</th>
          <th scope="col">Source</th>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody id="tcBody"></tbody>
    </table>
  </div>
</section>

<div id="tcModal" class="cal-modal tc-modal" aria-hidden="true">
  <div class="cal-modal__backdrop" data-tc-close></div>
  <div class="cal-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="tcModalTitle">
    <div class="cal-modal__header">
      <div><h3 id="tcModalTitle">Edit punch</h3><p id="tcModalSub"></p></div>
      <button type="button" class="cal-modal__close" data-tc-close aria-label="Close">&times;</button>
    </div>
    <div class="cal-modal__body tc-modal__body">
      <label id="tcEmpWrap">Employee <select id="tcEmp"></select></label>
      <label>Punch in <input id="tcIn" type="datetime-local" required></label>
      <label>Punch out <input id="tcOutAt" type="datetime-local"><small>Leave empty if still on shift.</small></label>
      <label>Reason for change <textarea id="tcReason" maxlength="255" placeholder="e.g. Forgot to punch out, confirmed with supervisor" required></textarea></label>
      <p id="tcErr" class="tc-modal__err" role="alert"></p>
    </div>
    <div class="cal-modal__footer tc-modal__footer">
      <button id="tcVoid" type="button" class="btn-danger">Void punch</button>
      <span class="tc-modal__spacer"></span>
      <button type="button" class="btn" data-tc-close>Cancel</button>
      <button id="tcSave" type="button" class="btn btn--primary">Save changes</button>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
$footer_js = '<script nonce="' . $cspNonceAttr . '">window.timeclockAdminConfig = ' . json_encode([
  'endpoint'  => $BASE_URL . '/utils/timeclock_admin.php',
  'csrfToken' => '', // fill from lib/security.php helper
]) . ';</script>';
if (is_file(__DIR__ . '/../js/timeclock-manage.js')) {
  $footer_js .= '<script defer src="' . $BASE_URL . '/js/timeclock-manage.js?v=' . filemtime(__DIR__ . '/../js/timeclock-manage.js') . '"></script>';
}

include __DIR__ . '/../templates/layout.php';