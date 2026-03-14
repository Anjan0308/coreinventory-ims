-- ============================================================
-- StockAxis IMS — MySQL Database Schema
-- Compatible with XAMPP (MySQL 5.7+ / MariaDB 10+)
-- Run this in phpMyAdmin or via: mysql -u root -p < stockaxis.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS stockaxis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE stockaxis;

-- ============================================================
-- 1. USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120)  NOT NULL,
  email       VARCHAR(180)  NOT NULL UNIQUE,
  password    VARCHAR(255)  NOT NULL,          -- bcrypt hash (via PHP password_hash)
  role        ENUM('Inventory Manager','Warehouse Staff','Admin') NOT NULL DEFAULT 'Warehouse Staff',
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- 2. OTP (password reset tokens)
-- ============================================================
CREATE TABLE IF NOT EXISTS otp_tokens (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(180)  NOT NULL,
  otp         CHAR(6)       NOT NULL,
  expires_at  DATETIME      NOT NULL,
  used        TINYINT(1)    NOT NULL DEFAULT 0,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email (email)
);

-- ============================================================
-- 3. WAREHOUSES
-- ============================================================
CREATE TABLE IF NOT EXISTS warehouses (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code         VARCHAR(20)   NOT NULL UNIQUE,   -- e.g. WH-A
  name         VARCHAR(120)  NOT NULL,          -- e.g. Main Warehouse
  location     VARCHAR(255),
  capacity     INT UNSIGNED  DEFAULT 1000,      -- max units
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- 4. CATEGORIES
-- ============================================================
CREATE TABLE IF NOT EXISTS categories (
  id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(80) NOT NULL UNIQUE
);

-- ============================================================
-- 5. PRODUCTS
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku          VARCHAR(50)   NOT NULL UNIQUE,
  name         VARCHAR(180)  NOT NULL,
  category_id  INT UNSIGNED,
  unit         VARCHAR(20)   NOT NULL DEFAULT 'units',
  qty          INT           NOT NULL DEFAULT 0,
  reorder_pt   INT           NOT NULL DEFAULT 10,     -- low-stock threshold
  status       ENUM('ok','low','out') NOT NULL DEFAULT 'out',
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- ============================================================
-- 6. PRODUCT STOCK PER WAREHOUSE LOCATION
-- ============================================================
CREATE TABLE IF NOT EXISTS stock_locations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    INT UNSIGNED NOT NULL,
  warehouse_id  INT UNSIGNED NOT NULL,
  qty           INT          NOT NULL DEFAULT 0,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prod_wh (product_id, warehouse_id),
  FOREIGN KEY (product_id)   REFERENCES products(id)   ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
);

-- ============================================================
-- 7. OPERATIONS  (Receipts, Deliveries, Transfers, Adjustments)
-- ============================================================
CREATE TABLE IF NOT EXISTS operations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ref           VARCHAR(30)  NOT NULL UNIQUE,          -- e.g. REC/2026/00001
  type          ENUM('Receipt','Delivery','Transfer','Adjustment') NOT NULL,
  status        ENUM('draft','waiting','ready','done','canceled') NOT NULL DEFAULT 'draft',
  supplier      VARCHAR(120),                          -- Receipt: supplier name
  customer      VARCHAR(120),                          -- Delivery: customer name
  origin_wh     INT UNSIGNED,                          -- source warehouse id
  dest_wh       INT UNSIGNED,                          -- dest warehouse id
  notes         TEXT,
  scheduled_at  DATE,
  created_by    INT UNSIGNED,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (origin_wh)  REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (dest_wh)    REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id)      ON DELETE SET NULL
);

-- ============================================================
-- 8. OPERATION LINE ITEMS
-- ============================================================
CREATE TABLE IF NOT EXISTS operation_lines (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  operation_id  INT UNSIGNED NOT NULL,
  product_id    INT UNSIGNED NOT NULL,
  qty_expected  INT          NOT NULL DEFAULT 0,
  qty_done      INT          NOT NULL DEFAULT 0,
  FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id)   REFERENCES products(id)   ON DELETE CASCADE
);

-- ============================================================
-- 9. STOCK LEDGER  (every stock movement is logged here)
-- ============================================================
CREATE TABLE IF NOT EXISTS stock_ledger (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id    INT UNSIGNED NOT NULL,
  warehouse_id  INT UNSIGNED,
  operation_id  INT UNSIGNED,
  delta         INT          NOT NULL,     -- positive = in, negative = out
  qty_after     INT          NOT NULL,     -- stock level after movement
  move_type     ENUM('receipt','delivery','transfer_in','transfer_out','adjustment') NOT NULL,
  reason        VARCHAR(180),
  created_by    INT UNSIGNED,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id)   REFERENCES products(id)   ON DELETE CASCADE,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL,
  FOREIGN KEY (operation_id) REFERENCES operations(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by)   REFERENCES users(id)      ON DELETE SET NULL
);

-- ============================================================
-- 10. REORDERING RULES
-- ============================================================
CREATE TABLE IF NOT EXISTS reorder_rules (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id      INT UNSIGNED NOT NULL UNIQUE,
  min_qty         INT          NOT NULL DEFAULT 10,   -- triggers reorder
  max_qty         INT          NOT NULL DEFAULT 100,  -- order-up-to qty
  lead_days       INT          NOT NULL DEFAULT 3,
  preferred_wh    INT UNSIGNED,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id)  REFERENCES products(id)   ON DELETE CASCADE,
  FOREIGN KEY (preferred_wh) REFERENCES warehouses(id) ON DELETE SET NULL
);

-- ============================================================
-- SEED DATA
-- ============================================================

-- Warehouses
INSERT IGNORE INTO warehouses (code, name, location, capacity) VALUES
  ('WH-A', 'Main Warehouse',  'Building A, Floor 1', 2000),
  ('WH-B', 'North Hub',       'Building B, Floor 2', 1500),
  ('WH-C', 'South Depot',     'South Campus',        1200);

-- Categories
INSERT IGNORE INTO categories (name) VALUES
  ('Electronics'), ('Accessories'), ('Office'), ('Furniture');

-- Demo admin user  (password: Admin@1234)
INSERT IGNORE INTO users (name, email, password, role) VALUES
  ('Admin User', 'admin@stockaxis.com',
   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
   'Admin');

-- Demo products
INSERT IGNORE INTO products (sku, name, category_id, unit, qty, reorder_pt, status) VALUES
  ('SKU-4821', 'USB-C Hub 7-in-1',   1, 'units',   0, 20, 'out'),
  ('SKU-2047', 'Laptop Stand Pro',    2, 'units',  12, 15, 'low'),
  ('SKU-3310', 'Mechanical Keyboard', 1, 'units', 156, 30, 'ok'),
  ('SKU-1982', 'Wireless Mouse',      1, 'units', 243, 40, 'ok'),
  ('SKU-5501', 'Monitor Arm',         2, 'units',  38, 10, 'ok'),
  ('SKU-6612', 'Desk Lamp LED',       3, 'units',   8, 10, 'low'),
  ('SKU-7734', 'Cable Manager',       2, 'units', 412, 50, 'ok'),
  ('SKU-8821', 'Standing Desk',       4, 'units',   0,  5, 'out'),
  ('SKU-9901', 'Ergonomic Chair',     4, 'units',  22,  5, 'ok'),
  ('SKU-0012', 'Webcam HD',           1, 'units',   5, 10, 'low');

-- ============================================================
-- TRIGGERS: auto-update product status when qty changes
-- ============================================================
DELIMITER $$

DROP TRIGGER IF EXISTS trg_product_status_update $$
CREATE TRIGGER trg_product_status_update
  BEFORE UPDATE ON products
  FOR EACH ROW
BEGIN
  IF NEW.qty = 0 THEN
    SET NEW.status = 'out';
  ELSEIF NEW.qty <= NEW.reorder_pt THEN
    SET NEW.status = 'low';
  ELSE
    SET NEW.status = 'ok';
  END IF;
END$$

DELIMITER ;
