(function () {
  'use strict';

  // --- Elements ---

  const fab      = document.getElementById('punchFab');
  if (!fab) return;

  // Config from templates/punch_clock.php (data-* attributes, so no inline script is needed under CSP).
  const endpoint = fab.dataset.endpoint || '';
  const csrf     = fab.dataset.csrf     || '';

  let state = { status: 'out', record: null, hasPin: null, late: null }; // hasPin: null until the first status fetch
  let busy  = false;

  let clockTick = null;
  let timerTick = null;

  const fabLabel = fab.querySelector('.punch-fab__label');
  const fabTimer = fab.querySelector('.punch-fab__timer');

  const modal      = document.getElementById('punchModal');
  const stateLabel = document.getElementById('punchStateLabel');
  const clockEl    = document.getElementById('punchClock');
  const sinceEl    = document.getElementById('punchSince');
  const actionBtn  = document.getElementById('punchAction');
  const logList    = document.getElementById('punchLogList');

  const statusView = document.getElementById('punchStatusView');
  const pinView    = document.getElementById('punchPinView');
  const pinHint    = document.getElementById('punchPinHint');
  const pinInputs  = pinView ? Array.from(pinView.querySelectorAll('.punch-pin__digit')) : [];
  const pinError   = document.getElementById('punchPinError');
  const pinBack    = document.getElementById('punchPinBack');
  const pinConfirm = document.getElementById('punchPinConfirm');

  const lateBanner = document.getElementById('punchLateBanner');
  const lateDetail = document.getElementById('punchLateDetail');
  const lateOut    = lateBanner ? lateBanner.querySelector('[data-punch-late-out]') : null;
  const lateSnooze = lateBanner ? lateBanner.querySelector('[data-punch-late-snooze]') : null;

  // --- Formatters ---

  function fmtTime(date) {
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  function fmtClock(date) {
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
  }

  function fmtElapsed(ms) {
    const m = Math.floor(ms / 60000);
    const h = Math.floor(m / 60);
    return h > 0 ? h + 'h ' + (m % 60) + 'm' : m + 'm';
  }

  // --- Clock & elapsed timer ---

  function startClock() {
    if (clockTick) return;
    function tick() { if (clockEl) clockEl.textContent = fmtClock(new Date()); }
    tick();
    clockTick = setInterval(tick, 1000);
  }

  function stopClock() { clearInterval(clockTick); clockTick = null; }

  function startTimer() {
    if (timerTick || !state.record) return;
    function tick() {
      const ms = Date.now() - new Date(state.record.punchedInAt.replace(' ', 'T')).getTime();
      if (fabTimer) fabTimer.textContent = fmtElapsed(ms);
    }
    tick();
    timerTick = setInterval(tick, 30000);
  }

  function stopTimer() {
    clearInterval(timerTick);
    timerTick = null;
    if (fabTimer) fabTimer.textContent = '';
  }

  // --- FAB ---

  function updateFab() {
    if (state.status === 'in') {
      fab.classList.add('is-in');
      if (fabLabel) fabLabel.textContent = 'Punch Out';
      fab.title = 'Punch Out';
      startTimer();
    } else {
      fab.classList.remove('is-in');
      if (fabLabel) fabLabel.textContent = 'Punch In';
      fab.title = 'Punch In';
      stopTimer();
    }
    updateStatusBadges();
    updateLate();
  }

  // --- Forgot-to-punch-out banner (state.late from utils/punch_api.php) ---

  function updateLate() {
    const late = state.status === 'in' && state.late && state.late.overdue ? state.late : null;
    fab.classList.toggle('is-late', !!late);
    if (!lateBanner) return;
    lateBanner.hidden = !late;
    if (late && lateDetail && state.record) {
      const since = fmtTime(new Date(state.record.punchedInAt.replace(' ', 'T')));
      lateDetail.textContent = 'You’ve been on the clock since ' + since
        + (late.shiftEnd ? '; your shift ended at ' + fmtTime(new Date(late.shiftEnd.replace(' ', 'T'))) + '.' : '.');
    }
  }

  async function snoozeLate() {
    if (lateSnooze) lateSnooze.disabled = true;
    try {
      const res = await fetch(endpoint, {
        method:      'POST',
        credentials: 'same-origin',
        headers:     { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body:        JSON.stringify({ action: 'snooze' }),
      });
      const data = res.ok ? await res.json() : {};
      if (data.ok) {
        state.late = data.late;
        updateLate();
      }
    } catch (e) {
      console.error('[punch-clock] snooze failed', e);
    } finally {
      if (lateSnooze) lateSnooze.disabled = false;
    }
  }

  // Settings drawer "My Time" status pill(s)
  function updateStatusBadges() {
    const isIn = state.status === 'in' && state.record;
    document.querySelectorAll('[data-punch-status]').forEach(function (el) {
      el.classList.toggle('is-in', !!isIn);
      el.textContent = isIn
        ? 'Clocked in · ' + new Date(state.record.punchedInAt.replace(' ', 'T'))
            .toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }).replace(' ', '').toLowerCase()
        : 'Clocked out';
    });
  }

  // --- Modal content ---

  function updateModalState() {
    const isIn = state.status === 'in';
    modal.classList.toggle('is-in', isIn);

    if (stateLabel) stateLabel.textContent = isIn ? 'Clocked In' : 'Clocked Out';
    if (actionBtn)  actionBtn.textContent  = isIn ? 'Punch Out'  : 'Punch In';

    if (sinceEl) {
      sinceEl.textContent = (isIn && state.record)
        ? 'Since ' + fmtTime(new Date(state.record.punchedInAt.replace(' ', 'T')))
        : '\u00a0';
    }

    if (logList) {
      logList.innerHTML = (isIn && state.record)
        ? '<li><span>' + fmtTime(new Date(state.record.punchedInAt.replace(' ', 'T'))) + ' \u2013 now</span><span>In progress</span></li>'
        : '<li class="punch-log__empty">No punches recorded today.</li>';
    }
  }

  // --- Modal open / close ---

  function openModal() {
    showStatusView();
    updateModalState();
    startClock();
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('is-open');
    if (actionBtn) actionBtn.focus();
  }

  function closeModal() {
    modal.setAttribute('aria-hidden', 'true');
    modal.classList.remove('is-open');
    stopClock();
    showStatusView();
    fab.focus();
  }

  // --- Screen switching ---

  function showStatusView() {
    statusView.hidden = false;
    pinView.hidden    = true;
  }

  function showPinView() {
    statusView.hidden = true;
    pinView.hidden    = false;
    clearPin();
    if (pinHint) {
      pinHint.textContent = state.status === 'out'
        ? 'Enter your 4-digit PIN to punch in.'
        : 'Enter your 4-digit PIN to punch out.';
    }
    if (pinInputs[0]) pinInputs[0].focus();
  }

  // --- No PIN yet: send the user to set one ---

  // Opens the "Set your PIN" modal (js/punch-pin.js); falls back to a message.
  function requestPinSetup() {
    if (document.getElementById('pinModal')) {
      closeModal();
      document.dispatchEvent(new CustomEvent('punch:pin-setup', { detail: { trigger: fab } }));
    } else {
      showPinView();
      showPinError(GEO_ERRORS.pin_not_set);
    }
  }

  function onActionClick() {
    if (state.hasPin === false) requestPinSetup();
    else showPinView();
  }

  // --- PIN helpers ---

  function getPin() { return pinInputs.map(function (el) { return el.value; }).join(''); }

  function clearPin() {
    pinInputs.forEach(function (el) { el.value = ''; el.classList.remove('is-filled', 'is-error'); });
    if (pinError)   pinError.hidden       = true;
    if (pinConfirm) pinConfirm.disabled   = true;
  }

  function showPinError(msg) {
    if (pinError) { pinError.textContent = msg; pinError.hidden = false; }
    pinInputs.forEach(function (el) { el.classList.add('is-error'); el.value = ''; });
    setTimeout(function () {
      pinInputs.forEach(function (el) { el.classList.remove('is-error'); });
    }, 350);
    if (pinConfirm) pinConfirm.disabled = true;
    if (pinInputs[0]) pinInputs[0].focus();
  }

  // --- PIN input wiring ---

  pinInputs.forEach(function (input, i) {
    input.addEventListener('input', function () {
      input.value = input.value.replace(/[^0-9]/g, '').slice(-1);
      input.classList.toggle('is-filled', input.value !== '');
      if (input.value && i < pinInputs.length - 1) pinInputs[i + 1].focus();
      if (pinConfirm) pinConfirm.disabled = getPin().length < 4;
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !input.value && i > 0) {
        pinInputs[i - 1].value = '';
        pinInputs[i - 1].classList.remove('is-filled');
        pinInputs[i - 1].focus();
      }
      if (e.key === 'Enter' && getPin().length === 4) submitPin();
    });
  });

  // --- Network ---

  async function fetchStatus() {
    try {
      const res  = await fetch(endpoint, { credentials: 'same-origin' });
      const data = await res.json();
      if (data.ok) {
        state.status = data.status;
        state.record = data.record;
        state.hasPin = data.hasPin !== false;
        state.late   = data.late || null;
        document.dispatchEvent(new CustomEvent('punch:pin-status', { detail: { hasPin: state.hasPin } }));
        updateFab();
      }
    } catch (e) {
      console.error('[punch-clock] status fetch failed', e);
    }
  }

  // --- Geofence ---

  const GEO_ERRORS = {
    pin_not_set:             'You don’t have a punch PIN yet. Set one in Settings → Change PIN.',
    pin_locked:              'Too many wrong PINs. Try again in 15 minutes.',
    db_error:                'The time clock couldn’t save right now. Try again or tell a manager.',
    geofence_not_configured: 'Punch-in location is not set up yet. Ask an admin.',
    location_required:       'Location is required to punch in. Allow location access and try again.',
    location_inaccurate:     'Your location is too imprecise. Turn on GPS / precise location and try again.'
  };

  // Resolves to { lat, lng, accuracy } or rejects with a user-facing message.
  function getLocation() {
    return new Promise(function (resolve, reject) {
      if (!navigator.geolocation) {
        reject(new Error('This device can’t share its location, so you can’t punch in here.'));
        return;
      }
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy });
        },
        function (err) {
          reject(new Error(err.code === err.PERMISSION_DENIED
            ? 'Location access is blocked. Allow location for this site in your browser settings, then try again.'
            : 'Couldn’t get your location. Check that location/GPS is on and try again.'));
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
      );
    });
  }

  function geofenceMessage(data) {
    if (data.error === 'outside_geofence') {
      return 'You’re about ' + data.distance + ' m from the warehouse. Punch in from within ' + data.radius + ' m.';
    }
    return GEO_ERRORS[data.error] || null;
  }

  async function submitPin() {
    const pin = getPin();
    if (pin.length < 4 || busy) return;
    busy = true;
    if (pinConfirm) pinConfirm.disabled = true;

    try {
      let body;
      if (state.status === 'out') {
        if (pinHint) pinHint.textContent = 'Checking your location…';
        let location;
        try {
          location = await getLocation();
        } catch (geoErr) {
          showPinError(geoErr.message);
          return;
        } finally {
          if (pinHint) pinHint.textContent = 'Enter your 4-digit PIN to punch in.';
        }
        body = { action: 'punch_in', pin, location };
      } else {
        body = { action: 'punch_out', pin, id: state.record?.id ?? null, note: '' };
      }

      const res  = await fetch(endpoint, {
        method:      'POST',
        credentials: 'same-origin',
        headers:     { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body:        JSON.stringify(body),
      });

      const data = await res.json();

      if (data.ok) {
        state.status = data.status;
        state.record = data.record ?? null;
        state.late   = null;
        updateFab();
        updateModalState();
        showStatusView();
        document.dispatchEvent(new CustomEvent('punch:changed'));
      } else if (data.error === 'pin_not_set') {
        state.hasPin = false;
        document.dispatchEvent(new CustomEvent('punch:pin-status', { detail: { hasPin: false } }));
        requestPinSetup();
      } else if (data.error === 'invalid_pin') {
        showPinError('Incorrect PIN. Try again.');
      } else if (geofenceMessage(data)) {
        showPinError(geofenceMessage(data));
      } else {
        showPinError(data.error || 'Something went wrong.');
      }
    } catch (e) {
      console.error('[punch-clock] submit failed', e);
      showPinError('Network error. Try again.');
    } finally {
      busy = false;
    }
  }

  // --- Events ---

  fab.addEventListener('click', openModal);
  actionBtn?.addEventListener('click', onActionClick);
  document.addEventListener('punch:pin-set', function () { state.hasPin = true; });
  pinBack?.addEventListener('click', showStatusView);
  pinConfirm?.addEventListener('click', submitPin);

  modal.querySelectorAll('[data-punch-close]').forEach(function (el) {
    el.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
  });

  lateOut?.addEventListener('click', function () { openModal(); onActionClick(); });
  lateSnooze?.addEventListener('click', snoozeLate);

  // --- Init ---

  fetchStatus();
  setInterval(function () { if (!document.hidden) fetchStatus(); }, 5 * 60 * 1000); // banner appears without a reload
})();
