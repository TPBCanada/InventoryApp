<?php
declare(strict_types=1);
session_start();

// Simple guard: only allow logged-in users.
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo 'Unauthorized.';
    exit;
}

$raw = $_POST['reorder_json'] ?? '';
$items = [];

if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $items = $decoded;
    }
}

// Helper to extract SKU/Qty pair.
$map_sku_qty = static function (array $row): array {
    $sku = (string) ($row['uline'] ?? $row['name'] ?? $row['id'] ?? '');
    $qty = (string) ($row['qty_to_order'] ?? $row['qty'] ?? $row['quantity'] ?? '');
    return [$sku, $qty];
};

// If the user requested a CSV download ( ?download=1 ), stream a minimal CSV.
if (isset($_GET['download']) && $_GET['download'] === '1') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="reorder_export.csv"');
    $out = fopen('php://output', 'w');
    // No header per request; each line: SKU, Qty
    foreach ($items as $row) {
        [$sku, $qty] = $map_sku_qty($row);
        if ($sku === '' || $qty === '') continue;
        fputcsv($out, [$sku, $qty]);
    }
    fclose($out);
    exit;
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Reorder Preview</title>
  <style>
    body { font-family: system-ui, -apple-system, sans-serif; margin: 24px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { padding: 8px 10px; border: 1px solid #ddd; }
    th { background: #f5f5f5; text-align: left; }
    .empty { color: #666; }
    .actions { margin: 12px 0; }
  </style>
</head>
<body>
  <h1>Reorder Preview</h1>
  <div class="actions">
    <form method="get">
      <input type="hidden" name="download" value="1">
      <button type="submit">Download CSV</button>
    </form>
  </div>
  <?php if (empty($items)): ?>
    <p class="empty">No items were provided in the request.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>SKU</th>
          <th>Qty</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $row):
          [$sku, $qty] = $map_sku_qty($row);
          if ($sku === '' && $qty === '') continue;
          ?>
          <tr>
            <td><?= htmlspecialchars($sku) ?></td>
            <td><?= htmlspecialchars($qty) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</body>
</html>
