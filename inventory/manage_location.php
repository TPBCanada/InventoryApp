<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . "/dbinv.php";

// --- Include the configuration initialization script ---
require_once __DIR__ . '/../utils/config_init.php';

// ---- auth ----
// Refactored to separate header() and exit; for robust parsing.
if (!isset($_SESSION["username"])) {
  header("Location: ../login.php");
  exit();
}

$allowed_roles = [1, 2];
if (!in_array((int) ($_SESSION["role_id"] ?? 0), $allowed_roles, true)) {
  // Using exit() instead of die() for consistency, although they are aliases.
  exit(
    "<p style='color:red; text-align:center; font-size:18px; margin-top:50px;'>Access denied.</p>"
  );
}

$username = $_SESSION["username"];
$user_id = $_SESSION["user_id"];
$role_id = $_SESSION["role_id"] ?? 0;

// The SPECIAL_ROWS constant is now available if config_init.php was included successfully.

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
  $conn->set_charset("utf8mb4");
} catch (\Throwable $_) {
}

function h($v)
{
  return htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8");
}
function pad2(string $s)
{
  return preg_match('/^\d+$/', $s) ? str_pad($s, 2, "0", STR_PAD_LEFT) : $s;
}


$message = "";
$PAGE_SIZE = 50;

// ---------- CREATE (new location) ----------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["create_location"])) {
  $row = strtoupper(trim($_POST["row_code"] ?? ""));
  $bay = pad2(trim($_POST["bay_num"] ?? ""));
  $lvl = pad2(trim($_POST["level_code"] ?? ""));
  $sd = strtoupper(trim($_POST["side"] ?? ""));

  if (
    $row === "" ||
    $bay === "" ||
    $lvl === "" ||
    ($sd !== "F" && $sd !== "B")
  ) {
    $message = "❌ Please fill Row, Bay, Level and Side (F/B).";
  } else {
    // Check for exact duplicates (regardless of disabled status)
    $chk = $conn->prepare(
      "SELECT COUNT(*) c FROM location WHERE row_code=? AND bay_num=? AND level_code=? AND side=?",
    );
    $chk->bind_param("ssss", $row, $bay, $lvl, $sd);
    $chk->execute();
    $c = (int) ($chk->get_result()->fetch_assoc()["c"] ?? 0);
    $chk->close();

    if ($c > 0) {
      $message = "❌ That location already exists (either active or disabled).";
    } else {
      // Default for is_disabled is 0 (Active)
      $ins = $conn->prepare(
        "INSERT INTO location (row_code, bay_num, level_code, side, is_disabled) VALUES (?,?,?,?, 0)",
      );
      $ins->bind_param("ssss", $row, $bay, $lvl, $sd);
      if ($ins->execute()) {
        $message = "✅ Location created.";
        header("Location: " . basename(__FILE__));
        exit();
      } else {
        $message = "❌ Insert failed.";
      }
      $ins->close();
    }
  }
}

// ---------- DISABLE / ENABLE Location ----------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action_location"])) {
  $id = (int) ($_POST["location_id"] ?? 0);
  $action = $_POST["action"] ?? ""; // 'disable' or 'enable'
  $status = $action === "disable" ? 1 : 0; // 1 for disabled, 0 for enabled

  if ($id > 0 && ($action === "disable" || $action === "enable")) {
    $upd = $conn->prepare("UPDATE location SET is_disabled = ? WHERE id = ?");
    $upd->bind_param("ii", $status, $id);

    if ($upd->execute()) {
      $message =
        "✅ Location ID " .
        $id .
        " (" .
        h($_POST["location_code"] ?? "N/A") .
        ") " .
        ($status ? "disabled" : "enabled") .
        ".";
    } else {
      $message = "❌ Update failed for ID " . $id . ".";
    }
    $upd->close();

    // Preserve current filters on redirect (PRG pattern)
    $redirect_url = basename(__FILE__);
    $query_params = [];
    // Important: Read search state from POST for the redirect
    if (!empty($_POST["q"])) {
      $query_params["q"] = $_POST["q"];
    }

    // --- NEW STATUS FILTER LOGIC: Checkbox state (Show Disabled Only) ---
    if (isset($_POST["show_disabled"])) {
      $query_params["show_disabled"] = "on";
    }

    if (!empty($query_params)) {
      $redirect_url .= "?" . http_build_query($query_params);
    }
    header("Location: " . $redirect_url);
    exit(); // PRG pattern
  } else {
    $message = "❌ Invalid action or ID.";
  }
}

// ---------- SEARCH/FILTER Logic (Status and Code LIKE) ----------
$q = trim($_GET["q"] ?? "");

// Checkbox presence means 'show disabled only' is active.
$show_disabled_filter = isset($_GET["show_disabled"]);

$params = [];
$types = "";
$conditions = [];

// 1. Status Filter (Controlled by Checkbox)
$status_clause = "";
if ($show_disabled_filter) {
  // If checked: Show ONLY disabled locations
  $status_clause = "is_disabled = 1";
} else {
  // If unchecked (default): Show ONLY active locations
  $status_clause = "is_disabled = 0";
}

if ($status_clause !== "") {
  $conditions[] = $status_clause;
}

// 2. Code Search Filter (applied to the filtered status set)
if ($q !== "") {
  // Build the code the same way we render it: row-bay-lvl-side with padding
  $conditions[] =
    "CONCAT(row_code,'-',LPAD(bay_num,2,'0'),'-',LPAD(level_code,2,'0'),'-',side) LIKE ?";
  $params[] = "%{$q}%";
  $types .= "s";
}

$where = "";
if (!empty($conditions)) {
  $where = "WHERE " . implode(" AND ", $conditions);
}

// Common SELECT and FROM clauses
$select_cols = "
  location.id, 
  row_code, 
  bay_num, 
  level_code, 
  side, 
  is_disabled
";

$from_clause = "location";

// ---------- Totals by row + grand total (filtered by $where) - Counts locations, not pallets ----------
$totSql =
  "
  SELECT row_code, COUNT(*) AS cnt
  FROM location
  " .
  ($where ?: "") .
  "
  GROUP BY row_code
  ORDER BY row_code
";
$ts = $conn->prepare($totSql);
if ($types) {
  $ts->bind_param($types, ...$params);
}
$ts->execute();
$tres = $ts->get_result();
$rowTotals = [];
$grandTotal = 0;
while ($t = $tres->fetch_assoc()) {
  $rowTotals[$t["row_code"]] = (int) $t["cnt"];
  $grandTotal += (int) $t["cnt"];
}
$ts->close();

// total count for lazy-load (filtered by $where) - Counts locations, not pallets
$cntSql = "SELECT COUNT(*) AS c FROM location " . ($where ?: "");
$cs = $conn->prepare($cntSql);
if ($types) {
  $cs->bind_param($types, ...$params);
}
$cs->execute();
$ct = $cs->get_result()->fetch_assoc();
$totalCount = (int) ($ct["c"] ?? 0);
$cs->close();

// ---------- AJAX: lazy page ----------
if (isset($_GET["ajax"]) && $_GET["ajax"] === "1") {
  while (ob_get_level() > 0) {
    ob_end_clean();
  }
  header("Content-Type: application/json; charset=UTF-8");

  $offset = max(0, (int) ($_GET["offset"] ?? 0));
  $limit = max(1, min(500, (int) ($_GET["limit"] ?? $PAGE_SIZE)));

  $pageSql =
    "
    SELECT {$select_cols}
    FROM {$from_clause}
    " .
    ($where ?: "") .
    "
    ORDER BY location.id ASC
    LIMIT ? OFFSET ?
  ";

  $ptypes = $types . "ii";
  $pparams = $params;
  $pparams[] = $limit;
  $pparams[] = $offset;

  $ps = $conn->prepare($pageSql);
  $ps->bind_param($ptypes, ...$pparams);
  $ps->execute();
  $res = $ps->get_result();

  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $row = (string) $r["row_code"];
    $bay = pad2((string) $r["bay_num"]);
    $lvl = pad2((string) $r["level_code"]);
    $sd = (string) $r["side"];
    $rows[] = [
      "id" => (int) $r["id"],
      "code" => $row . "-" . $bay . "-" . $lvl . "-" . $sd,
      "disabled" => (bool) $r["is_disabled"],
    ];
  }
  $ps->close();

  echo json_encode(
    [
      "ok" => true,
      "rows" => $rows,
      "hasMore" => $offset + count($rows) < $totalCount,
    ],
    JSON_INVALID_UTF8_SUBSTITUTE,
  );
  exit();
}

// ---------- First page (filtered by $where) ----------
$firstSql =
  "
  SELECT {$select_cols}
  FROM {$from_clause}
  " .
  ($where ?: "") .
  "
  ORDER BY location.id ASC
  LIMIT ? OFFSET 0
";
$fs = $conn->prepare($firstSql);
$fs->bind_param($types . "i", ...array_merge($params, [$PAGE_SIZE]));
$fs->execute();
$fres = $fs->get_result();

$firstRows = [];
while ($r = $fres->fetch_assoc()) {
  $row = (string) $r["row_code"];
  $bay = pad2((string) $r["bay_num"]);
  $lvl = pad2((string) $r["level_code"]);
  $sd = (string) $r["side"];
  $firstRows[] = [
    "id" => (int) $r["id"],
    "code" => $row . "-" . $bay . "-" . $lvl . "-" . $sd,
    "disabled" => (bool) $r["is_disabled"],
  ];
}
$fs->close();

// --- Capture the exact count of rows loaded on the first page for JS offset initialization ---
$actualFirstRowsCount = count($firstRows);

// --- Display name reflects the new checkbox state ---
$filter_display_name = $show_disabled_filter ? "Disabled Only" : "Active Only";

// ---------- Render ----------
$title = "Manage Location";
$page_class = "page-manage-locations";
ob_start();
?>
<div class="container" style="max-width:1100px; margin:0 auto; padding:16px;">
  <h1 style="margin:0 0 6px;">Manage Location</h1>
  <p class="muted" style="margin:0 0 12px;">Locations matching the filter are listed below. Use the action button to
    change a location's status.</p>

  <?php if ($message): ?>
    <div class="card card--pad"
      style="margin-bottom:12px; <?= str_starts_with($message, "✅")
        ? "border:1px solid #cfe9cf;background:#f6fff6;"
        : "border:1px solid #f3c2c2;background:#fff6f6;" ?>">
      <?= h($message) ?>
    </div>
  <?php endif; ?>

  <form method="post" class="card" style="padding:12px; margin-bottom:14px; display:grid; gap:8px;">
    <div style="display:grid; grid-template-columns: repeat(5, minmax(0,1fr)); gap:8px;">
      <div>
        <label style="font-size:.9rem;opacity:.8;">Row</label>
        <input name="row_code" class="input" placeholder="e.g., R10" required>
      </div>
      <div>
        <label style="font-size:.9rem;opacity:.8;">Bay</label>
        <input name="bay_num" class="input" placeholder="01" required>
      </div>
      <div>
        <label style="font-size:.9rem;opacity:.8;">Level</label>
        <input name="level_code" class="input" placeholder="11" required>
      </div>
      <div>
        <label style="font-size:.9rem;opacity:.8;">Side</label>
        <select name="side" class="input" required>
          <option value="F">F</option>
          <option value="B">B</option>
        </select>
      </div>
      <div class="flex items-end">
        <button class="btn btn-primary" type="submit" name="create_location">Create Location</button>
      </div>
    </div>
    <div class="small" style="opacity:.75;">Bay/Level will be zero-padded (e.g., 1 → 01). New locations are created as
      **Active**.</div>
  </form>

  <form id="searchForm" method="get" class="card flex flex-row g-3 items-center flex-wrap justify-between"
    style="padding:12px; margin-bottom:14px;">

    <div class="flex-wrap grow" style="position:relative; max-width: 480x;">
      <label for="q" style="font-size:.9rem; opacity:.8; display:block;">Search Location Code</label>
      <input id="q" name="q" class="input" value="<?= h(
        $q,
      ) ?>" placeholder="Type part of code, e.g., R10-01-11 or -F">
    </div>

    <div class="flex flex-row flex-wrap justify-center items-center" style="position:relative; ">
      <label for="show_disabled" style="font-size:.9rem; opacity:.8; display:block;">Disabled</label>
      <input type="checkbox" id="show_disabled" name="show_disabled" class="checkbox-override" <?= $show_disabled_filter
        ? "checked"
        : "" ?>>
      <!-- <label for="show_disabled" style="font-size:.9rem; opacity:.8;">Disabled</label> -->
    </div>

    <button class="btn btn-primary shrink-0" type="submit">Search</button>
    <?php if ($q !== "" || $show_disabled_filter): ?>
      <a class="btn shrink-0" href="<?= h(basename(__FILE__)) ?>">Reset</a>
    <?php endif; ?>
  </form>

  <div class="card" style="padding:12px; margin-bottom:12px;">
    <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
      <strong>Totals per Row (<?= h($filter_display_name) ?>):</strong>
      <?php if ($rowTotals): ?>
        <?php foreach ($rowTotals as $rk => $cnt): ?>
          <span class="badge" style="background:#eef; border:1px solid #cde; padding:4px 8px; border-radius:999px;">
            <?= h($rk) ?>: <?= (int) $cnt ?>
          </span>
        <?php endforeach; ?>
      <?php else: ?>
        <span class="muted">No rows matched the current filter.</span>
      <?php endif; ?>
      <span style="margin-left:auto;"><strong>Grand Total (<?= h(
        $filter_display_name,
      ) ?>):</strong>
        <?= (int) $grandTotal ?></span>
    </div>
  </div>

  <div class="card loc-table-card">
    <div class="loc-table-wrap">
      <table class="loc-table">
        <thead>
          <tr>
            <th class="loc-col-id">ID</th>
            <th class="loc-col-code">Code</th>
            <th class="loc-col-status">Status</th>
            <th class="loc-col-action">Action</th>
          </tr>
        </thead>
        <tbody id="locTbody">
          <?php if (!$firstRows): ?>
            <tr>
              <td colspan="4" class="loc-empty">No locations found matching the filter.</td>
            </tr>
          <?php else:foreach ($firstRows as $r): ?>
              <tr>
                <td><?= (int) $r["id"] ?></td>
                <td class="mono"><?= h($r["code"]) ?></td>
                <td>
                  <span class="status-pill <?= $r["disabled"]
                    ? "is-disabled"
                    : "is-active" ?>">
                    <?= $r["disabled"] ? "Disabled" : "Active" ?>
                  </span>
                </td>
                <td class="align-right">
                  <form method="post" style="margin:0; display:inline-block;">
                    <input type="hidden" name="location_id" value="<?= (int) $r[
                      "id"
                    ] ?>">
                    <input type="hidden" name="location_code" value="<?= h(
                      $r["code"],
                    ) ?>">
                    <input type="hidden" name="action" value="<?= $r["disabled"]
                      ? "enable"
                      : "disable" ?>">
                    <input type="hidden" name="q" value="<?= h($q) ?>">
                    <?php if ($show_disabled_filter): ?>
                      <input type="hidden" name="show_disabled" value="on">
                    <?php endif; ?>
                    <button class="btn btn-sm" type="submit" name="action_location"
                      onclick="return confirm('Are you sure you want to <?= $r[
                        "disabled"
                      ]
                        ? "enable"
                        : "disable" ?> location <?= h(
      $r["code"],
    ) ?>? This status affects all system inventory assignment functions.')">
                      <?= $r["disabled"] ? "Enable" : "Disable" ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach;endif; ?>
        </tbody>
        <tfoot>
          <tr id="lazySentinel">
            <td colspan="4" class="loc-loading">Loading more…</td>
          </tr>
          <tr id="lazyDone" hidden>
            <td colspan="4" class="loc-loading muted">All filtered results loaded
              (<?= (int) $totalCount ?>)</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();

// ---------- Footer JS (lazy-load append + typeahead on #q) ----------
$footer_js = <<<HTML
<style>
.page-manage-locations .loc-table-card {
  padding: 0;
  background: color-mix(in srgb, var(--surface) 90%, #f9fbff);
}

.page-manage-locations .loc-table-wrap {
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: auto;
  background: var(--surface);
  box-shadow: 0 10px 20px rgba(7, 15, 35, 0.08);
}

.page-manage-locations .loc-table {
  width: 100%;
  border-collapse: collapse;
  min-width: 520px;
  table-layout: auto;
  font-size: 0.88rem;
}

.page-manage-locations .loc-table th,
.page-manage-locations .loc-table td {
  padding: 6px 10px;
  border: 1px solid var(--inv-grid);
  text-align: left;
  vertical-align: middle;
}

.page-manage-locations .loc-table th {
  position: sticky;
  top: 0;
  background: color-mix(in srgb, var(--brand) 10%, var(--surface));
  color: var(--inv-ink);
  z-index: 1;
  white-space: nowrap;
}

.page-manage-locations .loc-table td {
  white-space: normal;
  line-height: 1.3;
  color: var(--inv-graphite);
}

.page-manage-locations .loc-table tbody tr:nth-child(even) td {
  background: color-mix(in srgb, var(--brand) 4%, var(--surface));
}

.page-manage-locations .loc-table tbody tr:hover td {
  background: color-mix(in srgb, var(--brand) 8%, var(--surface));
}

.page-manage-locations .loc-table .loc-col-action,
.page-manage-locations .loc-table .align-right {
  text-align: right;
}

.page-manage-locations .loc-table .mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.page-manage-locations .loc-table .loc-empty,
.page-manage-locations .loc-table .loc-loading {
  text-align: center;
  padding: 14px;
}

.page-manage-locations .loc-table .status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 9px;
  border-radius: 12px;
  font-weight: 600;
  font-size: 0.85rem;
  border: 1px solid color-mix(in srgb, var(--border) 70%, rgba(15, 118, 110, 0.35));
  background: rgba(255, 255, 255, 0.62);
  color: var(--inv-ink);
}

.page-manage-locations .loc-table .status-pill.is-active {
  box-shadow: 0 6px 12px rgba(15, 118, 110, 0.18);
}

.page-manage-locations .loc-table .status-pill.is-disabled {
  background: color-mix(in srgb, #fef2f2 80%, var(--surface));
  border-color: color-mix(in srgb, #fda4af 60%, var(--border));
  color: #b91c1c;
}

/* --- NEW: Checkbox style override to fix min-height 44px global rule --- */
input[type="checkbox"].checkbox-override,
/* Targeting standard checkbox inputs as well for safety */
input[type="checkbox"] {
    /* Override global form element styles*/
    min-height: unset;
    width: 16px !important;
    height: 16px !important;
    padding: 0;
    border-radius: 4px;
    margin: 0;
    /* Ensure it has the correct appearance for a checkbox */
    appearance: checkbox;
    -webkit-appearance: checkbox;
    /* Reset border style for a standard checkbox */
    border: 1px solid var(--border);
    background: var(--surface);
    transition: none;
    flex-shrink: 0;
}
input[type="checkbox"].checkbox-override:focus,
input[type="checkbox"]:focus {
  /* Inherit focus styling but keep size */
  outline: 2px solid var(--brand);
  outline-offset: 2px;
  box-shadow: none;
  border-color: var(--border);
}


/* Autocomplete dropdown for #q */
.ac-list {
  position: absolute; left: 0; right: 0; top: calc(100% + 2px);
  max-height: 260px; overflow-y: auto;
  border: 1px solid #ced4da; border-top: none;
  background: #fff; z-index: 9999;
  box-shadow: 0 6px 12px rgba(0,0,0,.1);
}
.ac-item {
  padding: .375rem .75rem;
  cursor: pointer;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.ac-item:hover, .ac-item.active { background: #f0f6ff; }

/* Custom button styles for Action column */
.btn-sm { padding: 4px 8px; font-size: 0.8rem; line-height: 1; border-radius: 4px; }
.btn-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.btn-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cd; }
.btn-danger:hover { background-color: #f5c6cb; }
.btn-success:hover { background-color: #c3e6cd; }
</style>

<script>
(function(){
  // ===== Infinite scroll for the table (existing behavior) =====
  const PAGE_SIZE   = {$PAGE_SIZE};
  const TOTAL_COUNT = {$totalCount};
  // FIX: Initialize offset based on actual number of rows loaded on the first page
  let offset        = {$actualFirstRowsCount};
  let busy=false, done=(offset>=TOTAL_COUNT);

  const tbody  = document.getElementById('locTbody');
  const sent   = document.getElementById('lazySentinel');
  const doneEl = document.getElementById('lazyDone');
  const searchForm = document.getElementById('searchForm'); // Used to get filter parameters
  // Reference the new checkbox
  const disabledCheckbox = document.getElementById('show_disabled');

  if (tbody && sent && doneEl) {
    if (done) { sent.hidden = true; doneEl.hidden = false; observer && observer.disconnect(); }

    // Helper to create a table data cell (UPDATED to support alignCenter)
    function td(txt, mono, align){ // align can be 'right' or 'center'
      const el=document.createElement('td');
      if (mono) el.classList.add('mono');
      if (align === 'right') el.classList.add('align-right');
      if (align === 'center') el.style.textAlign='center';
      el.textContent = txt ?? '';
      return el;
    }

    // Helper to create the Status cell
    function createStatusCell(isDisabled) {
        const tdEl = document.createElement('td');

        const span = document.createElement('span');
        span.className = `status-pill \${isDisabled ? 'is-disabled' : 'is-active'}`;
        span.textContent = isDisabled ? 'Disabled' : 'Active';
        tdEl.appendChild(span);
        return tdEl;
    }

    // Helper to create the Action cell
    function createActionCell(id, code, isDisabled) {
      const tdEl = document.createElement('td');
      tdEl.className = 'align-right';

      const form = document.createElement('form');
      form.method = 'post';
      form.style.margin = '0';
      form.style.display = 'inline-block';

      const action = isDisabled ? 'enable' : 'disable';
      const buttonText = isDisabled ? 'Enable' : 'Disable';
      const buttonClass = isDisabled ? 'btn-success' : 'btn-danger';
      const confirmMsg = 'Are you sure you want to ' + action + ' location ' + code + '? This status affects all system inventory assignment functions.';

      // Preserve current filters on lazy-loaded rows
      const qVal = document.getElementById('q')?.value || '';
      const isChecked = !!disabledCheckbox?.checked;

      const inputs = [
        ['location_id', String(id)],
        ['location_code', code],
        ['action', action],
        ['q', qVal],
      ];
      if (isChecked) inputs.push(['show_disabled', 'on']);

      for (const [name, value] of inputs) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      }

      const btn = document.createElement('button');
      btn.type = 'submit';
      btn.name = 'action_location';
      btn.className = `btn btn-sm \${buttonClass}`;
      btn.textContent = buttonText;
      btn.addEventListener('click', (e) => {
        if (!confirm(confirmMsg)) e.preventDefault();
      });

      form.appendChild(btn);
      tdEl.appendChild(form);
      return tdEl;
    }

    function appendRows(rows){
      if (!rows || rows.length===0){ done=true; sent.hidden=true; doneEl.hidden=false; observer && observer.disconnect(); return; }
      for (const r of rows){
        const tr=document.createElement('tr');
        tr.appendChild(td(String(r.id)));
        tr.appendChild(td(r.code, true));
        tr.appendChild(createStatusCell(r.disabled));
        tr.appendChild(createActionCell(r.id, r.code, r.disabled));
        tbody.appendChild(tr);
      }
    }
    async function fetchPage(){
      if (busy || done) return;
      busy = true;
      try{
        const url=new URL(window.location.href);
        url.searchParams.set('ajax','1');
        url.searchParams.set('offset', String(offset));
        url.searchParams.set('limit',  String(PAGE_SIZE));
        // Add current search query and checkbox filter to AJAX request
        const q_val = document.getElementById('q').value;
        if (q_val) url.searchParams.set('q', q_val); else url.searchParams.delete('q');

        // Send checkbox state
        if (disabledCheckbox?.checked) url.searchParams.set('show_disabled', 'on'); else url.searchParams.delete('show_disabled');

        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }});
        if(!res.ok) throw new Error('Network error');
        const data = await res.json();
        appendRows(data.rows || []);
        offset += (data.rows?.length || 0);
        done = !data.hasMore;
        if (done){ sent.hidden=true; doneEl.hidden=false; observer && observer.disconnect(); }
      }catch(e){
        console.error(e);
        observer && observer.disconnect();
        sent.textContent='Could not load more rows.';
      }finally{ busy=false; }
    }
    const observer = new IntersectionObserver((ents)=>{ for(const e of ents){ if(e.isIntersecting) fetchPage(); } }, {root:null, rootMargin:'600px 0px', threshold:0});
    if (!done) observer.observe(sent);
  }

  // ===== Typeahead for the Search input (#q) =====
  // NOTE: The typeahead search respects the current checkbox filter.
  const form = document.getElementById('searchForm');
  const input = document.getElementById('q');
  if (!form || !input) return;

  // Create dropdown container
  const wrap = input.parentElement; // .ac-wrap
  const list = document.createElement('div');
  list.className = 'ac-list';
  list.style.display = 'none';
  wrap.appendChild(list);

  let selectedIndex = -1;
  const LIMIT = 50;
  let lastQuery = '';
  let inflight = 0;

  function render(items){
    list.innerHTML = '';
    selectedIndex = -1;
    if (!items || !items.length) { list.style.display = 'none'; return; }
    const frag = document.createDocumentFragment();
    items.forEach((code, idx) => {
      const item = document.createElement('div');
      item.className = 'ac-item';
      item.setAttribute('data-index', String(idx));
      item.textContent = code;
      item.addEventListener('mousedown', (e) => {
        e.preventDefault();
        choose(code);
      });
      frag.appendChild(item);
    });
    list.appendChild(frag);
    list.style.display = 'block';
  }

  function choose(code){
    if (!code) return;
    input.value = code;
    hide();
    // Submit immediately when a suggestion is chosen
    form.requestSubmit ? form.requestSubmit() : form.submit();
  }

  function hide(){
    list.style.display = 'none';
    selectedIndex = -1;
  }

  async function fetchSuggestions(q){
    const myTicket = ++inflight;
    try{
      const url = new URL(window.location.href);
      url.searchParams.set('ajax','1');
      url.searchParams.set('offset','0');
      url.searchParams.set('limit', String(LIMIT));

      // Add current checkbox filter to the suggestion request
      if (disabledCheckbox?.checked) url.searchParams.set('show_disabled', 'on'); else url.searchParams.delete('show_disabled');

      if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');

      const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' }});
      if (!res.ok) throw new Error('HTTP '+res.status);
      const data = await res.json();
      if (myTicket !== inflight) return; // stale
      const codes = Array.isArray(data.rows) ? data.rows.map(r => r.code) : [];
      render(codes);
    } catch(e){
      if (myTicket !== inflight) return;
      console.error(e);
      render([]);
    }
  }

  // Debounce input
  let t = null;
  input.addEventListener('input', () => {
    const q = input.value.trim();
    lastQuery = q;
    clearTimeout(t);
    t = setTimeout(() => fetchSuggestions(q), 150);
  });

  // Show first page on focus
  input.addEventListener('focus', () => {
    fetchSuggestions(input.value.trim());
  });

  // Re-fetch suggestions when the status filter changes, and submit the form
  // We use 'change' on the checkbox to submit the form immediately
  disabledCheckbox.addEventListener('change', () => {
    form.submit();
  });


  // Keyboard navigation
  input.addEventListener('keydown', (e) => {
    if (list.style.display === 'none') return;
    const items = Array.from(list.querySelectorAll('.ac-item'));
    if (!items.length) return;

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      selectedIndex = (selectedIndex + 1) % items.length;
      updateActive(items);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      selectedIndex = (selectedIndex - 1 + items.length) % items.length;
      updateActive(items);
    } else if (e.key === 'Enter') {
      if (selectedIndex >= 0) {
        e.preventDefault();
        const code = items[selectedIndex].textContent || '';
        choose(code);
      }
    } else if (e.key === 'Escape') {
      hide();
    }
  });

  function updateActive(items){
    items.forEach(el => el.classList.remove('active'));
    if (selectedIndex >= 0 && items[selectedIndex]) {
      const el = items[selectedIndex];
      el.classList.add('active');
      const top = el.offsetTop, bottom = top + el.offsetHeight;
      if (top < list.scrollTop) list.scrollTop = top;
      else if (bottom > list.scrollTop + list.clientHeight) list.scrollTop = bottom - list.clientHeight;
    }
  }

  // Hide on blur (allow mousedown to run first)
  input.addEventListener('blur', () => setTimeout(hide, 150));
})();
</script>
HTML;

include __DIR__ . "/templates/layout.php";

