(function() {
  const drawer = document.getElementById('ordersDrawer');
  const launch = document.getElementById('ordersLaunchBtn');
  const closeBtn = document.getElementById('ordersCloseBtn');
  const backdrop = document.getElementById('ordersBackdrop');

  const refreshBtn = document.getElementById('ordersRefreshBtn');
  const statusEl = document.getElementById('ordersStatus');

  const tableWrap = document.getElementById('ordersTableWrap');
  const tableHead = document.getElementById('ordersHead');
  const tableBody = document.getElementById('ordersBody');
  const emptyEl = document.getElementById('ordersEmpty');

  if (!drawer || !launch) {
    return;
  }

  const hasFeedUi = !!(refreshBtn && statusEl && tableWrap && tableHead && tableBody && emptyEl);

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

  function setOpen(isOpen) {
    drawer.setAttribute('data-open', isOpen ? 'true' : 'false');
    drawer.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    launch.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

    if (document.body) {
      if (isOpen) {
        document.body.setAttribute('data-orders-open', 'true');
      } else {
        document.body.removeAttribute('data-orders-open');
      }
    }
  }

  function setStatus(text, isError) {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.classList.toggle('orders-status--error', !!isError);
  }

  function buildUrl() {
    const base = (window.INVAPP_BASE || '').replace(/\/$/, '');
    const endpoint = `${base}/utils/orders_feed.php`;
    const url = new URL(endpoint, window.location.origin);

    url.searchParams.set('sheet', 'sales');

    return url.toString();
  }

  function parseCsv(text) {
    const rows = [];
    let row = [];
    let cell = '';
    let inQuotes = false;

    for (let i = 0; i < text.length; i++) {
      const ch = text[i];
      const next = text[i + 1];

      if (inQuotes) {
        if (ch === '"' && next === '"') {
          cell += '"';
          i++;
        } else if (ch === '"') {
          inQuotes = false;
        } else {
          cell += ch;
        }
      } else if (ch === '"') {
        inQuotes = true;
      } else if (ch === ',') {
        row.push(cell);
        cell = '';
      } else if (ch === '\n') {
        row.push(cell);
        rows.push(row);
        row = [];
        cell = '';
      } else if (ch === '\r') {
        // Ignore CR in CRLF.
      } else {
        cell += ch;
      }
    }

    row.push(cell);
    rows.push(row);
    return rows.filter((r) => r.some((v) => String(v || '').trim() !== ''));
  }

  function pickRowTone(cells, stateIdx) {
    const state = stateIdx >= 0 ? String(cells[stateIdx] || '').toLowerCase() : '';
    const full = cells.join(' ').toLowerCase();

    if (full.includes('ship today')) return 'orders-row--urgent';
    if (state.includes('printed,picked,packed')) return 'orders-row--packed';
    if (state.includes('printed,picked')) return 'orders-row--picked';
    if (state.includes('printed')) return 'orders-row--printed';
    return 'orders-row--base';
  }

  function renderGrid(headers, rows) {
    if (!tableWrap || !tableHead || !tableBody || !emptyEl) return;
    tableHead.innerHTML = '';
    tableBody.innerHTML = '';

    if (!headers.length) {
      tableWrap.hidden = true;
      emptyEl.hidden = false;
      emptyEl.textContent = 'No rows returned by the feed.';
      return;
    }

    const safeHeaders = headers.map((h, i) => (String(h || '').trim() || `column_${i + 1}`));
    const stateIdx = safeHeaders.findIndex((h) => h.trim().toLowerCase() === 'state');

    tableHead.innerHTML = `<tr>${safeHeaders.map((h) => `<th>${esc(h)}</th>`).join('')}</tr>`;

    const html = rows.map((cells) => {
      const rowClass = pickRowTone(cells, stateIdx);
      return `<tr class="${rowClass}">${safeHeaders.map((_, idx) => `<td>${esc(cells[idx] ?? '')}</td>`).join('')}</tr>`;
    }).join('');

    tableBody.innerHTML = html;
    tableWrap.hidden = false;
    emptyEl.hidden = true;
  }

  async function loadOrders() {
    if (!hasFeedUi || !refreshBtn || !emptyEl || !tableWrap) {
      return;
    }
    const url = buildUrl();

    setStatus('Loading...', false);
    refreshBtn.disabled = true;

    try {
      const response = await fetch(url, {
        method: 'GET',
        headers: {
          Accept: 'text/csv,text/plain,application/json,*/*'
        },
        credentials: 'same-origin'
      });

      if (!response.ok) {
        const errText = await response.text();
        if (errText && errText.trim().startsWith('{')) {
          const errJson = JSON.parse(errText);
          throw new Error(errJson.error || `Request failed (${response.status}).`);
        }
        throw new Error(`Request failed (${response.status}).`);
      }

      const text = await response.text();
      if (!text.trim()) {
        renderGrid([], []);
        setStatus('Loaded 0 row(s).', false);
        return;
      }

      const matrix = parseCsv(text);
      const headers = matrix.length ? matrix[0] : [];
      const rows = matrix.length > 1 ? matrix.slice(1) : [];
      renderGrid(headers, rows);
      setStatus(`Loaded ${rows.length} row(s).`, false);
    } catch (err) {
      setStatus(String(err.message || err), true);
      tableWrap.hidden = true;
      emptyEl.hidden = false;
      emptyEl.textContent = 'Unable to load open orders.';
    } finally {
      refreshBtn.disabled = false;
    }
  }

  launch.addEventListener('click', () => {
    console.log('Orders drawer launch clicked');
    const isOpen = drawer.getAttribute('data-open') === 'true';
    const nextOpen = !isOpen;
    setOpen(nextOpen);
    if (nextOpen && hasFeedUi) {
      loadOrders();
    }
  });

  closeBtn && closeBtn.addEventListener('click', () => setOpen(false));
  backdrop && backdrop.addEventListener('click', () => setOpen(false));

  document.addEventListener('keydown', (evt) => {
    if (evt.key === 'Escape' && drawer.getAttribute('data-open') === 'true') {
      setOpen(false);
    }
  });

  if (hasFeedUi && refreshBtn) {
    refreshBtn.addEventListener('click', loadOrders);
  }

  setOpen(false);
})();
