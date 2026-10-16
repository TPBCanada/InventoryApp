(function () {
  const tabsRoot = document.querySelector('[data-checklist-tabs]');
  const form = document.getElementById('checklistForm');
  const prevBtn = document.getElementById('prevCategoryBtn');
  const nextBtn = document.getElementById('nextCategoryBtn');
  const stepText = document.getElementById('categoryStepText');
  const progressLabel = document.getElementById('checklistProgressLabel');
  const progressPercent = document.getElementById('checklistProgressPercent');
  const progressFill = document.getElementById('checklistProgressFill');
  const statusText = document.getElementById('statusText');
  const saveBtn = document.getElementById('saveBtn');
  const showFinalizeBtn = document.getElementById('showFinalizeBtn');
  const finalizeSection = document.getElementById('finalizeSection');
  const recipientInput = document.getElementById('recipient_email');
  const commentsField = document.getElementById('comments');

  if (!tabsRoot || !form) {
    return;
  }

  const tabs = Array.from(tabsRoot.querySelectorAll('.checklist-category-button'));
  const panels = Array.from(tabsRoot.querySelectorAll('.checklist-panel'));
  if (tabs.length === 0 || panels.length === 0) {
    return;
  }

  let activeIndex = Math.max(0, tabs.findIndex((tab) => tab.classList.contains('is-active')));
  if (activeIndex < 0) activeIndex = 0;
  const statusButtons = Array.from(form.querySelectorAll('.checklist-status__option'));
  const hiddenStatusInputs = Array.from(form.querySelectorAll('input[data-item-id]'));
  const hiddenStatusByItemId = new Map(
    hiddenStatusInputs.map((input) => [String(input.dataset.itemId || ''), input]).filter(([id]) => id !== '')
  );
  const noteFields = new Map(
    Array.from(form.querySelectorAll('[data-item-note-id]')).map((field) => [String(field.dataset.itemNoteId || ''), field]).filter(([id]) => id !== '')
  );
  const mediaWrappers = new Map(
    Array.from(form.querySelectorAll('[data-item-media-wrap]')).map((field) => [String(field.dataset.itemMediaWrap || ''), field]).filter(([id]) => id !== '')
  );
  const buttonsByItemId = new Map();
  let touchStartX = 0;
  let touchStartY = 0;
  let touchEndX = 0;
  let touchEndY = 0;
  let swipeLocked = false;
  const swipeThreshold = 80;
  const swipeContainer = form || document.body;

  statusButtons.forEach((button) => {
    const itemId = String(button.dataset.itemStatusId || '');
    if (!itemId) {
      return;
    }
    if (!buttonsByItemId.has(itemId)) {
      buttonsByItemId.set(itemId, []);
    }
    buttonsByItemId.get(itemId).push(button);
  });

  function setActive(index, focusTab = false) {
    if (index < 0 || index >= tabs.length) {
      return;
    }

    activeIndex = index;

    tabs.forEach((tab, tabIndex) => {
      const isActive = tabIndex === index;
      tab.classList.toggle('is-active', isActive);
      tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
      tab.tabIndex = isActive ? 0 : -1;
    });

    panels.forEach((panel, panelIndex) => {
      const isActive = panelIndex === index;
      panel.hidden = !isActive;
      panel.classList.toggle('is-active', isActive);
    });

    if (stepText) {
      stepText.textContent = `Category ${index + 1} of ${tabs.length}`;
    }

    if (focusTab) {
      tabs[index].focus();
    }
  }

  function updateProgress() {
    const total = hiddenStatusInputs.length;
    const complete = hiddenStatusInputs.filter((input) => (input.value || '').trim() !== '').length;
    const percent = total > 0 ? Math.round((complete / total) * 100) : 0;

    if (progressLabel) {
      progressLabel.textContent = `${complete} / ${total} items complete`;
    }
    if (progressPercent) {
      progressPercent.textContent = `${percent}%`;
    }
    if (progressFill) {
      progressFill.style.width = `${percent}%`;
    }
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function formatStatus(status) {
    if (status === 'pass') return 'Pass';
    if (status === 'fail') return 'Fail';
    if (status === 'na') return 'N/A';
    return status || 'Unspecified';
  }

  function collectInspectionItems() {
    return hiddenStatusInputs.map((input) => {
      const itemId = String(input.dataset.itemId || '');
      const noteField = noteFields.get(itemId);
      const mediaInput = input.closest('li') ? input.closest('li').querySelector('input[type="file"][data-item-media-id]') : null;
      const mediaNames = mediaInput && mediaInput.files ? Array.from(mediaInput.files).map((file) => file.name) : [];
      const labelNode = input.closest('li') ? input.closest('li').querySelector('.checklist-item-label') : null;

      return {
        item_id: itemId,
        label: labelNode ? labelNode.textContent.trim() : `Item ${itemId}`,
        status: (input.value || '').trim(),
        status_label: formatStatus((input.value || '').trim()),
        note: noteField ? String(noteField.value || '').trim() : '',
        photos: mediaNames,
      };
    });
  }

  function buildReportText(items, submittedAt) {
    const inspectionType = form.querySelector('input[name="inspection_type"]')?.value || 'Inspection';
    const truckLabel = form.querySelector('select[name="truck_label"]')?.value || '';
    const lines = [];
    lines.push(`Inspection completed: ${submittedAt}`);
    lines.push(`Checklist: ${inspectionType}`);
    lines.push('');

    if (truckLabel !== '') {
      lines.push(`Lift Truck: ${truckLabel}`);
      lines.push('');
    }

    if (recipientInput && recipientInput.value.trim() !== '') {
      lines.push(`Recipient: ${recipientInput.value.trim()}`);
      lines.push('');
    }

    if (commentsField && commentsField.value.trim() !== '') {
      lines.push('Comments:');
      lines.push(commentsField.value.trim());
      lines.push('');
    }

    lines.push('Items:');
    items.forEach((item) => {
      lines.push(`- ${item.label} [${item.status_label}]`);
      if (item.note) {
        lines.push(`  Note: ${item.note}`);
      }
      if (item.photos.length > 0) {
        lines.push(`  Photos: ${item.photos.join(', ')}`);
      }
    });

    return lines.join('\n');
  }

  function buildReportHtml(items, submittedAt) {
    const inspectionType = form.querySelector('input[name="inspection_type"]')?.value || 'Inspection';
    const truckLabel = form.querySelector('select[name="truck_label"]')?.value || '';
    const rows = items.map((item) => {
      const note = item.note ? `<div style="margin-top:4px;color:#4b5563;">${escapeHtml(item.note)}</div>` : '';
      const photos = item.photos.length > 0
        ? `<div data-photo-item="${escapeHtml(item.item_id)}" style="margin-top:4px;color:#6b7280;">Photos: ${escapeHtml(item.photos.join(', '))}</div>`
        : '';
      return `
        <tr>
          <td style="padding:10px;border:1px solid #d1d5db;vertical-align:top;">${escapeHtml(item.label)}</td>
          <td style="padding:10px;border:1px solid #d1d5db;vertical-align:top;">${escapeHtml(item.status_label)}</td>
          <td style="padding:10px;border:1px solid #d1d5db;vertical-align:top;">${note}${photos}</td>
        </tr>
      `;
    }).join('');

    const recipientLine = recipientInput && recipientInput.value.trim() !== ''
      ? `<p><strong>Recipient:</strong> ${escapeHtml(recipientInput.value.trim())}</p>`
      : '';
    const commentsBlock = commentsField && commentsField.value.trim() !== ''
      ? `<div style="margin:16px 0;padding:12px;border-left:4px solid #0ea5e9;background:#f8fafc;"><strong>Comments</strong><div style="margin-top:6px;white-space:pre-wrap;">${escapeHtml(commentsField.value.trim())}</div></div>`
      : '';

    return `
      <div style="font-family:Arial,sans-serif;color:#111827;line-height:1.45;">
        <h2 style="margin:0 0 12px;">Inspection completed</h2>
        <p><strong>Completed:</strong> ${escapeHtml(submittedAt)}</p>
        <p><strong>Checklist:</strong> ${escapeHtml(inspectionType)}</p>
        ${truckLabel !== '' ? `<p><strong>Lift Truck:</strong> ${escapeHtml(truckLabel)}</p>` : ''}
        ${recipientLine}
        ${commentsBlock}
        <table style="width:100%;border-collapse:collapse;margin-top:16px;">
          <thead>
            <tr>
              <th style="padding:10px;border:1px solid #d1d5db;text-align:left;background:#f3f4f6;">Item</th>
              <th style="padding:10px;border:1px solid #d1d5db;text-align:left;background:#f3f4f6;">Status</th>
              <th style="padding:10px;border:1px solid #d1d5db;text-align:left;background:#f3f4f6;">Details</th>
            </tr>
          </thead>
          <tbody>
            ${rows}
          </tbody>
        </table>
      </div>
    `;
  }

  function setBusy(isBusy, message) {
    if (saveBtn) {
      saveBtn.disabled = isBusy;
      saveBtn.textContent = isBusy ? 'Sending report...' : 'Finalize & Sign';
    }
    if (statusText && message) {
      statusText.textContent = message;
    }
  }

  function setStatusForItem(itemId, status) {
    const input = hiddenStatusByItemId.get(String(itemId));
    if (!input) {
      return;
    }

    input.value = status;

    const itemButtons = buttonsByItemId.get(String(itemId)) || [];
    itemButtons.forEach((button) => {
      const isActive = button.dataset.statusOption === status;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });

    const detailVisible = status === 'fail' || status === 'na';
    const noteField = noteFields.get(String(itemId));
    const mediaWrapper = mediaWrappers.get(String(itemId));
    if (noteField) {
      noteField.hidden = !detailVisible;
      noteField.toggleAttribute('aria-hidden', !detailVisible);
    }
    if (mediaWrapper) {
      mediaWrapper.hidden = !detailVisible;
      mediaWrapper.toggleAttribute('aria-hidden', !detailVisible);
    }

    updateProgress();
  }

  function handleTabSwipe() {
    if (tabs.length === 0) {
      return;
    }

    const horizontalDistance = touchEndX - touchStartX;

    if (horizontalDistance < -swipeThreshold) {
      const nextIndex = (activeIndex + 1) % tabs.length;
      setActive(nextIndex, true);
    }

    if (horizontalDistance > swipeThreshold) {
      const prevIndex = (activeIndex - 1 + tabs.length) % tabs.length;
      setActive(prevIndex, true);
    }
  }

  if (swipeContainer) {
    swipeContainer.addEventListener(
      'touchstart',
      (event) => {
        if (!event.changedTouches || event.changedTouches.length === 0) {
          return;
        }
        touchStartX = event.changedTouches[0].screenX;
        touchStartY = event.changedTouches[0].screenY;
        touchEndX = touchStartX;
        touchEndY = touchStartY;
        swipeLocked = false;
      },
      { passive: true }
    );

    swipeContainer.addEventListener(
      'touchmove',
      (event) => {
        if (!event.touches || event.touches.length === 0) {
          return;
        }

        touchEndX = event.touches[0].screenX;
        touchEndY = event.touches[0].screenY;

        const deltaX = Math.abs(touchEndX - touchStartX);
        const deltaY = Math.abs(touchEndY - touchStartY);
        if (!swipeLocked && deltaX > swipeThreshold / 2 && deltaX > deltaY) {
          swipeLocked = true;
          event.preventDefault();
        }
      },
      { passive: false }
    );

    swipeContainer.addEventListener(
      'touchend',
      (event) => {
        if (!event.changedTouches || event.changedTouches.length === 0) {
          return;
        }
        touchEndX = event.changedTouches[0].screenX;
        touchEndY = event.changedTouches[0].screenY;

        const deltaX = Math.abs(touchEndX - touchStartX);
        const deltaY = Math.abs(touchEndY - touchStartY);
        if (deltaX > swipeThreshold / 2 && deltaX > deltaY) {
          event.preventDefault();
        }
        handleTabSwipe();
      },
      { passive: true }
    );
  }
 
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => setActive(index, true));
    tab.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
        event.preventDefault();
        setActive((index + 1) % tabs.length, true);
      }
      if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
        event.preventDefault();
        setActive((index - 1 + tabs.length) % tabs.length, true);
      }
      if (event.key === 'Home') {
        event.preventDefault();
        setActive(0, true);
      }
      if (event.key === 'End') {
        event.preventDefault();
        setActive(tabs.length - 1, true);
      }
    });
  });

  if (prevBtn) {
    prevBtn.addEventListener('click', () => {
      setActive((activeIndex - 1 + tabs.length) % tabs.length, true);
      window.scrollTo({
        top: 0,
        behavior: 'smooth',
      });
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', () => {
      setActive((activeIndex + 1) % tabs.length, true);
      window.scrollTo({
        top: 0,
        behavior: 'smooth',
      });
    });
  }

  if (showFinalizeBtn && finalizeSection) {
    showFinalizeBtn.addEventListener('click', () => {
      finalizeSection.hidden = false;
      showFinalizeBtn.hidden = true;

      if (recipientInput) {
        recipientInput.focus();
        recipientInput.select();
      }
    });
  }

  statusButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const itemId = button.dataset.itemStatusId;
      const status = button.dataset.statusOption || '';
      if (!itemId || !status) {
        return;
      }
      setStatusForItem(itemId, status);
    });
  });

  const MAX_PHOTO_BYTES = 1100 * 1024;
  const compressedMedia = new Map();

  function canvasToJpeg(canvas, quality) {
    return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
  }

  function loadImage(file) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = () => {
        URL.revokeObjectURL(url);
        resolve(img);
      };
      img.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error('Unable to read photo'));
      };
      img.src = url;
    });
  }

  // Re-encode as JPEG, lowering quality then size until it fits under MAX_PHOTO_BYTES.
  // Falls back to the original file if the browser can't decode it.
  async function compressPhoto(file) {
    if (file.size <= MAX_PHOTO_BYTES && file.type === 'image/jpeg') {
      return file;
    }
    try {
      const img = await loadImage(file);
      const baseName = (file.name || 'photo').replace(/\.[^.]+$/, '');
      let maxSide = 1920;
      for (let pass = 0; pass < 5; pass += 1) {
        const scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        for (const quality of [0.82, 0.72, 0.62, 0.52]) {
          const blob = await canvasToJpeg(canvas, quality);
          if (blob && blob.size <= MAX_PHOTO_BYTES) {
            return new File([blob], `${baseName}.jpg`, { type: 'image/jpeg' });
          }
        }
        maxSide = Math.round(maxSide * 0.75);
      }
    } catch (error) {
      // Undecodable format (e.g. HEIC on some browsers): upload as-is.
    }
    return file;
  }

  form.querySelectorAll('input[type="file"][data-item-media-id]').forEach((input) => {
    input.addEventListener('change', () => {
      const itemId = input.dataset.itemMediaId || '';
      const preview = form.querySelector(`[data-item-media-preview="${itemId}"]`);
      const fileName = form.querySelector(`[data-item-media-name="${itemId}"]`);
      const file = input.files && input.files[0];
      if (file) {
        compressedMedia.set(itemId, compressPhoto(file));
      } else {
        compressedMedia.delete(itemId);
      }
      if (fileName) {
        fileName.textContent = file ? file.name : 'No photo selected';
      }
      if (!preview) {
        return;
      }
      preview.innerHTML = '';
      preview.hidden = !file;
      if (!file) {
        return;
      }

      const thumb = document.createElement('img');
      thumb.className = 'checklist-media__thumb';
      thumb.alt = `Selected photo for checklist item ${itemId}`;
      thumb.src = URL.createObjectURL(file);
      thumb.addEventListener('load', () => {
        URL.revokeObjectURL(thumb.src);
      }, { once: true });
      preview.appendChild(thumb);
    });
  });

  updateProgress();

  setActive(activeIndex, false);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    const incomplete = hiddenStatusInputs.filter((input) => (input.value || '').trim() === '');
    if (incomplete.length > 0) {
      if (statusText) {
        statusText.textContent = `Complete all ${incomplete.length} remaining item(s) before finalizing.`;
      }
      return;
    }

    const submittedAt = new Date().toLocaleString();
    const items = collectInspectionItems();
    const reportText = buildReportText(items, submittedAt);
    const reportHtml = buildReportHtml(items, submittedAt);
    const inspectionType = form.querySelector('input[name="inspection_type"]')?.value || 'Inspection';
    const truckLabel = form.querySelector('select[name="truck_label"]')?.value || '';

    const payload = new FormData(form);
    payload.set('signed_at', submittedAt);
    payload.set('inspection_type', inspectionType);
    payload.set('report_json', JSON.stringify({
      submitted_at: submittedAt,
      inspection_type: inspectionType,
      truck_label: truckLabel,
      comments: commentsField ? commentsField.value.trim() : '',
      recipient_email: recipientInput ? recipientInput.value.trim() : '',
      items,
    }));
    payload.set('body_text', reportText);
    payload.set('body_html', reportHtml);

    setBusy(true, 'Preparing email...');

    try {
      for (const [itemId, pending] of compressedMedia) {
        const photo = await pending;
        payload.set(`media_item_${itemId}`, photo, photo.name);
      }

      const response = await fetch(form.dataset.saveUrl || window.location.href, {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
        },
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.ok === false) {
        throw new Error(data.error || data.message || `Request failed with status ${response.status}`);
      }

      if (statusText) {
        statusText.textContent = data.message || 'Inspection saved.';
      }

      window.setTimeout(() => {
        window.location.href = (window.INVAPP_BASE || '') + '/inspection/dashboard.php';
      }, 900);
    } catch (error) {
      setBusy(false, 'Ready to retry');
      if (statusText) {
        statusText.textContent = error instanceof Error ? error.message : 'Unable to finalize inspection.';
      }
    }
  });
})();
