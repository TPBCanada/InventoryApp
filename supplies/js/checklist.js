// /js/checklist.js
(function () {
  // Find all checklist forms that define a storage key
  const forms = document.querySelectorAll('form[data-storage-key]');
  if (!forms.length) return;

  forms.forEach(form => {
    const STORAGE_KEY = form.dataset.storageKey || 'checklist_default';

    const checkboxes = Array.from(
      form.querySelectorAll('input[type="checkbox"]')
    );

    const statusText = form.querySelector('#statusText, .status-pill');
    const saveBtn = form.querySelector('#saveBtn');
    const clearBtn = form.querySelector('#clearBtn');

    // If this form isn't fully wired, skip it
    if (!statusText || !saveBtn || !clearBtn) return;

    function applyState(state) {
      if (!state) return;
      checkboxes.forEach(cb => {
        cb.checked = !!state[cb.name];
      });
    }

    function loadState() {
      try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return;
        const state = JSON.parse(raw);
        applyState(state);
        statusText.textContent = 'Restored from last save';
      } catch (e) {
        console.error('Failed to restore checklist state', e);
      }
    }

    function collectState() {
      const out = {};
      checkboxes.forEach(cb => {
        out[cb.name] = cb.checked;
      });
      return out;
    }

    function saveState() {
      try {
        const state = collectState();
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        statusText.textContent = 'Saved';
      } catch (e) {
        console.error('Failed to save checklist state', e);
        statusText.textContent = 'Save failed';
      }
    }

    function clearState() {
      checkboxes.forEach(cb => (cb.checked = false));
      localStorage.removeItem(STORAGE_KEY);
      statusText.textContent = 'Cleared';
    }

    saveBtn.addEventListener('click', saveState);
    clearBtn.addEventListener('click', clearState);

    form.addEventListener('change', function () {
      statusText.textContent = 'Unsaved changes';
    });

    // Initial load
    loadState();
  });
})();
