<?php
// ============================================================
// StockAxis IMS — Mobile App (PHP version)
// File: mobile/index.php
// All screens server-rendered. AJAX for mutations only.
// ============================================================
session_start();
require_once __DIR__ . '/../api/config.php';

if (empty($_SESSION['user'])) { header('Location: signin.php'); exit; }
$user = $_SESSION['user'];
$db   = getDB();

// ── Handle AJAX POST mutations ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = getBody();
    $action = $_GET['action'] ?? $body['action'] ?? '';
    $uid    = (int)$user['id'];

    header('Content-Type: application/json');

    switch ($action) {

        case 'create_op':
            $type     = $body['type']     ?? '';
            $status   = $body['status']   ?? 'draft';
            $supplier = trim($body['supplier'] ?? '');
            $customer = trim($body['customer'] ?? '');
            $notes    = trim($body['notes']    ?? '');
            $whId     = (int)($body['wh_id']   ?? 0) ?: null;
            if (!in_array($type,['Receipt','Delivery','Transfer','Adjustment']))
                jsonOut(['ok'=>false,'error'=>'Invalid type.'],400);
            $prefix = ['Receipt'=>'REC','Delivery'=>'DEL','Transfer'=>'INT','Adjustment'=>'ADJ'][$type];
            $count  = (int)$db->query("SELECT COUNT(*) FROM operations")->fetchColumn();
            $ref    = $prefix.'/'.date('Y').'/'.str_pad($count+1,5,'0',STR_PAD_LEFT);
            $originId = in_array($type,['Delivery','Transfer','Adjustment']) ? $whId : null;
            $destId   = $type === 'Receipt' ? $whId : null;
            $db->prepare('INSERT INTO operations (ref,type,status,supplier,customer,origin_wh,dest_wh,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?)')
               ->execute([$ref,$type,$status,$supplier,$customer,$originId,$destId,$notes,$uid]);
            jsonOut(['ok'=>true,'ref'=>$ref]);

        case 'validate_op':
            $id   = (int)($body['id'] ?? 0);
            $stmt = $db->prepare('SELECT * FROM operations WHERE id=?'); $stmt->execute([$id]);
            $op   = $stmt->fetch();
            if (!$op) jsonOut(['ok'=>false,'error'=>'Not found.'],404);
            if ($op['status']==='done') jsonOut(['ok'=>false,'error'=>'Already done.'],409);
            $ls = $db->prepare('SELECT ol.*,p.qty AS current_qty FROM operation_lines ol JOIN products p ON p.id=ol.product_id WHERE ol.operation_id=?');
            $ls->execute([$id]);
            $lines = $ls->fetchAll();
            $db->beginTransaction();
            try {
                foreach ($lines as $line) {
                    $pid = (int)$line['product_id'];
                    $qty = (int)($line['qty_done'] ?: $line['qty_expected']);
                    $cur = (int)$line['current_qty'];
                    switch ($op['type']) {
                        case 'Receipt':    $nq=$cur+$qty;        $mt='receipt';      $d=$qty;   break;
                        case 'Delivery':   $nq=max(0,$cur-$qty); $mt='delivery';     $d=-$qty;  break;
                        case 'Transfer':   $nq=max(0,$cur-$qty); $mt='transfer_out'; $d=-$qty;  break;
                        case 'Adjustment': $nq=max(0,$cur+$qty); $mt='adjustment';   $d=$qty;   break;
                        default: continue 2;
                    }
                    $db->prepare('UPDATE products SET qty=? WHERE id=?')->execute([$nq,$pid]);
                    $wh = $op['origin_wh'] ?? $op['dest_wh'];
                    $db->prepare('INSERT INTO stock_ledger (product_id,warehouse_id,operation_id,delta,qty_after,move_type,created_by) VALUES (?,?,?,?,?,?,?)')->execute([$pid,$wh,$id,$d,$nq,$mt,$uid]);
                    if ($wh) $db->prepare('INSERT INTO stock_locations (product_id,warehouse_id,qty) VALUES (?,?,?) ON DUPLICATE KEY UPDATE qty=qty+?')->execute([$pid,$wh,$d,$d]);
                }
                $db->prepare('UPDATE operations SET status="done" WHERE id=?')->execute([$id]);
                $db->commit();
                jsonOut(['ok'=>true]);
            } catch (Exception $ex) { $db->rollBack(); jsonOut(['ok'=>false,'error'=>$ex->getMessage()],500); }

        case 'add_product':
            $name    = trim($body['name'] ?? '');
            $sku     = trim($body['sku']  ?? '');
            $catName = trim($body['cat']  ?? 'Electronics');
            $unit    = trim($body['unit'] ?? 'units');
            $qty     = (int)($body['qty']     ?? 0);
            $reorder = (int)($body['reorder'] ?? 10);
            if (!$name||!$sku) jsonOut(['ok'=>false,'error'=>'Name and SKU required.'],400);
            $chk=$db->prepare('SELECT id FROM products WHERE sku=?'); $chk->execute([$sku]);
            if ($chk->fetch()) jsonOut(['ok'=>false,'error'=>'SKU already exists.'],409);
            $catId = resolveCatM($db,$catName);
            $status = $qty===0?'out':($qty<=$reorder?'low':'ok');
            $db->prepare('INSERT INTO products (sku,name,category_id,unit,qty,reorder_pt,status) VALUES (?,?,?,?,?,?,?)')->execute([$sku,$name,$catId,$unit,$qty,$reorder,$status]);
            $pid = (int)$db->lastInsertId();
            if ($qty>0) $db->prepare('INSERT INTO stock_ledger (product_id,delta,qty_after,move_type,reason,created_by) VALUES (?,?,?,"receipt","Initial stock",?)')->execute([$pid,$qty,$qty,$uid]);
            jsonOut(['ok'=>true,'id'=>$pid]);

        case 'update_product':
            $id = (int)($body['id']??0);
            $fields=[]; $params=[];
            foreach(['name','unit','sku'] as $f) { if(isset($body[$f])){$fields[]="$f=?";$params[]=trim($body[$f]);} }
            foreach(['qty','reorder_pt'] as $f)  { if(isset($body[$f])){$fields[]="$f=?";$params[]=(int)$body[$f];} }
            if(isset($body['cat'])&&$body['cat']!=='') { $fields[]='category_id=?'; $params[]=resolveCatM($db,trim($body['cat'])); }
            if(!$fields) jsonOut(['ok'=>false,'error'=>'Nothing to update.'],400);
            $params[]=$id;
            $db->prepare('UPDATE products SET '.implode(',',$fields).' WHERE id=?')->execute($params);
            jsonOut(['ok'=>true]);

        case 'delete_product':
            $id=(int)($body['id']??0);
            $db->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
            jsonOut(['ok'=>true]);

        default:
            jsonOut(['ok'=>false,'error'=>'Unknown action.'],400);
    }
    exit;
}

// ── Fetch all data (server-side) ──────────────────────────────
$products = $db->query("
    SELECT p.*, c.name AS cat
    FROM products p LEFT JOIN categories c ON c.id=p.category_id
    ORDER BY p.name
")->fetchAll();

$ops = $db->query("
    SELECT o.*,
           wo.code AS origin_code, wd.code AS dest_code,
           u.name AS created_by_name
    FROM operations o
    LEFT JOIN warehouses wo ON wo.id=o.origin_wh
    LEFT JOIN warehouses wd ON wd.id=o.dest_wh
    LEFT JOIN users u ON u.id=o.created_by
    ORDER BY o.created_at DESC LIMIT 60
")->fetchAll();

$warehouses = $db->query("
    SELECT w.*,
           COALESCE(SUM(sl.qty),0) AS total_qty,
           COUNT(DISTINCT sl.product_id) AS product_count
    FROM warehouses w
    LEFT JOIN stock_locations sl ON sl.warehouse_id=w.id
    GROUP BY w.id ORDER BY w.code
")->fetchAll();

$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

// KPIs
$kpi = $db->query("
    SELECT
      (SELECT COUNT(*) FROM products) AS total_products,
      (SELECT COUNT(*) FROM products WHERE status='low') AS low_stock,
      (SELECT COUNT(*) FROM products WHERE status='out') AS out_stock,
      (SELECT COUNT(*) FROM operations WHERE status IN ('draft','waiting','ready')) AS pending_ops,
      (SELECT COUNT(*) FROM operations WHERE type='Receipt' AND status IN ('draft','waiting','ready')) AS pending_receipts
")->fetch();

// Recent activity
$activity = $db->query("
    SELECT sl.delta, sl.move_type, sl.created_at,
           p.name AS product_name, p.sku, u.name AS user_name
    FROM stock_ledger sl
    LEFT JOIN products p ON p.id=sl.product_id
    LEFT JOIN users u ON u.id=sl.created_by
    ORDER BY sl.created_at DESC LIMIT 5
")->fetchAll();

// ── Helpers ───────────────────────────────────────────────────
function badge(string $s): string {
    $map=['ok'=>'In stock','low'=>'Low stock','out'=>'Out of stock',
          'draft'=>'Draft','waiting'=>'Waiting','ready'=>'Ready',
          'done'=>'Done','canceled'=>'Canceled'];
    return '<span class="badge '.e($s).'">'.(isset($map[$s])?$map[$s]:e($s)).'</span>';
}
function badgeShort(string $s): string {
    $map=['ok'=>'OK','low'=>'Low','out'=>'Out','draft'=>'Draft',
          'waiting'=>'Wait','ready'=>'Ready','done'=>'Done','canceled'=>'X'];
    return '<span class="badge '.e($s).'">'.(isset($map[$s])?$map[$s]:e($s)).'</span>';
}
function resolveCatM(PDO $db, string $name): int {
    $s=$db->prepare('SELECT id FROM categories WHERE name=?'); $s->execute([$name]);
    $r=$s->fetch(); if($r) return (int)$r['id'];
    $db->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
    return (int)$db->lastInsertId();
}
function timeAgoM(string $dt): string {
    $diff=time()-strtotime($dt);
    if($diff<60) return 'Just now';
    if($diff<3600) return floor($diff/60).'m ago';
    if($diff<86400) return floor($diff/3600).'h ago';
    return floor($diff/86400).'d ago';
}

$initials = strtoupper(substr(implode('',array_map(fn($w)=>$w[0],explode(' ',$user['name']))),0,2));
$lowOut   = array_filter($products, fn($p) => $p['status']!=='ok');
$pending  = array_filter($ops, fn($o) => in_array($o['status'],['draft','waiting','ready']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0f0f0f">
<title>StockAxis</title>
<link rel="manifest" href="manifest.json">
<link rel="stylesheet" href="style.css">
</head>
<body>

<div id="app">
  <div class="status-bar"></div>

  <!-- ── TOP HEADER ─────────────────────────────────────────── -->
  <div class="top-header">
    <div class="top-logo">
      <div class="logo-mark">
        <svg viewBox="0 0 24 24"><path d="M3 3h7v7H3zm0 11h7v7H3zm11-11h7v7h-7zm3 11v3h3v-3h-3zm-3 3h3v3h-3zm3-3h-3v-3h3v3z"/></svg>
      </div>
      <div>
        <div class="logo-text">StockAxis</div>
        <div class="logo-ver">v2.1 · IMS</div>
      </div>
    </div>
    <div class="header-right">
      <div class="icon-btn" style="position:relative" onclick="navTo('screen-ops')">
        <svg viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.64-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
        <?php if (count($pending)>0): ?>
          <div class="notif-dot"></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── CONTENT ────────────────────────────────────────────── -->
  <div id="content">

    <!-- ══ HOME / DASHBOARD ══════════════════════════════════ -->
    <div class="screen active" id="screen-home">

      <div class="sec-hdr">
        <div>
          <div style="font-size:13px;color:var(--ink3);margin-bottom:2px">Good day,</div>
          <div class="sec-title"><?= e(explode(' ',$user['name'])[0]) ?></div>
        </div>
        <div style="font-size:11px;color:var(--ink4);font-family:var(--mono);text-align:right">
          <?= date('D, M j') ?>
        </div>
      </div>

      <!-- KPI cards -->
      <div class="kpi-scroll">
        <div class="kpi-card anim-up d1" onclick="navTo('screen-products')">
          <div class="kpi-accent" style="background:var(--accent)"></div>
          <div class="kpi-val"><?= $kpi['total_products'] ?></div>
          <div class="kpi-lbl">Products</div>
        </div>
        <div class="kpi-card anim-up d2" onclick="navTo('screen-products')">
          <div class="kpi-accent" style="background:var(--warn)"></div>
          <div class="kpi-val"><?= $kpi['low_stock'] + $kpi['out_stock'] ?></div>
          <div class="kpi-lbl">Low / Out</div>
          <div class="kpi-sub" style="color:var(--neg)"><?= $kpi['out_stock'] ?> critical</div>
        </div>
        <div class="kpi-card anim-up d3" onclick="navTo('screen-ops')">
          <div class="kpi-accent" style="background:var(--pos)"></div>
          <div class="kpi-val"><?= $kpi['pending_ops'] ?></div>
          <div class="kpi-lbl">Pending ops</div>
          <div class="kpi-sub"><?= $kpi['pending_receipts'] ?> receipts</div>
        </div>
        <div class="kpi-card anim-up d4" onclick="navTo('screen-warehouses')">
          <div class="kpi-accent" style="background:var(--ink3)"></div>
          <div class="kpi-val"><?= count($warehouses) ?></div>
          <div class="kpi-lbl">Warehouses</div>
        </div>
      </div>

      <!-- Alert pills -->
      <div class="alert-row">
        <?php if ($kpi['out_stock']>0): ?>
          <div class="alert-pill neg" onclick="navTo('screen-products')">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
            <?= $kpi['out_stock'] ?> out of stock
          </div>
        <?php endif; ?>
        <?php if ($kpi['low_stock']>0): ?>
          <div class="alert-pill warn" onclick="navTo('screen-products')">
            <svg viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
            <?= $kpi['low_stock'] ?> low stock
          </div>
        <?php endif; ?>
        <?php if ($kpi['pending_receipts']>0): ?>
          <div class="alert-pill info" onclick="navTo('screen-ops')">
            <svg viewBox="0 0 24 24"><path d="M20 6h-2.18c.07-.44.18-.9.18-1.36C18 2.98 16.04 1 13.64 1c-1.3 0-2.44.62-3.23 1.57L9 4 7.59 2.57C6.8 1.62 5.66 1 4.36 1 1.96 1 0 2.98 0 5.36 0 6.47.46 7.4 1.1 8H1c-.55 0-1 .45-1 1v3c0 .55.45 1 1 1h8l4.01 4.49A1 1 0 0014.76 19H18v2h2V6z"/></svg>
            <?= $kpi['pending_receipts'] ?> receipts pending
          </div>
        <?php endif; ?>
      </div>

      <!-- Recent ops -->
      <div class="sec-hdr">
        <div class="sec-title" style="font-size:15px">Recent operations</div>
        <div class="sec-link" onclick="navTo('screen-ops')">See all</div>
      </div>
      <div class="card-list">
        <?php foreach (array_slice($ops, 0, 5) as $o):
          $party = $o['supplier'] ?: $o['customer'] ?: ($o['origin_code']&&$o['dest_code'] ? $o['origin_code'].'→'.$o['dest_code'] : '—');
        ?>
          <div class="list-card anim-up" onclick="navTo('screen-ops')">
            <div class="card-icon">
              <svg viewBox="0 0 24 24" style="fill:var(--accent)"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4z"/></svg>
            </div>
            <div class="card-body">
              <div class="card-name"><?= e($o['ref']) ?></div>
              <div class="card-sub"><?= e($o['type']) ?> · <?= e($party) ?></div>
            </div>
            <div class="card-right">
              <?= badgeShort($o['status']) ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($ops)): ?>
          <div style="padding:24px;text-align:center;font-size:13px;color:var(--ink4)">No operations yet</div>
        <?php endif; ?>
      </div>

      <!-- Needs attention -->
      <div class="sec-hdr" style="margin-top:8px">
        <div class="sec-title" style="font-size:15px">Needs attention</div>
        <div class="sec-link" onclick="navTo('screen-products')">View all</div>
      </div>
      <div class="card-list">
        <?php $attn = array_slice(array_values($lowOut),0,5); ?>
        <?php foreach ($attn as $p):
          $color = $p['status']==='out' ? 'var(--neg)' : 'var(--warn)';
        ?>
          <div class="list-card anim-up" onclick="openEditModal(<?= $p['id'] ?>)">
            <div class="card-icon">
              <svg viewBox="0 0 24 24" style="fill:<?= $color ?>"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
            </div>
            <div class="card-body">
              <div class="card-name"><?= e($p['name']) ?></div>
              <div class="card-sub"><?= e($p['sku']) ?> · <?= e($p['cat']??'Uncategorised') ?></div>
            </div>
            <div class="card-right">
              <div class="card-val" style="color:<?= $color ?>"><?= $p['qty'] ?></div>
              <?= badgeShort($p['status']) ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($lowOut)): ?>
          <div style="padding:16px;text-align:center;font-size:13px;color:var(--pos)">✓ All products are in stock</div>
        <?php endif; ?>
      </div>

      <!-- Activity feed -->
      <?php if (!empty($activity)): ?>
        <div class="sec-hdr" style="margin-top:8px">
          <div class="sec-title" style="font-size:15px">Recent activity</div>
        </div>
        <div class="card-list" style="margin-bottom:10px">
          <?php foreach ($activity as $a):
            $aColor = $a['delta']>0 ? 'var(--pos)' : 'var(--neg)';
          ?>
            <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--line)">
              <div style="width:6px;height:6px;border-radius:50%;background:<?= $aColor ?>;flex-shrink:0"></div>
              <div style="flex:1;min-width:0">
                <div style="font-size:13px;color:var(--ink2)">
                  <strong style="color:var(--ink)"><?= e($a['product_name']??'Stock') ?></strong>
                  — <?= ($a['delta']>0?'+':'').$a['delta'] ?> (<?= e($a['move_type']) ?>)
                </div>
                <div style="font-size:11px;color:var(--ink4);font-family:var(--mono);margin-top:2px">
                  <?= timeAgoM($a['created_at']) ?> · <?= e($a['user_name']??'System') ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div><!-- /home -->

    <!-- ══ PRODUCTS ══════════════════════════════════════════ -->
    <div class="screen" id="screen-products">
      <div class="sec-hdr">
        <div class="sec-title">Products</div>
        <div style="font-size:12px;color:var(--ink3);font-family:var(--mono)"><?= count($products) ?> items</div>
      </div>

      <!-- Live search -->
      <div class="search-wrap">
        <div class="search-bar">
          <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0016 9.5 6.5 6.5 0 109.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
          <input type="text" id="prod-search" placeholder="Search name or SKU…" oninput="filterProds()">
        </div>
      </div>

      <!-- Status chips -->
      <div class="chip-row">
        <div class="chip active" data-st="all"  onclick="setProdChip(this)">All</div>
        <div class="chip"        data-st="ok"   onclick="setProdChip(this)">In stock</div>
        <div class="chip"        data-st="low"  onclick="setProdChip(this)">Low</div>
        <div class="chip"        data-st="out"  onclick="setProdChip(this)">Out</div>
      </div>

      <!-- Product cards (PHP-rendered, JS filters client-side) -->
      <div class="card-list" id="prod-list">
        <?php foreach ($products as $p):
          $qc = $p['status']==='out'?'var(--neg)':($p['status']==='low'?'var(--warn)':'var(--pos)');
          $stLabel = $p['status']==='out'?'Out of stock':($p['status']==='low'?'Low stock':'In stock');
        ?>
          <div class="list-card prod-card anim-up"
               data-name="<?= e(strtolower($p['name'])) ?>"
               data-sku="<?= e(strtolower($p['sku'])) ?>"
               data-status="<?= e($p['status']) ?>"
               onclick="openEditModal(<?= $p['id'] ?>,<?= htmlspecialchars(json_encode($p),ENT_QUOTES) ?>)">
            <div class="card-icon">
              <svg viewBox="0 0 24 24" style="fill:<?= $qc ?>"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
            </div>
            <div class="card-body">
              <div class="card-name"><?= e($p['name']) ?></div>
              <div class="card-sub" style="font-family:var(--mono);color:var(--accent);font-size:11px"><?= e($p['sku']) ?></div>
              <div class="card-sub"><?= e($p['cat']??'Uncategorised') ?></div>
            </div>
            <div class="card-right">
              <div class="card-val" style="color:<?= $qc ?>"><?= $p['qty'] ?></div>
              <div class="card-unit"><?= e($p['unit']) ?></div>
              <span class="badge <?= e($p['status']) ?>"><?= $stLabel ?></span>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($products)): ?>
          <div style="padding:40px;text-align:center;color:var(--ink4);font-size:13px">No products yet</div>
        <?php endif; ?>
      </div>
      <div id="prod-empty" style="display:none;padding:40px;text-align:center;color:var(--ink4);font-size:13px">No products match your search</div>
    </div><!-- /products -->

    <!-- ══ OPERATIONS ════════════════════════════════════════ -->
    <div class="screen" id="screen-ops">
      <div class="sec-hdr">
        <div class="sec-title">Operations</div>
        <div style="font-size:12px;color:var(--ink3);font-family:var(--mono)"><?= count($ops) ?> total</div>
      </div>

      <!-- Type chips -->
      <div class="chip-row">
        <div class="chip active" data-type="all"        onclick="setOpChip(this,'type')">All</div>
        <div class="chip"        data-type="Receipt"    onclick="setOpChip(this,'type')">Receipts</div>
        <div class="chip"        data-type="Delivery"   onclick="setOpChip(this,'type')">Deliveries</div>
        <div class="chip"        data-type="Transfer"   onclick="setOpChip(this,'type')">Transfers</div>
        <div class="chip"        data-type="Adjustment" onclick="setOpChip(this,'type')">Adjustments</div>
      </div>

      <!-- Status chips -->
      <div class="chip-row">
        <div class="chip active" data-status="all"     onclick="setOpChip(this,'status')">All status</div>
        <div class="chip"        data-status="draft"   onclick="setOpChip(this,'status')">Draft</div>
        <div class="chip"        data-status="waiting" onclick="setOpChip(this,'status')">Waiting</div>
        <div class="chip"        data-status="ready"   onclick="setOpChip(this,'status')">Ready</div>
        <div class="chip"        data-status="done"    onclick="setOpChip(this,'status')">Done</div>
      </div>

      <!-- Op cards -->
      <div class="card-list" id="ops-list" style="gap:10px">
        <?php foreach ($ops as $o):
          $party  = $o['supplier'] ?: $o['customer'] ?: '—';
          $whInfo = $o['type']==='Transfer'
            ? ($o['origin_code']??'?').' → '.($o['dest_code']??'?')
            : ($o['origin_code'] ?: $o['dest_code'] ?: '—');
          $canValidate = !in_array($o['status'],['done','canceled']);
          $actionLabel = $o['type']==='Delivery'?'Ship':($o['type']==='Transfer'?'Start':'Validate');
        ?>
          <div class="op-card"
               data-type="<?= e($o['type']) ?>"
               data-status="<?= e($o['status']) ?>">
            <div class="op-top">
              <div class="op-ref"><?= e($o['ref']) ?></div>
              <div class="op-date"><?= date('M j', strtotime($o['created_at'])) ?></div>
            </div>
            <div class="op-mid">
              <div class="op-type-tag"><?= e($o['type']) ?></div>
              <div class="op-party"><?= e($party) ?></div>
            </div>
            <div class="op-bottom">
              <div style="display:flex;align-items:center;gap:8px">
                <?= badge($o['status']) ?>
                <span class="op-wh"><?= e($whInfo) ?></span>
              </div>
              <?php if ($canValidate): ?>
                <button class="op-action-btn"
                        onclick="validateOp(<?= $o['id'] ?>,'<?= e($o['type']) ?>',this)">
                  <?= $actionLabel ?>
                </button>
              <?php else: ?>
                <span class="op-action-btn done-lbl">
                  <?= $o['type']==='Delivery'?'Shipped':'Done' ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($ops)): ?>
          <div style="padding:40px;text-align:center;color:var(--ink4);font-size:13px">No operations yet</div>
        <?php endif; ?>
      </div>
      <div id="ops-empty" style="display:none;padding:40px;text-align:center;color:var(--ink4);font-size:13px">No operations match your filters</div>
    </div><!-- /operations -->

    <!-- ══ WAREHOUSES ════════════════════════════════════════ -->
    <div class="screen" id="screen-warehouses">
      <div class="sec-hdr">
        <div class="sec-title">Warehouses</div>
      </div>
      <div class="card-list" style="gap:10px">
        <?php foreach ($warehouses as $wh):
          $pct  = $wh['capacity'] ? min(100,round($wh['total_qty']/$wh['capacity']*100)) : 0;
          $fill = $pct>80?'var(--warn)':'var(--accent)';
        ?>
          <div class="wh-card">
            <div class="wh-top">
              <div>
                <div class="wh-code"><?= e($wh['code']) ?></div>
                <div class="wh-name"><?= e($wh['name']) ?></div>
                <div class="wh-loc"><?= e($wh['location']??'—') ?></div>
              </div>
              <span class="badge <?= $pct>80?'low':'ok' ?>"><?= $pct ?>%</span>
            </div>
            <div class="wh-stats">
              <div>
                <div class="wh-stat-val"><?= number_format($wh['total_qty']) ?></div>
                <div class="wh-stat-lbl">units</div>
              </div>
              <div>
                <div class="wh-stat-val"><?= $wh['product_count'] ?></div>
                <div class="wh-stat-lbl">SKUs</div>
              </div>
              <div>
                <div class="wh-stat-val"><?= number_format($wh['capacity']) ?></div>
                <div class="wh-stat-lbl">capacity</div>
              </div>
            </div>
            <div class="cap-row">
              <span>Capacity used</span>
              <span style="color:<?= $fill ?>;font-weight:600"><?= $pct ?>%</span>
            </div>
            <div class="prog-track">
              <div class="prog-fill" style="width:<?= $pct ?>%;background:<?= $fill ?>"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div><!-- /warehouses -->

    <!-- ══ PROFILE ═══════════════════════════════════════════ -->
    <div class="screen" id="screen-profile">
      <div class="profile-hero">
        <div class="avatar-big"><?= e($initials) ?></div>
        <div class="profile-name"><?= e($user['name']) ?></div>
        <div class="profile-role"><?= e($user['role']) ?></div>
        <div class="profile-email"><?= e($user['email']) ?></div>
      </div>

      <div class="settings-group">
        <div class="settings-row" onclick="navTo('screen-products')">
          <div class="settings-icon" style="background:var(--accent-l)">
            <svg viewBox="0 0 24 24" style="fill:var(--accent)"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
          </div>
          <div class="settings-label">Products</div>
          <div class="settings-val"><?= count($products) ?> items</div>
          <svg class="settings-arrow" viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
        </div>
        <div class="settings-row" onclick="navTo('screen-ops')">
          <div class="settings-icon" style="background:var(--pos-bg)">
            <svg viewBox="0 0 24 24" style="fill:var(--pos)"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4z"/></svg>
          </div>
          <div class="settings-label">Operations</div>
          <div class="settings-val"><?= count($ops) ?> total</div>
          <svg class="settings-arrow" viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
        </div>
        <div class="settings-row" onclick="navTo('screen-warehouses')">
          <div class="settings-icon" style="background:var(--warn-bg)">
            <svg viewBox="0 0 24 24" style="fill:var(--warn)"><path d="M12 3L2 12h3v8h14v-8h3L12 3z"/></svg>
          </div>
          <div class="settings-label">Warehouses</div>
          <div class="settings-val"><?= count($warehouses) ?> locations</div>
          <svg class="settings-arrow" viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
        </div>
      </div>

      <div class="settings-group">
        <div class="settings-row" onclick="location.href='logout.php'" style="color:var(--neg)">
          <div class="settings-icon" style="background:var(--neg-bg)">
            <svg viewBox="0 0 24 24" style="fill:var(--neg)"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
          </div>
          <div class="settings-label" style="color:var(--neg)">Sign out</div>
        </div>
      </div>

      <div style="text-align:center;padding:20px;font-size:11px;color:var(--ink4);font-family:var(--mono)">
        StockAxis IMS v2.1 · Mobile PHP · <?= date('Y') ?>
      </div>
    </div><!-- /profile -->

  </div><!-- /content -->

  <!-- ── FAB ──────────────────────────────────────────────── -->
  <button class="fab" id="fab-btn" onclick="openModal('modal-fab')">
    <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
  </button>

  <!-- ── BOTTOM NAV ─────────────────────────────────────────── -->
  <div id="bottom-nav">
    <div class="nav-tab active" id="tab-home"       onclick="navTo('screen-home')">
      <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
      <span>Home</span>
    </div>
    <div class="nav-tab" id="tab-products"          onclick="navTo('screen-products')">
      <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
      <span>Products</span>
    </div>
    <div class="nav-tab" id="tab-ops"               onclick="navTo('screen-ops')">
      <svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4z"/></svg>
      <span>Ops</span>
      <?php if (count($pending)>0): ?>
        <div class="nav-badge"><?= count($pending) ?></div>
      <?php endif; ?>
    </div>
    <div class="nav-tab" id="tab-warehouses"        onclick="navTo('screen-warehouses')">
      <svg viewBox="0 0 24 24"><path d="M12 3L2 12h3v8h14v-8h3L12 3z"/></svg>
      <span>Warehouses</span>
    </div>
    <div class="nav-tab" id="tab-profile"           onclick="navTo('screen-profile')">
      <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
      <span>Profile</span>
    </div>
  </div>
</div><!-- /app -->

<!-- ══ MODALS ════════════════════════════════════════════════ -->

<!-- FAB picker -->
<div class="modal-overlay" id="modal-fab">
  <div class="modal-box">
    <div class="modal-handle"></div>
    <div class="modal-header">
      <div class="modal-title">What would you like to add?</div>
      <div class="modal-close" onclick="closeModal('modal-fab')">✕</div>
    </div>
    <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;padding-bottom:24px">
      <div class="list-card" style="flex-direction:column;align-items:flex-start;gap:8px"
           onclick="closeModal('modal-fab');openModal('modal-new-op')">
        <div class="card-icon" style="background:var(--accent-l);border-color:rgba(79,110,247,.3)">
          <svg viewBox="0 0 24 24" style="fill:var(--accent)"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4z"/></svg>
        </div>
        <div><div style="font-size:13px;font-weight:700">New operation</div><div style="font-size:11px;color:var(--ink3)">Receipt, delivery, transfer</div></div>
      </div>
      <div class="list-card" style="flex-direction:column;align-items:flex-start;gap:8px"
           onclick="closeModal('modal-fab');openModal('modal-add-prod')">
        <div class="card-icon" style="background:var(--pos-bg);border-color:rgba(45,212,160,.3)">
          <svg viewBox="0 0 24 24" style="fill:var(--pos)"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
        </div>
        <div><div style="font-size:13px;font-weight:700">Add product</div><div style="font-size:11px;color:var(--ink3)">Create a new SKU</div></div>
      </div>
    </div>
  </div>
</div>

<!-- New Operation -->
<div class="modal-overlay" id="modal-new-op">
  <div class="modal-box">
    <div class="modal-handle"></div>
    <div class="modal-header">
      <div class="modal-title">New Operation</div>
      <div class="modal-close" onclick="closeModal('modal-new-op')">✕</div>
    </div>
    <div class="modal-body">
      <div class="field-row">
        <div class="field-group">
          <div class="field-label">Type</div>
          <select class="field-select" id="nop-type">
            <option>Receipt</option><option>Delivery</option>
            <option>Transfer</option><option>Adjustment</option>
          </select>
        </div>
        <div class="field-group">
          <div class="field-label">Status</div>
          <select class="field-select" id="nop-status">
            <option value="draft">Draft</option>
            <option value="waiting">Waiting</option>
            <option value="ready">Ready</option>
          </select>
        </div>
      </div>
      <div class="field-group">
        <div class="field-label">Supplier / Customer</div>
        <input class="field-input" type="text" id="nop-party" placeholder="Company name">
      </div>
      <div class="field-group">
        <div class="field-label">Warehouse</div>
        <select class="field-select" id="nop-wh">
          <?php foreach ($warehouses as $wh): ?>
            <option value="<?= $wh['id'] ?>"><?= e($wh['code']) ?> — <?= e($wh['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field-group">
        <div class="field-label">Notes</div>
        <input class="field-input" type="text" id="nop-notes" placeholder="Optional notes">
      </div>
    </div>
    <div class="modal-footer">
      <button class="modal-btn" onclick="closeModal('modal-new-op')">Cancel</button>
      <button class="modal-btn primary" onclick="createOp()">Create</button>
    </div>
  </div>
</div>

<!-- Add Product -->
<div class="modal-overlay" id="modal-add-prod">
  <div class="modal-box">
    <div class="modal-handle"></div>
    <div class="modal-header">
      <div class="modal-title">Add Product</div>
      <div class="modal-close" onclick="closeModal('modal-add-prod')">✕</div>
    </div>
    <div class="modal-body">
      <div class="field-row">
        <div class="field-group">
          <div class="field-label">Name</div>
          <input class="field-input" type="text" id="np-name" placeholder="Product name">
        </div>
        <div class="field-group">
          <div class="field-label">SKU</div>
          <input class="field-input" type="text" id="np-sku" placeholder="SKU-0000" style="font-family:var(--mono)">
        </div>
      </div>
      <div class="field-row">
        <div class="field-group">
          <div class="field-label">Category</div>
          <select class="field-select" id="np-cat">
            <?php foreach ($categories as $c): ?>
              <option value="<?= e($c['name']) ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-group">
          <div class="field-label">Unit</div>
          <select class="field-select" id="np-unit">
            <option>units</option><option>kg</option><option>litres</option><option>boxes</option>
          </select>
        </div>
      </div>
      <div class="field-row">
        <div class="field-group">
          <div class="field-label">Initial qty</div>
          <input class="field-input" type="number" id="np-qty" placeholder="0" min="0" inputmode="numeric">
        </div>
        <div class="field-group">
          <div class="field-label">Reorder point</div>
          <input class="field-input" type="number" id="np-reorder" placeholder="10" min="0" inputmode="numeric">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="modal-btn" onclick="closeModal('modal-add-prod')">Cancel</button>
      <button class="modal-btn primary" onclick="addProduct()">Add product</button>
    </div>
  </div>
</div>

<!-- Edit Product -->
<div class="modal-overlay" id="modal-edit-prod">
  <div class="modal-box">
    <div class="modal-handle"></div>
    <div class="modal-header">
      <div>
        <div class="modal-title">Edit Product</div>
        <div style="font-size:11px;color:var(--accent);font-family:var(--mono);margin-top:2px" id="ep-sku-lbl"></div>
      </div>
      <div class="modal-close" onclick="closeModal('modal-edit-prod')">✕</div>
    </div>
    <div class="modal-body">
      <input type="hidden" id="ep-id">
      <div class="field-group">
        <div class="field-label">Product name</div>
        <input class="field-input" type="text" id="ep-name" placeholder="Product name" style="font-size:16px;font-weight:600">
      </div>
      <div class="field-row">
        <div class="field-group">
          <div class="field-label">SKU</div>
          <input class="field-input" type="text" id="ep-sku" style="font-family:var(--mono)">
        </div>
        <div class="field-group">
          <div class="field-label">Category</div>
          <select class="field-select" id="ep-cat">
            <?php foreach ($categories as $c): ?>
              <option value="<?= e($c['name']) ?>"><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field-row">
        <div class="field-group">
          <div class="field-label">Unit</div>
          <select class="field-select" id="ep-unit">
            <option>units</option><option>kg</option><option>litres</option><option>boxes</option><option>pallets</option>
          </select>
        </div>
        <div class="field-group">
          <div class="field-label">Reorder point</div>
          <input class="field-input" type="number" id="ep-reorder" inputmode="numeric">
        </div>
      </div>
      <div class="field-group">
        <div class="field-label">Current quantity</div>
        <input class="field-input" type="number" id="ep-qty" inputmode="numeric">
      </div>
      <div style="display:flex;justify-content:flex-end;margin-top:4px">
        <button style="background:none;border:none;color:var(--neg);font-size:12px;font-family:var(--font);cursor:pointer;padding:4px"
                onclick="deleteProduct()">Delete this product</button>
      </div>
    </div>
    <div class="modal-footer">
      <button class="modal-btn" onclick="closeModal('modal-edit-prod')">Cancel</button>
      <button class="modal-btn primary" id="ep-save-btn" onclick="saveEditProduct()">Save changes</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
  <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
  <span id="toast-msg">Done</span>
</div>

<script>
// ── Navigation ─────────────────────────────────────────────────
const TABS = { 'screen-home':'tab-home','screen-products':'tab-products','screen-ops':'tab-ops','screen-warehouses':'tab-warehouses','screen-profile':'tab-profile' };
function navTo(screenId) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
  const s = document.getElementById(screenId);
  if (s) s.classList.add('active');
  const t = document.getElementById(TABS[screenId]);
  if (t) t.classList.add('active');
  document.getElementById('content').scrollTop = 0;
  const hideFab = screenId === 'screen-profile' || screenId === 'screen-warehouses';
  document.getElementById('fab-btn').style.display = hideFab ? 'none' : 'flex';
}

// ── Modals ────────────────────────────────────────────────────
function openModal(id) {
  const m = document.getElementById(id);
  m.classList.add('open');
  m.addEventListener('click', e => { if (e.target === m) closeModal(id); }, { once: true });
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// ── Toast ─────────────────────────────────────────────────────
let _toastT;
function toast(msg, type='ok') {
  const t = document.getElementById('toast');
  t.querySelector('svg').style.fill = type==='ok'?'var(--pos)':type==='warn'?'var(--warn)':'var(--neg)';
  document.getElementById('toast-msg').textContent = msg;
  t.classList.add('show');
  clearTimeout(_toastT);
  _toastT = setTimeout(() => t.classList.remove('show'), 2600);
}

// ── AJAX post helper ──────────────────────────────────────────
async function post(action, data) {
  try {
    const res = await fetch('index.php?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify(data)
    });
    return await res.json();
  } catch { return { ok: false, error: 'Network error.' }; }
}

// ── Product search & filter (client-side) ─────────────────────
let _prodSt = 'all';
function setProdChip(el) {
  el.closest('.chip-row').querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
  _prodSt = el.dataset.st;
  filterProds();
}
function filterProds() {
  const q  = (document.getElementById('prod-search').value || '').toLowerCase();
  const cards = document.querySelectorAll('#prod-list .prod-card');
  let shown = 0;
  cards.forEach(c => {
    const nameOk = !q || c.dataset.name.includes(q) || c.dataset.sku.includes(q);
    const stOk   = _prodSt === 'all' || c.dataset.status === _prodSt;
    const show   = nameOk && stOk;
    c.style.display = show ? '' : 'none';
    if (show) shown++;
  });
  document.getElementById('prod-empty').style.display = shown === 0 ? 'block' : 'none';
}

// ── Operations filter (client-side) ───────────────────────────
let _opType = 'all', _opSt = 'all';
function setOpChip(el, dim) {
  el.closest('.chip-row').querySelectorAll('.chip').forEach(c => c.classList.remove('active'));
  el.classList.add('active');
  if (dim === 'type')   _opType = el.dataset.type;
  if (dim === 'status') _opSt   = el.dataset.status;
  filterOps();
}
function filterOps() {
  const cards = document.querySelectorAll('#ops-list .op-card');
  let shown = 0;
  cards.forEach(c => {
    const tOk = _opType === 'all' || c.dataset.type   === _opType;
    const sOk = _opSt   === 'all' || c.dataset.status === _opSt;
    const show = tOk && sOk;
    c.style.display = show ? '' : 'none';
    if (show) shown++;
  });
  document.getElementById('ops-empty').style.display = shown === 0 ? 'block' : 'none';
}

// ── Create operation ──────────────────────────────────────────
async function createOp() {
  const type   = document.getElementById('nop-type').value;
  const status = document.getElementById('nop-status').value;
  const party  = document.getElementById('nop-party').value.trim();
  const whId   = document.getElementById('nop-wh').value;
  const notes  = document.getElementById('nop-notes').value.trim();
  const r = await post('create_op', {
    type, status, notes, wh_id: whId,
    supplier: ['Receipt','Adjustment'].includes(type) ? party : '',
    customer: type === 'Delivery' ? party : ''
  });
  if (r.ok) {
    closeModal('modal-new-op');
    toast('Operation ' + r.ref + ' created');
    setTimeout(() => location.reload(), 800);
  } else { toast(r.error || 'Failed', 'neg'); }
}

// ── Validate operation ────────────────────────────────────────
async function validateOp(id, type, btn) {
  btn.textContent = '…'; btn.disabled = true;
  const r = await post('validate_op', { id });
  btn.disabled = false;
  if (r.ok) {
    toast(type==='Delivery'?'Shipment confirmed':type==='Transfer'?'Transfer started':'Receipt validated');
    setTimeout(() => location.reload(), 800);
  } else {
    btn.textContent = type==='Delivery'?'Ship':type==='Transfer'?'Start':'Validate';
    toast(r.error || 'Validation failed', 'neg');
  }
}

// ── Add product ───────────────────────────────────────────────
async function addProduct() {
  const name = document.getElementById('np-name').value.trim();
  const sku  = document.getElementById('np-sku').value.trim();
  if (!name || !sku) { toast('Name and SKU are required', 'neg'); return; }
  const r = await post('add_product', {
    name, sku,
    cat:     document.getElementById('np-cat').value,
    unit:    document.getElementById('np-unit').value,
    qty:     parseInt(document.getElementById('np-qty').value)     || 0,
    reorder: parseInt(document.getElementById('np-reorder').value) || 10,
  });
  if (r.ok) { closeModal('modal-add-prod'); toast(name + ' added'); setTimeout(() => location.reload(), 800); }
  else { toast(r.error || 'Failed', 'neg'); }
}

// ── Edit product ──────────────────────────────────────────────
let _editProdData = null;
function openEditModal(id, data) {
  _editProdData = data || null;
  document.getElementById('ep-id').value      = id;
  document.getElementById('ep-name').value    = data?.name    || '';
  document.getElementById('ep-sku').value     = data?.sku     || '';
  document.getElementById('ep-unit').value    = data?.unit    || 'units';
  document.getElementById('ep-qty').value     = data?.qty     ?? '';
  document.getElementById('ep-reorder').value = data?.reorder_pt ?? '';
  document.getElementById('ep-sku-lbl').textContent = data?.sku || '';
  const catSel = document.getElementById('ep-cat');
  if (data?.cat) { for (const opt of catSel.options) { if (opt.value === data.cat) { opt.selected = true; break; } } }
  openModal('modal-edit-prod');
  setTimeout(() => document.getElementById('ep-name').focus(), 120);
}

async function saveEditProduct() {
  const id   = parseInt(document.getElementById('ep-id').value);
  const name = document.getElementById('ep-name').value.trim();
  if (!name) { toast('Name is required', 'neg'); return; }
  const btn = document.getElementById('ep-save-btn');
  btn.textContent = 'Saving…'; btn.disabled = true;
  const r = await post('update_product', {
    id, name,
    sku:        document.getElementById('ep-sku').value.trim(),
    cat:        document.getElementById('ep-cat').value,
    unit:       document.getElementById('ep-unit').value,
    qty:        parseInt(document.getElementById('ep-qty').value)     || 0,
    reorder_pt: parseInt(document.getElementById('ep-reorder').value) || 10,
  });
  btn.textContent = 'Save changes'; btn.disabled = false;
  if (r.ok) {
    closeModal('modal-edit-prod');
    toast(name + ' updated');
    setTimeout(() => location.reload(), 800);
  } else { toast(r.error || 'Failed', 'neg'); }
}

async function deleteProduct() {
  const id   = parseInt(document.getElementById('ep-id').value);
  const name = document.getElementById('ep-name').value || 'this product';
  if (!confirm('Delete "' + name + '"?\nThis cannot be undone.')) return;
  const r = await post('delete_product', { id });
  if (r.ok) { closeModal('modal-edit-prod'); toast(name + ' deleted'); setTimeout(() => location.reload(), 800); }
  else { toast(r.error || 'Failed', 'neg'); }
}

// ── Service worker ────────────────────────────────────────────
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('./sw.php').catch(() => {});
}
</script>
</body>
</html>
