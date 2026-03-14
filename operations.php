<?php
// ============================================================
// StockAxis IMS — Operations API
// File: api/operations.php
// Endpoints:
//   GET  ?action=list&type=Receipt|Delivery|Transfer|Adjustment
//   GET  ?action=get&id=X
//   POST ?action=create    — create new operation
//   POST ?action=validate  — mark as done + update stock
//   POST ?action=status    — change status manually
//   GET  ?action=ledger    — full stock movement history
// ============================================================

require_once __DIR__ . '/config.php';
session_start();

if (empty($_SESSION['user'])) {
    jsonOut(['ok' => false, 'error' => 'Authentication required.'], 401);
}

$action = $_GET['action'] ?? 'list';
$body   = getBody();
$db     = getDB();
$userId = (int)$_SESSION['user']['id'];

switch ($action) {

    // ── LIST OPERATIONS ───────────────────────────────────────
    case 'list':
        $where  = [];
        $params = [];

        if (!empty($_GET['type'])) {
            $where[] = 'o.type = ?';
            $params[] = $_GET['type'];
        }
        if (!empty($_GET['status'])) {
            $where[] = 'o.status = ?';
            $params[] = $_GET['status'];
        }

        $sql = 'SELECT o.*,
                       wo.code AS origin_code, wo.name AS origin_name,
                       wd.code AS dest_code,   wd.name AS dest_name,
                       u.name  AS created_by_name
                  FROM operations o
                  LEFT JOIN warehouses wo ON wo.id = o.origin_wh
                  LEFT JOIN warehouses wd ON wd.id = o.dest_wh
                  LEFT JOIN users      u  ON u.id  = o.created_by';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY o.created_at DESC LIMIT 100';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $ops = $stmt->fetchAll();

        // Attach line items to each op
        foreach ($ops as &$op) {
            $ls = $db->prepare(
                'SELECT ol.*, p.name AS product_name, p.sku
                   FROM operation_lines ol
                   JOIN products p ON p.id = ol.product_id
                  WHERE ol.operation_id = ?'
            );
            $ls->execute([$op['id']]);
            $op['lines'] = $ls->fetchAll();
        }

        jsonOut(['ok' => true, 'operations' => $ops]);

    // ── GET SINGLE ────────────────────────────────────────────
    case 'get':
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM operations WHERE id = ?');
        $stmt->execute([$id]);
        $op = $stmt->fetch();
        if (!$op) jsonOut(['ok' => false, 'error' => 'Operation not found.'], 404);

        $ls = $db->prepare(
            'SELECT ol.*, p.name AS product_name, p.sku
               FROM operation_lines ol
               JOIN products p ON p.id = ol.product_id
              WHERE ol.operation_id = ?'
        );
        $ls->execute([$id]);
        $op['lines'] = $ls->fetchAll();

        jsonOut(['ok' => true, 'operation' => $op]);

    // ── CREATE OPERATION ──────────────────────────────────────
    case 'create':
        $type     = $body['type']     ?? '';
        $status   = $body['status']   ?? 'draft';
        $supplier = trim($body['supplier'] ?? '');
        $customer = trim($body['customer'] ?? '');
        $notes    = trim($body['notes']    ?? '');
        $sched    = $body['scheduled_at']  ?? null;
        $lines    = $body['lines']         ?? [];   // [{product_id, qty_expected}]

        if (!in_array($type, ['Receipt','Delivery','Transfer','Adjustment'])) {
            jsonOut(['ok' => false, 'error' => 'Invalid operation type.'], 400);
        }

        // Resolve warehouse IDs
        $originId = resolveWH($db, $body['origin_wh'] ?? null);
        $destId   = resolveWH($db, $body['dest_wh']   ?? null);

        // Generate reference number
        $prefix = ['Receipt'=>'REC','Delivery'=>'DEL','Transfer'=>'INT','Adjustment'=>'ADJ'][$type];
        $count  = (int)$db->query("SELECT COUNT(*) FROM operations")->fetchColumn();
        $ref    = $prefix . '/' . date('Y') . '/' . str_pad($count + 1, 5, '0', STR_PAD_LEFT);

        $stmt = $db->prepare(
            'INSERT INTO operations
               (ref, type, status, supplier, customer, origin_wh, dest_wh, notes, scheduled_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$ref, $type, $status, $supplier, $customer,
                         $originId, $destId, $notes, $sched, $userId]);
        $opId = (int)$db->lastInsertId();

        // Insert line items
        foreach ($lines as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            $qty = (int)($line['qty_expected'] ?? 0);
            if ($pid && $qty > 0) {
                $db->prepare(
                    'INSERT INTO operation_lines (operation_id, product_id, qty_expected)
                     VALUES (?, ?, ?)'
                )->execute([$opId, $pid, $qty]);
            }
        }

        jsonOut(['ok' => true, 'id' => $opId, 'ref' => $ref]);

    // ── VALIDATE OPERATION (mark done + update stock) ─────────
    case 'validate':
        $id = (int)($body['id'] ?? $_GET['id'] ?? 0);

        $stmt = $db->prepare('SELECT * FROM operations WHERE id = ?');
        $stmt->execute([$id]);
        $op = $stmt->fetch();
        if (!$op) jsonOut(['ok' => false, 'error' => 'Operation not found.'], 404);
        if ($op['status'] === 'done') jsonOut(['ok' => false, 'error' => 'Already validated.'], 409);

        // Get lines
        $ls = $db->prepare(
            'SELECT ol.*, p.qty AS current_qty
               FROM operation_lines ol
               JOIN products p ON p.id = ol.product_id
              WHERE ol.operation_id = ?'
        );
        $ls->execute([$id]);
        $lines = $ls->fetchAll();

        $db->beginTransaction();
        try {
            foreach ($lines as $line) {
                $pid = (int)$line['product_id'];
                $qty = (int)($line['qty_done'] ?: $line['qty_expected']);
                $cur = (int)$line['current_qty'];

                if ($op['type'] === 'Receipt') {
                    // Stock IN
                    $newQty   = $cur + $qty;
                    $moveType = 'receipt';
                    $delta    = $qty;
                } elseif ($op['type'] === 'Delivery') {
                    // Stock OUT
                    $newQty   = max(0, $cur - $qty);
                    $moveType = 'delivery';
                    $delta    = -$qty;
                } elseif ($op['type'] === 'Transfer') {
                    // Stock OUT from origin, IN to dest handled separately
                    $newQty   = max(0, $cur - $qty);
                    $moveType = 'transfer_out';
                    $delta    = -$qty;
                } elseif ($op['type'] === 'Adjustment') {
                    $adjDelta = (int)($line['qty_expected']); // can be negative
                    $newQty   = max(0, $cur + $adjDelta);
                    $moveType = 'adjustment';
                    $delta    = $adjDelta;
                } else {
                    continue;
                }

                // Update product qty
                $db->prepare('UPDATE products SET qty = ? WHERE id = ?')
                   ->execute([$newQty, $pid]);

                // Log ledger entry
                $db->prepare(
                    'INSERT INTO stock_ledger
                       (product_id, warehouse_id, operation_id, delta, qty_after, move_type, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $pid,
                    $op['origin_wh'] ?? $op['dest_wh'],
                    $id,
                    $delta,
                    $newQty,
                    $moveType,
                    $userId
                ]);

                // Update stock_locations if warehouse is set
                $whId = $op['origin_wh'] ?? $op['dest_wh'];
                if ($whId) {
                    $db->prepare(
                        'INSERT INTO stock_locations (product_id, warehouse_id, qty)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE qty = qty + ?'
                    )->execute([$pid, $whId, $delta, $delta]);
                }
            }

            // Mark done
            $db->prepare('UPDATE operations SET status = "done" WHERE id = ?')
               ->execute([$id]);

            $db->commit();
            jsonOut(['ok' => true, 'message' => 'Operation validated and stock updated.']);

        } catch (Exception $e) {
            $db->rollBack();
            jsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
        }

    // ── CHANGE STATUS ─────────────────────────────────────────
    case 'status':
        $id     = (int)($body['id'] ?? 0);
        $status = $body['status'] ?? '';
        $valid  = ['draft','waiting','ready','done','canceled'];
        if (!in_array($status, $valid)) {
            jsonOut(['ok' => false, 'error' => 'Invalid status.'], 400);
        }
        $db->prepare('UPDATE operations SET status = ? WHERE id = ?')->execute([$status, $id]);
        jsonOut(['ok' => true]);

    // ── STOCK LEDGER / MOVE HISTORY ───────────────────────────
    case 'ledger':
        $where  = [];
        $params = [];

        if (!empty($_GET['product_id'])) {
            $where[] = 'sl.product_id = ?';
            $params[] = (int)$_GET['product_id'];
        }
        if (!empty($_GET['warehouse_id'])) {
            $where[] = 'sl.warehouse_id = ?';
            $params[] = (int)$_GET['warehouse_id'];
        }

        $sql = 'SELECT sl.*, p.name AS product_name, p.sku,
                       w.code AS warehouse_code,
                       o.ref  AS operation_ref,
                       u.name AS user_name
                  FROM stock_ledger sl
                  LEFT JOIN products   p ON p.id = sl.product_id
                  LEFT JOIN warehouses w ON w.id = sl.warehouse_id
                  LEFT JOIN operations o ON o.id = sl.operation_id
                  LEFT JOIN users      u ON u.id = sl.created_by';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY sl.created_at DESC LIMIT 200';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonOut(['ok' => true, 'ledger' => $stmt->fetchAll()]);

    default:
        jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400);
}

// ── Helper ─────────────────────────────────────────────────
function resolveWH(PDO $db, $codeOrId): ?int {
    if (!$codeOrId) return null;
    if (is_numeric($codeOrId)) return (int)$codeOrId;
    $stmt = $db->prepare('SELECT id FROM warehouses WHERE code = ?');
    $stmt->execute([$codeOrId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}
