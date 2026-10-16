<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

if (!isset($_SESSION['username'])) { header('Location: ../login.php'); exit; }

require_once __DIR__ . '/../templates/access_control.php';

$is_manager = in_array((int) ($_SESSION['role_id'] ?? 0), [1, 2], true);

$title = 'Timesheets';
$BASE_URL = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$ROOT_ASSET_BASE = $BASE_URL;
$css = ['/refactor_css/timesheet.css'];
$js = ['/js/timesheet.js'];
$page_class = 'page-timesheet';

ob_start();
?>
<section class="card card--wide ts">
  <div class="ts__head">
    <div class="ts__head-main">
      <h2 class="ts__title">Timesheets</h2>

      <div class="ts__toolbar">
        <div class="ts__range" role="group" aria-label="Date range">
          <button type="button" class="ts__range-step" data-ts-step="-1" aria-label="Previous period">&lsaquo;</button>
          <svg class="ts__range-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7Zm-2 8h14v10H5V10Z"/></svg>
          <input id="tsFrom" type="date" aria-label="From">
          <span aria-hidden="true">&ndash;</span>
          <input id="tsTo" type="date" aria-label="To">
          <button type="button" class="ts__range-step" data-ts-step="1" aria-label="Next period">&rsaquo;</button>
        </div>

        <details class="ts__menu" id="tsFilters">
          <summary>Filters <span class="ts__caret" aria-hidden="true"></span></summary>
          <div class="ts__menu-panel">
            <?php if ($is_manager): ?>
              <label>Employee
                <select id="tsEmployee"><option value="">All employees</option></select>
              </label>
            <?php endif; ?>
            <label class="ts__check"><input id="tsIssuesOnly" type="checkbox"> Only time cards with issues</label>
          </div>
        </details>

        <details class="ts__menu" id="tsView">
          <summary>View <span class="ts__caret" aria-hidden="true"></span></summary>
          <div class="ts__menu-panel">
            <button type="button" data-ts-view="expand">Expand all</button>
            <button type="button" data-ts-view="collapse">Collapse all</button>
            <label class="ts__check"><input id="tsHideEmpty" type="checkbox"> Hide employees with no time cards</label>
          </div>
        </details>
      </div>

      <button type="button" id="tsReview" class="ts__review" aria-pressed="false">
        <span class="ts__caret" aria-hidden="true"></span>
        Needs review <span id="tsReviewCount" class="ts__count">0</span>
      </button>
    </div>
  </div>

  <p id="tsStatus" class="ts__status" role="status" aria-live="polite">Loading&hellip;</p>

  <div class="ts__table-wrap">
    <table class="ts__table">
      <thead>
        <tr>
          <th scope="col">Date</th>
          <th scope="col">Role</th>
          <th scope="col">Time card</th>
          <th scope="col">Issues</th>
          <th scope="col" class="num">Scheduled<br>hours</th>
          <th scope="col" class="num">Actual vs.<br>scheduled</th>
          <th scope="col" class="num">Total paid<br>hours</th>
          <th scope="col" class="num">Regular<br>hours</th>
          <th scope="col" class="num">Meal<br>breaks</th>
          <th scope="col" class="num">OT<br>hours</th>
        </tr>
      </thead>
      <!-- one <tbody class="ts-group"> per employee, rendered by js/timesheet.js -->
    </table>
  </div>
</section>

<?php if ($is_manager): ?>
<dialog id="tsAddDialog" class="ts-dialog" aria-labelledby="tsAddTitle">
  <form id="tsAddForm" method="dialog">
    <h3 id="tsAddTitle">Add time card</h3>
    <p id="tsAddFor" class="ts-dialog__sub"></p>
    <label>Date <input name="date" type="date" required></label>
    <div class="ts-dialog__row">
      <label>Clock in <input name="in" type="time" required></label>
      <label>Clock out <input name="out" type="time"><small>Leave empty if still on shift.</small></label>
    </div>
    <label>Meal break (minutes) <input name="breakMinutes" type="number" min="0" max="240" step="5" value="30"></label>
    <p id="tsAddErr" class="ts-dialog__err" role="alert"></p>
    <div class="ts-dialog__actions">
      <button type="button" class="btn" data-ts-close>Cancel</button>
      <button type="submit" class="btn btn--primary">Add time card</button>
    </div>
  </form>
</dialog>
<?php endif; ?>
<?php
$content = ob_get_clean();
$footer_js = '<script nonce="' . $cspNonceAttr . '">window.timesheetConfig = ' . json_encode([
  'endpoint'  => $BASE_URL . '/utils/timesheet_data.php',
  'csrfToken' => app_csrf_token(),
  'isManager' => $is_manager,
]) . ';</script>';

include __DIR__ . '/../templates/layout.php';
