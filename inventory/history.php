<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php'; 
require_once __DIR__ . '/../utils/config_init.php';



mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (!function_exists('parse_location_code')) {
    /**
     * Parses a location string like ROW-BAY-LEVEL-SIDE into its components.
     */
    function parse_location_code(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        $parts = preg_split('/[-\s]+/', $code);
        return [
            'row_code' => $parts[0] ?? '',
            'bay_num' => $parts[1] ?? '',
            'level_code' => $parts[2] ?? '',
            'side' => $parts[3] ?? '',
        ];
    }
}

if (!function_exists('getLocationDisplayLabel')) {
    function getLocationDisplayLabel(array $loc_record): string
    {
        $row_code = $loc_record['row_code'] ?? '';

        if (defined('SPECIAL_ROWS') && in_array(strtoupper($row_code), SPECIAL_ROWS, true)) {
            return $row_code;
        }

        $bay_num = $loc_record['bay_num'] ?? '';
        $level_code = $loc_record['level_code'] ?? '';
        $side = $loc_record['side'] ?? '';

        return "{$row_code}-{$bay_num}-{$level_code}-{$side}";
    }
}

function pad2(string $s): string
{
    return preg_match('/^\d+$/', $s) ? str_pad($s, 2, '0', STR_PAD_LEFT) : $s;
}

/* ---------- Username Resolver ---------- */
if (!function_exists('get_username_by_id')) {
    function get_username_by_id(mysqli $conn, int $userId): string
    {
        if ($userId <= 0) return '—';
        $fallback = "User #{$userId}";
        try {
            $stmt = $conn->prepare("SELECT username FROM `xserqhmy_users`.`users` WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $username = $result->fetch_column();
            $stmt->close();
            if (is_string($username) && trim($username) !== '') return $username;
            return $fallback;
        } catch (Throwable $e) {
            error_log("[get_username_by_id] Database Error for ID {$userId}: " . $e->getMessage());
            return $fallback;
        }
    }
}

/* ---------- Auth ---------- */
if (!isset($_SESSION['username'])) {
 header('Location: ../login.php'); exit;
}
$username = $_SESSION['username'];
$user_id = (int) ($_SESSION['user_id'] ?? 0);
date_default_timezone_set('America/Toronto');
$title = 'Inventory Movement History';

if (isset($_GET['suggest']) && $_GET['suggest'] === '1') {
  while (ob_get_level() > 0) ob_end_clean();
  header('Content-Type: application/json; charset=utf-8');

  $type = ($_GET['type'] ?? 'loc') === 'sku' ? 'sku' : 'loc';
  $q    = trim((string)($_GET['q'] ?? ''));
  $items = [];

  try {
    if ($type === 'sku') {
      // Return ONLY sku_num, but allow matching on sku_num OR description
      $sql = "
        SELECT sku_num
        FROM sku
        WHERE status='ACTIVE'
          AND (sku_num LIKE CONCAT('%', ?, '%') OR `desc` LIKE CONCAT('%', ?, '%'))
        ORDER BY sku_num ASC
        LIMIT 30
      ";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param('ss', $q, $q);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) {
        $items[] = ['sku_num' => (string)$r['sku_num']];
      }
      $stmt->close();
    } else {
      $sql = "
        SELECT row_code, bay_num, level_code, side
        FROM location
        WHERE is_disabled = 0
          AND CONCAT(row_code,'-',LPAD(bay_num,2,'0'),'-',LPAD(level_code,2,'0'),'-',side)
              LIKE CONCAT('%', ?, '%')
        ORDER BY row_code, CAST(bay_num AS UNSIGNED), level_code, side
        LIMIT 30
      ";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param('s', $q);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) {
        $items[] = [
          'row_code'   => (string)$r['row_code'],
          'bay_num'    => pad2((string)$r['bay_num']),
          'level_code' => pad2((string)$r['level_code']),
          'side'       => (string)$r['side'],
          'code'       => $r['row_code'].'-'.pad2((string)$r['bay_num']).'-'.pad2((string)$r['level_code']).'-'.$r['side'],
        ];
      }
      $stmt->close();
    }
  } catch (Throwable $e) {
    error_log('[history suggest] '.$e->getMessage());
  }

  echo json_encode(['ok'=>true,'type'=>$type,'items'=>$items], JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

/* ---------- VAR INIT ---------- */
$error_message     = $error_message     ?? '';
$loc_str           = $loc_str           ?? '';
$sku_str           = $sku_str           ?? '';
$history_records   = $history_records   ?? [];
$total_count       = $total_count       ?? 0;
$pagination_limit  = $pagination_limit  ?? 50;
$page              = $page              ?? 1;
$offset            = $offset            ?? 0;
$is_default_load   = false;
$run_paginated_query = false;

$current_special_rows = defined('SPECIAL_ROWS') ? SPECIAL_ROWS : [];

$rows = $rows ?? [];
$baysByRow = $baysByRow ?? [];
$levelsByRow = $levelsByRow ?? [];
$sidesByRow = $sidesByRow ?? [];
$current_selection = $current_selection ?? ['row' => '', 'bay' => '', 'lvl' => '', 'side' => '', 'sku' => ''];

$css = is_array($css ?? null) ? $css : [];
$js = is_array($js ?? null) ? $js : [];
$footer_js = $footer_js ?? '';

/* ---------- Load selectors for JS (Excludes B/L/S for SPECIAL_ROWS) ---------- */
$res = $conn->query("
  SELECT row_code, bay_num, level_code, side
  FROM location
  WHERE is_disabled = 0
  ORDER BY row_code, CAST(bay_num AS UNSIGNED), bay_num, level_code, side
");
while ($r = $res->fetch_assoc()) {
  $row = (string)$r['row_code'];
  $row_upper = strtoupper($row);
  $bay = (string)$r['bay_num'];
  $lvl = (string)$r['level_code'];
  $side = (string)$r['side'];
  
  $is_special = in_array($row_upper, $current_special_rows, true); 

  if (!in_array($row, $rows, true)) $rows[] = $row;
  $baysByRow[$row] = $baysByRow[$row] ?? [];
  $levelsByRow[$row] = $levelsByRow[$row] ?? [];
  $levelsByRow[$row][$bay] = $levelsByRow[$row][$bay] ?? [];
  $sidesByRow[$row] = $sidesByRow[$row] ?? [];
  $sidesByRow[$row][$bay] = $sidesByRow[$row][$bay] ?? [];
  $sidesByRow[$row][$bay][$lvl] = $sidesByRow[$row][$bay][$lvl] ?? [];
  
  if (!$is_special) { 
    if (!in_array($bay, $baysByRow[$row], true)) $baysByRow[$row][] = $bay;
    if (!in_array($lvl, $levelsByRow[$row][$bay], true)) $levelsByRow[$row][$bay][] = $lvl;
    if (!in_array($side, $sidesByRow[$row][$bay][$lvl], true)) $sidesByRow[$row][$bay][$lvl][] = $side;
  }
}

/* ---------- Pagination ---------- */
$pagination_limit = 50;
$page = (int) ($_GET['page'] ?? 1);
$page = max(1, $page);
$offset = ($page - 1) * $pagination_limit;

/* ---------- Capture filters ---------- */
$row = trim((string) ($_GET['row_code'] ?? $_GET['row'] ?? ''));
$bay = trim((string) ($_GET['bay_num'] ?? $_GET['bay'] ?? ''));
$lvl = trim((string) ($_GET['level_code'] ?? $_GET['lvl'] ?? ''));
$side = trim((string) ($_GET['side'] ?? ''));
$sku = trim((string) ($_GET['sku'] ?? ''));

$current_selection = ['row' => $row, 'bay' => $bay, 'lvl' => $lvl, 'side' => $side, 'sku' => $sku];

$norm_row = $row === '' ? '' : strtoupper($row);
$norm_bay = $bay === '' ? '' : ltrim($bay, '0');
if ($norm_bay === '' && $bay !== '') $norm_bay = '0';
$norm_level = $lvl === '' ? '' : strtoupper($lvl);
$norm_side = $side === '' ? '' : strtoupper($side);

$query_row = $norm_row;
$query_bay = $norm_bay;
$query_level = $norm_level;
$query_side = $norm_side;

$is_special_row = $norm_row !== '' && in_array($norm_row, $current_special_rows, true);
$has_complete_location_filter = false;

if ($is_special_row && $query_row) {
    // Force defaults for special rows
    $query_bay = '01';
    $query_level = '11';
    $query_side = 'F';
    $has_complete_location_filter = true;
} elseif ($query_row && $query_bay !== '' && $query_level && $query_side) {
    $has_complete_location_filter = true;
}

$loc_id = null;
$loc_label = '';

if ($has_complete_location_filter) {
    $stmt = $conn->prepare(
        "SELECT id, row_code, bay_num, level_code, side
         FROM location
         WHERE UPPER(row_code) = ?
           AND CAST(bay_num AS UNSIGNED) = CAST(? AS UNSIGNED)
           AND UPPER(level_code) = ?
           AND LEFT(UPPER(side), 1) = LEFT(?, 1)
           AND is_disabled = 0
         LIMIT 1"
    );
    $stmt->bind_param('ssss', $query_row, $query_bay, $query_level, $query_side);
    $stmt->execute();
    $rs = $stmt->get_result();
    if ($rec = $rs->fetch_assoc()) {
        $loc_id = (int)$rec['id'];
        $loc_label = getLocationDisplayLabel($rec);
    }
    $stmt->close();
    if ($loc_id === null) {
        $error_message = 'No location found matching the current selection.';
    }
}

$is_sku_filter = !empty($sku);


/* ---------- Query modes (invLoc-aligned logic) ---------- */
if ($loc_id !== null) {
  $run_paginated_query = true;
  $loc_str = $loc_label ?: $row;
  $sku_str = $is_sku_filter ? " for SKU: " . h($sku) : "";

  $where_clause = "WHERE im.loc_id = ?";
  $bind_types = 'i';
  $bind_params = [$loc_id];

  if ($is_sku_filter) {
    $where_clause .= " AND s.sku_num = ?";
    $bind_types .= 's';
    $bind_params[] = $sku;
  }

  $sql_count = "
    SELECT COUNT(im.id)
    FROM inventory_movements im
    INNER JOIN sku s ON s.id = im.sku_id
    $where_clause
  ";
  try {
    $stmt_count = $conn->prepare($sql_count);
    $stmt_count->bind_param($bind_types, ...$bind_params);
    $stmt_count->execute();
    $total_count = (int) ($stmt_count->get_result()->fetch_column() ?: 0);
  } catch (Throwable $e) {
    error_log('[history loc count] ' . $e->getMessage());
  }

  $sql_win = "
    WITH base AS (
      SELECT
        im.id, im.created_at, im.movement_type,
        im.quantity_change AS delta,
        im.reference, im.user_id, im.sku_id, s.sku_num AS sku, s.`desc` AS sku_desc,
        l.id AS loc_id, l.row_code, l.bay_num, l.level_code, l.side,
        CONCAT(l.row_code,'-',LPAD(l.bay_num,2,'0'),'-',LPAD(l.level_code,2,'0'),'-',l.side) AS location_code
      FROM inventory_movements im
      INNER JOIN sku s ON s.id = im.sku_id
      LEFT JOIN location l ON l.id = im.loc_id
      $where_clause
    ),
    ranked AS (
      SELECT *,
             SUM(delta) OVER (
               PARTITION BY sku_id, loc_id
               ORDER BY created_at ASC, id ASC
               ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
             ) AS running_balance
      FROM base
    )
    SELECT
      id, created_at, sku, sku_desc, location_code, movement_type,
      delta AS signed_delta, reference, user_id, running_balance
    FROM ranked
    ORDER BY created_at DESC, id DESC
    LIMIT ? OFFSET ?
  ";
  try {
    $stmt = $conn->prepare($sql_win);
    $final_bind_params = $bind_params;
    $final_bind_params[] = $pagination_limit;
    $final_bind_params[] = $offset;
    $final_bind_types = $bind_types . 'ii';
    $stmt->bind_param($final_bind_types, ...$final_bind_params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
      $r['user_name'] = get_username_by_id($conn, (int)($r['user_id'] ?? 0));
      $history_records[] = $r;
    }
  } catch (Throwable $e) {
    $error_message = 'Failed to load history: Database Error.';
    error_log('[history fetch LOC] ' . $e->getMessage());
  }

} elseif ($is_sku_filter) {
  $run_paginated_query = true;
  $is_default_load = true;
  $loc_str = "Movements for SKU: " . h($sku);
  $where_sku = " WHERE s.sku_num = ? ";
  $bind_types = 's';
  $bind_params = [$sku];

  $sql_count = "
    SELECT COUNT(im.id)
    FROM inventory_movements im
    INNER JOIN sku s ON s.id = im.sku_id
    $where_sku
  ";
  try {
    $stmt_count = $conn->prepare($sql_count);
    $stmt_count->bind_param($bind_types, ...$bind_params);
    $stmt_count->execute();
    $total_count = (int) ($stmt_count->get_result()->fetch_column() ?: 0);
  } catch (Throwable $e) {
    error_log('[history SKU-only count] ' . $e->getMessage());
  }

  $sql_sku_only = "
    SELECT
      im.id, im.created_at, im.movement_type, im.quantity_change AS signed_delta,
      im.reference, im.user_id, s.sku_num AS sku, s.`desc` AS sku_desc,
      CONCAT(l.row_code,'-',LPAD(l.bay_num,2,'0'),'-',LPAD(l.level_code,2,'0'),'-',l.side) AS location_code,
      NULL AS running_balance
    FROM inventory_movements im
    INNER JOIN sku s ON s.id = im.sku_id
    INNER JOIN location l ON l.id = im.loc_id
    $where_sku
    ORDER BY im.created_at DESC, im.id DESC
    LIMIT ? OFFSET ?
  ";
  try {
    $stmt = $conn->prepare($sql_sku_only);
    $final_bind_params = $bind_params;
    $final_bind_params[] = $pagination_limit;
    $final_bind_params[] = $offset;
    $final_bind_types = $bind_types . 'ii';
    $stmt->bind_param($final_bind_types, ...$final_bind_params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
      $r['user_name'] = get_username_by_id($conn, (int)($r['user_id'] ?? 0));
      $history_records[] = $r;
    }
  } catch (Throwable $e) {
    $error_message = 'Failed to load SKU history: Database Error.';
    error_log('[history SKU-only fetch] ' . $e->getMessage());
  }

} else {
  $is_default_load = true;
  $total_count = 30;
  $loc_str = "Latest 30 movements";
  $sql_default = "
    SELECT
      im.id, im.created_at, im.movement_type, im.quantity_change AS signed_delta,
      im.reference, im.user_id, s.sku_num AS sku, s.`desc` AS sku_desc,
      CONCAT(l.row_code,'-',LPAD(l.bay_num,2,'0'),'-',LPAD(l.level_code,2,'0'),'-',l.side) AS location_code,
      NULL AS running_balance
    FROM inventory_movements im
    INNER JOIN sku s ON s.id = im.sku_id
    INNER JOIN location l ON l.id = im.loc_id
    ORDER BY im.created_at DESC, im.id DESC
    LIMIT 30
  ";
  try {
    $res = $conn->query($sql_default);
    while ($r = $res->fetch_assoc()) {
      $r['user_name'] = get_username_by_id($conn, (int)($r['user_id'] ?? 0));
      $history_records[] = $r;
    }
  } catch (Throwable $e) {
    $error_message = 'Failed to load default history: Database Error.';
    error_log('[history default fetch] ' . $e->getMessage());
  }
}

/* ---------- View ---------- */
ob_start();
?>
<style>
  .movement-type { padding: 2px 8px; border-radius: 999px; font-weight: 700; font-size: .8rem; }
  .movement-in { background: rgba(22, 163, 74, .12); }
  .movement-out { background: rgba(220, 38, 38, .12); }
  .movement-adjust, .movement-transfer { background: rgba(14, 165, 233, .12); }
  .positive-change { color: #16a34a; font-weight: 700; }
  .negative-change { color: #dc2626; font-weight: 700; }
  .location-choice-trigger {
    background: none;
    border: 0;
    color: #0f62fe;
    cursor: pointer;
    font: inherit;
    padding: 0;
  }
  .location-choice-trigger:hover,
  .location-choice-trigger:focus {
    color: #093aa9;
    outline: none;
  }
  .location-choice-menu {
    position: fixed;
    min-width: 180px;
    background: #fff;
    border: 1px solid #d6dde8;
    border-radius: 12px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, .18);
    padding: 6px;
    z-index: 10000;
  }
  .location-choice-menu__title {
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .02em;
    text-transform: uppercase;
    color: #64748b;
    padding: 6px 8px 4px;
  }
  .location-choice-menu a {
    display: block;
    border-radius: 8px;
    color: #0f172a;
    padding: 8px 10px;
    text-decoration: none;
  }
  .location-choice-menu a:hover,
  .location-choice-menu a:focus {
    background: #eff6ff;
    outline: none;
  }

  /* Dropdown styling reused for both location + SKU */
  .ac-list {
    position: absolute; left: 0; right: 0; top: calc(100% + 2px);
    max-height: 260px; overflow-y: auto;
    border: 1px solid #ced4da; border-top: none; background: #fff; z-index: 9999;
    box-shadow: 0 6px 12px rgba(0,0,0,.1);
  }
  .ac-item {
    padding: .375rem .75rem; cursor: pointer;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  }
  .ac-item:hover, .ac-item.active { background: #f0f6ff; }
</style>

<div class="">
  <h2>Inventory History</h2>
</div>

<?php if ($error_message): ?>
  <div class="toast error"><?= h($error_message) ?></div>
<?php endif; ?>

<form id="historyForm" method="get" autocomplete="off" class="form-grid-4">
  <div class="history-filters">
    <fieldset class="history-filters__loc">
    <legend>Select Location</legend>

    <!-- Quick Location Search (with suggestions) -->
    <div class="quick-search">
      <div style="position:relative;">
        <label for="locQuick">Quick Location Search</label>
        <input type="text" id="locQuick" placeholder="Type e.g., R10-01-11-F or part of it" class="input" />
        <div id="locSuggest" class="ac-list" style="display:none;"></div>
        <div class="small" style="opacity:.7; margin-top:4px;">
          Pick a suggestion to auto-fill Row / Bay / Level / Side.
        </div>
      </div>
    </div>

    <div class="grid grid-4">
      <div><label>Row</label><select name="row" id="row"></select></div>
      <div><label>Bay</label><select name="bay" id="bay" disabled></select></div>
      <div><label>Level</label><select name="lvl" id="lvl" disabled></select></div>
      <div><label>Side</label><select name="side" id="side" disabled></select></div>
    </div>
    </fieldset>

    <fieldset class="history-filters__sku">
    <legend>Filter by SKU</legend>
    <div class="grid grid-4">
      <div class="full-width" style="position:relative;">
        <label for="sku">SKU Number</label>
        <input type="text" name="sku" id="sku" value="<?= h($sku) ?>" placeholder="Start typing a SKU…" class="input"
          data-suggest="inventory" data-suggest-box="skuSuggest">
        <div id="skuSuggest" class="inv-suggest" style="display:none;"></div>
      </div>
    </div>
    </fieldset>

  </div>

  <div class="actions">
    <button class="btn" type="submit" id="searchBtn">🔎 Search History</button>
    <button class="btn secondary" type="reset" id="resetBtn">Clear Selection</button>
  </div>
</form>

<div id="locationChoiceMenu" class="location-choice-menu" hidden>
  <div class="location-choice-menu__title">Choose action</div>
  <a id="locationChoiceAdd" href="#">Add Inventory</a>
  <a id="locationChoiceRemove" href="#">Remove Inventory</a>
</div>

<?php if ($loc_str): ?>
  <h3 style="margin-top:18px;">
    History for <strong><?= h($loc_str) ?></strong>
    <?php if (!$is_default_load && $has_complete_location_filter): ?>
      <?= $sku_str ?>
    <?php endif; ?>
    (Total Records: <?= (int) $total_count ?>)
  </h3>
<?php endif; ?>

<?php if ($history_records || ($has_complete_location_filter && !$history_records) || ($is_sku_filter && !$history_records)): ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date/Time</th>
          <?php if ($is_default_load): ?><th>Location</th><?php endif; ?>
          <th>SKU</th>
          <th>Description</th>
          <th>Type</th>
          <th style="text-align:right">Change</th>
          <th style="text-align:right">Balance</th>
          <th>Reference</th>
          <th>User Name</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$history_records): ?>
          <tr><td colspan="<?= $is_default_load ? 9 : 8 ?>" class="muted">No movement records found.</td></tr>
        <?php else:
          foreach ($history_records as $rec):
            $created = $rec['created_at'] ?? null;
            $locationCode = (string)($rec['location_code'] ?? '');
            $location = h($locationCode);
            $locationAddLink = '';
            $locationRemoveLink = '';
            if ($locationCode !== '') {
              $locParts = parse_location_code($locationCode);
              if ($locParts) {
                $locationQuery = http_build_query([
                  'row_code' => $locParts['row_code'] ?? '',
                  'bay_num' => $locParts['bay_num'] ?? '',
                  'level_code' => $locParts['level_code'] ?? '',
                  'side' => $locParts['side'] ?? '',
                ]);
                $locationAddLink = 'place.php?' . $locationQuery;
                $locationRemoveLink = 'take.php?' . $locationQuery;
              }
            }
            $skuVal = h($rec['sku'] ?? '');
            $skuDesc = h($rec['sku_desc'] ?? '');
            $ref = h($rec['reference'] ?? '');
            $userName = h($rec['user_name'] ?? '—');
            $typeRaw = strtoupper((string) ($rec['movement_type'] ?? ''));
            $typeNorm = ($typeRaw === 'ADJUSTMENT') ? 'ADJUST' : $typeRaw;
            switch ($typeNorm) {
              case 'IN':
                $typeClass = 'movement-in';
                break;
              case 'OUT':
                $typeClass = 'movement-out';
                break;
              case 'ADJUST':
                $typeClass = 'movement-adjust';
                break;
              case 'TRANSFER':
                $typeClass = 'movement-transfer';
                break;
              default:
                $typeClass = 'movement-unknown';
                break;
            }
            $delta = (int) ($rec['signed_delta'] ?? 0);
            $balance = $rec['running_balance'];
        ?>
            <tr>
              <td><?= $created ? date('Y-m-d H:i', strtotime($created)) : '—' ?></td>
              <?php if ($is_default_load): ?>
                <td>
                  <?php if ($locationAddLink && $locationRemoveLink): ?>
                    <button
                      type="button"
                      class="location-choice-trigger"
                      data-location-add="<?= h($locationAddLink) ?>"
                      data-location-remove="<?= h($locationRemoveLink) ?>"
                      data-location-label="<?= h($locationCode) ?>"
                      aria-haspopup="menu"
                      aria-controls="locationChoiceMenu"
                    ><?= $location ?></button>
                  <?php else: ?>
                    <?= $location ?>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <td><?= $skuVal ?></td>
              <td><?= $skuDesc ?></td>
              <td><span class="movement-type <?= $typeClass ?>"><?= h($typeNorm) ?></span></td>
              <td style="text-align:right" class="<?= $delta > 0 ? 'positive-change' : 'negative-change' ?>"><?= $delta ?></td>
              <td style="text-align:right">
                <?php if ($balance !== NULL): ?>
                  <strong><?= number_format((int)$balance) ?></strong>
                <?php else: ?>
                  <span class="muted">N/A*</span>
                <?php endif; ?>
              </td>
              <td><?= $ref ?></td>
              <td><?= $userName ?></td>
            </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($is_default_load): ?>
    <p class="muted" style="text-align: center; margin-top: 10px;">
      *Balance is only calculated and displayed when filtering by a specific and complete location.
    </p>
  <?php endif; ?>

  <?php if ($run_paginated_query): ?>
    <div class="pagination" style="margin-top: 15px; text-align: center;">
      <?php
      $max_page = ceil($total_count / $pagination_limit);
      $base_url = http_build_query(['row' => $row, 'bay' => $bay, 'lvl' => $lvl, 'side' => $side, 'sku' => $sku]);
      if ($page > 1): ?>
        <a href="?<?= $base_url ?>&page=<?= $page - 1 ?>" class="btn secondary">&lt; Previous</a>
      <?php endif;
      if ($page < $max_page): ?>
        <a href="?<?= $base_url ?>&page=<?= $page + 1 ?>" class="btn secondary">Next &gt;</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php
/* ---------- Boot payload for history.js ---------- */
$boot = [
  'rows' => $rows,
  'baysByRow' => $baysByRow,
  'levelsByRow' => $levelsByRow,
  'sidesByRow' => $sidesByRow,
  'currentSelection' => $current_selection,
  'specialRows' => SPECIAL_ROWS, // Export the special rows array
];
$json_data = json_encode($boot, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

$footer_js =
  '<script id="history-boot" type="application/json">' . $json_data . '</script>' .
  '<script>window.HISTORY_BOOT = window.HISTORY_BOOT || (function(){try{return JSON.parse(document.getElementById("history-boot").textContent)}catch(e){return null}})();</script>' .
  '<link rel="stylesheet" href="/refactor_css/suggest.css">' .
  '<script defer src="/inventory/js/suggest.js"></script>';

$js = isset($js) && is_array($js) ? $js : [];
$js[] = '/js/history.js';

$content = ob_get_clean();

/* ---------- Extra JS: wire quick location typeahead (SKU now uses shared suggest.js) ---------- */
$footer_js .= <<<'HTML'
<script>
(function(){
  const locationMenu = document.getElementById('locationChoiceMenu');
  const locationAdd = document.getElementById('locationChoiceAdd');
  const locationRemove = document.getElementById('locationChoiceRemove');
  let activeLocationTrigger = null;

  function hideLocationMenu() {
    if (!locationMenu) return;
    locationMenu.hidden = true;
    activeLocationTrigger = null;
  }

  function showLocationMenu(trigger) {
    if (!locationMenu || !locationAdd || !locationRemove) return;
    const addHref = trigger.dataset.locationAdd || '';
    const removeHref = trigger.dataset.locationRemove || '';
    if (!addHref || !removeHref) return;

    locationAdd.href = addHref;
    locationRemove.href = removeHref;

    const rect = trigger.getBoundingClientRect();
    const menuWidth = 220;
    const left = Math.max(8, Math.min(window.innerWidth - menuWidth - 8, rect.left));
    let top = rect.bottom + 8;
    if (top + 112 > window.innerHeight) {
      top = Math.max(8, rect.top - 120);
    }

    locationMenu.style.left = `${left}px`;
    locationMenu.style.top = `${top}px`;
    locationMenu.hidden = false;
    activeLocationTrigger = trigger;
  }

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('.location-choice-trigger');
    if (trigger) {
      event.preventDefault();
      event.stopPropagation();
      if (activeLocationTrigger === trigger && locationMenu && !locationMenu.hidden) {
        hideLocationMenu();
      } else {
        showLocationMenu(trigger);
      }
      return;
    }

    if (locationMenu && !locationMenu.hidden && !event.target.closest('#locationChoiceMenu')) {
      hideLocationMenu();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      hideLocationMenu();
    }
  });

  window.addEventListener('resize', hideLocationMenu);
  window.addEventListener('scroll', hideLocationMenu, true);

  // ====== LOCATION QUICK SEARCH ======
  const inputLoc = document.getElementById('locQuick');
  const listLoc  = document.getElementById('locSuggest');
  const selRow  = document.getElementById('row');
  const selBay  = document.getElementById('bay');
  const selLvl  = document.getElementById('lvl');
  const selSide = document.getElementById('side');
  let tLoc=null, idxLoc=-1, ticketLoc=0;

  function hideLoc(){ listLoc.style.display='none'; listLoc.innerHTML=''; idxLoc=-1; }
  function showLoc(){ listLoc.style.display='block'; }

  function ensureOption(select, value, text){
    if (!select.querySelector(`option[value="${CSS.escape(value)}"]`)) {
      const opt = document.createElement('option');
      opt.value = value; opt.textContent = (text ?? value);
      select.appendChild(opt);
    }
    select.value = value;
    select.disabled = false;
    select.dispatchEvent(new Event('change', {bubbles:true}));
  }

  function applyLocation(loc){
    ensureOption(selRow, loc.row_code);
    ensureOption(selBay, loc.bay_num);
    ensureOption(selLvl, loc.level_code);
    ensureOption(selSide, loc.side, loc.side);
    inputLoc.value = loc.code;
    hideLoc();
    // focus SKU next
    const sku = document.getElementById('sku');
    if (sku) sku.focus();
  }

  function renderLoc(items){
    listLoc.innerHTML = '';
    idxLoc = -1;
    if (!items || !items.length) { hideLoc(); return; }
    const frag = document.createDocumentFragment();
    items.forEach((it, i) => {
      const div = document.createElement('div');
      div.className = 'ac-item';
      div.textContent = it.code;
      div.dataset.index = String(i);
      div.addEventListener('mousedown', (e)=>{ e.preventDefault(); applyLocation(items[i]); });
      frag.appendChild(div);
    });
    listLoc.appendChild(frag);
    showLoc();
  }

  function buildUrl(params){
    const url = new URL(window.location.href);
    for (const [k,v] of Object.entries(params)) {
      if (v === null) url.searchParams.delete(k);
      else url.searchParams.set(k, String(v));
    }
    return url.toString();
  }

  async function fetchSuggestLoc(q, my){
    const url = buildUrl({ suggest:'1', type:'loc', q, page:null });
    const res = await fetch(url, { headers: { 'Accept': 'application/json' }});
    if (!res.ok) throw new Error('HTTP '+res.status);
    const data = await res.json();
    if (my !== ticketLoc) return null;
    return Array.isArray(data.items) ? data.items : [];
  }

  if (inputLoc && listLoc && selRow && selBay && selLvl && selSide) {
    inputLoc.addEventListener('input', () => {
      clearTimeout(tLoc);
      const q = inputLoc.value.trim();
      if (!q) { hideLoc(); return; }
      const my = ++ticketLoc;
      tLoc = setTimeout(async () => {
        try {
          const items = await fetchSuggestLoc(q, my);
          if (my !== ticketLoc) return;
          renderLoc(items||[]);
        } catch (e) { console.error(e); hideLoc(); }
      }, 150);
    });

    inputLoc.addEventListener('focus', () => {
      const q = inputLoc.value.trim();
      const my = ++ticketLoc;
      fetchSuggestLoc(q, my).then(items => {
        if (my !== ticketLoc) return;
        renderLoc(items||[]);
      }).catch(()=>hideLoc());
    });

    inputLoc.addEventListener('blur', () => setTimeout(hideLoc, 150));

    inputLoc.addEventListener('keydown', (e) => {
      if (listLoc.style.display === 'none') return;
      const items = Array.from(listLoc.querySelectorAll('.ac-item'));
      if (!items.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        idxLoc = (idxLoc + 1) % items.length;
        items.forEach(el => el.classList.remove('active'));
        items[idxLoc].classList.add('active');
        items[idxLoc].scrollIntoView({block:'nearest'});
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        idxLoc = (idxLoc - 1 + items.length) % items.length;
        items.forEach(el => el.classList.remove('active'));
        items[idxLoc].classList.add('active');
        items[idxLoc].scrollIntoView({block:'nearest'});
      } else if (e.key === 'Enter') {
        if (idxLoc >= 0) {
          e.preventDefault();
          const code = items[idxLoc].textContent || '';
          const parts = code.split('-');
          if (parts.length === 4) {
            applyLocation({ code, row_code: parts[0], bay_num: parts[1], level_code: parts[2], side: parts[3] });
          }
        }
      } else if (e.key === 'Escape') {
        hideLoc();
      }
    });
  }

  // ====== SKU TYPEAHEAD ======
  // SKU field now uses shared autosuggest.js; no extra JS needed here.
})();
</script>
HTML;

require __DIR__ . '/templates/layout.php';
