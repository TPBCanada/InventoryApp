// dev/js/transfer.js
(function () {
  if (window.__transferInit) return; // guard against double-run
  window.__transferInit = true;


    // Inside /js/transfer.js, or code that runs on $(document).ready()
    function init() {
        // 1. Read the boot data
        const bootElement = document.getElementById('transfer-boot');
        if (!bootElement) return;
    
        try {
            const bootData = JSON.parse(bootElement.textContent);
            
            // 2. Check for the postTransfer object
            if (bootData && bootData.postTransfer) {
                const data = bootData.postTransfer;
    
                // 3. Trigger the AJAX calls to load the "After Transfer" tables
                // This function is what loads the inventory (e.g., used in Image 2)
                loadInventoryByLoc(
                    data.s_row, data.s_bay, data.s_lvl, data.s_side, 
                    'srcTableAfter', 'sourceInventoryAfter', bootData.ajaxPath
                );
                loadInventoryByLoc(
                    data.d_row, data.d_bay, data.d_lvl, data.d_side, 
                    'dstTableAfter', 'destinationInventoryAfter', bootData.ajaxPath
                );
            }
            // ... rest of init logic
        } catch (e) {
            console.error("Failed to parse boot JSON:", e);
        }
    }
    
  // Read boot data injected by PHP
  function readBoot() {
    const el = document.getElementById('transfer-boot');
    if (!el) return null;
    try { return JSON.parse(el.textContent || '{}'); }
    catch { return null; }
  }

  const BOOT = readBoot();
  if (!BOOT) return;

  const rows        = BOOT.rows || [];
  const baysByRow   = BOOT.baysByRow || {};
  const levelsByRow = BOOT.levelsByRow || {};
  const sidesByRow  = BOOT.sidesByRow || {};
  const ajaxPath    = BOOT.ajaxPath || location.pathname;

  // Element refs
  const sRow = document.getElementById('s_row'), sBay = document.getElementById('s_bay'),
        sLvl = document.getElementById('s_lvl'), sSide = document.getElementById('s_side');
  const dRow = document.getElementById('d_row'), dBay = document.getElementById('d_bay'),
        dLvl = document.getElementById('d_lvl'), dSide = document.getElementById('d_side');

  const sRowVal = document.getElementById('s_row_val'), sBayVal = document.getElementById('s_bay_val'),
        sLvlVal = document.getElementById('s_lvl_val'), sSideVal = document.getElementById('s_side_val');
  const dRowVal = document.getElementById('d_row_val'), dBayVal = document.getElementById('d_bay_val'),
        dLvlVal = document.getElementById('d_lvl_val'), dSideVal = document.getElementById('d_side_val');

  const srcTableBody = document.querySelector('#srcTable tbody');
  const dstTableBody = document.querySelector('#dstTable tbody');

  const chosenSkuInput = document.getElementById('chosenSkuNum');
  const hiddenSkuId    = document.getElementById('sku_id');
  const availSpan      = document.getElementById('avail');
  const qtyInput       = document.getElementById('qty');
  const approveBtn     = document.getElementById('approveBtn');
  const resetBtn       = document.getElementById('resetBtn');

  // Helpers
  function fill(select, arr, placeholder) {
    if (!select) return;
    select.innerHTML = '';
    const ph = document.createElement('option');
    ph.value = ''; ph.textContent = placeholder;
    select.appendChild(ph);
    arr.forEach(v => {
      const o = document.createElement('option');
      o.value = v; o.textContent = v;
      select.appendChild(o);
    });
    select.disabled = (arr.length === 0);
  }

  function clearResults(isSrc) {
    const tbody = isSrc ? srcTableBody : dstTableBody;
    if (!tbody) return;
    tbody.innerHTML = '<tr><td class="muted" colspan="2">Pick a full location to load items.</td></tr>';
  }

function arr(x) { return Array.isArray(x) ? x.slice() : []; }

function getBays(row) {
  return arr(baysByRow[row]);
}

function getLevels(row, bay) {
  // New nested shape: levelsByRow[row][bay] is an array
  if (levelsByRow[row] && Array.isArray(levelsByRow[row][bay])) {
    return levelsByRow[row][bay].slice();
  }
  // Fallback to old shape: levelsByRow[row] is an array
  return arr(levelsByRow[row]);
}

function getSides(row, bay, lvl) {
  // New nested shape: sidesByRow[row][bay][lvl] is an array
  if (sidesByRow[row] && sidesByRow[row][bay] && Array.isArray(sidesByRow[row][bay][lvl])) {
    return sidesByRow[row][bay][lvl].slice();
  }
  // Fallback to old shape: sidesByRow[row] is an array
  return arr(sidesByRow[row]);
}

  async function fetchInv(isSrc) {
    const row  = (isSrc ? sRow : dRow).value;
    const bay  = (isSrc ? sBay : dBay).value;
    const lvl  = (isSrc ? sLvl : dLvl).value;
    const side = (isSrc ? sSide : dSide).value;
    const tbody = isSrc ? srcTableBody : dstTableBody;

    if (!row || !bay || !lvl || !side) { clearResults(isSrc); return; }

    tbody.innerHTML = '<tr><td class="muted" colspan="2">Loading…</td></tr>';
    const params = new URLSearchParams({ ajax: 'inv_by_loc', row, bay, lvl, side });
    const res = await fetch(ajaxPath + '?' + params.toString(), { credentials: 'same-origin' });
    const data = await res.json().catch(() => ({ ok: false, error: 'Invalid server response.' }));

    if (!data.ok) {
      tbody.innerHTML = `<tr><td class="muted" colspan="2">${data.error || 'No results.'}</td></tr>`;
      return;
    }

    if (isSrc) { sRowVal.value = row; sBayVal.value = bay; sLvlVal.value = lvl; sSideVal.value = side; }
    else       { dRowVal.value = row; dBayVal.value = bay; dLvlVal.value = lvl; dSideVal.value = side; }

    if (!data.items.length) {
      tbody.innerHTML = '<tr><td class="muted" colspan="2">No items at this location.</td></tr>';
    } else {
      tbody.innerHTML = '';
      data.items.forEach(it => {
        const tr = document.createElement('tr');
        tr.dataset.skuId = it.sku_id;
        tr.dataset.skuNum = it.sku_num;
        tr.dataset.qty = it.quantity;
        tr.innerHTML = `<td>${it.sku_num}</td><td>${it.quantity}</td>`;
        if (isSrc) {
          tr.style.cursor = 'pointer';
          tr.addEventListener('click', () => {
            [...srcTableBody.querySelectorAll('tr')].forEach(r => r.classList.remove('selected'));
            tr.classList.add('selected');
            hiddenSkuId.value     = it.sku_id;
            chosenSkuInput.value  = it.sku_num;
            availSpan.textContent = it.quantity;
            qtyInput.disabled     = false;
            qtyInput.max          = it.quantity;
            qtyInput.value        = Math.min(1, it.quantity);
            validateApproveReady();
          });
        }
        tbody.appendChild(tr);
      });
    }
    validateApproveReady();
  }

  function validateApproveReady() {
    const okSrc = sRowVal.value && sBayVal.value && sLvlVal.value && sSideVal.value;
    const okDst = dRowVal.value && dBayVal.value && dLvlVal.value && dSideVal.value;
    const okSku = hiddenSkuId.value;
    const maxAvail = parseInt(availSpan.textContent || '0', 10);
    const q = parseInt(qtyInput.value || '0', 10);
    approveBtn.disabled = !(okSrc && okDst && okSku && q > 0 && q <= maxAvail);
  }

  qtyInput && qtyInput.addEventListener('input', () => {
    const max = parseInt(qtyInput.max || '0', 10);
    let v = parseInt(qtyInput.value || '0', 10);
    if (v > max) v = max;
    if (v < 1) v = 1;
    qtyInput.value = v;
    validateApproveReady();
  });

  approveBtn && approveBtn.addEventListener('click', () => {
    const details =
`Confirm transfer:
  SKU: ${chosenSkuInput.value}
  Qty: ${qtyInput.value}
  From: ${sRowVal.value}-B${sBayVal.value}-L${sLvlVal.value}-${sSideVal.value}
  To:   ${dRowVal.value}-B${dBayVal.value}-L${dLvlVal.value}-${dSideVal.value}`;
    if (confirm(details)) document.getElementById('transferForm').submit();
  });

  resetBtn && resetBtn.addEventListener('click', () => { setTimeout(() => location.reload(), 0); });

  // Cascading dropdowns
    function onRowChange(rowSel, baySel, lvlSel, sideSel, isSrc) {
      const row = rowSel.value;
    
      const bays = getBays(row);
      fill(baySel, bays, 'Bay');     baySel.disabled = (bays.length === 0);
    
      fill(lvlSel, [], 'Level');     lvlSel.disabled = true;
      fill(sideSel, [], 'Side');     sideSel.disabled = true;
    
      if (isSrc) { sRowVal.value = row; sBayVal.value = sLvlVal.value = sSideVal.value = ''; }
      else       { dRowVal.value = row; dBayVal.value = dLvlVal.value = dSideVal.value = ''; }
    
      clearResults(isSrc);
      validateApproveReady();
    }

    function onBayChange(rowSel, baySel, lvlSel, sideSel, isSrc) {
      const row = rowSel.value;
      const bay = baySel.value;
    
      const lvls = getLevels(row, bay);
      fill(lvlSel, lvls, 'Level');   lvlSel.disabled = (lvls.length === 0);
    
      fill(sideSel, [], 'Side');     sideSel.disabled = true;
    
      if (isSrc) { sBayVal.value = bay; sLvlVal.value = sSideVal.value = ''; }
      else       { dBayVal.value = bay; dLvlVal.value = dSideVal.value = ''; }
    
      clearResults(isSrc);
      validateApproveReady();
    }

    function onLvlChange(rowSel, baySel, lvlSel, sideSel, isSrc) {
      const row = rowSel.value;
      const bay = baySel.value;
      const lvl = lvlSel.value;
    
      let sides = getSides(row, bay, lvl);
    
      // Your special rule for R11 still applies
      if (row === 'R11') sides = ['F'];
    
      fill(sideSel, sides, 'Side');  sideSel.disabled = (sides.length === 0);
    
      if (isSrc) { sLvlVal.value = lvl; sSideVal.value = ''; }
      else       { dLvlVal.value = lvl; dSideVal.value = ''; }
    
      clearResults(isSrc);
      validateApproveReady();
    }

  function onSideChange(rowSel, baySel, lvlSel, sideSel, isSrc) {
    const row  = rowSel.value;
    const side = (row === 'R11') ? 'F' : sideSel.value;
    sideSel.value = side;
    if (isSrc) { sSideVal.value = side; } else { dSideVal.value = side; }
    fetchInv(isSrc); // auto-load when full selection exists
  }

// ensure everything is postable right before submit
document.getElementById('transferForm').addEventListener('submit', (e) => {
  // if qty was still disabled for any edge case, enable it so it posts
  if (qtyInput && qtyInput.disabled) qtyInput.disabled = false;

  // sync hidden fields (belt-and-suspenders)
  s_row_val.value = sRow.value || '';
  s_bay_val.value = sBay.value || '';
  s_lvl_val.value = sLvl.value || '';
  s_side_val.value = sSide.value || '';

  d_row_val.value = dRow.value || '';
  d_bay_val.value = dBay.value || '';
  d_lvl_val.value = dLvl.value || '';
  d_side_val.value = dSide.value || '';
});

  // Initial population
  fill(sRow, rows, 'Row');
  fill(dRow, rows, 'Row');

  // Wire source
  sRow && sRow.addEventListener('change', () => onRowChange(sRow, sBay, sLvl, sSide, true));
  sBay && sBay.addEventListener('change', () => onBayChange(sRow, sBay, sLvl, sSide, true));
  sLvl && sLvl.addEventListener('change', () => onLvlChange(sRow, sBay, sLvl, sSide, true));
  sSide && sSide.addEventListener('change', () => onSideChange(sRow, sBay, sLvl, sSide, true));

  // Wire destination
  dRow && dRow.addEventListener('change', () => onRowChange(dRow, dBay, dLvl, dSide, false));
  dBay && dBay.addEventListener('change', () => onBayChange(dRow, dBay, dLvl, dSide, false));
  dLvl && dLvl.addEventListener('change', () => onLvlChange(dRow, dBay, dLvl, dSide, false));
  dSide && dSide.addEventListener('change', () => onSideChange(dRow, dBay, dLvl, dSide, false));

  // Initial state
  clearResults(true);
  clearResults(false);
  validateApproveReady();
})();
