<?php
session_start();
include 'dbinv.php';

// auth
if (!isset($_SESSION['username'])) {
  header("Location: login.php");
  exit;
}
$user_id  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$username = $_SESSION['username'] ?? 'User';
$role_id  = (int)($_SESSION['role_id'] ?? 0);

// Restrict access to admins only
$allowed_roles = [1]; // Add or change role IDs as needed
if (!in_array($_SESSION['role_id'] ?? null, $allowed_roles, true)) {
    die("<p style='color:red; text-align:center; font-size:18px; margin-top:50px;'>
        Access denied. Only certain roles can access this page.
    </p>");
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$message = "";

// Add SKU
if (isset($_POST['add_sku'])) {
    $sku_num  = mysqli_real_escape_string($conn, $_POST['sku_num'] ?? '');
    $desc     = mysqli_real_escape_string($conn, substr($_POST['desc'] ?? '', 0, 100));
    $status   = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);

    $query = "INSERT INTO sku (sku_num, `desc`, `status`, quantity, created_at)
              VALUES ('$sku_num', '$desc', '$status', '$quantity', NOW())";

    if (mysqli_query($conn, $query)) {
        $message = "✅ SKU added successfully.";
    } else {
        $message = "❌ Error adding SKU: " . mysqli_error($conn);
    }
}

// Delete SKU
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    mysqli_query($conn, "DELETE FROM sku WHERE id = $id");
    header("Location: manage_sku.php");
    exit;
}

// Update SKU
if (isset($_POST['update_sku'])) {
    $id       = intval($_POST['id'] ?? 0);
    $sku_num  = mysqli_real_escape_string($conn, $_POST['sku_num'] ?? '');
    $desc     = mysqli_real_escape_string($conn, substr($_POST['desc'] ?? '', 0, 100));
    $status   = mysqli_real_escape_string($conn, $_POST['status'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);

    $query = "UPDATE sku 
              SET sku_num='$sku_num', `desc`='$desc', `status`='$status', quantity='$quantity' 
              WHERE id='$id'";

    if (mysqli_query($conn, $query)) {
        $message = "✅ SKU updated successfully.";
    } else {
        $message = "❌ Update error: " . mysqli_error($conn);
    }
}

// Fetch all SKUs
$result = mysqli_query($conn, "SELECT * FROM sku ORDER BY id DESC");

// ---------- PAGE LAYOUT WIRING ----------
$title    = 'Manage SKU';
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

/**
 * Optional: If your layout supports $extra_head, you can inject page-specific CSS.
 * If your global layout already includes your site styles, you can remove this block.
 */
$extra_head = <<<HTML
<link rel="stylesheet" href="{$BASE_URL}/css/site.css?v=1">
HTML;

ob_start();
?>
  <h2 class="title">Manage SKU</h2>
  <span class="small">Admin-only</span>

  <?php if ($message): ?>
    <?php
      $isGood = str_starts_with($message, '✅');
      $isBad  = str_starts_with($message, '❌');
      $cls = $isGood ? 'message message--good' : ($isBad ? 'message message--bad' : 'message');
    ?>
    <div class="card card--pad <?= h($cls) ?>" style="margin-top:12px;">
      <?= h($message) ?>
    </div>
  <?php endif; ?>

  <!-- Add SKU -->
  <div class="card card--pad" style="margin-top:16px;">
    <form class="form" method="POST">
      <h3 style="margin:0 0 10px">Add New SKU</h3>
      <div class="form-row" style="display:grid;grid-template-columns:1fr 2fr 1fr 1fr auto;gap:8px;">
        <input class="input" type="text" name="sku_num" placeholder="SKU Number" required>
        <input class="input" type="text" name="desc" maxlength="100" placeholder="Description (max 100 chars)" required>
        <select class="select" name="status" required>
          <option value="ACTIVE">ACTIVE</option>
          <option value="DISCONTINUED">DISCONTINUED</option>
        </select>
        <input class="input" type="number" name="quantity" placeholder="Quantity" required>
        <button class="btn btn--primary" type="submit" name="add_sku">Add SKU</button>
      </div>
    </form>
  </div>

  <!-- SKU Table -->
  <div class="card card--pad" style="margin-top:16px;">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th style="min-width:160px">SKU Number</th>
            <th style="min-width:280px">Description</th>
            <th>Status</th>
            <th style="min-width:120px">Quantity</th>
            <th style="min-width:160px">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php while ($row = mysqli_fetch_assoc($result)) : ?>
          <tr>
            <form method="POST">
              <td>
                <input class="input" type="text" name="sku_num" value="<?= h($row['sku_num']) ?>">
              </td>
              <td>
                <input class="input" type="text" name="desc" maxlength="100" value="<?= h($row['desc']) ?>">
              </td>
              <td>
                <div style="display:flex;align-items:center;gap:8px;">
                  <select class="select" name="status">
                    <option value="ACTIVE" <?= $row['status'] === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
                    <option value="DISCONTINUED" <?= $row['status'] === 'DISCONTINUED' ? 'selected' : '' ?>>DISCONTINUED</option>
                  </select>
                  <?php if ($row['status'] === 'ACTIVE'): ?>
                    <span class="badge badge--ok">Active</span>
                  <?php else: ?>
                    <span class="badge badge--disc">Discontinued</span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <input class="input" type="number" name="quantity" value="<?= h($row['quantity']) ?>">
              </td>
              <td>
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="btn btn--ghost" type="submit" name="update_sku" title="Update this SKU">Update</button>
                <a class="link-danger" href="manage_sku.php?delete=<?= (int)$row['id'] ?>" onclick="return confirm('Delete this SKU?')">Delete</a>
              </td>
            </form>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
    <p class="small" style="margin:10px 0 0">Tip: You can edit inline and press <strong>Update</strong> per row.</p>
  </div>

<?php
$content = ob_get_clean();

/** Optional per-page JS */
$footer_js = <<<HTML
<script>
  // Page-specific JS can go here if needed later.
</script>
HTML;

include __DIR__ . '/templates/layout.php';
