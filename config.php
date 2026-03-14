<?php
// ============================================================
// StockAxis IMS — Database Configuration
// File: config.php
// Place this file in your XAMPP project folder, e.g.:
//   C:\xampp\htdocs\stockaxis\api\config.php
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'stockaxis');
define('DB_USER', 'root');       // XAMPP default
define('DB_PASS', '');           // XAMPP default (empty password)
define('DB_CHARSET', 'utf8mb4');

// App settings
define('APP_NAME', 'StockAxis IMS');
define('APP_ENV',  'development'); // change to 'production' when live
define('SESSION_LIFETIME', 3600); // 1 hour in seconds

// ── PDO connection (singleton) ───────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST
         . ';dbname=' . DB_NAME
         . ';charset=' . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['ok' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]));
    }

    return $pdo;
}

// ── Helper: send JSON response ───────────────────────────────
function jsonOut(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Helper: get JSON body from request ──────────────────────
function getBody(): array {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?? []) : [];
}

// ── CORS (allow same-origin + XAMPP localhost) ───────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
