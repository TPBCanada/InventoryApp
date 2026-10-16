(function () {
  const menu = document.getElementById('mobileFabMenu');
  const toggle = document.getElementById('mobileFabMenuToggle');
  const panel = document.getElementById('mobileFabPanel');

  if (!menu || !toggle || !panel) {
    return;
  }

  const mq = window.matchMedia('(max-width: 960px)');

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
        const calcLaunchBtn = document.getElementById('calcLaunchBtn');
        if (!calcLaunchBtn) {
          return false;
        }
        calcLaunchBtn.click();
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
  const ordersButton = getDrawer('ordersLaunchBtn');
  const ordersBackdrop = getDrawer('ordersBackdrop');
  const ordersCloseBtn = getDrawer('ordersCloseBtn');

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
    activateFabTarget(targetId);

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
