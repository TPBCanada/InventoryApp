<?php
declare(strict_types=1);

// Shared stock-level loader for stock_on_hand.php and the request page's stock window.

const SOH_STATUS_BADGES = [
    'ok' => ['badge--ok', 'OK'],
    'low' => ['badge--disc', 'Low'],
    'zero' => ['badge--zero', 'Out'],
    'untracked' => ['badge--untracked', 'Not tracked'],
];

// Sort order for the Status column: most urgent first
const SOH_STATUS_SORT = ['zero' => 0, 'low' => 1, 'ok' => 2, 'untracked' => 3];

// Request types offered on the request landing page
const SOH_REQUEST_TYPES = ['boxes' => 'Boxes', 'supplies' => 'Supplies'];

/** Maps a supply_category to a request type: anything with "box" in it (e.g. "Corrugated Boxes") is boxes. */
function soh_request_type(string $category): string
{
    return stripos($category, 'box') !== false ? 'boxes' : 'supplies';
}

/** Heading shown for a category; box quantities are counted in bundles, so say so. */
function soh_category_label(string $category): string
{
    return soh_request_type($category) === 'boxes' ? $category . ' (per bundle)' : $category;
}

function sohColumnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    return (bool) $stmt->get_result()->fetch_row();
}

/**
 * @return array{
 *   groups: array<string,array<int,array<string,mixed>>>,
 *   summary: array{skus:int,low:int,zero:int,no_min:int,last_invoice:?string},
 *   usage_live: bool,
 *   error: ?string
 * }
 */
function soh_load_stock_levels(mysqli $conn): array
{
    // Boxes used since the last invoice posted, so on-hand stays live between invoices.
    // Only counted when the stock row has a reset_invoice_date to measure from.
    $usage_live = false;
    $used_since_invoice = [];
    try {
        if (sohColumnExists($conn, 'box_usage_events', 'supply_id')
            && sohColumnExists($conn, 'box_usage_events', 'created_at')) {
            $res = $conn->query("
                SELECT e.supply_id, COUNT(*) AS used
                FROM box_usage_events e
                JOIN Warehouse_Current_Stock c ON c.supply_id = e.supply_id
                WHERE c.reset_invoice_date IS NOT NULL
                  AND e.created_at >= c.reset_invoice_date
                GROUP BY e.supply_id
            ");
            while ($r = $res->fetch_assoc()) {
                $used_since_invoice[(int) $r['supply_id']] = (int) $r['used'];
            }
            $res->free();
            $usage_live = true;
        }
    } catch (\Throwable $e) {
        error_log('[stock_levels usage] ' . $e->getMessage());
    }

    $rows = [];
    $error = null;
    try {
        $res = $conn->query("
            SELECT s.supply_id, s.supply_category, s.model_no, s.supply_name, s.inside_dimensions,
                   s.min_stock_level, c.supply_id AS stock_supply_id, c.qty_on_hand,
                   c.reset_invoice_number, c.reset_invoice_date
            FROM warehouse_supplies s
            LEFT JOIN Warehouse_Current_Stock c ON c.supply_id = s.supply_id
            ORDER BY s.supply_category, s.model_no
        ");
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $res->free();
    } catch (\Throwable $e) {
        error_log('[stock_levels load] ' . $e->getMessage());
        $error = 'Could not load stock levels.';
    }

    $summary = ['skus' => 0, 'low' => 0, 'zero' => 0, 'no_min' => 0, 'last_invoice' => null];
    $groups = [];

    foreach ($rows as $r) {
        $supply_id = (int) $r['supply_id'];
        $tracked = $r['stock_supply_id'] !== null;
        $min = (int) ($r['min_stock_level'] ?? 0);
        $used = $used_since_invoice[$supply_id] ?? 0;
        $on_hand = $tracked ? (int) $r['qty_on_hand'] - $used : null;

        if (!$tracked) {
            $status = 'untracked';
        } elseif ($on_hand <= 0) {
            $status = 'zero';
        } elseif ($on_hand <= $min) {
            $status = 'low';
        } else {
            $status = 'ok';
        }

        $summary['skus']++;
        if ($status === 'low') {
            $summary['low']++;
        } elseif ($status === 'zero') {
            $summary['zero']++;
        }
        if ($min <= 0) {
            $summary['no_min']++;
        }
        $received = (string) ($r['reset_invoice_date'] ?? '');
        if ($received !== '' && ($summary['last_invoice'] === null || $received > $summary['last_invoice'])) {
            $summary['last_invoice'] = $received;
        }

        $category = trim((string) ($r['supply_category'] ?? '')) ?: 'Uncategorized';
        $groups[$category][] = [
            'supply_id' => $supply_id,
            'model_no' => (string) ($r['model_no'] ?? ''),
            'name' => (string) ($r['supply_name'] ?? ''),
            'dims' => (string) ($r['inside_dimensions'] ?? ''),
            'on_hand' => $on_hand,
            'used' => $used,
            'min' => $min,
            'status' => $status,
            'invoice' => (string) ($r['reset_invoice_number'] ?? ''),
            'received' => $received !== '' ? substr($received, 0, 10) : '',
            'request_qty' => max(1, $min - (int) $on_hand),
        ];
    }

    return [
        'groups' => $groups,
        'summary' => $summary,
        'usage_live' => $usage_live,
        'error' => $error,
    ];
}

/**
 * When the stock window's numbers were last set from parsed orders: one row per invoice
 * currently applied to Warehouse_Current_Stock, newest first. When the invoice was also
 * logged in supplies_invoice_orders, the parse time and user come from there.
 *
 * Only invoices still applied to at least one item appear; an invoice fully superseded
 * by a later one drops out, because the stock row keeps just its latest invoice.
 *
 * @return array<int,array{invoice:string,received:string,posted_at:string,items:int,qty:?int,imported_by:string}>
 */
function soh_load_update_history(mysqli $conn, int $limit = 10): array
{
    try {
        $postedExpr = sohColumnExists($conn, 'Warehouse_Current_Stock', 'updated_at') ? 'MAX(updated_at)' : 'NULL';
        $qtyExpr = sohColumnExists($conn, 'Warehouse_Current_Stock', 'reset_invoice_qty') ? 'SUM(reset_invoice_qty)' : 'NULL';

        $stmt = $conn->prepare("
            SELECT reset_invoice_number AS invoice,
                   MAX(reset_invoice_date) AS received,
                   {$postedExpr} AS posted_at,
                   COUNT(*) AS items,
                   {$qtyExpr} AS qty
            FROM Warehouse_Current_Stock
            WHERE reset_invoice_number IS NOT NULL AND reset_invoice_number <> ''
            GROUP BY reset_invoice_number
            ORDER BY received DESC, posted_at DESC
            LIMIT ?
        ");
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Parse time + user from the import log, when the invoice is in it
        $imports = [];
        if ($rows && sohColumnExists($conn, 'supplies_invoice_orders', 'invoice_number')) {
            $invoices = array_column($rows, 'invoice');
            $marks = implode(',', array_fill(0, count($invoices), '?'));
            $stmt = $conn->prepare("
                SELECT invoice_number, imported_at, imported_by_username
                FROM supplies_invoice_orders
                WHERE invoice_number IN ({$marks})
                ORDER BY imported_at ASC
            ");
            $stmt->bind_param(str_repeat('s', count($invoices)), ...$invoices);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $imp) {
                $imports[(string) $imp['invoice_number']] = $imp; // latest import wins
            }
        }

        return array_map(static function (array $r) use ($imports): array {
            $imp = $imports[(string) $r['invoice']] ?? null;
            return [
                'invoice' => (string) $r['invoice'],
                'received' => substr((string) ($r['received'] ?? ''), 0, 10),
                'posted_at' => (string) ($imp['imported_at'] ?? $r['posted_at'] ?? ''),
                'items' => (int) $r['items'],
                'qty' => $r['qty'] !== null ? (int) $r['qty'] : null,
                'imported_by' => (string) ($imp['imported_by_username'] ?? ''),
            ];
        }, $rows);
    } catch (\Throwable $e) {
        error_log('[stock_levels history] ' . $e->getMessage());
        return [];
    }
}

/**
 * Most recent supply request per catalog item.
 *
 * @return array<int,array{request_id:int,qty:int,requested_at:string,requested_by:string}> keyed by supply_id
 */
function soh_load_last_requests(mysqli $conn): array
{
    $last = [];
    try {
        $res = $conn->query("
            SELECT i.supply_id, r.id AS request_id, i.qty, r.created_at, r.requested_by_username
            FROM supply_request_items i
            JOIN supply_requests r ON r.id = i.request_id
            JOIN (
                SELECT supply_id, MAX(request_id) AS request_id
                FROM supply_request_items
                WHERE supply_id IS NOT NULL
                GROUP BY supply_id
            ) latest ON latest.supply_id = i.supply_id AND latest.request_id = i.request_id
        ");
        while ($r = $res->fetch_assoc()) {
            $sid = (int) $r['supply_id'];
            $last[$sid] = [
                'request_id' => (int) $r['request_id'],
                // an item listed twice on one request adds up
                'qty' => ($last[$sid]['qty'] ?? 0) + (int) $r['qty'],
                'requested_at' => substr((string) $r['created_at'], 0, 10),
                'requested_by' => (string) $r['requested_by_username'],
            ];
        }
        $res->free();
    } catch (\Throwable $e) {
        // supply_requests tables may not exist until the request page has loaded once
        error_log('[stock_levels last requests] ' . $e->getMessage());
    }
    return $last;
}

/**
 * Click-to-sort for <table class="js-sortable"> as an inline <script>, so pages don't depend
 * on a separate .js file being deployed. Headers opt in with data-sort="text|number"; see the
 * comment inside for data-sort-value and .sort-pinned.
 */
function soh_table_sort_script(?string $nonceAttr = null): string
{
    $js = <<<'JS'
// Click-to-sort for <table class="js-sortable">.
// Headers opt in with data-sort="text|number". A cell can override its sort key
// with data-sort-value; empty keys always sort last. Rows are sorted within each
// <tbody>, so grouped tables keep their groups, and rows marked .sort-pinned
// (e.g. group headers) stay at the top of their tbody.
(function () {
  function keyFor(row, index, type) {
    const cell = row.children[index];
    if (!cell) return null;
    const raw = (cell.dataset.sortValue ?? cell.textContent).trim();
    if (raw === '') return null;
    if (type === 'number') {
      const n = Number.parseFloat(raw.replace(/[^0-9.-]+/g, ''));
      return Number.isFinite(n) ? n : null;
    }
    return raw.toLowerCase();
  }

  function sortTable(table, th) {
    const headers = Array.from(th.parentElement.children);
    const index = headers.indexOf(th);
    const type = th.dataset.sort || 'text';
    const dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
    const factor = dir === 'ascending' ? 1 : -1;

    table.querySelectorAll('tbody').forEach((tbody) => {
      const rows = Array.from(tbody.rows).filter((r) => !r.classList.contains('sort-pinned'));
      rows.sort((a, b) => {
        const av = keyFor(a, index, type);
        const bv = keyFor(b, index, type);
        if (av === null || bv === null) return av === bv ? 0 : (av === null ? 1 : -1);
        if (av < bv) return -factor;
        if (av > bv) return factor;
        return 0;
      });
      rows.forEach((r) => tbody.appendChild(r));
    });

    headers.forEach((h) => h.removeAttribute('aria-sort'));
    th.setAttribute('aria-sort', dir);
  }

  document.querySelectorAll('table.js-sortable').forEach((table) => {
    table.querySelectorAll('thead th[data-sort]').forEach((th) => {
      th.tabIndex = 0;
      th.addEventListener('click', () => sortTable(table, th));
      th.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          sortTable(table, th);
        }
      });
    });
  });
})();
JS;
    // The nonce is only needed where lib/security.php sends a CSP header; an older
    // security.php doesn't define $cspNonceAttr, so tolerate it missing.
    $nonce = ($nonceAttr ?? '') !== '' ? ' nonce="' . $nonceAttr . '"' : '';
    return '<script' . $nonce . '>' . PHP_EOL . $js . PHP_EOL . '</script>' . PHP_EOL;
}
