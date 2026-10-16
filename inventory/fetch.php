<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';
require_once __DIR__ . '/utils/shopify_fetch.php';

// Quick test page for fetching a Shopify product by SKU.
$sku = trim((string) ($_GET['sku'] ?? '202400007'));
$max_words = 30;

$shopify_product = null;
$error_message = null;

try {
    $shopify_product = shopify_fetch_product_by_sku($sku, $max_words);
    if ($shopify_product === null) {
        $error_message = "No Shopify product found for SKU {$sku}.";
    }
} catch (\Throwable $e) {
    $error_message = 'API Call Failed: ' . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopify Product Fetch</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; }
        .error { color: red; }
        .card { border: 1px solid #ddd; padding: 16px; border-radius: 6px; max-width: 840px; }
        img { max-width: 220px; height: auto; border: 1px solid #eee; border-radius: 4px; }
        .meta { color: #555; margin: 6px 0; }
    </style>
</head>

<body>
    <h1>Shopify Product Fetch</h1>
    <form method="get" style="margin-bottom:16px;">
        <label for="sku">SKU:</label>
        <input id="sku" name="sku" value="<?= h($sku) ?>" />
        <button type="submit">Load</button>
    </form>

    <?php if ($error_message !== null): ?>
        <div class="error"><?= h($error_message) ?></div>
    <?php elseif ($shopify_product !== null): ?>
        <div class="card">
            <h2><?= h($shopify_product['title'] ?: 'Untitled Product') ?></h2>
            <div class="meta">SKU <?= h($shopify_product['sku']) ?></div>
            <?php if (!empty($shopify_product['image_src'])): ?>
                <div style="margin: 10px 0;">
                    <img src="<?= h($shopify_product['image_src']) ?>" alt="<?= h($shopify_product['title']) ?>">
                </div>
            <?php endif; ?>
            <?php if (!empty($shopify_product['description'])): ?>
                <p><?= h($shopify_product['description']) ?></p>
            <?php endif; ?>
            <details style="margin-top:12px;">
                <summary>Raw Response</summary>
                <pre><?php echo htmlspecialchars(json_encode($shopify_product, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            </details>
        </div>
    <?php else: ?>
        <p>Enter a SKU to fetch its Shopify product.</p>
    <?php endif; ?>
</body>

</html>
