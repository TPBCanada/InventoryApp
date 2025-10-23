<?php
require_once 'dbusers.php';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $message = "Please enter your email.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $token = bin2hex(random_bytes(16)); // 32-character secure token
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $update = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE email = ?");
            $update->bind_param("sss", $token, $expires, $email);
            $update->execute();

            $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/new_password.php?token=" . $token;

            // ---- Send the reset email ----
            $subject = "Password Reset Request";
            $body = "Click this link to reset your password: $reset_link\n\nThis link will expire in 1 hour.";
            $headers = "From: no-reply@" . $_SERVER['HTTP_HOST'];

            if (mail($email, $subject, $body, $headers)) {
                $message = "A reset link has been sent to your email.";
            } else {
                $message = "Failed to send reset email. Please contact support.";
            }
        } else {
            $message = "Email not found.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reset Password</title>
<style>
body { font-family: Arial; background: #f7f7f7; }
.container { width: 400px; margin: 100px auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 0 5px #ccc; }
h2 { text-align: center; }
input[type=email] {
    width: 100%; padding: 10px; margin: 8px 0; border: 1px solid #ccc; border-radius: 5px;
}
button { width: 100%; padding: 10px; background: #28a745; color: #fff; border: none; border-radius: 5px; cursor: pointer; }
button:hover { background: #218838; }
.message { text-align: center; color: #333; margin-top: 10px; }
</style>
</head>
<body>
<div class="container">
    <h2>Reset Password</h2>
    <form method="POST">
        <input type="email" name="email" placeholder="Enter your email" required>
        <button type="submit">Send Reset Link</button>
    </form>
    <?php if ($message): ?><p class="message"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <a href="login.php">Back to Login</a>
</div>
</body>
</html>
