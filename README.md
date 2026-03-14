# StockAxis IMS

> A full-stack Inventory Management System built with PHP, MySQL, and vanilla JavaScript. Includes a desktop web app, a mobile PWA, Excel/CSV product import, and a complete REST API — all running on XAMPP.

![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?style=flat&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-4479A1?style=flat&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-ES2020-F7DF1E?style=flat&logo=javascript&logoColor=black)
![License](https://img.shields.io/badge/License-MIT-green?style=flat)

---

## Screenshots

| Desktop App | Mobile App |
|---|---|
| Dashboard with KPI cards, operations table, activity feed | Dark-themed PWA with bottom nav, filter chips, bottom-sheet modals |

---

## Features

- **Authentication** — Sign up, sign in, forgot password with 6-digit OTP verification
- **Dashboard** — Live KPI cards (total products, low/out stock, pending operations, warehouses), activity feed, stock-by-category chart, weekly throughput, warehouse capacity bars
- **Products** — Full CRUD, inline name editing, category filters, status badges (ok / low / out), reorder point tracking
- **Operations** — Receipts, Deliveries, Transfers, Adjustments with full status workflow (draft → waiting → ready → done)
- **Stock ledger** — Complete audit trail of every stock movement ever made
- **Warehouses** — Capacity tracking with per-warehouse stock breakdown
- **Excel / CSV import** — Bulk upload products from a spreadsheet
- **Mobile PWA** — Installable dark-themed mobile app, works on Android & iOS from the same XAMPP server
- **Dual-mode JS layer** — `db.js` syncs to MySQL when available, falls back to `localStorage` offline

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.0+ (PDO, prepared statements, bcrypt) |
| Database | MySQL 5.7+ / MariaDB 10+ |
| Frontend | Vanilla JS, HTML5, CSS3 (no framework) |
| Fonts | Instrument Sans, Geist Mono (Google Fonts) |
| Server | XAMPP (Apache + MySQL) |
| Mobile | PWA — installable, offline-capable |

---

## Project Structure

```
stockaxis/
│
├── app.html                  ← Desktop app (single-page)
├── app.php                   ← Desktop app (PHP version, no infinite redirect)
├── signin.php                ← Login page
├── signup.php                ← Register page
├── reset.php                 ← Password reset (3-step OTP flow)
├── logout.php                ← Destroys PHP session
├── session.php               ← Session bridge (returns PHP session as JSON)
├── db.js                     ← JS data layer (MySQL sync + localStorage fallback)
├── theme.css                 ← Global design system (CSS variables, components)
├── import_excel.php          ← Bulk product import from CSV/Excel
├── products_csv.xlsx         ← Sample import spreadsheet
├── stockaxis.sql             ← Full database schema + seed data
│
├── api/                      ← REST API (JSON responses)
│   ├── config.php            ← PDO connection, helpers (jsonOut, getBody)
│   ├── auth.php              ← Register, login, logout, OTP, password reset
│   ├── products.php          ← Products CRUD + categories + stock by location
│   ├── operations.php        ← Operations CRUD + validate + stock ledger
│   ├── warehouses.php        ← Warehouse CRUD + reorder rules
│   └── history.php           ← Stock ledger queries + dashboard summary
│
└── stockaxis-mobile-php/     ← Mobile PWA (PHP-rendered)
    ├── index.php             ← Main mobile app (all screens + AJAX handlers)
    ├── signin.php            ← Mobile login
    ├── signup.php            ← Mobile register
    ├── reset.php             ← Mobile password reset
    ├── logout.php            ← Mobile logout
    ├── style.css             ← Mobile dark theme
    ├── sw.php                ← Service worker (PHP-served)
    └── manifest.json         ← PWA manifest
```

---

## Installation

### Prerequisites
- [XAMPP](https://www.apachefriends.org) with Apache and MySQL running
- PHP 8.0 or higher
- A modern browser (Chrome, Firefox, Safari, Edge)

### Step 1 — Clone or download

```bash
git clone https://github.com/yourusername/stockaxis.git
```

Or download the ZIP and extract it.

### Step 2 — Place files in XAMPP

Copy the project into XAMPP's web root:

```
Windows:  C:\xampp\htdocs\stockaxis\
Mac:      /Applications/XAMPP/htdocs/stockaxis/
Linux:    /opt/lampp/htdocs/stockaxis/
```

Place the mobile folder next to it:

```
C:\xampp\htdocs\
├── stockaxis\
│   └── api\         ← must exist at this path
└── stockaxis-mobile-php\
```

### Step 3 — Create the database

1. Open `http://localhost/phpmyadmin` in your browser
2. Click the **Import** tab
3. Select `stockaxis.sql` → click **Go**
4. The `stockaxis` database is created with all tables and demo data

### Step 4 — Configure database credentials

Open `api/config.php` and verify these match your XAMPP setup:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'stockaxis');
define('DB_USER', 'root');
define('DB_PASS', '');   // XAMPP default: empty password
```

### Step 5 — Open the app

```
Desktop:  http://localhost/stockaxis/signin.php
Mobile:   http://localhost/stockaxis-mobile-php/
```

### Demo credentials

| Email | Password | Role |
|---|---|---|
| admin@stockaxis.com | Admin@1234 | Admin |

Or create a new account via the Sign Up page.

---

## Mobile App Setup

The mobile PWA runs from `stockaxis-mobile-php/` and uses the same MySQL database.

### Access from your phone

1. Find your PC's local IP address:
   - **Windows:** Open Command Prompt → type `ipconfig` → look for **IPv4 Address**
   - **Mac:** Open Terminal → type `ipconfig getifaddr en0`

2. On your phone (same WiFi network), open:
   ```
   http://YOUR-PC-IP/stockaxis-mobile-php/
   ```

### Install as a native app

**Android (Chrome):** Menu (⋮) → Add to Home Screen → Add

**iPhone (Safari):** Share button → Add to Home Screen → Add

---

## Excel / CSV Import

To bulk-import products from a spreadsheet:

1. Prepare your file with columns in this order:
   ```
   name, category, price, quantity
   ```
2. Save as `products.csv` in the `stockaxis/` root folder
3. Visit: `http://localhost/stockaxis/import_excel.php`

A sample file `products_csv.xlsx` is included in the repository.

---

## API Reference

All API endpoints are in the `api/` folder and return JSON.

### Authentication — `api/auth.php`

| Action | Method | Description |
|---|---|---|
| `?action=register` | POST | Create a new user account |
| `?action=login` | POST | Sign in and start session |
| `?action=logout` | POST | Destroy session |
| `?action=me` | GET | Get current session user |
| `?action=gen_otp` | POST | Generate 6-digit OTP for password reset |
| `?action=verify_otp` | POST | Verify OTP code |
| `?action=reset_pass` | POST | Set new password after OTP verification |

### Products — `api/products.php`

| Action | Method | Description |
|---|---|---|
| `?action=list` | GET | All products (supports `?cat=`, `?status=`, `?q=`) |
| `?action=get&id=X` | GET | Single product by ID |
| `?action=categories` | GET | All product categories |
| `?action=stock_by_location&id=X` | GET | Per-warehouse stock for a product |
| `?action=add` | POST | Create a new product |
| `?action=update` | POST | Update product fields |
| `?action=delete` | POST | Delete a product |

### Operations — `api/operations.php`

| Action | Method | Description |
|---|---|---|
| `?action=list` | GET | All operations (supports `?type=`, `?status=`) |
| `?action=get&id=X` | GET | Single operation with line items |
| `?action=create` | POST | Create Receipt / Delivery / Transfer / Adjustment |
| `?action=validate` | POST | Mark done + update stock quantities |
| `?action=status` | POST | Change operation status manually |
| `?action=ledger` | GET | Full stock movement history |

### Warehouses — `api/warehouses.php`

| Action | Method | Description |
|---|---|---|
| `?action=list` | GET | All warehouses with capacity stats |
| `?action=get&id=X` | GET | Single warehouse |
| `?action=add` | POST | Create warehouse |
| `?action=update` | POST | Update warehouse details |
| `?action=delete` | POST | Delete warehouse |
| `?action=reorder_rules` | GET | All reorder rules |
| `?action=save_rule` | POST | Create or update a reorder rule |

### History — `api/history.php`

| Action | Method | Description |
|---|---|---|
| `?action=list` | GET | Paginated stock ledger |
| `?action=product&id=X` | GET | Ledger entries for one product |
| `?action=warehouse&id=X` | GET | Ledger entries for one warehouse |
| `?action=summary` | GET | Dashboard KPI aggregates |

---

## Database Schema

```
users              → Login accounts (bcrypt passwords)
otp_tokens         → Password-reset codes (10 min expiry)
warehouses         → Storage locations (WH-A, WH-B, WH-C)
categories         → Product categories
products           → Master product list (SKU, qty, reorder point)
stock_locations    → Per-product, per-warehouse quantities
operations         → Receipts, Deliveries, Transfers, Adjustments
operation_lines    → Individual product lines within an operation
stock_ledger       → Full audit trail of every stock movement
reorder_rules      → Min/max/lead-time rules per product
```

### Stock update flow

Stock quantities only change when an operation is **validated** (`status = done`):

```
Receipt    → qty increases at destination warehouse
Delivery   → qty decreases from origin warehouse
Transfer   → qty moves between two warehouses
Adjustment → delta applied (positive = gain, negative = loss)
```

Every movement writes a row to `stock_ledger` for full traceability.

### Product status (auto-updated by MySQL trigger)

```sql
qty = 0           → status = 'out'   (red badge)
qty ≤ reorder_pt  → status = 'low'   (yellow badge)
qty > reorder_pt  → status = 'ok'    (green badge)
```

---

## Dual-Mode Architecture

`db.js` operates in two modes simultaneously:

| Mode | When | How |
|---|---|---|
| **MySQL mode** | PHP API is reachable | Full CRUD via REST API calls |
| **Offline mode** | API unreachable | Falls back silently to `localStorage` |

This means the app always works — even without a database connection — and syncs automatically when the server becomes available again.

---

## Troubleshooting

| Problem | Solution |
|---|---|
| Blank white page | Check Apache is running in XAMPP Control Panel |
| "Database connection failed" | Check MySQL is running; verify `api/config.php` credentials |
| 404 on API calls | Confirm files are placed in `htdocs/stockaxis/api/` |
| CORS errors | Always open via `http://localhost/...`, never via `file://` |
| App works but doesn't sync to DB | API unreachable — app is in localStorage fallback mode |
| Infinite redirect loop on `app.html` | Use `app.php` instead — it fixes the session bridge issue |
| Phone can't connect | Check both phone and PC are on the same WiFi network |
| phpMyAdmin import fails | Try MySQL version ≥ 5.7; run SQL statements manually if needed |
| Password login fails | Make sure `stockaxis.sql` was imported (creates the admin user) |

---

## License

MIT License — free to use, modify, and distribute.

---

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Commit your changes: `git commit -m "Add your feature"`
4. Push to the branch: `git push origin feature/your-feature`
5. Open a Pull Request

---

## Acknowledgements

- [XAMPP](https://www.apachefriends.org) — local PHP + MySQL server
- [Google Fonts](https://fonts.google.com) — Instrument Sans, Geist Mono
- [PhpMyAdmin](https://www.phpmyadmin.net) — database management
