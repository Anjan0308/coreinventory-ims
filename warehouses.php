<?php
// ============================================================
// StockAxis IMS — Warehouses & Settings API
// File: api/warehouses.php
// Endpoints:
//   GET  ?action=list          — all warehouses
//   GET  ?action=get&id=X      — single warehouse
//   POST ?action=add           — create warehouse
//   POST ?action=update        — update warehouse
//   POST ?action=delete&id=X   — delete warehouse
//   GET  ?action=reorder_rules — all reorder rules
//   POST ?action=save_rule     — upsert reorder rule for a product
// ============================================================

require_once __DIR__ . '/config.php';
session_start();

if (empty($_SESSION['user'])) {
    jsonOut(['ok' => false, 'error' => 'Authentication required.'], 401);
}

$action = $_GET['action'] ?? 'list';
$body   = getBody();
$db     = getDB();

switch ($action) {

    // ── LIST WAREHOUSES ───────────────────────────────────────
    case 'list':
        $whs = $db->query('SELECT * FROM warehouses ORDER BY code')->fetchAll();

        // Attach current stock totals per warehouse
        foreach ($whs as &$wh) {
            $stmt = $db->prepare(
                'SELECT COALESCE(SUM(sl.qty),0) AS total_qty,
                        COUNT(DISTINCT sl.product_id) AS product_count
                   FROM stock_locations sl
                  WHERE sl.warehouse_id = ?'
            );
            $stmt->execute([$wh['id']]);
            $totals = $stmt->fetch();
            $wh['total_qty']     = (int)$totals['total_qty'];
            $wh['product_count'] = (int)$totals['product_count'];
            $wh['capacity_pct']  = $wh['capacity']
                ? round(min(100, $wh['total_qty'] / $wh['capacity'] * 100))
                : 0;
        }

        jsonOut(['ok' => true, 'warehouses' => $whs]);

    // ── GET SINGLE ────────────────────────────────────────────
    case 'get':
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM warehouses WHERE id = ?');
        $stmt->execute([$id]);
        $wh = $stmt->fetch();
        if (!$wh) jsonOut(['ok' => false, 'error' => 'Warehouse not found.'], 404);
        jsonOut(['ok' => true, 'warehouse' => $wh]);

    // ── ADD WAREHOUSE ─────────────────────────────────────────
    case 'add':
        $code     = strtoupper(trim($body['code']     ?? ''));
        $name     = trim($body['name']                ?? '');
        $location = trim($body['location']            ?? '');
        $capacity = (int)($body['capacity']           ?? 1000);

        if (!$code || !$name) {
            jsonOut(['ok' => false, 'error' => 'Code and name are required.'], 400);
        }

        // Check duplicate code
        $chk = $db->prepare('SELECT id FROM warehouses WHERE code = ?');
        $chk->execute([$code]);
        if ($chk->fetch()) jsonOut(['ok' => false, 'error' => 'Warehouse code already exists.'], 409);

        $db->prepare(
            'INSERT INTO warehouses (code, name, location, capacity) VALUES (?, ?, ?, ?)'
        )->execute([$code, $name, $location, $capacity]);

        jsonOut(['ok' => true, 'id' => (int)$db->lastInsertId()]);

    // ── UPDATE WAREHOUSE ──────────────────────────────────────
    case 'update':
        $id       = (int)($body['id']       ?? 0);
        $name     = trim($body['name']      ?? '');
        $location = trim($body['location']  ?? '');
        $capacity = isset($body['capacity']) ? (int)$body['capacity'] : null;

        $fields  = [];
        $params  = [];
        if ($name)     { $fields[] = 'name = ?';     $params[] = $name; }
        if ($location) { $fields[] = 'location = ?'; $params[] = $location; }
        if ($capacity) { $fields[] = 'capacity = ?'; $params[] = $capacity; }

        if (!$fields) jsonOut(['ok' => false, 'error' => 'Nothing to update.'], 400);
        $params[] = $id;

        $db->prepare('UPDATE warehouses SET ' . implode(', ', $fields) . ' WHERE id = ?')
           ->execute($params);
        jsonOut(['ok' => true]);

    // ── DELETE WAREHOUSE ──────────────────────────────────────
    case 'delete':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        $db->prepare('DELETE FROM warehouses WHERE id = ?')->execute([$id]);
        jsonOut(['ok' => true]);

    // ── REORDER RULES ─────────────────────────────────────────
    case 'reorder_rules':
        $stmt = $db->query(
            'SELECT rr.*, p.name AS product_name, p.sku, w.code AS wh_code
               FROM reorder_rules rr
               JOIN products p ON p.id = rr.product_id
               LEFT JOIN warehouses w ON w.id = rr.preferred_wh
              ORDER BY p.name'
        );
        jsonOut(['ok' => true, 'rules' => $stmt->fetchAll()]);

    // ── SAVE REORDER RULE ─────────────────────────────────────
    case 'save_rule':
        $productId = (int)($body['product_id'] ?? 0);
        $minQty    = (int)($body['min_qty']    ?? 10);
        $maxQty    = (int)($body['max_qty']    ?? 100);
        $leadDays  = (int)($body['lead_days']  ?? 3);
        $prefWh    = isset($body['preferred_wh']) ? (int)$body['preferred_wh'] : null;

        if (!$productId) jsonOut(['ok' => false, 'error' => 'product_id is required.'], 400);

        $db->prepare(
            'INSERT INTO reorder_rules (product_id, min_qty, max_qty, lead_days, preferred_wh)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               min_qty = VALUES(min_qty),
               max_qty = VALUES(max_qty),
               lead_days = VALUES(lead_days),
               preferred_wh = VALUES(preferred_wh)'
        )->execute([$productId, $minQty, $maxQty, $leadDays, $prefWh]);

        // Also update reorder_pt on product
        $db->prepare('UPDATE products SET reorder_pt = ? WHERE id = ?')
           ->execute([$minQty, $productId]);

        jsonOut(['ok' => true]);

    default:
        jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400);
}
