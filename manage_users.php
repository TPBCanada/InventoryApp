<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/security.php';

if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/dbusers.php';


if (!isset($BASE_URL)) {
  $script_path = $_SERVER['SCRIPT_NAME'];
  $base = rtrim(dirname($script_path), '/\\');

  if ($base === DIRECTORY_SEPARATOR || $base === '.') {
    $BASE_URL = '';
  } else {
    $BASE_URL = $base;
  }
}

if (!isset($BASE_URL)) {
  $BASE_URL = '';
}


// --- HELPER FUNCTION: CSRF Validation ---
// Assumes $_SESSION['csrf_token'] is set by a function in layout.php
function validate_csrf_token($posted_token)
{
    if (empty($posted_token) || !isset($_SESSION['csrf_token']) || $posted_token !== $_SESSION['csrf_token']) {
        // Log attempt or handle failure
        http_response_code(403);
        die("<p style='color:red; text-align:center;'>Security Error: Invalid CSRF token.</p>");
    }
}
// ----------------------------------------

function manage_users_first_existing_column(mysqli $conn, string $table, array $candidates): ?string
{
    foreach ($candidates as $column) {
        $column = trim((string) $column);
        if ($column === '') {
            continue;
        }

        $safe_table = $conn->real_escape_string($table);
        $safe_column = $conn->real_escape_string($column);
        $sql = "SHOW COLUMNS FROM `{$safe_table}` LIKE '{$safe_column}'";
        $res = $conn->query($sql);
        if ($res instanceof mysqli_result) {
            $exists = $res->num_rows > 0;
            $res->free();
            if ($exists) {
                return $column;
            }
        }
    }

    return null;
}

function manage_users_format_last_login(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '<span class="text-muted">Never</span>';
    }

    try {
        $utc = new DateTimeZone('UTC');
        $local = new DateTimeZone('America/New_York');
        $dt = new DateTimeImmutable($value, $utc);
        return htmlspecialchars($dt->setTimezone($local)->format('Y-m-d g:i A'));
    } catch (Exception $e) {
        return '<span class="text-muted">Never</span>';
    }
}

// --- IMPORTANT: Determine the current logged-in user's ID safely ---
// This is necessary because $_SESSION['user_id'] is not being set at login (which is recommended).
$current_user_id = 0;
if (isset($_SESSION['username'])) {
    $stmt_user_id = $conn->prepare("SELECT id FROM users WHERE username = ?");
    if ($stmt_user_id) {
        $stmt_user_id->bind_param("s", $_SESSION['username']);
        $stmt_user_id->execute();
        $result_user_id = $stmt_user_id->get_result();
        if ($result_user_id->num_rows === 1) {
            $current_user_id = (int) $result_user_id->fetch_assoc()['id'];
        }
        $stmt_user_id->close();
    }
}
// -----------------------------------------------------------------


// Define roles for consistent access control
$current_user_role = $_SESSION['role_id'] ?? 0;
$admin_role_id = 1;
$inactive_role_id = 9;
$is_admin = ($current_user_role === $admin_role_id);

// Restrict access to Admins (1) and Managers (2)
$allowed_roles = [1, 2];
if (!in_array($current_user_role, $allowed_roles, true)) {
    http_response_code(403);
    die("<p style='color:red; text-align:center;'>Access denied. Only certain roles can access this page.</p>");
}

$last_login_column = manage_users_first_existing_column($conn, 'users', [
    'last_login',
    'last_login_time',
    'last_login_at',
    'login_time',
    'logged_in_at',
]);

$message = "";

// 🚨 NEW: Retrieve and clear success messages from the session for PRG
if (isset($_SESSION['add_user_message'])) {
    $message = $_SESSION['add_user_message'];
    unset($_SESSION['add_user_message']);
} elseif (isset($_SESSION['edit_user_message'])) {
    $message = $_SESSION['edit_user_message'];
    unset($_SESSION['edit_user_message']);
} elseif (isset($_SESSION['deactivate_user_message'])) {
    $message = $_SESSION['deactivate_user_message'];
    unset($_SESSION['deactivate_user_message']);
}

// --- Load roles list (Filtered for non-Admins) ---
// This block populates the $roles array for display/dropdowns.
$roles = [];
$sql_roles = "SELECT id, role FROM roles";

if (!$is_admin) {
    // If the current user is NOT an Admin, exclude the Admin role (1) from the dropdown/roles list
    $sql_roles .= " WHERE id != " . (int) $admin_role_id;
}
$sql_roles .= " ORDER BY id ASC";

if ($res = $conn->query($sql_roles)) {
    while ($r = $res->fetch_assoc()) {
        $roles[(int) $r['id']] = $r['role'];
    }
    $res->free();
}

// --- Fetch all users (Filtered for non-Admins) ---
// This block fetches the list of users to display in the table ($users).
$sql_users = "SELECT id, username, first_name, last_name, email, role_id";
if ($last_login_column !== null) {
    $sql_users .= ", `{$last_login_column}` AS last_login";
} else {
    $sql_users .= ", NULL AS last_login";
}
$sql_users .= " FROM users";

if (!$is_admin) {
    // Manager (non-Admin) views roles 2, 3, and 4, excluding Admin (1).
    $allowed_view_roles = [2, 3, 4];

    // Use IN clause to fetch users with these specific roles.
    $role_list = implode(', ', array_map('intval', $allowed_view_roles));

    $sql_users .= " WHERE role_id IN (" . $role_list . ")";
}

$sql_users .= " ORDER BY id ASC";

$users = $conn->query($sql_users);

// --- Add User ---
if (isset($_POST['add_user'])) {
    // 🔒 SECURITY: CSRF Check
    validate_csrf_token($_POST['csrf_token'] ?? null);

    $username = trim($_POST['username'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role_id = (int) ($_POST['role_id'] ?? 0);

    if (!$is_admin && $role_id === $admin_role_id) {
        $message = "ðŸš« Access Denied. You cannot assign the Admin role.";
    } elseif ($username && $first_name && $last_name && $email && $password && $role_id) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, first_name, last_name, email, password, role_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssi", $username, $first_name, $last_name, $email, $hash, $role_id);

        if ($stmt->execute()) {
            $_SESSION['add_user_message'] = "User **{$username}** added successfully!";
            header("Location: manage_users.php");
            exit;
        } else {
            $message = "Error adding user: " . $conn->error;
        }
        $stmt->close();
    } else {
        $message = "Please fill all fields.";
    }
}

// --- Delete/Deactivate User (Secured) ---
if (isset($_GET['delete'])) {
    // Note: GET requests generally don't require CSRF protection unless they trigger a state change,
    // which this one does. However, since it's a link click, we rely on the confirmation prompt
    // and URL parameter checks for basic security.

    $id = (int) $_GET['delete'];
    $can_proceed = true;
    $action_message = "";

    if ($id === 1) {
        $action_message = "ðŸš« Access Denied. You cannot deactivate the primary Admin account.";
        $can_proceed = false;
    } else {
        // Check the role of the user being targeted
        $stmt_check = $conn->prepare("SELECT role_id FROM users WHERE id=?");
        $stmt_check->bind_param("i", $id);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        $original_role = $res_check->fetch_assoc()['role_id'] ?? 0;
        $stmt_check->close();

        // SECURITY CHECK: Prevent non-admins from deactivating Admin accounts
        if (!$is_admin && $original_role === $admin_role_id) {
            $action_message = "ðŸš« Access Denied. You cannot deactivate an Admin account.";
            $can_proceed = false;
        }

        // SECURITY CHECK: Prevent user from deactivating themselves
        if ($id === $current_user_id) {
            $action_message = "ðŸš« Access Denied. You cannot deactivate your own account.";
            $can_proceed = false;
        }
    }

    if ($can_proceed) {
        // --- MODIFIED SQL: CHANGE DELETE TO UPDATE ---
        $stmt = $conn->prepare("UPDATE users SET role_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $inactive_role_id, $id);

        if ($stmt->execute()) {
            $_SESSION['deactivate_user_message'] = "User deactivated (Role ID: " . $inactive_role_id . ").";
            header("Location: manage_users.php");
            exit;
        } else {
            $action_message = "Error deactivating user.";
        }
        $stmt->close();
    }

    if (!empty($action_message)) {
        $message = $action_message; // Set local message if action failed before redirect
    }
}

// --- Edit User (Secured) ---
if (isset($_POST['edit_user'])) {
    // 🔒 SECURITY: CSRF Check
    validate_csrf_token($_POST['csrf_token'] ?? null);

    $id = (int) ($_POST['user_id'] ?? 0);
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role_id = (int) ($_POST['role_id'] ?? 0);
    $password = $_POST['password'] ?? '';

    $can_proceed = true;

    // 1. Fetch the CURRENT role of the user being edited (for security)
    $stmt_check = $conn->prepare("SELECT role_id FROM users WHERE id=?");
    $stmt_check->bind_param("i", $id);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    $original_role = $res_check->fetch_assoc()['role_id'] ?? 0;
    $stmt_check->close();

    // 2. SECURITY CHECK: Prevent non-admins from editing Admin accounts
    if (!$is_admin && $original_role === $admin_role_id) {
        $message = "ðŸš« Access Denied. You cannot edit an Admin account.";
        $can_proceed = false;
    }
    // 3. SECURITY CHECK: Prevent non-admins from assigning the Admin role
    elseif (!$is_admin && $role_id === $admin_role_id) {
        $message = "ðŸš« Access Denied. You cannot assign the Admin role.";
        $can_proceed = false;
    }

    if ($can_proceed && $id && $first_name && $last_name && $email && $role_id) {
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role_id=?, password=? WHERE id=?");
            $stmt->bind_param("sssisi", $first_name, $last_name, $email, $role_id, $hash, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, role_id=? WHERE id=?");
            $stmt->bind_param("sssii", $first_name, $last_name, $email, $role_id, $id);
        }

        if ($stmt->execute()) {
            $update_message = "User updated successfully!";

            // ⭐️ CRITICAL FIX: Only update the session role if the currently logged-in user edited THEIR OWN ID
            if ($current_user_id === $id) {
                $_SESSION['role_id'] = $role_id;
                $update_message .= " (Your role has been updated. Menu reflects new permissions.)";
            }

            // PRG Redirect
            $_SESSION['edit_user_message'] = $update_message;
            header("Location: manage_users.php");
            exit;

        } else {
            $message = "Error updating user: " . $conn->error;
        }
        $stmt->close();
    } elseif ($can_proceed) {
        $message = "Please fill all required fields.";
    }
}

// --- Fetch all users (Filtered for non-Admins) ---
// This block is duplicated at the end to ensure the list is refreshed after any action
$sql_users = "SELECT id, username, first_name, last_name, email, role_id";
if ($last_login_column !== null) {
    $sql_users .= ", `{$last_login_column}` AS last_login";
} else {
    $sql_users .= ", NULL AS last_login";
}
$sql_users .= " FROM users";

if (!$is_admin) {
    // If the current user is NOT an Admin (like a Manager, Role ID 2):
    // Show only roles 2 (Manager), 3 (Picker), and 4 (Shipper).
    $allowed_view_roles = [2, 3, 4];

    // Format the roles for the SQL IN clause: (2, 3, 4)
    $role_list = implode(', ', array_map('intval', $allowed_view_roles));

    $sql_users .= " WHERE role_id IN (" . $role_list . ")";
}

$sql_users .= " ORDER BY id ASC";
$users = $conn->query($sql_users); // Use the modified query

// ------ Page metadata + body-only render ------
$title = 'User Management';

ob_start(); ?>

<h2>User Management</h2>

<a alt="back to main" href="<?= $BASE_URL ?>/dashboard.php" class="btn btn-ghost back-to-main">
    ↩ Back to Main
</a>

<?php if (!empty($message)): ?>
    <p class="message"><?= htmlspecialchars($message) ?></p>
<?php endif; ?>

<form method="POST" class="card p-3 g-2" autocomplete="off">
    <h3>Add New User</h3>
    <!-- 🔒 SECURITY: CSRF Token Input -->
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
    <div class="grid-3 g-2">
        <input type="text" name="username" placeholder="Username" required>
        <input type="text" name="first_name" placeholder="First Name" required>
        <input type="text" name="last_name" placeholder="Last Name" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Password" required>
        <select name="role_id" required>
            <option value="">Select Role</option>
            <?php foreach ($roles as $id => $role_name): ?>
                <option value="<?= (int) $id ?>"><?= htmlspecialchars($role_name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" name="add_user" class="btn mt-2">Add User</button>
</form>

<?php
// Edit form (if requested)
if (isset($_GET['edit'])):
    $edit_id = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT id, username, first_name, last_name, email, role_id FROM users WHERE id=?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $edit_result = $stmt->get_result();
    if ($edit_result && $edit_result->num_rows > 0):
        $edit_user = $edit_result->fetch_assoc();
        $stmt->close();

        // FINAL SECURITY CHECK: Prevent a non-admin from loading the edit form for an Admin
        if (!$is_admin && $edit_user['role_id'] == $admin_role_id) {
            $message = "ðŸš« Access Denied. You cannot view the edit form for an Admin account.";
        } else {
            ?>
            <div class="card mt-4 p-3">
                <h3>Edit User (<?= htmlspecialchars($edit_user['username']) ?>)</h3>
                <form method="POST" class="g-2">
                    <!-- 🔒 SECURITY: CSRF Token Input -->
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                    <input type="hidden" name="user_id" value="<?= (int) $edit_user['id'] ?>">
                    <div class="grid-3 g-2">
                        <input type="text" name="first_name" value="<?= htmlspecialchars($edit_user['first_name']) ?>"
                            placeholder="First Name" required>
                        <input type="text" name="last_name" value="<?= htmlspecialchars($edit_user['last_name']) ?>"
                            placeholder="Last Name" required>
                        <input type="email" name="email" value="<?= htmlspecialchars($edit_user['email']) ?>" placeholder="Email"
                            required>
                        <input type="password" name="password" placeholder="New Password (leave blank)">
                        <select name="role_id" required>
                            <?php foreach ($roles as $id => $role_name): ?>
                                <option value="<?= (int) $id ?>" <?= ($edit_user['role_id'] == $id ? 'selected' : '') ?>>
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
            <?php
        } // End of security check for edit form display
    endif;
endif;
?>

<section class="card mt-4">
    <div class="card__header">
        <h3>Existing Users</h3>
    </div>
    <div class="card__body">
        <table class="table">
            <thead>
                <tr>
                    <th>Username</th>
                    <th>First</th>
                    <th>Last</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($users && $users->num_rows): ?>
                    <?php while ($row = $users->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['username']) ?></td>
                            <td><?= htmlspecialchars($row['first_name']) ?></td>
                            <td><?= htmlspecialchars($row['last_name']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td><?= htmlspecialchars($roles[$row['role_id']] ?? 'Unknown') ?></td>
                            <td><?= manage_users_format_last_login($row['last_login'] ?? null) ?></td>
                            <td class="nowrap">
                                <a href="?edit=<?= (int) $row['id'] ?>" class="btn btn--sm">Edit</a>
                                <?php
                                if ((int) $row['role_id'] !== $admin_role_id && (int) $row['id'] !== $current_user_id):
                                    ?>
                                    <a href="?delete=<?= (int) $row['id'] ?>" class="btn btn--sm btn--danger"
                                        onclick="return confirm('Deactivate this user account?')">Deactivate</a>
                                <?php else: ?>
                                    <span class="text-muted"></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-muted">No users found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>




<?php
$content = ob_get_clean();

// If you need page-specific JS, set $footer_js here (optional)
$footer_js = '';
include __DIR__ . '/templates/layout.php';
