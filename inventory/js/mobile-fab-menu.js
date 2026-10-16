(function () {
  const ROOT_BASE = (window.INVAPP_BASE || '').replace(/\/+$/, '');
  const APP_BASE = ROOT_BASE.endsWith('/inventory') ? ROOT_BASE : `${ROOT_BASE}/inventory`;
  const ORDERS_FEED_URL = `${APP_BASE}/orders_feed.php`;

  const menu = document.getElementById('mobileFabMenu');
  const toggle = document.getElementById('mobileFabMenuToggle');
  const panel = document.getElementById('mobileFabPanel');
  const ordersButton = document.getElementById('ordersLaunchBtn');
  const ordersBackdrop = document.getElementById('ordersBackdrop');
  const ordersCloseBtn = document.getElementById('ordersCloseBtn');
  const ordersRefreshBtn = document.getElementById('ordersRefreshBtn');
  const ordersStatus = document.getElementById('ordersStatus');
  const ordersTableWrap = document.getElementById('ordersTableWrap');
  const ordersHead = document.getElementById('ordersHead');
  const ordersBody = document.getElementById('ordersBody');
  const ordersEmpty = document.getElementById('ordersEmpty');

  console.log('[Mobile FAB: inventory] Initializing', {
    script: document.currentScript?.src,
    menu: Boolean(menu), toggle: Boolean(toggle), panel: Boolean(panel)
  });
  if (!menu || !toggle || !panel) {
    console.warn('[Mobile FAB: inventory] Required menu elements missing');
    return;
  }

  const mq = window.matchMedia('(max-width: 960px)');
  let ordersFeedLoading = null;
  let ordersFeedLoaded = false;

  function setOrdersStatus(message, isError = false) {
    if (!ordersStatus) {
      return;
    }

    ordersStatus.textContent = message;
    ordersStatus.dataset.state = isError ? 'error' : 'normal';
  }

  function renderOrdersTable(rows) {
    if (!ordersHead || !ordersBody || !ordersTableWrap || !ordersEmpty) {
      return;
    }

    ordersHead.innerHTML = '';
    ordersBody.innerHTML = '';

    const headRow = document.createElement('tr');
    [
      { key: 'order_number', label: 'Sales Order' },
      { key: 'status', label: 'Status' },
      { key: 'assigned', label: 'Assigned' },
      { key: 'updated_at', label: 'Updated' },
      { key: 'source_file', label: 'Source' }
    ].forEach((column) => {
      const th = document.createElement('th');
      th.textContent = column.label;
      headRow.appendChild(th);
    });
    ordersHead.appendChild(headRow);

    if (!Array.isArray(rows) || rows.length === 0) {
      ordersTableWrap.hidden = true;
      ordersEmpty.textContent = 'No open sales orders found.';
      ordersEmpty.hidden = false;
      return;
    }

    rows.forEach((row) => {
      const tr = document.createElement('tr');
      const orderNumber = String(row.order_number ?? '').trim() || '-';
      const orderLink = document.createElement('a');
      orderLink.href = `${APP_BASE}/verify_pick.php?action=load_order&order_id=${encodeURIComponent(row.id ?? '')}&order_number=${encodeURIComponent(orderNumber)}`;
      orderLink.textContent = orderNumber;
      orderLink.className = 'btn btn--ghost order-entry__button';

      const orderCell = document.createElement('td');
      orderCell.appendChild(orderLink);
      tr.appendChild(orderCell);

      const statusCell = document.createElement('td');
      statusCell.textContent = String(row.status ?? 'PENDING');
      tr.appendChild(statusCell);

      const assignedCell = document.createElement('td');
      assignedCell.textContent = String(
        row.assigned_user_label ?? (Number(row.assigned_user_id) > 0 ? `User #${row.assigned_user_id}` : 'Unassigned')
      );
      tr.appendChild(assignedCell);

      const updatedCell = document.createElement('td');
      updatedCell.textContent = String(row.updated_at || row.created_at || '-');
      tr.appendChild(updatedCell);

      const sourceCell = document.createElement('td');
      sourceCell.textContent = String(row.source_file ?? '-');
      tr.appendChild(sourceCell);

      ordersBody.appendChild(tr);
    });

    ordersTableWrap.hidden = false;
    ordersEmpty.hidden = true;
  }

  function showOrdersError(message) {
    if (!ordersTableWrap || !ordersEmpty) {
      return;
    }

    ordersTableWrap.hidden = true;
    ordersEmpty.textContent = message || 'Unable to load sales orders.';
    ordersEmpty.hidden = false;
  }

  function applyOrdersPayload(payload) {
    const rows = Array.isArray(payload?.orders) ? payload.orders : [];
    renderOrdersTable(rows);
    ordersFeedLoaded = true;

    const total = typeof payload?.count === 'number' ? payload.count : rows.length;
    const loadedLabel = total === 1 ? '1 order' : `${total} orders`;
    const moreLabel = payload?.has_more ? ' More rows available.' : '';
    setOrdersStatus(`Loaded ${loadedLabel}.${moreLabel}`.trim());
  }

  async function loadOrdersFeed(options = {}) {
    const force = Boolean(options.force);

    if (ordersFeedLoading) {
      return ordersFeedLoading;
    }

    if (ordersFeedLoaded && !force) {
      return true;
    }

    setOrdersStatus('Loading sales orders...');

    ordersFeedLoading = fetch(`${ORDERS_FEED_URL}?limit=50`, {
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(async (response) => {
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || payload.ok === false) {
          const message = (payload && payload.error) ? payload.error : `Unable to load orders (${response.status})`;
          throw new Error(message);
        }

        const rows = Array.isArray(payload.rows) ? payload.rows : [];
        renderOrdersTable(rows);
        ordersFeedLoaded = true;

        const total = typeof payload.count === 'number' ? payload.count : rows.length;
        const loadedLabel = total === 1 ? '1 order' : `${total} orders`;
        const moreLabel = payload?.has_more ? ' More rows available.' : '';
        setOrdersStatus(`Loaded ${loadedLabel}.${moreLabel}`.trim());
        return true;
      })
      .catch((error) => {
        ordersFeedLoaded = false;
        if (error?.name === 'AbortError') {
          return false;
        }

        showOrdersError('Unable to load sales orders.');
        setOrdersStatus(error?.message || 'Unable to load sales orders.', true);
        return false;
      })
      .finally(() => {
        ordersFeedLoading = null;
      });

    return ordersFeedLoading;
  }

  function setOpen(open) {
    menu.dataset.open = open ? 'true' : 'false';
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel.hidden = !open;
  }

  function isOpen() {
    return menu.dataset.open === 'true';
  }

  function setDrawerState(drawer, open) {
    if (!drawer) {
      return false;
    }

    const launchButton = document.getElementById(drawer.id.replace('Drawer', 'LaunchBtn'));

    drawer.hidden = !open;
    drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
    drawer.dataset.open = open ? 'true' : 'false';
    drawer.style.display = open ? 'block' : '';
    drawer.style.visibility = open ? 'visible' : '';
    drawer.style.pointerEvents = open ? '' : 'none';

    if ('inert' in drawer) {
      drawer.inert = !open;
    }

    if (launchButton) {
      launchButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (drawer.id === 'scanDrawer') {
      document.body.dataset.scanOpen = open ? 'true' : 'false';
    }

    if (drawer.id === 'ordersDrawer') {
      document.body.dataset.ordersOpen = open ? 'true' : 'false';
      if (open) {
        void loadOrdersFeed();
      }
    }

    return true;
  }

  function syncLaunchState(drawer) {
    if (!drawer) {
      return;
    }

    const launchButton = document.getElementById(drawer.id.replace('Drawer', 'LaunchBtn'));
    const open =
      drawer.dataset.open === 'true' ||
      drawer.classList.contains('is-open') ||
      (!drawer.hidden && drawer.getAttribute('aria-hidden') !== 'true' && drawer.style.display !== 'none');

    if (launchButton) {
      launchButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (drawer.id === 'scanDrawer') {
      document.body.dataset.scanOpen = open ? 'true' : 'false';
    }

    if (drawer.id === 'ordersDrawer') {
      document.body.dataset.ordersOpen = open ? 'true' : 'false';
    }
  }

  function getDrawer(id) {
    return document.getElementById(id);
  }

  function hideDrawer(id) {
    return setDrawerState(getDrawer(id), false);
  }

  function toggleDrawer(id) {
    const drawer = getDrawer(id);
    if (!drawer) {
      return false;
    }
    const open = drawer.dataset.open === 'true';
    return setDrawerState(drawer, !open);
  }

  ['appDrawer', 'calcDrawer', 'scanDrawer', 'ordersDrawer'].forEach((drawerId) => {
    const drawer = getDrawer(drawerId);
    if (!drawer || typeof MutationObserver !== 'function') {
      return;
    }

    const observer = new MutationObserver(() => syncLaunchState(drawer));
    observer.observe(drawer, {
      attributes: true,
      attributeFilter: ['hidden', 'aria-hidden', 'data-open', 'style', 'class']
    });
    syncLaunchState(drawer);
  });

  function openHelpDrawer() {
    const helpDrawer = document.getElementById('helpDrawer');
    const helpButton = document.getElementById('helpButton');
    if (!helpDrawer || !helpButton) {
      return false;
    }

    const rect = helpButton.getBoundingClientRect();
    const x = rect.left + rect.width / 2;
    const y = rect.top + rect.height / 2;
    helpDrawer.style.setProperty('--help-origin-x', `${x}px`);
    helpDrawer.style.setProperty('--help-origin-y', `${y}px`);
    const dialog = helpDrawer.querySelector('.help-modal__dialog');
    if (dialog) {
      dialog.style.setProperty('--help-origin-x', `${x}px`);
      dialog.style.setProperty('--help-origin-y', `${y}px`);
    }

    document.body.classList.add('help-open');
    helpDrawer.classList.add('is-visible');
    requestAnimationFrame(() => {
      helpDrawer.classList.add('is-open');
    });
    helpDrawer.setAttribute('aria-hidden', 'false');
    helpButton.setAttribute('aria-expanded', 'true');
    const focusTarget = helpDrawer.querySelector('#helpSearchInput') || dialog || helpDrawer;
    if (focusTarget && typeof focusTarget.focus === 'function') {
      focusTarget.focus();
    }
    return true;
  }

  function activateFabTarget(targetId) {
    switch (targetId) {
      case 'calcLaunchBtn': {
        const launchButton = document.getElementById(targetId);
        console.log('[Mobile FAB: inventory] Forwarding launch click', {
          targetId, found: Boolean(launchButton)
        });
        if (!launchButton) {
          return false;
        }
        launchButton.click();
        return true;
      }
      case 'scanLaunchBtn':
        document.body.dataset.scanOpen = 'true';
        return setDrawerState(document.getElementById('scanDrawer'), true);
      case 'ordersLaunchBtn':
        document.body.dataset.ordersOpen = 'true';
        return setDrawerState(document.getElementById('ordersDrawer'), true);
      case 'appLaunchBtn':
        return setDrawerState(document.getElementById('appDrawer'), true);
      case 'helpButton':
        return openHelpDrawer();
      default:
        return false;
    }
  }

  const appButton = getDrawer('appLaunchBtn');
  const appBackdrop = getDrawer('appBackdrop');
  const scanButton = getDrawer('scanLaunchBtn');
  const scanCloseBtn = getDrawer('scanCloseBtn');
  const scanBackdrop = getDrawer('scanBackdrop');
  const messageMenuButton = panel.querySelector('[data-fab-target="messageLaunchBtn"]');
  const messageLaunchButton = document.getElementById('messageLaunchBtn');

  if (messageMenuButton) {
    messageMenuButton.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();

      console.log('[Mobile FAB: inventory] Messages button tapped', {
        launcherFound: Boolean(messageLaunchButton),
        drawerAriaHidden: document.getElementById('messageDrawer')?.getAttribute('aria-hidden')
      });

      if (!messageLaunchButton) {
        console.error('[Mobile FAB: inventory] #messageLaunchBtn was not found');
        return;
      }

      messageLaunchButton.click();
      setOpen(false);

      console.log('[Mobile FAB: inventory] Message launcher clicked', {
        drawerAriaHidden: document.getElementById('messageDrawer')?.getAttribute('aria-hidden')
      });
    });
  } else {
    console.warn('[Mobile FAB: inventory] Messages menu button was not found');
  }

  if (appButton) {
    appButton.addEventListener('click', (event) => {
      event.preventDefault();
      toggleDrawer('appDrawer');
    });
  }

  if (appBackdrop) {
    appBackdrop.addEventListener('click', () => {
      hideDrawer('appDrawer');
    });
  }

  if (scanBackdrop) {
    scanBackdrop.addEventListener('click', () => {
      hideDrawer('scanDrawer');
    }, true);
  }

  if (scanCloseBtn) {
    scanCloseBtn.addEventListener('click', (event) => {
      event.preventDefault();
      hideDrawer('scanDrawer');
    }, true);
  }

  if (ordersButton) {
    ordersButton.addEventListener('click', (event) => {
      event.preventDefault();
      toggleDrawer('ordersDrawer');
    });
  }

  if (ordersRefreshBtn) {
    ordersRefreshBtn.addEventListener('click', (event) => {
      event.preventDefault();
      void loadOrdersFeed({ force: true });
    });
  }

  if (ordersBackdrop) {
    ordersBackdrop.addEventListener('click', () => {
      hideDrawer('ordersDrawer');
    });
  }

  if (ordersCloseBtn) {
    ordersCloseBtn.addEventListener('click', (event) => {
      event.preventDefault();
      hideDrawer('ordersDrawer');
    });
  }

  toggle.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    setOpen(!isOpen());
  });

  panel.addEventListener('click', (event) => {
    event.stopPropagation();
    const item = event.target.closest('[data-fab-target]');
    if (!item) {
      return;
    }

    const targetId = item.getAttribute('data-fab-target');
    console.log('[Mobile FAB: inventory] Menu item selected', { targetId });
    const activated = activateFabTarget(targetId);
    console.log('[Mobile FAB: inventory] Action dispatched', {
      targetId, activated,
      messageDrawerAriaHidden: document.getElementById('messageDrawer')?.getAttribute('aria-hidden')
    });

    setOpen(false);
  });

  document.addEventListener('click', (event) => {
    if (!isOpen()) {
      return;
    }
    if (menu.contains(event.target)) {
      return;
    }
    setOpen(false);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isOpen()) {
      setOpen(false);
      toggle.focus();
    }
  });

  const sync = () => {
    if (!mq.matches) {
      setOpen(false);
    } else {
      panel.hidden = menu.dataset.open !== 'true';
    }
  };

  if (typeof mq.addEventListener === 'function') {
    mq.addEventListener('change', sync);
  } else if (typeof mq.addListener === 'function') {
    mq.addListener(sync);
  }

  sync();
})();
