(function () {
  function initInventoryNotes() {
    const notePopover = document.getElementById('outwhseNotePopover');
    const notePopoverBody = document.getElementById('outwhseNotePopoverBody');
    const notePopoverClose = document.getElementById('outwhseNotePopoverClose');
    const notePopoverSave = document.getElementById('outwhseNotePopoverSave');
    const commentsLaunchBtn = document.getElementById('outwhseCommentsLaunchBtn');
    const table = document.getElementById('outwhseTable');
    const tableWrap = document.getElementById('outwhseTableWrap');
    const noteTriggers = Array.from(document.querySelectorAll('.outwhse-note-trigger'));
    let activeNoteTrigger = null;

    if (!noteTriggers.length && !commentsLaunchBtn) {
      return;
    }

    function closeInventoryNote() {
      if (notePopover) {
        notePopover.hidden = true;
      }
      activeNoteTrigger = null;
    }

    function openInventoryNote(trigger) {
      activeNoteTrigger = trigger || null;
      const note = trigger
        ? String(trigger.dataset.note || '').trim() || 'No pallet notes available.'
        : 'Select a pallet row to view its comments.';

      if (!notePopover || !notePopoverBody) {
        window.alert(note);
        return;
      }

      notePopoverBody.value = note === 'No pallet notes available.' ? '' : note;
      notePopover.hidden = false;

      if (!tableWrap) {
        return;
      }

      const triggerRect = tableWrap.getBoundingClientRect();
      const popoverRect = notePopover.getBoundingClientRect();
      const margin = 12;
      const headerClearance = 16;
      const left = Math.max(
        margin,
        Math.min(
          window.innerWidth - popoverRect.width - margin,
          triggerRect.left + (triggerRect.width / 2) - (popoverRect.width / 2)
        )
      );
      const aboveTop = triggerRect.top - popoverRect.height - headerClearance;
      const top = Math.max(
        margin,
        Math.min(
          aboveTop,
          window.innerHeight - popoverRect.height - margin
        )
      );

      notePopover.style.left = left + 'px';
      notePopover.style.top = top + 'px';

      if (typeof notePopoverBody.focus === 'function') {
        notePopoverBody.focus();
        if (typeof notePopoverBody.setSelectionRange === 'function') {
          const end = notePopoverBody.value.length;
          notePopoverBody.setSelectionRange(end, end);
        }
      }
    }

    function syncNoteTriggerText(trigger, note) {
      if (!trigger) {
        return;
      }

      const trimmed = String(note || '').trim();
      trigger.dataset.note = trimmed;
      trigger.textContent = trimmed !== '' ? trimmed : 'No notes';
      trigger.classList.toggle('outwhse-note-trigger--empty', trimmed === '');
      trigger.setAttribute('aria-label', trimmed !== '' ? 'View pallet note' : 'No pallet notes available');
    }

    async function saveInventoryNote() {
      if (!activeNoteTrigger || !notePopoverBody) {
        return;
      }

      const itemId = activeNoteTrigger.dataset.itemId || '';
      const inventoryNotes = notePopoverBody.value || '';

      if (!itemId) {
        window.alert('Choose a valid pallet line first.');
        return;
      }

      const formData = new FormData();
      const csrfTokenInput = document.querySelector('input[name="csrf_token"]');
      const returnLocationInput = document.querySelector('input[name="return_location_id"]');
      if (csrfTokenInput) {
        formData.append('csrf_token', csrfTokenInput.value || '');
      }
      if (returnLocationInput) {
        formData.append('return_location_id', returnLocationInput.value || '');
      }
      formData.append('action', 'update_pallet_notes');
      formData.append('id', itemId);
      formData.append('inventory_notes', inventoryNotes);

      if (notePopoverSave) {
        notePopoverSave.disabled = true;
      }

      try {
        const response = await fetch(window.location.href, {
          method: 'POST',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: formData
        });
        const payload = await response.json().catch(function () {
          return null;
        });

        if (!response.ok || !payload || payload.success === false) {
          window.alert((payload && payload.message) ? payload.message : 'Unable to update the pallet note.');
          return;
        }

        syncNoteTriggerText(activeNoteTrigger, inventoryNotes);
        closeInventoryNote();
      } catch (_) {
        window.alert('Unable to update the pallet note.');
      } finally {
        if (notePopoverSave) {
          notePopoverSave.disabled = false;
        }
      }
    }

    noteTriggers.forEach(function (trigger) {
      if (trigger.dataset.outwhseNoteBound === '1') {
        return;
      }

      trigger.dataset.outwhseNoteBound = '1';
      trigger.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        openInventoryNote(trigger);
      });
      trigger.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          event.stopPropagation();
          openInventoryNote(trigger);
        }
      });
    });

    if (commentsLaunchBtn && commentsLaunchBtn.dataset.outwhseNoteBound !== '1') {
      commentsLaunchBtn.dataset.outwhseNoteBound = '1';
      commentsLaunchBtn.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();

        const selectedRow = table
          ? (table.querySelector('tbody tr.is-selected:not([hidden])') || table.querySelector('tbody tr:not([hidden])'))
          : null;
        const trigger = selectedRow ? selectedRow.querySelector('.outwhse-note-trigger') : null;
        openInventoryNote(trigger);
      });
    }

    if (notePopoverSave && notePopoverSave.dataset.outwhseNoteBound !== '1') {
      notePopoverSave.dataset.outwhseNoteBound = '1';
      notePopoverSave.addEventListener('click', function (event) {
        event.preventDefault();
        saveInventoryNote();
      });
    }

    if (notePopoverClose && notePopoverClose.dataset.outwhseNoteBound !== '1') {
      notePopoverClose.dataset.outwhseNoteBound = '1';
      notePopoverClose.addEventListener('click', function (event) {
        event.preventDefault();
        closeInventoryNote();
      });
    }

    if (document.documentElement.dataset.outwhseNoteDocumentBound !== '1') {
      document.documentElement.dataset.outwhseNoteDocumentBound = '1';

      document.addEventListener('click', function (event) {
        if (notePopover && !notePopover.hidden && !notePopover.contains(event.target)) {
          closeInventoryNote();
        }
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          closeInventoryNote();
        }
      });

      window.addEventListener('resize', closeInventoryNote);
    }
  }

  function bootInventoryNotes() {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initInventoryNotes, { once: true });
    } else {
      initInventoryNotes();
    }
  }

  function initOutwhsePage() {
    const pageBody = document.body;
    const pageDataNode = document.getElementById('outwhsePageData');
    const searchInput = document.getElementById('outwhseSearch');
    const spaceFilter = document.getElementById('outwhseSpaceFilter');
    const queryForm = searchInput ? searchInput.closest('form') : null;
    const tableWrap = document.getElementById('outwhseTableWrap');
    const table = document.getElementById('outwhseTable');
    const tableBody = table && table.tBodies.length ? table.tBodies[0] : null;
    const selectAllRowsCheckbox = document.getElementById('outwhseSelectAllRows');
    const emptyState = document.getElementById('outwhseEmpty');
    const visibleCount = document.getElementById('outwhseVisibleCount');

    if (!pageDataNode || !searchInput || !spaceFilter || !queryForm || !tableWrap || !table || !tableBody || !emptyState || !visibleCount) {
      return;
    }

    let pageData = {
      basePageUrl: '',
      ajaxUrl: '',
      bolPdfUrl: '',
      createDefaults: {},
      plusIcon: '',
      editIcon: ''
    };

    try {
      const parsed = JSON.parse(pageDataNode.textContent || '{}');
      if (parsed && typeof parsed === 'object') {
        pageData = Object.assign(pageData, parsed);
      }
    } catch (_) {
      return;
    }

    const rows = Array.from(document.querySelectorAll('#outwhseTable tbody tr'));
    const addLaunchBtn = document.getElementById('outwhseAddLaunchBtn');
    const statusLaunchBtn = document.getElementById('outwhseStatusLaunchBtn');
    const addressBookLaunchBtn = document.getElementById('outwhseAddressBookLaunchBtn');
    const addressBookModal = document.getElementById('outwhseAddressBookModal');
    const warehouseLaunchBtn = document.getElementById('outwhseWarehouseLaunchBtn');
    const warehouseModal = document.getElementById('outwhseWarehouseModal');
    const lineModal = document.getElementById('outwhseLineModal');
    const lineForm = document.getElementById('outwhseLineForm');
    const lineActionInput = document.getElementById('outwhseLineAction');
    const lineIdInput = document.getElementById('outwhseLineItemId');
    const lineModalTitle = document.getElementById('outwhseLineModalTitle');
    const lineModalText = document.getElementById('outwhseLineModalText');
    const lineSubmitBtn = document.getElementById('outwhseLineSubmitBtn');
    const lineSubmitIcon = document.getElementById('outwhseLineSubmitIcon');
    const lineSubmitText = document.getElementById('outwhseLineSubmitText');
    const itemSourceSelect = document.getElementById('item_source');
    const warehouseLocationSelect = document.getElementById('warehouse_location_id');
    const skuInput = document.getElementById('sku');
    const manualLabelInput = document.getElementById('manual_label');
    const descriptionInput = document.getElementById('description');
    const lotNumberInput = document.getElementById('lot_number');
    const lotInfoBox = document.getElementById('outwhseLotInfo');
    const lotInfoNumber = document.getElementById('outwhseLotInfoNumber');
    const totalQtyInput = document.getElementById('total_qty');
    const palletNumberInput = document.getElementById('pallet_number');
    const lineNumberInput = document.getElementById('line_number');
    const destinationLocationSelect = document.getElementById('destination_location_id');
    const palletWeightInput = document.getElementById('pallet_weight');
    const palletLengthInput = document.getElementById('pallet_length_in');
    const palletWidthInput = document.getElementById('pallet_width_in');
    const palletHeightInput = document.getElementById('pallet_height_in');
    const dangerousGoodInput = document.getElementById('is_dangerous_good');
    const dgClassInput = document.getElementById('dg_class');
    const unNumberInput = document.getElementById('un_number');
    const dangerousGoodsUnNumbers = {
      '2.1': 'UN1057',
      '9': 'UN3481'
    };
    const lineNumberField = lineNumberInput ? lineNumberInput.closest('.outwhse-admin__field') : null;
    const batchLinesInput = document.getElementById('outwhseBatchLinesJson');
    const addDraftLineBtn = document.getElementById('outwhseAddDraftLineBtn');
    const resetDraftFieldsBtn = document.getElementById('outwhseResetDraftFieldsBtn');
    const draftLinesSection = document.getElementById('outwhseDraftLinesSection');
    const draftLinesWrap = document.getElementById('outwhseDraftLinesWrap');
    const draftLinesBody = document.getElementById('outwhseDraftLinesBody');
    const draftLinesEmpty = document.getElementById('outwhseDraftLinesEmpty');
    const wizardRoot = document.getElementById('outwhseWizard');
    const wizardPanels = wizardRoot ? Array.from(wizardRoot.querySelectorAll('[data-wizard-step]')) : [];
    const wizardTabs = wizardRoot ? Array.from(wizardRoot.querySelectorAll('[data-wizard-tab]')) : [];
    const wizardPrevBtn = document.getElementById('outwhseWizardPrevBtn');
    const wizardNextBtn = document.getElementById('outwhseWizardNextBtn');
    const wizardHint = document.getElementById('outwhseWizardHint');
    const sourceGroups = {
      catalog: Array.from(document.querySelectorAll('[data-source-group="catalog"]')),
      manual: Array.from(document.querySelectorAll('[data-source-group="manual"]'))
    };
    const tagLaunchBtn = document.getElementById('outwhseTagLaunchBtn');
    const tagModal = document.getElementById('outwhseTagModal');
    const tagPrintBtn = document.getElementById('outwhseTagPrintBtn');
    const printModal = document.getElementById('outwhsePrintModal');
    const printBolBtn = document.getElementById('outwhsePrintBolBtn');
    const printTagBtn = document.getElementById('outwhsePrintTagBtn');
    const bolLaunchBtn = document.getElementById('outwhseBolLaunchBtn');
    const bolModal = document.getElementById('outwhseBolModal');
    const skuLedgerLaunchBtn = document.getElementById('outwhseSkuLedgerLaunchBtn');
    const skuLedgerModal = document.getElementById('outwhseSkuLedgerModal');
    const skuLedgerSearchSku = document.getElementById('outwhseSkuLedgerSearchSku');
    const skuLedgerSearchWarehouse = document.getElementById('outwhseSkuLedgerSearchWarehouse');
    const skuLedgerSearchQty = document.getElementById('outwhseSkuLedgerSearchQty');
    const bolAddMoreBtn = document.getElementById('outwhseBolAddMoreBtn');
    const bolCopyBtn = document.getElementById('outwhseBolCopyBtn');
    const bolPrintBtn = document.getElementById('outwhseBolPrintBtn');
    const statusModal = document.getElementById('outwhseStatusModal');
    const statusForm = document.getElementById('outwhseStatusForm');
    const statusActionInput = document.getElementById('outwhseStatusAction');
    const statusItemIdInput = document.getElementById('outwhseStatusItemId');
    const statusPalletInput = document.getElementById('outwhseStatusPallet');
    const statusItemInput = document.getElementById('outwhseStatusItem');
    const statusDescriptionInput = document.getElementById('outwhseStatusDescription');
    const statusLotNumberInput = document.getElementById('outwhseStatusLotNumber');
    const statusSpaceDisplayInput = document.getElementById('outwhseStatusSpaceDisplay');
    const statusInventoryNotesInput = document.getElementById('outwhseStatusInventoryNotes');
    const statusContentsSummary = document.getElementById('outwhseStatusContentsSummary');
    const statusContentsBody = document.getElementById('outwhseStatusContentsBody');
    const editPalletWeightInput = document.getElementById('outwhseEditPalletWeight');
    const editPalletLengthInput = document.getElementById('outwhseEditPalletLength');
    const editPalletWidthInput = document.getElementById('outwhseEditPalletWidth');
    const editPalletHeightInput = document.getElementById('outwhseEditPalletHeight');
    const statusDangerousGoodInput = document.getElementById('outwhseStatusIsDangerousGood');
    const statusDgClassInput = document.getElementById('outwhseStatusDgClass');
    const statusUnNumberInput = document.getElementById('outwhseStatusUnNumber');
    const statusSubmitBtn = document.getElementById('outwhseStatusSubmitBtn');
    const statusDeleteBtn = document.getElementById('outwhseStatusDeleteBtn');
    const modalNodes = Array.from(document.querySelectorAll('.outwhse-modal'));
    const tagFields = {
      previewList: document.getElementById('outwhseTagPreviewList'),
      palletOnlyList: document.getElementById('outwhsePalletOnlyPreviewList'),
      note: document.getElementById('tagNote') || document.getElementById('outwhseTagNote') || document.getElementById('wizardTagNote')
    };
    const bolFields = {
      from: document.getElementById('bolFrom'),
      to: document.getElementById('bolTo'),
      item: document.getElementById('bolItem'),
      description: document.getElementById('bolDescription'),
      qty: document.getElementById('bolQty'),
      unit: document.getElementById('bolUnit'),
      pallet: document.getElementById('bolPallet'),
      line: document.getElementById('bolLine'),
      linesWrap: document.getElementById('bolLinesWrap'),
      linesBody: document.getElementById('bolLinesBody'),
      note: document.getElementById('bolNote')
    };
    const wizardTagFields = {
      warehouse: document.getElementById('wizardTagWarehouse'),
      item: document.getElementById('wizardTagItem'),
      itemMirror: document.getElementById('wizardTagItemMirror'),
      description: document.getElementById('wizardTagDescription'),
      lot: document.getElementById('wizardTagLot'),
      qty: document.getElementById('wizardTagQty'),
      unit: document.getElementById('wizardTagUnit'),
      pallet: document.getElementById('wizardTagPallet'),
      palletOnly: document.getElementById('wizardTagPalletOnly'),
      line: document.getElementById('wizardTagLine'),
      note: document.getElementById('wizardTagNote')
    };
    const wizardBolFields = {
      warehouse: document.getElementById('wizardBolWarehouse'),
      item: document.getElementById('wizardBolItem'),
      description: document.getElementById('wizardBolDescription'),
      qty: document.getElementById('wizardBolQty'),
      unit: document.getElementById('wizardBolUnit'),
      pallet: document.getElementById('wizardBolPallet'),
      line: document.getElementById('wizardBolLine'),
      note: document.getElementById('wizardBolNote')
    };
    const addressBookOptions = Array.isArray(pageData.addressBookOptions) ? pageData.addressBookOptions : [];
    const addressBookDefaults = pageData.addressBookDefaults && typeof pageData.addressBookDefaults === 'object'
      ? pageData.addressBookDefaults
      : {};
    const bolAddressSelects = {
      shipper: Array.from(document.querySelectorAll('[data-address-role="shipper"]')),
      consignee: Array.from(document.querySelectorAll('[data-address-role="consignee"]'))
    };
    const createDefaults = pageData.createDefaults || {};
    const maxBolLineCount = 12;
    const bolLocationLabels = Object.assign({
      '1': 'Lachaine',
      '2': 'Dickson',
      '3': 'TPB'
    }, pageData.warehouseLocationLabels && typeof pageData.warehouseLocationLabels === 'object'
      ? pageData.warehouseLocationLabels
      : {});
    const defaultWarehouseLocationId = String(pageData.defaultWarehouseLocationId || '').trim();
    const defaultWarehouseLocationLabel = bolLocationLabelFromId(defaultWarehouseLocationId);

    function setBolNote(message, type) {
      if (!bolFields.note) {
        return;
      }
      const normalizedType = type === 'success' ? 'success' : type === 'error' ? 'error' : 'info';
      bolFields.note.className = 'outwhse-tag-note' + (normalizedType === 'info' ? '' : ' outwhse-flash outwhse-flash--' + normalizedType);
      bolFields.note.textContent = message || '';
    }
    let wizardStep = 1;
    let palletNumberRequestId = 0;
    let skuLookupRequestId = 0;
    let lotLookupRequestId = 0;
    let draftLineId = 0;
    let draftLines = [];
    let activeRowId = rows.length ? (rows[0].dataset.itemId || '') : '';

    function syncModalLock() {
      const hasOpenModal = modalNodes.some(function (modal) {
        return !modal.hidden;
      });
      pageBody.classList.toggle('outwhse-modal-open', hasOpenModal);
    }

    function openModal(modal) {
      if (!modal) {
        return;
      }

      modal.hidden = false;
      modal.removeAttribute('hidden');
      modal.setAttribute('aria-hidden', 'false');
      requestAnimationFrame(function () {
        modal.classList.add('is-open');
      });
      syncModalLock();

      const autofocusTarget = modal.querySelector('select, textarea, input:not([type="hidden"]), button');
      if (autofocusTarget) {
        autofocusTarget.focus({ preventScroll: true });
      }
    }

    function closeModal(modal) {
      if (!modal) {
        return;
      }

      if (modal.contains(document.activeElement)) {
        document.activeElement.blur();
      }
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      window.setTimeout(function () {
        modal.setAttribute('hidden', 'hidden');
        modal.hidden = true;
        syncModalLock();
      }, 120);
    }

    function clearEditQuery() {
      if (window.location.search.indexOf('edit_id=') === -1) {
        return;
      }

      window.history.replaceState({}, '', pageData.basePageUrl || window.location.pathname);
    }

    function toggleSourceGroup(groupName, visible) {
      (sourceGroups[groupName] || []).forEach(function (node) {
        node.hidden = !visible;
        const fields = Array.from(node.querySelectorAll('input, select, textarea'));
        fields.forEach(function (field) {
          field.disabled = !visible;
        });
      });
    }

    function syncItemSourceFields() {
      if (!itemSourceSelect) {
        return;
      }

      const isManual = itemSourceSelect.value === 'manual';
      toggleSourceGroup('catalog', !isManual);
      toggleSourceGroup('manual', isManual);

      if (skuInput) {
        skuInput.required = !isManual && !isCreateMode();
      }
      if (manualLabelInput) {
        manualLabelInput.required = isManual && !isCreateMode();
      }
      if (descriptionInput) {
        descriptionInput.readOnly = !isManual;
      }
      if (lotNumberInput) {
        lotNumberInput.readOnly = false;
      }

      if (isManual) {
        syncLotInfoDisplay(null);
      }
    }

    function normalizeSkuInput() {
      if (!skuInput) {
        return;
      }

      skuInput.value = skuInput.value.toUpperCase().trim();
    }

    function syncLotInfoDisplay(details) {
      if (!lotInfoBox || !lotInfoNumber) {
        return;
      }

      const hasDetails = !!details && typeof details === 'object';
      const isCatalog = !!itemSourceSelect && itemSourceSelect.value === 'catalog';
      const lotNumber = hasDetails && typeof details.lotNumber === 'string'
        ? details.lotNumber.trim()
        : '';

      if (!isCatalog || !hasDetails || !lotNumber) {
        lotInfoBox.hidden = true;
        lotInfoNumber.textContent = '-';
        return;
      }

      lotInfoNumber.textContent = lotNumber;
      lotInfoBox.hidden = false;
    }

    function normalizeDateInputValue(value) {
      const raw = String(value || '').trim();
      if (!raw || raw === 'N/A' || raw === 'NA' || raw === '-') {
        return '';
      }

      const isoMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if (isoMatch) {
        return raw;
      }

      const usMatch = raw.match(/^(\d{2})-(\d{2})-(\d{4})$/);
      if (usMatch) {
        return usMatch[3] + '-' + usMatch[1] + '-' + usMatch[2];
      }

      return '';
    }

    function resetLotNumberOptions(selectEl, placeholderText, isRequired) {
      if (!selectEl) {
        return;
      }

      if ((selectEl.tagName || '').toUpperCase() !== 'SELECT') {
        selectEl.value = '';
        selectEl.required = !!isRequired;
        return;
      }

      const placeholder = typeof placeholderText === 'string' && placeholderText.trim() !== ''
        ? placeholderText.trim()
        : 'Select a lot';

      selectEl.innerHTML = '';
      const option = document.createElement('option');
      option.value = '';
      option.textContent = placeholder;
      selectEl.appendChild(option);
      selectEl.value = '';
      selectEl.required = !!isRequired;
    }

    function renderLotNumberOptions(selectEl, items, selectedLot, isRequired) {
      if (!selectEl) {
        return;
      }

      const lotItems = Array.isArray(items) ? items : [];
      const placeholderText = isRequired ? 'Select a lot' : 'Optional - select a lot';
      resetLotNumberOptions(selectEl, placeholderText, isRequired);

      lotItems.forEach(function (item) {
        const lotCode = String(item && item.lot_code ? item.lot_code : '').trim();
        if (!lotCode) {
          return;
        }

        const option = document.createElement('option');
        option.value = lotCode;
        const expiry = String(item && item.expiry_date ? item.expiry_date : '').trim();
        const quantity = String(item && item.quantity != null ? item.quantity : '').trim();
        const parts = [lotCode];
        if (expiry) {
          parts.push('Exp ' + expiry);
        }
        if (quantity) {
          parts.push('Qty ' + quantity);
        }
        option.textContent = parts.join(' | ');
        selectEl.appendChild(option);
      });

      const candidate = String(selectedLot || '').trim();
      if (candidate) {
        selectEl.value = candidate;
      } else {
        selectEl.value = '';
      }
    }

    async function populateLotOptionsForSku(selectEl, sku, selectedLot, isRequired) {
      if (!selectEl) {
        return;
      }

      const normalizedSku = String(sku || '').trim().toUpperCase();
      if (!normalizedSku) {
        resetLotNumberOptions(selectEl, 'Select a SKU first', false);
        return;
      }

      const requestId = ++lotLookupRequestId;
      resetLotNumberOptions(selectEl, 'Loading lots...', !!isRequired);

      try {
        const response = await fetch((pageData.ajaxUrl || window.location.pathname) + '?ajax=lot_lookup&sku=' + encodeURIComponent(normalizedSku), {
          credentials: 'same-origin'
        });
        if (!response.ok) {
          resetLotNumberOptions(selectEl, 'Unable to load lots', !!isRequired);
          return;
        }

        const payload = await response.json();
        if (requestId !== lotLookupRequestId || !payload || typeof payload !== 'object') {
          return;
        }

        const lots = Array.isArray(payload.lots) ? payload.lots : [];
        renderLotNumberOptions(selectEl, lots, selectedLot, !!isRequired);
      } catch (_) {
        resetLotNumberOptions(selectEl, 'Unable to load lots', !!isRequired);
      }
    }

    function isCreateMode() {
      return !!lineActionInput && (lineActionInput.value === 'create_item' || lineActionInput.value === 'create_pallet_batch');
    }

    function syncPalletFieldMode() {
      if (palletNumberInput) {
        palletNumberInput.readOnly = true;
      }

      if (lineNumberInput) {
        lineNumberInput.readOnly = !isCreateMode();
      }

      [palletWeightInput, palletLengthInput, palletWidthInput, palletHeightInput].forEach(function (input) {
        if (input) {
          input.readOnly = !isCreateMode();
        }
      });
    }

    async function populateCatalogMetadata() {
      if (!itemSourceSelect || itemSourceSelect.value !== 'catalog' || !skuInput || !descriptionInput) {
        syncLotInfoDisplay(null);
        return;
      }

      normalizeSkuInput();
      const sku = skuInput.value.trim();
      if (!sku) {
        descriptionInput.value = '';
        syncLotInfoDisplay(null);
        return;
      }

      const requestId = ++skuLookupRequestId;
      syncLotInfoDisplay(null);

      try {
        const response = await fetch((pageData.ajaxUrl || window.location.pathname) + '?ajax=sku_lookup&sku=' + encodeURIComponent(sku), {
          credentials: 'same-origin'
        });
        if (!response.ok) {
          return;
        }

        const payload = await response.json();
        if (requestId !== skuLookupRequestId || !payload || typeof payload !== 'object') {
          return;
        }

        if (typeof payload.description === 'string' && payload.description.trim() !== '') {
          descriptionInput.value = payload.description.trim();
        }

        if (wizardStep >= 2) {
          renderWizardPreviews();
        }
      } catch (_) {
        syncLotInfoDisplay(null);
        return;
      }
    }

    async function populateNextPalletNumber() {
      if (!destinationLocationSelect || !palletNumberInput || !isCreateMode()) {
        return;
      }

      const locationId = destinationLocationSelect.value;
      if (!locationId) {
        return;
      }

      const requestId = ++palletNumberRequestId;

      try {
        const response = await fetch((pageData.ajaxUrl || window.location.pathname) + '?ajax=next_pallet_number&destination_location_id=' + encodeURIComponent(locationId), {
          credentials: 'same-origin'
        });
        if (!response.ok) {
          return;
        }

        const payload = await response.json();
        if (requestId !== palletNumberRequestId || !payload || typeof payload !== 'object') {
          return;
        }

        if (typeof payload.pallet_number === 'string' && payload.pallet_number.trim() !== '') {
          palletNumberInput.value = payload.pallet_number.trim();
          if (wizardStep >= 2) {
            renderWizardPreviews();
          }
        }
      } catch (_) {
        return;
      }
    }

    function lineFieldValue(name) {
      if (!lineForm) {
        return '';
      }

      const field = lineForm.elements.namedItem(name);
      if (!field || typeof field.value === 'undefined') {
        return '';
      }

      return String(field.value || '').trim();
    }

    function normalizeUnitType(value) {
      const normalized = String(value || '').trim().toUpperCase();
      if (!normalized) {
        return '';
      }
      if (normalized === 'UNIT' || normalized.indexOf('UNIT') !== -1) {
        return 'UNIT';
      }
      if (normalized === 'MC' || normalized === 'MASTERCASE' || normalized === 'MASTER CASE' || normalized.indexOf('MASTER') !== -1) {
        return 'MASTERCASE';
      }
      return normalized;
    }

    function currentLineItemData() {
      const itemSource = lineFieldValue('item_source') || 'catalog';
      const sku = lineFieldValue('sku').toUpperCase();
      const manualLabel = lineFieldValue('manual_label');
      const manualReference = lineFieldValue('manual_reference');
      const manualCategory = lineFieldValue('manual_category');
      const description = lineFieldValue('description');
      const lotNumber = lineFieldValue('lot_number');
      const totalQty = lineFieldValue('total_qty');
      const unitType = lineFieldValue('unit_type');

      return {
        item_source: itemSource,
        sku: sku,
        manual_label: manualLabel,
        manual_reference: manualReference,
        manual_category: manualCategory,
        description: description,
        lot_number: lotNumber,
        total_qty: totalQty,
        unit_type: unitType,
        item_label: itemSource === 'manual' ? (manualLabel || manualReference || 'Manual item') : (sku || 'Catalog item')
      };
    }

    function validateCurrentLineItem() {
      const data = currentLineItemData();

      if (data.item_source === 'catalog' && !data.sku) {
        if (skuInput) {
          skuInput.reportValidity();
        }
        return false;
      }

      if (data.item_source === 'manual' && !data.manual_label) {
        if (manualLabelInput) {
          manualLabelInput.reportValidity();
        }
        return false;
      }

      if (!data.total_qty) {
        const qtyField = lineForm.elements.namedItem('total_qty');
        if (qtyField && typeof qtyField.reportValidity === 'function') {
          qtyField.reportValidity();
        }
        return false;
      }

      if (!data.unit_type) {
        const unitField = lineForm.elements.namedItem('unit_type');
        if (unitField && typeof unitField.reportValidity === 'function') {
          unitField.reportValidity();
        }
        return false;
      }

      return true;
    }

    function syncBatchLinesInput() {
      if (batchLinesInput) {
        batchLinesInput.value = JSON.stringify(draftLines);
      }
    }

    function renderDraftLines() {
      if (!draftLinesBody || !draftLinesWrap || !draftLinesEmpty) {
        return;
      }

      syncBatchLinesInput();
      draftLinesBody.innerHTML = '';

      if (!draftLines.length) {
        draftLinesWrap.hidden = true;
        draftLinesEmpty.hidden = false;
        return;
      }

      draftLinesWrap.hidden = false;
      draftLinesEmpty.hidden = true;

      draftLines.forEach(function (line, index) {
        const tr = document.createElement('tr');
        tr.innerHTML = ''
          + '<td class="outwhse-table__sku">' + escapeHtml(line.item_label) + '</td>'
          + '<td>' + escapeHtml(line.description || line.item_label) + '</td>'
          + '<td>' + escapeHtml(line.lot_number || 'N/A') + '</td>'
          + '<td>' + escapeHtml(line.total_qty || 'N/A') + '</td>'
          + '<td>' + escapeHtml(line.unit_type || 'N/A') + '</td>'
          + '<td><button class="outwhse-btn outwhse-btn--danger outwhse-btn--compact" type="button" data-draft-remove="' + escapeHtml(String(line.id)) + '">Remove</button></td>';
        draftLinesBody.appendChild(tr);
      });
    }

    function resetCurrentLineFields() {
      ['sku', 'manual_label', 'manual_reference', 'manual_category', 'description', 'lot_number', 'total_qty'].forEach(function (name) {
        const field = lineForm.elements.namedItem(name);
        if (field) {
          field.value = '';
        }
      });

      const unitField = lineForm.elements.namedItem('unit_type');
      if (unitField) {
        unitField.value = createDefaults.unit_type || 'UNIT';
      }

      const itemSourceField = lineForm.elements.namedItem('item_source');
      if (itemSourceField) {
        itemSourceField.value = 'catalog';
      }

      syncItemSourceFields();
    }

    function addCurrentLineToDraft() {
      if (!validateCurrentLineItem()) {
        return;
      }

      const data = currentLineItemData();
      draftLineId += 1;
      draftLines.push(Object.assign({ id: draftLineId }, data));
      renderDraftLines();

      if (skuInput) {
        skuInput.value = '';
      }
      if (totalQtyInput) {
        totalQtyInput.value = '';
      }

      if (wizardHint) {
        wizardHint.textContent = 'Line item added. Add another line or move to pallet info when the contents are complete.';
      }
    }

    function lineFieldLabel(name) {
      if (!lineForm) {
        return '';
      }

      const field = lineForm.elements.namedItem(name);
      if (!field || field.tagName !== 'SELECT') {
        return lineFieldValue(name);
      }

      const option = field.options[field.selectedIndex];
      return option ? String(option.textContent || '').trim() : '';
    }

    function wizardDataFromForm() {
      if (isCreateMode() && draftLines.length > 0) {
        if (draftLines.length === 1) {
          const line = draftLines[0];
          return {
            warehouse: lineFieldLabel('warehouse_location_id') || 'Unassigned',
            item: line.item_label,
            description: line.description || line.item_label || '-',
            lot: line.lot_number || '-',
            qty: line.total_qty || '-',
            unit: line.unit_type || '-',
            pallet: lineFieldValue('pallet_number') || '-',
            line: '1'
          };
        }

        return {
          warehouse: lineFieldLabel('warehouse_location_id') || 'Unassigned',
          item: 'Multiple',
          description: 'Mixed Pallet',
          lot: 'Multiple',
          qty: String(draftLines.length),
          unit: 'LINES',
          pallet: lineFieldValue('pallet_number') || '-',
          line: '1-' + String(draftLines.length)
        };
      }

      const itemSource = lineFieldValue('item_source') || 'catalog';
      const sku = lineFieldValue('sku');
      const manualLabel = lineFieldValue('manual_label');
      const manualReference = lineFieldValue('manual_reference');
      const lineNumber = lineFieldValue('line_number');
      const description = lineFieldValue('description');

      let itemLabel = sku || 'Catalog item';
      if (itemSource === 'manual') {
        itemLabel = manualLabel || manualReference || 'Manual item';
      }

      return {
        warehouse: lineFieldLabel('warehouse_location_id') || 'Unassigned',
        item: itemLabel,
        description: description || (itemSource === 'manual' ? manualLabel || '-' : '-'),
        lot: lineFieldValue('lot_number') || '-',
        qty: lineFieldValue('total_qty') || '-',
        unit: lineFieldValue('unit_type') || '-',
        pallet: lineFieldValue('pallet_number') || '-',
        line: lineNumber || 'Auto on save'
      };
    }

    function applyPreviewFields(targetFields, data, note) {
      if (!targetFields.warehouse) {
        return;
      }

      targetFields.warehouse.textContent = data.warehouse;
      if (targetFields.item) {
        targetFields.item.textContent = data.item;
      }
      if (targetFields.itemMirror) {
        targetFields.itemMirror.textContent = data.item;
      }
      if (targetFields.description) {
        targetFields.description.textContent = data.description;
      }
      if (targetFields.lot) {
        targetFields.lot.textContent = data.lot;
      }
      if (targetFields.qty) {
        targetFields.qty.textContent = data.qty;
      }
      if (targetFields.unit) {
        targetFields.unit.textContent = data.unit;
      }
      if (targetFields.pallet) {
        targetFields.pallet.textContent = data.pallet;
      }
      if (targetFields.palletOnly) {
        targetFields.palletOnly.textContent = data.pallet;
      }
      if (targetFields.line) {
        targetFields.line.textContent = data.line;
      }
      if (targetFields.note) {
        targetFields.note.textContent = note;
      }
    }

    function renderWizardPreviews() {
      const data = wizardDataFromForm();
      applyPreviewFields(
        wizardTagFields,
        data,
        'Review the label details before continuing to the BOL step.'
      );
      applyPreviewFields(
        wizardBolFields,
        data,
        'This BOL preview is generated from the same pallet line details you are about to save.'
      );
    }

    function setWizardStep(step) {
      if (!wizardRoot) {
        return;
      }

      const nextStep = Math.max(1, Math.min(3, step));
      wizardStep = nextStep;

      if (wizardStep >= 3) {
        renderWizardPreviews();
      }

      wizardPanels.forEach(function (panel) {
        const panelStep = Number(panel.dataset.wizardStep || '1');
        panel.hidden = panelStep !== wizardStep;
      });

      wizardTabs.forEach(function (tab) {
        const tabStep = Number(tab.dataset.wizardTab || '1');
        tab.classList.toggle('is-active', tabStep === wizardStep);
        tab.classList.toggle('is-complete', tabStep < wizardStep);
      });

      if (wizardPrevBtn) {
        wizardPrevBtn.hidden = wizardStep === 1;
      }
      if (wizardNextBtn) {
        wizardNextBtn.hidden = wizardStep === 3;
      }
      if (lineSubmitBtn) {
        lineSubmitBtn.hidden = wizardStep !== 3;
      }
      if (wizardHint) {
        if (wizardStep === 1) {
          wizardHint.textContent = isCreateMode()
            ? 'Add at least one line item to the pallet before moving to pallet info.'
            : 'Update the line item details before moving to pallet info.';
        } else if (wizardStep === 2) {
          syncDangerousGoodsFields();
          wizardHint.textContent = 'These pallet-level fields apply to every line item saved onto this pallet.';
        } else if (wizardStep === 3) {
          wizardHint.textContent = 'This preview is pulled from the drafted pallet contents and shared pallet info.';
        } else {
          wizardHint.textContent = isCreateMode()
            ? 'The full pallet and all drafted lines will be saved when you submit from this final pallet tag step.'
            : 'The updated pallet line will be saved when you submit from this final pallet tag step.';
        }
      }
    }

    function goToNextWizardStep() {
      if (!wizardRoot) {
        return;
      }

      if (wizardStep === 1) {
        if (isCreateMode()) {
          if (!draftLines.length) {
            if (wizardHint) {
              wizardHint.textContent = 'Add at least one line item before moving to pallet info.';
            }
            return;
          }
        } else {
          syncItemSourceFields();
          if (lineForm && typeof lineForm.reportValidity === 'function' && !lineForm.reportValidity()) {
            return;
          }
        }
      } else if (wizardStep === 2) {
        const requiredNames = ['pallet_number', 'space_status'];
        const palletInfoValid = requiredNames.every(function (name) {
          const field = lineForm.elements.namedItem(name);
          return field && typeof field.reportValidity === 'function' ? field.reportValidity() : true;
        });
        if (!palletInfoValid) {
          return;
        }
      }

      setWizardStep(wizardStep + 1);
    }

    function formatNumericDisplay(value) {
      const number = Number(value);
      if (!Number.isFinite(number)) {
        return '';
      }

      if (Math.abs(number - Math.round(number)) < 0.00001) {
        return String(Math.round(number));
      }

      return String(number.toFixed(2)).replace(/\.?0+$/, '');
    }

    function selectedRow() {
      return rows.find(function (row) {
        return (row.dataset.itemId || '') === activeRowId;
      }) || null;
    }

    function rowBolCheckbox(row) {
      return row ? row.querySelector('[data-bol-select]') : null;
    }

    function visibleSelectableRows() {
      return rows.filter(function (row) {
        return !row.hidden;
      });
    }

    function palletKeyForRow(row) {
      if (!row) {
        return '';
      }

      return String(
        row.dataset.tagPallet ||
        row.dataset.pallet ||
        row.dataset.editPalletNumber ||
        ''
      ).trim();
    }

    function syncPalletSelectionForRow(sourceRow, shouldSelect) {
      const palletKey = palletKeyForRow(sourceRow);

      if (!palletKey || palletKey === '-') {
        return;
      }

      rows.forEach(function (row) {
        if (palletKeyForRow(row) !== palletKey) {
          return;
        }

        const checkbox = rowBolCheckbox(row);
        if (checkbox && !checkbox.disabled) {
          checkbox.checked = shouldSelect;
        }
      });
    }

    function syncSelectAllCheckbox() {
      if (!selectAllRowsCheckbox) {
        return;
      }

      const visibleCheckboxes = visibleSelectableRows().map(rowBolCheckbox).filter(Boolean);
      const checkedCount = visibleCheckboxes.filter(function (checkbox) {
        return checkbox.checked;
      }).length;

      selectAllRowsCheckbox.indeterminate = checkedCount > 0 && checkedCount < visibleCheckboxes.length;
      selectAllRowsCheckbox.checked = visibleCheckboxes.length > 0 && checkedCount === visibleCheckboxes.length;
      selectAllRowsCheckbox.disabled = visibleCheckboxes.length === 0;
    }

    function selectedOutputRows() {
      const checkedRows = rows.filter(function (row) {
        const checkbox = rowBolCheckbox(row);
        return !!checkbox && checkbox.checked;
      });

      if (checkedRows.length) {
        return checkedRows;
      }

      const activeRow = selectedRow();
      return activeRow ? [activeRow] : [];
    }

    function selectedOutputRowIds() {
      return selectedOutputRows()
        .map(function (row) {
          return row ? String(row.dataset.itemId || '').trim() : '';
        })
        .filter(Boolean);
    }

    function setSelectedRow(row) {
      activeRowId = row ? (row.dataset.itemId || '') : '';
      updateSelectedState();
    }

    function openStatusModalForRow(row) {
      if (!row || !statusModal || !statusItemIdInput || !statusPalletInput || !statusItemInput || !statusDescriptionInput) {
        return;
      }

      const palletRows = palletRowsForRow(row);
      const palletNumber = String(row.dataset.tagPallet || row.dataset.pallet || '-').trim() || '-';
      const warehouseName = String(row.dataset.tagWarehouse || '').trim();
      const contentsLabel = palletRows.length === 1
        ? '1 pallet line'
        : (palletRows.length + ' pallet lines');
      const rowSku = String(row.dataset.sku || row.dataset.tagItem || '').trim().toUpperCase();
      const rowLot = String(row.dataset.lotRaw || row.dataset.tagLot || '').trim();
      const rowLotRequired = String(row.dataset.lotRequired || '0') === '1';

      statusItemIdInput.value = row.dataset.itemId || '';
      statusPalletInput.value = palletNumber;
      statusItemInput.value = palletRows.length > 1 ? 'Multiple items' : (row.dataset.tagItem || '-');
      statusDescriptionInput.value = palletRows.length > 1 ? contentsLabel : (row.dataset.tagDescription || '-');
      if (statusSpaceDisplayInput) {
        statusSpaceDisplayInput.value = row.dataset.spaceStatusRaw || '-';
      }
      if (statusInventoryNotesInput) {
        statusInventoryNotesInput.value = row.dataset.inventoryNotes || '';
      }
      if (statusLotNumberInput) {
        statusLotNumberInput.value = normalizeDateInputValue(row.dataset.lotRaw || row.dataset.tagLot || '');
      }
      if (editPalletWeightInput) {
        editPalletWeightInput.value = row.dataset.editPalletWeight || '';
      }
      if (editPalletLengthInput) {
        editPalletLengthInput.value = row.dataset.editPalletLength || '';
      }
      if (editPalletWidthInput) {
        editPalletWidthInput.value = row.dataset.editPalletWidth || '';
      }
      if (editPalletHeightInput) {
        editPalletHeightInput.value = row.dataset.editPalletHeight || '';
      }
      if (statusDangerousGoodInput) {
        statusDangerousGoodInput.checked = String(row.dataset.isDangerousGood || '0') === '1';
      }
      if (statusDgClassInput) {
        statusDgClassInput.value = row.dataset.dgClass || '';
      }
      if (statusUnNumberInput) {
        statusUnNumberInput.value = row.dataset.unNumber || '';
      }
      if (statusContentsSummary) {
        statusContentsSummary.textContent = (warehouseName ? warehouseName + ' - ' : '') + contentsLabel + ' on pallet ' + palletNumber;
      }
      renderPalletContentsTable(palletRows);
      syncStatusDangerousGoodsFields();

      const isMovementRow = String(row.dataset.liveSource || 'inventory') === 'movement';
      if (statusSubmitBtn) {
        statusSubmitBtn.disabled = isMovementRow || !statusItemIdInput.value;
      }
      if (statusDeleteBtn) {
        statusDeleteBtn.disabled = isMovementRow || !statusItemIdInput.value;
      }
      if (statusActionInput) {
        statusActionInput.value = isMovementRow ? '' : 'update_pallet';
      }

      openModal(statusModal);
    }

    function setLineFormModeCreate() {
      if (!lineForm || !lineActionInput || !lineIdInput) {
        return;
      }

      lineActionInput.value = 'create_pallet_batch';
      lineIdInput.value = '';
      draftLines = [];
      syncBatchLinesInput();
      renderDraftLines();

      if (lineModalTitle) {
        lineModalTitle.textContent = 'Create Pallet';
      }
      if (lineModalText) {
        lineModalText.textContent = 'Create a 3PL pallet with catalog or manual item details, then confirm the label and BOL preview.';
      }
      if (lineSubmitText) {
        lineSubmitText.textContent = 'Save Pallet';
      }
      if (lineSubmitIcon) {
        lineSubmitIcon.innerHTML = pageData.plusIcon || '';
      }

      Object.keys(createDefaults).forEach(function (key) {
        const field = lineForm.querySelector('[name="' + key + '"]');
        if (field) {
          field.value = createDefaults[key] || '';
        }
      });

      syncItemSourceFields();
      syncPalletFieldMode();
      syncDangerousGoodsFields();
      if (lineNumberField) {
        lineNumberField.hidden = true;
      }
      if (addDraftLineBtn) {
        addDraftLineBtn.hidden = false;
      }
      if (resetDraftFieldsBtn) {
        resetDraftFieldsBtn.hidden = false;
      }
      if (draftLinesSection) {
        draftLinesSection.hidden = false;
      }
      syncLotInfoDisplay(null);
      populateNextPalletNumber();
      populateCatalogMetadata();
      setWizardStep(1);
      clearEditQuery();
    }

    function updateSelectedState() {
      rows.forEach(function (row) {
        const isSelected = (row.dataset.itemId || '') === activeRowId;
        const checkbox = rowBolCheckbox(row);
        const isBolSelected = !!checkbox && checkbox.checked;
        row.classList.toggle('is-selected', isSelected);
        row.classList.toggle('is-bol-selected', isBolSelected);
        row.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
      });
      syncSelectAllCheckbox();
      if (statusLaunchBtn) {
        statusLaunchBtn.disabled = !selectedRow();
      }
    }

    function tagSummaryFromRows(inputRows) {
      const rowsToUse = Array.isArray(inputRows) ? inputRows.filter(Boolean) : [];
      if (!rowsToUse.length) {
        return null;
      }

      const uniqueOrigins = Array.from(new Set(rowsToUse.map(function (row) {
        return row.dataset.originLocationName || row.dataset.tagWarehouse || '';
      }).filter(Boolean)));
      const uniqueDestinations = Array.from(new Set(rowsToUse.map(function (row) {
        return row.dataset.destinationLocationName || '';
      }).filter(Boolean)));
      const uniqueUnits = Array.from(new Set(rowsToUse.map(function (row) {
        return row.dataset.tagUnit || '';
      }).filter(Boolean)));
      const uniquePallets = Array.from(new Set(rowsToUse.map(function (row) {
        return row.dataset.tagPallet || '';
      }).filter(function (value) {
        return value && value !== '-';
      })));

      const totalQty = rowsToUse.reduce(function (sum, row) {
        const next = parseFloat(String(row.dataset.tagQty || '').replace(/[^0-9.\-]/g, ''));
        return Number.isFinite(next) ? sum + next : sum;
      }, 0);

      if (rowsToUse.length === 1) {
        const row = rowsToUse[0];
        const description = (row.dataset.tagDescription || '').trim() || (row.dataset.tagItem || '-');
        return {
          rows: rowsToUse,
          warehouse: row.dataset.tagWarehouse || '-',
          item: row.dataset.tagItem || '-',
          description: description,
          lot: row.dataset.tagLot || '-',
          qty: row.dataset.tagQty || '-',
          unit: row.dataset.tagUnit || '-',
          pallet: row.dataset.tagPallet || '-',
          line: row.dataset.tagLine || '-',
          uniquePallets: uniquePallets,
          totalQty: totalQty
        };
      }

      return {
        rows: rowsToUse,
        warehouse: uniqueOrigins.length === 1 ? uniqueOrigins[0] : 'Multiple',
        item: String(rowsToUse.length) + ' selected lines',
        description: String(rowsToUse.length) + ' SKU pallet tags ready',
        lot: 'Multiple',
        qty: formatNumericDisplay(totalQty) || String(rowsToUse.length),
        unit: uniqueUnits.length === 1 ? uniqueUnits[0] : 'Mixed',
        pallet: uniquePallets.length <= 1 ? (uniquePallets[0] || '-') : (String(uniquePallets.length) + ' pallets'),
        line: String(rowsToUse.length) + ' lines',
        uniquePallets: uniquePallets,
        totalQty: totalQty
      };
    }

    function tagTextFromRows(inputRows) {
      const summary = tagSummaryFromRows(inputRows);
      if (!summary) {
        return 'No line item selected.';
      }

      const lines = summary.rows.map(function (row, index) {
        const description = (row.dataset.tagDescription || '').trim() || (row.dataset.tagItem || '-');
        return String(index + 1) + '. '
          + (row.dataset.tagPallet || '-') + ' | '
          + (row.dataset.tagItem || '-') + ' | '
          + description + ' | Qty ' + (row.dataset.tagQty || '-');
      });

      return [
        '3PL Warehouse Pallet Tag',
        'From: ' + summary.fromLocation,
        'To: ' + summary.toLocation,
        'Item / SKU: ' + summary.item,
        'Description: ' + summary.description,
        'Total Qty: ' + summary.qty,
        'Unit Type: ' + summary.unit,
        'Pallet #: ' + summary.pallet,
        'Line #: ' + summary.line,
        'Pallet Number Tags: ' + String(Math.max(summary.uniquePallets.length, 1)),
        'Lines:',
        lines.join('\n')
      ].join('\n');
    }

    function labelDataListFromRows(inputRows) {
      const rowsToUse = Array.isArray(inputRows) ? inputRows.filter(Boolean) : [];
      return rowsToUse.map(function (row) {
        const description = (row.dataset.tagDescription || '').trim() || (row.dataset.tagItem || '-');
        return {
          warehouse: row.dataset.tagWarehouse || '-',
          item: row.dataset.tagItem || '-',
          description: description,
          lot: row.dataset.tagLot || '-',
          qty: row.dataset.tagQty || '-',
          unit: row.dataset.tagUnit || '-',
          pallet: row.dataset.tagPallet || '-',
          line: row.dataset.tagLine || '-'
        };
      });
    }

    function palletOnlyDataListFromRows(inputRows) {
      const labelDataList = labelDataListFromRows(inputRows);
      const seen = new Set();
      return labelDataList.filter(function (data) {
        const palletKey = String(data.pallet || '').trim() || '__blank__';
        if (seen.has(palletKey)) {
          return false;
        }
        seen.add(palletKey);
        return true;
      });
    }

    function emptyLabelData() {
      return {
        warehouse: 'Select one or more lines',
        item: '-',
        description: '-',
        lot: '-',
        qty: '-',
        unit: '',
        pallet: '-',
        line: '-'
      };
    }

    function escapeHtml(value) {
      return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function escapeAttribute(value) {
      return escapeHtml(value);
    }

    function palletRowsForRow(row) {
      if (!row) {
        return [];
      }

      const palletNumber = String(row.dataset.tagPallet || row.dataset.pallet || '').trim();

      if (!palletNumber) {
        return [row];
      }

      return rows.filter(function (candidate) {
        if (!candidate) {
          return false;
        }

        const candidatePallet = String(candidate.dataset.tagPallet || candidate.dataset.pallet || '').trim();
        return candidatePallet === palletNumber;
      });
    }

    function renderPalletContentsTable(palletRows) {
      if (!statusContentsBody) {
        return;
      }

      if (!palletRows.length) {
        statusContentsBody.innerHTML = '<tr><td colspan="6">No pallet contents were found.</td></tr>';
        return;
      }

      statusContentsBody.innerHTML = palletRows.map(function (row) {
        const dgLabel = String(row.dataset.isDangerousGood || '0') === '1'
          ? ('DG ' + String(row.dataset.dgClass || '-'))
          : '-';

        return ''
          + '<tr>'
          + '<td>' + escapeHtml(row.dataset.tagLine || '-') + '</td>'
          + '<td>' + escapeHtml(row.dataset.tagItem || '-') + '</td>'
          + '<td>' + escapeHtml(row.dataset.tagDescription || '-') + '</td>'
          + '<td>' + escapeHtml(row.dataset.tagQty || '-') + '</td>'
          + '<td>' + escapeHtml(row.dataset.tagLot || '-') + '</td>'
          + '<td>' + escapeHtml(dgLabel) + '</td>'
          + '</tr>';
      }).join('');
    }

    function syncDangerousGoodsFields() {
      if (!dangerousGoodInput) {
        return;
      }

      const enabled = dangerousGoodInput.checked;
      if (dgClassInput) {
        dgClassInput.disabled = !enabled;
        if (!enabled) {
          dgClassInput.value = '';
        }
      }
      if (unNumberInput) {
        unNumberInput.disabled = !enabled;
        if (!enabled) {
          unNumberInput.value = '';
        } else {
          const mappedUnNumber = dangerousGoodsUnNumbers[String(dgClassInput ? dgClassInput.value : '').trim()] || '';
          unNumberInput.value = mappedUnNumber;
        }
      }
    }

    function syncStatusDangerousGoodsFields() {
      if (!statusDangerousGoodInput) {
        return;
      }

      const enabled = statusDangerousGoodInput.checked;
      if (statusDgClassInput) {
        statusDgClassInput.disabled = !enabled;
        if (!enabled) {
          statusDgClassInput.value = '';
        }
      }
      if (statusUnNumberInput) {
        statusUnNumberInput.disabled = !enabled;
        if (!enabled) {
          statusUnNumberInput.value = '';
        } else {
          const mappedUnNumber = dangerousGoodsUnNumbers[String(statusDgClassInput ? statusDgClassInput.value : '').trim()] || '';
          statusUnNumberInput.value = mappedUnNumber;
        }
      }
    }

    function labelPreviewMarkup(data) {
      return ''
        + '<article class="outwhse-label-preview">'
        + '<div class="outwhse-label-preview__brand">Turning Point Brands Canada</div>'
        + '<div class="outwhse-label-preview__hero">'
        + '<p class="outwhse-label-preview__eyebrow">Pallet Tag</p>'
        + '<h3 class="outwhse-label-preview__title">' + escapeHtml(data.description) + '</h3>'
        + '<p class="outwhse-label-preview__subtitle">SKU: <strong>' + escapeHtml(data.item) + '</strong></p>'
        + '</div>'
        + '<div class="outwhse-label-preview__grid">'
        + '<div class="outwhse-label-preview__cell"><span>Lot</span><strong>' + escapeHtml(data.lot) + '</strong></div>'
        + '<div class="outwhse-label-preview__cell"><span>SKU</span><strong>' + escapeHtml(data.item) + '</strong></div>'
        + '<div class="outwhse-label-preview__cell"><span>Pallet No.</span><strong>' + escapeHtml(data.pallet) + '</strong></div>'
        + '<div class="outwhse-label-preview__cell"><span>Qty</span><strong>' + escapeHtml(data.qty) + ' ' + escapeHtml(data.unit) + '</strong></div>'
        + '</div>'
        + '<div class="outwhse-label-preview__footer">'
        + '<span>Warehouse: <strong>' + escapeHtml(data.warehouse) + '</strong></span>'
        + '<span>Line: <strong>' + escapeHtml(data.line) + '</strong></span>'
        + '</div>'
        + '</article>';
    }

    function palletOnlyPreviewMarkup(data) {
      return ''
        + '<article class="outwhse-pallet-only-label">'
        + '<p class="outwhse-pallet-only-label__eyebrow">Pallet Number</p>'
        + '<strong class="outwhse-pallet-only-label__value">' + escapeHtml(data.pallet) + '</strong>'
        + '</article>';
    }

    function normalizedAddressBookOption(option) {
      if (!option || typeof option !== 'object') {
        return null;
      }

      const id = String(option.id || '').trim();
      if (!id) {
        return null;
      }

      return {
        id: id,
        type: String(option.type || 'other').trim().toLowerCase(),
        label: String(option.label || option.company_name || ('Address #' + id)).trim(),
        company_name: String(option.company_name || '').trim(),
        contact_name: String(option.contact_name || '').trim(),
        phone: String(option.phone || '').trim(),
        email: String(option.email || '').trim(),
        address_line_1: String(option.address_line_1 || '').trim(),
        address_line_2: String(option.address_line_2 || '').trim(),
        city: String(option.city || '').trim(),
        province_state: String(option.province_state || '').trim(),
        postal_code: String(option.postal_code || '').trim(),
        country: String(option.country || '').trim()
      };
    }

    function addressBookSelectableOptions() {
      return addressBookOptions
        .map(normalizedAddressBookOption)
        .filter(function (option) {
          return !!option;
        });
    }

    function populateAddressBookSelects() {
      Object.keys(bolAddressSelects).forEach(function (role) {
        const selects = bolAddressSelects[role] || [];
        const options = addressBookSelectableOptions();
        const defaultValue = String(addressBookDefaults[role] || '').trim();
        const fallbackValue = options.length ? options[0].id : '';

        selects.forEach(function (select) {
          if (!select) {
            return;
          }

          const currentValue = String(select.value || '').trim();
          const selectedValue = options.some(function (option) {
            return option.id === currentValue;
          })
            ? currentValue
            : (defaultValue || fallbackValue);

          const optionMarkup = ['<option value="">None</option>'];
          options.forEach(function (option) {
            optionMarkup.push(
              '<option value="' + escapeAttribute(option.id) + '">' + escapeHtml(option.label) + '</option>'
            );
          });

          select.innerHTML = optionMarkup.join('');
          select.value = selectedValue;
        });
      });
    }

    function selectedAddressForRole(role) {
      const selects = bolAddressSelects[role] || [];
      const value = selects.length ? String(selects[0].value || '').trim() : '';
      if (!value) {
        return null;
      }

      const options = addressBookSelectableOptions();
      return options.find(function (option) {
        return option.id === value;
      }) || null;
    }

    function bolLineFromRow(row) {
      if (!row) {
        return null;
      }

      const item = (row.dataset.tagItem || '').trim();
      const description = (row.dataset.tagDescription || '').trim();
      const qty = (row.dataset.tagQty || '').trim();
      const unit = (row.dataset.tagUnit || '').trim();
      const pallet = (row.dataset.tagPallet || '').trim();
      const line = (row.dataset.tagLine || '').trim();
      const isDangerousGood = String(row.dataset.isDangerousGood || '0') === '1';
      const dgClass = isDangerousGood ? (row.dataset.dgClass || '').trim() : '';
      const unNumber = isDangerousGood ? (row.dataset.unNumber || '').trim() : '';

      return {
        item: item,
        description: description,
        qty: qty,
        unit: unit,
        pallet: pallet,
        line: line,
        pallet_weight: (row.dataset.editPalletWeight || '').trim(),
        pallet_count: '',
        line_description: [item, description].filter(Boolean).join(' | '),
        is_dangerous_good: isDangerousGood,
        dg_class: dgClass,
        un_number: unNumber
      };
    }

    function bolLinesFromRows(inputRows) {
      return Array.isArray(inputRows)
        ? inputRows.filter(Boolean).slice(0, maxBolLineCount).map(bolLineFromRow).filter(Boolean)
        : [];
    }

    function bolLinePreviewMarkup(line, index) {
      return ''
        + '<tr data-bol-line-index="' + escapeAttribute(String(index)) + '">'
        + '<td>' + escapeHtml(line.pallet || '-') + '</td>'
        + '<td>' + escapeHtml(line.item || '-') + '</td>'
        + '<td>' + escapeHtml(line.description || '-') + '</td>'
        + '<td>' + escapeHtml(line.qty || '-') + '</td>'
        + '<td>' + (line.is_dangerous_good ? 'Yes' : 'No') + '</td>'
        + '<td>' + escapeHtml(line.dg_class || '-') + '</td>'
        + '<td>' + escapeHtml(line.un_number || '-') + '</td>'
        + '</tr>';
    }

    function labelMarkup(data) {
      return ''
        + '<article class="print-label">'
        + '<div class="print-label__brand">Turning Point Brands Canada</div>'
        + '<div class="print-label__hero">'
        + '<p class="print-label__eyebrow">Pallet Tag</p>'
        + '<h1 class="print-label__title">' + escapeHtml(data.description) + '</h1>'
        + '<p class="print-label__subtitle">SKU: <strong>' + escapeHtml(data.item) + '</strong></p>'
        + '</div>'
        + '<div class="print-label__grid">'
        + '<div class="print-label__cell"><span>Lot</span><strong>' + escapeHtml(data.lot) + '</strong></div>'
        + '<div class="print-label__cell"><span>SKU</span><strong>' + escapeHtml(data.item) + '</strong></div>'
        + '<div class="print-label__cell"><span>Pallet No.</span><strong>' + escapeHtml(data.pallet) + '</strong></div>'
        + '<div class="print-label__cell"><span>Qty</span><strong>' + escapeHtml(data.qty) + ' ' + escapeHtml(data.unit) + '</strong></div>'
        + '</div>'
        + '<div class="print-label__footer">'
        + '<span>Warehouse: <strong>' + escapeHtml(data.warehouse) + '</strong></span>'
        + '<span>Line: <strong>' + escapeHtml(data.line) + '</strong></span>'
        + '</div>'
        + '</article>';
    }

    function palletOnlyLabelMarkup(data) {
      return ''
        + '<article class="print-pallet-only-label">'
        + '<p class="print-pallet-only-label__eyebrow">Pallet Number</p>'
        + '<strong class="print-pallet-only-label__value">' + escapeHtml(data.pallet) + '</strong>'
        + '</article>';
    }

    function bolSummaryFromRows(inputRows) {
      const rowsToUse = Array.isArray(inputRows) ? inputRows.filter(Boolean) : [];
      if (!rowsToUse.length) {
        return null;
      }

      const uniqueOrigins = Array.from(new Set(rowsToUse.map(function (row) {
        return String(row.dataset.originLocationName || row.dataset.tagWarehouse || '').trim();
      }).filter(Boolean)));
      const uniqueDestinations = Array.from(new Set(rowsToUse.map(function (row) {
        return String(row.dataset.destinationLocationName || '').trim();
      }).filter(Boolean)));
      const uniqueDestinationIds = Array.from(new Set(rowsToUse.map(function (row) {
        return String(row.dataset.destinationLocationId || '').trim();
      }).filter(Boolean)));
      const uniqueUnits = Array.from(new Set(rowsToUse.map(function (row) {
        return row.dataset.tagUnit || '';
      }).filter(Boolean)));
      const uniquePallets = Array.from(new Set(rowsToUse.map(function (row) {
        return row.dataset.tagPallet || '';
      }).filter(function (value) {
        return value && value !== '-';
      })));

      const totalQty = rowsToUse.reduce(function (sum, row) {
        const next = parseFloat(String(row.dataset.tagQty || '').replace(/[^0-9.\-]/g, ''));
        return Number.isFinite(next) ? sum + next : sum;
      }, 0);

      const fromLocation = uniqueOrigins.length === 1 ? uniqueOrigins[0] : 'Multiple';
      const destinationLocationId = uniqueDestinationIds.length === 1 ? uniqueDestinationIds[0] : defaultWarehouseLocationId;
      const toLocation = destinationLocationId ? bolLocationLabelFromId(destinationLocationId) : 'Select destination';
      const item = rowsToUse.length === 1 ? (rowsToUse[0].dataset.tagItem || '-') : (String(rowsToUse.length) + ' selected lines');
      const description = rowsToUse.length === 1
        ? ((rowsToUse[0].dataset.tagDescription || '').trim() || rowsToUse[0].dataset.tagItem || '-')
        : 'Multiple line items selected';
      const qty = rowsToUse.length === 1
        ? (rowsToUse[0].dataset.tagQty || '-')
        : (formatNumericDisplay(totalQty) || String(rowsToUse.length));
      const unit = uniqueUnits.length === 1 ? uniqueUnits[0] : 'Mixed';
      const pallet = uniquePallets.length <= 1
        ? (uniquePallets[0] || rowsToUse[0].dataset.tagPallet || '-')
        : (String(uniquePallets.length) + ' pallets');
      const line = rowsToUse.length === 1 ? (rowsToUse[0].dataset.tagLine || '-') : (String(rowsToUse.length) + ' lines');

      return {
        rows: rowsToUse,
        fromLocation: fromLocation,
        toLocation: toLocation,
        destinationLocationId: destinationLocationId,
        item: item,
        description: description,
        qty: qty,
        unit: unit,
        pallet: pallet,
        line: line,
        totalQty: totalQty,
        uniquePallets: uniquePallets
      };
    }

    function bolTextFromRows(inputRows) {
      const summary = bolSummaryFromRows(inputRows);
      if (!summary) {
        return 'No line item selected.';
      }

      const destinationLocationId = summary.destinationLocationId || bolSelectedDestinationValue() || (summary.fromLocation && summary.fromLocation !== defaultWarehouseLocationLabel ? defaultWarehouseLocationId : '');
      const destinationLocationName = bolLocationLabelFromId(destinationLocationId) || summary.toLocation;
      const shipperAddress = selectedAddressForRole('shipper');
      const consigneeAddress = selectedAddressForRole('consignee');
      const lines = bolLinesFromRows(summary.rows).map(function (line, index) {
        const parts = [
          String(index + 1) + '.',
          line.pallet || '-',
          line.item || '-',
          line.description || '-',
          'Qty ' + (line.qty || '-')
        ];

        if (line.is_dangerous_good) {
          parts.push('DG');
          parts.push('Class ' + (line.dg_class || '-'));
          parts.push('UN ' + (line.un_number || '-'));
        }

        return parts.join(' | ');
      });

      return [
        'Bill of Lading Draft',
        'From: ' + summary.fromLocation,
        'To: ' + destinationLocationName,
        'Item / SKU: ' + summary.item,
        'Description: ' + summary.description,
        'Total Qty: ' + summary.qty,
        'Unit Type: ' + summary.unit,
        'Pallet #: ' + summary.pallet,
        'Shipper: ' + (shipperAddress ? shipperAddress.label : summary.fromLocation),
        'Consignee: ' + (consigneeAddress ? consigneeAddress.label : destinationLocationName),
        'Lines:',
        lines.join('\n')
      ].join('\n');
    }

    function bolDataFromRows(inputRows) {
      const summary = bolSummaryFromRows(inputRows);
      if (!summary) {
        return null;
      }

      const rowsToUse = summary.rows;
      const shipperAddress = selectedAddressForRole('shipper');
      const consigneeAddress = selectedAddressForRole('consignee');
      const lineItems = bolLinesFromRows(rowsToUse);

      const totalWeightByPallet = {};
      rowsToUse.forEach(function (row, index) {
        const palletKey = (row.dataset.tagPallet || '').trim() || ('row-' + String(index));
        if (Object.prototype.hasOwnProperty.call(totalWeightByPallet, palletKey)) {
          return;
        }

        const weight = parseFloat(String(row.dataset.editPalletWeight || '').replace(/[^0-9.\-]/g, ''));
        totalWeightByPallet[palletKey] = Number.isFinite(weight) ? weight : 0;
      });

      const totalWeight = Object.keys(totalWeightByPallet).reduce(function (sum, key) {
        return sum + totalWeightByPallet[key];
      }, 0);

      const destinationLocationIds = Array.from(new Set(rowsToUse.map(function (row) {
        return String(row.dataset.destinationLocationId || '').trim();
      }).filter(Boolean)));
      if (destinationLocationIds.length > 1) {
        return null;
      }
      const destinationLocationId = destinationLocationIds.length === 1
        ? destinationLocationIds[0]
        : (bolSelectedDestinationValue() || (summary.fromLocation && summary.fromLocation !== defaultWarehouseLocationLabel ? defaultWarehouseLocationId : ''));
      const originLocationIds = Array.from(new Set(rowsToUse.map(function (row) {
        return String(row.dataset.originLocationId || '').trim();
      }).filter(Boolean)));
      const originLocationId = originLocationIds.length === 1 ? originLocationIds[0] : '';
      const destinationLocationName = bolLocationLabelFromId(destinationLocationId);

      if (!destinationLocationId || !destinationLocationName) {
        return null;
      }

      return {
        from_location: summary.fromLocation,
        to_location: destinationLocationName,
        origin_location_id: originLocationId,
        destination_location_id: destinationLocationId,
        item: summary.item,
        description: summary.description,
        qty: formatNumericDisplay(summary.totalQty) || summary.qty,
        unit: summary.unit,
        pallet: summary.pallet,
        line: summary.line,
        pallet_weight: formatNumericDisplay(totalWeight),
        shipper_name: summary.fromLocation,
        shipper_contact_name: shipperAddress ? shipperAddress.contact_name : '',
        shipper_phone: shipperAddress ? shipperAddress.phone : '',
        shipper_address_line_1: shipperAddress ? shipperAddress.address_line_1 : '',
        shipper_address_line_2: shipperAddress ? shipperAddress.address_line_2 : '',
        shipper_city: shipperAddress ? shipperAddress.city : '',
        shipper_province_state: shipperAddress ? shipperAddress.province_state : '',
        shipper_postal_code: shipperAddress ? shipperAddress.postal_code : '',
        shipper_country: shipperAddress ? shipperAddress.country : '',
        consignee_name: destinationLocationName,
        consignee_contact_name: consigneeAddress ? consigneeAddress.contact_name : '',
        consignee_phone: consigneeAddress ? consigneeAddress.phone : '',
        consignee_address_line_1: consigneeAddress ? consigneeAddress.address_line_1 : '',
        consignee_address_line_2: consigneeAddress ? consigneeAddress.address_line_2 : '',
        consignee_city: consigneeAddress ? consigneeAddress.city : '',
        consignee_province_state: consigneeAddress ? consigneeAddress.province_state : '',
        consignee_postal_code: consigneeAddress ? consigneeAddress.postal_code : '',
        consignee_country: consigneeAddress ? consigneeAddress.country : '',
        consignor_name: destinationLocationName,
        carrier_name: '',
        pallet_count: String(summary.uniquePallets.length || rowsToUse.length || 1),
        special_instructions: 'From: ' + summary.fromLocation + ' | To: ' + destinationLocationName + (rowsToUse.length > maxBolLineCount ? ' | Showing first ' + String(maxBolLineCount) + ' lines on template.' : ''),
        line_description: lineItems.length === 1 ? lineItems[0].line_description : (String(lineItems.length) + ' BOL lines'),
        lines_json: JSON.stringify(lineItems),
        bol_number: 'BOL-' + String(Date.now()).slice(-8),
        ship_date: new Date().toISOString().slice(0, 10)
      };
    }

    function bolPdfUrlFromData(data) {
      if (!data) {
        return '';
      }

      const params = new URLSearchParams();
      Object.keys(data).forEach(function (key) {
        params.set(key, data[key] == null ? '' : String(data[key]));
      });

      return (pageData.bolPdfUrl || 'outwhse_bol_pdf.php') + '?' + params.toString();
    }

    function bolPdfUrlFromRows(inputRows) {
      return bolPdfUrlFromData(bolDataFromRows(inputRows));
    }

    function openBolPrintWindow(url, existingWindow) {
      const win = existingWindow || window.open('', '_blank', 'width=920,height=860');
      if (!win) {
        return null;
      }

      const escapedUrl = String(url || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
      win.document.open();
      win.document.write(
        '<!doctype html><html><head><title>BOL Print</title>' +
        '<style>html,body{margin:0;padding:0;width:100%;height:100%;background:#fff;}' +
        'iframe{width:100%;height:100%;border:0;display:block;}</style>' +
        '</head><body>' +
        '<iframe id="bolPrintFrame" src="' + escapedUrl + '"></iframe>' +
        '<script>' +
        'const frame=document.getElementById("bolPrintFrame");' +
        'frame.addEventListener("load",function(){try{frame.contentWindow.focus();frame.contentWindow.print();}catch(e){window.focus();window.print();}});' +
        '<\/script>' +
        '</body></html>'
      );
      win.document.close();
      win.focus();
      return win;
    }

    function applyBolDestinationToRows(selectedRows) {
      const bolDestinationId = bolSelectedDestinationValue();
      if (!bolDestinationId) {
        return false;
      }

      selectedRows.forEach(function (row) {
        if (row) {
          row.dataset.destinationLocationId = bolDestinationId;
          row.dataset.destinationLocationName = bolLocationLabelFromId(bolDestinationId);
        }
      });

      return true;
    }

    function renderTag(inputRows) {
      const summary = tagSummaryFromRows(inputRows);
      if (!summary) {
        if (tagFields.previewList) {
          tagFields.previewList.innerHTML = labelPreviewMarkup(emptyLabelData());
        }
        if (tagFields.palletOnlyList) {
          tagFields.palletOnlyList.innerHTML = palletOnlyPreviewMarkup({ pallet: '-' });
        }
        if (tagFields.note) {
          tagFields.note.textContent = 'Choose one or more rows using the Select checkboxes to build the pallet tag set.';
        }
        return;
      }

      const labelDataList = labelDataListFromRows(summary.rows);
      const palletOnlyList = palletOnlyDataListFromRows(summary.rows);

      if (tagFields.previewList) {
        tagFields.previewList.innerHTML = labelDataList.map(function (data) {
          return labelPreviewMarkup(data);
        }).join('');
      }
      if (tagFields.palletOnlyList) {
        tagFields.palletOnlyList.innerHTML = palletOnlyList.map(function (data) {
          return palletOnlyPreviewMarkup(data);
        }).join('');
      }
      if (tagFields.note) {
        tagFields.note.textContent = summary.rows.length > 1
          ? 'This print job will generate one pallet tag per selected line and one pallet-number page per pallet.'
          : 'This pallet tag uses the selected row fields from the table, excluding status values.';
      }
    }

    function setFieldText(field, value) {
      if (field) {
        field.textContent = value;
      }
    }

    function bolLocationLabelFromId(locationId) {
      const normalized = String(locationId || '').trim();
      return bolLocationLabels[normalized] || '';
    }

    function bolSetDestinationValue(locationId) {
      if (!bolFields.to) {
        return;
      }

      const normalized = String(locationId || '').trim();
      const hasOption = Array.from(bolFields.to.options || []).some(function (option) {
        return option && option.value === normalized;
      });

      bolFields.to.value = hasOption ? normalized : '';
    }

    function bolSelectedDestinationValue() {
      if (!bolFields.to) {
        return '';
      }

      return String(bolFields.to.value || '').trim();
    }

    function renderBol(inputRows) {
      const summary = bolSummaryFromRows(inputRows);
      if (!summary) {
        setFieldText(bolFields.from, defaultWarehouseLocationLabel || 'Warehouse');
        bolSetDestinationValue('');
        setFieldText(bolFields.item, '-');
        setFieldText(bolFields.description, '-');
        setFieldText(bolFields.qty, '-');
        setFieldText(bolFields.unit, '-');
        setFieldText(bolFields.pallet, '-');
        if (bolFields.linesBody) {
          bolFields.linesBody.innerHTML = '';
        }
        if (bolFields.linesWrap) {
          bolFields.linesWrap.hidden = true;
        }
        if (bolAddMoreBtn) {
          bolAddMoreBtn.textContent = 'Send BOL';
        }
        setBolNote('Choose one or more rows using the BOL checkboxes to build the draft.', 'info');
        return;
      }

      const lineItems = bolLinesFromRows(summary.rows);
      if (bolFields.linesBody) {
        bolFields.linesBody.innerHTML = lineItems.map(bolLinePreviewMarkup).join('');
      }
        if (bolFields.linesWrap) {
          bolFields.linesWrap.hidden = lineItems.length === 0;
        }

      setFieldText(bolFields.from, summary.fromLocation || '-');
      bolSetDestinationValue(summary.destinationLocationId || bolSelectedDestinationValue() || (summary.fromLocation && summary.fromLocation !== defaultWarehouseLocationLabel ? defaultWarehouseLocationId : ''));
      setFieldText(bolFields.item, summary.item || '-');
      setFieldText(bolFields.description, summary.description || '-');
      setFieldText(bolFields.qty, summary.qty || '-');
      setFieldText(bolFields.unit, summary.unit || '-');
      setFieldText(bolFields.pallet, summary.pallet || '-');
      const activeDestinationLabel = bolLocationLabelFromId(bolSelectedDestinationValue()) || summary.toLocation || 'Select destination';
      if (bolAddMoreBtn) {
        bolAddMoreBtn.textContent = activeDestinationLabel === defaultWarehouseLocationLabel
          ? 'Send Return BOL'
          : (summary.fromLocation === defaultWarehouseLocationLabel ? 'Send to 3PL' : 'Send BOL');
      }
      setBolNote(summary.rows.length > maxBolLineCount
        ? 'This BOL draft includes ' + String(summary.rows.length) + ' selected rows. The PDF template will print the first ' + String(maxBolLineCount) + ' lines.'
        : 'This BOL draft is built from the selected row fields and uses the stored pallet direction and DG values.', 'info');
    }

    function clearBolSelections() {
      rows.forEach(function (row) {
        const checkbox = rowBolCheckbox(row);
        if (checkbox) {
          checkbox.checked = false;
        }
      });

      if (selectAllRowsCheckbox) {
        selectAllRowsCheckbox.checked = false;
        selectAllRowsCheckbox.indeterminate = false;
      }

      syncSelectAllCheckbox();
      renderBol([]);
    }

    queryForm.addEventListener('submit', function () {
      const pageInput = queryForm.querySelector('input[name="page"]');
      if (pageInput) {
        pageInput.value = '1';
      }
    });

    if (searchInput) {
      searchInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
          const pageInput = queryForm.querySelector('input[name="page"]');
          if (pageInput) {
            pageInput.value = '1';
          }
        }
      });
    }

    if (itemSourceSelect) {
      itemSourceSelect.addEventListener('change', function () {
        syncItemSourceFields();
        if (itemSourceSelect.value === 'catalog') {
          populateCatalogMetadata();
        }
      });
    }

    if (destinationLocationSelect) {
      destinationLocationSelect.addEventListener('change', function () {
        if (isCreateMode()) {
          populateNextPalletNumber();
        }
      });
    }

    if (skuInput) {
      skuInput.addEventListener('input', normalizeSkuInput);
      skuInput.addEventListener('input', function () {
        syncLotInfoDisplay(null);
      });
      skuInput.addEventListener('change', populateCatalogMetadata);
      skuInput.addEventListener('blur', populateCatalogMetadata);
    }

    if (dangerousGoodInput) {
      dangerousGoodInput.addEventListener('change', syncDangerousGoodsFields);
      dangerousGoodInput.addEventListener('input', syncDangerousGoodsFields);
      dangerousGoodInput.addEventListener('click', syncDangerousGoodsFields);
    }
    if (dgClassInput) {
      dgClassInput.addEventListener('change', syncDangerousGoodsFields);
    }
    if (addDraftLineBtn) {
      addDraftLineBtn.addEventListener('click', function () {
        addCurrentLineToDraft();
      });
    }

    if (resetDraftFieldsBtn) {
      resetDraftFieldsBtn.addEventListener('click', function () {
        resetCurrentLineFields();
      });
    }

    if (draftLinesBody) {
      draftLinesBody.addEventListener('click', function (event) {
        const button = event.target.closest('[data-draft-remove]');
        if (!button) {
          return;
        }

        const removeId = Number(button.getAttribute('data-draft-remove') || '0');
        draftLines = draftLines.filter(function (line) {
          return line.id !== removeId;
        });
        renderDraftLines();
      });
    }

    if (lineForm) {
      lineForm.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
          event.preventDefault();
        }
      });
      lineForm.addEventListener('input', function () {
        if (wizardStep >= 3) {
          renderWizardPreviews();
        }
      });
      lineForm.addEventListener('change', function () {
        if (wizardStep >= 3) {
          renderWizardPreviews();
        }
      });
      lineForm.addEventListener('submit', function (event) {
        if (isCreateMode()) {
          if (!draftLines.length) {
            event.preventDefault();
            if (wizardHint) {
              wizardHint.textContent = 'Add at least one line item before saving the pallet.';
            }
            setWizardStep(1);
            return;
          }

          syncBatchLinesInput();
        }
      });
    }

    if (wizardPrevBtn) {
      wizardPrevBtn.addEventListener('click', function () {
        setWizardStep(wizardStep - 1);
      });
    }

    if (wizardNextBtn) {
      wizardNextBtn.addEventListener('click', function () {
        goToNextWizardStep();
      });
    }

    if (addLaunchBtn) {
      addLaunchBtn.addEventListener('click', function () {
        setLineFormModeCreate();
        openModal(lineModal);
      });
    }

    if (statusDangerousGoodInput) {
      statusDangerousGoodInput.addEventListener('change', syncStatusDangerousGoodsFields);
    }
    if (statusDgClassInput) {
      statusDgClassInput.addEventListener('change', syncStatusDangerousGoodsFields);
    }

    if (statusLaunchBtn) {
      statusLaunchBtn.addEventListener('click', function () {
        const row = selectedRow();
        if (!row) {
          return;
        }

        openStatusModalForRow(row);
      });
    }

    if (statusDeleteBtn && statusForm && statusItemIdInput) {
      statusDeleteBtn.addEventListener('click', function () {
        if (!statusItemIdInput.value || !statusForm) {
          return;
        }

        if (!window.confirm('Delete this pallet from live inventory? The pallet number will stay reserved.')) {
          return;
        }

        if (statusActionInput) {
          statusActionInput.value = 'delete_item';
        }

        statusForm.submit();
      });
    }

    if (addressBookLaunchBtn) {
      addressBookLaunchBtn.addEventListener('click', function () {
        openModal(addressBookModal);
      });
    }

    if (warehouseLaunchBtn) {
      warehouseLaunchBtn.addEventListener('click', function () {
        openModal(warehouseModal);
      });
    }

    rows.forEach(function (row) {
      const bolCheckbox = rowBolCheckbox(row);
      row.addEventListener('click', function () {
        setSelectedRow(row);
      });

      row.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }

        event.preventDefault();
        setSelectedRow(row);
      });

      if (bolCheckbox) {
        bolCheckbox.addEventListener('click', function (event) {
          event.stopPropagation();
        });
        bolCheckbox.addEventListener('keydown', function (event) {
          event.stopPropagation();
        });
        bolCheckbox.addEventListener('change', function () {
          syncPalletSelectionForRow(row, bolCheckbox.checked);
          updateSelectedState();
          if (!activeRowId) {
            setSelectedRow(row);
          }
        });
      }
    });

    if (selectAllRowsCheckbox) {
      selectAllRowsCheckbox.addEventListener('click', function (event) {
        event.stopPropagation();
      });
      selectAllRowsCheckbox.addEventListener('change', function () {
        const shouldSelect = selectAllRowsCheckbox.checked;
        visibleSelectableRows().forEach(function (row) {
          const checkbox = rowBolCheckbox(row);
          if (checkbox) {
            checkbox.checked = shouldSelect;
            syncPalletSelectionForRow(row, shouldSelect);
          }
        });
        updateSelectedState();
        if (shouldSelect && !activeRowId) {
          const firstVisibleRow = visibleSelectableRows()[0] || null;
          if (firstVisibleRow) {
            setSelectedRow(firstVisibleRow);
          }
        }
      });
    }

    if (skuLedgerSearchSku || skuLedgerSearchWarehouse || skuLedgerSearchQty) {
      const ledgerParentRows = Array.from(document.querySelectorAll('#outwhseSkuLedgerTableBody tr.sku-ledger-parent'));

      function normalizeLedgerText(value) {
        return String(value || '').toUpperCase().trim();
      }

      function getLedgerDetailsRow(parentRow) {
        if (!parentRow) {
          return null;
        }

        const nextRow = parentRow.nextElementSibling;
        return nextRow && nextRow.classList.contains('sku-ledger-details-row') ? nextRow : null;
      }

      function collapseLedgerRow(parentRow) {
        const toggle = parentRow ? parentRow.querySelector('[data-sku-toggle]') : null;
        const chevron = parentRow ? parentRow.querySelector('.sku-ledger-chevron') : null;
        const detailsRow = getLedgerDetailsRow(parentRow);

        if (detailsRow) {
          detailsRow.hidden = true;
        }

        if (toggle) {
          toggle.setAttribute('aria-expanded', 'false');
        }
        if (chevron) {
          chevron.textContent = '▶';
        }
        if (parentRow) {
          parentRow.classList.remove('is-open');
        }
      }

      function toggleLedgerRow(parentRow) {
        const toggle = parentRow ? parentRow.querySelector('[data-sku-toggle]') : null;
        const chevron = parentRow ? parentRow.querySelector('.sku-ledger-chevron') : null;
        const detailsRow = getLedgerDetailsRow(parentRow);

        if (!parentRow || !detailsRow || !toggle || !chevron) {
          return;
        }

        const isHidden = detailsRow.hasAttribute('hidden');
        if (isHidden) {
          detailsRow.hidden = false;
          detailsRow.removeAttribute('hidden');
          toggle.setAttribute('aria-expanded', 'true');
          chevron.textContent = '▼';
          parentRow.classList.add('is-open');
        } else {
          detailsRow.hidden = true;
          collapseLedgerRow(parentRow);
        }
      }

      function applySkuLedgerFilters() {
        const skuTerm = normalizeLedgerText(skuLedgerSearchSku ? skuLedgerSearchSku.value : '');
        const warehouseTerm = normalizeLedgerText(skuLedgerSearchWarehouse ? skuLedgerSearchWarehouse.value : '');
        const qtyTerm = normalizeLedgerText(skuLedgerSearchQty ? skuLedgerSearchQty.value : '');

        ledgerParentRows.forEach(function (parentRow) {
          const searchBlob = normalizeLedgerText(parentRow.getAttribute('data-ledger-search'));
          const visible = (!skuTerm || searchBlob.indexOf(skuTerm) !== -1)
            && (!warehouseTerm || searchBlob.indexOf(warehouseTerm) !== -1)
            && (!qtyTerm || searchBlob.indexOf(qtyTerm) !== -1);
          const detailsRow = getLedgerDetailsRow(parentRow);

          parentRow.hidden = !visible;

          if (!visible) {
            collapseLedgerRow(parentRow);
          } else if (detailsRow) {
            detailsRow.hidden = detailsRow.hasAttribute('hidden');
          }
        });
      }

      ledgerParentRows.forEach(function (parentRow) {
        const toggle = parentRow.querySelector('[data-sku-toggle]');
        if (!toggle) {
          return;
        }

        toggle.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          if (parentRow.hidden) {
            return;
          }
          toggleLedgerRow(parentRow);
        });
      });

      [skuLedgerSearchSku, skuLedgerSearchWarehouse, skuLedgerSearchQty].forEach(function (field) {
        if (field) {
          field.addEventListener('input', applySkuLedgerFilters);
        }
      });

      applySkuLedgerFilters();
    }

    if (tagLaunchBtn) {
      tagLaunchBtn.addEventListener('click', function () {
        renderTag(selectedOutputRows());
        openModal(tagModal);
      });
    }

    if (printBolBtn) {
      printBolBtn.addEventListener('click', function () {
        if (printModal) {
          closeModal(printModal);
        }
        window.setTimeout(function () {
          if (bolModal) {
            openModal(bolModal);
          }
        }, 140);
      });
    }

    if (printTagBtn) {
      printTagBtn.addEventListener('click', function () {
        renderTag(selectedOutputRows());
        if (printModal) {
          closeModal(printModal);
        }
        window.setTimeout(function () {
          if (tagModal) {
            openModal(tagModal);
          }
        }, 140);
      });
    }

    if (bolLaunchBtn) {
      bolLaunchBtn.addEventListener('click', function () {
        renderBol(selectedOutputRows());
        openModal(bolModal);
      });
    }

    if (skuLedgerLaunchBtn) {
      skuLedgerLaunchBtn.addEventListener('click', function () {
        openModal(skuLedgerModal);
      });
    }

    if (tagPrintBtn) {
      tagPrintBtn.addEventListener('click', function () {
        const selectedRows = selectedOutputRows();
        const labelDataList = labelDataListFromRows(selectedRows);
        const palletOnlyDataList = palletOnlyDataListFromRows(selectedRows);
        if (!labelDataList.length) {
          tagFields.note.textContent = 'Choose one or more rows using the Select checkboxes to print pallet tags.';
          return;
        }
        const win = window.open('', '_blank', 'width=700,height=800');
        if (!win) {
          tagFields.note.textContent = 'Pop-up blocked. Allow pop-ups to print the tag.';
          return;
        }

        const pages = labelDataList.map(function (data) {
          return '<section class=\"print-page\">' + labelMarkup(data) + '</section>';
        }).join('') + palletOnlyDataList.map(function (data) {
          return '<section class=\"print-page\">' + palletOnlyLabelMarkup(data) + '</section>';
        }).join('');

        win.document.write('<!doctype html><html><head><title>Pallet Tag</title><style>@page{size:4in 6in;margin:0;}html,body{width:4in;margin:0;padding:0;background:#fff;}body{font-family:Arial,sans-serif;color:#111827;-webkit-print-color-adjust:exact;print-color-adjust:exact;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;text-rendering:optimizeLegibility;}.print-page{width:4in;height:6in;page-break-after:always;break-after:page;display:block;overflow:hidden;box-sizing:border-box;}.print-page:last-child{page-break-after:auto;break-after:auto;}.print-label{width:4in;height:6in;box-sizing:border-box;display:grid;grid-template-rows:auto auto 1fr auto;gap:.08in;padding:.12in;overflow:hidden;}.print-label__brand{display:flex;align-items:center;justify-content:center;min-height:.55in;padding:.08in;background:#111827;color:#fff;font-size:.14in;font-weight:800;letter-spacing:.08em;text-transform:uppercase;text-align:center;}.print-label__hero{display:grid;align-content:center;gap:.02in;text-align:center;overflow:hidden;}.print-label__eyebrow{margin:0;color:#475569;font-size:.11in;font-weight:800;letter-spacing:.12em;text-transform:uppercase;}.print-label__title{margin:0;font-size:.36in;line-height:1.05;font-weight:900;letter-spacing:.01em;text-transform:uppercase;overflow:hidden;word-break:break-word;overflow-wrap:break-word;}.print-label__subtitle{margin:0;font-size:.14in;line-height:1.2;font-weight:600;}.print-label__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));border:2px solid #1f2937;}.print-label__cell{display:grid;gap:.02in;min-height:.65in;padding:.04in .06in;border-right:2px solid #1f2937;border-bottom:2px solid #1f2937;align-content:center;overflow:hidden;}.print-label__cell:nth-child(2n){border-right:0;}.print-label__cell:nth-last-child(-n+2){border-bottom:0;}.print-label__cell span{font-size:.11in;line-height:1;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#475569;}.print-label__cell strong{font-size:.25in;line-height:1.05;font-weight:900;word-break:break-all;overflow-wrap:break-word;}.print-label__footer{display:flex;justify-content:space-between;gap:.08in;font-size:.11in;font-weight:700;color:#334155;overflow:hidden;}.print-label__footer strong{color:#0f172a;}.print-pallet-only-label{width:4in;height:6in;box-sizing:border-box;display:grid;align-content:center;justify-items:center;gap:.18in;padding:.20in;border:2px solid #111827;overflow:hidden;}.print-pallet-only-label__eyebrow{margin:0;color:#475569;font-size:.14in;font-weight:800;letter-spacing:.12em;text-transform:uppercase;}.print-pallet-only-label__value{font-size:.65in;line-height:1;font-weight:800;letter-spacing:.04em;text-transform:uppercase;text-align:center;word-break:break-all;overflow-wrap:break-word;}</style></head><body>' + pages + '</body></html>');
        win.document.close();
        win.focus();
        win.print();
      });
    }

    if (bolCopyBtn) {
      bolCopyBtn.addEventListener('click', async function () {
        const text = bolTextFromRows(selectedOutputRows());
        try {
          await navigator.clipboard.writeText(text);
          bolFields.note.textContent = 'BOL draft copied to clipboard.';
        } catch (_) {
          bolFields.note.textContent = 'Clipboard copy failed in this browser.';
        }
      });
    }

    if (bolPrintBtn) {
      bolPrintBtn.addEventListener('click', function () {
        const selectedRows = selectedOutputRows();
        if (!applyBolDestinationToRows(selectedRows)) {
          setBolNote('Choose a destination before printing the BOL.', 'error');
          return;
        }
        const bolData = bolDataFromRows(selectedRows);
        if (!bolData || !bolData.destination_location_id) {
          setBolNote('Choose pallet rows that already have a destination 3PL before exporting the BOL.', 'error');
          return;
        }

        const url = bolPdfUrlFromData(bolData);
        const win = openBolPrintWindow(url);
        if (!win) {
          setBolNote('Pop-up blocked. Allow pop-ups to print the BOL.', 'error');
          return;
        }
      });
    }

    if (bolAddMoreBtn) {
      bolAddMoreBtn.addEventListener('click', function () {
        const selectedRows = selectedOutputRows();
        const selectedIds = selectedOutputRowIds();
        if (!selectedIds.length) {
          setBolNote('Select at least one pallet row before sending the BOL.', 'error');
          return;
        }

        if (!applyBolDestinationToRows(selectedRows)) {
          setBolNote('Choose a destination before sending the BOL.', 'error');
          return;
        }

        const bolData = bolDataFromRows(selectedRows);
        if (!bolData || !bolData.destination_location_id) {
          setBolNote('Choose pallet rows that already have a destination 3PL before sending the BOL.', 'error');
          return;
        }

        const csrfInput = document.querySelector('input[name="csrf_token"]');
        const returnLocationInput = document.querySelector('#outwhseLineForm input[name="return_location_id"]');
        if (!csrfInput || !csrfInput.value || !returnLocationInput) {
          setBolNote('Unable to complete the BOL because the form context is missing.', 'error');
          return;
        }

        const bolNumber = bolData && bolData.bol_number ? bolData.bol_number : ('BOL-' + String(Date.now()).slice(-8));
        const printWindow = window.open('', '_blank', 'width=920,height=860');
        if (!printWindow) {
          setBolNote('Pop-up blocked. Allow pop-ups, or use Print BOL before completing.', 'error');
          return;
        }

        const formData = new FormData();
        formData.append('csrf_token', csrfInput.value);
        formData.append('return_location_id', returnLocationInput.value || '');
        formData.append('action', 'complete_bol');
        formData.append('ids_json', JSON.stringify(selectedIds));
        formData.append('bol_number', bolNumber);

        Object.keys(bolData).forEach(function (key) {
          if (bolData[key] != null) {
            formData.append(key, bolData[key]);
          }
        });

        setBolNote('Sending the BOL, updating pallet status to On Route, and opening the print preview...', 'info');

        fetch(pageData.basePageUrl || window.location.pathname, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: formData
        }).then(function (response) {
          return response.text().then(function (text) {
            let payload = null;

            try {
              payload = text ? JSON.parse(text) : null;
            } catch (_) {
              payload = null;
            }

            if (!response.ok || !payload || !payload.success) {
              throw new Error(payload && payload.message ? payload.message : 'Unable to send the BOL.');
            }

            return payload;
          });
        }).then(function (payload) {
          const printUrl = payload.bol_pdf_url || bolPdfUrlFromData(bolData);
          if (!openBolPrintWindow(printUrl, printWindow)) {
            throw new Error('Pop-up blocked while opening the BOL print preview.');
          }

          setBolNote(payload.message || 'BOL sent and print preview opened.', 'success');
          clearBolSelections();
          closeModal(bolModal);

          window.setTimeout(function () {
            window.location.reload();
          }, 600);
        }).catch(function (error) {
          try {
            if (printWindow && !printWindow.closed) {
              printWindow.close();
            }
          } catch (_) {}
          setBolNote(error && error.message ? error.message : 'Unable to send the BOL.', 'error');
        });
      });
    }

    Object.keys(bolAddressSelects).forEach(function (role) {
      const selects = bolAddressSelects[role] || [];
      selects.forEach(function (select) {
        select.addEventListener('change', function () {
          selects.forEach(function (target) {
            if (target && target !== select) {
              target.value = select.value || '';
            }
          });
        });
      });
    });

    modalNodes.forEach(function (modal) {
      const closeTriggers = Array.from(modal.querySelectorAll('[data-close-modal]'));
      closeTriggers.forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
          event.preventDefault();
          closeModal(modal);
          if (modal === lineModal) {
            setLineFormModeCreate();
            setWizardStep(1);
          }
        });
      });
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') {
        return;
      }

      const openModalNode = modalNodes.find(function (modal) {
        return !modal.hidden;
      });

      if (openModalNode) {
        closeModal(openModalNode);
        if (openModalNode === lineModal) {
          setLineFormModeCreate();
          setWizardStep(1);
        }
      }
    });

    updateSelectedState();
    setLineFormModeCreate();
    renderDraftLines();
    syncItemSourceFields();
    setWizardStep(1);
    clearEditQuery();
    populateAddressBookSelects();
    if (pageData.openAddressBook && addressBookModal) {
      openModal(addressBookModal);
    }
  }

  if (document.readyState === 'loading') {
    bootInventoryNotes();
    document.addEventListener('DOMContentLoaded', initOutwhsePage, { once: true });
  } else {
    bootInventoryNotes();
    initOutwhsePage();
  }
})();
