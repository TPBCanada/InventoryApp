// /js/transfer.js
(function () {
  if (window.__transferInit) return;
  window.__transferInit = true;

  // --- read boot data ---
  function readBoot() {
    const el = document.getElementById('transfer-boot');
    if (!el) return null;
    try { return JSON.parse(el.textContent || '{}'); } catch { return null; }
  }
  const BOOT = readBoot();
  if (!BOOT) return;

  const rows        = BOOT.rows || [];
  const baysByRow   = BOOT.baysByRow || {};
  const levelsByRow = BOOT.levelsByRow || {};
  const sidesByRow  = BOOT.sidesByRow || {};
  const ajaxPath    = BOOT.ajaxPath || location.pathname;

  // --- elements ---
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
  const hiddenSkuNum   = document.getElementById('transfer_sku_val'); // what PHP reads
  const availSpan      = document.getElementById('avail');
  const qtyInput       = document.getElementById('qty');
  const approveBtn     = document.getElementById('approveBtn');
  const resetBtn       = document.getElementById('resetBtn');
  const form           = document.getElementById('transferForm');

  // --- helpers ---
  function fill(select, arr, placeholder) {
    if (!select) return;
    select.innerHTML = '';
    const ph = document.createElement('option');
    ph.value = ''; ph.textContent = placeholder;
    select.appendChild(ph);
    (arr || []).forEach(v => {
      const o = document.createElement('option');
      o.value = v; o.textContent = v;
      select.appendChild(o);
    });
    select.disabled = (arr || []).length === 0;
  }
  function arr(x){ return Array.isArray(x) ? x.slice() : []; }
  function getBays(row){ return arr(baysByRow[row]); }
  function getLevels(row,bay){
    if (levelsByRow[row] && Array.isArray(levelsByRow[row][bay])) return levelsByRow[row][bay].slice();
    return arr(levelsByRow[row]);
  }
  function getSides(row,bay,lvl){
    if (sidesByRow[row] && sidesByRow[row][bay] && Array.isArray(sidesByRow[row][bay][lvl])) {
      return sidesByRow[row][bay][lvl].slice();
    }
    return arr(sidesByRow[row]);
  }

  function clearResults(isSrc) {
    const tbody = isSrc ? srcTableBody : dstTableBody;
    if (!tbody) return;
    tbody.innerHTML = '<tr><td class="muted" colspan="2">Pick a full location to load items.</td></tr>';
  }

  function validateApproveReady() {
    const okSrc = sRowVal.value && sBayVal.value && sLvlVal.value && sSideVal.value;
    const okDst = dRowVal.value && dBayVal.value && dLvlVal.value && dSideVal.value;
    const okSku = hiddenSkuNum.value && chosenSkuInput.value;
    const maxAvail = parseInt(availSpan.textContent || '0', 10);
    const q = parseInt(qtyInput.value || '0', 10);
    approveBtn.disabled = !(okSrc && okDst && okSku && q > 0 && q <= maxAvail);
  }

  function bindQty() {
    qtyInput.addEventListener('input', () => {
      const max = parseInt(qtyInput.max || '0', 10);
      let v = parseInt(qtyInput.value || '0', 10);
      if (!Number.isFinite(v)) v = 1;
      if (v > max) v = max;
      if (v < 1) v = 1;
      qtyInput.value = v;
      validateApproveReady();
    });
  }

  // --- fetch & render inventory for a location ---
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

    // sync hidden locs used by PHP
    if (isSrc) { sRowVal.value=row; sBayVal.value=bay; sLvlVal.value=lvl; sSideVal.value=side; }
    else       { dRowVal.value=row; dBayVal.value=bay; dLvlVal.value=lvl; dSideVal.value=side; }

    if (!data.items.length) {
      tbody.innerHTML = '<tr><td class="muted" colspan="2">No items at this location.</td></tr>';
    } else {
      tbody.innerHTML = '';
      data.items.forEach(it => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${it.sku_num}</td><td>${it.quantity}</td>`;
        tr.dataset.skuNum = it.sku_num;
        tr.dataset.qty    = it.quantity;
        if (isSrc) {
          tr.style.cursor = 'pointer';
          tr.addEventListener('click', () => {
            // visual select
            [...srcTableBody.querySelectorAll('tr')].forEach(r => r.classList.remove('selected'));
            tr.classList.add('selected');
            // fill 3rd box + hidden field PHP expects
            chosenSkuInput.value  = it.sku_num;
            hiddenSkuNum.value    = it.sku_num;
            availSpan.textContent = it.quantity;
            // enable qty
            qtyInput.disabled = false;
            qtyInput.min = '1';
            qtyInput.max = String(it.quantity);
            qtyInput.value = it.quantity > 0 ? '1' : '';
            validateApproveReady();
          });
        }
        tbody.appendChild(tr);
      });
    }
    validateApproveReady();
  }

  // --- cascading selects ---
  function onRowChange(rowSel, baySel, lvlSel, sideSel, isSrc) {
    const row = rowSel.value;
    const bays = getBays(row);
    fill(baySel, bays, 'Bay');     baySel.disabled = (bays.length === 0);
    fill(lvlSel, [], 'Level');     lvlSel.disabled = true;
    fill(sideSel, [], 'Side');     sideSel.disabled = true;

    if (isSrc) { sRowVal.value = row; sBayVal.value = sLvlVal.value = sSideVal.value = ''; clearResults(true); }
    else       { dRowVal.value = row; dBayVal.value = dLvlVal.value = dSideVal.value = ''; clearResults(false); }

    validateApproveReady();
  }
  function onBayChange(rowSel, baySel, lvlSel, sideSel, isSrc) {
    const row = rowSel.value;
    const bay = baySel.value;
    const lvls = getLevels(row, bay);
    fill(lvlSel, lvls, 'Level');   lvlSel.disabled = (lvls.length === 0);
    fill(sideSel, [], 'Side');     sideSel.disabled = true;

    if (isSrc) { sBayVal.value = bay; sLvlVal.value = sSideVal.value = ''; clearResults(true); }
    else       { dBayVal.value = bay; dLvlVal.value = dSideVal.value = ''; clearResults(false); }

    validateApproveReady();
  }
  function onLvlChange(rowSel, baySel, lvlSel, sideSel, isSrc) {
    const row = rowSel.value, bay = baySel.value, lvl = lvlSel.value;
    let sides = getSides(row, bay, lvl);
    if (row === 'R11') sides = ['F']; // your rule
    fill(sideSel, sides, 'Side'); sideSel.disabled = (sides.length === 0);

    if (isSrc) { sLvlVal.value = lvl; sSideVal.value = ''; clearResults(true); }
    else       { dLvlVal.value = lvl; dSideVal.value = ''; clearResults(false); }

    validateApproveReady();
  }
  function onSideChange(rowSel, baySel, lvlSel, sideSel, isSrc) {
    const row  = rowSel.value;
    const side = (row === 'R11') ? 'F' : sideSel.value;
    sideSel.value = side;
    if (isSrc) { sSideVal.value = side; } else { dSideVal.value = side; }
    fetchInv(isSrc);
  }

  // keep hidden fields in sync on submit (and ensure qty is enabled)
  form.addEventListener('submit', () => {
    if (qtyInput.disabled) qtyInput.disabled = false;
    // (fields already kept in sync elsewhere)
  });

  // reset fully
  resetBtn && resetBtn.addEventListener('click', () => { setTimeout(() => location.reload(), 0); });

  // --- init ---
  fill(sRow, rows, 'Row'); fill(dRow, rows, 'Row');
  sRow && sRow.addEventListener('change', () => onRowChange(sRow, sBay, sLvl, sSide, true));
  sBay && sBay.addEventListener('change', () => onBayChange(sRow, sBay, sLvl, sSide, true));
  sLvl && sLvl.addEventListener('change', () => onLvlChange(sRow, sBay, sLvl, sSide, true));
  sSide&& sSide.addEventListener('change', () => onSideChange(sRow, sBay, sLvl, sSide, true));

  dRow && dRow.addEventListener('change', () => onRowChange(dRow, dBay, dLvl, dSide, false));
  dBay && dBay.addEventListener('change', () => onBayChange(dRow, dBay, dLvl, dSide, false));
  dLvl && dLvl.addEventListener('change', () => onLvlChange(dRow, dBay, dLvl, dSide, false));
  dSide&& dSide.addEventListener('change', () => onSideChange(dRow, dBay, dLvl, dSide, false));

  bindQty();
  clearResults(true); clearResults(false);
  validateApproveReady();
})();
