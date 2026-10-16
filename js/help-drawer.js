(() => {
  const helpDrawer = document.getElementById('helpDrawer');
  const helpButton = document.getElementById('helpButton');
  const closeButton = document.getElementById('helpDrawerClose');
  const backdrop = document.getElementById('helpBackdrop');

  if (!helpDrawer || !helpButton || !closeButton || !backdrop) {
    return;
  }

  const dialog = helpDrawer.querySelector('.help-modal__dialog');
  const searchInput = document.getElementById('helpSearchInput');
  const previewWrapper = helpDrawer.querySelector('.help-modal__preview');
  const previewImage = document.getElementById('helpCalendarImage');
  const previewCopy = document.getElementById('helpCalendarCopy');
  const previewHeading = document.getElementById('helpPreviewHeading');
  const previewTiles = helpDrawer.querySelectorAll('.help-modal__tile[data-preview-image]');

  if (!dialog) {
    return;
  }

  function setOrigin() {
    const rect = helpButton.getBoundingClientRect();
    const x = rect.left + rect.width / 2;
    const y = rect.top + rect.height / 2;

    helpDrawer.style.setProperty('--help-origin-x', `${x}px`);
    helpDrawer.style.setProperty('--help-origin-y', `${y}px`);
    dialog.style.setProperty('--help-origin-x', `${x}px`);
    dialog.style.setProperty('--help-origin-y', `${y}px`);
  }

  function resetPreview() {
    if (!previewWrapper) return;
    previewWrapper.classList.remove('is-visible');
    previewImage && (previewImage.src = '');
    previewCopy && (previewCopy.textContent = '');
    previewHeading && (previewHeading.textContent = '');
    previewTiles.forEach((tile) => tile.classList.remove('is-active'));
  }

  function openDrawer() {
    setOrigin();
    document.body.classList.add('help-open');
    helpDrawer.classList.add('is-visible');
    requestAnimationFrame(() => {
      helpDrawer.classList.add('is-open');
    });
    helpDrawer.setAttribute('aria-hidden', 'false');
    helpButton.setAttribute('aria-expanded', 'true');
    document.addEventListener('keydown', handleKeydown);
    (searchInput ?? dialog).focus();
  }

  function closeDrawer() {
    if (!helpDrawer.classList.contains('is-open')) {
      return;
    }
    resetPreview();
    previewWrapper && previewWrapper.classList.remove('is-visible');
    helpDrawer.classList.remove('is-open');
    helpButton.setAttribute('aria-expanded', 'false');
    document.removeEventListener('keydown', handleKeydown);
    document.body.classList.remove('help-open');
  }

  function handleKeydown(event) {
    if (event.key === 'Escape') {
      closeDrawer();
    }
  }

  function activatePreview(tile) {
    if (!tile || !previewWrapper || !previewImage || !previewCopy) {
      return;
    }
    const src = tile.dataset.previewImage;
    if (!src) {
      return;
    }
    previewImage.src = src;
    previewImage.alt = tile.dataset.previewHeading || tile.textContent.trim();
    previewCopy.textContent = tile.dataset.previewCopy || '';
    previewHeading && (previewHeading.textContent = tile.dataset.previewHeading || '');
    previewWrapper.classList.add('is-visible');
    previewTiles.forEach((item) => item.classList.toggle('is-active', item === tile));
    previewWrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  dialog.addEventListener('transitionend', (event) => {
    if (event.propertyName !== 'clip-path') {
      return;
    }
    if (helpDrawer.classList.contains('is-open')) {
      return;
    }
    helpDrawer.classList.remove('is-visible');
    helpDrawer.setAttribute('aria-hidden', 'true');
    helpButton.focus();
  });

  helpButton.addEventListener('click', () => {
    if (helpDrawer.classList.contains('is-open')) {
      closeDrawer();
      return;
    }
    openDrawer();
  });

  closeButton.addEventListener('click', closeDrawer);
  backdrop.addEventListener('click', closeDrawer);

  previewTiles.forEach((tile) => {
    tile.addEventListener('click', (event) => {
      event.preventDefault();
      activatePreview(tile);
    });
  });
})();
