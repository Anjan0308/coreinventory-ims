<?php
// ============================================================
// StockAxis IMS — Auth API
// File: api/auth.php
// Endpoints (POST):
//   ?action=register   — create new user
//   ?action=login      — sign in
//   ?action=logout     — destroy session
//   ?action=me         — return current session user
//   ?action=gen_otp    — generate & return OTP for password reset
//   ?action=verify_otp — verify OTP code
//   ?action=reset_pass — set new password after OTP verified
// ============================================================

require_once __DIR__ . '/config.php';
session_start();

$action = $_GET['action'] ?? '';
$body   = getBody();

switch ($action) {

    // ── REGISTER ─────────────────────────────────────────────
    case 'register':
        $name     = trim($body['name']     ?? '');
        $email    = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';
        $role     = $body['role']     ?? 'Warehouse Staff';

        if (!$name || !$email || !$password) {
            jsonOut(['ok' => false, 'error' => 'Name, email and password are required.'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonOut(['ok' => false, 'error' => 'Invalid email address.'], 400);
        }
        if (strlen($password) < 8) {
            jsonOut(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 400);
        }

        $db = getDB();

        // Check duplicate
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonOut(['ok' => false, 'error' => 'Email already registered.'], 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare(
            'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $email, $hash, $role]);
        $id = $db->lastInsertId();

        $user = ['id' => (int)$id, 'name' => $name, 'email' => $email, 'role' => $role];
        $_SESSION['user'] = $user;
        jsonOut(['ok' => true, 'user' => $user]);

    // ── LOGIN ─────────────────────────────────────────────────
    case 'login':
        $email    = strtolower(trim($body['email']    ?? ''));
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            jsonOut(['ok' => false, 'error' => 'Please enter your email and password.'], 400);
        }

        $db   = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            jsonOut(['ok' => false, 'error' => 'Invalid email or password.'], 401);
        }

        $sess = [
            'id'    => (int)$user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ];
        $_SESSION['user'] = $sess;
        jsonOut(['ok' => true, 'user' => $sess]);

    // ── LOGOUT ────────────────────────────────────────────────
    case 'logout':
        session_destroy();
        jsonOut(['ok' => true]);

    // ── ME (current session) ──────────────────────────────────
    case 'me':
        if (empty($_SESSION['user'])) {
            jsonOut(['ok' => false, 'error' => 'Not authenticated.'], 401);
        }
        jsonOut(['ok' => true, 'user' => $_SESSION['user']]);

    // ── GENERATE OTP ──────────────────────────────────────────
    case 'gen_otp':
        $email = strtolower(trim($body['email'] ?? ''));
        if (!$email) jsonOut(['ok' => false, 'error' => 'Email is required.'], 400);

        $db   = getDB();
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if (!$stmt->fetch()) {
            jsonOut(['ok' => false, 'error' => 'No account found with that email address.'], 404);
        }

        $otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

        // Invalidate old OTPs for this email
        $db->prepare('UPDATE otp_tokens SET used=1 WHERE email=? AND used=0')->execute([$email]);

        $db->prepare(
            'INSERT INTO otp_tokens (email, otp, expires_at) VALUES (?, ?, ?)'
        )->execute([$email, $otp, $expires]);

        // In production: send $otp via email (SMTP / mail())
        // For XAMPP demo we return it in the response so the UI can display it
        jsonOut(['ok' => true, 'otp' => $otp, 'message' => 'OTP generated (demo: returned in response)']);

    // ── VERIFY OTP ────────────────────────────────────────────
    case 'verify_otp':
        $email = strtolower(trim($body['email'] ?? ''));
        $otp   = trim($body['otp'] ?? '');

        if (!$email || !$otp) jsonOut(['ok' => false, 'error' => 'Email and OTP are required.'], 400);

        $db   = getDB();
        $stmt = $db->prepare(
            'SELECT id FROM otp_tokens
              WHERE email = ? AND otp = ? AND used = 0 AND expires_at > NOW()
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$email, $otp]);
        $rec = $stmt->fetch();

        if (!$rec) {
            jsonOut(['ok' => false, 'error' => 'Incorrect or expired code. Please try again.'], 400);
        }

        // Mark used
        $db->prepare('UPDATE otp_tokens SET used=1 WHERE id=?')->execute([$rec['id']]);
        jsonOut(['ok' => true, 'verified' => true]);

    // ── RESET PASSWORD ────────────────────────────────────────
    case 'reset_pass':
        $email    = strtolower(trim($body['email']    ?? ''));
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            jsonOut(['ok' => false, 'error' => 'Email and new password are required.'], 400);
        }
        if (strlen($password) < 8) {
            jsonOut(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 400);
        }

        $db   = getDB();
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $db->prepare('UPDATE users SET password = ? WHERE email = ?');
        $stmt->execute([$hash, $email]);

        if ($stmt->rowCount() === 0) {
            jsonOut(['ok' => false, 'error' => 'User not found.'], 404);
        }
        jsonOut(['ok' => true, 'message' => 'Password updated successfully.']);

    default:
        jsonOut(['ok' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)], 400);
}
