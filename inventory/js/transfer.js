// Enhanced transfer workflow with multi-SKU selection
(function () {
    function init() {
        if (window.__transferInit) {
            console.warn('[transfer] init skipped; already initialized by another script.');
            return;
        }
        window.__transferInit = true;

        console.log('[transfer] script executing (inventory version)');

    function wrapWithLog(fn, name) {
        return function (...args) {
            console.log(`[transfer] calling ${name}`, ...args);
            try {
                const result = fn.apply(this, args);
                console.log(`[transfer] ${name} ->`, result);
                return result;
            } catch (err) {
                console.error(`[transfer] ${name} threw`, err);
                throw err;
            }
        };
    }

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
    readBoot = wrapWithLog(readBoot, 'readBoot');

    const BOOT = readBoot();
    console.log('[transfer] init starting.', BOOT);
    if (!BOOT) return;

    const rows = BOOT.rows || [];
    const baysByRow = BOOT.baysByRow || {};
    const levelsByRow = BOOT.levelsByRow || {};
    const sidesByRow = BOOT.sidesByRow || {};
    console.log('[transfer] rows:', rows);
    console.log('[transfer] baysByRow:', baysByRow);
    console.log('[transfer] levelsByRow:', levelsByRow);
    console.log('[transfer] sidesByRow:', sidesByRow);
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
    const selectAllBtn = document.getElementById('selectAllSourceBtn');
    const confirmModal = document.getElementById('transferConfirmModal');
    const confirmMessage = document.getElementById('transferConfirmMessage');
    const confirmYesBtn = document.getElementById('transferConfirmYes');
    const confirmNoBtn = document.getElementById('transferConfirmNo');
    console.log('[transfer] table bodies:', { sourceTableBody, destinationTableBody });
    console.log('[transfer] modal refs:', { confirmModal, confirmMessage, confirmYesBtn, confirmNoBtn });
    console.log('[transfer] table bodies:', { sourceTableBody, destinationTableBody });

    function isSpecial(row) {
        return row && specialRows.includes(row.toUpperCase());
    }
    isSpecial = wrapWithLog(isSpecial, 'isSpecial');

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[ch] || ch));
    }
    escapeHtml = wrapWithLog(escapeHtml, 'escapeHtml');

    function getExpiryBadge(expiryDate) {
        const raw = (expiryDate || '').toString().trim();
        if (!raw) return '';
        const parts = raw.split('-').map(n => parseInt(n, 10));
        if (parts.length !== 3 || parts.some(n => !Number.isFinite(n))) return '';
        const [year, month, day] = parts;
        const expUtc = Date.UTC(year, month - 1, day);
        const today = new Date();
        const todayUtc = Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate());
        const diffDays = Math.floor((expUtc - todayUtc) / (1000 * 60 * 60 * 24));
        let badgeClass = 'badge lot-exp-badge--ok';
        if (diffDays <= 90) {
            badgeClass = 'badge lot-exp-badge--danger';
        } else if (diffDays <= 180) {
            badgeClass = 'badge lot-exp-badge--warn';
        }
        const safeDate = escapeHtml(raw);
        return ` <span class="${badgeClass} ms-2 lot-exp-badge" title="Lot expiry ${safeDate}">Lot exp: ${safeDate}</span>`;
    }
    getExpiryBadge = wrapWithLog(getExpiryBadge, 'getExpiryBadge');

    function renderDescription(desc, expiryDate) {
        return `${escapeHtml(desc || '')}${getExpiryBadge(expiryDate)}`;
    }
    renderDescription = wrapWithLog(renderDescription, 'renderDescription');

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
    fillSelect = wrapWithLog(fillSelect, 'fillSelect');

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
    disableSelect = wrapWithLog(disableSelect, 'disableSelect');

    function locationComplete(prefix) {
        const set = selects[prefix];
        if (!set || !set.row) return false;
        const rowVal = set.row.value;
        if (!rowVal) return false;
        if (isSpecial(rowVal)) return true;
        return Boolean(set.bay.value && set.level.value && set.side.value);
    }
    locationComplete = wrapWithLog(locationComplete, 'locationComplete');

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
    updateHidden = wrapWithLog(updateHidden, 'updateHidden');

    function clearSource(message) {
        if (!sourceTableBody) return;
        sourceTableBody.innerHTML = `<tr><td class="muted" colspan="6">${escapeHtml(message)}</td></tr>`;
        const selectAll = document.getElementById('selectAllSkus');
        if (selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        setSelectAllButtonState(false);
        updateSubmitState();
    }
    clearSource = wrapWithLog(clearSource, 'clearSource');

    function clearDestination(message) {
        if (!destinationTableBody) return;
        destinationTableBody.innerHTML = `<tr><td class="muted" colspan="4">${escapeHtml(message)}</td></tr>`;
        const headLabel = document.getElementById('destinationLocationLabel');
        if (headLabel) headLabel.textContent = '';
        removeDestinationWarning();
    }
    clearDestination = wrapWithLog(clearDestination, 'clearDestination');

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
    createLocationParams = wrapWithLog(createLocationParams, 'createLocationParams');

    function maybeFetch(prefix) {
        updateHidden(prefix);
        if (prefix === 's') {
            clearSource('Pick a full location to load items.');
        } else {
            clearDestination('Pick a full location to load items.');
        }
        updateSubmitState();
        const params = createLocationParams(prefix);
        console.log('[transfer] maybeFetch', prefix, params);
        if (!params) return;
        fetchInventory(prefix, params);
    }
    maybeFetch = wrapWithLog(maybeFetch, 'maybeFetch');

    async function fetchInventory(prefix, params) {
        const targetBody = prefix === 's' ? sourceTableBody : destinationTableBody;
        if (targetBody) {
            const cols = prefix === 's' ? 6 : 4;
            targetBody.innerHTML = `<tr><td class="muted" colspan="${cols}">Loading…</td></tr>`;
        }
        try {
            const url = new URL(ajaxPath, window.location.origin);
            url.searchParams.set('ajax', 'inv_by_loc');
            url.searchParams.set('row', params.row);
            url.searchParams.set('bay', params.bay);
            url.searchParams.set('lvl', params.lvl);
            url.searchParams.set('side', params.side);
            const urlStr = url.toString();
            console.log('[transfer] fetchInventory url', urlStr, 'params:', params);
            if (prefix === 's') {
                setSelectAllButtonState(false);
            }
            const res = await fetch(urlStr, { credentials: 'same-origin' });
            console.log('[transfer] fetchInventory status', res.status);
            const data = await res.json();
            console.log('[transfer] fetchInventory data', data);
            if (!data.ok) {
                if (targetBody) {
                    const cols = prefix === 's' ? 6 : 4;
                    targetBody.innerHTML = `<tr><td class="text-danger" colspan="${cols}">${escapeHtml(data.error || 'Unable to load inventory')}</td></tr>`;
                }
                return;
            }
            if (prefix === 's') {
                renderSource(data.items || data.pallets || [], data.location_label || '');
            } else {
                renderDestination(data.items || [], data.location_label || '');
            }
        } catch (err) {
            console.error('transfer fetch failed', err);
            if (targetBody) {
                const cols = prefix === 's' ? 6 : 4;
                targetBody.innerHTML = `<tr><td class="text-danger" colspan="${cols}">Server error.</td></tr>`;
            }
        } finally {
            updateSubmitState();
        }
    }
    fetchInventory = wrapWithLog(fetchInventory, 'fetchInventory');

    function renderSource(items) {
        if (!sourceTableBody) return;
        const flat = [];
        (items || []).forEach(entry => {
            if (entry && Array.isArray(entry.skus)) {
                const palId = entry.pallet_id || 0;
                (entry.skus || []).forEach(sku => {
                    flat.push({
                        pallet_id: palId,
                        sku_id: sku.sku_id,
                        sku_num: sku.sku_num,
                        sku_desc: sku.sku_desc,
                        quantity: sku.quantity,
                        lot_id: sku.lot_id != null ? sku.lot_id : null,
                        lot_code: sku.lot_code || '',
                        expiry_date: sku.expiry_date || '',
                    });
                });
                return;
            }
            flat.push({
                pallet_id: entry.pallet_id || 0,
                sku_id: entry.sku_id || 0,
                sku_num: entry.sku_num || '',
                sku_desc: entry.sku_desc || '',
                quantity: entry.quantity,
                lot_id: entry.lot_id != null ? entry.lot_id : null,
                lot_code: entry.lot_code || '',
                expiry_date: entry.expiry_date || '',
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
            const expiryDate = item.expiry_date || '';
            const skuId = item.sku_id || 0;
            const palletId = item.pallet_id || 0;
            const lotId = item.lot_id != null ? Number(item.lot_id) : 0;
            const lotLabel = [item.lot_code || '', expiryDate].filter(Boolean).join(' / ') || (lotId ? `#${lotId}` : 'No lot');
            return `
            <tr>
                <td class="text-center">
                    <input type="checkbox" class="sku-checkbox" name="selected_items[]" value="${palletId}:${skuId}:${lotId}">
                </td>
                <td>
                    <div>${escapeHtml(skuNum)}</div>
                </td>
                <td>${renderDescription(skuDesc, expiryDate)}</td>
                <td>${escapeHtml(lotLabel)}</td>
                <td class="text-end">${max}</td>
                <td>
                    <input type="hidden" name="selected_lot_id[${palletId}][${skuId}]" value="${lotId}">
                    <input type="number" class="qty-input input" name="selected_qty[${palletId}][${skuId}][${lotId}]" min="1" ${max ? `max="${max}"` : ''} value="${max || 1}" disabled>
                </td>
            </tr>`;
        }).join('');
        sourceTableBody.innerHTML = rowsHtml;
        bindSourceRowEvents();
        setSelectAllButtonState(true);
        updateSubmitState();
    }
    renderSource = wrapWithLog(renderSource, 'renderSource');

    function syncRowSelection(cb) {
        const row = cb.closest('tr');
        const qtyInput = row ? row.querySelector('.qty-input') : null;
        if (!qtyInput) return;
        qtyInput.disabled = !cb.checked;
        if (cb.checked && (!qtyInput.value || Number(qtyInput.value) <= 0)) {
            qtyInput.value = qtyInput.getAttribute('max') || '1';
        }
    }
    syncRowSelection = wrapWithLog(syncRowSelection, 'syncRowSelection');

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
                <td>${renderDescription(item.sku_desc || '', item.expiry_date || '')}</td>
                <td>${escapeHtml([item.lot_code || '', item.expiry_date || ''].filter(Boolean).join(' / ') || (item.lot_id ? `#${item.lot_id}` : 'No lot'))}</td>
                <td>${Number(item.quantity) || 0}</td>
            </tr>
        `).join('');
        removeDestinationWarning();
    }
    renderDestination = wrapWithLog(renderDestination, 'renderDestination');

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
    bindSourceRowEvents = wrapWithLog(bindSourceRowEvents, 'bindSourceRowEvents');

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
    updateSubmitState = wrapWithLog(updateSubmitState, 'updateSubmitState');

    function updateSelectAllState() {
        const selectAll = document.getElementById('selectAllSkus');
        if (!selectAll || !sourceTableBody) return;
        const boxes = sourceTableBody.querySelectorAll('.sku-checkbox');
        const checked = sourceTableBody.querySelectorAll('.sku-checkbox:checked');
        selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
    }
    updateSelectAllState = wrapWithLog(updateSelectAllState, 'updateSelectAllState');

    function setSelectAllButtonState(enabled) {
        if (!selectAllBtn) return;
        selectAllBtn.disabled = !enabled;
        selectAllBtn.classList.toggle('is-disabled', !enabled);
    }
    setSelectAllButtonState = wrapWithLog(setSelectAllButtonState, 'setSelectAllButtonState');

    function selectAllSourceSkus() {
        if (!sourceTableBody) return;
        const checkboxes = sourceTableBody.querySelectorAll('.sku-checkbox');
        if (!checkboxes.length) return;
        checkboxes.forEach(cb => {
            cb.checked = true;
            syncRowSelection(cb);
        });
        updateSelectAllState();
        updateSubmitState();
    }
    selectAllSourceSkus = wrapWithLog(selectAllSourceSkus, 'selectAllSourceSkus');

    function buildLocationLabel(prefix) {
        const set = selects[prefix];
        if (!set || !set.row || !set.row.value) return '(not set)';
        const row = set.row.value;
        if (isSpecial(row)) return row;
        const parts = [row, set.bay.value || '?', set.level.value || '?', set.side.value || '?'];
        return parts.join('-');
    }
    buildLocationLabel = wrapWithLog(buildLocationLabel, 'buildLocationLabel');

    function makeToastCloseable(toastEl) {
        if (!toastEl) return null;
        let closeBtn = toastEl.querySelector('.toast__close');
        if (!closeBtn) {
            closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'toast__close';
            closeBtn.textContent = 'Close';
            toastEl.appendChild(closeBtn);
        }
        closeBtn.style.position = 'absolute';
        closeBtn.style.top = '12px';
        closeBtn.style.right = '14px';
        closeBtn.style.border = 'none';
        closeBtn.style.background = 'transparent';
        closeBtn.style.color = 'inherit';
        closeBtn.style.fontWeight = '700';
        closeBtn.style.cursor = 'pointer';
        closeBtn.style.fontSize = '0.95rem';
        const handleClose = () => {
            toastEl.remove();
        };
        closeBtn.onclick = handleClose;
        toastEl.addEventListener('keydown', evt => {
            if (evt.key === 'Escape') handleClose();
        });
        if (!toastEl.getAttribute('role')) toastEl.setAttribute('role', 'alertdialog');
        if (!toastEl.getAttribute('aria-live')) toastEl.setAttribute('aria-live', 'assertive');
        toastEl.setAttribute('tabindex', '-1');
        if (typeof toastEl.focus === 'function') {
            setTimeout(() => toastEl.focus(), 0);
        }
        return toastEl;
    }
    makeToastCloseable = wrapWithLog(makeToastCloseable, 'makeToastCloseable');

    function loadTransferApprovedToast(options = {}) {
        const addDetailRow = (li, label, value) => {
            const row = document.createElement('div');
            row.className = 'toast__details-row';
            const lbl = document.createElement('span');
            lbl.className = 'toast__details-label';
            lbl.textContent = label;
            const val = document.createElement('span');
            val.className = 'toast__details-value';
            val.textContent = value;
            row.appendChild(lbl);
            row.appendChild(val);
            li.appendChild(row);
        };

        const opts = typeof options === 'string' ? { message: options } : options;
        const { message, meta } = opts;
        const existing = document.querySelector('.toast.toast--banner');
        if (existing) existing.remove();
        const toastEl = document.createElement('div');
        toastEl.className = 'toast toast--banner success';
        const title = document.createElement('div');
        title.className = 'toast__headline';
        title.textContent = message || 'Transfer approved';
        toastEl.appendChild(title);
        const details = Array.isArray(meta) ? meta : (meta ? [meta] : []);
        if (details.length) {
            const list = document.createElement('ul');
            list.className = 'toast__details';
            details.forEach(detail => {
                const li = document.createElement('li');
                if (detail && typeof detail === 'object') {
                    addDetailRow(li, 'Sku', detail.sku || detail.sku_num || '');
                    addDetailRow(li, 'Desc', detail.desc || detail.sku_desc || '');
                    addDetailRow(li, 'Qty', detail.qty ?? '');
                } else {
                    li.textContent = String(detail);
                }
                list.appendChild(li);
            });
            toastEl.appendChild(list);
        }
        console.log('[transfer-loadTransferApprovedTaost] loadTransferApprovedToast created toast:', toastEl);
        makeToastCloseable(toastEl);
        document.body.appendChild(toastEl);
        return toastEl;
    }
    loadTransferApprovedToast = wrapWithLog(loadTransferApprovedToast, 'loadTransferApprovedToast');
    window.loadTransferApprovedToast = loadTransferApprovedToast;

    let allowSubmit = false;

    function openConfirmModal() {
        if (!confirmModal || !confirmMessage) {
            console.warn('[transfer] openConfirmModal: modal elements missing');
            if (window.confirm('Are you sure you want to transfer the selected items?')) {
                allowSubmit = true;
                if (form) form.submit();
            }
            return;
        }

        const selectedCount = (form || document).querySelectorAll('input[name="selected_items[]"]:checked').length;
        const srcLabel = buildLocationLabel('s');
        const dstLabel = buildLocationLabel('d');
        const skuWord = selectedCount === 1 ? 'SKU' : 'SKUs';
        confirmMessage.textContent = `Transfer ${selectedCount} selected ${skuWord} from ${srcLabel} to ${dstLabel}?`;

        confirmModal.removeAttribute('hidden');
        confirmModal.setAttribute('aria-hidden', 'false');
        confirmModal.classList.add('is-open');
        if (confirmYesBtn) confirmYesBtn.focus();
    }
    openConfirmModal = wrapWithLog(openConfirmModal, 'openConfirmModal');

    function closeConfirmModal() {
        if (!confirmModal) return;
        confirmModal.classList.remove('is-open');
        confirmModal.setAttribute('aria-hidden', 'true');
        confirmModal.setAttribute('hidden', 'hidden');
    }
    closeConfirmModal = wrapWithLog(closeConfirmModal, 'closeConfirmModal');

    function initSelectors(prefix) {
        const set = selects[prefix];
        if (!set || !set.row) return;

        fillSelect(set.row, rows, 'Select row');
        disableSelect(set.bay, 'Select bay');
        disableSelect(set.level, 'Select level');
        disableSelect(set.side, 'Select side');

        set.row.addEventListener('change', () => {
            const rowVal = set.row.value;
            console.log(`[transfer] ${prefix} row changed:`, rowVal, 'bays =>', baysByRow[rowVal] || []);
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
            console.log(`[transfer] ${prefix} bay changed:`, { rowVal, bayVal, levels: rowVal && bayVal && levelsByRow[rowVal] ? levelsByRow[rowVal][bayVal] || [] : [] });
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
            console.log(`[transfer] ${prefix} level changed:`, { rowVal, bayVal, lvlVal, sides: rowVal && bayVal && lvlVal && sidesByRow[rowVal] && sidesByRow[rowVal][bayVal]
                ? sidesByRow[rowVal][bayVal][lvlVal] || []
                : [] });
            const sides = rowVal && bayVal && lvlVal && sidesByRow[rowVal] && sidesByRow[rowVal][bayVal]
                ? sidesByRow[rowVal][bayVal][lvlVal] || []
                : [];
            fillSelect(set.side, sides, 'Select side');
            updateHidden(prefix);
            maybeFetch(prefix);
        });

        set.side.addEventListener('change', () => {
            console.log(`[transfer] ${prefix} side changed:`, set.side.value);
            updateHidden(prefix);
            maybeFetch(prefix);
        });
    }
    initSelectors = wrapWithLog(initSelectors, 'initSelectors');

    console.log('7. transfer.js DEBUG: About to call initSelectors. Selectors object:', { s: selects.s, d: selects.d });
    initSelectors('s');
    initSelectors('d');
    console.log('[transfer] Init complete');

    if (confirmNoBtn) {
        confirmNoBtn.addEventListener('click', () => {
            closeConfirmModal();
        });
    }

    if (confirmYesBtn) {
        confirmYesBtn.addEventListener('click', () => {
            allowSubmit = true;
            closeConfirmModal();
            if (form) form.submit();
        });
    }

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', () => {
            selectAllSourceSkus();
        });
    }

    if (form) {
        form.addEventListener('submit', e => {
            if (allowSubmit) {
                console.log('[transfer] submit allowed after confirmation');
                return;
            }
            e.preventDefault();
            const hasSource = locationComplete('s');
            const hasDestination = locationComplete('d');
            const selectedCount = form.querySelectorAll('input[name="selected_items[]"]:checked').length;
            console.log('[transfer] pre-submit check', { hasSource, hasDestination, selectedCount });
            if (!hasSource || !hasDestination || selectedCount === 0) {
                console.warn('[transfer] submit blocked: incomplete form');
                return;
            }
            openConfirmModal();
        });
    }

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
    const successToast = document.querySelector('.toast.toast--banner.success');
    if (successToast) {
        const msg = (successToast.textContent || '').trim();
        let meta = [];
        const rawDetails = successToast.dataset.toastDetails;
        if (rawDetails) {
            try {
                const parsed = JSON.parse(rawDetails);
                if (Array.isArray(parsed)) {
                    meta = parsed.map(det => ({
                        sku: det?.sku || det?.sku_num || '',
                        desc: det?.desc || det?.sku_desc || '',
                        qty: det?.qty ?? ''
                    }));
                }
            } catch (err) {
                console.warn('[transfer] failed to parse toast details', err);
            }
        }
        successToast.remove();
        loadTransferApprovedToast({ message: msg, meta });
    } else {
        const initialToast = document.querySelector('.toast.toast--banner');
        if (initialToast) {
            makeToastCloseable(initialToast);
        }
    }

    // Remove destination warning text if present in the layout.
    function removeDestinationWarning() {
        const container = document.getElementById('destinationInventory') || destinationTableBody?.parentElement;
        if (!container) return;
        const nodes = container.querySelectorAll('*');
        nodes.forEach(el => {
            const text = (el.textContent || '').trim().toLowerCase();
            if (text.includes('no storage available to receive inventory') || text.includes('no pallets available to receive inventory')) {
                el.remove();
            }
        });
    }
    removeDestinationWarning = wrapWithLog(removeDestinationWarning, 'removeDestinationWarning');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
