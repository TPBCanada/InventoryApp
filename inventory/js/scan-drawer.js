// /dev/js/scan-drawer.js
(() => {
  // --- Configuration ---
  const ROOT_BASE = (window.INVAPP_BASE || '/dev').replace(/\/+$/, '');
  const APP_BASE = ROOT_BASE.endsWith('/inventory') ? ROOT_BASE : `${ROOT_BASE}/inventory`;

  const SPECIAL_ROWS = Array.isArray(window.SPECIAL_ROWS)
    ? window.SPECIAL_ROWS.map(r => String(r || '').toUpperCase()).filter(Boolean)
    : ['FLOOR', 'RECEIVING', 'TUNNEL'];
  const LOCATION_ROW_PREFIXES = ['R10', 'R11']; // Only rows that should trigger location logic
  const SPECIAL_DEFAULT_BAY = '01';
  const SPECIAL_DEFAULT_LEVEL = '11';
  const SPECIAL_DEFAULT_SIDE = 'F';

  // API endpoints under the inventory base (passthroughs exist in /inventory/)
  const API_URL = `${APP_BASE}/scan_api.php`;
  const LOC_API_URL = `${APP_BASE}/loc_api.php`;
  const LOC_FUZZY_URL = `${APP_BASE}/loc_search_fuzzy.php`;
  const SKU_FUZZY_URL = `${APP_BASE}/sku_search_ai.php`;

  // --- Element References ---
  const drawer = document.getElementById('scanDrawer');
  const panel = drawer?.querySelector('.scan-drawer__panel');
  const backdrop = document.getElementById('scanBackdrop');
  const launch = document.getElementById('scanLaunchBtn');
  const closeBtn = document.getElementById('scanCloseBtn');
  const camToggleBtn = document.getElementById('scanCamToggleBtn');
  const resetBtn = document.getElementById('scanResetBtn');
  const searchList = document.getElementById('scanSearchList');

  let video = document.getElementById('scanVideo');
  const camSection = drawer?.querySelector('.scan-drawer__cam');
  const scanBox = document.querySelector('.scan-box');
  const startBtn = document.getElementById('scanStartBtn');
  const stopBtn = document.getElementById('scanStopBtn');
  const manual = document.getElementById('scanManual');
  const lookupBtn = document.getElementById('scanLookupBtn');
  const bracket = document.getElementById('scanBracket');

  const resultsWrap = document.getElementById('scanResults');
  const skuGrid = document.getElementById('scanSkuGrid');
  const summary = document.getElementById('scanSummary');
  const locBody = document.getElementById('scanLocBody');
  const productImage = document.getElementById('scanProductImage');
  const palletHeading = document.getElementById('scanPalletHeading');
  const copyBtn = document.getElementById('scanCopyBtn');
  const addUpcBtn = document.querySelector('.addUpcBtn');

  // --- State ---
  let stream = null, detector = null, scanning = false, raf = null;
  let lastValue = '', cooldown = 0;
  let aiTimer = null; // used only for fuzzy LOCATION (no AI SKU)
  let searchRequestNonce = 0;
  let currentSkuNum = '';
  let lastSkuData = null;
  let lastLocations = [];
  let lastLocationsTotal = 0;
  window.InvApp = window.InvApp || {};
  window.InvApp.scanState = window.InvApp.scanState || {};

  // --- Helpers ---
  const esc = (s) =>
    String(s ?? '').replace(/[&<>"']/g, m => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));

  const row = (label, value) => `<div><strong>${label}</strong>${value}</div>`;

  // --- Barcode Modal Controller ---
  const barcodeModal = document.getElementById('barcode-modal');
  const barcodeRows = barcodeModal ? barcodeModal.querySelector('.bc-rows') : null;
  const barcodeSkuLabel = barcodeModal ? barcodeModal.querySelector('.bc-sku') : null;
  const barcodeAddForm = barcodeModal ? barcodeModal.querySelector('#bc-add-form') : null;
  const barcodeCsrfToken = barcodeAddForm ? (barcodeAddForm.querySelector('input[name="csrf_token"]')?.value || '') : '';
  const barcodeApiUrl = `${APP_BASE}/manage_sku.php`;
  let barcodeSkuId = '';
  let barcodeControllerReady = false;

  async function postBarcode(action, payload = {}) {
    const body = new URLSearchParams({
      barcode_action: action,
      sku_id: barcodeSkuId || '',
      csrf_token: barcodeCsrfToken,
    });
    Object.entries(payload).forEach(([k, v]) => body.append(k, v));
    const res = await fetch(barcodeApiUrl, {
      method: 'POST',
      body,
      credentials: 'same-origin',
    });
    const json = await res.json();
    return json;
  }

  function renderBarcodeRows(barcodes) {
    if (!barcodeRows) return;
    barcodeRows.innerHTML = '';
    if (!barcodes || !barcodes.length) {
      barcodeRows.innerHTML = '<div style="padding: 20px; text-align: center; color: #999;">No barcodes yet.</div>';
      return;
    }

    barcodes.forEach((barcode) => {
      const wrapper = document.createElement('div');
      wrapper.className = 'bc-row-wrapper';
      wrapper.dataset.id = String(barcode.id || '');
      const isEnabled = (barcode.enabled ?? 1) === 1;
      if (!isEnabled) {
        wrapper.style.cssText = 'opacity: 0.6; background-color: #f5f5f5;';
      }

      const line1 = document.createElement('div');
      line1.className = 'bc-row-line1 bc-row';

      const upcElement = document.createElement('input');
      upcElement.className = 'input';
      upcElement.name = 'upc';
      upcElement.placeholder = 'UPC/Barcode';
      upcElement.value = barcode.upc ?? '';
      if (!isEnabled) upcElement.disabled = true;

      const uomSelect = document.createElement('select');
      uomSelect.className = 'select';
      uomSelect.name = 'uom_label';
      if (!isEnabled) uomSelect.disabled = true;
      ['UNIT', 'CARTON', 'MASTERCASE'].forEach((uom) => {
        const option = document.createElement('option');
        option.value = uom;
        option.textContent = uom;
        if ((barcode.uom_label ?? 'UNIT') === uom) {
          option.selected = true;
        }
        uomSelect.appendChild(option);
      });

      const multiplierInput = document.createElement('input');
      multiplierInput.className = 'input';
      multiplierInput.name = 'multiplier';
      multiplierInput.type = 'number';
      multiplierInput.min = '1';
      multiplierInput.placeholder = 'Qty';
      multiplierInput.value = String(barcode.multiplier ?? 1);
      if (!isEnabled) multiplierInput.disabled = true;

      const sourceSelect = document.createElement('select');
      sourceSelect.className = 'select';
      sourceSelect.name = 'source';
      if (!isEnabled) sourceSelect.disabled = true;
      ['NOT SET', 'SUPPLIER', 'OWN_LABEL', 'REPACK'].forEach((opt) => {
        const option = document.createElement('option');
        option.value = opt;
        option.textContent = opt || 'select source';
        if ((barcode.source ?? '') === opt) {
          option.selected = true;
        }
        sourceSelect.appendChild(option);
      });

      const spacer = document.createElement('div');

      const actions = document.createElement('div');
      actions.className = 'bc-actions';
      const toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'btn btn--ghost bc-toggle-enabled';
      toggleBtn.textContent = isEnabled ? 'I' : 'O';
      toggleBtn.title = isEnabled ? 'Disable' : 'Enable';
      toggleBtn.style.cssText = 'padding: 6px 12px; font-weight: bold; font-size: 14px;';
      const saveBtn = document.createElement('button');
      saveBtn.type = 'button';
      saveBtn.className = 'btn btn--ghost bc-save';
      saveBtn.textContent = '✓';
      saveBtn.style.cssText = 'background: #0f766e; color: white; padding: 6px 12px;';
      const deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.className = 'btn btn--ghost bc-delete';
      deleteBtn.textContent = 'X';
      deleteBtn.style.cssText = 'color: #dc2626; padding: 6px 12px;';
      actions.append(toggleBtn, saveBtn); 
      // actions.append(toggleBtn, saveBtn, deleteBtn);

      line1.append(upcElement, uomSelect, multiplierInput, sourceSelect, spacer, actions);

      const line2 = document.createElement('div');
      line2.className = 'bc-row-line2 bc-row';

      const widthInput = document.createElement('input');
      widthInput.className = 'input dim-input';
      widthInput.name = 'width';
      widthInput.type = 'number';
      widthInput.step = '0.01';
      widthInput.min = '0';
      widthInput.placeholder = 'W (cm)';
      widthInput.value = barcode.width ? String(barcode.width) : '';
      if (!isEnabled) widthInput.disabled = true;

      const heightInput = document.createElement('input');
      heightInput.className = 'input dim-input';
      heightInput.name = 'height';
      heightInput.type = 'number';
      heightInput.step = '0.01';
      heightInput.min = '0';
      heightInput.placeholder = 'H (cm)';
      heightInput.value = barcode.height ? String(barcode.height) : '';
      if (!isEnabled) heightInput.disabled = true;

      const lengthInput = document.createElement('input');
      lengthInput.className = 'input dim-input';
      lengthInput.name = 'length';
      lengthInput.type = 'number';
      lengthInput.step = '0.01';
      lengthInput.min = '0';
      lengthInput.placeholder = 'L (cm)';
      lengthInput.value = barcode.length ? String(barcode.length) : '';
      if (!isEnabled) lengthInput.disabled = true;

      const weightInput = document.createElement('input');
      weightInput.className = 'input dim-input';
      weightInput.name = 'weight';
      weightInput.type = 'number';
      weightInput.step = '0.01';
      weightInput.min = '0';
      weightInput.placeholder = 'kg';
      weightInput.value = barcode.weight_lbs ? String(barcode.weight_lbs) : '';
      if (!isEnabled) weightInput.disabled = true;

      const noBarcodeLabel = document.createElement('label');
      noBarcodeLabel.style.cssText = 'display: flex; align-items: center; gap: 6px; font-size: 13px; margin: 0; cursor: pointer;';
      const noBarcodeCheckbox = document.createElement('input');
      noBarcodeCheckbox.type = 'checkbox';
      noBarcodeCheckbox.className = 'no-barcode-checkbox-row';
      noBarcodeCheckbox.name = 'no_barcode_row';
      noBarcodeCheckbox.checked = !!barcode.no_barcode;
      if (!isEnabled) noBarcodeCheckbox.disabled = true;
      noBarcodeLabel.append(noBarcodeCheckbox);
      noBarcodeLabel.append(document.createTextNode('No Barcode'));

      const spacer2 = document.createElement('div');
      const emptyActions = document.createElement('div');

      line2.append(widthInput, heightInput, lengthInput, weightInput, noBarcodeLabel, spacer2, emptyActions);

      wrapper.append(line1, line2);
      barcodeRows.appendChild(wrapper);
    });
  }

  async function loadBarcodeRows() {
    const data = await postBarcode('list');
    if (data && data.ok) {
      renderBarcodeRows(data.rows || []);
      updateUomDropdownOptions(data.rows || []);
    } else {
      console.warn('[barcode-modal] loadRows failed', data);
      if (barcodeRows) {
        barcodeRows.innerHTML = '<div class="message message--bad">Failed to load barcodes.</div>';
      }
    }
  }

  function updateUomDropdownOptions(barcodes) {
    // Only disable UOMs that have actual barcodes (not NO BARCODE entries)
    const usedUoms = new Set(barcodes.filter(b => b.upc).map(b => b.uom_label));
    const uomSelect = barcodeAddForm?.querySelector('select[name="uom_label"]');
    if (!uomSelect) return;

    uomSelect.querySelectorAll('option').forEach(option => {
      option.disabled = usedUoms.has(option.value);
    });
  }

  function openBarcodeModal(nextSkuId, nextSkuNum) {
    if (!barcodeModal || !barcodeRows || !barcodeAddForm) {
      console.warn('[barcode-modal] open requested but modal DOM is missing');
      return;
    }

    barcodeSkuId = String(nextSkuId || '');
    if (barcodeSkuLabel) {
      barcodeSkuLabel.textContent = nextSkuNum || '';
    }
    barcodeModal.hidden = false;
    void loadBarcodeRows();
  }

  function closeBarcodeModal() {
    if (!barcodeModal || !barcodeRows) return;
    barcodeModal.hidden = true;
    barcodeSkuId = '';
    barcodeRows.innerHTML = '';
  }

  async function resolveBarcodeContext(fallbackCode = '') {
    const stateSku = window.InvApp.scanState || {};
    const directSource = stateSku.lastLookupSku || stateSku.lastLookupData?.sku || stateSku.lastSkuData || lastSkuData || null;
    const directSkuId = String(directSource?.sku_id || directSource?.id || '').trim();
    const directSkuNum = String(directSource?.sku_num || stateSku.currentSkuNum || currentSkuNum || '').trim();

    if (directSkuId || directSkuNum) {
      return { skuId: directSkuId, skuNum: directSkuNum };
    }

    const code = String(fallbackCode || manual?.value || '').trim();
    if (!code) {
      return { skuId: '', skuNum: '' };
    }

    try {
      const url = new URL(API_URL, window.location.origin);
      url.searchParams.set('code', code);
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      if (data && data.ok && data.sku) {
        return {
          skuId: String(data.sku.sku_id || data.sku.id || ''),
          skuNum: String(data.sku.sku_num || code),
        };
      }
    } catch (e) {
      console.warn('[barcode-modal] resolve context lookup error', e);
    }

    return { skuId: '', skuNum: code };
  }

  function initBarcodeController() {
    if (barcodeControllerReady) return;
    barcodeControllerReady = true;

    window.InvApp = window.InvApp || {};
    window.InvApp.openBarcodeModal = openBarcodeModal;
    window.InvApp.closeBarcodeModal = closeBarcodeModal;

    if (!barcodeModal || !barcodeRows || !barcodeAddForm) {
      console.warn('[barcode-modal] controller not initialized because required DOM nodes are missing');
      return;
    }

    document.body.addEventListener('click', (event) => {
      const openBtn = event.target.closest('.btn-barcode, .addUpcBtn');
      if (openBtn) {
        event.preventDefault();
        void (async () => {
          const stateSku = window.InvApp.scanState || {};
          const nextSkuId = openBtn.dataset.skuId
            || String(stateSku.lastLookupSku?.sku_id || stateSku.lastLookupSku?.id || stateSku.lastSkuData?.sku_id || stateSku.lastSkuData?.id || lastSkuData?.sku_id || lastSkuData?.id || '');
          const nextSkuNum = openBtn.dataset.skuNum
            || String(stateSku.lastLookupSku?.sku_num || stateSku.currentSkuNum || stateSku.lastSkuData?.sku_num || currentSkuNum || lastSkuData?.sku_num || '');
          let resolved = { skuId: nextSkuId, skuNum: nextSkuNum };
          if (!resolved.skuId && !resolved.skuNum) {
            resolved = await resolveBarcodeContext(nextSkuNum);
          }
          if (!resolved.skuId && !resolved.skuNum) {
            console.warn('[barcode-modal] click ignored because skuId and skuNum are both empty');
            return;
          }
          openBarcodeModal(resolved.skuId, resolved.skuNum);
        })();
        return;
      }

      if (event.target.closest('[data-bc-close]')) {
        event.preventDefault();
        closeBarcodeModal();
        return;
      }

      if (event.target.classList.contains('bc-delete')) {
        const wrapper = event.target.closest('.bc-row-wrapper');
        const id = wrapper ? wrapper.dataset.id : '';
        if (id) {
          void postBarcode('delete', { id }).then(loadBarcodeRows);
        }
        return;
      }

      if (event.target.classList.contains('bc-toggle-enabled')) {
        const wrapper = event.target.closest('.bc-row-wrapper');
        const id = wrapper ? wrapper.dataset.id : '';
        if (!id) {
          return;
        }
        if (!confirm('Toggle enable/disable?')) {
          return;
        }
        void postBarcode('toggle_enabled', { id }).then(loadBarcodeRows);
        return;
      }

      if (event.target.classList.contains('bc-save')) {
        const wrapper = event.target.closest('.bc-row-wrapper');
        if (!wrapper) return;
        const id = wrapper.dataset.id || '';
        const upcInput = wrapper.querySelector('input[name="upc"]');
        const upc = upcInput ? upcInput.value.trim() : '';
        const uom = wrapper.querySelector('select[name="uom_label"]')?.value || 'UNIT';
        const multiplier = wrapper.querySelector('input[name="multiplier"]')?.value || '1';
        const sourceVal = wrapper.querySelector('select[name="source"]')?.value || '';
        const source = sourceVal === 'NOT SET' ? '' : sourceVal;
        const length = wrapper.querySelector('input[name="length"]')?.value || '';
        const width = wrapper.querySelector('input[name="width"]')?.value || '';
        const height = wrapper.querySelector('input[name="height"]')?.value || '';
        const weight = wrapper.querySelector('input[name="weight"]')?.value || '';
        const noBarcode = wrapper.querySelector('.no-barcode-checkbox-row')?.checked ? 1 : 0;

        // Validation: UPC must be provided OR "No Barcode" must be checked
        if (!upc && !noBarcode) {
          alert(`${uom} entry requires either a UPC or "No Barcode" checkbox to be checked.`);
          return;
        }

        const payload = { upc, uom_label: uom, multiplier, source, length, width, height, weight, no_barcode: noBarcode };
        const action = id ? 'update' : 'create';
        if (id) payload.id = id;
        void postBarcode(action, payload).then((res) => {
          if (!res || !res.ok) {
            alert(`Save failed: ${res?.msg || 'unknown error'}`);
            return;
          }
          return loadBarcodeRows();
        });
      }
    });

    const noBarcodeCheckbox = barcodeAddForm?.querySelector('.bc-no-barcode-checkbox');
    const upcInput = barcodeAddForm?.querySelector('.bc-upc-input');

    if (noBarcodeCheckbox && upcInput) {
      noBarcodeCheckbox.addEventListener('change', () => {
        if (noBarcodeCheckbox.checked) {
          upcInput.removeAttribute('required');
          upcInput.value = '';
          upcInput.style.opacity = '0.5';
        } else {
          upcInput.setAttribute('required', 'required');
          upcInput.style.opacity = '1';
        }
      });
    }

    barcodeAddForm.addEventListener('submit', (event) => {
      event.preventDefault();
      const fd = new FormData(barcodeAddForm);
      const upc = String(fd.get('upc') || '').trim();
      const selectedUom = fd.get('uom_label') || 'UNIT';
      const noBarcode = barcodeAddForm.querySelector('.bc-no-barcode-checkbox')?.checked ? 1 : 0;

      if (!upc && !noBarcode) {
        alert(`${selectedUom} entry requires either a UPC or "No Barcode" checkbox to be checked.`);
        return;
      }

      // Check if the selected UOM already has a barcode (not just NO BARCODE)
      const existingBarcode = Array.from(barcodeRows.querySelectorAll('.bc-row-wrapper')).find(wrapper => {
        const uom = wrapper.querySelector('select[name="uom_label"]')?.value;
        const upcField = wrapper.querySelector('input[name="upc"]');
        return uom === selectedUom && upcField && upcField.value.trim();
      });

      if (existingBarcode) {
        alert(`A ${selectedUom} barcode already exists for this SKU. Please edit the existing entry or select a different UOM.`);
        return;
      }

      void postBarcode('create', {
        upc,
        uom_label: selectedUom,
        multiplier: fd.get('multiplier') || '1',
        source: fd.get('source') || '',
        length: fd.get('length') || '',
        width: fd.get('width') || '',
        height: fd.get('height') || '',
        weight: fd.get('weight') || '',
        no_barcode: noBarcode,
      }).then((res) => {
        if (!res || !res.ok) {
          alert(`Save failed: ${res?.msg || 'unknown error'}`);
          return;
        }
        barcodeAddForm.reset();
        const multiplierInput = barcodeAddForm.querySelector('input[name="multiplier"]');
        if (multiplierInput) {
          multiplierInput.value = '1';
        }
        void loadBarcodeRows();
      });
    });
  }

  initBarcodeController();

  function updateCopyButtonState() {
    if (!copyBtn) return;
    const hasSku = !!(lastSkuData && lastSkuData.sku_num);
    copyBtn.disabled = !hasSku;
  }

  function buildResultsText() {
    if (lastSkuData && lastSkuData.sku_num) {
      return String(lastSkuData.sku_num).trim();
    }
    return '';
  }

  async function copyResultsToClipboard() {
    const text = buildResultsText();
    if (!text) {
      toast('No results to copy.');
      return;
    }

    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
      } else {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'absolute';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
      }
      toast('Results copied to clipboard.');
    } catch (e) {
      console.error('Copy failed:', e);
      toast('Copy failed.');
    }
  }

  // Looks like a clean SKU / code (no spaces, 3+ chars)
  const looksLikeSku = (s) => {
    const v = String(s).trim();
    if (!v || /\s/.test(v)) return false;
    return /^[A-Za-z0-9\-]{3,}$/.test(v);
  };
  // Consider a SKU complete only when it is reasonably long (e.g., 7+ chars)
  const isCompleteSku = (s) => {
    const v = String(s).trim();
    if (!v || /\s/.test(v)) return false;
    return /^[A-Za-z0-9\-]{7,}$/.test(v);
  };

  // Restrict which row codes can be treated as valid locations
  const isAllowedLocationRow = (rowCodeUpper) => {
    const row = String(rowCodeUpper || '').toUpperCase();
    return LOCATION_ROW_PREFIXES.includes(row) || SPECIAL_ROWS.includes(row);
  };

  // Allow partial prefixes only for R10/R11 and the special rows
  const isAllowedLocationPrefix = (valueUpper) => {
    const val = String(valueUpper || '').toUpperCase();
    if (LOCATION_ROW_PREFIXES.some(prefix => val.startsWith(prefix))) return true;
    return SPECIAL_ROWS.some(row => row.startsWith(val) || val.startsWith(row));
  };

  // Four-part location code: TEXT/NUM - NUM - NUM - TEXT (e.g. FLOOR-01-11-F).
  // Also accept compact 7/8-char codes for R10/R11 rows and normalize them to SS-SS-SS- (side optional).
  const normalizeLocationInput = (s) => {
    const code = String(s).trim().toUpperCase();
    if (!code) return '';

    if (SPECIAL_ROWS.includes(code)) {
      return `${code}-${SPECIAL_DEFAULT_BAY}-${SPECIAL_DEFAULT_LEVEL}-${SPECIAL_DEFAULT_SIDE}`;
    }

    const isCompactRRow = (prefix) => prefix === 'R10' || prefix === 'R11';

    // 8 chars: row(3) bay(2) level(2) side(1)
    if (/^[A-Z0-9]{8}$/.test(code)) {
      const row = code.slice(0, 3);
      if (!isCompactRRow(row)) return code;
      const bay = code.slice(3, 5);
      const level = code.slice(5, 7);
      const side = code.slice(7);
      return `${row}-${bay}-${level}-${side}`;
    }

    // 7 chars: row(3) bay(2) level(2) -> leave side blank with trailing dash
    if (/^[A-Z0-9]{7}$/.test(code)) {
      const row = code.slice(0, 3);
      if (!isCompactRRow(row)) return code;
      const bay = code.slice(3, 5);
      const level = code.slice(5, 7);
      return `${row}-${bay}-${level}-`;
    }

    return code;
  };

  const looksLikeLocation = (s) => {
    const code = normalizeLocationInput(s);
    if (!/^[A-Z0-9]+-\d{2}-\d{2}-[A-Z0-9]$/.test(code)) return false;
    const rowPart = code.split('-')[0];
    return isAllowedLocationRow(rowPart);
  };

  // Normalize the manual input field in-place (special rows, compact R10/R11 codes)
  function normalizeManualInput() {
    if (!manual) return '';
    const raw = manual.value.trim();
    const normalized = normalizeLocationInput(raw);
    if (normalized && normalized !== raw) {
      manual.value = normalized;
      return normalized;
    }
    return raw;
  }

  function toast(msg) {
    if (!panel || !msg) return;
    panel.setAttribute('data-toast', msg);
    if (panel._toast) clearTimeout(panel._toast);
    panel._toast = setTimeout(() => {
      panel.removeAttribute('data-toast');
      panel._toast = null;
    }, 2200);
  }

  function showSearchMessage(msg) {
    if (!searchList) return;
    searchList.hidden = false;
    searchList.innerHTML =
      `<div class="muted" style="padding:10px 12px;">${esc(msg)}</div>`;
  }

  function showError(msg) {
    console.error('Scan lookup error:', msg);
    showSearchMessage(msg || 'Lookup failed.');
    toast(msg || 'Lookup failed.');
  }

  function showLoading() {
    if (!searchList) return;
    searchList.hidden = false;
    searchList.innerHTML = `<div class="loading">Searching…</div>`;
  }

  function hideSearchList() {
    if (!searchList) return;
    searchList.hidden = true;
    searchList.innerHTML = '';
  }

  function invalidatePendingSearches() {
    searchRequestNonce += 1;
    if (aiTimer) {
      clearTimeout(aiTimer);
      aiTimer = null;
    }
  }

  function setCameraMinimized(minimized) {
    if (!camSection) return;

    camSection.hidden = !!minimized;
    drawer?.setAttribute('data-camera-minimized', minimized ? 'true' : 'false');

    if (camToggleBtn) {
      camToggleBtn.textContent = minimized ? 'Show Camera' : 'Minimize Camera';
      camToggleBtn.setAttribute('aria-expanded', minimized ? 'false' : 'true');
      camToggleBtn.setAttribute('aria-label', minimized ? 'Show camera controls' : 'Minimize camera controls');
    }

    if (minimized) {
      stopCamera();
    }
  }

  function setDrawerState(open) {
    if (!drawer) return;

    drawer.hidden = !open;
    drawer.dataset.open = open ? 'true' : 'false';
    drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
    drawer.style.display = open ? 'block' : '';
    drawer.style.pointerEvents = open ? '' : 'none';

    if ('inert' in drawer) {
      drawer.inert = !open;
    }

    launch?.setAttribute('aria-expanded', open ? 'true' : 'false');
    document.body.dataset.scanOpen = open ? 'true' : 'false';
  }

  // --- Drawer Control ---
  function openDrawer() {
    const isOpen = drawer?.getAttribute('data-open') === 'true';
    if (isOpen) {
      closeDrawer();
      return;
    }

    setDrawerState(true);
    setCameraMinimized(false);
    manual?.focus();
    // startCamera();
  }

  function closeDrawer() {
    stopCamera();
    setCameraMinimized(false);
    invalidatePendingSearches();
    setDrawerState(false);
    hideSearchList();
  }

  function resetScan() {
    invalidatePendingSearches();
    if (manual) manual.value = '';
    lastValue = '';

    showSku(null);
    showLocations([], 0);
    hideSearchList();

    if (productImage) {
      productImage.src = '';
      productImage.style.display = 'none';
      productImage.alt = 'Product Image';
    }

    if (summary) summary.textContent = '';
    if (locBody) locBody.innerHTML = '';
    if (resultsWrap) resultsWrap.hidden = true;
    lastSkuData = null;
    lastLocations = [];
    lastLocationsTotal = 0;
    updateCopyButtonState();

    if (bracket) {
      bracket.style.borderColor = 'rgba(13,202,240,.6)';
      setTimeout(() => {
        bracket.style.borderColor = 'rgba(13,202,240,.6)';
      }, 300);
    }

    manual?.focus();
    toast('Cleared');
  }

  // --- UI Display ---
  function showSku(sku) {
    if (!resultsWrap || !skuGrid || !summary || !locBody) return;

    // Clear everything if no SKU
    if (!sku) {
      currentSkuNum = '';
      resultsWrap.hidden = true;
      skuGrid.innerHTML = '';
      summary.textContent = '';
      locBody.innerHTML = '';
      if (palletHeading) palletHeading.hidden = true;
      if (productImage) {
        productImage.src = '';
        productImage.style.display = 'none';
      }
      lastSkuData = null;
      window.InvApp.scanState.currentSkuNum = '';
      window.InvApp.scanState.lastSkuData = null;
      window.InvApp.scanState.lastLookupSku = null;
      window.InvApp.scanState.lastLookupData = null;
      if (addUpcBtn) {
        addUpcBtn.dataset.skuId = '';
        addUpcBtn.dataset.skuNum = '';
      }
      updateCopyButtonState();
      return;
    }

    lastSkuData = { ...sku };
    window.InvApp.scanState.lastSkuData = lastSkuData;
    window.InvApp.scanState.lastLookupSku = lastSkuData;
    window.InvApp.scanState.lastLookupData = { sku: lastSkuData };

    // Image
    if (productImage) {
      const imageUrl = sku.image_url || '';
      productImage.src = esc(imageUrl);
      productImage.alt = `Image of ${esc(sku.desc)}`;
      productImage.style.display = imageUrl ? 'block' : 'none';
    }

    currentSkuNum = sku.sku_num || '';
    window.InvApp.scanState.currentSkuNum = currentSkuNum;
    const active = (sku.status || '').toUpperCase() === 'ACTIVE';
    const baseUrl = APP_BASE;

    if (addUpcBtn) {
      addUpcBtn.dataset.skuId = String(sku.sku_id || sku.id || '');
      addUpcBtn.dataset.skuNum = String(currentSkuNum || '');
    }

    const skuLink = currentSkuNum
      ? `<a href="${baseUrl}/invSku.php?q=${encodeURIComponent(currentSkuNum)}"
            target="" rel="noopener">${esc(currentSkuNum)}</a>`
      : 'N/A';

    const uomInfo = sku._uom || null;
    const uomRow = uomInfo && (uomInfo.uom_label || uomInfo.multiplier)
      ? row('Scan UOM', `${esc(uomInfo.uom_label || 'UNIT')} × ${Number(uomInfo.multiplier || 1)}`)
      : '';

    skuGrid.innerHTML = [
      row('Scanned Code', esc(sku.scanned_code || '')),
      row('SKU #', skuLink),
      row('Status',
        `<span class="badge ${active ? 'ok' : 'bad'}">${esc(sku.status || 'UNKNOWN')}</span>`),
      row('Description', esc(sku.desc || '')),
      row('Shelf Code', esc(sku.shelf_code || 'N/A')),
      uomRow,
      row('SKU Qty (global)', String(Number(sku.sku_quantity ?? 0))),
      sku._ai_derived_location
        ? row('AI Location Target', esc(sku._ai_derived_location))
        : '',
    ].join('');

    resultsWrap.hidden = false;
    updateCopyButtonState();
  }

  function normalizedLocParts(row) {
    const rc = (row?.row_code || '').toUpperCase();
    if (!rc) return null;
    const bay = row?.bay_num || '';
    const lvl = row?.level_code || '';
    const side = row?.side || '';
    const useDefaults = SPECIAL_ROWS.includes(rc) || bay === '' || lvl === '' || side === '';
    return {
      row_code: rc,
      bay_num: useDefaults ? (bay || SPECIAL_DEFAULT_BAY) : bay,
      level_code: useDefaults ? (lvl || SPECIAL_DEFAULT_LEVEL) : lvl,
      side: useDefaults ? (side || SPECIAL_DEFAULT_SIDE) : side,
    };
  }

  function buildLocationUrl(row) {
    const normalized = normalizedLocParts(row);
    if (!normalized) return '';
    const base = `${APP_BASE}/take.php`;
    const params = new URLSearchParams({
      row_code: normalized.row_code,
      bay_num: normalized.bay_num,
      level_code: normalized.level_code,
      side: normalized.side
    });
    const skuParam = (currentSkuNum || '').trim();
    if (skuParam) {
      params.set('return', `invSku.php?q=${encodeURIComponent(skuParam)}`);
    }
    return `${base}?${params.toString()}`;
  }

  function showLocations(rows, total) {
    if (!locBody || !summary) return;
    locBody.innerHTML = '';



    lastLocations = Array.isArray(rows) ? [...rows] : [];
    lastLocationsTotal = total;

    if (!rows || !rows.length) {
      summary.textContent = 'No stock on hand across locations.';
      if (palletHeading) palletHeading.hidden = false;
      updateCopyButtonState();
      return;
    }

    summary.textContent =
      `Locations with stock (Total on-hand: ${Number(total)})`;
    if (palletHeading) palletHeading.hidden = false;

    for (const r of rows) {
      const tr = document.createElement('tr');
      const url = buildLocationUrl(r);
      const label = esc(r.location_label || '');
      const anchor = url
        ? `<a href="${esc(url)}" rel="noopener">${label}</a>`
        : label;

      tr.innerHTML =
        `<td>${anchor}</td>
         <td>${Number(r.on_hand)}</td>
         <td class="muted">${esc(r.last_movement || '')}</td>`;
      locBody.appendChild(tr);
    }
    updateCopyButtonState();
  }

  // --- Fuzzy SKU suggestions via sku_search_ai.php (SQL fuzzy)
  async function lookupSkuFuzzy(q) {
    const requestId = ++searchRequestNonce;
    try {
      const url = new URL(SKU_FUZZY_URL, window.location.origin);
      url.searchParams.set('q', q);
      showLoading();

      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      if (requestId !== searchRequestNonce) return;
      const ct = res.headers.get('content-type') || '';

      if (!res.ok) {
        const text = await res.text();
        if (requestId !== searchRequestNonce) return;
        console.error('sku_search_ai.php HTTP error', res.status, text);
        showSearchMessage('Search failed (server error).');
        return;
      }

      if (!ct.includes('application/json')) {
        const text = await res.text();
        if (requestId !== searchRequestNonce) return;
        console.error('sku_search_ai.php returned non-JSON', res.status, text);
        showSearchMessage('Search failed (unexpected response).');
        return;
      }

      const data = await res.json();
      if (requestId !== searchRequestNonce) return;

      if (!data.ok) {
        showSearchMessage(data.error || 'Search failed.');
        return;
      }

      if (!data.results || !data.results.length) {
        showSearchMessage('No matches.');
        return;
      }

      if (!searchList) return;

      searchList.innerHTML = data.results.map(r => `
        <div class="pick" data-sku="${esc(r.sku_num)}">
          <div><strong>${esc(r.sku_num)}</strong> — ${esc(r.desc || '')}</div>
          <div class="muted">On-hand: ${Number(r.on_hand)} | ${esc(r.status || '')}</div>
        </div>
      `).join('');
      searchList.hidden = false;

      searchList.querySelectorAll('.pick').forEach(el => {
        el.addEventListener('click', () => {
          const sku = el.getAttribute('data-sku') || '';
          if (manual) manual.value = sku;
          invalidatePendingSearches();
          lookupSkuDirect(sku);
        });
      });
    } catch (e) {
      if (requestId !== searchRequestNonce) return;
      console.error('*** SKU Fuzzy Lookup FAILED in JS:', e);
      showSearchMessage('SKU search failed.');
    }
  }

  // --- Camera Control ---
  // async function startCamera() {
  //   try {
  //     if (stream && scanning) {
  //       if (startBtn) startBtn.disabled = true;
  //       if (stopBtn) stopBtn.disabled = false;
  //       return;
  //     }

  //     if (!video && scanBox) {
  //       video = document.createElement('video');
  //       video.id = 'scanVideo';
  //       video.autoplay = true;
  //       video.playsInline = true;
  //       video.muted = true;
  //       scanBox.prepend(video);
  //     }
  //     if (!video) {
  //       console.error('No #scanVideo element found.');
  //       toast('Scanner video element missing.');
  //       return;
  //     }

  //     stream = await navigator.mediaDevices.getUserMedia({
  //       video: {
  //         facingMode: { ideal: 'environment' },
  //         advanced: [{ focusMode: 'continuous' }],
  //         width: { ideal: 300 },
  //         height: { ideal: 300 }
  //       },
  //       audio: false
  //     });

  //     video.srcObject = stream;

  //     try {
  //       await video.play();
  //     } catch (e) {
  //       console.error('Video play() failed:', e);
  //     }

  //     if (startBtn) startBtn.disabled = true;
  //     if (stopBtn) stopBtn.disabled = false;
  //     scanning = true;

  //     if ('BarcodeDetector' in window) {
  //       try {
  //         detector = new window.BarcodeDetector({
  //           formats: [
  //             'ean_13', 'ean_8', 'upc_a', 'upc_e',
  //             'code_128', 'code_39', 'qr_code', 'itf'
  //           ]
  //         });
  //       } catch {
  //         detector = new window.BarcodeDetector();
  //       }
  //       scanLoop();
  //     } else {
  //       toast('BarcodeDetector not supported. Use manual entry.');
  //     }
  //   } catch (e) {
  //     console.error(e);
  //     toast('Camera access failed. Check permissions.');
  //   }
  // }

  // function stopCamera() {
  //   scanning = false;
  //   if (raf) cancelAnimationFrame(raf);
  //   if (stream) {
  //     for (const t of stream.getTracks()) t.stop();
  //   }
  //   stream = null;
  //   if (startBtn) startBtn.disabled = false;
  //   if (stopBtn) stopBtn.disabled = true;
  // }

  // async function scanLoop() {
  //   if (!scanning || !detector || !video) return;

  //   try {
  //     if (cooldown > 0) {
  //       cooldown--;
  //       raf = requestAnimationFrame(scanLoop);
  //       return;
  //     }

  //     const codes = await detector.detect(video);
  //     if (codes && codes.length) {
  //       const value = (codes[0].rawValue || '').trim();
  //       const format = codes[0].format || 'Unknown';

  //       console.warn(`--- BARCODE CAPTURED: "${value}" (Format: ${format}) ---`);

  //       if (value && value !== lastValue) {
  //         lastValue = value;
  //         if (bracket) bracket.style.borderColor = '#28a745';
  //         if (manual) manual.value = value;

  //         invalidatePendingSearches();
  //         lookup(); // main routing

  //         cooldown = 2;
  //         setTimeout(() => {
  //           if (bracket) {
  //             bracket.style.borderColor = 'rgba(13,202,240,.6)';
  //           }
  //         }, 800);
  //       }
  //     }
  //   } catch (e) {
  //     console.error('Barcode detector error:', e);
  //   }

  //   raf = requestAnimationFrame(scanLoop);
  // }

  // --- API Lookups ---

  // Direct SKU lookup via scan_api.php
  async function lookupSkuDirect(code) {
    console.log('--- lookupSkuDirect called with code:', code);
    try {
      invalidatePendingSearches();
      const url = new URL(API_URL, window.location.origin);
      url.searchParams.set('code', code);
      hideSearchList();

      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const ct = res.headers.get('content-type') || '';

      if (!res.ok) {
        const text = await res.text();
        console.error('scan_api.php HTTP error', res.status, text);
        showError('Lookup failed (server error).');
        return;
      }

      if (!ct.includes('application/json')) {
        const text = await res.text();
        console.error('scan_api.php returned non-JSON', res.status, text);
        showError('Lookup failed (unexpected response).');
        return;
      }

      const data = await res.json();
      console.log('API SUCCESS: Received Data:', data);
      window.InvApp.scanState.lastLookupData = data;
      window.InvApp.scanState.lastLookupSku = data?.sku || null;

      if (data.ok) {
        try {
          if (data.sku && data.uom) {
            data.sku._uom = data.uom;
          }
          if (data.sku && data.sku.sku_num) {
            document.dispatchEvent(new CustomEvent('invapp:scan-sku', {
              detail: { sku: data.sku.sku_num }
            }));
          }
          if (data.sku && data.sku._shopify_product) {
            console.info('Shopify product (from scan_api.php):', data.sku._shopify_product);
          }
          showSku(data.sku);
          showLocations(data.locations || [], data.total_on_hand || 0);
          if (resultsWrap) resultsWrap.hidden = false;
          toast('SKU Found: ' + (data.sku?.sku_num || code));
        } catch (displayError) {
          console.error('*** JS DISPLAY FAILED:', displayError);
          toast('Error displaying results. Check console.');
        }
      } else {
        const err = data.detail && data.error
          ? `${data.error} (${data.detail})`
          : (data.error || 'Unknown error');
        showError(err);
      }
    } catch (e) {
      console.error('*** API Lookup FAILED in JS:', e);
      toast('Lookup failed due to system error.');
    }
  }

  // Direct location lookup via loc_api.php
  async function lookupLocationDirect(code) {
    console.log('--- lookupLocationDirect called with code:', code);

    try {
      invalidatePendingSearches();
      const url = new URL(LOC_API_URL, window.location.origin);
      url.searchParams.set('code', code);
      showLoading();

      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const ct = res.headers.get('content-type') || '';

      if (!res.ok) {
        const text = await res.text();
        console.error('loc_api.php HTTP error', res.status, text);
        showSearchMessage('Location lookup failed (server error).');
        return;
      }

      if (!ct.includes('application/json')) {
        const text = await res.text();
        console.error('loc_api.php returned non-JSON', res.status, text);
        showSearchMessage('Location lookup failed (unexpected response).');
        return;
      }

      const data = await res.json();

      if (data.ok && data.location) {
        const loc = data.location;
        const base = APP_BASE;

        const links = [
          `<a href="${base}/place.php?row_code=${encodeURIComponent(loc.row_code)}&bay_num=${encodeURIComponent(loc.bay_num || SPECIAL_DEFAULT_BAY)}&level_code=${encodeURIComponent(loc.level_code || SPECIAL_DEFAULT_LEVEL)}&side=${encodeURIComponent(loc.side || SPECIAL_DEFAULT_SIDE)}"
              class="btn" target="" rel="noopener">Add Inventory</a>`,
          `<a href="${base}/take.php?row_code=${encodeURIComponent(loc.row_code)}&bay_num=${encodeURIComponent(loc.bay_num || SPECIAL_DEFAULT_BAY)}&level_code=${encodeURIComponent(loc.level_code || SPECIAL_DEFAULT_LEVEL)}&side=${encodeURIComponent(loc.side || SPECIAL_DEFAULT_SIDE)}"
              class="btn" target="" rel="noopener">Remove Inventory</a>`,
          `<a href="${base}/invLoc.php?row_code=${encodeURIComponent(loc.row_code)}&bay_num=${encodeURIComponent(loc.bay_num || SPECIAL_DEFAULT_BAY)}&level_code=${encodeURIComponent(loc.level_code || SPECIAL_DEFAULT_LEVEL)}&side=${encodeURIComponent(loc.side || SPECIAL_DEFAULT_SIDE)}"
              class="btn" target="" rel="noopener">View Inventory at Location</a>`
        ].join('');

        if (searchList) {
          searchList.hidden = false;
          searchList.innerHTML = `
            <div style="padding: 10px 12px;">
              <h4>Location Found: ${esc(loc.label)}</h4>
              <p class="muted">Select an action:</p>
              <div class="location-actions" style="display:flex; flex-direction:column; gap:8px;">
                ${links}
              </div>
            </div>`;
        }

        showSku(null);
        showLocations([], 0);
        toast('Location Found: ' + loc.label);
      } else {
        showError(data.error || `Location lookup failed for: ${code}`);
      }
    } catch (e) {
      console.error('*** Location Lookup FAILED in JS:', e);
      toast('Location lookup failed due to system error.');
    }
  }

  // Fuzzy location suggestions via loc_search_fuzzy.php
  async function lookupLocationFuzzy(q) {
    console.log('--- lookupLocationFuzzy initiated for:', q);
    const requestId = ++searchRequestNonce;
    try {
      const url = new URL(LOC_FUZZY_URL, window.location.origin);
      url.searchParams.set('q', q);
      showLoading();

      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      if (requestId !== searchRequestNonce) return;
      const ct = res.headers.get('content-type') || '';

      if (!res.ok) {
        const text = await res.text();
        if (requestId !== searchRequestNonce) return;
        console.error('loc_search_fuzzy.php HTTP error', res.status, text);
        showSearchMessage('Location search failed (server error).');
        return;
      }

      if (!ct.includes('application/json')) {
        const text = await res.text();
        if (requestId !== searchRequestNonce) return;
        console.error('loc_search_fuzzy.php returned non-JSON', res.status, text);
        showSearchMessage('Location search failed (unexpected response).');
        return;
      }

      const data = await res.json();
      if (requestId !== searchRequestNonce) return;

      if (!data.ok) {
        showSearchMessage(data.error || 'Location search failed.');
        return;
      }

      if (!data.results || !data.results.length) {
        showSearchMessage('No location matches.');
        return;
      }

      if (!searchList) return;

      searchList.innerHTML = data.results.map(r => `
        <div class="pick location-pick" data-loc="${esc(r.label)}">
          <strong>${esc(r.label)}</strong>
        </div>
      `).join('');
      searchList.hidden = false;

      searchList.querySelectorAll('.location-pick').forEach(el => {
        el.addEventListener('click', () => {
          const locLabel = el.getAttribute('data-loc') || '';
          if (manual) manual.value = locLabel;
          invalidatePendingSearches();
          lookupLocationDirect(locLabel);
        });
      });
    } catch (e) {
      if (requestId !== searchRequestNonce) return;
      console.error('*** Location Fuzzy Lookup FAILED in JS:', e);
      showSearchMessage('Location fuzzy search failed.');
    }
  }

  // --- Main lookup router (manual entry + scan) ---
  async function lookup() {
    invalidatePendingSearches();
    let q = manual ? normalizeManualInput() : '';
    const normalizedLoc = normalizeLocationInput(q);
    if (normalizedLoc && normalizedLoc !== q) {
      q = normalizedLoc;
      if (manual) manual.value = normalizedLoc;
    }
    console.log('--- LOOKUP initiated for:', q);
    if (!q) {
      toast('Enter or scan a code.');
      return;
    }

    hideSearchList();

    if (looksLikeLocation(q)) {
      console.log('--- Routing to Location Direct Lookup ---');
      return lookupLocationDirect(q);
    }

    if (isCompleteSku(q)) {
      console.log('--- Routing to SKU Direct Lookup ---');
      return lookupSkuDirect(q);
    }

    console.log('--- Routing to Fuzzy SKU Lookup ---');
    if (searchList) showLoading();
    return lookupSkuFuzzy(q);
  }

  // --- Event Listeners ---
  launch?.addEventListener('click', openDrawer);
  closeBtn?.addEventListener('click', closeDrawer);
  backdrop?.addEventListener('click', closeDrawer);
  resetBtn?.addEventListener('click', resetScan);

  // ESC key behavior
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && drawer?.getAttribute('data-open') === 'true') {
      const hasResults = !resultsWrap?.hidden;
      if ((manual && manual.value) || hasResults) {
        e.stopPropagation();
        resetScan();
      } else {
        closeDrawer();
      }
    }
  });

  // Manual triggers
  lookupBtn?.addEventListener('click', () => {
    normalizeManualInput();
    showLoading();
    lookup();
  });
  copyBtn?.addEventListener('click', copyResultsToClipboard);
  manual?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      normalizeManualInput();
      showLoading();
      lookup();
    }
  });

  // Fuzzy LOCATION or SKU search
  manual?.addEventListener('input', () => {
    let q = manual.value.trim();

    // Auto-format special rows and compact location codes into SS-SS-SS-S
    const normalizedLoc = normalizeLocationInput(q);
    if (normalizedLoc && normalizedLoc !== q) {
      q = normalizedLoc;
      manual.value = normalizedLoc;
    }

    if (!q) {
      clearTimeout(aiTimer);
      aiTimer = null;
      hideSearchList();
      return;
    }

    // If it looks like full location, don't fuzzy search — just wait for Enter.
    if (looksLikeLocation(q)) {
      clearTimeout(aiTimer);
      aiTimer = null;
      hideSearchList();
      return;
    }

    clearTimeout(aiTimer);
    aiTimer = null;

    const qUpper = q.toUpperCase();

    // Require an allowed prefix (R10/R11 or special rows) before treating as location
    const hasLetter = /[A-Z]/.test(qUpper);
    const looksLikePartialLoc =
      isAllowedLocationPrefix(qUpper) && hasLetter && (
        /^[A-Z0-9]{3,}/.test(qUpper) ||  // e.g. "R10", "TUN"
        /^[A-Z0-9].*?-/.test(qUpper)     // e.g. "TUNNEL-", "R10-"
      );

    if (looksLikePartialLoc) {
      if (searchList) showLoading();
      aiTimer = setTimeout(() => {
        aiTimer = null;
        lookupLocationFuzzy(q);
      }, 400);
      return;
    }

    // Otherwise run fuzzy SKU suggestions
    if (searchList) showLoading();
    aiTimer = setTimeout(() => {
      aiTimer = null;
      lookupSkuFuzzy(q);
    }, 400);
  });

  // Camera controls
  // startBtn?.addEventListener('click', startCamera);
  // stopBtn?.addEventListener('click', stopCamera);
  // camToggleBtn?.addEventListener('click', () => {
  //   const isMinimized = camSection?.hidden === true;
  //   setCameraMinimized(!isMinimized);

  //   if (isMinimized && drawer?.getAttribute('data-open') === 'true') {
  //     startCamera();
  //   }
  // });
})();
