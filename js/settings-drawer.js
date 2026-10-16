/**
 * settings-drawer.js
 *
 * Mirrors the app drawer interaction for the profile/settings panel.
 */
(function () {
    const drawer = document.getElementById('settingsDrawer');
    const launchers = Array.from(document.querySelectorAll('[data-settings-launch]'));
    const backdrop = document.getElementById('settingsBackdrop');
    const closeBtn = document.getElementById('settingsCloseBtn');
    const focusableSelector = 'a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])';

    if (!drawer || launchers.length === 0 || !backdrop) {
        return;
    }

    let lastTrigger = null;

    function handleKeydown(event) {
        if (event.key === 'Escape') {
            closeDrawer();
        }
    }

    function closeDrawer() {
        drawer.setAttribute('data-open', 'false');
        drawer.setAttribute('aria-hidden', 'true');
        launchers.forEach(btn => btn.setAttribute('aria-expanded', 'false'));
        document.removeEventListener('keydown', handleKeydown);

        if (lastTrigger) {
            lastTrigger.focus();
        }
    }

    function openDrawer(trigger) {
        lastTrigger = trigger || null;
        drawer.setAttribute('data-open', 'true');
        drawer.setAttribute('aria-hidden', 'false');
        launchers.forEach(btn => btn.setAttribute('aria-expanded', 'true'));
        document.addEventListener('keydown', handleKeydown);

        const firstFocusable = drawer.querySelector(focusableSelector);
        if (firstFocusable) {
            firstFocusable.focus();
        }
    }

    launchers.forEach(btn => {
        btn.addEventListener('click', () => {
            const isOpen = drawer.getAttribute('data-open') === 'true';
            if (isOpen) {
                closeDrawer();
            } else {
                openDrawer(btn);
            }
        });
    });

    backdrop.addEventListener('click', closeDrawer);
    if (closeBtn) {
        closeBtn.addEventListener('click', closeDrawer);
    }

    // Inline password change handler (profile drawer)
    const passwordModal = document.getElementById('passwordModal');
    const passwordModalCloseEls = passwordModal ? Array.from(passwordModal.querySelectorAll('[data-password-modal-close]')) : [];
    const passwordModalOpenBtn = document.querySelector('[data-password-modal-open]');
    const passwordForm = document.getElementById('passwordChangeFormModal');
    const passwordAlert = document.getElementById('passwordChangeAlertModal');
    let lastPasswordTrigger = null;

    function setPasswordAlert(target, type, message) {
        if (!target) return;
        const isSuccess = type === 'success';
        const hasMessage = Boolean(message);
        target.textContent = message || '';
        target.hidden = !hasMessage;
        target.style.display = hasMessage ? 'block' : 'none';
        target.style.borderColor = hasMessage ? (isSuccess ? '#16a34a' : '#dc2626') : 'transparent';
        target.style.backgroundColor = hasMessage
            ? (isSuccess ? 'rgba(22,163,74,0.08)' : 'rgba(220,38,38,0.08)')
            : 'transparent';
        target.style.color = hasMessage ? (isSuccess ? '#14532d' : '#7f1d1d') : '';
    }

    function closePasswordModal() {
        if (!passwordModal) return;
        passwordModal.dataset.open = 'false';
        passwordModal.setAttribute('aria-hidden', 'true');
        passwordModal.style.display = 'none';
        document.removeEventListener('keydown', handlePasswordModalKeydown);
        if (lastPasswordTrigger) {
            try {
                lastPasswordTrigger.focus();
            } catch (_) { /* ignore */ }
        }
    }

    function openPasswordModal(trigger) {
        if (!passwordModal) return;
        closeDrawer();
        lastPasswordTrigger = trigger || document.activeElement || null;
        passwordModal.dataset.open = 'true';
        passwordModal.setAttribute('aria-hidden', 'false');
        passwordModal.style.display = 'flex';
        document.addEventListener('keydown', handlePasswordModalKeydown);

        const first = passwordModal.querySelector('input, button, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (first) first.focus();
    }

    function handlePasswordModalKeydown(event) {
        if (event.key === 'Escape') {
            closePasswordModal();
        }
    }

    if (passwordForm) {
        passwordForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            setPasswordAlert(passwordAlert, '', '');

            const submitBtn = passwordForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.loading = 'true';
            }

            try {
                const formData = new FormData(passwordForm);

                // Resolve a safe URL (fallback to /dev/change_password.php when base is known)
                const base = (window.INVAPP_BASE || '').replace(/\/$/, '');
                const action = passwordForm.getAttribute('action') || (base ? `${base}/change_password.php` : 'change_password.php');

                const response = await fetch(action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                });

                let data = {};
                let parsed = false;
                let rawText = '';
                try {
                    data = await response.json();
                    parsed = true;
                } catch (_) {
                    try {
                        rawText = await response.text();
                    } catch (_) {
                        rawText = '';
                    }
                }

                const ok = response.ok && data.success;
                const fallbackMsg = ok
                    ? 'Password updated.'
                    : (data.message
                        || rawText
                        || `Unable to update password (${response.status}).`);
                setPasswordAlert(passwordAlert, ok ? 'success' : 'error', fallbackMsg);

                if (ok) {
                    passwordForm.reset();
                    setTimeout(closePasswordModal, 1200);
                }
            } catch (error) {
                setPasswordAlert(passwordAlert, 'error', 'Something went wrong. Please try again.');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.removeAttribute('data-loading');
                }
            }
        });
    }

    if (passwordModalOpenBtn && passwordModal) {
        passwordModalOpenBtn.addEventListener('click', () => openPasswordModal(passwordModalOpenBtn));
    }
    if (passwordModalCloseEls.length) {
        passwordModalCloseEls.forEach((el) => {
            el.addEventListener('click', closePasswordModal);
        });
    }

    // Punch PIN modal lives in js/punch-pin.js (templates/punch_clock.php, on every page).
    // Close the drawer when its "Set/Change PIN" button opens it.
    document.querySelectorAll('[data-pin-modal-open]').forEach((btn) => btn.addEventListener('click', closeDrawer));

    // Profile edit mode toggle
    document.addEventListener('DOMContentLoaded', function () {
        const editBtn = document.getElementById('editBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const saveGroup = document.getElementById('saveGroup');
        const inputs = document.querySelectorAll('.editable-input');

        editBtn.addEventListener('click', () => {
            // Toggle Buttons
            editBtn.style.display = 'none';
            saveGroup.style.display = 'flex';

            // Enable Inputs
            inputs.forEach(input => {
                input.removeAttribute('readonly');
                input.classList.add('is-editing');
            });
            document.getElementById('phone_field').focus();
        });

        cancelBtn.addEventListener('click', () => {
            // Toggle Buttons Back
            editBtn.style.display = 'block';
            saveGroup.style.display = 'none';

            // Disable Inputs & Reset Values
            inputs.forEach(input => {
                input.setAttribute('readonly', true);
                input.classList.remove('is-editing');
            });
        });
    });


})();
