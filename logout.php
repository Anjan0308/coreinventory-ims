<?php
// ============================================================
// StockAxis IMS — Logout
// File: logout.php
// ============================================================
session_start();
session_destroy();
header('Location: signin.php');
exit;
