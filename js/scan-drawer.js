// /dev/js/scan-drawer.js (or /assets/js/scan-drawer.js if deployed)
(() => {

    // --- Configuration ---
    // API_URL and AI_SEARCH_URL construction is consolidated and correct.
    const API_URL = (window.INVAPP_BASE || '') + '/scan_api.php';
    const AI_SEARCH_URL = (window.INVAPP_BASE || '') + '/sku_search_ai.php';

    // --- Element References ---
    const drawer = document.getElementById('scanDrawer');
    const panel = drawer?.querySelector('.scan-drawer__panel');
    const backdrop = document.getElementById('scanBackdrop');
    const launch = document.getElementById('scanLaunchBtn');
    const closeBtn = document.getElementById('scanCloseBtn');
    const resetBtn = document.getElementById('scanResetBtn');
    const searchList = document.getElementById('scanSearchList');

    const video = document.getElementById('scanVideo');
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

    // --- State Variables ---
    let stream = null, detector = null, scanning = false, raf = null, lastValue = '', cooldown = 0;
    let aiTimer = null; 

    // --- Helper Functions ---
    // Escape string for safe HTML rendering
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
    const row = (label, value) => `<div><strong>${label}</strong>${value}</div>`;
    
    // Simple check to differentiate between a typed SKU/code and natural language
    const looksLikeSku = (s) => {
        const v = String(s).trim();
        // Check for non-empty, no internal whitespace, and at least 3 alphanumeric/hyphen characters
        return !v || /\s/.test(v) ? false : /^[A-Za-z0-9\-]{3,}$/.test(v);
    };

    function toast(msg) {
        if (!panel) return;
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
        searchList.innerHTML = `<div class="muted" style="padding:10px 12px;">${esc(msg)}</div>`;
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

    // --- Drawer Control ---
    function openDrawer() {
        drawer?.setAttribute('data-open', 'true');
        drawer?.setAttribute('aria-hidden', 'false');
        launch?.setAttribute('aria-expanded', 'true');
    }

    function closeDrawer() {
        stopCamera();
        drawer?.removeAttribute('data-open');
        drawer?.setAttribute('aria-hidden', 'true');
        launch?.setAttribute('aria-expanded', 'false');
        hideSearchList(); 
    }

    function resetScan() {
        manual.value = '';
        lastValue = '';

        showSku(null);
        showLocations([], 0);
        hideSearchList();
        
        if (productImage) {
            productImage.src = '';
            productImage.style.display = 'none';
            productImage.alt = 'Product Image';
        }

        const sum = document.getElementById('scanSummary');
        if (sum) sum.textContent = '';
        const body = document.getElementById('scanLocBody');
        if (body) body.innerHTML = '';
        const wrap = document.getElementById('scanResults');
        if (wrap) wrap.hidden = true;

        if (bracket) {
            bracket.style.borderColor = 'rgba(13,202,240,.6)';
            setTimeout(() => (bracket.style.borderColor = 'rgba(13,202,240,.6)'), 300);
        }

        manual.focus();
        toast('Cleared');
    }

    // --- UI Display ---
    function showSku(sku) {
        // If sku is null, clear the results display
        if (!sku) { 
            resultsWrap.hidden = true; // Set to hidden if no SKU to display
            skuGrid.innerHTML = ''; 
            summary.textContent = ''; 
            locBody.innerHTML = ''; 
            
            // CLEAR IMAGE
            if (productImage) {
                productImage.src = '';
                productImage.style.display = 'none';
            }
            return; 
        }
    
    // IMAGE HANDLING: Set source and make the image visible
    if (productImage) {
        const imageUrl = esc(sku.image_url || ''); // Assuming your PHP proxy returns 'image_url'
        productImage.src = imageUrl;
        productImage.alt = `Image of ${esc(sku.desc)}`;
        productImage.style.display = imageUrl ? 'block' : 'none'; // Only show if URL exists
    }

    const active = (sku.status || '').toUpperCase() === 'ACTIVE';
    
    // SKU Grid: Now appears below the image placeholder
    skuGrid.innerHTML = [
        row('Scanned Code', esc(sku.scanned_code || '')),
        row('SKU #', esc(sku.sku_num || '')),
        row('Status', `<span class="badge ${active ? 'ok' : 'bad'}">${esc(sku.status || 'UNKNOWN')}</span>`),
        row('Description', esc(sku.desc || '')),
        row('SKU Qty (global)', String(Number(sku.sku_quantity ?? 0))),
    ].join('');
    
    resultsWrap.hidden = false;
}

    function showLocations(rows, total) {
        locBody.innerHTML = '';
        if (!rows || !rows.length) {
            summary.textContent = 'No stock on hand across locations.';
            return;
        }
        summary.textContent = `Locations with stock (Total on-hand: ${Number(total)})`;
        for (const r of rows) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td>${esc(r.location_label)}</td><td>${Number(r.on_hand)}</td><td class="muted">${esc(r.last_movement || '')}</td>`;
            locBody.appendChild(tr);
        }
    }

    // --- Camera Control ---
    async function startCamera() {
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
                        formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code', 'itf']
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

    function stopCamera() {
        scanning = false;
        if (raf) cancelAnimationFrame(raf);
        if (stream) for (const t of stream.getTracks()) t.stop();
        stream = null;
        startBtn.disabled = false;
        stopBtn.disabled = true;
    }

    async function scanLoop() {
        if (!scanning || !detector) return;
        try {
            if (cooldown > 0) { cooldown--; raf = requestAnimationFrame(scanLoop); return; }
            const codes = await detector.detect(video);
            if (codes && codes.length) {
                const value = (codes[0].rawValue || '').trim();
                if (value && value !== lastValue) {
                    lastValue = value;
                    bracket.style.borderColor = '#28a745';
                    manual.value = value;
                    lookup(); 
                    cooldown = 10;
                    setTimeout(() => bracket.style.borderColor = 'rgba(13,202,240,.6)', 800);
                }
            }
        } catch (_) { }
        raf = requestAnimationFrame(scanLoop);
    }

    // --- Lookup Logic ---

    /**
     * Executes the direct SKU lookup against scan_api.php.
     * @param {string} code The SKU/UPC to look up.
     */
    async function lookupSkuDirect(code) {
        try {
            const url = new URL(API_URL, window.location.origin);
            url.searchParams.set('code', code);
            hideSearchList(); 

            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();

            if (!data.ok) {
                showSku(null);
                showLocations([], 0);
                toast(data.error || 'Not found.');
                return;
            }

            showSku(data.sku);
            showLocations(data.locations || [], data.total_on_hand || 0);

        } catch (e) {
            console.error(e);
            toast('Lookup failed.');
        }
    }

    /**
     * Executes the AI-powered natural language search against sku_search_ai.php.
     * @param {string} q The search query (natural language).
     */
    async function lookupAi(q) {
        try {
            const url = new URL(AI_SEARCH_URL, window.location.origin);
            url.searchParams.set('q', q);
            showLoading(); 

            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            const data = await res.json();

            if (!data.ok) { showSearchMessage(data.error || 'Search failed.'); return; }

            if (!data.results || !data.results.length) {
                showSearchMessage('No matches.'); return;
            }

            searchList.innerHTML = data.results.map(r => `
                <div class="pick" data-sku="${esc(r.sku_num)}">
                    <div><strong>${esc(r.sku_num)}</strong> — ${esc(r.desc)}</div>
                    <div class="muted">On-hand: ${Number(r.on_hand)} | ${esc(r.status || '')}</div>
                </div>
            `).join('');
            searchList.hidden = false;

            searchList.querySelectorAll('.pick').forEach(el => {
                el.addEventListener('click', () => {
                    const sku = el.getAttribute('data-sku') || '';
                    manual.value = sku;
                    lookupSkuDirect(sku);
                });
            });
        } catch (e) {
            console.error(e);
            showSearchMessage('AI search failed.');
        }
    }

    /**
     * Main lookup function, decides between direct SKU or AI search.
     */
    async function lookup() {
        const q = manual.value.trim();
        if (!q) { toast('Enter or scan a code.'); return; }

        if (looksLikeSku(q)) {
            hideSearchList(); 
            return lookupSkuDirect(q);
        }

        return lookupAi(q);
    }

    // --- Event Listeners ---
    launch?.addEventListener('click', openDrawer);
    closeBtn?.addEventListener('click', closeDrawer);
    backdrop?.addEventListener('click', closeDrawer);
    resetBtn?.addEventListener('click', resetScan); 

    // Keyboard controls (ESC key logic)
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

    // Manual input triggers
    lookupBtn?.addEventListener('click', lookup);
    manual?.addEventListener('keydown', (e) => { if (e.key === 'Enter') lookup(); });

    // AI search debouncer (handles instant results when typing)
    manual?.addEventListener('input', () => {
        const q = manual.value.trim();
        
        if (!q) { hideSearchList(); return; }

        if (looksLikeSku(q)) { hideSearchList(); return; }

        clearTimeout(aiTimer);
        searchList && showLoading();
        aiTimer = setTimeout(() => lookupAi(q), 400);
    });

    startBtn?.addEventListener('click', startCamera);
    stopBtn?.addEventListener('click', stopCamera);
})();