<?php
// warehouse_module_base.php — Minimal base for warehouse functionality
// ----------------------------------------------------------------------------
// Includes session management, database includes, and user authentication checks.
// ----------------------------------------------------------------------------

declare(strict_types=1);
session_start();

// --- Include Statements ---
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/inventory_ops.php';
require_once __DIR__ . '/utils/helpers.php';

// --- Configuration Variables ---
$OPENAI_KEY = (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '')
    ? OPENAI_API_KEY
    : (getenv('OPENAI_API_KEY') ?: '');

// ---- auth ----
if (!isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

$username = $_SESSION['username'];
$user_id  = $_SESSION['user_id'];
$role_id  = $_SESSION['role_id'] ?? 0;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn->set_charset('utf8mb4');
} catch (\Throwable $_) {
}

// Restrict access based on role (admins/managers/analysts: 1, 2, 3)
$can_use_ai = in_array($role_id, [1, 2, 3], true);
if (!$can_use_ai) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// --- Mock / Sample Data (replace with real DB calls) ---
$reorder_items = [
    [
        'id' => 101,
        'name' => 'TPBC Shipping Labels (4x6)',
        'type' => 'UNIT',
        'current_qty' => 50,
        'min_stock' => 100,
        'last_count' => '2025-10-20',
        'total_units' => null,
        'reorder' => 'YES'
    ],
    [
        'id' => 205,
        'name' => 'Small Corrugated Boxes (8x6x4)',
        'type' => 'BOX',
        'current_qty' => 5, // bundles
        'min_stock' => 10,
        'last_count' => '2025-10-25',
        'total_units' => 250, // 5 bundles * 50 boxes/bundle
        'reorder' => 'YES'
    ],
];

$inventory_data = array_merge($reorder_items, [
    [
        'id' => 312,
        'name' => 'Packing Tape (Clear)',
        'type' => 'UNIT',
        'current_qty' => 300,
        'min_stock' => 50,
        'last_count' => '2025-10-28',
        'total_units' => null,
        'reorder' => 'NO'
    ],
    [
        'id' => 450,
        'name' => 'Large Pallet Wrap (Industrial)',
        'type' => 'UNIT',
        'current_qty' => 20,
        'min_stock' => 10,
        'last_count' => '2025-10-26',
        'total_units' => null,
        'reorder' => 'NO'
    ],
    [
        'id' => 501,
        'name' => 'Medium Mailing Envelopes',
        'type' => 'BOX',
        'current_qty' => 50,    // bundles
        'min_stock' => 20,      // bundles
        'last_count' => '2025-10-27',
        'total_units' => 5000,  // 50 bundles * 100 envelopes/bundle
        'reorder' => 'NO'
    ],
]);

// 3. Variables for the Lazy-Loading Footer JS
$PAGE_SIZE  = 50; // rows per AJAX request
$totalCount = 120;

// ---------- Page defaults ----------
$title      = $title      ?? 'TPB';
$page_class = $page_class ?? '';
$content    = $content    ?? '';
$css        = $css        ?? [];
$js         = $js         ?? [];
$footer_js  = $footer_js  ?? '';

// ---------- Overwrite defaults for this page ----------
$title   = 'Warehouse Supplies Manager';
$page_class = 'page-warehouse';
$js[]   = '/js/scan-drawer.js';
$js[]   = '/js/reorder.js'; // <-- ADD THIS LINE
$css[]   = '/refactor_css/table-sorting.css';
$css[]   = '/refactor_css/folder-menu.css';

// ---------- Compute BASE_URL for asset paths ----------
$script_dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/dev/utils/warehouse_supplies_manager.php');
if (basename($script_dir) === 'utils') {
    $BASE_URL = rtrim(dirname($script_dir), '/');
} else {
    $BASE_URL = rtrim($script_dir, '/');
}
if ($BASE_URL === '') {
    $BASE_URL = '/';
}



// Which tab/view?
$view = strtolower($_GET['view'] ?? 'supplies'); // 'supplies' | 'boxes'
$isSupplies = ($view === 'supplies');
$isBoxes    = ($view === 'boxes');

// Table name
$TABLE_STOCK = 'Warehouse_Current_Stock';

// WHERE clause
$where = $isSupplies
    ? "item_type = 'SUPPLY'"
    : "item_type LIKE '%BOX%'";

// Fetch filtered rows
$rows = [];
try {
    $sql = "
        SELECT
            stock_id,
            item_name,
            item_type,
            qty_current_stock,
            qty_per_bundle,
            total_units,
            min_stock_level,
            reorder_flag,
            last_count_date,
            reference_note,
            uline_link_text
        FROM `{$TABLE_STOCK}`
        WHERE {$where}
        ORDER BY item_name
    ";
    if ($res = $conn->query($sql)) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $res->free();
    }
} catch (\Throwable $e) {
    error_log('[Warehouse_Current_Stock filter] ' . $e->getMessage());
}

// Map to your UI structure
$inventory_data = array_map(static function(array $r) {
    $isBox = stripos($r['item_type'] ?? '', 'BOX') !== false;

    return [
        'id'             => (int)$r['stock_id'],
        'name'           => (string)$r['item_name'],
        'type'           => (string)$r['item_type'],                 // SUPPLY or *BOX*
        'current_qty'    => (int)$r['qty_current_stock'],            // Units or Bundles (if box)
        'min_stock'      => isset($r['min_stock_level']) ? (int)$r['min_stock_level'] : 0,
        'last_count'     => (string)$r['last_count_date'],
        'total_units'    => $isBox ? (int)$r['total_units'] : null,  // Show only for boxes
        'reorder'        => strtoupper((string)($r['reorder_flag'] ?? 'NO')) === 'YES' ? 'YES' : 'NO',
        // Optional extras you might display later:
        'qty_per_bundle' => $isBox ? (int)($r['qty_per_bundle'] ?? 0) : null,
        'note'           => (string)($r['reference_note'] ?? ''),
        'uline'          => (string)($r['uline_link_text'] ?? ''),
    ];
}, $rows);

// Tab badge counts (optional)
$count_supplies = $count_boxes = null;
try {
    $c = $conn->query("SELECT COUNT(*) AS c FROM `{$TABLE_STOCK}` WHERE item_type='SUPPLY'")->fetch_assoc();
    $count_supplies = (int)($c['c'] ?? 0);
    $c = $conn->query("SELECT COUNT(*) AS c FROM `{$TABLE_STOCK}` WHERE item_type LIKE '%BOX%'")->fetch_assoc();
    $count_boxes = (int)($c['c'] ?? 0);
} catch (\Throwable $_) { /* ignore */ 

}
// ---------- Build the page body ----------


ob_start();
?>


  <div class="folder-content-wrapper">
    <div class="folder-content-base">
      <h2>Warehouse Supplies Manager</h2>
      <p class="muted">Stock levels, reorder alerts, and management for TPBC Warehouse.</p>

      <section class="card is-error mb-4">
        <h2>🚨 Items to Reorder (<?= count($reorder_items) ?>)</h2>
        <div class="table-wrap">
          <table class="sortable">
            <thead>
              <tr>
                <th>Item / Size</th>
                <th>Type</th>
                <th>Current Stock</th>
                <th>Min Stock</th>
                <th>Last Count</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($reorder_items)): ?>
                <tr>
                  <td colspan="6" class="text-center">🎉 All essential stock levels are currently met!</td>
                </tr>
              <?php else: ?>
                <?php foreach ($reorder_items as $item):
                    $stock_unit = ($item['type'] === 'BOX') ? ' Bundle(s)' : ' Unit(s)';
                    $total_units_display = ($item['type'] === 'BOX')
                        ? ' (' . (int)$item['total_units'] . ' total units)'
                        : '';
                ?>
                  <tr class="is-danger">
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td><?= htmlspecialchars($item['type']) ?></td>
                    <td><strong><?= (int)$item['current_qty'] ?></strong><?= $stock_unit ?><?= $total_units_display ?></td>
                    <td><?= (int)$item['min_stock'] . $stock_unit ?></td>
                    <td><?= htmlspecialchars($item['last_count']) ?></td>
                    <td><button class="btn btn-sm btn-primary">Order</button></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="card">
  <h2>📦 Full Inventory</h2>

  <nav class="folder-menu tabs-full">
    <div class="folder-tab <?= $isSupplies ? 'active' : '' ?>">
      <a href="?view=supplies" class="tab-link"><i class="icon icon-home"></i>
        Office Supplies<?= is_int($count_supplies) ? ' (' . (int)$count_supplies . ')' : '' ?>
      </a>
    </div>
    <div class="folder-tab <?= $isBoxes ? 'active' : '' ?>">
      <a href="?view=boxes" class="tab-link"><i class="icon icon-archive"></i>
        Boxes<?= is_int($count_boxes) ? ' (' . (int)$count_boxes . ')' : '' ?>
      </a>
    </div>
    </nav>

  <div class="actions-row">
    <button type="button" class="btn btn-sm" id="openReorderBtn">
      Items to Reorder
      <span id="reorderCountBadge" class="badge is-muted" style="margin-left:.5rem;">0</span>
    </button>
  </div>

  <div class="table-wrap" id="inventoryTableWrap">
    <table class="sortable" id="inventoryTable">
      <thead>
        <tr>
          <th style="width:52px;">Select</th>
          <th>Item / Size</th>
          <th>Type</th>
          <th>Current Stock</th>
          <th>Min Stock</th>
          <th>Last Count</th>
          <th>Reorder?</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($inventory_data)): ?>
          <tr><td colspan="7" class="text-center">No items found for this filter.</td></tr>
        <?php else: ?>
          <?php foreach ($inventory_data as $item):
              $isBox = stripos($item['type'], 'BOX') !== false;
              $stock_unit = $isBox ? ' Bundle(s)' : ' Unit(s)';
              $total_units_display = ($isBox && $item['total_units'])
                  ? ' (' . (int)$item['total_units'] . ' total units)'
                  : '';
              $reorder_class = ($item['reorder'] === 'YES') ? ' class="is-danger"' : '';
              $defaultOrderQty = max(1, (int)($item['min_stock'] - $item['current_qty']));
          ?>
            <tr<?= $reorder_class ?>
                data-stock-id="<?= (int)$item['id'] ?>"
                data-item-name="<?= htmlspecialchars($item['name']) ?>"
                data-item-type="<?= htmlspecialchars($item['type']) ?>"
                data-current="<?= (int)$item['current_qty'] ?>"
                data-min="<?= (int)$item['min_stock'] ?>"
                data-default-order="<?= (int)$defaultOrderQty ?>">
              <td>
                <input type="checkbox" class="reorder-select" />
              </td>
              <td><?= htmlspecialchars($item['name']) ?></td>
              <td><?= htmlspecialchars($item['type']) ?></td>
              <td><?= (int)$item['current_qty'] . $stock_unit . $total_units_display ?></td>
              <td><?= (int)$item['min_stock'] . $stock_unit ?></td>
              <td><?= htmlspecialchars($item['last_count']) ?></td>
              <td>
                <span class="badge <?= ($item['reorder'] === 'YES') ? 'is-danger' : 'is-success' ?>">
                  <?= htmlspecialchars($item['reorder']) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <dialog id="reorderWindow" class="inventory-window">
    <header class="flex items-center justify-between mb-2">
      <h3 class="m-0">Items to Reorder</h3>
      <div class="flex gap-2">
        <form id="reorderExportForm" action="reorder_preview.php" method="post" target="_blank" style="display:inline;">
          <input type="hidden" name="reorder_json" id="reorderJsonField" value="">
          <button type="submit" class="btn btn-sm btn-success" id="exportCsvBtn" disabled>Export CSV</button>
        </form>
        <button class="btn btn-sm" id="closeReorderBtn">Close</button>
      </div>
    </header>

    <div id="reorderEmpty" class="muted" style="padding:.75rem 0; display:none;">
      No items selected yet. Check the boxes in the Full Inventory table to add items here.
    </div>

    <div class="table-wrap" id="reorderTableWrap" style="display:none;">
      <table class="sortable" id="reorderTable">
        <thead>
          <tr>
            <th style="width:80px;">Stock ID</th>
            <th>Item</th>
            <th style="width:120px;">Type</th>
            <th style="width:120px;">On Hand</th>
            <th style="width:120px;">Min</th>
            <th style="width:160px;">Qty to Order</th>
            <th style="width:80px;">Remove</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
  </dialog>
</section>

    </div>
  </div>
<?php
$content = ob_get_clean();


// ---------- Footer JS ----------
$footer_js = <<<HTML
<script>
// Any future lazy-load or pagination JS could go here, or in another file.

</script>
HTML;


include __DIR__ . '/templates/layout.php';
