<?php
// ============================================================
// StockAxis IMS — Mobile Sign Up
// File: mobile/signup.php
// ============================================================
session_start();
require_once __DIR__ . '/../api/config.php';

if (!empty($_SESSION['user'])) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name']  ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';
    $conf  = $_POST['confirm']  ?? '';
    $role  = $_POST['role']     ?? 'Warehouse Staff';
    if (!$name || !$email || !$pass) {
        $error = 'All fields are required.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $conf) {
        $error = 'Passwords do not match.';
    } else {
        $db  = getDB();
        $chk = $db->prepare('SELECT id FROM users WHERE email=?');
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $error = 'That email is already registered.';
        } else {
            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $db->prepare('INSERT INTO users (name,email,password,role) VALUES (?,?,?,?)')->execute([$name,$email,$hash,$role]);
            $id = (int)$db->lastInsertId();
            $_SESSION['user'] = ['id'=>$id,'name'=>$name,'email'=>$email,'role'=>$role];
            session_regenerate_id(true);
            header('Location: index.php'); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
<meta name="theme-color" content="#0f0f0f">
<title>StockAxis — Create Account</title>
<link rel="stylesheet" href="style.css">
</head>
<body style="overflow:auto">
<div style="min-height:100dvh;display:flex;flex-direction:column;padding:var(--safe-top) 0 var(--safe-bot);background:var(--bg)">
  <div style="flex:1;padding:28px 24px 40px;display:flex;flex-direction:column;max-width:430px;margin:0 auto;width:100%">

    <div class="auth-heading">Create account</div>
    <div class="auth-sub">Set up your workspace in under a minute.</div>

    <?php if ($error): ?>
      <div class="auth-error show"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="signup.php">
      <div class="field-row">
        <div class="field-group">
          <div class="field-label">Full name</div>
          <input class="field-input" type="text" name="name" placeholder="Your name"
                 value="<?= e($_POST['name'] ?? '') ?>" required>
        </div>
        <div class="field-group">
          <div class="field-label">Role</div>
          <select class="field-select" name="role">
            <option value="Inventory Manager" <?= (($_POST['role']??'')===('Inventory Manager')?'selected':'') ?>>Manager</option>
            <option value="Warehouse Staff"   <?= (($_POST['role']??'Warehouse Staff')==='Warehouse Staff'?'selected':'') ?>>Staff</option>
          </select>
        </div>
      </div>
      <div class="field-group">
        <div class="field-label">Work email</div>
        <input class="field-input" type="email" name="email" placeholder="you@company.com"
               value="<?= e($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="field-row">
        <div class="field-group">
          <div class="field-label">Password</div>
          <input class="field-input" type="password" name="password" placeholder="8+ chars" required>
        </div>
        <div class="field-group">
          <div class="field-label">Confirm</div>
          <input class="field-input" type="password" name="confirm" placeholder="Repeat" required>
        </div>
      </div>
      <button class="auth-btn" type="submit">Create account</button>
    </form>

    <div class="auth-footer"><a href="signin.php">← Back to sign in</a></div>
  </div>
</div>
</body>
</html>
