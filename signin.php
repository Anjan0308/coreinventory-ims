<?php
// ============================================================
// StockAxis IMS — Mobile Sign In
// File: mobile/signin.php
// ============================================================
session_start();
require_once __DIR__ . '/../api/config.php';

if (!empty($_SESSION['user'])) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';
    if (!$email || !$pass) {
        $error = 'Please enter your email and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($pass, $user['password'])) {
            $_SESSION['user'] = ['id'=>(int)$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role']];
            session_regenerate_id(true);
            header('Location: index.php'); exit;
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
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="theme-color" content="#0f0f0f">
<title>StockAxis — Sign In</title>
<link rel="stylesheet" href="style.css">
</head>
<body style="overflow:auto">
<div style="min-height:100dvh;display:flex;flex-direction:column;padding:var(--safe-top) 0 var(--safe-bot);background:var(--bg)">
  <div style="flex:1;padding:28px 24px 40px;display:flex;flex-direction:column;max-width:430px;margin:0 auto;width:100%">

    <div class="auth-logo">
      <div class="logo-mark" style="width:38px;height:38px;border-radius:11px">
        <svg viewBox="0 0 24 24" style="width:22px;height:22px"><path d="M3 3h7v7H3zm0 11h7v7H3zm11-11h7v7h-7zm3 11v3h3v-3h-3zm-3 3h3v3h-3zm3-3h-3v-3h3v3z"/></svg>
      </div>
      <div>
        <div style="font-size:19px;font-weight:700;letter-spacing:-.4px">StockAxis</div>
        <div style="font-size:10px;color:var(--ink4);font-family:var(--mono)">IMS v2.1</div>
      </div>
    </div>

    <div class="auth-heading">Welcome back</div>
    <div class="auth-sub">Sign in to your inventory workspace</div>

    <?php if ($error): ?>
      <div class="auth-error show"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="signin.php">
      <div class="field-group">
        <div class="field-label">Work email</div>
        <input class="field-input" type="email" name="email" placeholder="you@company.com"
               value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email" required>
      </div>
      <div class="field-group">
        <div class="field-label">Password</div>
        <input class="field-input" type="password" name="password" placeholder="••••••••"
               autocomplete="current-password" required>
      </div>
      <button class="auth-btn" type="submit">Sign in</button>
    </form>

    <div class="auth-footer">
      No account? <a href="signup.php">Create one</a> &nbsp;·&nbsp;
      <a href="reset.php">Forgot password?</a>
    </div>
  </div>
</div>
</body>
</html>
