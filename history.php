<?php
// ============================================================
// StockAxis IMS — Move History API
// File: api/history.php
// Endpoints:
//   GET ?action=list           — full ledger (paginated)
//   GET ?action=product&id=X   — ledger for a specific product
//   GET ?action=warehouse&id=X — ledger for a specific warehouse
//   GET ?action=summary        — aggregate stats for dashboard
// ============================================================

require_once __DIR__ . '/config.php';
session_start();

if (empty($_SESSION['user'])) {
    jsonOut(['ok' => false, 'error' => 'Authentication required.'], 401);
}

$action = $_GET['action'] ?? 'list';
$db     = getDB();

switch ($action) {

    // ── FULL LEDGER ───────────────────────────────────────────
    case 'list':
        $limit  = min((int)($_GET['limit'] ?? 50), 200);
        $offset = (int)($_GET['offset'] ?? 0);

        $stmt = $db->prepare(
            'SELECT sl.id, sl.delta, sl.qty_after, sl.move_type, sl.reason, sl.created_at,
                    p.name AS product_name, p.sku,
                    w.code AS warehouse_code, w.name AS warehouse_name,
                    o.ref  AS operation_ref,
                    u.name AS user_name
               FROM stock_ledger sl
               LEFT JOIN products   p ON p.id = sl.product_id
               LEFT JOIN warehouses w ON w.id = sl.warehouse_id
               LEFT JOIN operations o ON o.id = sl.operation_id
               LEFT JOIN users      u ON u.id = sl.created_by
              ORDER BY sl.created_at DESC
              LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);
        $rows = $stmt->fetchAll();

        $total = (int)$db->query('SELECT COUNT(*) FROM stock_ledger')->fetchColumn();
        jsonOut(['ok' => true, 'total' => $total, 'offset' => $offset, 'rows' => $rows]);

    // ── BY PRODUCT ────────────────────────────────────────────
    case 'product':
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare(
            'SELECT sl.*, o.ref AS operation_ref, w.code AS warehouse_code, u.name AS user_name
               FROM stock_ledger sl
               LEFT JOIN operations o ON o.id = sl.operation_id
               LEFT JOIN warehouses w ON w.id = sl.warehouse_id
               LEFT JOIN users      u ON u.id = sl.created_by
              WHERE sl.product_id = ?
              ORDER BY sl.created_at DESC LIMIT 100'
        );
        $stmt->execute([$id]);
        jsonOut(['ok' => true, 'rows' => $stmt->fetchAll()]);

    // ── BY WAREHOUSE ──────────────────────────────────────────
    case 'warehouse':
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare(
            'SELECT sl.*, p.name AS product_name, p.sku, o.ref AS operation_ref, u.name AS user_name
               FROM stock_ledger sl
               LEFT JOIN products   p ON p.id = sl.product_id
               LEFT JOIN operations o ON o.id = sl.operation_id
               LEFT JOIN users      u ON u.id = sl.created_by
              WHERE sl.warehouse_id = ?
              ORDER BY sl.created_at DESC LIMIT 100'
        );
        $stmt->execute([$id]);
        jsonOut(['ok' => true, 'rows' => $stmt->fetchAll()]);

    // ── DASHBOARD SUMMARY ─────────────────────────────────────
    case 'summary':
        $db2 = getDB();

        // Products overview
        $pStats = $db2->query(
            'SELECT COUNT(*) AS total,
                    SUM(qty=0) AS out_of_stock,
                    SUM(status="low") AS low_stock
               FROM products'
        )->fetch();

        // Pending operations by type
        $opStats = $db2->query(
            'SELECT type, COUNT(*) AS cnt
               FROM operations
              WHERE status IN ("draft","waiting","ready")
              GROUP BY type'
        )->fetchAll();
        $pending = [];
        foreach ($opStats as $row) {
            $pending[$row['type']] = (int)$row['cnt'];
        }

        // Weekly inbound / outbound
        $weekly = $db2->query(
            'SELECT move_type, SUM(delta) AS total
               FROM stock_ledger
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              GROUP BY move_type'
        )->fetchAll();
        $weeklyMap = [];
        foreach ($weekly as $row) {
            $weeklyMap[$row['move_type']] = (int)$row['total'];
        }

        jsonOut([
            'ok'      => true,
            'products'  => $pStats,
            'pending'   => $pending,
            'weekly'    => $weeklyMap,
        ]);

    default:
        jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400);
}
