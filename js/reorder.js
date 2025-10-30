(function () {
  const table = document.getElementById('inventoryTable');
  const countBadge = document.getElementById('reorderCountBadge');

  const dlg = document.getElementById('reorderWindow');
  const openBtn = document.getElementById('openReorderBtn');
  const closeBtn = document.getElementById('closeReorderBtn');

  const reorderTableWrap = document.getElementById('reorderTableWrap');
  const reorderTbody = document.querySelector('#reorderTable tbody');
  const reorderEmpty = document.getElementById('reorderEmpty');

  const exportForm = document.getElementById('reorderExportForm');
  const exportBtn = document.getElementById('exportCsvBtn');
  const reorderJsonField = document.getElementById('reorderJsonField');

  // In-memory reorder map: { stock_id: {stock_id, item_name, item_type, qty_on_hand, min_stock, qty_to_order} }
  const reorderMap = Object.create(null);

  function updateBadge() {
    const n = Object.keys(reorderMap).length;
    countBadge.textContent = String(n);
    exportBtn.disabled = n === 0;
  }

  function addOrUpdateFromRow(tr, checked) {
    const id = tr.getAttribute('data-stock-id');
    if (!id) return;

    if (!checked) {
      delete reorderMap[id];
      return;
    }

    const name = tr.getAttribute('data-item-name') || '';
    const type = tr.getAttribute('data-item-type') || '';
    const onHand = parseInt(tr.getAttribute('data-current') || '0', 10);
    const minStock = parseInt(tr.getAttribute('data-min') || '0', 10);
    const def = parseInt(tr.getAttribute('data-default-order') || '1', 10); 

    reorderMap[id] = {
      stock_id: id,
      item_name: name,
      item_type: type,
      qty_on_hand: onHand,
      min_stock: minStock,
      qty_to_order: Math.max(1, def) 
    };
  }

  function renderReorderTable() {
    const ids = Object.keys(reorderMap);
    reorderTbody.innerHTML = '';

    if (ids.length === 0) {
      reorderEmpty.style.display = '';
      reorderTableWrap.style.display = 'none';
      return;
    }

    reorderEmpty.style.display = 'none';
    reorderTableWrap.style.display = '';

    ids.forEach(id => {
      const it = reorderMap[id];
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${it.stock_id}</td>
        <td>${escapeHtml(it.item_name)}</td>
        <td>${escapeHtml(it.item_type)}</td>
        <td>${it.qty_on_hand}</td>
        <td>${it.min_stock}</td>
        <td>
          <input type="number" class="qty-input" min="1" step="1" value="${it.qty_to_order}" style="width:100px;" data-id="${it.stock_id}">
        </td>
        <td>
          <button type="button" class="btn btn-sm btn-danger remove-reorder" data-id="${it.stock_id}">Remove</button>
        </td>
      `;
      reorderTbody.appendChild(tr);
    });
  }

  function escapeHtml(s) {
    const div = document.createElement('div'); div.textContent = s; return div.innerHTML;
  }

  function openModal() {
    renderReorderTable();
    if (typeof dlg.showModal === 'function') dlg.showModal();
    else {
      alert('Your browser does not support <dialog>. Please use a modern browser.');
    }
  }

  function syncCheckboxesFromMap() {
    const checks = table.querySelectorAll('input.reorder-select');
    checks.forEach(cb => {
      const tr = cb.closest('tr');
      const id = tr && tr.getAttribute('data-stock-id');
      cb.checked = !!(id && reorderMap[id]);
    });
  }

  // Event: selecting rows
  if (table) {
    table.addEventListener('change', (e) => {
      const cb = e.target;
      if (!(cb && cb.classList.contains('reorder-select'))) return;
      const tr = cb.closest('tr');
      addOrUpdateFromRow(tr, cb.checked);
      updateBadge();
    });
  }

  // Event: open modal
  if (openBtn) openBtn.addEventListener('click', () => {
    openModal();
  });

  // Event: close modal
  if (closeBtn) closeBtn.addEventListener('click', () => {
    dlg.close();
  });

  // Event: qty input + remove inside modal
  reorderTbody.addEventListener('input', (e) => {
    const inp = e.target;
    if (!(inp && inp.classList.contains('qty-input'))) return;
    const id = inp.getAttribute('data-id');
    const v = Math.max(1, parseInt(inp.value || '1', 10));
    if (reorderMap[id]) reorderMap[id].qty_to_order = v;
    inp.value = v; 
  });

  reorderTbody.addEventListener('click', (e) => {
    const btn = e.target;
    if (!(btn && btn.classList.contains('remove-reorder'))) return;
    const id = btn.getAttribute('data-id');
    if (reorderMap[id]) {
      delete reorderMap[id];
      renderReorderTable();
      updateBadge();
      syncCheckboxesFromMap();
    }
  });

  // Export: serialize to JSON and submit to session preview endpoint
  if (exportForm) {
    exportForm.addEventListener('submit', (e) => {
      if (Object.keys(reorderMap).length === 0) {
        e.preventDefault();
        return;
      }
      const payload = Object.keys(reorderMap).map(id => reorderMap[id]);
      reorderJsonField.value = JSON.stringify(payload);
    });
  }

})();