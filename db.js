// ============================================================
// StockAxis IMS — Database Layer (db.js)
// ============================================================
// This file keeps the ORIGINAL localStorage API so that all
// existing HTML pages (app.html, signin.html, signup.html,
// reset.html) work without modification.
//
// When the PHP/MySQL backend (XAMPP) is available, every call
// ALSO syncs with the REST API.  If the API is unreachable the
// app gracefully falls back to localStorage only.
//
// API base URL — change this to match your XAMPP setup:
const API_BASE = 'http://localhost/stockaxis/api';
// ============================================================

const DB = {

  // ──────────────────────────────────────────────────────────
  // INTERNAL: REST helpers
  // ──────────────────────────────────────────────────────────
  async _get(endpoint, params = {}) {
    try {
      const qs = new URLSearchParams(params).toString();
      const url = `${API_BASE}/${endpoint}${qs ? '?' + qs : ''}`;
      const res = await fetch(url, { credentials: 'include' });
      return res.ok ? await res.json() : null;
    } catch { return null; }
  },

  async _post(endpoint, action, data = {}) {
    try {
      const res = await fetch(`${API_BASE}/${endpoint}?action=${action}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(data),
      });
      return res.ok ? await res.json() : null;
    } catch { return null; }
  },

  // ──────────────────────────────────────────────────────────
  // USERS
  // ──────────────────────────────────────────────────────────
  getUsers() {
    return JSON.parse(localStorage.getItem('veristock_users') || '[]');
  },
  saveUsers(users) {
    localStorage.setItem('veristock_users', JSON.stringify(users));
  },

  async registerUser({ name, email, password, role }) {
    // Try backend first
    const res = await this._post('auth.php', 'register', { name, email, password, role });
    if (res) {
      if (res.ok) {
        // Mirror in localStorage so session works offline too
        const users = this.getUsers();
        users.push({ id: 'db_' + res.user.id, name, email, password: btoa(password), role, createdAt: new Date().toISOString() });
        this.saveUsers(users);
        return { ok: true, user: res.user };
      }
      return { ok: false, error: res.error };
    }
    // Fallback: localStorage only
    const users = this.getUsers();
    if (users.find(u => u.email === email)) return { ok: false, error: 'Email already registered.' };
    const user = { id: 'usr_' + Date.now(), name, email, password: btoa(password), role, createdAt: new Date().toISOString() };
    users.push(user);
    this.saveUsers(users);
    return { ok: true, user };
  },

  async loginUser(email, password) {
    // Try backend first
    const res = await this._post('auth.php', 'login', { email, password });
    if (res) {
      if (res.ok) return { ok: true, user: res.user };
      return { ok: false, error: res.error };
    }
    // Fallback: localStorage
    const users = this.getUsers();
    const user = users.find(u => u.email === email && u.password === btoa(password));
    if (!user) return { ok: false, error: 'Invalid email or password.' };
    return { ok: true, user };
  },

  getUserByEmail(email) {
    // Sync fallback only (used in reset.html step-1 check)
    return this.getUsers().find(u => u.email === email) || null;
  },

  async updatePassword(email, newPassword) {
    const res = await this._post('auth.php', 'reset_pass', { email, password: newPassword });
    // Also update localStorage mirror
    const users = this.getUsers();
    const idx = users.findIndex(u => u.email === email);
    if (idx !== -1) { users[idx].password = btoa(newPassword); this.saveUsers(users); }
    return res?.ok ?? true;
  },

  // ──────────────────────────────────────────────────────────
  // SESSION  (unchanged — localStorage/sessionStorage)
  // ──────────────────────────────────────────────────────────
  setSession(user) {
    sessionStorage.setItem('veristock_session', JSON.stringify({
      id: user.id, name: user.name, email: user.email, role: user.role
    }));
  },
  getSession() {
    return JSON.parse(sessionStorage.getItem('veristock_session') || 'null');
  },
  clearSession() {
    sessionStorage.removeItem('veristock_session');
    // Also logout from PHP session
    this._post('auth.php', 'logout');
  },

  // ──────────────────────────────────────────────────────────
  // OTP  (backend-first, localStorage fallback)
  // ──────────────────────────────────────────────────────────
  setOTP(email, otp) {
    const otps = JSON.parse(localStorage.getItem('veristock_otps') || '{}');
    otps[email] = { otp, expires: Date.now() + 10 * 60 * 1000 };
    localStorage.setItem('veristock_otps', JSON.stringify(otps));
  },
  verifyOTP(email, otp) {
    const otps = JSON.parse(localStorage.getItem('veristock_otps') || '{}');
    const rec = otps[email];
    if (!rec) return false;
    if (Date.now() > rec.expires) return false;
    return rec.otp === otp;
  },
  generateOTP(email) {
    const otp = String(Math.floor(100000 + Math.random() * 900000));
    this.setOTP(email, otp);
    // Fire-and-forget to backend
    this._post('auth.php', 'gen_otp', { email });
    return otp;
  },

  // ──────────────────────────────────────────────────────────
  // PRODUCTS  (backend-first, localStorage fallback)
  // ──────────────────────────────────────────────────────────
  getProducts() {
    const stored = localStorage.getItem('veristock_products');
    if (stored) return JSON.parse(stored);
    const defaults = [
       {id:'p2', sku:'SKU-4821', name:'USB-C Hub 7-in-1',   cat:'Electronics', qty:0,unit:'units', reorder:20, status:'out', img:'images/hub.jpg' },
        { id:'p3', sku:'SKU-2047', name:'Laptop Stand Pro',    cat:'Accessories', qty:12,  unit:'units', reorder:15, status:'low', img:'images/stand.jpg' },
        { id:'p4', sku:'SKU-3310', name:'Mechanical Keyboard', cat:'Electronics', qty:156, unit:'units', reorder:30, status:'ok', img:'images/keyboard.jpg' },
        { id:'p5', sku:'SKU-1982', name:'Wireless Mouse',      cat:'Electronics', qty:243, unit:'units', reorder:40, status:'ok', img:'images/mouse.jpg' },
        { id:'p6', sku:'SKU-5501', name:'Monitor Arm',         cat:'Accessories', qty:38,  unit:'units', reorder:10, status:'ok', img:'images/monitorarm.jpg' },
        { id:'p7', sku:'SKU-6612', name:'Desk Lamp LED',       cat:'Office',      qty:8,   unit:'units', reorder:10, status:'low', img:'images/lamp.jpg' },
        { id:'p8', sku:'SKU-7734', name:'Cable Manager',       cat:'Accessories', qty:412, unit:'units', reorder:50, status:'ok', img:'images/cable.jpg' },
        { id:'p9', sku:'SKU-8821', name:'Standing Desk',       cat:'Furniture',   qty:0,   unit:'units', reorder:5,  status:'out', img:'images/desk.jpg' },
        { id:'p10', sku:'SKU-9901', name:'Ergonomic Chair',     cat:'Furniture',   qty:22,  unit:'units', reorder:5,  status:'ok', img:'images/chair.jpg' },
        { id:'p11',sku:'SKU-0012', name:'Webcam HD',           cat:'Electronics', qty:5,   unit:'units', reorder:10, status:'low', img:'images/webcam.jpg' },
    ];
    this.saveProducts(defaults);
    return defaults;
  },
  saveProducts(products) {
    localStorage.setItem('veristock_products', JSON.stringify(products));
  },

  addProduct(product) {
    const products = this.getProducts();
    const qty     = parseInt(product.qty)     || 0;
    const reorder = parseInt(product.reorder) || 10;
    const status  = qty === 0 ? 'out' : qty <= reorder ? 'low' : 'ok';
    const p = { ...product, id: 'p_' + Date.now(), qty, reorder, status, createdAt: new Date().toISOString() };
    products.unshift(p);
    this.saveProducts(products);
    // Sync to backend (async, non-blocking)
    this._post('products.php', 'add', {
      name: p.name, sku: p.sku, cat: p.cat, unit: p.unit, qty: p.qty, reorder: p.reorder
    });
    return p;
  },

  // ──────────────────────────────────────────────────────────
  // OPERATIONS  (backend-first, localStorage fallback)
  // ──────────────────────────────────────────────────────────
  getOps() {
    const stored = localStorage.getItem('veristock_ops');
    if (stored) return JSON.parse(stored);
    const defaults = [
      { id:'op1', ref:'REC/2026/00142', type:'Receipt',  supplier:'TechPro Supplies', dest:'WH-A', date:'Mar 16', products:'3 products', status:'waiting' },
      { id:'op2', ref:'REC/2026/00141', type:'Receipt',  supplier:'Global Parts Co.', dest:'WH-B', date:'Mar 15', products:'7 products', status:'ready'   },
      { id:'op3', ref:'REC/2026/00140', type:'Receipt',  supplier:'Office Direct',    dest:'WH-A', date:'Mar 14', products:'2 products', status:'draft'    },
      { id:'op4', ref:'DEL/2026/00089', type:'Delivery', customer:'Acme Corp',        origin:'WH-A', date:'Mar 15', items:'4 items', status:'ready'   },
      { id:'op5', ref:'DEL/2026/00088', type:'Delivery', customer:'Beta Inc',         origin:'WH-A', date:'Mar 16', items:'2 items', status:'waiting' },
      { id:'op6', ref:'INT/2026/00032', type:'Transfer', from:'WH-A', to:'WH-B',     date:'Mar 15', products:'Laptop Stand Pro × 50', status:'waiting' },
      { id:'op7', ref:'INT/2026/00031', type:'Transfer', from:'WH-A', to:'WH-B',     date:'Mar 13', products:'Keyboards × 30',        status:'done'    },
      { id:'op8', ref:'ADJ/2026/00018', type:'Adjust',   product:'USB-C Hub 7-in-1', wh:'WH-A',    delta:'-5', reason:'Damaged',  date:'Mar 13', status:'done' },
    ];
    this.saveOps(defaults);
    return defaults;
  },
  saveOps(ops) {
    localStorage.setItem('veristock_ops', JSON.stringify(ops));
  },
  addOp(op) {
    const ops = this.getOps();
    const typePrefix = { Receipt:'REC', Delivery:'DEL', Transfer:'INT', Adjustment:'ADJ' };
    const pre  = typePrefix[op.type] || 'OP';
    const num  = String(ops.length + 1).padStart(5, '0');
    const newOp = { ...op, id: 'op_' + Date.now(), ref: `${pre}/2026/${num}`, status: op.status || 'draft', createdAt: new Date().toISOString() };
    ops.unshift(newOp);
    this.saveOps(ops);
    // Sync to backend
    this._post('operations.php', 'create', {
      type: op.type, status: newOp.status,
      supplier: op.supplier || '', customer: op.customer || '',
      origin_wh: op.from || op.origin || null,
      dest_wh:   op.to   || op.dest   || null,
    });
    return newOp;
  },
  updateOpStatus(id, status) {
    const ops = this.getOps();
    const idx = ops.findIndex(o => o.id === id);
    if (idx !== -1) { ops[idx].status = status; this.saveOps(ops); }
    // Sync to backend
    this._post('operations.php', 'status', { id, status });
    if (status === 'done') {
      this._post('operations.php', 'validate', { id });
    }
  },
};

window.DB = DB;
