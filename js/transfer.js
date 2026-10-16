// Enhanced transfer workflow with multi-SKU selection
(function () {
    if (window.__transferInit) return;
    window.__transferInit = true;

    function readBoot() {
        const el = document.getElementById('transfer-boot');
        if (!el) return null;
        try {
            return JSON.parse(el.textContent || '{}');
        } catch (err) {
            console.error('transfer boot parse failed', err);
            return null;
        }
    }

    const BOOT = readBoot();
    if (!BOOT) return;

    const rows = BOOT.rows || [];
    const baysByRow = BOOT.baysByRow || {};
    const levelsByRow = BOOT.levelsByRow || {};
    const sidesByRow = BOOT.sidesByRow || {};
    const ajaxPath = BOOT.ajaxPath || window.location.pathname;
    const specialRows = (BOOT.specialRows || []).map(r => (r || '').toString().toUpperCase());
    const specialDefaults = BOOT.specialDefaults || { bay: '01', level: '11', side: 'F' };

    const selects = {
        s: {
            row: document.getElementById('s_row'),
            bay: document.getElementById('s_bay'),
            level: document.getElementById('s_lvl'),
            side: document.getElementById('s_side'),
        },
        d: {
            row: document.getElementById('d_row'),
            bay: document.getElementById('d_bay'),
            level: document.getElementById('d_lvl'),
            side: document.getElementById('d_side'),
        },
    };

    const hidden = {
        s: {
            row: document.getElementById('s_row_val'),
            bay: document.getElementById('s_bay_val'),
            level: document.getElementById('s_lvl_val'),
            side: document.getElementById('s_side_val'),
        },
        d: {
            row: document.getElementById('d_row_val'),
            bay: document.getElementById('d_bay_val'),
            level: document.getElementById('d_lvl_val'),
            side: document.getElementById('d_side_val'),
        },
    };

    const sourceTableBody = document.querySelector('#srcTable tbody');
    const destinationTableBody = document.querySelector('#dstTable tbody');
    const submitBtn = document.getElementById('approveBtn');
    const resetBtn = document.getElementById('resetBtn');
    const form = document.getElementById('transferForm');

    function isSpecial(row) {
        return row && specialRows.includes(row.toUpperCase());
    }

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[ch] || ch));
    }

    function fillSelect(select, values, placeholder) {
        if (!select) return;
        const opts = Array.isArray(values) ? values : [];
        select.innerHTML = '';
        const placeholderOpt = document.createElement('option');
        placeholderOpt.value = '';
        placeholderOpt.textContent = placeholder;
        placeholderOpt.disabled = true;
        placeholderOpt.selected = true;
        select.appendChild(placeholderOpt);
        opts.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v;
            opt.textContent = v;
            select.appendChild(opt);
        });
        select.disabled = opts.length === 0;
    }

    function disableSelect(select, label) {
        if (!select) return;
        select.innerHTML = '';
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = label;
        select.appendChild(opt);
        select.disabled = true;
        select.value = '';
    }

    function locationComplete(prefix) {
        const set = selects[prefix];
        if (!set || !set.row) return false;
        const rowVal = set.row.value;
        if (!rowVal) return false;
        if (isSpecial(rowVal)) return true;
        return Boolean(set.bay.value && set.level.value && set.side.value);
    }

    function updateHidden(prefix) {
        const set = selects[prefix];
        const target = hidden[prefix];
        if (!set || !target) return;
        const rowVal = set.row.value;
        target.row.value = rowVal || '';
        if (isSpecial(rowVal)) {
            target.bay.value = specialDefaults.bay || '01';
            target.level.value = (specialDefaults.level || '11').toUpperCase();
            target.side.value = (specialDefaults.side || 'F').toUpperCase();
        } else {
            target.bay.value = set.bay.value || '';
            target.level.value = set.level.value || '';
            target.side.value = set.side.value || '';
        }
    }

    function clearSource(message) {
        if (!sourceTableBody) return;
        sourceTableBody.innerHTML = `<tr><td class="muted" colspan="5">${escapeHtml(message)}</td></tr>`;
        const selectAll = document.getElementById('selectAllSkus');
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        updateSubmitState();
    }

    function clearDestination(message) {
        if (!destinationTableBody) return;
        destinationTableBody.innerHTML = `<tr><td class="muted" colspan="2">${escapeHtml(message)}</td></tr>`;
        const headLabel = document.getElementById('destinationLocationLabel');
        if (headLabel) headLabel.textContent = '';
        removePalletWarning();
    }

    function createLocationParams(prefix) {
        const set = selects[prefix];
        if (!set) return null;
        const row = set.row.value;
        if (!row) return null;
        if (isSpecial(row)) {
            return {
                row,
                bay: specialDefaults.bay || '01',
                lvl: specialDefaults.level || '11',
                side: specialDefaults.side || 'F'
            };
        }
        if (!(set.bay.value && set.level.value && set.side.value)) return null;
        return {
            row,
            bay: set.bay.value,
            lvl: set.level.value,
            side: set.side.value
        };
    }

    function maybeFetch(prefix) {
        updateHidden(prefix);
        if (prefix === 's') {
            clearSource('Pick a full location to load items.');
        } else {
            clearDestination('Pick a full location to load items.');
        }
        updateSubmitState();
        const params = createLocationParams(prefix);
        if (!params) return;
        fetchInventory(prefix, params);
    }

    async function fetchInventory(prefix, params) {
        const targetBody = prefix === 's' ? sourceTableBody : destinationTableBody;
        if (targetBody) {
            const cols = prefix === 's' ? 5 : 2;
            targetBody.innerHTML = `<tr><td class="muted" colspan="${cols}">Loading…</td></tr>`;
        }
        try {
            const url = new URL(ajaxPath, window.location.origin);
            url.searchParams.set('ajax', 'inv_by_loc');
            url.searchParams.set('row', params.row);
            url.searchParams.set('bay', params.bay);
            url.searchParams.set('lvl', params.lvl);
            url.searchParams.set('side', params.side);
            const res = await fetch(url.toString(), { credentials: 'same-origin' });
            const data = await res.json();
            if (!data.ok) {
                if (targetBody) {
                    const cols = prefix === 's' ? 5 : 2;
                    targetBody.innerHTML = `<tr><td class="text-danger" colspan="${cols}">${escapeHtml(data.error || 'Unable to load inventory')}</td></tr>`;
                }
                return;
            }
            if (prefix === 's') {
                renderSource(data.pallets || [], data.location_label || '');
            } else {
                renderDestination(data.items || [], data.location_label || '');
            }
        } catch (err) {
            console.error('transfer fetch failed', err);
            if (targetBody) {
                const cols = prefix === 's' ? 5 : 2;
                targetBody.innerHTML = `<tr><td class="text-danger" colspan="${cols}">Server error.</td></tr>`;
            }
        } finally {
            updateSubmitState();
        }
    }

    function renderSource(pallets) {
        if (!sourceTableBody) return;
        const flat = [];
        (pallets || []).forEach(pallet => {
            const palTag = pallet.pallet_tag || '';
            const palId = pallet.pallet_id || 0;
            (pallet.skus || []).forEach(sku => {
                flat.push({
                    pallet_id: palId,
                    pallet_tag: palTag,
                    sku_id: sku.sku_id,
                    sku_num: sku.sku_num,
                    sku_desc: sku.sku_desc,
                    quantity: sku.quantity,
                });
            });
        });
        if (!flat.length) {
            clearSource('No inventory at this location.');
            return;
        }
        const rowsHtml = flat.map(item => {
            const max = Number(item.quantity) || 0;
            const skuNum = item.sku_num || '';
            const skuDesc = item.sku_desc || '';
            const skuId = item.sku_id || 0;
            const palletId = item.pallet_id || 0;
            const palletTag = item.pallet_tag || '';
            const rowKey = `${palletId}:${skuId}`;
            return `
            <tr>
                <td class="text-center">
                    <input type="checkbox" class="sku-checkbox" name="selected_items[]" value="${palletId}:${skuId}">
                </td>
                <td>
                    <div>${escapeHtml(skuNum)}</div>
                    <div class="text-muted small">${escapeHtml(palletTag)}</div>
                </td>
                <td>${escapeHtml(skuDesc)}</td>
                <td class="text-end">${max}</td>
                <td>
                    <input type="number" class="qty-input input" name="selected_qty[${palletId}][${skuId}]" min="1" ${max ? `max="${max}"` : ''} value="${max || 1}" disabled>
                </td>
            </tr>`;
        }).join('');
        sourceTableBody.innerHTML = rowsHtml;
        bindSourceRowEvents();
        updateSubmitState();
    }

    function syncRowSelection(cb) {
        const row = cb.closest('tr');
        const qtyInput = row ? row.querySelector('.qty-input') : null;
        if (!qtyInput) return;
        qtyInput.disabled = !cb.checked;
        if (cb.checked && (!qtyInput.value || Number(qtyInput.value) <= 0)) {
            qtyInput.value = qtyInput.getAttribute('max') || '1';
        }
    }

    function renderDestination(items, label) {
        if (!destinationTableBody) return;
        const headLabel = document.getElementById('destinationLocationLabel');
        if (headLabel) {
            headLabel.textContent = label || '';
        }
        if (!items.length) {
            clearDestination('No inventory at this location.');
            return;
        }
        destinationTableBody.innerHTML = items.map(item => `
            <tr>
                <td>${escapeHtml(item.sku_num || '')}</td>
                <td>${Number(item.quantity) || 0}</td>
            </tr>
        `).join('');
        removePalletWarning();
    }

    function bindSourceRowEvents() {
        const checkboxes = sourceTableBody.querySelectorAll('.sku-checkbox');
        checkboxes.forEach(cb => {
            cb.removeEventListener('change', cb.__onChange || (() => {}));
            const handler = () => {
                syncRowSelection(cb);
                updateSelectAllState();
                updateSubmitState();
            };
            cb.__onChange = handler;
            cb.addEventListener('change', handler);
            syncRowSelection(cb);
        });

        const qtyInputs = sourceTableBody.querySelectorAll('.qty-input');
        qtyInputs.forEach(input => {
            input.removeEventListener('input', input.__onInput || (() => {}));
            const handler = () => {
                let val = parseInt(input.value || '0', 10);
                const max = parseInt(input.getAttribute('max') || '0', 10);
                if (!Number.isFinite(val) || val < 1) val = 1;
                if (max && val > max) val = max;
                input.value = val;
                updateSubmitState();
            };
            input.__onInput = handler;
            input.addEventListener('input', handler);
        });

        const selectAll = document.getElementById('selectAllSkus');
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
            selectAll.onchange = () => {
                checkboxes.forEach(cb => {
                    cb.checked = selectAll.checked;
                    syncRowSelection(cb);
                });
                updateSelectAllState();
                updateSubmitState();
            };
        }

        updateSelectAllState();
    }

    function updateSubmitState() {
        if (!submitBtn) return;
        const hasSource = locationComplete('s');
        const hasDestination = locationComplete('d');
        // Count checked items by name to avoid missing rows if markup changes.
        const scope = form || document;
        const selectedCount = scope.querySelectorAll('input[name="selected_items[]"]:checked').length;
        const shouldDisable = !(hasSource && hasDestination && selectedCount > 0);
        submitBtn.disabled = shouldDisable;
        console.log('[transfer] updateSubmitState', { hasSource, hasDestination, selectedCount, disabled: shouldDisable });
    }

    function updateSelectAllState() {
        const selectAll = document.getElementById('selectAllSkus');
        if (!selectAll || !sourceTableBody) return;
        const boxes = sourceTableBody.querySelectorAll('.sku-checkbox');
        const checked = sourceTableBody.querySelectorAll('.sku-checkbox:checked');
        selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }

    function initSelectors(prefix) {
        const set = selects[prefix];
        if (!set || !set.row) return;

        fillSelect(set.row, rows, 'Select row');
        disableSelect(set.bay, 'Select bay');
        disableSelect(set.level, 'Select level');
        disableSelect(set.side, 'Select side');

        set.row.addEventListener('change', () => {
            const rowVal = set.row.value;
            if (!rowVal) {
                disableSelect(set.bay, 'Select bay');
                disableSelect(set.level, 'Select level');
                disableSelect(set.side, 'Select side');
                updateHidden(prefix);
                maybeFetch(prefix);
                return;
            }
            if (isSpecial(rowVal)) {
                disableSelect(set.bay, 'N/A');
                disableSelect(set.level, 'N/A');
                disableSelect(set.side, 'N/A');
                updateHidden(prefix);
                maybeFetch(prefix);
                return;
            }
            fillSelect(set.bay, baysByRow[rowVal] || [], 'Select bay');
            disableSelect(set.level, 'Select level');
            disableSelect(set.side, 'Select side');
            updateHidden(prefix);
            maybeFetch(prefix);
        });

        set.bay.addEventListener('change', () => {
            const rowVal = set.row.value;
            const bayVal = set.bay.value;
            const levels = rowVal && bayVal && levelsByRow[rowVal] ? levelsByRow[rowVal][bayVal] || [] : [];
            fillSelect(set.level, levels, 'Select level');
            disableSelect(set.side, 'Select side');
            updateHidden(prefix);
            maybeFetch(prefix);
        });

        set.level.addEventListener('change', () => {
            const rowVal = set.row.value;
            const bayVal = set.bay.value;
            const lvlVal = set.level.value;
            const sides = rowVal && bayVal && lvlVal && sidesByRow[rowVal] && sidesByRow[rowVal][bayVal]
                ? sidesByRow[rowVal][bayVal][lvlVal] || []
                : [];
            fillSelect(set.side, sides, 'Select side');
            updateHidden(prefix);
            maybeFetch(prefix);
        });

        set.side.addEventListener('change', () => {
            updateHidden(prefix);
            maybeFetch(prefix);
        });
    }

    initSelectors('s');
    initSelectors('d');

    if (resetBtn && form) {
        form.addEventListener('reset', () => {
            setTimeout(() => {
                if (selects.s) {
                    fillSelect(selects.s.row, rows, 'Select row');
                    disableSelect(selects.s.bay, 'Select bay');
                    disableSelect(selects.s.level, 'Select level');
                    disableSelect(selects.s.side, 'Select side');
                }
                if (selects.d) {
                    fillSelect(selects.d.row, rows, 'Select row');
                    disableSelect(selects.d.bay, 'Select bay');
                    disableSelect(selects.d.level, 'Select level');
                    disableSelect(selects.d.side, 'Select side');
                }
                clearSource('Pick a full location to load items.');
                clearDestination('Pick a full location to load items.');
                updateHidden('s');
                updateHidden('d');
                updateSubmitState();
            }, 0);
        });
    }

    clearSource('Pick a full location to load items.');
    clearDestination('Pick a full location to load items.');
    updateSubmitState();

    // Remove destination pallet warning text if present in the layout.
    function removePalletWarning() {
        const container = document.getElementById('destinationInventory') || destinationTableBody?.parentElement;
        if (!container) return;
        const nodes = container.querySelectorAll('*');
        nodes.forEach(el => {
            const text = (el.textContent || '').trim().toLowerCase();
            if (text.includes('no pallets available to receive inventory')) {
                el.remove();
            }
        });
    }
})();
