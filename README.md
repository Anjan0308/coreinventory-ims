# coreinventory-ims
# CoreInventory

**CoreInventory** is a modular **Inventory Management System (IMS)** developed for the **Odoo Hackathon**.  
The system digitizes and centralizes inventory operations, replacing manual tracking methods such as spreadsheets and paper registers with a structured, real-time inventory platform.

---

## Overview

Many businesses still rely on fragmented tools to manage inventory, leading to inaccurate stock levels, operational delays, and poor visibility into warehouse activities.

CoreInventory addresses these challenges by providing a centralized system that manages:

- Product catalog and stock levels  
- Incoming and outgoing inventory  
- Internal stock transfers  
- Stock adjustments and audit trails  

The system is designed for both **inventory managers** and **warehouse staff**, enabling efficient stock monitoring and operational control.

---

## Target Users

### Inventory Managers

Responsible for supervising inventory operations, monitoring stock levels, and maintaining product records.

### Warehouse Staff

Responsible for physical warehouse activities such as receiving goods, picking items, transferring inventory, and performing stock counts.

---

## Core Features

### Authentication

- Secure user login and registration  
- OTP-based password reset  
- Profile management  

---

### Dashboard

The dashboard provides a summary of warehouse activity and key inventory indicators.

**Key metrics include:**

- Total products in stock  
- Low stock and out-of-stock items  
- Pending receipts  
- Pending deliveries  
- Scheduled internal transfers  

Dynamic filters allow users to view data by:

- Document type  
- Status  
- Warehouse or location  
- Product category  

---

### Product Management

The system enables administrators to create and maintain product records including:

- Product name  
- SKU or product code  
- Product category  
- Unit of measure  
- Initial stock quantity  

Stock availability can be tracked across multiple locations.

---

### Receipts (Incoming Goods)

Receipts are created when goods arrive from vendors.

**Process:**

1. Create a new receipt  
2. Add supplier information  
3. Add products and quantities  
4. Validate the receipt  

Once validated, inventory quantities are automatically increased.

Example:

Receiving **50 units of steel rods** increases the recorded stock by **50 units**.

---

### Delivery Orders (Outgoing Goods)

Delivery orders are used when products are shipped to customers.

**Process:**

1. Pick items from inventory  
2. Pack items for shipment  
3. Validate the delivery order  

After validation, the system automatically reduces the stock quantity.

---

### Internal Transfers

Internal transfers allow movement of stock between warehouses or locations.

Examples:

- Main warehouse to production floor  
- Rack A to Rack B  
- Warehouse 1 to Warehouse 2  

Each transfer is recorded in the system’s inventory ledger.

---

### Stock Adjustments

Stock adjustments correct differences between recorded inventory and physical counts.

**Steps:**

1. Select the product and location  
2. Enter the counted quantity  
3. Confirm the adjustment  

The system updates the stock and logs the adjustment for traceability.

---

## System Architecture

The system follows a typical **client-server architecture**:
