<?php
// ============================================================
// StockAxis IMS — Products API
// File: api/products.php
// Endpoints:
//   GET  ?action=list              — all products (with filters)
//   GET  ?action=get&id=X          — single product
//   GET  ?action=categories        — list all categories
//   GET  ?action=stock_by_location&id=X — per-warehouse stock for product
//   POST ?action=add               — create product
//   POST ?action=update            — update product fields
//   POST ?action=delete&id=X       — soft-delete / remove product
// ============================================================

require_once __DIR__ . '/config.php';
session_start();

// Auth guard
if (empty($_SESSION['user'])) {
    jsonOut(['ok' => false, 'error' => 'Authentication required.'], 401);
}

$action = $_GET['action'] ?? 'list';
$body   = getBody();
$db     = getDB();

switch ($action) {

    // ── LIST PRODUCTS ─────────────────────────────────────────
    case 'list':
        $where  = [];
        $params = [];

        if (!empty($_GET['cat'])) {
            $where[] = 'c.name = ?';
            $params[] = $_GET['cat'];
        }
        if (!empty($_GET['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['q'])) {
            $where[] = '(p.name LIKE ? OR p.sku LIKE ?)';
            $like = '%' . $_GET['q'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT p.*, c.name AS cat
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY p.created_at DESC';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        jsonOut(['ok' => true, 'products' => $products]);

    // ── GET SINGLE ────────────────────────────────────────────
    case 'get':
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare(
            'SELECT p.*, c.name AS cat
               FROM products p
               LEFT JOIN categories c ON c.id = p.category_id
              WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        if (!$product) jsonOut(['ok' => false, 'error' => 'Product not found.'], 404);
        jsonOut(['ok' => true, 'product' => $product]);

    // ── CATEGORIES ────────────────────────────────────────────
    case 'categories':
        $cats = $db->query('SELECT * FROM categories ORDER BY name')->fetchAll();
        jsonOut(['ok' => true, 'categories' => $cats]);

    // ── STOCK BY LOCATION ─────────────────────────────────────
    case 'stock_by_location':
        $id   = (int)($_GET['id'] ?? 0);
        $stmt = $db->prepare(
            'SELECT w.code, w.name AS warehouse, sl.qty
               FROM stock_locations sl
               JOIN warehouses w ON w.id = sl.warehouse_id
              WHERE sl.product_id = ?'
        );
        $stmt->execute([$id]);
        jsonOut(['ok' => true, 'locations' => $stmt->fetchAll()]);

    // ── ADD PRODUCT ───────────────────────────────────────────
    case 'add':
        $name     = trim($body['name'] ?? '');
        $sku      = trim($body['sku']  ?? '');
        $catName  = trim($body['cat']  ?? 'Electronics');
        $unit     = trim($body['unit'] ?? 'units');
        $qty      = (int)($body['qty']     ?? 0);
        $reorder  = (int)($body['reorder'] ?? 10);

        if (!$name || !$sku) {
            jsonOut(['ok' => false, 'error' => 'Name and SKU are required.'], 400);
        }

        // Check duplicate SKU
        $chk = $db->prepare('SELECT id FROM products WHERE sku = ?');
        $chk->execute([$sku]);
        if ($chk->fetch()) jsonOut(['ok' => false, 'error' => 'SKU already exists.'], 409);

        // Resolve or create category
        $catId = resolveCat($db, $catName);

        // Compute status
        $status = $qty === 0 ? 'out' : ($qty <= $reorder ? 'low' : 'ok');

        $stmt = $db->prepare(
            'INSERT INTO products (sku, name, category_id, unit, qty, reorder_pt, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$sku, $name, $catId, $unit, $qty, $reorder, $status]);
        $pid = $db->lastInsertId();

        // Log initial stock in ledger if qty > 0
        if ($qty > 0) {
            $db->prepare(
                'INSERT INTO stock_ledger (product_id, delta, qty_after, move_type, reason, created_by)
                 VALUES (?, ?, ?, "receipt", "Initial stock", ?)'
            )->execute([$pid, $qty, $qty, $_SESSION['user']['id']]);
        }

        jsonOut(['ok' => true, 'id' => (int)$pid, 'status' => $status]);

    // ── UPDATE PRODUCT ────────────────────────────────────────
    case 'update':
        $id      = (int)($body['id'] ?? 0);
        $fields  = [];
        $params  = [];

        $allowed = ['name','unit','qty','reorder_pt'];
        foreach ($allowed as $f) {
            if (isset($body[$f])) {
                $fields[] = "$f = ?";
                $params[] = $f === 'qty' || $f === 'reorder_pt' ? (int)$body[$f] : trim($body[$f]);
            }
        }
        if (isset($body['cat'])) {
            $fields[] = 'category_id = ?';
            $params[] = resolveCat($db, trim($body['cat']));
        }

        if (!$fields) jsonOut(['ok' => false, 'error' => 'Nothing to update.'], 400);

        $params[] = $id;
        $db->prepare('UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?')
           ->execute($params);

        jsonOut(['ok' => true]);

    // ── DELETE PRODUCT ────────────────────────────────────────
    case 'delete':
        $id = (int)($_GET['id'] ?? $body['id'] ?? 0);
        $db->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        jsonOut(['ok' => true]);

    default:
        jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400);
}

// ── Helper ─────────────────────────────────────────────────
function resolveCat(PDO $db, string $name): int {
    $stmt = $db->prepare('SELECT id FROM categories WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];
    $db->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
    return (int)$db->lastInsertId();
}
