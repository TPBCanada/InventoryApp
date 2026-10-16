// Set / Change punch PIN modal (templates/punch_clock.php) -> change_pin.php.
// Opened by any [data-pin-modal-open] button, or by js/punch-clock.js via the 'punch:pin-setup' event
// when the user has no PIN. Wording follows 'punch:pin-status' ({ hasPin }) from the punch clock.
(function () {
    'use strict';

    const pinModal = document.getElementById('pinModal');
    const pinForm = document.getElementById('pinChangeFormModal');
    if (!pinModal || !pinForm) return;

    const pinAlert = document.getElementById('pinChangeAlertModal');
    const pinSubmit = pinForm.querySelector('button[type="submit"]');
    const pinCurrent = pinForm.elements.current_pin;
    const pinGroups = Array.from(pinForm.querySelectorAll('[data-pin-group]'));
    let hasPin = true;
    let lastPinTrigger = null;

    // "Set your PIN" (no PIN yet, confirm with account password) vs "Change PIN".
    function applyPinMode(value) {
        hasPin = value;
        const text = hasPin
            ? { pinModalTitle: 'Change PIN', pinModalIntro: 'Your 4-digit PIN for punching in and out.', pinCurrentLabel: 'Current PIN' }
            : { pinModalTitle: 'Set your PIN', pinModalIntro: 'You need a 4-digit PIN before you can punch in.', pinCurrentLabel: 'Account password' };
        Object.keys(text).forEach((id) => {
            const el = document.getElementById(id);
            if (el) el.textContent = text[id];
        });
        const hint = document.getElementById('pinCurrentHint');
        if (hint) hint.hidden = hasPin;
        if (pinSubmit) pinSubmit.textContent = hasPin ? 'Update PIN' : 'Set PIN';
        document.querySelectorAll('[data-pin-modal-open]').forEach((btn) => {
            btn.textContent = hasPin ? 'Change PIN' : 'Set PIN';
        });
    }

    function setPinMsg(type, message) {
        if (!pinAlert) return;
        pinAlert.textContent = message || '';
        pinAlert.classList.toggle('is-error', type === 'error');
        pinAlert.classList.toggle('is-ok', type === 'success');
    }

    function groupDigits(group) {
        return Array.from(group.querySelectorAll('.punch-pin__digit'));
    }

    function groupValue(group) {
        return groupDigits(group).map((el) => el.value).join('');
    }

    function clearGroup(group, asError) {
        groupDigits(group).forEach((el) => {
            el.value = '';
            el.classList.remove('is-filled');
            if (asError) el.classList.add('is-error');
        });
        if (asError) setTimeout(() => groupDigits(group).forEach((el) => el.classList.remove('is-error')), 350);
    }

    function updatePinSubmit() {
        if (!pinSubmit) return;
        pinSubmit.disabled = !(pinCurrent && pinCurrent.value) || pinGroups.some((g) => groupValue(g).length < 4);
    }

    // Digit boxes: numbers only, auto-advance, backspace goes back, paste fills the row.
    pinGroups.forEach((group, gi) => {
        const digits = groupDigits(group);
        digits.forEach((input, i) => {
            input.addEventListener('input', () => {
                input.value = input.value.replace(/[^0-9]/g, '').slice(-1);
                input.classList.toggle('is-filled', input.value !== '');
                if (input.value) {
                    if (i < digits.length - 1) digits[i + 1].focus();
                    else if (pinGroups[gi + 1]) groupDigits(pinGroups[gi + 1])[0].focus();
                }
                updatePinSubmit();
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !input.value && i > 0) {
                    digits[i - 1].value = '';
                    digits[i - 1].classList.remove('is-filled');
                    digits[i - 1].focus();
                    updatePinSubmit();
                }
            });
            input.addEventListener('paste', (e) => {
                const text = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 4);
                if (!text) return;
                e.preventDefault();
                text.split('').forEach((ch, k) => {
                    if (digits[k]) { digits[k].value = ch; digits[k].classList.add('is-filled'); }
                });
                (digits[text.length] || digits[digits.length - 1]).focus();
                updatePinSubmit();
            });
        });
    });
    if (pinCurrent) pinCurrent.addEventListener('input', updatePinSubmit);

    function resetPinForm() {
        pinForm.reset();
        pinGroups.forEach((g) => clearGroup(g, false));
        setPinMsg('', '');
        updatePinSubmit();
    }

    function handlePinModalKeydown(event) {
        if (event.key === 'Escape') closePinModal();
    }

    function closePinModal() {
        pinModal.classList.remove('is-open');
        pinModal.setAttribute('aria-hidden', 'true');
        document.removeEventListener('keydown', handlePinModalKeydown);
        if (lastPinTrigger) {
            try { lastPinTrigger.focus(); } catch (_) { /* ignore */ }
        }
    }

    function openPinModal(trigger) {
        lastPinTrigger = trigger || document.activeElement || null;
        resetPinForm();
        pinModal.classList.add('is-open');
        pinModal.setAttribute('aria-hidden', 'false');
        document.addEventListener('keydown', handlePinModalKeydown);
        if (pinCurrent) pinCurrent.focus();
    }

    pinForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        setPinMsg('', '');

        const [newGroup, confirmGroup] = pinGroups;
        if (groupValue(newGroup) !== groupValue(confirmGroup)) {
            setPinMsg('error', 'New PINs do not match.');
            clearGroup(confirmGroup, true);
            groupDigits(confirmGroup)[0].focus();
            updatePinSubmit();
            return;
        }

        pinGroups.forEach((g) => { pinForm.elements[g.dataset.pinGroup].value = groupValue(g); });
        if (pinSubmit) pinSubmit.disabled = true;

        try {
            const response = await fetch(pinForm.getAttribute('action'), {
                method: 'POST',
                credentials: 'same-origin',
                body: new FormData(pinForm),
            });
            let data = {};
            try { data = await response.json(); } catch (_) { /* non-JSON error page */ }

            const ok = response.ok && data.success;
            setPinMsg(ok ? 'success' : 'error',
                data.message || (ok ? 'PIN updated.' : `Unable to update PIN (${response.status}).`));

            if (ok) {
                applyPinMode(true);
                document.dispatchEvent(new CustomEvent('punch:pin-set'));
                setTimeout(closePinModal, 1200);
            } else {
                pinGroups.forEach((g) => clearGroup(g, true));
                if (pinCurrent) { pinCurrent.value = ''; pinCurrent.focus(); }
            }
        } catch (error) {
            setPinMsg('error', 'Something went wrong. Please try again.');
        } finally {
            updatePinSubmit();
        }
    });

    document.querySelectorAll('[data-pin-modal-open]').forEach((btn) => {
        btn.addEventListener('click', () => openPinModal(btn));
    });
    pinModal.querySelectorAll('[data-pin-modal-close]').forEach((el) => {
        el.addEventListener('click', closePinModal);
    });

    document.addEventListener('punch:pin-status', (event) => applyPinMode(!!(event.detail && event.detail.hasPin)));
    document.addEventListener('punch:pin-setup', (event) => openPinModal(event.detail && event.detail.trigger));
})();
