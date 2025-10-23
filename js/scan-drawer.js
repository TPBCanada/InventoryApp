// /assets/js/scan-drawer.js
(() => {

  const API_URL = (window.INVAPP_BASE || '') + '/scan_api.php';
  const AI_SEARCH_URL  = (window.INVAPP_BASE || '') + '/sku_search_ai.php';


  // Elements
  const drawer   = document.getElementById('scanDrawer');
  const panel    = drawer?.querySelector('.scan-drawer__panel');
  const backdrop = document.getElementById('scanBackdrop');
  const launch   = document.getElementById('scanLaunchBtn');
  const closeBtn = document.getElementById('scanCloseBtn');
  const resetBtn = document.getElementById('scanResetBtn'); 
  const searchList = document.getElementById('scanSearchList');


  const video    = document.getElementById('scanVideo');
  const startBtn = document.getElementById('scanStartBtn');
  const stopBtn  = document.getElementById('scanStopBtn');
  const manual   = document.getElementById('scanManual');
  const lookupBtn= document.getElementById('scanLookupBtn');
  const bracket  = document.getElementById('scanBracket');

  const resultsWrap = document.getElementById('scanResults');
  const skuGrid  = document.getElementById('scanSkuGrid');
  const summary  = document.getElementById('scanSummary');
  const locBody  = document.getElementById('scanLocBody');

  let stream = null, detector = null, scanning = false, raf = null, lastValue = '', cooldown = 0;

  function openDrawer(){
    drawer?.setAttribute('data-open', 'true');
    drawer?.setAttribute('aria-hidden', 'false');
    launch?.setAttribute('aria-expanded', 'true');
  }
  function closeDrawer(){
    stopCamera();
    drawer?.removeAttribute('data-open');
    drawer?.setAttribute('aria-hidden', 'true');
    launch?.setAttribute('aria-expanded', 'false');
  }

  // Launchers
  launch?.addEventListener('click', openDrawer);
  closeBtn?.addEventListener('click', closeDrawer);
  backdrop?.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && drawer?.getAttribute('data-open') === 'true') {
      if (manual.value || !document.getElementById('scanResults')?.hidden) {
        e.stopPropagation();
        resetScan();
      } else {
        closeDrawer();
      }
    }

  });

  // Camera + Scan
  async function startCamera(){
    try {
      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 500 }, height: { ideal: 300 } },
        audio: false
      });
      video.srcObject = stream;
      startBtn.disabled = true;
      stopBtn.disabled = false;
      scanning = true;

      if ('BarcodeDetector' in window) {
        try {
          detector = new window.BarcodeDetector({
            formats: ['ean_13','ean_8','upc_a','upc_e','code_128','code_39','qr_code','itf']
          });
        } catch { detector = new window.BarcodeDetector(); }
        scanLoop();
      } else {
        toast('BarcodeDetector not supported. Use manual entry.');
      }
    } catch (e) {
      console.error(e);
      toast('Camera access failed. Check permissions.');
    }
  }

  function stopCamera(){
    scanning = false;
    if (raf) cancelAnimationFrame(raf);
    if (stream) for (const t of stream.getTracks()) t.stop();
    stream = null;
    startBtn.disabled = false;
    stopBtn.disabled = true;
  }

  async function scanLoop(){
    if (!scanning || !detector) return;
    try {
      if (cooldown > 0) { cooldown--; raf = requestAnimationFrame(scanLoop); return; }
      const codes = await detector.detect(video);
      if (codes && codes.length){
        const value = (codes[0].rawValue || '').trim();
        if (value && value !== lastValue){
          lastValue = value;
          bracket.style.borderColor = '#28a745';
          manual.value = value;
          lookup();
          cooldown = 10;
          setTimeout(() => bracket.style.borderColor = 'rgba(13,202,240,.6)', 800);
        }
      }
    } catch (_) {}
    raf = requestAnimationFrame(scanLoop);
  }

  startBtn?.addEventListener('click', startCamera);
  stopBtn?.addEventListener('click', stopCamera);
  lookupBtn?.addEventListener('click', lookup);
  resetBtn?.addEventListener('click', resetScan); // NEW

  manual?.addEventListener('keydown', (e) => { if (e.key === 'Enter') lookup(); });

  async function lookupAi(q){
  try{
    const url = new URL(AI_SEARCH_URL, window.location.origin);
    url.searchParams.set('q', q);
    const res = await fetch(url, { headers:{ 'Accept':'application/json' }});
    const data = await res.json();
    if (!data.ok) { showSearchMessage(data.error || 'Search failed.'); return; }

    if (!data.results || !data.results.length){
      showSearchMessage('No matches.'); return;
    }

    
    searchList.innerHTML = data.results.map(r => `
      <div class="pick" data-sku="${esc(r.sku_num)}">
        <div><strong>${esc(r.sku_num)}</strong> — ${esc(r.desc)}</div>
        <div class="muted">On-hand: ${Number(r.on_hand)} | ${esc(r.status||'')}</div>
      </div>
    `).join('');
    searchList.hidden = false;


    searchList.querySelectorAll('.pick').forEach(el => {
      el.addEventListener('click', () => {
        const sku = el.getAttribute('data-sku') || '';
        manual.value = sku;
        hideSearchList();
        lookupSkuDirect(sku);           
      });
    });
  } catch(e){
    console.error(e);
    showSearchMessage('AI search failed.');
  }
}

async function lookupSkuDirect(code){
  try {
    const url = new URL(API_URL, window.location.origin);
    url.searchParams.set('code', code);
    const res = await fetch(url, { headers: { 'Accept': 'application/json' }});
    const data = await res.json();
    if (!data.ok) { showSku(null); showLocations([], 0); toast(data.error || 'Not found.'); return; }
    showSku(data.sku);
    showLocations(data.locations || [], data.total_on_hand || 0);
  } catch (e) { console.error(e); toast('Lookup failed.'); }
}
function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

function showSearchMessage(msg){
  if (!searchList) return;
  searchList.hidden = false;
  searchList.innerHTML = `<div class="muted" style="padding:10px 12px;">${esc(msg)}</div>`;
}
function showLoading(){
  if (!searchList) return;
  searchList.hidden = false;
  searchList.innerHTML = `<div class="loading">Searching…</div>`;
}
function hideSearchList(){
  if (!searchList) return;
  searchList.hidden = true;
  searchList.innerHTML = '';
}

async function lookup(){
  const q = manual.value.trim();
  if (!q) { toast('Enter or scan a code.'); return; }

  // 1) If it looks like a SKU (or you actually scanned a code), hit the existing API
  if (looksLikeSku(q)) {
    return lookupSkuDirect(q);
  }

  // 2) Otherwise: natural language → AI search
  return lookupAi(q);
}

let aiTimer = null;
manual?.addEventListener('input', () => {
  const q = manual.value.trim();
  // If they start typing words, show AI suggestions in-module
  if (!q) { hideSearchList(); return; }
  if (looksLikeSku(q)) { hideSearchList(); return; }      // SKU path, no AI list
  clearTimeout(aiTimer);
  searchList && showLoading();                             // small loading state
  aiTimer = setTimeout(() => lookupAi(q), 400);            // debounce
});

async function lookupSkuDirect(code){
  try {
    const url = new URL((window.INVAPP_BASE || '') + '/scan_api.php', window.location.origin);
    url.searchParams.set('code', code);
    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
    const data = await res.json();
    if (!data.ok) { showSku(null); showLocations([], 0); toast(data.error || 'Not found.'); return; }
    showSku(data.sku);
    showLocations(data.locations || [], data.total_on_hand || 0);
    // hide search list if visible
    const list = document.getElementById('scanSearchList'); if (list) list.hidden = true;
  } catch (e) { console.error(e); toast('Lookup failed.'); }
}

async function lookupAi(q){
  try {
    const url = new URL((window.INVAPP_BASE || '') + '/sku_search_ai.php', window.location.origin);
    url.searchParams.set('q', q);
    const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
    const data = await res.json();
    if (!data.ok) { toast(data.error || 'Search failed'); return; }

    const list = document.getElementById('scanSearchList');
    if (!list) return;

    if (!data.results || !data.results.length){
      list.innerHTML = '<div class="muted">No matches.</div>';
      list.hidden = false;
      return;
    }

    // Render a simple pick-list; clicking a row does a direct lookup
    list.innerHTML = data.results.map(r => `
      <div class="pick" data-sku="${esc(r.sku_num)}">
        <div><strong>${esc(r.sku_num)}</strong> — ${esc(r.desc)}</div>
        <div class="muted">On-hand: ${Number(r.on_hand)} | ${esc(r.status||'')}</div>
      </div>
    `).join('');
    list.hidden = false;

    list.querySelectorAll('.pick').forEach(el => {
      el.addEventListener('click', () => {
        const sku = el.getAttribute('data-sku') || '';
        manual.value = sku;
        lookupSkuDirect(sku);
      });
    });
  } catch (e) { console.error(e); toast('AI search failed.'); }
}

function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

    function looksLikeSku(s){
      const v = String(s).trim();
      if (!v || /\s/.test(v)) return false;            
      return /^[A-Za-z0-9\-]{3,}$/.test(v);            
    }

    function resetScan(){
      // clear manual input + last scanned value
      manual.value = '';
      lastValue = '';
    
      // clear UI
      showSku(null);
      showLocations([], 0);
      const sum = document.getElementById('scanSummary');
      if (sum) sum.textContent = '';
      const body = document.getElementById('scanLocBody');
      if (body) body.innerHTML = '';
      const wrap = document.getElementById('scanResults');
      if (wrap) wrap.hidden = true;
    
      // visual feedback
      if (bracket) {
        bracket.style.borderColor = 'rgba(13,202,240,.6)';
        setTimeout(() => (bracket.style.borderColor = 'rgba(13,202,240,.6)'), 300);
      }
    
      // keep scanning if the camera is already on
      manual.focus();
      toast('Cleared');
    }


  function showSku(sku){
    if (!sku){ resultsWrap.hidden = false; skuGrid.innerHTML=''; summary.textContent=''; locBody.innerHTML=''; return; }
    const active = (sku.status || '').toUpperCase() === 'ACTIVE';
    skuGrid.innerHTML = [
      row('Scanned Code', esc(sku.scanned_code || '')),
      row('SKU #', esc(sku.sku_num || '')),
      row('Status', `<span class="badge ${active?'ok':'bad'}">${esc(sku.status || 'UNKNOWN')}</span>`),
      row('Description', esc(sku.desc || '')),
      row('SKU Qty (global)', String(Number(sku.sku_quantity ?? 0))),
    ].join('');
    resultsWrap.hidden = false;
  }

  function showLocations(rows, total){
    locBody.innerHTML = '';
    if (!rows || !rows.length){
      summary.textContent = 'No stock on hand across locations.';
      return;
    }
    summary.textContent = `Locations with stock (Total on-hand: ${Number(total)})`;
    for (const r of rows){
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${esc(r.location_label)}</td><td>${Number(r.on_hand)}</td><td class="muted">${esc(r.last_movement || '')}</td>`;
      locBody.appendChild(tr);
    }
  }

  // UI helpers
  function row(label, value){ return `<div><strong>${label}</strong>${value}</div>`; }
  function esc(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function toast(msg){
  if (!panel) return;                          // guard
  panel.setAttribute('data-toast', msg);
  if (panel._toast) clearTimeout(panel._toast);
  panel._toast = setTimeout(() => {
    panel.removeAttribute('data-toast');
    panel._toast = null;
  }, 2200);
}
})();
