<?php
// ============================================================
// StockAxis IMS — Mobile Password Reset
// File: mobile/reset.php
// ============================================================
session_start();
require_once __DIR__ . '/../api/config.php';

if (isset($_GET['restart'])) {
    unset($_SESSION['mob_reset_step'],$_SESSION['mob_reset_email'],$_SESSION['mob_reset_otp'],$_SESSION['mob_reset_verified']);
    header('Location: reset.php'); exit;
}

$step     = (int)($_SESSION['mob_reset_step'] ?? 1);
$email    = $_SESSION['mob_reset_email'] ?? '';
$otp_demo = '';
$error    = '';
$success  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_otp') {
        $em = strtolower(trim($_POST['email'] ?? ''));
        if (!$em) { $error = 'Please enter your email.'; }
        else {
            $db = getDB();
            $s  = $db->prepare('SELECT id FROM users WHERE email=?'); $s->execute([$em]);
            if (!$s->fetch()) { $error = 'No account found with that email.'; }
            else {
                $otp = str_pad(random_int(0,999999),6,'0',STR_PAD_LEFT);
                $exp = date('Y-m-d H:i:s', time()+600);
                $db->prepare('UPDATE otp_tokens SET used=1 WHERE email=? AND used=0')->execute([$em]);
                $db->prepare('INSERT INTO otp_tokens (email,otp,expires_at) VALUES (?,?,?)')->execute([$em,$otp,$exp]);
                $_SESSION['mob_reset_step']  = 2;
                $_SESSION['mob_reset_email'] = $em;
                $_SESSION['mob_reset_otp']   = $otp;
                $step = 2; $email = $em; $otp_demo = $otp;
                $success = 'Code sent! (Demo: shown below)';
            }
        }
    }

    elseif ($action === 'verify_otp') {
        $entered = trim($_POST['otp'] ?? '');
        if (strlen($entered) !== 6) { $error = 'Enter the complete 6-digit code.'; $step = 2; }
        else {
            $db = getDB();
            $s  = $db->prepare('SELECT id FROM otp_tokens WHERE email=? AND otp=? AND used=0 AND expires_at>NOW() ORDER BY id DESC LIMIT 1');
            $s->execute([$email, $entered]);
            $rec = $s->fetch();
            if (!$rec) { $error = 'Incorrect or expired code.'; $step = 2; }
            else {
                $db->prepare('UPDATE otp_tokens SET used=1 WHERE id=?')->execute([$rec['id']]);
                $_SESSION['mob_reset_step']     = 3;
                $_SESSION['mob_reset_verified'] = true;
                $step = 3;
            }
        }
    }

    elseif ($action === 'reset_pass') {
        if (empty($_SESSION['mob_reset_verified'])) { $error = 'Session expired.'; $step = 1; }
        else {
            $np = $_POST['new_pass'] ?? ''; $cp = $_POST['conf_pass'] ?? '';
            if (strlen($np) < 8)  { $error = 'Password must be 8+ characters.'; $step = 3; }
            elseif ($np !== $cp)  { $error = 'Passwords do not match.'; $step = 3; }
            else {
                $db   = getDB();
                $hash = password_hash($np, PASSWORD_BCRYPT);
                $db->prepare('UPDATE users SET password=? WHERE email=?')->execute([$hash,$email]);
                unset($_SESSION['mob_reset_step'],$_SESSION['mob_reset_email'],$_SESSION['mob_reset_otp'],$_SESSION['mob_reset_verified']);
                $step = 4;
            }
        }
    }
}
if ($step===2 && empty($otp_demo)) $otp_demo = $_SESSION['mob_reset_otp'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
<meta name="theme-color" content="#0f0f0f">
<title>StockAxis — Reset Password</title>
<link rel="stylesheet" href="style.css">
</head>
<body style="overflow:auto">
<div style="min-height:100dvh;display:flex;flex-direction:column;padding:var(--safe-top) 0 var(--safe-bot);background:var(--bg)">
  <div style="flex:1;padding:28px 24px 40px;display:flex;flex-direction:column;max-width:430px;margin:0 auto;width:100%">

    <!-- step indicators -->
    <div style="display:flex;gap:6px;margin-bottom:28px">
      <?php for($i=1;$i<=3;$i++): $active=$step>=$i; ?>
        <div style="height:3px;flex:1;border-radius:2px;background:<?= $active?'var(--accent)':'var(--bg4)' ?>;transition:background .3s"></div>
      <?php endfor; ?>
    </div>

    <?php if ($step===1): ?>
      <div class="auth-heading">Reset password</div>
      <div class="auth-sub">Enter your email and we'll send a verification code.</div>
      <?php if ($error): ?><div class="auth-error show"><?= e($error) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="send_otp">
        <div class="field-group">
          <div class="field-label">Work email</div>
          <input class="field-input" type="email" name="email" placeholder="you@company.com" required>
        </div>
        <button class="auth-btn" type="submit">Send code</button>
      </form>

    <?php elseif ($step===2): ?>
      <div class="auth-heading">Enter code</div>
      <div class="auth-sub">A 6-digit code was generated for <strong><?= e($email) ?></strong>.</div>
      <?php if ($otp_demo): ?>
        <div style="background:var(--warn-bg);border:1px solid rgba(245,166,35,.3);border-radius:var(--r);padding:12px 14px;margin-bottom:16px;font-size:13px;color:var(--warn)">
          Demo code: <strong style="font-family:var(--mono);font-size:18px;letter-spacing:4px"><?= e($otp_demo) ?></strong>
        </div>
      <?php endif; ?>
      <?php if ($error): ?><div class="auth-error show"><?= e($error) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="verify_otp">
        <div class="field-group">
          <div class="field-label">6-digit code</div>
          <input class="field-input" type="text" name="otp" placeholder="000000" maxlength="6"
                 inputmode="numeric" style="font-family:var(--mono);font-size:24px;letter-spacing:10px;text-align:center" required>
        </div>
        <button class="auth-btn" type="submit">Verify code</button>
      </form>
      <div class="auth-footer"><a href="reset.php?restart=1">Start over</a></div>

    <?php elseif ($step===3): ?>
      <div class="auth-heading">New password</div>
      <div class="auth-sub">Choose a strong password — at least 8 characters.</div>
      <?php if ($error): ?><div class="auth-error show"><?= e($error) ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="action" value="reset_pass">
        <div class="field-group">
          <div class="field-label">New password</div>
          <input class="field-input" type="password" name="new_pass" placeholder="New password" required>
        </div>
        <div class="field-group">
          <div class="field-label">Confirm password</div>
          <input class="field-input" type="password" name="conf_pass" placeholder="Re-enter password" required>
        </div>
        <button class="auth-btn" type="submit">Update password</button>
      </form>

    <?php elseif ($step===4): ?>
      <div style="display:flex;flex-direction:column;align-items:center;text-align:center;padding:32px 0">
        <div style="width:64px;height:64px;border-radius:50%;background:var(--pos-bg);border:1px solid rgba(45,212,160,.3);display:flex;align-items:center;justify-content:center;margin-bottom:20px">
          <svg viewBox="0 0 24 24" style="width:30px;height:30px;fill:var(--pos)"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
        </div>
        <div style="font-size:20px;font-weight:700;margin-bottom:8px">Password updated!</div>
        <div style="font-size:13px;color:var(--ink3);margin-bottom:28px">You can now sign in with your new credentials.</div>
        <a href="signin.php" class="auth-btn" style="text-decoration:none;display:block;text-align:center">Go to sign in</a>
      </div>
    <?php endif; ?>

    <?php if ($step < 4): ?>
      <div class="auth-footer"><a href="signin.php">← Back to sign in</a></div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
