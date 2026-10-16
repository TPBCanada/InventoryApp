(function () {
  function initOutwhseMovementsPage() {
    const searchInput = document.getElementById('outwhseMovementsSearch');
    const showTpbReceivedCheckbox = document.getElementById('outwhseShowTpbReceived');
    const spaceFilter = document.getElementById('outwhseSpaceStatusFilter');
    const tableWrap = document.getElementById('outwhseMovementsTableWrap');
    const emptyState = document.getElementById('outwhseMovementsEmpty');
    const visibleCount = document.getElementById('outwhseMovementsVisibleCount');
    const shipmentModal = document.querySelector('[data-shipment-modal]');
    const shipmentModalContent = shipmentModal ? shipmentModal.querySelector('[data-shipment-modal-content]') : null;
    const shipmentModalTitle = document.getElementById('outwhseShipmentModalTitle');
    const shipmentModalCloseButtons = Array.from(document.querySelectorAll('[data-shipment-modal-close]'));
    if (!searchInput || !spaceFilter || !tableWrap || !emptyState) {
      return;
    }

    const rows = Array.from(document.querySelectorAll('#outwhseMovementsTable tbody tr'));
    const shipmentCards = Array.from(document.querySelectorAll('[data-shipment-card]'));
    const shipmentToggleButtons = Array.from(document.querySelectorAll('[data-shipment-toggle], .outwhseShipmentModal'));

    function escapeHtml(value) {
      return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    }

    function formatNumber(value) {
      const number = Number(value);
      return Number.isFinite(number) ? number.toLocaleString() : escapeHtml(value);
    }

    function shipmentBolPdfUrl(shipment) {
      const params = new URLSearchParams();

      params.set('bol_number', shipment && shipment.bol_number ? shipment.bol_number : '');
      params.set('warehouse_location_name', shipment && shipment.warehouse_location_name ? shipment.warehouse_location_name : '');
      params.set('pallet_count', shipment && shipment.pallet_count != null ? String(shipment.pallet_count) : '0');
      params.set('qty_total', shipment && shipment.qty_total != null ? String(shipment.qty_total) : '0');
      params.set('created_at', shipment && shipment.created_at_display ? shipment.created_at_display : '');

      if (shipment && Array.isArray(shipment.rows)) {
        params.set('rows_json', JSON.stringify(shipment.rows));
      }

      return 'outwhse_bol_pdf.php?' + params.toString();
    }

    function buildShipmentMarkup(shipment) {
      const fromLocation = shipment && shipment.source_location ? shipment.source_location : (shipment && shipment.warehouse_location_name ? shipment.warehouse_location_name : 'N/A');
      const toLocation = shipment && shipment.destination_location ? shipment.destination_location : 'N/A';
      const rowsMarkup = Array.isArray(shipment.rows) ? shipment.rows.map(function (row) {
        return ''
          + '<li class="outwhse-shipment-card__list-item">'
          + '<strong>' + escapeHtml(row.pallet_number || 'N/A') + '</strong>'
          + '<span>' + escapeHtml(row.sku || 'Manual') + '</span>'
          + '<span>' + escapeHtml(row.description || 'N/A') + '</span>'
          + '<span>Qty ' + escapeHtml(row.qty || 'N/A') + '</span>'
          + '</li>';
      }).join('') : '';

        return ''
        + '<div class="outwhse-shipment-card__content outwhse-shipment-card__content--modal">'
        + '<div class="outwhse-shipment-card__row">'
        + '<span class="outwhse-shipment-card__label">From</span>'
        + '<strong>' + escapeHtml(fromLocation) + '</strong>'
        + '</div>'
        + '<div class="outwhse-shipment-card__row">'
        + '<span class="outwhse-shipment-card__label">To</span>'
        + '<strong>' + escapeHtml(toLocation) + '</strong>'
        + '</div>'
        + '<div class="outwhse-shipment-card__row">'
        + '<span class="outwhse-shipment-card__label">Pallets</span>'
        + '<strong>' + formatNumber(shipment.pallet_count || 0) + '</strong>'
        + '</div>'
        
        + '<div class="outwhse-shipment-card__row">'
        + '<span class="outwhse-shipment-card__label">Qty</span>'
        + '<strong>' + formatNumber(shipment.qty_total || 0) + '</strong>'
        + '</div>'
        + '<div class="outwhse-shipment-card__row">'
        + '<span class="outwhse-shipment-card__label">Created</span>'
        + '<strong>' + escapeHtml(shipment.created_at_display || 'N/A') + '</strong>'
        + '</div>'
        + '<ul class="outwhse-shipment-card__list">'
        + rowsMarkup
        + '</ul>'
        + '<form method="post" class="outwhse-shipment-card__actions">'
        + '<input type="hidden" name="csrf_token" value="' + escapeHtml(shipment.csrf_token || '') + '">'
        + '<input type="hidden" name="return_location_id" value="' + escapeHtml(shipment.return_location_id || '') + '">'
        + '<input type="hidden" name="bol_number" value="' + escapeHtml(shipment.bol_number || '') + '">'
        + '<button class="outwhse-btn outwhse-btn--secondary" type="button" data-shipment-print-bol="' + escapeHtml(shipmentBolPdfUrl(shipment)) + '">Print BOL</button>'
        + '<button class="outwhse-btn outwhse-btn--danger" type="submit" name="action" value="cancel_shipment">Cancel Shipment</button>'
        + '<button class="outwhse-btn outwhse-btn--primary" type="submit" name="action" value="receive_shipment">Mark Received</button>'
        + '</form>'
        + '</div>';
    }

    function closeShipmentModal() {
      if (!shipmentModal) {
        return;
      }

      shipmentModal.classList.remove('is-open');
      shipmentModal.setAttribute('aria-hidden', 'true');
      window.setTimeout(function () {
        shipmentModal.hidden = true;
        shipmentModal.setAttribute('hidden', 'hidden');
        document.body.classList.remove('outwhse-modal-open');
        if (shipmentModalContent) {
          shipmentModalContent.innerHTML = '';
        }
        shipmentToggleButtons.forEach(function (button) {
          button.setAttribute('aria-expanded', 'false');
        });
      }, 120);
    }

    function openShipmentModal(card, triggerButton) {
      if (!shipmentModal || !shipmentModalContent || !card || !triggerButton) {
        return;
      }

      const payloadNode = card.querySelector('[data-shipment-json]');
      if (!payloadNode) {
        return;
      }

      let shipment = null;
      try {
        shipment = JSON.parse(payloadNode.textContent || '{}');
      } catch (error) {
        return;
      }

      if (shipmentModalTitle) {
        shipmentModalTitle.textContent = 'Shipment Contents - ' + String(shipment.bol_number || '').trim();
      }

      shipmentModalContent.innerHTML = buildShipmentMarkup(shipment);
      shipmentToggleButtons.forEach(function (button) {
        button.setAttribute('aria-expanded', 'false');
      });
      shipmentModal.hidden = false;
      shipmentModal.removeAttribute('hidden');
      shipmentModal.setAttribute('aria-hidden', 'false');
      requestAnimationFrame(function () {
        shipmentModal.classList.add('is-open');
      });
      document.body.classList.add('outwhse-modal-open');
      triggerButton.setAttribute('aria-expanded', 'true');
    }

    shipmentModalCloseButtons.forEach(function (button) {
      button.addEventListener('click', closeShipmentModal);
    });

    if (shipmentModal) {
      shipmentModal.addEventListener('click', function (event) {
        const printButton = event.target.closest('[data-shipment-print-bol]');
        if (printButton) {
          event.preventDefault();
          event.stopPropagation();

          const printUrl = printButton.getAttribute('data-shipment-print-bol');
          if (printUrl) {
            window.open(printUrl, '_blank', 'width=920,height=860');
          }
          return;
        }

        const closeTarget = event.target.closest('[data-shipment-modal-close]');
        if (closeTarget) {
          closeShipmentModal();
        }
      });
    }

    function getShipmentToggle(eventTarget) {
      return eventTarget && typeof eventTarget.closest === 'function'
        ? eventTarget.closest('[data-shipment-toggle], .outwhseShipmentModal')
        : null;
    }

    function handleShipmentToggle(event) {
      const toggle = getShipmentToggle(event.target);
      if (!toggle || !document.contains(toggle)) {
        return;
      }

      const card = toggle.closest('[data-shipment-card]');
      if (!card) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      openShipmentModal(card, toggle);
    }

    shipmentToggleButtons.forEach(function (toggle) {
      const card = toggle.closest('[data-shipment-card]');
      if (!card) {
        return;
      }

      toggle.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        openShipmentModal(card, toggle);
      });
    });

    document.addEventListener('click', handleShipmentToggle, true);

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && shipmentModal && !shipmentModal.hidden) {
        closeShipmentModal();
      }
    });

    function applyFilters() {
      if (!rows.length) {
        if (visibleCount) {
          visibleCount.textContent = '0';
        }
        tableWrap.hidden = true;
        emptyState.hidden = false;
        return;
      }

      const query = searchInput.value.trim().toLowerCase();
      const spaceStatus = spaceFilter.value;
      const showHistoryOnly = !showTpbReceivedCheckbox || showTpbReceivedCheckbox.checked;
      let shown = 0;

      rows.forEach(function (row) {
        const haystack = row.dataset.search || '';
        const rowSpace = row.dataset.spaceStatus || '';
        const isHistoryOnly = row.dataset.historyOnly === '1';
        const matchesSearch = !query || haystack.indexOf(query) !== -1;
        const matchesType = true;
        const matchesSpace = spaceStatus === 'all' || rowSpace === spaceStatus;
        const matchesHistory = showHistoryOnly || !isHistoryOnly;
        const isVisible = matchesSearch && matchesType && matchesSpace && matchesHistory;
        row.hidden = !isVisible;
        if (isVisible) {
          shown += 1;
        }
      });

      if (visibleCount) {
        visibleCount.textContent = shown.toLocaleString();
      }
      tableWrap.hidden = shown === 0;
      emptyState.hidden = shown !== 0;
    }

    searchInput.addEventListener('input', applyFilters);
    spaceFilter.addEventListener('change', applyFilters);
    if (showTpbReceivedCheckbox) {
      showTpbReceivedCheckbox.addEventListener('change', function () {
        const nextUrl = new URL(window.location.href);
        if (showTpbReceivedCheckbox.checked) {
          nextUrl.searchParams.set('show_completed', '1');
        } else {
          nextUrl.searchParams.delete('show_completed');
        }
        window.location.href = nextUrl.toString();
      });
    }

    applyFilters();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initOutwhseMovementsPage, { once: true });
  } else {
    initOutwhseMovementsPage();
  }
})();
