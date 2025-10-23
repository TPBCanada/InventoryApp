<?php
session_start();
require_once 'dbusers.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role_id, first_name, last_name FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['first_name']= $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['role_id']   = $user['role_id'];
                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "User not found.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>TPB Whse Login</title>
<style>
:root{
  --bg1:#0f172a;      /* slate-900 */
  --bg2:#1e293b;      /* slate-800 */
  --card:#0b1220e6;   /* translucent */
  --text:#e5e7eb;     /* gray-200 */
  --muted:#94a3b8;    /* slate-400 */
  --primary:#3b82f6;  /* blue-500 */
  --primary-600:#2563eb;
  --ring:#93c5fd;     /* blue-300 */
  --danger:#ef4444;   /* red-500 */
  --shadow: 0 10px 30px rgba(2,6,23,.45), inset 0 1px 0 rgba(255,255,255,.06);
}

@media (prefers-color-scheme: light){
  :root{
    --bg1:#f7f7fb;
    --bg2:#eef1f6;
    --card:#ffffffee;
    --text:#0f172a;
    --muted:#475569;
    --primary:#2563eb;
    --primary-600:#1d4ed8;
    --ring:#93c5fd;
    --shadow: 0 10px 30px rgba(2,6,23,.08), inset 0 1px 0 rgba(255,255,255,.9);
  }
}

*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  font-family: ui-sans-serif, -apple-system, Segoe UI, Roboto, Helvetica, Arial, "Apple Color Emoji","Segoe UI Emoji";
  color:var(--text);
  background:
    radial-gradient(1200px 600px at 10% 10%, var(--bg2), transparent 60%),
    radial-gradient(1200px 600px at 90% 90%, var(--bg2), transparent 60%),
    linear-gradient(135deg, var(--bg1), var(--bg2));
  display:grid;
  place-items:center;
  padding:24px;
}

.card{
  width:min(420px, 92vw);
  border-radius:18px;
  background:var(--card);
  box-shadow:var(--shadow);
  backdrop-filter: blur(10px);
  border:1px solid rgba(148,163,184,.15);
  padding:28px 26px 22px;
  animation: floatIn .4s ease-out both;
}

@keyframes floatIn {
  from { transform: translateY(8px); opacity:0; }
  to   { transform: translateY(0);   opacity:1; }
}

.logo{
  display:flex; align-items:center; justify-content:center;
  margin-bottom:12px;
}
.logo img{
  height:64px; width:auto; display:block; object-fit:contain;
  filter: drop-shadow(0 2px 8px rgba(0,0,0,.25));
}

h1{
  font-size:1.4rem; text-align:center; margin:6px 0 18px; letter-spacing:.2px;
}

.form{
  display:grid; gap:12px;
}

.label{
  font-size:.9rem; color:var(--muted); margin-bottom:4px; display:block;
}

.input{
  width:100%;
  border:1px solid rgba(148,163,184,.35);
  background:rgba(2,6,23,.04);
  color:inherit;
  border-radius:12px;
  padding:12px 14px;
  outline:none;
  transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
}
.input::placeholder{ color: var(--muted); opacity:.8; }
.input:focus{
  border-color: var(--primary);
  box-shadow: 0 0 0 4px color-mix(in oklab, var(--ring) 35%, transparent);
  background: rgba(2,6,23,.02);
}

.password-wrap{
  position:relative;
}
.toggle-pass{
  position:absolute; right:10px; top:50%; transform:translateY(-50%);
  border:none; background:transparent; color:var(--muted);
  cursor:pointer; padding:6px; border-radius:8px;
}
.toggle-pass:focus-visible{
  outline:2px solid var(--ring); outline-offset:2px;
}

.btn{
  width:100%; padding:12px 14px; border:none; cursor:pointer;
  border-radius:12px; font-weight:600; letter-spacing:.2px;
  background: linear-gradient(180deg, var(--primary), var(--primary-600));
  color:white;
  box-shadow: 0 8px 20px rgba(37,99,235,.35);
  transition: transform .06s ease, box-shadow .2s ease, filter .2s ease;
}
.btn:hover{ filter:brightness(1.05); box-shadow: 0 10px 22px rgba(37,99,235,.45); }
.btn:active{ transform: translateY(1px); }

.meta{
  display:flex; justify-content:space-between; align-items:center; margin-top:10px;
}
.link{
  color:var(--primary); text-decoration:none; font-weight:600; font-size:.95rem;
}
.link:hover{ text-decoration:underline; }

.error{
  background: color-mix(in oklab, var(--danger) 12%, transparent);
  border:1px solid color-mix(in oklab, var(--danger) 40%, transparent);
  color: color-mix(in oklab, var(--danger) 90%, white 10%);
  padding:10px 12px; border-radius:10px;
  font-size:.95rem; margin: 0 0 14px; text-align:center;
}

.helper{
  color:var(--muted); font-size:.9rem; text-align:center; margin-top:8px;
}
.small{
  font-size:.85rem; color:var(--muted);
}
</style>
</head>
<body>

<main class="card" role="main" aria-labelledby="login-title">
  <div class="logo">
    <img src="img/tpbc.jpg" alt="TPB Warehouse" />
  </div>

  <h1 id="login-title">Sign in to TPB Warehouse</h1>

  <?php if ($error): ?>
    <p class="error" role="alert" aria-live="polite"><?= htmlspecialchars($error) ?></p>
  <?php endif; ?>

  <form class="form" method="POST" action="" novalidate>
    <label class="label" for="username">Username or Email</label>
    <input
      class="input"
      type="text"
      id="username"
      name="username"
      placeholder="you@example.com"
      autocomplete="username"
      required />

    <label class="label" for="password">Password</label>
    <div class="password-wrap">
      <input
        class="input"
        type="password"
        id="password"
        name="password"
        placeholder="••••••••"
        autocomplete="current-password"
        required />
      <button type="button" class="toggle-pass" aria-label="Show password" title="Show/Hide password" onclick="(function(btn){
        const input = document.getElementById('password');
        const isPw = input.type === 'password';
        input.type = isPw ? 'text' : 'password';
        btn.setAttribute('aria-label', isPw ? 'Hide password' : 'Show password');
      })(this)">
        👁️
      </button>
    </div>

    <button class="btn" type="submit">Login</button>

    <div class="meta">
      <span class="small">&nbsp;</span>
      <a class="link" href="reset_password.php">Forgot your password?</a>
    </div>
  </form>

  <p class="helper">Need access? Contact your administrator.</p>
</main>

</body>
</html>
