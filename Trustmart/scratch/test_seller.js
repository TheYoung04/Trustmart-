
// â”€â”€ Tab switching â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
const TABS = ['dash','produk','pesanan'];
const TITLES = {
  dash:    'Papan Pemuka',
  produk:  'Produk Saya',
  pesanan: 'Pesanan Diterima',
};

function showTab(name) {
  TABS.forEach(t => {
    document.getElementById('tab-' + t).style.display = t === name ? 'block' : 'none';
  });
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active',
      el.getAttribute('onclick') === `showTab('${name}')`);
  });
  document.getElementById('topbar-title').textContent = TITLES[name];

  // Lazy-load orders table when tab first opened
  if (name === 'pesanan' && !window._ordersLoaded) {
    loadOrders('');
    window._ordersLoaded = true;
  }
}

// â”€â”€ Toast notification â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function showToast(msg, type = '') {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.className   = `toast show ${type}`;
  setTimeout(() => t.classList.remove('show'), 3200);
}

// â”€â”€ API helper â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
async function api(action, params = {}, body = null, isFormData = false) {
  const url = `?action=${action}&${new URLSearchParams(params)}`;
  const opts = { method: body ? 'POST' : 'GET' };
  if (body) {
    opts.body    = isFormData ? body : new URLSearchParams(body);
    opts.headers = isFormData ? {} : { 'Content-Type':'application/x-www-form-urlencoded' };
  }
  // Always signal we want JSON so auth_check.php returns JSON on session expiry
  opts.headers = Object.assign({ 'Accept': 'application/json' }, opts.headers || {});
  const res  = await fetch(url, opts);
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    console.error('[api] Non-JSON response for action=' + action + ':', text.slice(0, 300));
    return { success: false, message: 'Ralat pelayan: respons tidak sah.' };
  }
}

// â”€â”€ Order actions â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
async function updateOrder(txn_id, status) {
  const labels = {
    Disahkan:  'Sahkan pesanan ini?',
    Selesai:   'Tandakan pesanan sebagai selesai?',
    Dibatalkan:'Tolak / batalkan pesanan ini?',
  };
  if (!confirm(labels[status] || 'Teruskan?')) return;

  let data;
  try {
    data = await api('update_order_status', {}, {
      transaction_id: txn_id,
      status,
    });
  } catch (err) {
    showToast('Ralat sambungan. Sila cuba lagi.', 'error');
    console.error('[updateOrder] fetch error:', err);
    return;
  }

  showToast(data.message, data.success ? 'success' : 'error');

  if (data.success) {
    // Update badge and buttons in-place without full reload
    const row = document.getElementById('order-row-' + txn_id);
    if (row) {
      const badgeClass = {
        Menunggu:'badge-gold', Disahkan:'badge-blue',
        Selesai:'badge-green', Dibatalkan:'badge-red',
      }[status] || 'badge-gray';

      row.querySelector('.badge').className = `badge ${badgeClass}`;
      row.querySelector('.badge').innerHTML =
        `<span class="badge-dot"></span>${status}`;

      // Update action buttons
      const btns = row.querySelector('td:last-child div');
      if (status === 'Disahkan') {
        btns.innerHTML = `
          <button class="btn btn-primary btn-sm"
            onclick="updateOrder(${txn_id},'Selesai')">Selesai</button>
          <button class="btn btn-danger btn-sm"
            onclick="updateOrder(${txn_id},'Dibatalkan')">Batalkan</button>`;
      } else {
        btns.innerHTML = `<span style="font-size:11px;color:var(--ink4)">${status}</span>`;
      }
    }

    // Refresh top stats quietly
    refreshStats();
  }
}

async function refreshStats() {
  const data = await api('get_stats');
  if (!data.success) return;
  const s = data.stats;
  document.getElementById('sc-trust').textContent    = Math.round(s.trust_score);
  document.getElementById('sc-revenue').textContent  = 'RM ' + Math.round(s.revenue).toLocaleString('ms-MY');
  document.getElementById('sc-pending').textContent  = s.counts['Menunggu'] || 0;
  document.getElementById('topbar-trust').textContent= s.trust_score.toFixed(1) + '/100';
}

// â”€â”€ Orders table (pesanan tab) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
async function loadOrders(statusFilter) {
  const data = await api('get_orders', { status: statusFilter });
  if (!data.success) return;

  const tbody = document.getElementById('orders-tbody');
  if (!data.orders.length) {
    tbody.innerHTML = `<tr><td colspan="7">
      <div class="empty-state">
        <div style="font-size:32px;opacity:.3;margin-bottom:10px">ðŸ“‹</div>
        <div style="font-weight:500">Tiada pesanan</div>
      </div></td></tr>`;
    return;
  }

  tbody.innerHTML = data.orders.map(o => {
    const badgeClass = {
      Menunggu:'badge-gold', Disahkan:'badge-blue',
      Selesai:'badge-green', Dibatalkan:'badge-red',
    }[o.status] || 'badge-gray';

    let actions = '';
    if (o.status === 'Menunggu') {
      actions = `
        <button class="btn btn-success btn-sm"
          onclick="updateOrder(${o.transaction_id},'Disahkan')">Sahkan</button>
        <button class="btn btn-danger btn-sm"
          onclick="updateOrder(${o.transaction_id},'Dibatalkan')">Tolak</button>`;
    } else if (o.status === 'Disahkan') {
      actions = `
        <button class="btn btn-primary btn-sm"
          onclick="updateOrder(${o.transaction_id},'Selesai')">Selesai</button>
        <button class="btn btn-danger btn-sm"
          onclick="updateOrder(${o.transaction_id},'Dibatalkan')">Batalkan</button>`;
    } else if (o.status === 'Selesai' && o.rating) {
      actions = `<span style="color:var(--gold)">${'â˜…'.repeat(o.rating)}${'â˜†'.repeat(5-o.rating)}</span>`;
    } else {
      actions = `<span style="font-size:11px;color:var(--ink4)">${o.status}</span>`;
    }

    return `<tr id="order-row-${o.transaction_id}">
      <td style="font-size:11px;color:var(--ink3)">#${o.transaction_id}</td>
      <td>
        <div style="font-weight:500;max-width:150px;white-space:nowrap;
                    overflow:hidden;text-overflow:ellipsis">${o.product_name}</div>
        <div style="font-size:11px;color:var(--ink3)">${o.category}</div>
      </td>
      <td>
        <div>${o.buyer_name}</div>
        <div style="font-size:11px;color:var(--ink3)">${o.buyer_matric}</div>
      </td>
      <td>RM ${parseFloat(o.total_amount).toFixed(2)}</td>
      <td><span class="badge ${badgeClass}">
        <span class="badge-dot"></span>${o.status}
      </span></td>
      <td style="font-size:11px;color:var(--ink3)">
        ${new Date(o.created_at).toLocaleDateString('ms-MY',
          {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}
      </td>
      <td><div style="display:flex;gap:6px">${actions}</div></td>
    </tr>`;
  }).join('');
}

function filterOrders(el, status) {
  document.querySelectorAll('#order-filters .filter-btn')
    .forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  loadOrders(status === 'Semua' ? '' : status);
}

// â”€â”€ Products filter â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function filterProducts(el, status) {
  document.querySelectorAll('.filters .filter-btn')
    .forEach(b => b.classList.remove('active'));
  el.classList.add('active');

  document.querySelectorAll('#products-tbody tr[id]').forEach(row => {
    if (!status) { row.style.display = ''; return; }
    const badge = row.querySelector('.badge');
    row.style.display = (badge && badge.textContent.trim() === status) ? '' : 'none';
  });
}

// â”€â”€ Add product modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openAddModal() {
  document.getElementById('add-name').value    = '';
  document.getElementById('add-price').value   = '';
  document.getElementById('add-stock').value   = '1';
  document.getElementById('add-desc').value    = '';
  document.getElementById('add-image').value   = '';
  document.getElementById('add-errors').textContent = '';
  document.getElementById('add-preview').style.display = 'none';
  document.getElementById('modal-add').style.display = 'flex';
}

async function submitAdd() {
  const btn  = document.getElementById('add-submit-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Menyimpan...';
  document.getElementById('add-errors').textContent = '';

  const fd = new FormData();
  fd.append('name',        document.getElementById('add-name').value);
  fd.append('category',    document.getElementById('add-category').value);
  fd.append('description', document.getElementById('add-desc').value);
  fd.append('price',       document.getElementById('add-price').value);
  fd.append('stock',       document.getElementById('add-stock').value);
  const img = document.getElementById('add-image').files[0];
  if (img) fd.append('image', img);

  const data = await api('add_product', {}, fd, true);

  if (data.success) {
    showToast(data.message, 'success');
    closeModal('modal-add');
    setTimeout(() => location.reload(), 800);
  } else {
    const msg = data.errors ? data.errors.join(' Â· ') : data.message;
    document.getElementById('add-errors').textContent = msg;
    btn.disabled = false;
    btn.textContent = 'Simpan Produk';
  }
}

// â”€â”€ Edit product modal â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function openEditModal(product) {
  document.getElementById('edit-id').value       = product.product_id;
  document.getElementById('edit-name').value     = product.name;
  document.getElementById('edit-category').value = product.category;
  document.getElementById('edit-status').value   = product.status;
  document.getElementById('edit-price').value    = product.price;
  document.getElementById('edit-stock').value    = product.stock;
  document.getElementById('edit-desc').value     = product.description || '';
  document.getElementById('edit-image').value    = '';
  document.getElementById('edit-errors').textContent = '';

  // Show current image if any
  const preview = document.getElementById('edit-preview');
  if (product.image_path) {
    preview.src   = '/uploads/' + product.image_path;
    preview.style.display = 'block';
  } else {
    preview.style.display = 'none';
  }

  document.getElementById('modal-edit').style.display = 'flex';
}

async function submitEdit() {
  const btn = document.getElementById('edit-submit-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Menyimpan...';
  document.getElementById('edit-errors').textContent = '';

  const fd = new FormData();
  fd.append('product_id',  document.getElementById('edit-id').value);
  fd.append('name',        document.getElementById('edit-name').value);
  fd.append('category',    document.getElementById('edit-category').value);
  fd.append('description', document.getElementById('edit-desc').value);
  fd.append('price',       document.getElementById('edit-price').value);
  fd.append('stock',       document.getElementById('edit-stock').value);
  fd.append('status',      document.getElementById('edit-status').value);
  const img = document.getElementById('edit-image').files[0];
  if (img) fd.append('image', img);

  const data = await api('edit_product', {}, fd, true);

  if (data.success) {
    showToast(data.message, 'success');
    closeModal('modal-edit');
    setTimeout(() => location.reload(), 800);
  } else {
    const msg = data.errors ? data.errors.join(' Â· ') : data.message;
    document.getElementById('edit-errors').textContent = msg;
    btn.disabled = false;
    btn.textContent = 'Simpan Perubahan';
  }
}

// â”€â”€ Delete product â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
async function deleteProduct(product_id) {
  if (!confirm('Padamkan produk ini? Tindakan ini tidak boleh diulang.')) return;
  const data = await api('delete_product', {}, { product_id });
  showToast(data.message, data.success ? 'success' : 'error');
  if (data.success) {
    const row = document.getElementById('prod-row-' + product_id);
    if (row) row.remove();
  }
}

// â”€â”€ Image preview â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function previewImage(input, previewId) {
  const preview = document.getElementById(previewId);
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      preview.src = e.target.result;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

// â”€â”€ Modal helpers â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}

function openSellerTrustModal() {
  document.getElementById('seller-trust-modal').style.display = 'flex';
}

