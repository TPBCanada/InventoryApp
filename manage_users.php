<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/dbusers.php';

// Restrict access to admins only
$allowed_roles = [1, 2];
if (!in_array($_SESSION['role_id'] ?? 0, $allowed_roles, true)) {
    http_response_code(403);
    die("<p style='color:red; text-align:center;'>Access denied. Only certain roles can access this page.</p>");
}

$message = "";

// --- Load roles list ---
$roles = [];
if ($res = $conn->query("SELECT id, role FROM roles ORDER BY id ASC")) {
    while ($r = $res->fetch_assoc()) {
        $roles[(int)$r['id']] = $r['role'];
    }
    $res->free();
}

// --- Add User ---
if (isset($_POST['add_user'])) {
    $username   = trim($_POST['username'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $password   = $_POST['password'] ?? '';
    $role_id    = (int)($_POST['role_id'] ?? 0);

    if ($username && $first_name && $last_name && $email && $password && $role_id) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, first_name, last_name, email, password, role_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssi", $username, $first_name, $last_name, $email, $hash, $role_id);
        $message = $stmt->execute() ? "User added successfully!" : ("Error adding user: " . $conn->error);
        $stmt->close();
    } else {
        $message = "Please fill all fields.";
    }
}

// --- Delete User ---
if (isset($_GET['delete'])) {
    $id  = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    $stmt->close();
    $message = $ok ? "User deleted." : "Error deleting user.";
}

// --- Edit User ---
if (isset($_POST['edit_user'])) {
    $id         = (int)($_POST['user_id'] ?? 0);
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $role_id    = (int)($_POST['role_id'] ?? 0);
    $password   = $_POST['password'] ?? '';

    if ($id && $first_name && $last_name && $email && $role_id) {
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role_id=?, password=? WHERE id=?");
            $stmt->bind_param("sssisi", $first_name, $last_name, $email, $role_id, $hash, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role_id=? WHERE id=?");
            $stmt->bind_param("sssii", $first_name, $last_name, $email, $role_id, $id);
        }
        $message = $stmt->execute() ? "User updated successfully!" : ("Error updating user: " . $conn->error);
        $stmt->close();
    } else {
        $message = "Please fill all required fields.";
    }
}

// --- Fetch all users (for table) ---
$users = $conn->query("SELECT id, username, first_name, last_name, email, role_id FROM users ORDER BY id ASC");

// ------ Page metadata + body-only render ------
$title = 'User Management';

ob_start(); ?>

  <h2>User Management</h2>

  <?php if (!empty($message)): ?>
    <p class="message"><?= htmlspecialchars($message) ?></p>
  <?php endif; ?>

  <!-- Add User Form -->
  <form method="POST" class="card p-3 g-2" autocomplete="off">
    <h3>Add New User</h3>
    <div class="grid-3 g-2">
      <input type="text"     name="username"    placeholder="Username"    required>
      <input type="text"     name="first_name"  placeholder="First Name"  required>
      <input type="text"     name="last_name"   placeholder="Last Name"   required>
      <input type="email"    name="email"       placeholder="Email"       required>
      <input type="password" name="password"    placeholder="Password"    required>
      <select name="role_id" required>
        <option value="">Select Role</option>
        <?php foreach ($roles as $id => $role_name): ?>
          <option value="<?= (int)$id ?>"><?= htmlspecialchars($role_name) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" name="add_user" class="btn mt-2">Add User</button>
  </form>

  <!-- Users Table -->
  <section class="card mt-4">
    <div class="card__header"><h3>Existing Users</h3></div>
    <div class="card__body">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th><th>Username</th><th>First</th><th>Last</th><th>Email</th><th>Role</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($users && $users->num_rows): ?>
            <?php while ($row = $users->fetch_assoc()): ?>
              <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><?= htmlspecialchars($row['username']) ?></td>
                <td><?= htmlspecialchars($row['first_name']) ?></td>
                <td><?= htmlspecialchars($row['last_name']) ?></td>
                <td><?= htmlspecialchars($row['email']) ?></td>
                <td><?= htmlspecialchars($roles[$row['role_id']] ?? 'Unknown') ?></td>
                <td class="nowrap">
                  <a href="?edit=<?= (int)$row['id'] ?>" class="btn btn--sm">Edit</a>
                  <a href="?delete=<?= (int)$row['id'] ?>" class="btn btn--sm btn--danger"
                     onclick="return confirm('Delete this user?')">Delete</a>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="7" class="text-muted">No users found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php
  // Edit form (if requested)
  if (isset($_GET['edit'])):
    $edit_id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT id, username, first_name, last_name, email, role_id FROM users WHERE id=?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_result = $stmt->get_result();
    if ($edit_result && $edit_result->num_rows > 0):
      $edit_user = $edit_result->fetch_assoc();
      $stmt->close();
  ?>
    <div class="card mt-4 p-3">
      <h3>Edit User (<?= htmlspecialchars($edit_user['username']) ?>)</h3>
      <form method="POST" class="g-2">
        <input type="hidden" name="user_id" value="<?= (int)$edit_user['id'] ?>">
        <div class="grid-3 g-2">
          <input type="text"   name="first_name" value="<?= htmlspecialchars($edit_user['first_name']) ?>" placeholder="First Name" required>
          <input type="text"   name="last_name"  value="<?= htmlspecialchars($edit_user['last_name'])  ?>" placeholder="Last Name" required>
          <input type="email"  name="email"      value="<?= htmlspecialchars($edit_user['email'])      ?>" placeholder="Email" required>
          <input type="password" name="password" placeholder="New Password (leave blank)">
          <select name="role_id" required>
            <?php foreach ($roles as $id => $role_name): ?>
              <option value="<?= (int)$id ?>" <?= ($edit_user['role_id'] == $id ? 'selected' : '') ?>>
                <?= htmlspecialchars($role_name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mt-2">
          <button type="submit" name="edit_user" class="btn">Save Changes</button>
          <a href="manage_users.php" class="btn btn--ghost">Cancel</a>
        </div>
      </form>
    </div>
  <?php endif; endif; ?>

<?php
$content = ob_get_clean();

// If you need page-specific JS, set $footer_js here (optional)
$footer_js = '';
include __DIR__ . '/templates/layout.php';
