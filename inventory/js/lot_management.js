document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('globalLotModal');
  if (!modal) return;

  const closeButtons = modal.querySelectorAll('.lot__menu-close');
  const lotForm = document.getElementById('lotManagerForm');
  const lotsContainer = document.getElementById('lots-list-container');
  const skuIdInput = document.getElementById('modal_sku_id');
  const skuNumInput = document.getElementById('modal_sku_num');
  const lotRequiredInput = document.getElementById('modal_lot_required');
  const locIdInput = document.getElementById('modal_loc_id');
  const title = document.getElementById('lotModalTitle');
  const expiryHint = document.getElementById('lot-expiry-hint');

  const base = (window.INVAPP_BASE || '').replace(/\/$/, '');
  const handler = `${base}/ajax_lot_handler.php`;

  const endpoints = {
    list: `${handler}?action=fetch`,
    create: `${handler}?action=create`,
    update: `${handler}?action=update`,
  };

  const isIsoDate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));

  const formatExpiryLabel = (isoDate) => {
    if (!isIsoDate(isoDate)) return '';
    const parts = String(isoDate).split('-');
    const year = Number(parts[0]);
    const month = Number(parts[1]) - 1;
    if (!Number.isFinite(year) || !Number.isFinite(month) || month < 0 || month > 11) return isoDate;
    const dt = new Date(year, month, 1);
    return dt.toLocaleString(undefined, { month: 'short', year: 'numeric' });
  };

  const formatExpiryList = (dates, maxItems = 5) => {
    const labels = (Array.isArray(dates) ? dates : [])
      .filter((d) => isIsoDate(d))
      .map((d) => formatExpiryLabel(d) || d)
      .filter((d) => d);
    if (!labels.length) return '';
    const unique = Array.from(new Set(labels));
    if (unique.length <= maxItems) return unique.join(', ');
    const shown = unique.slice(0, maxItems).join(', ');
    return `${shown} (+${unique.length - maxItems} more)`;
  };

  const formatExpiryLocationList = (entries, maxItems = 5) => {
    const formatted = (Array.isArray(entries) ? entries : [])
      .map((entry) => {
        const expiry = String(entry?.expiry_date || '');
        const location = String(entry?.location || '').trim();
        if (!isIsoDate(expiry) || !location) return '';
        const expiryLabel = formatExpiryLabel(expiry) || expiry;
        return `${expiryLabel} @ ${location}`;
      })
      .filter((v) => v);
    if (!formatted.length) return '';
    const unique = Array.from(new Set(formatted));
    if (unique.length <= maxItems) return unique.join('; ');
    const shown = unique.slice(0, maxItems).join('; ');
    return `${shown} (+${unique.length - maxItems} more)`;
  };

  const clearExpiryHint = () => {
    if (!expiryHint) return;
    expiryHint.textContent = '';
    expiryHint.hidden = true;
  };

  const renderLotRow = (lot) => {
    const lotMode = modal?.dataset?.lotMode || 'add';
    const modeLabel = lotMode === 'remove' ? 'Remove' : 'Add';
    const isViewMode = lotMode === 'view';
    const row = document.createElement('div');
    row.className = 'lot-row';
    row.dataset.lotId = lot.id ? String(lot.id) : '';

    const codeCell = document.createElement('span');
    codeCell.className = 'lot-select-trigger';
    codeCell.textContent = lot.lot_code || '';
    const expiryInput = document.createElement('input');
    expiryInput.type = 'date';
    expiryInput.className = 'input--small';
    expiryInput.value = lot.expiry_date || '';
    if (expiryInput.value) {
      expiryInput.disabled = true;
    }

    const onHandDisplay = document.createElement('span');
    onHandDisplay.className = 'on-hand-display';
    onHandDisplay.textContent = lot.on_hand != null ? String(lot.on_hand) : '0';

    const qtyInput = document.createElement('input');
    qtyInput.type = 'number';
    qtyInput.min = '-999999';
    qtyInput.step = '1';
    qtyInput.className = 'input--small lot-qty-input';
    qtyInput.value = '';
    qtyInput.placeholder = '0';
    if (lot.expiry_date) {
      const today = new Date();
      const yyyy = today.getFullYear();
      const mm = String(today.getMonth() + 1).padStart(2, '0');
      const dd = String(today.getDate()).padStart(2, '0');
      const todayStr = `${yyyy}-${mm}-${dd}`;
      if (lot.expiry_date < todayStr) {
        qtyInput.classList.add('is-expired');
      }
    }
    if (isViewMode) {
      qtyInput.disabled = true;
      qtyInput.value = '';
      qtyInput.placeholder = '--';
    }

    const actions = document.createElement('div');
    actions.style.display = 'flex';
    actions.style.gap = '8px';
    actions.style.justifyContent = 'flex-end';

    const saveButton = document.createElement('button');
    saveButton.type = 'button';
    saveButton.className = 'btn--save-lot';
    saveButton.textContent = modeLabel;
    if (isViewMode) {
      saveButton.style.display = 'none';
    }

    // const deleteButton = document.createElement('button');
    // deleteButton.type = 'button';
    // deleteButton.className = 'btn--status';
    // deleteButton.textContent = 'Delete';

    row.appendChild(codeCell);
    row.appendChild(expiryInput);
    row.appendChild(onHandDisplay);
    row.appendChild(qtyInput);
    actions.appendChild(saveButton);
    // actions.appendChild(deleteButton);
    row.appendChild(actions);

    return row;
  };

  const parseJson = async (response, label) => {
    const text = await response.text();
    try {
      return JSON.parse(text);
    } catch (err) {
      console.error(`${label} response was not JSON:`, text);
      throw err;
    }
  };

  const loadLots = async (skuId) => {
    if (!lotsContainer) return;
    clearExpiryHint();
    lotsContainer.innerHTML = '<div class="small muted">Loading lots...</div>';
    const params = new URLSearchParams();
    params.set('sku_id', skuId);
    const lotMode = modal?.dataset?.lotMode || '';
    const locId = modal?.dataset?.locId || '';
    if (lotMode) {
      params.set('lot_mode', lotMode);
    }
    if (lotMode === 'remove' && locId) {
      params.set('loc_id', locId);
    }
    const url = `${endpoints.list}&${params.toString()}`;

    try {
      const response = await fetch(url);
      if (!response.ok) throw new Error('Network error');
      const data = await parseJson(response, 'Lot list');

      if (!data || !data.ok) {
        lotsContainer.innerHTML = '<div class="small" style="color:#b91c1c;">Failed to load lots.</div>';
        return;
      }

      const items = Array.isArray(data.items) ? data.items : (Array.isArray(data) ? data : []);
      lotsContainer.innerHTML = '';

      if (items.length === 0) {
        lotsContainer.innerHTML = '<div class="small muted">No lots found.</div>';
        return;
      }

      const oldestExpiryAny = isIsoDate(data?.oldest_expiry_any || '') ? String(data.oldest_expiry_any) : '';
      const expiryDatesAny = Array.isArray(data?.expiry_dates_any)
        ? data.expiry_dates_any.map((d) => String(d || '')).filter((d) => isIsoDate(d))
        : [];
      const expiryLocationsAny = Array.isArray(data?.expiry_locations_any)
        ? data.expiry_locations_any
        : [];
      const currentLocId = Number(modal?.dataset?.locId || 0);
      let oldestExpiryLocal = '';
      items.forEach((lot) => {
        const expiry = String(lot?.expiry_date || '');
        const onHand = Number(lot?.on_hand ?? 0);
        if (!isIsoDate(expiry) || !(onHand > 0)) return;
        if (!oldestExpiryLocal || expiry < oldestExpiryLocal) {
          oldestExpiryLocal = expiry;
        }
      });

      if (lotMode === 'remove' && oldestExpiryAny) {
        const hasOlderElsewhere = !oldestExpiryLocal || oldestExpiryAny < oldestExpiryLocal;
        if (expiryHint && hasOlderElsewhere) {
          const oldestLabel = formatExpiryLabel(oldestExpiryAny) || oldestExpiryAny;
          const olderDates = expiryDatesAny.filter((d) => !oldestExpiryLocal || d < oldestExpiryLocal);
          const olderList = formatExpiryList(olderDates.length ? olderDates : [oldestExpiryAny]);
          const olderLocEntries = expiryLocationsAny.filter((entry) => {
            const expiry = String(entry?.expiry_date || '');
            const locId = Number(entry?.loc_id || 0);
            if (!isIsoDate(expiry)) return false;
            if (!!oldestExpiryLocal && expiry >= oldestExpiryLocal) return false;
            if (currentLocId > 0 && locId === currentLocId) return false;
            return true;
          });
          const olderLocList = formatExpiryLocationList(olderLocEntries);

          let msg = `FIFO reminder: older expiry exists outside this location (${oldestLabel}).`;
          if (olderList) msg += ` Older expiry dates: ${olderList}.`;
          if (olderLocList) msg += ` Locations: ${olderLocList}.`;
          expiryHint.textContent = msg;
          expiryHint.hidden = false;
        }
      }

      items.forEach((lot) => {
        lotsContainer.appendChild(renderLotRow(lot));
      });
    } catch (_) {
      clearExpiryHint();
      lotsContainer.innerHTML = '<div class="small" style="color:#b91c1c;">Failed to load lots.</div>';
    }
  };

  const openModal = (skuId, skuNum, lotRequired, locId, lotSelect, lotMode) => {
    if (lotForm) lotForm.reset();
    if (skuIdInput) skuIdInput.value = skuId;
    if (skuNumInput) skuNumInput.value = skuNum;
    if (lotRequiredInput) lotRequiredInput.value = lotRequired ? '1' : '0';
    if (locIdInput) locIdInput.value = locId ? String(locId) : '';
    if (title) title.textContent = `Manage Lots: ${skuNum}`;
    modal.dataset.skuId = skuId ? String(skuId) : '';
    modal.dataset.locId = locId ? String(locId) : '';
    modal.dataset.lotSelect = lotSelect ? '1' : '';
    modal.dataset.lotMode = lotMode || 'add';
    const modeLabel = modal.dataset.lotMode === 'remove' ? 'Remove' : 'Add';
    const saveButtons = modal.querySelectorAll('.btn--save-lot');
    saveButtons.forEach((btn) => {
      btn.textContent = modeLabel;
    });
    const isViewMode = modal.dataset.lotMode === 'view';
    const lotContent = modal.querySelector('.lot__content');
    if (lotContent) {
      lotContent.style.display = isViewMode ? 'none' : '';
    }
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    loadLots(skuId);
  };

  const closeModal = () => {
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    clearExpiryHint();
    if (lotsContainer) lotsContainer.innerHTML = '';
    if (lotForm) lotForm.reset();
  };

  document.addEventListener('click', (e) => {
    const target = e.target;
    if (!(target instanceof HTMLElement)) return;
    if (!target.classList.contains('btn-lot')) return;

    const skuId = target.getAttribute('data-sku-id') || '';
    const skuNum = target.getAttribute('data-sku-num') || '';
    const lotRequired = target.getAttribute('data-lot-required') === '1';
    const locId = target.getAttribute('data-loc-id') || '';
    const lotSelect = target.getAttribute('data-lot-select') === '1';
    const lotMode = target.getAttribute('data-lot-mode') || 'add';
    if (!skuId || !skuNum) return;
    openModal(skuId, skuNum, lotRequired, locId, lotSelect, lotMode);
  });

  closeButtons.forEach((btn) => {
    btn.addEventListener('click', closeModal);
  });

  if (lotForm) {
    lotForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      let skuId = skuIdInput ? skuIdInput.value : '';
      if (!skuId && modal.dataset.skuId) {
        skuId = modal.dataset.skuId;
      }
      let locId = locIdInput ? locIdInput.value : '';
      if (!locId && modal.dataset.locId) {
        locId = modal.dataset.locId;
      }
      const lotRequired = lotRequiredInput ? lotRequiredInput.value === '1' : false;
      const lotCode = (lotForm.querySelector('[name="lot_code"]')?.value || '').trim();
      const lotCodeInput = lotForm.querySelector('[name="lot_code"]');
      if (lotCodeInput && lotCodeInput.value) {
        lotCodeInput.value = lotCodeInput.value.toUpperCase();
      }
      const expiryDate = (lotForm.querySelector('[name="expiry_date"]')?.value || '').trim();
      const quantity = (lotForm.querySelector('[name="quantity"]')?.value || '').trim();

      if (!skuId) {
        alert('Missing SKU. Close and reopen the lot modal.');
        return;
      }
      if (!lotCode || (lotRequired && !expiryDate)) {
        alert('Lot code and expiry date are required for this SKU.');
        return;
      }
      if (quantity === '' || Number.isNaN(Number(quantity)) || Number(quantity) < 0) {
        alert('Quantity is required and must be 0 or greater.');
        return;
      }

      const formData = new FormData();
      formData.append('sku_id', skuId);
      formData.append('lot_code', lotCode);
      formData.append('expiry_date', expiryDate);
      formData.append('quantity', quantity);
      if (locId) {
        formData.append('loc_id', locId);
      }

      try {
        const response = await fetch(endpoints.create, {
          method: 'POST',
          body: formData,
        });
        const data = await parseJson(response, 'Lot create');
        if (!data || !data.ok) {
          alert(data && data.message ? data.message : 'Lot create failed.');
          return;
        }
        lotForm.reset();
        loadLots(skuId);
        window.location.reload();
      } catch (_) {
        alert('Lot create failed.');
      }
    });
  }

  if (lotsContainer) {
    lotsContainer.addEventListener('click', async (e) => {
      const target = e.target;
      if (!(target instanceof HTMLElement)) return;
      const row = target.closest('.lot-row');
      if (!row) return;
      if (modal.dataset.lotSelect === '1') {
        if (target.classList.contains('btn--save-lot') || target.classList.contains('btn--status')) {
          // fall through to existing handlers
        } else if (target.closest('.lot-select-trigger')) {
          const lotId = row.dataset.lotId || '';
          if (lotId) {
            const lotInput = document.getElementById('take_lot_id');
            if (lotInput) lotInput.value = lotId;
          }
          closeModal();
          return;
        } else {
          return;
        }
      }

      if (target.classList.contains('btn--status')) {
        row.remove();
        return;
      }

      if (target.classList.contains('btn--save-lot')) {
        const lotId = row.dataset.lotId || '';
        if (!lotId) {
          alert('This lot has not been saved yet.');
          return;
        }

        const expiryInput = row.querySelector('input[type="date"]');
        const expiryDate = expiryInput ? expiryInput.value : '';
        const onHandDisplay = row.querySelector('.on-hand-display');
        const qtyInput = row.querySelector('.lot-qty-input');
        const quantity = qtyInput ? qtyInput.value : '';
        let locId = locIdInput ? locIdInput.value : '';
        if (!locId && modal.dataset.locId) {
          locId = modal.dataset.locId;
        }

        if (quantity === '' || Number.isNaN(Number(quantity))) {
          alert('Adjustment quantity is required and must be a number.');
          return;
        }
        const mode = modal.dataset.lotMode || 'add';
        let adjustment = Number(quantity);
        if (mode === 'remove') {
          adjustment = -Math.abs(adjustment);
        } else if (adjustment < 0) {
          alert('Add quantity must be 0 or greater.');
          return;
        }

        const formData = new FormData();
        formData.append('lot_id', lotId);
        formData.append('expiry_date', expiryDate);
        formData.append('adjustment_qty', String(adjustment));
        if (locId) {
          formData.append('loc_id', locId);
        }

        try {
          const response = await fetch(endpoints.update, {
            method: 'POST',
            body: formData,
          });
          const data = await parseJson(response, 'Lot update');

          if (data && data.ok) {
            if (onHandDisplay) {
              const next = data.new_total != null ? data.new_total : data.quantity;
              if (next != null) onHandDisplay.textContent = String(next);
            }
            if (qtyInput) qtyInput.value = '';
            alert(data.message || 'Lot updated successfully.');
            window.location.reload();
          } else {
            alert(data.message || 'Update failed.');
          }
        } catch (err) {
          alert('Error connecting to server.');
        }
      }
    });
  }

  window.LotManager = {
    init: () => { },
    openModal,
    fetchLots: loadLots,
  };
});
