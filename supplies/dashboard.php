<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/lib/stock_levels.php';

if (!isset($_SESSION['username'])) {
  header('Location: ../login.php');
  exit;
}

$username = $_SESSION['username'];
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$role_id = $_SESSION['role_id'] ?? 0;

require_once __DIR__ . '/templates/access_control.php';

if (!$conn) {
  die("Connection failed: " . mysqli_connect_error());
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$title = 'Supplies - Request';
$page_class = 'page-supplies';

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Create the request tables on first use (keep in sync with sql/2026_10_07_create_supply_requests.sql)
$setup_error = null;
try {
  $conn->query("
        CREATE TABLE IF NOT EXISTS supply_requests (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          requested_by_user_id INT NOT NULL DEFAULT 0,
          requested_by_username VARCHAR(255) NOT NULL DEFAULT '',
          note TEXT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_supply_requests_user (requested_by_user_id),
          KEY idx_supply_requests_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
  $conn->query("
        CREATE TABLE IF NOT EXISTS supply_request_items (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          request_id BIGINT UNSIGNED NOT NULL,
          supply_id INT NULL,
          item_name VARCHAR(255) NOT NULL,
          qty INT UNSIGNED NOT NULL DEFAULT 1,
          note VARCHAR(255) NULL,
          PRIMARY KEY (id),
          KEY idx_supply_request_items_request_id (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
  // Early version of the table keyed lines by stock_id
  $legacy = $conn->query("
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supply_request_items' AND COLUMN_NAME = 'stock_id'
    ");
  if ($legacy->fetch_row()) {
    $conn->query("ALTER TABLE supply_request_items CHANGE stock_id supply_id INT NULL");
  }
  // Per-line notes were added after the table was first created
  $has_note = $conn->query("
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supply_request_items' AND COLUMN_NAME = 'note'
    ");
  if (!$has_note->fetch_row()) {
    $conn->query("ALTER TABLE supply_request_items ADD COLUMN note VARCHAR(255) NULL AFTER qty");
  }
} catch (\Throwable $e) {
  error_log('[supply_requests ensure] ' . $e->getMessage());
  $setup_error = $e->getMessage();
}

// Catalogue of requestable items
$catalog = [];
try {
  $res = $conn->query("
        SELECT supply_id, supply_category, model_no, supply_name, inside_dimensions
        FROM warehouse_supplies
        ORDER BY supply_category, model_no
    ");
  while ($r = $res->fetch_assoc()) {
    $label = implode(' — ', array_filter([trim((string) $r['model_no']), trim((string) $r['supply_name'])]));
    if (trim((string) $r['inside_dimensions']) !== '') {
      $label .= ' (' . trim((string) $r['inside_dimensions']) . ')';
    }
    $catalog[(int) $r['supply_id']] = [
      'name' => $label,
      'type' => trim((string) $r['supply_category']) ?: 'Uncategorized',
    ];
  }
  $res->free();
} catch (\Throwable $e) {
  error_log('[supply_requests catalog] ' . $e->getMessage());
}

// Prefill from the Stock on Hand "Request" button
$prefill_id = (int) ($_GET['supply_id'] ?? 0);
if (!isset($catalog[$prefill_id])) {
  $prefill_id = 0;
}
$prefill_qty = $prefill_id ? max(1, (int) ($_GET['qty'] ?? 1)) : 1;

// Request type (?type=boxes|supplies). No type shows the landing tiles;
// a prefilled item picks its own type.
$type = (string) ($_GET['type'] ?? '');
if (!isset(SOH_REQUEST_TYPES[$type])) {
  $type = $prefill_id ? soh_request_type($catalog[$prefill_id]['type']) : '';
}
$return_url = 'dashboard.php' . ($type !== '' ? '?type=' . $type : '');

// Handle submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  app_require_csrf();

  // Clear one or all of the current user's requests
  $action = (string) ($_POST['action'] ?? '');
  if ($action === 'delete_request' || $action === 'clear_requests') {
    try {
      $conn->begin_transaction();
      if ($action === 'delete_request') {
        $request_id = (int) ($_POST['request_id'] ?? 0);
        $where = 'id = ? AND requested_by_user_id = ?';
        $types = 'ii';
        $params = [$request_id, $user_id];
      } else {
        $where = 'requested_by_user_id = ?';
        $types = 'i';
        $params = [$user_id];
      }
      $stmt = $conn->prepare("
                DELETE i FROM supply_request_items i
                JOIN supply_requests r ON r.id = i.request_id
                WHERE r.{$where}
            ");
      $stmt->bind_param($types, ...$params);
      $stmt->execute();
      $stmt = $conn->prepare("DELETE FROM supply_requests WHERE {$where}");
      $stmt->bind_param($types, ...$params);
      $stmt->execute();
      $deleted = $stmt->affected_rows;
      $conn->commit();
      $_SESSION['flash'] = $action === 'delete_request'
        ? ($deleted ? "Request #{$request_id} deleted." : 'Request not found.')
        : "Cleared {$deleted} request(s).";
    } catch (\Throwable $e) {
      $conn->rollback();
      error_log('[supply_requests delete] ' . $e->getMessage());
      $_SESSION['error'] = 'Could not clear requests. Please try again.';
    }
    header('Location: ' . $return_url, true, 303);
    exit;
  }

  $stockIds = (array) ($_POST['stock_id'] ?? []);
  $otherNames = (array) ($_POST['other_name'] ?? []);
  $qtys = (array) ($_POST['qty'] ?? []);
  $lineNotes = (array) ($_POST['line_note'] ?? []);
  $note = trim((string) ($_POST['note'] ?? ''));

  $lines = [];
  foreach ($stockIds as $i => $sid) {
    $sid = (string) $sid;
    $qty = (int) ($qtys[$i] ?? 0);
    $lineNote = mb_substr(trim((string) ($lineNotes[$i] ?? '')), 0, 255);
    if ($sid === '' || $qty <= 0) {
      continue;
    }
    if ($sid === 'other') {
      $name = trim((string) ($otherNames[$i] ?? ''));
      if ($name !== '') {
        $lines[] = [null, mb_substr($name, 0, 255), $qty, $lineNote];
      }
    } elseif (isset($catalog[(int) $sid])) {
      $lines[] = [(int) $sid, $catalog[(int) $sid]['name'], $qty, $lineNote];
    }
  }

  if (!$lines) {
    $_SESSION['error'] = 'Add at least one item with a quantity.';
  } else {
    try {
      $conn->begin_transaction();
      $stmt = $conn->prepare("
                INSERT INTO supply_requests (requested_by_user_id, requested_by_username, note)
                VALUES (?, ?, ?)
            ");
      $stmt->bind_param('iss', $user_id, $username, $note);
      $stmt->execute();
      $request_id = (int) $conn->insert_id;

      $stmt = $conn->prepare("
                INSERT INTO supply_request_items (request_id, supply_id, item_name, qty, note)
                VALUES (?, ?, ?, ?, NULLIF(?, ''))
            ");
      foreach ($lines as [$sid, $name, $qty, $lineNote]) {
        $stmt->bind_param('iisis', $request_id, $sid, $name, $qty, $lineNote);
        $stmt->execute();
      }
      $conn->commit();
      $_SESSION['flash'] = "Supply request #{$request_id} submitted.";
    } catch (\Throwable $e) {
      $conn->rollback();
      error_log('[supply_requests insert] ' . $e->getMessage());
      $_SESSION['error'] = 'Could not save your request. Please try again.';
      if ((int) $role_id === 1) { // admins see the database error
        $_SESSION['error'] .= ' (' . $e->getMessage() . ($setup_error ? '; setup: ' . $setup_error : '') . ')';
      }
    }
  }

  header('Location: ' . $return_url, true, 303);
  exit;
}

$flash = $_SESSION['flash'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['flash'], $_SESSION['error']);

// This user's recent requests
$my_requests = [];
try {
  $stmt = $conn->prepare("
        SELECT r.id, r.note, r.created_at,
               GROUP_CONCAT(
                 CONCAT(i.qty, ' x ', i.item_name, IF(COALESCE(i.note, '') <> '', CONCAT(' (', i.note, ')'), ''))
                 ORDER BY i.id SEPARATOR '\n'
               ) AS items
        FROM supply_requests r
        JOIN supply_request_items i ON i.request_id = r.id
        WHERE r.requested_by_user_id = ?
        GROUP BY r.id
        ORDER BY r.id DESC
        LIMIT 10
    ");
  $stmt->bind_param('i', $user_id);
  $stmt->execute();
  $my_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (\Throwable $e) {
  error_log('[supply_requests history] ' . $e->getMessage());
}

// Stock levels for the "View stock" window, plus when they were last updated from parsed orders
$stock = soh_load_stock_levels($conn);
$update_history = $type !== '' ? soh_load_update_history($conn) : [];

// Favorites: the items requested most often (all users) for the chosen type
$favorites = [];
if ($type !== '') {
    $stock_by_id = [];
    foreach ($stock['groups'] as $items) {
        foreach ($items as $item) {
            $stock_by_id[$item['supply_id']] = $item;
        }
    }
    try {
        $res = $conn->query("
            SELECT i.supply_id,
                   COUNT(*) AS times,
                   MAX(r.created_at) AS last_requested,
                   SUBSTRING_INDEX(GROUP_CONCAT(i.qty ORDER BY r.id DESC), ',', 1) AS last_qty
            FROM supply_request_items i
            JOIN supply_requests r ON r.id = i.request_id
            WHERE i.supply_id IS NOT NULL
            GROUP BY i.supply_id
            ORDER BY times DESC, last_requested DESC
            LIMIT 100
        ");
        while ($r = $res->fetch_assoc()) {
            $sid = (int) $r['supply_id'];
            if (!isset($catalog[$sid]) || soh_request_type($catalog[$sid]['type']) !== $type) {
                continue;
            }
            $favorites[] = [
                'supply_id' => $sid,
                'name' => $catalog[$sid]['name'],
                'times' => (int) $r['times'],
                'last_qty' => max(1, (int) $r['last_qty']),
                'last_requested' => substr((string) $r['last_requested'], 0, 10),
                'status' => $stock_by_id[$sid]['status'] ?? 'untracked',
                'on_hand' => $stock_by_id[$sid]['on_hand'] ?? null,
            ];
            if (count($favorites) >= 12) {
                break;
            }
        }
        $res->free();
    } catch (\Throwable $e) {
        error_log('[supply_requests favorites] ' . $e->getMessage());
    }
}

// Landing tile counts per request type
$type_stats = array_fill_keys(array_keys(SOH_REQUEST_TYPES), ['items' => 0, 'low' => 0, 'zero' => 0]);
foreach ($stock['groups'] as $category => $items) {
  $t = soh_request_type($category);
  foreach ($items as $item) {
    $type_stats[$t]['items']++;
    if ($item['status'] === 'low' || $item['status'] === 'zero') {
      $type_stats[$t][$item['status']]++;
    }
  }
}

// Stock window only shows the chosen type, so "Add" always matches an option in the form
$stock_groups = array_filter(
  $stock['groups'],
  static fn($category) => soh_request_type((string) $category) === $type,
  ARRAY_FILTER_USE_KEY
);

// Build <option> list grouped by category (chosen type only)
$options_html = '<option value="">Select an item…</option>';
$current_type = null;
foreach ($catalog as $sid => $item) {
  if (soh_request_type($item['type']) !== $type) {
    continue;
  }
  if ($item['type'] !== $current_type) {
    if ($current_type !== null) {
      $options_html .= '</optgroup>';
    }
    $current_type = $item['type'];
    $options_html .= '<optgroup label="' . $h(soh_category_label($current_type)) . '">';
  }
  $options_html .= '<option value="' . $sid . '"' . ($sid === $prefill_id ? ' selected' : '') . '>'
    . $h($item['name']) . '</option>';
}
if ($current_type !== null) {
  $options_html .= '</optgroup>';
}
$options_html .= '<option value="other">Other (not listed)…</option>';

// Landing tile artwork (inline SVG, so no image uploads or CSP changes are needed)
$TILE_ART = [
  'boxes' => '<svg viewBox="0 0 120 100" role="img"><path d="M20 38 60 22l40 16v40L60 94 20 78Z" fill="#d9a066"/><path d="M20 38l40 16 40-16" fill="none" stroke="#a8743f" stroke-width="2"/><path d="M60 54v40" stroke="#a8743f" stroke-width="2"/><path d="M20 38 6 52l40 16 14-14Z" fill="#e6b67f"/><path d="M100 38l14 14-40 16-14-14Z" fill="#c98f52"/><path d="M40 30l40 16" stroke="#f3d3a8" stroke-width="6" stroke-linecap="round"/></svg>',
  'supplies' => '<svg viewBox="0 0 120 100" role="img"><rect x="30" y="18" width="7" height="44" rx="2" fill="#2563eb" transform="rotate(-10 33 40)"/><rect x="44" y="12" width="7" height="50" rx="2" fill="#dc2626"/><rect x="57" y="16" width="7" height="46" rx="2" fill="#16a34a" transform="rotate(6 60 40)"/><path d="M47 12l2-8 2 8Z" fill="#334155"/><circle cx="80" cy="26" r="7" fill="none" stroke="#475569" stroke-width="3"/><circle cx="90" cy="34" r="7" fill="none" stroke="#475569" stroke-width="3"/><path d="M84 31 70 58M86 29 76 58" stroke="#94a3b8" stroke-width="3"/><path d="M24 52h72l-6 40H30Z" fill="#334155"/><path d="M30 60h60M32 70h56M34 80h52" stroke="#64748b" stroke-width="2"/></svg>',
];

ob_start();
?>

<div class="supplies-dashboard">
  <div class="supplies-grid-shell">
    <section class="card card--wide supplies-card">
      <nav class="request-crumbs" aria-label="Request steps">
        <?php if ($type === ''): ?>
          <span aria-current="page">Supplies Request</span>
        <?php else: ?>
          <a href="dashboard.php">Supplies Request</a>
          <span aria-hidden="true">›</span>
          <span aria-current="page"><?= $h(SOH_REQUEST_TYPES[$type]) ?></span>
        <?php endif; ?>
      </nav>

      <?php if ($flash): ?>
        <div class="alert alert--info mb-3"><?= $h($flash) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert--error mb-3"><?= $h($error) ?></div>
      <?php endif; ?>

      <?php if ($type === ''): ?>
        <h2>What do you need?</h2>
        <div class="request-tiles">
          <?php foreach (SOH_REQUEST_TYPES as $key => $label):
            $stats = $type_stats[$key];
            ?>
            <a class="request-tile" href="dashboard.php?type=<?= $key ?>">
              <span class="request-tile__art" aria-hidden="true"><?= $TILE_ART[$key] ?></span>
              <span class="request-tile__label">Request <?= $h($label) ?></span>
              <span class="request-tile__meta">
                <?= (int) $stats['items'] ?> items
                <?php if ($stats['low']): ?> · <span class="request-tile__low"><?= (int) $stats['low'] ?>
                    low</span><?php endif; ?>
                <?php if ($stats['zero']): ?> · <span class="request-tile__zero"><?= (int) $stats['zero'] ?>
                    out</span><?php endif; ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else:
        $other_type = $type === 'boxes' ? 'supplies' : 'boxes';
        ?>
        <div class="flex items-center justify-between request-form-head">
          <h2>Request <?= $h(SOH_REQUEST_TYPES[$type]) ?></h2>
          <div class="flex items-center g-2">
            <a class="btn btn-sm btn--ghost" href="dashboard.php?type=<?= $other_type ?>">Switch to
              <?= $h(SOH_REQUEST_TYPES[$other_type]) ?></a>
            <button type="button" class="btn btn-sm btn-outline" id="openStockBtn">View stock</button>
          </div>
        </div>

        <form method="post" class="form" id="supplyRequestForm">
          <input type="hidden" name="csrf_token" value="<?= $h(app_csrf_token()) ?>">

          <div class="table-wrap">
            <table class="table spaced-table" id="requestLines">
              <thead>
                <tr>
                  <th class="col-item">Item</th>
                  <th class="col-qty">Qty</th>
                  <th class="col-note">Note</th>
                  <th class="col-delete"><span class="sr-only">&times;</span></th>
                </tr>
              </thead>
              <tbody>
                <tr class="request-line">
                  <td class="col-item">
                    <select name="stock_id[]" class="line-item" required aria-label="Item"><?= $options_html ?></select>
                    <input type="text" name="other_name[]" class="line-other" maxlength="255"
                      placeholder="Describe the item" style="display:none; margin-top:.4rem;">
                  </td>
                  <td class="col-qty"><input type="number" name="qty[]" min="1" value="<?= (int) $prefill_qty ?>" required
                      aria-label="Quantity"></td>
                  <td class="col-note"><input type="text" name="line_note[]" class="line-note" maxlength="255"
                      placeholder="Where it's needed, urgency, etc." aria-label="Note"></td>
                  <td class="col-delete"><button type="button" class="btn btn-sm btn-outline line-remove">&times;</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="form-group">
            <?php /* One item per request for now (select item >> submit). Restore to allow multi-line requests.
            <p><button type="button" class="btn btn-sm btn-outline" id="addLineBtn">+ Add another item</button></p>
            */ ?>
            <p><button type="submit" class="btn btn-primary">Submit Request</button></p>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <section class="card card--wide supplies-card">
      <div class="flex items-center justify-between">
        <h3>Recent Requests</h3>
        <div class="flex items-center g-2">
          <?php if ($type !== ''): /* favorites fill the request form, which only exists on a type page */ ?>
            <button type="button" class="btn btn-sm btn-outline" id="openFavoritesBtn">Favorites</button>
          <?php endif; ?>
          <?php if ($my_requests): ?>
            <form method="post" data-confirm="Clear all of your supply requests? This can't be undone.">
              <input type="hidden" name="csrf_token" value="<?= $h(app_csrf_token()) ?>">
              <input type="hidden" name="action" value="clear_requests">
              <button type="submit" class="btn btn-sm btn-danger">Clear all</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!$my_requests): ?>
        <div class="note">You haven't submitted any supply requests yet.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table spaced-table js-sortable">
            <thead>
              <tr>
                <th data-sort="text" aria-sort="descending">Submitted</th>
                <th data-sort="text">Items</th>
                <th style="width:80px;"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($my_requests as $req): ?>
                <tr>
                  <td><?= $h($req['created_at']) ?></td>
                  <td>
                    <?= nl2br($h($req['items'])) ?>
                    <?php if (trim((string) ($req['note'] ?? '')) !== ''): ?>
                      <div class="muted request-note"><?= nl2br($h($req['note'])) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <form method="post" data-confirm="Delete the request from <?= $h($req['created_at']) ?>?">
                      <input type="hidden" name="csrf_token" value="<?= $h(app_csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete_request">
                      <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline">&times;</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php if ($type !== ''): ?>
  <dialog id="stockWindow" class="stock-window" aria-labelledby="stockWindowTitle">
    <header class="stock-window__header">
      <h3 id="stockWindowTitle">Stock Inventory</h3>
      <button type="button" class="btn btn-sm btn--ghost" id="closeStockBtn" aria-label="Close">×</button>
    </header>

    <div class="stock-window__summary muted">
      <?= (int) $stock['summary']['skus'] ?> SKUs ·
      <?= (int) $stock['summary']['low'] ?> low ·
      <?= (int) $stock['summary']['zero'] ?> out
    </div>

    <div class="stock-window__updated">
      <?php if ($update_history):
        $latest = $update_history[0];
        ?>
        <div>
          <strong>Last updated:</strong>
          <?= $h($latest['posted_at'] !== '' ? substr($latest['posted_at'], 0, 16) : $latest['received']) ?>
          from parsed order, invoice <?= $h($latest['invoice']) ?>
          (<?= (int) $latest['items'] ?> item<?= $latest['items'] === 1 ? '' : 's' ?>)
        </div>
        <details class="stock-history">
          <summary>Update history</summary>
          <div class="table-wrap">
            <table class="table spaced-table">
              <thead>
                <tr>
                  <th>Updated</th>
                  <th>Invoice</th>
                  <th>Received</th>
                  <th class="num">Items</th>
                  <th class="num">Qty</th>
                  <th>By</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($update_history as $u): ?>
                  <tr>
                    <td><?= $h($u['posted_at'] !== '' ? substr($u['posted_at'], 0, 16) : '—') ?></td>
                    <td><?= $h($u['invoice']) ?></td>
                    <td><?= $h($u['received']) ?></td>
                    <td class="num"><?= (int) $u['items'] ?></td>
                    <td class="num"><?= $u['qty'] === null ? '—' : (int) $u['qty'] ?></td>
                    <td><?= $h($u['imported_by'] !== '' ? $u['imported_by'] : '—') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php else: ?>
        <span class="muted">No parsed orders have updated stock yet.</span>
      <?php endif; ?>
    </div>

    <div class="stock-window__filters">
      <input type="search" id="stockSearch" placeholder="Search model, item or size…" autocomplete="off">
      <label><input type="checkbox" id="stockLowOnly"> Low / out only</label>
    </div>

    <?php if ($stock['error']): ?>
      <div class="alert alert--error"><?= $h($stock['error']) ?></div>
    <?php endif; ?>

    <div class="table-wrap stock-window__body">
      <table class="table soh-table spaced-table js-sortable" id="stockTable">
        <thead>
          <tr>
            <th data-sort="text">Model</th>
            <th data-sort="text">Item</th>
            <th data-sort="text">Dimensions</th>
            <th class="num" data-sort="number">On hand</th>
            <th></th>
          </tr>
        </thead>
        <?php foreach ($stock_groups as $category => $items): ?>
          <tbody class="stock-group">
            <tr class="stock-group__header sort-pinned">
              <th colspan="5"><?= $h(soh_category_label((string) $category)) ?></th>
            </tr>
            <?php foreach ($items as $item):
              $add_qty = in_array($item['status'], ['low', 'zero'], true) ? $item['request_qty'] : 1;
              ?>
              <tr class="stock-row" data-status="<?= $h($item['status']) ?>"
                data-search="<?= $h(strtolower($item['model_no'] . ' ' . $item['name'] . ' ' . $item['dims'])) ?>">
                <td><?= $h($item['model_no']) ?></td>
                <td><?= $h($item['name']) ?></td>
                <td><?= $h($item['dims']) ?></td>
                <td class="num" data-sort-value="<?= $item['on_hand'] === null ? '' : (int) $item['on_hand'] ?>">
                  <?= $item['on_hand'] === null ? '<span class="muted">—</span>' : (int) $item['on_hand'] ?>
                </td>
                <td>
                  <button type="button" class="btn btn-sm btn-primary stock-add"
                    data-supply-id="<?= (int) $item['supply_id'] ?>" data-qty="<?= (int) $add_qty ?>">Add</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        <?php endforeach; ?>
      </table>
      <div class="note" id="stockNoMatch" hidden>No items match.</div>
    </div>
  </dialog>

  <dialog id="favoritesWindow" class="stock-window favorites-window" aria-labelledby="favoritesWindowTitle">
    <header class="stock-window__header">
      <h3 id="favoritesWindowTitle">Favorite <?= $h(SOH_REQUEST_TYPES[$type]) ?></h3>
      <button type="button" class="btn btn-sm btn--ghost" id="closeFavoritesBtn" aria-label="Close">×</button>
    </header>

    <p class="stock-window__summary muted">The items requested most often. Pick one to fill in your request.</p>

    <div class="stock-window__body">
      <?php if (!$favorites): ?>
        <div class="note">No <?= $h(strtolower(SOH_REQUEST_TYPES[$type])) ?> have been requested yet. Items you request most will show up here.</div>
      <?php else: ?>
        <ul class="favorites-list">
          <?php foreach ($favorites as $fav):
            [$badge_class, $badge_label] = SOH_STATUS_BADGES[$fav['status']];
            ?>
            <li class="favorites-item">
              <div class="favorites-item__main">
                <span class="favorites-item__name"><?= $h($fav['name']) ?></span>
                <span class="favorites-item__meta muted">
                  Requested <?= (int) $fav['times'] ?> time<?= $fav['times'] === 1 ? '' : 's' ?>
                  · last <?= $h($fav['last_requested']) ?>
                  · usual qty <?= (int) $fav['last_qty'] ?>
                </span>
              </div>
              <span class="badge <?= $badge_class ?>" title="<?= $fav['on_hand'] === null ? '' : (int) $fav['on_hand'] . ' on hand' ?>"><?= $badge_label ?></span>
              <button type="button" class="btn btn-sm btn-primary favorite-pick"
                data-supply-id="<?= (int) $fav['supply_id'] ?>" data-qty="<?= (int) $fav['last_qty'] ?>">Select</button>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </dialog>
<?php endif; ?>

<?php
$content = ob_get_clean();


$footer_js = <<<HTML
    <script nonce="{$cspNonceAttr}">
      (function() {
        document.querySelectorAll('form[data-confirm]').forEach((form) => {
          form.addEventListener('submit', (e) => {
            if (!window.confirm(form.dataset.confirm)) e.preventDefault();
          });
        });

        const tbody = document.querySelector('#requestLines tbody');
        if (!tbody) return; // landing page: no form
        const template = tbody.querySelector('.request-line').cloneNode(true);

        function syncOther(row) {
          const other = row.querySelector('.line-other');
          const isOther = row.querySelector('.line-item').value === 'other';
          other.style.display = isOther ? '' : 'none';
          other.required = isOther;
        }

        tbody.addEventListener('change', (e) => {
          if (e.target.classList.contains('line-item')) syncOther(e.target.closest('tr'));
        });

        tbody.addEventListener('click', (e) => {
          if (!e.target.classList.contains('line-remove')) return;
          const rows = tbody.querySelectorAll('.request-line');
          const row = e.target.closest('tr');
          if (rows.length > 1) {
            row.remove();
          } else { // last line: clear it instead of removing it
            row.querySelector('.line-item').value = '';
            row.querySelector('input[name="qty[]"]').value = 1;
            row.querySelector('.line-note').value = '';
            syncOther(row);
          }
        });

        function newLine() {
          const row = template.cloneNode(true);
          row.querySelector('.line-item').value = '';
          row.querySelector('input[name="qty[]"]').value = 1;
          row.querySelector('.line-note').value = '';
          tbody.appendChild(row);
          syncOther(row);
          return row;
        }

        // "+ Add another item" is commented out in the form; only wire it if it's there
        const addLineBtn = document.getElementById('addLineBtn');
        if (addLineBtn) {
          addLineBtn.addEventListener('click', () => {
            newLine().querySelector('.line-item').focus();
          });
        }

        // Put an item on the request form: reuse a line that already has it, or an empty one;
        // in single-item mode (no "+ Add another item") replace the one line.
        function fillLine(id, qty) {
          const lines = Array.from(tbody.querySelectorAll('.request-line'));
          const row = lines.find((r) => r.querySelector('.line-item').value === id)
            || lines.find((r) => r.querySelector('.line-item').value === '')
            || (addLineBtn ? newLine() : lines[0]);
          row.querySelector('.line-item').value = id;
          row.querySelector('input[name="qty[]"]').value = qty;
          syncOther(row);
          return row;
        }

        // ---- Favorites window ----
        const favoritesWindow = document.getElementById('favoritesWindow');
        document.getElementById('openFavoritesBtn').addEventListener('click', () => favoritesWindow.showModal());
        document.getElementById('closeFavoritesBtn').addEventListener('click', () => favoritesWindow.close());
        favoritesWindow.addEventListener('click', (e) => {
          if (e.target === favoritesWindow) { // backdrop click
            favoritesWindow.close();
            return;
          }
          const btn = e.target.closest('.favorite-pick');
          if (!btn) return;
          const row = fillLine(btn.dataset.supplyId, btn.dataset.qty);
          favoritesWindow.close();
          row.querySelector('input[name="qty[]"]').focus(); // ready to adjust qty, then submit
        });

        // ---- Stock window ----
        const stockWindow = document.getElementById('stockWindow');
        const search = document.getElementById('stockSearch');
        const lowOnly = document.getElementById('stockLowOnly');
        const noMatch = document.getElementById('stockNoMatch');

        document.getElementById('openStockBtn').addEventListener('click', () => {
          stockWindow.showModal();
          search.focus();
        });
        document.getElementById('closeStockBtn').addEventListener('click', () => stockWindow.close());
        stockWindow.addEventListener('click', (e) => {
          if (e.target === stockWindow) stockWindow.close(); // backdrop click
        });

        function filterStock() {
          const term = search.value.trim().toLowerCase();
          let shown = 0;
          stockWindow.querySelectorAll('.stock-group').forEach((group) => {
            let groupShown = 0;
            group.querySelectorAll('.stock-row').forEach((row) => {
              const status = row.dataset.status;
              const visible = (!term || row.dataset.search.includes(term))
                && (!lowOnly.checked || status === 'low' || status === 'zero');
              row.hidden = !visible;
              if (visible) groupShown++;
            });
            group.hidden = groupShown === 0;
            shown += groupShown;
          });
          noMatch.hidden = shown > 0;
        }
        search.addEventListener('input', filterStock);
        lowOnly.addEventListener('change', filterStock);

        // "Add" puts the item on the request form (reusing a line that already has it, or an empty one)
        stockWindow.addEventListener('click', (e) => {
          const btn = e.target.closest('.stock-add');
          if (!btn) return;
          fillLine(btn.dataset.supplyId, btn.dataset.qty);
          btn.textContent = 'Added';
          btn.disabled = true;
          setTimeout(() => { btn.textContent = 'Add'; btn.disabled = false; }, 1500);
        });
      })();
    </script>
HTML;
$footer_js .= soh_table_sort_script($cspNonceAttr ?? null);

include __DIR__ . '/templates/layout.php';
