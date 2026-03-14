<?php
// ============================================================
// StockAxis IMS — Sign In Page
// File: signin.php
// ============================================================
session_start();
require_once __DIR__ . '/api/config.php';

// If already logged in → go to app
if (!empty($_SESSION['user'])) {
    header('Location: app.html');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email']    ?? ''));
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Save session
            $_SESSION['user'] = [
                'id'    => (int)$user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ];
            header('Location: app.html');
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — Veristock</title>
<link rel="stylesheet" href="theme.css">
<style>
body { display: flex; min-height: 100vh; background: var(--page); }

.left-panel {
  width: 420px; flex-shrink: 0;
  background: var(--surface); border-right: 1px solid var(--line);
  display: flex; flex-direction: column; padding: 48px 44px;
}
.right-panel {
  flex: 1; display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  padding: 48px; background: var(--page);
  position: relative; overflow: hidden;
}
.right-panel::before {
  content: ''; position: absolute; inset: 0;
  background-image: radial-gradient(circle at 1px 1px, var(--line) 1px, transparent 0);
  background-size: 28px 28px; opacity: 0.5;
}
.right-content { position: relative; z-index: 1; max-width: 340px; text-align: center; }
.right-stat {
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r2); padding: 20px 24px;
  box-shadow: var(--shadow); margin-bottom: 14px; text-align: left;
}
.right-stat-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-3); margin-bottom: 6px; }
.right-stat-value { font-size: 28px; font-weight: 700; color: var(--ink); font-family: var(--mono); letter-spacing: -1px; }
.right-stat-sub   { font-size: 12px; color: var(--ink-3); margin-top: 2px; }

.brand { margin-bottom: 44px; }
.brand-lockup { display: flex; align-items: center; gap: 10px; }
.brand-mark { width: 32px; height: 32px; background: var(--ink); border-radius: 7px; display: flex; align-items: center; justify-content: center; }
.brand-mark svg { width: 18px; height: 18px; fill: white; }
.brand-name { font-size: 17px; font-weight: 700; letter-spacing: -0.4px; color: var(--ink); }

.form-head { margin-bottom: 28px; }
.form-head h1 { font-size: 22px; font-weight: 700; color: var(--ink); letter-spacing: -0.4px; margin-bottom: 4px; }
.form-head p  { font-size: 14px; color: var(--ink-3); }

.input-wrap { position: relative; }
.input-wrap .form-input { padding-left: 38px; }
.input-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; fill: var(--ink-4); pointer-events: none; }
.eye-btn { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--ink-4); padding: 2px; display: flex; align-items: center; }
.eye-btn svg { width: 15px; height: 15px; fill: currentColor; }

.submit-btn {
  width: 100%; padding: 10px 16px; background: var(--ink);
  border: 1px solid var(--ink); border-radius: var(--r); color: white;
  font-size: 14px; font-weight: 600; font-family: var(--font);
  cursor: pointer; transition: all 0.12s;
}
.submit-btn:hover { background: #2d2c28; }

.link-row { display: flex; justify-content: space-between; align-items: center; margin-top: 18px; }
.link { font-size: 13px; color: var(--ink-3); text-decoration: none; }
.link:hover { color: var(--ink); }
.link.accent { color: var(--accent); font-weight: 500; }
.footer-note { margin-top: auto; padding-top: 24px; font-size: 12px; color: var(--ink-4); }
</style>
</head>
<body>

<div class="left-panel">
  <div class="brand">
    <div class="brand-lockup">
      <div class="brand-mark">
        <svg viewBox="0 0 24 24"><path d="M3 3h7v7H3zm0 11h7v7H3zm11-11h7v7h-7zm3 11v3h3v-3h-3zm-3 3h3v3h-3zm3-3h-3v-3h3v3z"/></svg>
      </div>
      <span class="brand-name">Veristock</span>
    </div>
  </div>

  <div class="form-head">
    <h1>Welcome back</h1>
    <p>Sign in to your inventory workspace</p>
  </div>

  <?php if ($error): ?>
    <div class="alert error show" style="margin-bottom:16px">
      <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="signin.php">
    <div class="form-group">
      <label class="form-label">Work email</label>
      <div class="input-wrap">
        <svg class="input-icon" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
        <input class="form-input" type="email" name="email" placeholder="you@company.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="email" required>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Password</label>
      <div class="input-wrap">
        <svg class="input-icon" viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
        <input class="form-input" type="password" name="password" id="password"
               placeholder="••••••••" style="padding-right:38px" autocomplete="current-password" required>
        <button class="eye-btn" type="button" onclick="togglePass()">
          <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
        </button>
      </div>
    </div>

    <button class="submit-btn" type="submit">Sign in</button>
  </form>

  <div class="link-row">
    <a href="signup.php" class="link accent">Create account</a>
    <a href="reset.php"  class="link">Forgot password?</a>
  </div>

  <div class="footer-note">© 2026 Veristock. Enterprise Inventory Management.</div>
</div>

<div class="right-panel">
  <div class="right-content">
    <div class="right-stat">
      <div class="right-stat-label">Real-time stock visibility</div>
      <div class="right-stat-value">1,248</div>
      <div class="right-stat-sub">products tracked across 3 warehouses</div>
    </div>
    <div class="right-stat">
      <div class="right-stat-label">Operations this month</div>
      <div class="right-stat-value">342</div>
      <div class="right-stat-sub">receipts, deliveries and transfers logged</div>
    </div>
    <div class="right-stat" style="margin-bottom:0">
      <div class="right-stat-label">Accuracy rate</div>
      <div class="right-stat-value" style="color:var(--pos)">99.4%</div>
      <div class="right-stat-sub">inventory counts matched to system</div>
    </div>
  </div>
</div>

<script>
function togglePass() {
  const f = document.getElementById('password');
  f.type = f.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>
