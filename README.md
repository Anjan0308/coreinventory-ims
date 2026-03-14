# StockAxis IMS — Backend Setup Guide (XAMPP)

## Project File Structure

```
stockaxis/                         ← Place inside: C:\xampp\htdocs\stockaxis\
│
├── app.html          ✅ (your original file — unchanged)
├── signin.html       ✅ (your original file — unchanged)
├── signup.html       ✅ (your original file — unchanged)
├── reset.html        ✅ (your original file — unchanged)
├── theme.css         ✅ (your original file — unchanged)
│
├── db.js             🔄 REPLACE with the new db.js
│                        (adds MySQL sync, keeps full localStorage fallback)
│
├── stockaxis.sql     🗄️  Run once in phpMyAdmin to create the database
│
└── api/                           ← New PHP backend folder
    ├── config.php                 ← DB connection + helpers
    ├── auth.php                   ← Register / Login / OTP / Password reset
    ├── products.php               ← Products CRUD + categories
    ├── operations.php             ← Receipts / Deliveries / Transfers / Adjustments
    ├── warehouses.php             ← Warehouse management + reorder rules
    └── history.php                ← Stock ledger / move history
```

---

## Step-by-Step XAMPP Setup

### 1. Install & Start XAMPP
- Download from https://www.apachefriends.org
- Start **Apache** and **MySQL** from the XAMPP Control Panel

### 2. Place the project files
Copy the entire `stockaxis/` folder to:
```
C:\xampp\htdocs\stockaxis\
```
On Mac/Linux:
```
/Applications/XAMPP/htdocs/stockaxis/
/opt/lampp/htdocs/stockaxis/
```

### 3. Create the database
1. Open your browser → http://localhost/phpmyadmin
2. Click **Import** tab (top menu)
3. Choose the `stockaxis.sql` file and click **Go**
4. You should see database `stockaxis` created with all tables and seed data

### 4. Verify config (api/config.php)
Default XAMPP settings are already set:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'stockaxis');
define('DB_USER', 'root');
define('DB_PASS', '');      // XAMPP default: empty password
```
If you have a MySQL password, update `DB_PASS` accordingly.

### 5. Open the app
```
http://localhost/stockaxis/signin.html
```

### 6. Demo login credentials
| Email | Password | Role |
|-------|----------|------|
| admin@stockaxis.com | Admin@1234 | Admin |

Or **create a new account** via the Sign Up page.

---

## How the Backend Works

### Dual-Mode Architecture
`db.js` has been updated to work in **two modes simultaneously**:

| Mode | When used | How |
|------|-----------|-----|
| **MySQL (XAMPP)** | When PHP API is reachable | Full CRUD via REST API |
| **localStorage only** | API unreachable / offline | Falls back silently |

All existing HTML files (`app.html`, `signin.html`, etc.) are **100% unchanged** — they call `DB.xxx()` methods exactly as before.

### API Endpoints Reference

#### Auth (`api/auth.php`)
| Action | Method | Description |
|--------|--------|-------------|
| `register` | POST | Create account |
| `login` | POST | Sign in |
| `logout` | POST | Destroy session |
| `me` | GET | Current user |
| `gen_otp` | POST | Generate OTP for password reset |
| `verify_otp` | POST | Verify OTP code |
| `reset_pass` | POST | Set new password |

#### Products (`api/products.php`)
| Action | Method | Description |
|--------|--------|-------------|
| `list` | GET | All products (supports `?cat=`, `?status=`, `?q=` filters) |
| `get&id=X` | GET | Single product |
| `categories` | GET | All categories |
| `stock_by_location&id=X` | GET | Per-warehouse stock |
| `add` | POST | Create product |
| `update` | POST | Update product |
| `delete&id=X` | POST | Delete product |

#### Operations (`api/operations.php`)
| Action | Method | Description |
|--------|--------|-------------|
| `list` | GET | All ops (supports `?type=`, `?status=` filters) |
| `get&id=X` | GET | Single operation with line items |
| `create` | POST | New receipt / delivery / transfer / adjustment |
| `validate` | POST | Mark done + auto-update stock quantities |
| `status` | POST | Change status manually |
| `ledger` | GET | Full stock movement log |

#### Warehouses (`api/warehouses.php`)
| Action | Method | Description |
|--------|--------|-------------|
| `list` | GET | All warehouses with capacity stats |
| `get&id=X` | GET | Single warehouse |
| `add` | POST | Create warehouse |
| `update` | POST | Update warehouse |
| `delete&id=X` | POST | Delete warehouse |
| `reorder_rules` | GET | All reorder rules |
| `save_rule` | POST | Upsert reorder rule |

#### Move History (`api/history.php`)
| Action | Method | Description |
|--------|--------|-------------|
| `list` | GET | Paginated stock ledger |
| `product&id=X` | GET | Ledger for one product |
| `warehouse&id=X` | GET | Ledger for one warehouse |
| `summary` | GET | Dashboard KPI data |

---

## Database Tables Overview

| Table | Purpose |
|-------|---------|
| `users` | User accounts (bcrypt passwords) |
| `otp_tokens` | Password-reset OTP codes (10-min expiry) |
| `warehouses` | WH-A, WH-B, WH-C locations |
| `categories` | Product categories |
| `products` | Product master (SKU, name, qty, reorder point) |
| `stock_locations` | Per-product, per-warehouse stock quantities |
| `operations` | Receipts / Deliveries / Transfers / Adjustments |
| `operation_lines` | Line items within each operation |
| `stock_ledger` | Full audit trail of every stock movement |
| `reorder_rules` | Min/max/lead-time rules per product |

---

## Key Business Logic

### Stock is updated when an operation is **Validated** (`status = done`)
- **Receipt** → stock **increases** for destination warehouse
- **Delivery** → stock **decreases** from origin warehouse  
- **Transfer** → stock moves between warehouses (out from origin, in to dest)
- **Adjustment** → delta applied (positive = gain, negative = loss/damage)
- Every movement is logged to `stock_ledger` for full traceability

### Product status auto-updates via MySQL trigger
```
qty = 0         → status = 'out'
qty ≤ reorder   → status = 'low'
qty > reorder   → status = 'ok'
```

### Inventory Flow Example
```
1. Create Receipt  (REC/2026/00001)  → status: draft
2. Confirm supplier                  → status: waiting → ready
3. Validate receipt                  → status: done
   └─ Stock +100 kg Steel logged in stock_ledger
4. Create Transfer (INT/2026/00001)  → Main Store → Production Rack
   └─ Location updated in stock_locations, total unchanged
5. Validate Delivery (DEL/2026/00001)
   └─ Stock -20 kg logged
6. Submit Adjustment (ADJ/2026/00001) — 3 kg damaged
   └─ Stock -3 kg logged with reason "Damaged goods"
```

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Blank page / API errors | Check XAMPP Apache + MySQL are running |
| "Database connection failed" | Verify `config.php` credentials |
| 404 on API calls | Confirm files are in `htdocs/stockaxis/api/` |
| CORS errors | Open via `localhost`, not `file://` |
| App works but doesn't sync | API unreachable — app uses localStorage fallback |
| phpMyAdmin import fails | Check MySQL version ≥ 5.7; try running SQL manually |
