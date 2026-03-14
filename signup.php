<?php
// ============================================================
// StockAxis IMS — Sign Up Page
// File: signup.php
// ============================================================
session_start();
require_once __DIR__ . '/api/config.php';

// If already logged in → go to app
if (!empty($_SESSION['user'])) {
    header('Location: app.html');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';
    $role     = $_POST['role']     ?? 'Warehouse Staff';

    // Validate
    if (!$name || !$email || !$password) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $db = getDB();

        // Check duplicate email
        $chk = $db->prepare('SELECT id FROM users WHERE email = ?');
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $error = 'That email is already registered. Please sign in instead.';
        } else {
            // Create account
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare(
                'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$name, $email, $hash, $role]);
            $id = (int)$db->lastInsertId();

            // Auto login
            $_SESSION['user'] = [
                'id'    => $id,
                'name'  => $name,
                'email' => $email,
                'role'  => $role,
            ];
            header('Location: app.html');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account — Veristock</title>
<link rel="stylesheet" href="theme.css">
<style>
body {
  display: flex; align-items: center; justify-content: center;
  min-height: 100vh; background: var(--page); padding: 40px 20px;
}
body::before {
  content: ''; position: fixed; inset: 0;
  background-image: radial-gradient(circle at 1px 1px, var(--line) 1px, transparent 0);
  background-size: 28px 28px; opacity: 0.5;
}
.card {
  background: var(--surface); border: 1px solid var(--line);
  border-radius: var(--r3); padding: 36px 40px;
  width: 480px; position: relative; z-index: 1; box-shadow: var(--shadow-lg);
}
.brand { display: flex; align-items: center; gap: 9px; margin-bottom: 28px; }
.brand-mark { width: 28px; height: 28px; background: var(--ink); border-radius: 6px; display: flex; align-items: center; justify-content: center; }
.brand-mark svg { width: 16px; height: 16px; fill: white; }
.brand-name { font-size: 16px; font-weight: 700; letter-spacing: -0.3px; }

.card-head { margin-bottom: 26px; border-bottom: 1px solid var(--line); padding-bottom: 20px; }
.card-head h1 { font-size: 20px; font-weight: 700; letter-spacing: -0.3px; color: var(--ink); margin-bottom: 4px; }
.card-head p  { font-size: 13.5px; color: var(--ink-3); }

.input-wrap { position: relative; }
.input-wrap .form-input { padding-left: 38px; }
.input-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; fill: var(--ink-4); pointer-events: none; }

/* Role selector */
.role-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.role-opt  { display: none; }
.role-card {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 12px 14px; border-radius: var(--r);
  border: 1.5px solid var(--line); cursor: pointer;
  background: var(--surface-2); transition: all 0.12s;
}
.role-card:hover { border-color: var(--line-3); background: var(--surface); }
.role-opt:checked + .role-card { border-color: var(--accent); background: var(--accent-l); }
.role-icon { width: 30px; height: 30px; border-radius: 6px; background: var(--surface-4); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.role-icon svg { width: 16px; height: 16px; fill: var(--ink-2); }
.role-opt:checked + .role-card .role-icon { background: var(--accent-m); }
.role-opt:checked + .role-card .role-icon svg { fill: var(--accent); }
.role-info-title { font-size: 13px; font-weight: 600; color: var(--ink); margin-bottom: 1px; }
.role-info-desc  { font-size: 11.5px; color: var(--ink-3); line-height: 1.4; }

/* Strength bar */
.strength-row { display: flex; gap: 4px; margin-top: 7px; }
.seg { flex: 1; height: 3px; background: var(--surface-4); border-radius: 2px; transition: background 0.25s; }
.strength-text { font-size: 11px; color: var(--ink-4); margin-top: 4px; font-weight: 500; }

.submit-btn {
  width: 100%; padding: 10px 16px; background: var(--ink);
  border: 1px solid var(--ink); border-radius: var(--r); color: white;
  font-size: 14px; font-weight: 600; font-family: var(--font);
  cursor: pointer; transition: all 0.12s; margin-top: 4px;
}
.submit-btn:hover { background: #2d2c28; }

.footer-link { text-align: center; margin-top: 20px; font-size: 13px; color: var(--ink-3); }
.footer-link a { color: var(--accent); font-weight: 500; text-decoration: none; }
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="brand-mark"><svg viewBox="0 0 24 24"><path d="M3 3h7v7H3zm0 11h7v7H3zm11-11h7v7h-7zm3 11v3h3v-3h-3zm-3 3h3v3h-3zm3-3h-3v-3h3v3z"/></svg></div>
    <span class="brand-name">Veristock</span>
  </div>

  <div class="card-head">
    <h1>Create your account</h1>
    <p>Set up your Veristock workspace in under a minute.</p>
  </div>

  <?php if ($error): ?>
    <div class="alert error show" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="signup.php">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Full name</label>
        <div class="input-wrap">
          <svg class="input-icon" viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
          <input class="form-input" type="text" name="name" placeholder="Full name"
                 value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" autocomplete="name" required>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Work email</label>
        <div class="input-wrap">
          <svg class="input-icon" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
          <input class="form-input" type="email" name="email" placeholder="you@company.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="email" required>
        </div>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Role</label>
      <div class="role-grid">
        <label>
          <input class="role-opt" type="radio" name="role" value="Inventory Manager"
                 <?= (($_POST['role'] ?? 'Inventory Manager') === 'Inventory Manager') ? 'checked' : '' ?>>
          <div class="role-card">
            <div class="role-icon"><svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg></div>
            <div>
              <div class="role-info-title">Inventory Manager</div>
              <div class="role-info-desc">Stock control & analytics</div>
            </div>
          </div>
        </label>
        <label>
          <input class="role-opt" type="radio" name="role" value="Warehouse Staff"
                 <?= (($_POST['role'] ?? '') === 'Warehouse Staff') ? 'checked' : '' ?>>
          <div class="role-card">
            <div class="role-icon"><svg viewBox="0 0 24 24"><path d="M22 9V7h-2V5c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-2h2v-2h-2v-2h2v-2h-2V9h2zm-4 10H4V5h14v14z"/></svg></div>
            <div>
              <div class="role-info-title">Warehouse Staff</div>
              <div class="role-info-desc">Picking, transfers & counts</div>
            </div>
          </div>
        </label>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Password</label>
        <input class="form-input" type="password" name="password" id="password"
               placeholder="Create password" oninput="checkStr(this.value)" required>
        <div class="strength-row">
          <div class="seg" id="s1"></div><div class="seg" id="s2"></div>
          <div class="seg" id="s3"></div><div class="seg" id="s4"></div>
        </div>
        <div class="strength-text" id="str-lbl"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Confirm password</label>
        <input class="form-input" type="password" name="confirm"
               placeholder="Re-enter password" required>
      </div>
    </div>

    <button class="submit-btn" type="submit">Create account</button>
  </form>

  <div class="footer-link">Already have an account? <a href="signin.php">Sign in</a></div>
</div>

<script>
function checkStr(v) {
  let s = 0;
  if (v.length >= 8) s++;
  if (/[A-Z]/.test(v)) s++;
  if (/[0-9]/.test(v)) s++;
  if (/[^A-Za-z0-9]/.test(v)) s++;
  const clrs = ['','#a12020','#8a5a00','#1a5a8a','#1a7a4a'];
  const lbls = ['','Weak','Fair','Good','Strong'];
  ['s1','s2','s3','s4'].forEach((id,i) => {
    document.getElementById(id).style.background = i < s ? clrs[s] : 'var(--surface-4)';
  });
  const lbl = document.getElementById('str-lbl');
  lbl.textContent = v.length ? lbls[s] : '';
  lbl.style.color = clrs[s];
}
</script>
</body>
</html>
