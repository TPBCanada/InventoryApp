<?php
session_start();

// auth
if (!isset($_SESSION['username'])) {
  header("Location: login.php");
  exit;
}

require_once 'dbusers.php';

// Restrict access to admins only
$allowed_roles = [1]; // extend as needed
if (!in_array($_SESSION['role_id'] ?? null, $allowed_roles, true)) {
    die("<h1 style='color:red; text-align:center;'>Access denied. Only certain roles can access this page.</h1>");
}


$message = "";

/** Helpers **/
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Add Role **/
if (isset($_POST['add_role'])) {
    $role = trim($_POST['role'] ?? '');
    if ($role !== '') {
        $stmt = $conn->prepare("INSERT INTO roles (role) VALUES (?)");
        $stmt->bind_param("s", $role);
        $message = $stmt->execute() ? "Role added successfully." : ("Error adding role: " . $conn->error);
    } else {
        $message = "Role name cannot be empty.";
    }
}

/** Update Role **/
if (isset($_POST['update_role'])) {
    $id   = intval($_POST['role_id'] ?? 0);
    $role = trim($_POST['role'] ?? '');
    if ($role !== '' && $id > 0) {
        $stmt = $conn->prepare("UPDATE roles SET role = ? WHERE id = ?");
        $stmt->bind_param("si", $role, $id);
        $message = $stmt->execute() ? "Role updated successfully." : ("Error updating role: " . $conn->error);
    } else {
        $message = "Role name cannot be empty.";
    }
}

/** Delete Role **/
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    if ($id > 0) {
        $conn->query("DELETE FROM roles WHERE id = $id");
        $message = "Role deleted.";
    }
}

/** Fetch all roles **/
$result = $conn->query("SELECT * FROM roles ORDER BY id ASC");

/** Page meta / assets **/
$title    = 'Manage Roles';
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

/**
 * If your layout supports extra head content, set $extra_head.
 * Otherwise, your global CSS should already be loaded by layout.
 * Keeping this here in case you want page-specific CSS.
 */
$extra_head = <<<HTML
<link rel="stylesheet" href="{$BASE_URL}/css/site.css?v=1">
HTML;

/** Page content **/
ob_start();
?>

  <h2 class="title">Role Management</h2>

  <?php if (!empty($message)): ?>
    <div class="alert alert-info" role="status" style="margin:12px 0;">
      <?= h($message) ?>
    </div>
  <?php endif; ?>

  <section class="card" style="margin-top:16px;">
    <h2 class="h3" style="margin-top:0;">Add New Role</h2>
    <form method="POST" class="form grid" style="display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;max-width:520px;">
      <input class="input" type="text" name="role" placeholder="Role name" required>
      <button class="btn btn-primary" type="submit" name="add_role">Add Role</button>
    </form>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2 class="h3" style="margin-top:0;">Existing Roles</h2>
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th style="width:80px;">ID</th>
            <th>Role</th>
            <th style="width:140px;">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
          <tr>
            <td><?= (int)$row['id'] ?></td>
            <td>
              <form method="POST" class="edit-form" style="display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="role_id" value="<?= (int)$row['id'] ?>">
                <input class="input" type="text" name="role" value="<?= h($row['role']) ?>" required>
                <button class="btn btn-secondary" type="submit" name="update_role">Save</button>
              </form>
            </td>
            <td>
              <a class="btn btn-danger"
                 href="?delete=<?= (int)$row['id'] ?>"
                 onclick="return confirm('Delete this role?')">Delete</a>
            </td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
<?php
$content = ob_get_clean();

/** Optional per-page JS (kept minimal here) **/
$footer_js = <<<HTML
<script>
  // Future page-specific JS can live here
</script>
HTML;

/** Use shared layout **/
include __DIR__ . '/templates/layout.php';
