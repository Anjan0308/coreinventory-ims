<?php
// ============================================================
// StockAxis IMS — Session Bridge
// File: session.php
// Called by app.html via fetch() to get the logged-in user
// from the PHP session and return it as JSON
// ============================================================
session_start();
header('Content-Type: application/json');

if (!empty($_SESSION['user'])) {
    echo json_encode(['ok' => true, 'user' => $_SESSION['user']]);
} else {
    http_response_code(401);
    echo json_encode(['ok' => false]);
}
