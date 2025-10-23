<?php
require_once 'dbusers.php';
$message = '';
$token = $_GET['token'] ?? '';

if ($token === '') {
    die("Invalid or missing token.");
}

// Check token validity
$stmt = $conn->prepare("SELECT id, reset_expires FROM users WHERE reset_token = ?");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Invalid or expired reset link.");
}

$user = $result->fetch_assoc();

if (strtotime($user['reset_expires']) < time()) {
    die("This reset link has expired. Please request a new one.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if ($password === '' || $confirm === '') {
        $message = "Please fill out both password fields.";
    } elseif ($password !== $confirm) {
        $message = "Passwords do not match.";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $update->bind_param("si", $hashed, $user['id']);
        $update->execute();
        $message = "Your password has been successfully reset. You can now <a href='login.php'>login</a>.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Set New Password</title>
<style>
body { font-family: Arial; background: #f7f7f7; }
.container { width: 400px; margin: 100px auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 0 5px #ccc; }
h2 { text-align: center; }
input[type=password] {
    width: 100%; padding: 10px; margin: 8px 0; border: 1px solid #ccc; border-radius: 5px;
}
button { width: 100%; padding: 10px; background: #007bff; color: #fff; border: none; border-radius: 5px; cursor: pointer; }
button:hover { background: #0056b3; }
.message { text-align: center; color: #333; margin-top: 10px; }
</style>
</head>
<body>
<div class="container">
    <h2>Set New Password</h2>
    <form method="POST">
        <input type="password" name="password" placeholder="New Password" required>
        <input type="password" name="confirm_password" placeholder="Confirm Password" required>
        <button type="submit">Reset Password</button>
    </form>
    <?php if ($message): ?><p class="message"><?= $message ?></p><?php endif; ?>
</div>
</body>
</html>
