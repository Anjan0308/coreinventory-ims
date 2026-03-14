<?php
// ============================================================
// StockAxis IMS — Reset Password Page
// File: reset.php
// ============================================================
session_start();
require_once __DIR__ . '/api/config.php';

$step    = (int)($_SESSION['reset_step']  ?? 1);
$email   = $_SESSION['reset_email'] ?? '';
$error   = '';
$success = '';
$otp_demo = ''; // shown on screen for demo (no real email)

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // STEP 1 — submit email
    if ($action === 'send_otp') {
        $em = strtolower(trim($_POST['email'] ?? ''));
        if (!$em) {
            $error = 'Please enter your email.';
        } else {
            $db   = getDB();
            $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$em]);
            if (!$stmt->fetch()) {
                $error = 'No account found with that email address.';
            } else {
                // Generate OTP
                $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $expires = date('Y-m-d H:i:s', time() + 600);
                // Invalidate old OTPs
                $db->prepare('UPDATE otp_tokens SET used=1 WHERE email=? AND used=0')->execute([$em]);
                $db->prepare('INSERT INTO otp_tokens (email, otp, expires_at) VALUES (?,?,?)')->execute([$em, $otp, $expires]);

                $_SESSION['reset_email'] = $em;
                $_SESSION['reset_step']  = 2;
                $_SESSION['reset_otp_demo'] = $otp; // demo only
                $step     = 2;
                $email    = $em;
                $otp_demo = $otp;
                $success  = "Code sent! (Demo: your code is shown below)";
            }
        }
    }

    // STEP 2 — verify OTP
    elseif ($action === 'verify_otp') {
        $entered = trim($_POST['otp'] ?? '');
        if (strlen($entered) !== 6) {
            $error = 'Please enter the complete 6-digit code.';
            $step  = 2;
        } else {
            $db   = getDB();
            $stmt = $db->prepare(
                'SELECT id FROM otp_tokens
                  WHERE email=? AND otp=? AND used=0 AND expires_at > NOW()
                  ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$email, $entered]);
            $rec = $stmt->fetch();
            if (!$rec) {
                $error = 'Incorrect or expired code. Please try again.';
                $step  = 2;
            } else {
                $db->prepare('UPDATE otp_tokens SET used=1 WHERE id=?')->execute([$rec['id']]);
                $_SESSION['reset_step']     = 3;
                $_SESSION['reset_verified'] = true;
                $step = 3;
            }
        }
    }

    // STEP 3 — set new password
    elseif ($action === 'reset_pass') {
        if (empty($_SESSION['reset_verified'])) {
            $error = 'Session expired. Please start again.';
            $step  = 1;
            unset($_SESSION['reset_step'], $_SESSION['reset_email'], $_SESSION['reset_verified']);
        } else {
            $np = $_POST['new_pass']  ?? '';
            $cp = $_POST['conf_pass'] ?? '';
            if (strlen($np) < 8) {
                $error = 'Password must be at least 8 characters.';
                $step  = 3;
            } elseif ($np !== $cp) {
                $error = 'Passwords do not match.';
                $step  = 3;
            } else {
                $db   = getDB();
                $hash = password_hash($np, PASSWORD_BCRYPT);
                $db->prepare('UPDATE users SET password=? WHERE email=?')->execute([$hash, $email]);
                // Clear reset session
                unset($_SESSION['reset_step'], $_SESSION['reset_email'],
                      $_SESSION['reset_verified'], $_SESSION['reset_otp_demo']);
                $step = 4; // success
            }
        }
    }
}

// Restore demo OTP for display if on step 2
if ($step === 2 && empty($otp_demo)) {
    $otp_demo = $_SESSION['reset_otp_demo'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — Veristock</title>
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
  border-radius: var(--r3); padding: 36px 40px; width: 460px;
  position: relative; z-index: 1; box-shadow: var(--shadow-lg);
}
.brand { display: flex; align-items: center; gap: 9px; margin-bottom: 28px; }
.brand-mark { width: 28px; height: 28px; background: var(--ink); border-radius: 6px; display: flex; align-items: center; justify-content: center; }
.brand-mark svg { width: 16px; height: 16px; fill: white; }
.brand-name { font-size: 16px; font-weight: 700; letter-spacing: -0.3px; }

/* Step track */
.step-track { display: flex; align-items: center; margin-bottom: 28px; }
.step-node  { display: flex; align-items: center; gap: 7px; }
.step-circle {
  width: 24px; height: 24px; border-radius: 50%;
  border: 1.5px solid var(--line-2); display: flex; align-items: center;
  justify-content: center; font-size: 11px; font-weight: 700;
  color: var(--ink-4); font-family: var(--mono); flex-shrink: 0;
  background: var(--surface);
}
.step-circle.active { border-color: var(--accent); color: var(--accent); background: var(--accent-l); }
.step-circle.done   { border-color: var(--pos);    color: white;         background: var(--pos); }
.step-label  { font-size: 12px; font-weight: 500; color: var(--ink-4); }
.step-label.active { color: var(--ink-2); }
.step-label.done   { color: var(--pos); }
.step-connector { flex: 1; height: 1px; background: var(--line); margin: 0 10px; }
.step-connector.done { background: var(--pos); }

.step-title { font-size: 19px; font-weight: 700; letter-spacing: -0.3px; margin-bottom: 4px; }
.step-desc  { font-size: 13.5px; color: var(--ink-3); margin-bottom: 22px; line-height: 1.5; }

.input-wrap { position: relative; }
.input-wrap .form-input { padding-left: 38px; }
.input-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; fill: var(--ink-4); pointer-events: none; }

.submit-btn {
  width: 100%; padding: 10px 16px; background: var(--ink);
  border: 1px solid var(--ink); border-radius: var(--r); color: white;
  font-size: 14px; font-weight: 600; font-family: var(--font); cursor: pointer;
}
.submit-btn:hover { background: #2d2c28; }

/* OTP boxes */
.otp-row { display: flex; gap: 8px; margin-bottom: 18px; }
.otp-box {
  flex: 1; height: 48px; text-align: center;
  background: var(--surface); border: 1.5px solid var(--line-2);
  border-radius: var(--r); color: var(--ink);
  font-size: 20px; font-family: var(--mono); font-weight: 700; outline: none;
}
.otp-box:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(61,82,213,0.10); }

/* Demo hint box */
.demo-hint {
  background: var(--warn-bg); border: 1px solid var(--warn-line);
  border-radius: var(--r); padding: 10px 14px; margin-bottom: 16px;
  font-size: 12.5px; color: var(--warn);
}
.demo-hint strong { font-size: 16px; font-family: var(--mono); letter-spacing: 3px; }

/* Success */
.success-wrap { text-align: center; padding: 12px 0; }
.check-icon {
  width: 52px; height: 52px; border-radius: 50%;
  background: var(--pos-bg); border: 1px solid var(--pos-line);
  display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;
}
.check-icon svg { width: 26px; height: 26px; fill: var(--pos); }

.back-link { display: block; text-align: center; margin-top: 20px; font-size: 13px; color: var(--ink-3); text-decoration: none; }
.back-link:hover { color: var(--ink); }
</style>
</head>
<body>
<div class="card">

  <div class="brand">
    <div class="brand-mark"><svg viewBox="0 0 24 24"><path d="M3 3h7v7H3zm0 11h7v7H3zm11-11h7v7h-7zm3 11v3h3v-3h-3zm-3 3h3v3h-3zm3-3h-3v-3h3v3z"/></svg></div>
    <span class="brand-name">Veristock</span>
  </div>

  <!-- Step tracker -->
  <?php
    $s1c = $step >= 2 ? 'done' : ($step == 1 ? 'active' : '');
    $s2c = $step >= 3 ? 'done' : ($step == 2 ? 'active' : '');
    $s3c = $step >= 4 ? 'done' : ($step == 3 ? 'active' : '');
    $c1  = $step >= 2 ? 'done' : '';
    $c2  = $step >= 3 ? 'done' : '';
  ?>
  <div class="step-track">
    <div class="step-node">
      <div class="step-circle <?= $s1c ?>"><?= $step >= 2 ? '✓' : '1' ?></div>
      <div class="step-label <?= $s1c ?>">Email</div>
    </div>
    <div class="step-connector <?= $c1 ?>"></div>
    <div class="step-node">
      <div class="step-circle <?= $s2c ?>"><?= $step >= 3 ? '✓' : '2' ?></div>
      <div class="step-label <?= $s2c ?>">Verify</div>
    </div>
    <div class="step-connector <?= $c2 ?>"></div>
    <div class="step-node">
      <div class="step-circle <?= $s3c ?>"><?= $step >= 4 ? '✓' : '3' ?></div>
      <div class="step-label <?= $s3c ?>">New password</div>
    </div>
  </div>

  <!-- Alerts -->
  <?php if ($error):   ?><div class="alert error show"   style="margin-bottom:16px"><?= htmlspecialchars($error)   ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert success show" style="margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <!-- ── STEP 1: Enter email ── -->
  <?php if ($step === 1): ?>
    <div class="step-title">Forgot your password?</div>
    <div class="step-desc">Enter your registered email and we'll give you a verification code.</div>
    <form method="POST" action="reset.php">
      <input type="hidden" name="action" value="send_otp">
      <div class="form-group">
        <label class="form-label">Work email</label>
        <div class="input-wrap">
          <svg class="input-icon" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
          <input class="form-input" type="email" name="email" placeholder="you@company.com" required>
        </div>
      </div>
      <button class="submit-btn" type="submit">Send verification code</button>
    </form>
    <a href="signin.php" class="back-link">← Back to sign in</a>

  <!-- ── STEP 2: Enter OTP ── -->
  <?php elseif ($step === 2): ?>
    <div class="step-title">Enter verification code</div>
    <div class="step-desc">We generated a 6-digit code for <strong><?= htmlspecialchars($email) ?></strong>.</div>

    <?php if ($otp_demo): ?>
    <div class="demo-hint">
      Demo code (no real email sent): <strong><?= htmlspecialchars($otp_demo) ?></strong>
    </div>
    <?php endif; ?>

    <form method="POST" action="reset.php" id="otp-form">
      <input type="hidden" name="action" value="verify_otp">
      <input type="hidden" name="otp"    id="otp-hidden" value="">
      <div class="otp-row" id="otp-row"></div>
      <button class="submit-btn" type="submit" onclick="collectOTP()">Verify code</button>
    </form>
    <a href="reset.php?restart=1" class="back-link">← Start over</a>

  <!-- ── STEP 3: New password ── -->
  <?php elseif ($step === 3): ?>
    <div class="step-title">Set a new password</div>
    <div class="step-desc">Choose a strong password — at least 8 characters.</div>
    <form method="POST" action="reset.php">
      <input type="hidden" name="action" value="reset_pass">
      <div class="form-group">
        <label class="form-label">New password</label>
        <input class="form-input" type="password" name="new_pass" placeholder="New password" required>
      </div>
      <div class="form-group">
        <label class="form-label">Confirm new password</label>
        <input class="form-input" type="password" name="conf_pass" placeholder="Re-enter new password" required>
      </div>
      <button class="submit-btn" type="submit">Update password</button>
    </form>

  <!-- ── STEP 4: Success ── -->
  <?php elseif ($step === 4): ?>
    <div class="success-wrap">
      <div class="check-icon"><svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></div>
      <div style="font-size:19px;font-weight:700;margin-bottom:6px">Password updated!</div>
      <div style="font-size:13.5px;color:var(--ink-3);margin-bottom:24px;line-height:1.6">
        Your password has been changed. You can now sign in with your new credentials.
      </div>
      <a href="signin.php" class="submit-btn" style="display:inline-block;text-align:center;text-decoration:none">
        Go to sign in
      </a>
    </div>
  <?php endif; ?>

</div>

<?php
// Restart: clear session and redirect
if (isset($_GET['restart'])) {
    unset($_SESSION['reset_step'], $_SESSION['reset_email'],
          $_SESSION['reset_verified'], $_SESSION['reset_otp_demo']);
    header('Location: reset.php');
    exit;
}
?>

<script>
// Build 6 OTP input boxes
(function() {
  const row = document.getElementById('otp-row');
  if (!row) return;
  for (let i = 0; i < 6; i++) {
    const b = document.createElement('input');
    b.className = 'otp-box'; b.maxLength = 1; b.type = 'text'; b.inputMode = 'numeric';
    b.addEventListener('input', function() {
      if (this.value && this.nextElementSibling) this.nextElementSibling.focus();
    });
    b.addEventListener('keydown', function(e) {
      if (e.key === 'Backspace' && !this.value && this.previousElementSibling) {
        this.previousElementSibling.value = '';
        this.previousElementSibling.focus();
      }
    });
    row.appendChild(b);
  }
})();

function collectOTP() {
  const boxes = document.querySelectorAll('.otp-box');
  document.getElementById('otp-hidden').value = Array.from(boxes).map(b => b.value).join('');
}
</script>
</body>
</html>
