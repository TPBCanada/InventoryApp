// /dev/js/scan-drawer.js (or /assets/js/scan-drawer.js if deployed)
(() => {
  window.__scanDrawerBooted = true;

  // --- Configuration ---
  // API_URL and AI_SEARCH_URL construction is consolidated and correct.
  const API_URL = (window.INVAPP_BASE || '') + '/scan_api.php';
  const LOC_API_URL = (window.INVAPP_BASE || '') + '/loc_api.php';
  const AI_SEARCH_URL = (window.INVAPP_BASE || '') + '/sku_search_ai.php';
  const LOC_FUZZY_URL = (window.INVAPP_BASE || '') + '/loc_search_fuzzy.php';

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
  const palletHeading = document.getElementById('scanPalletHeading');
  const body = document.body;

  // --- State Variables ---
  let stream = null, detector = null, scanning = false, raf = null, lastValue = '', cooldown = 0;
  let aiTimer = null;
  let currentSkuNum = '';

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

  // --- NEW Helper: Decide if it's a Location Code ---
  const looksLikeLocation = (s) => {
    // This regex matches the standard four-part location code pattern: 
    // [Text/Number]-[Number]-[Number]-[Text] (e.g., FLOOR-01-11-F)
    const code = String(s).trim().toUpperCase();
    return /^[A-Z0-9]+-\d+-\d+-[A-Z]$/.test(code);
  };

  /**
 * Executes the fuzzy location search against loc_search_fuzzy.php.
 * @param {string} q The partial location search query.
 */
  async function lookupLocationFuzzy(q) {
    console.log('--- lookupLocationFuzzy initiated for:', q);

    try {
      const url = new URL(LOC_FUZZY_URL, window.location.origin);
      url.searchParams.set('q', q);
      showLoading();

      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const data = await res.json();

      if (!data.ok) {
        showSearchMessage(data.error || 'Location search failed.');
        return;
      }

      if (!data.results || !data.results.length) {
        showSearchMessage('No location matches.');
        return;
      }

      // Display suggestions in the search list area
      searchList.innerHTML = data.results.map(r => `
            <div class="pick location-pick" data-loc="${esc(r.label)}">
                <strong>${esc(r.label)}</strong>
            </div>
        `).join('');
      searchList.hidden = false;

      // Add event listener to each suggested item
      searchList.querySelectorAll('.location-pick').forEach(el => {
        el.addEventListener('click', () => {
          const locLabel = el.getAttribute('data-loc') || '';
          manual.value = locLabel;

          // Clicking a suggestion triggers the direct lookup (which shows action links)
          lookupLocationDirect(locLabel);
        });
      });

    } catch (e) {
      console.error('*** Location Fuzzy Lookup FAILED in JS:', e);
      showSearchMessage('Location fuzzy search failed.');
    }
  }

  /**
 * Main lookup function: Routes the scanned code based on its format.
 */
  async function lookup() {
    const q = manual.value.trim();
    console.log('--- LOOKUP initiated for:', q);
    if (!q) { toast('Enter or scan a code.'); return; }

    // Clear any previous fuzzy search list (e.g., AI results)
    hideSearchList();

    // 1. Check if the code looks like a COMPLETE Location (e.g., FLOOR-01-11-F)
    if (looksLikeLocation(q)) {
      console.log('--- Routing to Location Direct Lookup ---');
      // Calls loc_api.php and shows action links
      return lookupLocationDirect(q);
    }

    // 2. Check if the code looks like a COMPLETE SKU/Barcode (e.g., 12345678)
    if (looksLikeSku(q)) {
      console.log('--- Routing to SKU Direct Lookup ---');
      // Calls scan_api.php for inventory lookup
      return lookupSkuDirect(q);
    }

    // 3. FAILSAFE: If it's short, fuzzy, or doesn't match a complete pattern, 
    // force the user to use the suggestion list or complete the code.
    console.log('--- Routing Failed: Invalid/Incomplete Code ---');
    showError(`Please enter a complete location code (e.g., FLOOR-01-01-F) or a complete SKU/Barcode. Use suggestions for partial searches.`);
  }

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

  // Parse JSON safely and surface the raw text when the server returns HTML (e.g., 404/login page)
  async function readJsonResponse(res, context) {
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch (err) {
      console.error(`[scan] ${context} expected JSON but got:`, { status: res.status, text });
      throw new Error(`non-json:${context}`);
    }
  }

  // --- Drawer Control ---
  function setDrawerState(isOpen) {
    if (!drawer) return;
    drawer.setAttribute('data-open', isOpen ? 'true' : 'false');
    drawer.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    launch?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    if (body) {
      if (isOpen) {
        body.setAttribute('data-scan-open', 'true');
      } else {
        body.removeAttribute('data-scan-open');
      }
    }
  }

  if (drawer && !drawer.hasAttribute('data-open')) {
    setDrawerState(false);
  }

  function openDrawer() {
    const isOpen = drawer?.getAttribute('data-open') === 'true';
    if (isOpen) {
      closeDrawer();
      return;
    }

    setDrawerState(true);
    hideSearchList();
  }

  function closeDrawer() {
    stopCamera();
    setDrawerState(false);
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
      currentSkuNum = '';
      resultsWrap.hidden = true; // Set to hidden if no SKU to display
      skuGrid.innerHTML = '';
      summary.textContent = '';
      locBody.innerHTML = '';
      if (palletHeading) palletHeading.hidden = true;

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

    currentSkuNum = sku.sku_num || '';
    const active = (sku.status || '').toUpperCase() === 'ACTIVE';

    const baseUrl = window.INVAPP_BASE || '';
    const skuLink = currentSkuNum
      ? `<a href="${baseUrl}/invSku.php?q=${encodeURIComponent(currentSkuNum)}" target="_blank" rel="noopener">${esc(currentSkuNum)}</a>`
      : 'N/A';

    skuGrid.innerHTML = [
      row('Scanned Code', esc(sku.scanned_code || '')),
      row('SKU #', skuLink),
      row('Status', `<span class="badge ${active ? 'ok' : 'bad'}">${esc(sku.status || 'UNKNOWN')}</span>`),
      row('Description', esc(sku.desc || '')),
      row('SKU Qty (global)', String(Number(sku.sku_quantity ?? 0))),
      (sku._ai_derived_location ? row('AI Location Target', esc(sku._ai_derived_location)) : ''),
    ].join('');

    resultsWrap.hidden = false;
  }

  function buildLocationUrl(row) {
    if (!row || !row.row_code) return '';
    const base = (window.INVAPP_BASE || '') + '/invLoc.php';
    const params = new URLSearchParams({
      row_code: row.row_code || '',
      bay_num: row.bay_num || '',
      level_code: row.level_code || '',
      side: row.side || ''
    });
    const skuParam = (currentSkuNum || '').trim();
    if (skuParam) {
      params.set('return', `invSku.php?q=${encodeURIComponent(skuParam)}`);
    }
    return `${base}?${params.toString()}`;
  }

  function buildPalletUrl(row) {
    if (!row || !row.pallet_tag || !row.location_label) return '';

    const base = (window.INVAPP_BASE || '') + '/pallet.php';

    // Create the required search string: "LOCATION-LABEL — PALLET-TAG"
    const searchString = `${row.location_label} — ${row.pallet_tag}`;

    const params = new URLSearchParams({
      manage_pallet_search: searchString,
      manage_pallet_id: row.pallet_id || 0 // Use the actual pallet_id if available, otherwise 0
    });

    return `${base}?${params.toString()}`;
  }

  function showLocations(rows, total) {
    locBody.innerHTML = '';
    if (!rows || !rows.length) {
      summary.textContent = 'No stock on hand across locations.';
      if (palletHeading) palletHeading.hidden = false;
      return;
    }
    summary.textContent = `Locations with stock (Total on-hand: ${Number(total)})`;
    if (palletHeading) palletHeading.hidden = false;
    for (const r of rows) {
      const tr = document.createElement('tr');
      const url = buildLocationUrl(r);
      const label = esc(r.location_label || '');
      const anchor = url ? `<a href="${esc(url)}" rel="noopener">${label}</a>` : label;
      const palletTag = esc(r.pallet_tag || '');
      const palletUrl = buildPalletUrl(r);
      const palletAnchor = palletUrl
        ? `<a href="${esc(palletUrl)}" target="_blank" rel="noopener">${palletTag}</a>`
        : palletTag;
      tr.innerHTML = `<td>${anchor}</td><td>${palletAnchor}</td><td>${Number(r.on_hand)}</td><td class="muted">${esc(r.last_movement || '')}</td>`; locBody.appendChild(tr);
    }
  }

  // --- Camera Control ---
  async function startCamera() {
    try {
      if (!video) {
        console.error('No #scanVideo element found.');
        toast('Scanner video element missing.');
        return;
      }

      stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 150 }, height: { ideal: 150 } },
        audio: false
      });

      video.srcObject = stream;

      try {
        await video.play();   // 🔑 ensure video is actually playing
      } catch (e) {
        console.error('Video play() failed:', e);
      }

      if (startBtn) startBtn.disabled = true;
      if (stopBtn) stopBtn.disabled = false;
      scanning = true;

      if ('BarcodeDetector' in window) {
        try {
          detector = new window.BarcodeDetector({
            formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'qr_code', 'itf']
          });
        } catch {
          detector = new window.BarcodeDetector();
        }
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
    console.debug('--- SCAN LOOP CYCLE STARTING ---');
    try {
      // The cooldown is now only for preventing *redundant* lookups of the *same* code.
      if (cooldown > 0) {
        cooldown--;
        raf = requestAnimationFrame(scanLoop);
        return;
      }

      const codes = await detector.detect(video);
      console.log('Detected codes (raw output from detector):', codes);

      if (codes && codes.length) {
        const value = (codes[0].rawValue || '').trim();
        const currentFormat = codes[0].format || 'Unknown';

        // 🚨 LOG: Clearly show the sanitized value that is about to be processed
        console.warn(`--- BARCODE CAPTURED: "${value}" (Format: ${currentFormat}) ---`);

        // Check if the code is new/different from the last one scanned
        if (value && value !== lastValue) {
          lastValue = value;

          // --- ACTION: Trigger lookup for the new code ---
          bracket.style.borderColor = '#28a745';
          manual.value = value;
          console.log('Scanned code (routed to lookup):', value);

          lookup(); // 🚀 Keep lookup() to process the new code

          // Set a short cooldown period (e.g., 20 frames or ~330ms at 60fps) 
          // to ignore the current code temporarily, allowing rapid *new* scans.
          cooldown = 2;

          // NOTE: stopCamera() has been removed to allow continuous scanning.
          setTimeout(() => bracket.style.borderColor = 'rgba(13,202,240,.6)', 800);
        }
      }
    } catch (e) {
      // Log errors if the detector fails to prevent silent failures
      console.error('Barcode detector error:', e);
    }
    raf = requestAnimationFrame(scanLoop);
  }

  // --- Lookup Logic ---

  /**
   * Executes the direct SKU lookup against scan_api.php.
   * @param {string} code The SKU/UPC to look up.
   */
  async function lookupSkuDirect(code) {
    console.log('--- lookupSkuDirect called with code:', code);

    // NOTE: You must have a showLoading() function defined and called here
    // for a better user experience while the API is working.

    try {
      const url = new URL(API_URL, window.location.origin);
      url.searchParams.set('code', code);
      hideSearchList();

      // 1. Fetch the data
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });

      // 2. Parse the JSON response
      const data = await readJsonResponse(res, 'lookupSkuDirect');

      // Console log for successful retrieval (Debug)
      console.log('API SUCCESS: Received Data:', data);

      // 3. Process the response (The missing block!)
      if (data.ok) {
        // Data successfully retrieved and structured by PHP

        // NOTE: Add a try...catch block here to catch display errors
        try {
          showSku(data.sku);
          showLocations(data.locations || [], data.total_on_hand || 0);
          // NOTE: Ensure your HTML container for results is unhidden here
          if (resultsWrap) resultsWrap.hidden = false;
          toast('SKU Found: ' + data.sku.sku_num);

        } catch (displayError) {
          // Catches errors that happen during DOM manipulation (e.g., showSku/showLocations)
          console.error('*** JS DISPLAY FAILED:', displayError);
          toast('Error displaying results. Check console.');
        }

      } else {
        // PHP returned valid JSON but with an error status (e.g., "SKU not found")
        const err = data.detail && data.error
          ? `${data.error} (${data.detail})`
          : (data.error || 'Unknown error');
        showError(err);
      }

    } catch (e) {
      // Catches Network errors or JSON parsing errors (server crash/HTML response)
      console.error('*** API Lookup FAILED in JS:', e);
      console.log('Check Network tab for scan_api.php status or JSON syntax error.');
      const msg = (e && e.message) ? String(e.message) : '';
      if (msg.startsWith('non-json')) {
        toast('Lookup failed: server returned non-JSON (possibly 404/auth).');
      } else {
        toast('Lookup failed due to system error.');
      }
    } finally {
      // NOTE: You should hide the loading indicator here if it was shown
      // hideLoading(); 
    }
  }

  /**
 * Executes the direct Location lookup against loc_api.php.
 * @param {string} code The location code (e.g., FLOOR-01-11-F) to look up.
 */
  async function lookupLocationDirect(code) {
    console.log('--- lookupLocationDirect called with code:', code);

    try {
      const url = new URL(LOC_API_URL, window.location.origin);
      url.searchParams.set('code', code);
      showLoading(); // Show loading indicator

      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const data = await readJsonResponse(res, 'lookupLocationDirect');

      if (data.ok && data.location) {
        const loc = data.location;

        // Generate the action links
        const base = window.INVAPP_BASE || '';
        const links = [
          `<a href="${base}/place.php?row_code=${encodeURIComponent(loc.row_code)}&bay_num=${encodeURIComponent(loc.bay_num)}&level_code=${encodeURIComponent(loc.level_code)}&side=${encodeURIComponent(loc.side)}"
      class="btn" target="_blank" rel="noopener">Add Inventory</a>`,

          `<a href="${base}/take.php?row_code=${encodeURIComponent(loc.row_code)}&bay_num=${encodeURIComponent(loc.bay_num)}&level_code=${encodeURIComponent(loc.level_code)}&side=${encodeURIComponent(loc.side)}"
      class="btn" target="_blank" rel="noopener">Remove Inventory</a>`,

          `<a href="${base}/invLoc.php?row_code=${encodeURIComponent(loc.row_code)}&bay_num=${encodeURIComponent(loc.bay_num)}&level_code=${encodeURIComponent(loc.level_code)}&side=${encodeURIComponent(loc.side)}"
      class="btn" target="_blank" rel="noopener">View Inventory at Location</a>`
        ].join('');

        // Display the results and action links in the search list area
        if (searchList) {
          searchList.hidden = false;
          searchList.innerHTML = `
                    <div style="padding: 10px 12px;">
                        <h4>Location Found: ${esc(loc.label)}</h4>
                        <p class="muted">Select an action:</p>
                        <div class="location-actions" style="display:flex; flex-direction:column; gap:8px;">
                            ${links}
                        </div>
                    </div>
                `;
        }

        // Clear other result areas
        showSku(null);
        showLocations([], 0);
        toast('Location Found: ' + loc.label);

      } else {
        showError(data.error || `Location lookup failed for: ${code}`);
      }
    } catch (e) {
      console.error('*** Location Lookup FAILED in JS:', e);
      const msg = (e && e.message) ? String(e.message) : '';
      if (msg.startsWith('non-json')) {
        toast('Location lookup failed: non-JSON response (check path/auth).');
      } else {
        toast('Location lookup failed due to system error.');
      }
    } finally {
      // You might want to hide the loading indicator here if you use one
      // hideLoading(); 
    }
  }

  /**
  * Executes the AI-powered natural language search against sku_search_ai.php.
  * @param {string} q The search query (natural language).
  **/
  async function lookupAi(q) {
    try {
      const url = new URL(AI_SEARCH_URL, window.location.origin);
      url.searchParams.set('q', q);
      showLoading();

      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const data = await readJsonResponse(res, 'lookupAi');

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
      const msg = (e && e.message) ? String(e.message) : '';
      if (msg.startsWith('non-json')) {
        showSearchMessage('AI search returned unexpected (non-JSON) response.');
      } else {
        showSearchMessage('AI search failed.');
      }
    }
  }

  /**
   * Main lookup function: Sends all lookups (manual entry, barcode, button click)
   * to the powerful scan_api.php endpoint, which now handles AI interpretation.
   *
  async function lookup() {
    const q = manual.value.trim();
    console.log('--- LOOKUP initiated for:', q); // <--- ADD THIS
    if (!q) { toast('Enter or scan a code.'); return; }

    // If the user has a fuzzy search list open, pressing lookup should clear it
    // and run the final direct lookup against the powerful API.
    hideSearchList();
    return lookupSkuDirect(q);
  }*/

  // --- Event Listeners ---
  launch?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    openDrawer();
  });
  closeBtn?.addEventListener('click', openDrawer);
  backdrop?.addEventListener('click', openDrawer);
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

  // AI/Location Search debouncer (handles instant results when typing fuzzy queries)
  manual?.addEventListener('input', () => {
    const q = manual.value.trim();

    if (!q) {
      clearTimeout(aiTimer);
      hideSearchList();
      return;
    }

    // --- PRIORITIZE DIRECT LOOKUP PATTERNS ---
    // If it looks like a COMPLETE Location Code or a clean SKU/Barcode, 
    // we stop the suggestion timer. Hitting 'Enter' will run the direct lookup.
    // NOTE: We MUST check this first to avoid running fuzzy search on a complete code.
    if (looksLikeLocation(q) || looksLikeSku(q)) {
      clearTimeout(aiTimer);
      hideSearchList();
      return;
    }

    // --- FUZZY SEARCH LOGIC ---
    clearTimeout(aiTimer);

    // Check for partial location query (starts with A-Z0-9 and has at least one hyphen)
    // This is a heuristic to guess the user is typing a location code (e.g., 'F-01-')
    const qUpper = q.toUpperCase();
    const looksLikePartialLoc =
      /^[A-Z0-9]{3,}/.test(qUpper) || // e.g., "R10" (3+ chars, no hyphen yet)
      /^[A-Z0-9].*?-/.test(qUpper);   // e.g., "R10-" or "FLOOR-"



    // Use the Location Fuzzy Search if it looks like a partial location
    if (looksLikePartialLoc) {
      searchList && showLoading();
      aiTimer = setTimeout(() => lookupLocationFuzzy(q), 400); // New Location Search
      return;
    }

    // Otherwise, assume it's a fuzzy SKU query and route to AI search
    searchList && showLoading();
    aiTimer = setTimeout(() => lookupAi(q), 400); // Existing SKU Search
  });

  startBtn?.addEventListener('click', startCamera);
  stopBtn?.addEventListener('click', stopCamera);
})();
