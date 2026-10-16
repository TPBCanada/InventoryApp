/* ---------- Typeahead on the main search (#q) -----------
 - Fetches SKUs and Location codes in parallel (server-side LIKE)
 - Click SKU -> fill input & submit
 - Click Location -> navigate to place.php with parsed parts
----------------------------------------------------------*/

(function () {
    const input = document.getElementById('q');
    const form = document.getElementById('searchForm');
    const box = document.getElementById('suggestBox');

    if (!input || !box || !form) return;

    let t = null, ticket = 0, sel = -1;

        // /js/sku_search.js

        window.SkuSearch = (() => {
            function init(options = {}) {
                const config = {
                    input: '#q',
                    form: null,
                    suggestBox: '#suggestBox',
                    endpoint: window.location.pathname,
                    debounce: 200,
                    minChars: 1,
                    onSelect: null,
                    ...options
                };

                const input = typeof config.input === 'string' ? document.querySelector(config.input) : config.input;
                const box = typeof config.suggestBox === 'string' ? document.querySelector(config.suggestBox) : config.suggestBox;
                const form = typeof config.form === 'string' ? document.querySelector(config.form) : config.form;

                if (!input) {
                    console.warn('SkuSearch: input not found');
                    return;
                }

                if (!box) {
                    console.warn('SkuSearch: suggestion box not found');
                    return;
                }

                let timer = null;
                let requestId = 0;
                let selectedIndex = -1;
                let currentItems = [];

                function hide() {
                    box.style.display = 'none';
                    box.innerHTML = '';
                    selectedIndex = -1;
                    currentItems = [];
                }

                function show() {
                    box.style.display = 'block';
                }

                function render(items) {
                    box.innerHTML = '';
                    selectedIndex = -1;
                    currentItems = items;

                    if (!Array.isArray(items) || items.length === 0) {
                        hide();
                        return;
                    }

                    const group = document.createElement('div');
                    group.className = 'suggest-group';

                    items.forEach((row, index) => {
                        const item = document.createElement('div');

                        item.className = 'suggest-item';
                        item.dataset.index = String(index);

                        const sku = document.createElement('span');
                        sku.className = 'sku-chip';
                        sku.textContent = row.sku_num || '';

                        item.appendChild(sku);

                        if (row.desc) {
                            const desc = document.createElement('span');
                            desc.className = 'sku-description';
                            desc.textContent = row.desc;
                            item.appendChild(desc);
                        }

                        item.addEventListener('mousedown', (event) => {
                            event.preventDefault();
                            choose(row);
                        });

                        group.appendChild(item);
                    });

                    box.appendChild(group);
                    show();
                }

                function choose(row) {
                    if (!row) return;

                    input.value = row.sku_num || '';
                    hide();

                    if (typeof config.onSelect === 'function') {
                        config.onSelect(row);
                        return;
                    }

                    if (form) {
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit();
                        } else {
                            form.submit();
                        }
                    }
                }

                async function fetchSuggestions(query, id) {
                    const url = new URL(config.endpoint, window.location.origin);

                    url.searchParams.set('suggest', '1');
                    url.searchParams.set('type', 'sku');
                    url.searchParams.set('q', query);

                    const response = await fetch(url.toString(), {
                        headers: { Accept: 'application/json' }
                    });

                    if (!response.ok) {
                        throw new Error(`SKU search returned HTTP ${response.status}`);
                    }

                    const data = await response.json();

                    // Ignore responses from older requests.
                    if (id !== requestId) {
                        return null;
                    }

                    return Array.isArray(data.items) ? data.items : [];
                }

                function search() {
                    clearTimeout(timer);

                    const query = input.value.trim();

                    if (query.length < config.minChars) {
                        hide();
                        return;
                    }

                    const id = ++requestId;

                    timer = setTimeout(async () => {
                        try {
                            const items = await fetchSuggestions(query, id);

                            if (id !== requestId || items === null) {
                                return;
                            }

                            render(items);
                        } catch (error) {
                            console.error('SkuSearch:', error);
                            hide();
                        }
                    }, config.debounce);
                }

                input.addEventListener('input', search);

                input.addEventListener('focus', () => {
                    if (input.value.trim().length >= config.minChars) {
                        search();
                    }
                });

                input.addEventListener('blur', () => {
                    setTimeout(hide, 150);
                });

                input.addEventListener('keydown', (event) => {
                    if (box.style.display === 'none') {
                        return;
                    }

                    const items = Array.from(
                        box.querySelectorAll('.suggest-item')
                    );

                    if (!items.length) {
                        return;
                    }

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();

                        selectedIndex =
                            (selectedIndex + 1) % items.length;

                        updateSelection(items);
                    }

                    else if (event.key === 'ArrowUp') {
                        event.preventDefault();

                        selectedIndex =
                            (selectedIndex - 1 + items.length) %
                            items.length;

                        updateSelection(items);
                    }

                    else if (event.key === 'Enter') {
                        if (selectedIndex >= 0) {
                            event.preventDefault();

                            choose(currentItems[selectedIndex]);
                        }
                    }

                    else if (event.key === 'Escape') {
                        hide();
                    }
                });

                function updateSelection(items) {
                    items.forEach((item, index) => {
                        item.classList.toggle(
                            'active',
                            index === selectedIndex
                        );
                    });

                    items[selectedIndex]?.scrollIntoView({
                        block: 'nearest'
                    });
                }

                // Optional API if the page wants to control it.
                return {
                    search,
                    hide,
                    destroy() {
                        clearTimeout(timer);
                        hide();
                    }
                };
            }

            return {
                init
            };
        })();

        function hide() { box.style.display = 'none'; box.innerHTML = ''; sel = -1; }
        function show() { box.style.display = 'block'; }

        function buildUrl(params) {
            const url = new URL(window.location.href);
            for (const [k, v] of Object.entries(params)) {
                if (v === null) url.searchParams.delete(k);
                else url.searchParams.set(k, String(v));
            }
            return url.toString();
        }

        function render(skus, locs) {
            box.innerHTML = '';
            sel = -1;
            const frag = document.createDocumentFragment();

            if (Array.isArray(skus) && skus.length) {
                const g = document.createElement('div'); g.className = 'suggest-group';
                const title = document.createElement('div'); title.className = 'suggest-title'; title.textContent = 'SKUs';
                g.appendChild(title);
                skus.forEach((r, i) => {
                    const it = document.createElement('div'); it.className = 'suggest-item'; it.dataset.kind = 'sku'; it.dataset.index = String(i);
                    const sku = document.createElement('span'); sku.className = 'sku-chip'; sku.textContent = r.sku_num;
                    it.appendChild(sku);
                    if (r.desc) { const d = document.createElement('span'); d.style.color = '#6c757d'; d.textContent = r.desc; it.appendChild(d); }
                    it.addEventListener('mousedown', (e) => { e.preventDefault(); choose({ kind: 'sku', value: r.sku_num }); });
                    g.appendChild(it);
                });
                frag.appendChild(g);
            }

            if (Array.isArray(locs) && locs.length) {
                const g = document.createElement('div'); g.className = 'suggest-group';
                const title = document.createElement('div'); title.className = 'suggest-title'; title.textContent = 'Locations';
                g.appendChild(title);
                locs.forEach((r, i) => {
                    const it = document.createElement('div'); it.className = 'suggest-item'; it.dataset.kind = 'loc'; it.dataset.index = String(i);
                    const code = document.createElement('span'); code.className = 'loc-chip'; code.textContent = r.code;
                    g.appendChild(it);
                    it.appendChild(code);
                    it.addEventListener('mousedown', (e) => { e.preventDefault(); choose({ kind: 'loc', value: r }); });
                });
                frag.appendChild(g);
            }

            if (!frag.children.length) { hide(); return; }
            box.appendChild(frag);
            show();
        }

        function choose(selObj) {
            if (!selObj) return;
            if (selObj.kind === 'sku') {
                input.value = selObj.value;
                hide();
                // When selecting a SKU from the search box, use the standard form submit (partial match)
                if (form.requestSubmit) form.requestSubmit(); else form.submit();
            } else if (selObj.kind === 'loc') {
                const r = selObj.value; // {code,row_code,bay_num,level_code,side}
                const params = {
                    row_code: r.row_code,
                    bay_num: r.bay_num,
                    level_code: r.level_code,
                    side: r.side
                };
                const qs = new URLSearchParams(params).toString();
                window.location.href = 'place.php?' + qs;
            }
        }

        async function fetchSuggest(kind, q, myTicket) {
            const url = buildUrl({ suggest: '1', type: kind, q, ajax: null, debug: null, path: null, sku_q: null });
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const data = await res.json();
            if (ticket !== myTicket) return null; // stale
            return Array.isArray(data.items) ? data.items : [];
        }

        function looksLikeLocation(q) {
            // heuristic: contains at least two dashes like "R10-01" or ends with "-F"/"-B"
            return /-/.test(q) || /^[A-Za-z]+\d{0,}-/.test(q);
        }

        input.addEventListener('input', () => {
            clearTimeout(t);
            const q = input.value.trim();
            if (!q) { hide(); return; }
            const my = ++ticket;
            t = setTimeout(async () => {
                try {
                    const wantLoc = looksLikeLocation(q);
                    const [skus, locs] = await Promise.all([
                        fetchSuggest('sku', q, my),
                        wantLoc ? fetchSuggest('loc', q, my) : Promise.resolve([])
                    ]);
                    if (ticket !== my) return; // stale
                    render(skus || [], locs || []);
                } catch (e) {
                    console.error(e); hide();
                }
            }, 150);
        });

        input.addEventListener('focus', () => {
            if (input.value.trim()) {
                input.dispatchEvent(new Event('input')); // trigger suggestions for current text
            } else {
                // Show some initial SKUs (empty q) - ask for empty suggest
                const my = ++ticket;
                Promise.all([fetchSuggest('sku', '', my), Promise.resolve([])]).then(([skus]) => {
                    if (ticket !== my) return;
                    render(skus || [], []);
                }).catch(() => hide());
            }
        });

        input.addEventListener('blur', () => setTimeout(hide, 150));

        // Keyboard navigation
        input.addEventListener('keydown', (e) => {
            if (box.style.display === 'none') return;
            const items = Array.from(box.querySelectorAll('.suggest-item'));
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                sel = (sel + 1) % items.length;
                items.forEach(el => el.classList.remove('active'));
                items[sel].classList.add('active');
                items[sel].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                sel = (sel - 1 + items.length) % items.length;
                items.forEach(el => el.classList.remove('active'));
                items[sel].classList.add('active');
                items[sel].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                if (sel >= 0) {
                    e.preventDefault();
                    const el = items[sel];
                    if (el.dataset.kind === 'sku') {
                        choose({ kind: 'sku', value: el.querySelector('.sku-chip')?.textContent || '' });
                    } else {
                        // rebuild loc object from text (we stored full record in list via closure on mousedown;
                        // for Enter we re-fetch the code and split)
                        const code = el.querySelector('.loc-chip')?.textContent || '';
                        const parts = code.split('-');
                        if (parts.length === 4) {
                            choose({ kind: 'loc', value: { code, row_code: parts[0], bay_num: parts[1], level_code: parts[2], side: parts[3] } });
                        }
                    }
                }
            } else if (e.key === 'Escape') {
                hide();
            }
        });
    })();