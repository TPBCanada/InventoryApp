(function () {
  'use strict';

  const cfg      = window.timesheetConfig || {};
  const endpoint = cfg.endpoint || '';

  const table     = document.querySelector('.ts__table');
  const status    = document.getElementById('tsStatus');
  const fromInput = document.getElementById('tsFrom');
  const toInput   = document.getElementById('tsTo');
  const empSelect = document.getElementById('tsEmployee');
  const issuesBox = document.getElementById('tsIssuesOnly');
  const hideEmpty = document.getElementById('tsHideEmpty');
  const reviewBtn = document.getElementById('tsReview');
  const reviewCnt = document.getElementById('tsReviewCount');
  const dialog    = document.getElementById('tsAddDialog');
  const addForm   = document.getElementById('tsAddForm');
  const weekDialog = document.getElementById('tsWeekDialog');
  const weekForm   = document.getElementById('tsWeekForm');
  const dayDialog  = document.getElementById('tsDayDialog');
  const dayForm    = document.getElementById('tsDayForm');

  if (!table || !endpoint) return;

  const NUM_COLS  = ['scheduled', 'actual', 'paid', 'regular', 'break', 'ot'];
  const collapsed = new Set();
  let data = null;

  // --- Formatters ---

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // "2026-10-06 15:00:00" -> "3:00pm" (string parsing avoids timezone shifts)
  function fmtTime(str) {
    const h = parseInt(str.substr(11, 2), 10);
    return ((h % 12) || 12) + ':' + str.substr(14, 2) + (h < 12 ? 'am' : 'pm');
  }

  function parseDate(ymd) {
    const p = ymd.split('-').map(Number);
    return new Date(p[0], p[1] - 1, p[2]);
  }

  function toYmd(d) {
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function fmtDate(ymd) {
    return parseDate(ymd)
      .toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' })
      .replace(',', '');
  }

  function fmtHours(mins) {
    return (mins / 60).toFixed(2);
  }

  // --- Render ---

  function visibleCards(emp) {
    return issuesBox && issuesBox.checked
      ? emp.cards.filter(function (c) { return c.issues.length; })
      : emp.cards;
  }

  function render() {
    table.querySelectorAll('tbody').forEach(function (tb) { tb.remove(); });
    if (!data) return;

    const empFilter = empSelect ? empSelect.value : '';
    const groups = data.employees.filter(function (emp) {
      if (empFilter && emp.username !== empFilter) return false;
      if (issuesBox && issuesBox.checked && !emp.totals.issues) return false;
      if (hideEmpty && hideEmpty.checked && !emp.cards.length) return false;
      return true;
    });

    status.textContent = groups.length ? '' : 'No time cards for this period.';
    status.hidden = !!groups.length;

    groups.forEach(function (emp) {
      const tbody = document.createElement('tbody');
      tbody.className = 'ts-group' + (collapsed.has(emp.username) ? ' is-collapsed' : '');
      tbody.dataset.username = emp.username;
      tbody.innerHTML = groupRow(emp) + visibleCards(emp).map(function (c) { return cardRow(c, emp); }).join('') + addRow(emp);
      table.appendChild(tbody);
    });
  }

  function groupRow(emp) {
    const n = emp.cards.filter(function (c) { return c.id !== null; }).length; // excludes scheduled-only rows
    return '<tr class="ts-group__head">' +
      '<th scope="rowgroup" colspan="2">' +
        '<button type="button" class="ts-group__toggle" data-ts-toggle aria-expanded="' + !collapsed.has(emp.username) + '">' +
          '<span class="ts__caret" aria-hidden="true"></span>' +
          '<span class="ts-avatar" aria-hidden="true">' + esc(emp.initials) + '</span>' +
          '<span class="ts-group__name">' + esc(emp.name) + '</span>' +
        '</button>' +
      '</th>' +
      '<td class="ts-group__count">' + n + ' Time Card' + (n === 1 ? '' : 's') + '</td>' +
      '<td></td>' +
      NUM_COLS.map(function (k) { return '<td class="num">' + fmtHours(emp.totals[k]) + '</td>'; }).join('') +
    '</tr>';
  }

  // "08:00" -> "8:00am"
  function fmtClock(hm) {
    return fmtTime('0000-00-00 ' + hm + ':00');
  }

  function cardRow(c, emp) {
    const sched = emp.schedule[c.date];
    const scheduledOnly = c.id === null;
    let time;
    if (scheduledOnly) {
      time = '<span class="ts-muted">Scheduled ' + fmtClock(sched.start) + ' - ' + fmtClock(sched.end) + '</span>';
    } else {
      time = c.out
        ? fmtTime(c.in) + ' - ' + fmtTime(c.out)
        : fmtTime(c.in) + (c.open ? ' <span class="ts-pill ts-pill--open">On shift</span>' : '');
    }
    const issues = c.issues.length
      ? c.issues.map(function (i) { return '<span class="ts-pill ts-pill--issue">' + esc(i) + '</span>'; }).join(' ')
      : '<span class="ts-muted">--</span>';

    const editable = cfg.isManager && !scheduledOnly
      ? ' is-editable" tabindex="0" data-ts-card="' + c.id + '" data-ts-user="' + esc(emp.username) + '" title="Edit or void this time card'
      : '';

    // Scheduled hours cell: managers click it to change that day's schedule.
    function schedCell() {
      const label = fmtHours(c.scheduled) + (sched && sched.source === 'override' ? '<span class="ts-sched__mark" title="Changed for this day">*</span>' : '');
      if (!cfg.isManager) return '<td class="num">' + label + '</td>';
      return '<td class="num"><button type="button" class="ts-sched" data-ts-day="' + c.date + '" data-ts-user="' +
        esc(emp.username) + '" title="Change the schedule for ' + fmtDate(c.date) + '">' + label + '</button></td>';
    }

    return '<tr class="ts-card' + (c.issues.length ? ' has-issue' : '') + (scheduledOnly ? ' is-scheduled-only' : '') + editable + '">' +
      '<td>' + fmtDate(c.date) + '</td>' +
      '<td>' + (emp.role ? esc(emp.role) : '<span class="ts-muted">--</span>') + '</td>' +
      '<td class="ts-card__time">' + time + '</td>' +
      '<td>' + issues + '</td>' +
      NUM_COLS.map(function (k) {
        return k === 'scheduled' ? schedCell() : '<td class="num">' + fmtHours(c[k]) + '</td>';
      }).join('') +
    '</tr>';
  }

  function addRow(emp) {
    if (!cfg.isManager) return '';
    return '<tr class="ts-add"><td colspan="10">' +
      '<button type="button" class="ts-add__btn" data-ts-add="' + esc(emp.username) + '">' +
        '<span aria-hidden="true">+</span> Add time card' +
      '</button>' +
      '<button type="button" class="ts-add__btn" data-ts-week="' + esc(emp.username) + '">' +
        '<span aria-hidden="true">&#128197;</span> Usual schedule' +
      '</button>' +
      '<button type="button" class="ts-add__btn" data-ts-day-pick="' + esc(emp.username) + '">' +
        '<span aria-hidden="true">&#9998;</span> Change a day' +
      '</button></td></tr>';
  }

  function renderChrome() {
    fromInput.value = data.from;
    toInput.value   = data.to;
    reviewCnt.textContent = data.needsReview;
    reviewCnt.hidden = !data.needsReview;

    if (empSelect) {
      const current = empSelect.value;
      empSelect.innerHTML = '<option value="">All employees</option>' + data.employees.map(function (e) {
        return '<option value="' + esc(e.username) + '">' + esc(e.name) + '</option>';
      }).join('');
      empSelect.value = current;
    }
  }

  // --- Fetch ---

  async function load(from, to) {
    const qs = from && to ? '?from=' + from + '&to=' + to : '';
    status.hidden = false;
    status.textContent = 'Loading…';
    try {
      const res  = await fetch(endpoint + qs, { credentials: 'same-origin' });
      if (!(res.headers.get('Content-Type') || '').includes('application/json')) {
        throw new Error('data endpoint returned HTTP ' + res.status + ' (not JSON) - check that ' + endpoint + ' is uploaded');
      }
      const json = await res.json();
      if (!json.ok) throw new Error(json.error || 'Request failed');
      data = json;
      renderChrome();
      render();
    } catch (e) {
      console.error('[timesheet] fetch failed', e);
      status.textContent = 'Could not load timesheets: ' + e.message;
    }
  }

  function reload() {
    load(fromInput.value, toInput.value);
  }

  // --- Events ---

  document.querySelectorAll('[data-ts-step]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!data) return;
      const from = parseDate(data.from);
      const to   = parseDate(data.to);
      const days = Math.round((to - from) / 86400000) + 1;
      const dir  = Number(btn.dataset.tsStep);
      from.setDate(from.getDate() + dir * days);
      to.setDate(to.getDate() + dir * days);
      load(toYmd(from), toYmd(to));
    });
  });

  [fromInput, toInput].forEach(function (input) {
    input.addEventListener('change', function () {
      if (fromInput.value && toInput.value && fromInput.value <= toInput.value) reload();
    });
  });

  [empSelect, issuesBox, hideEmpty].forEach(function (el) {
    if (el) el.addEventListener('change', render);
  });

  issuesBox.addEventListener('change', function () {
    reviewBtn.setAttribute('aria-pressed', String(issuesBox.checked));
  });

  reviewBtn.addEventListener('click', function () {
    issuesBox.checked = !issuesBox.checked;
    reviewBtn.setAttribute('aria-pressed', String(issuesBox.checked));
    render();
  });

  document.querySelectorAll('[data-ts-view]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      collapsed.clear();
      if (btn.dataset.tsView === 'collapse' && data) {
        data.employees.forEach(function (e) { collapsed.add(e.username); });
      }
      render();
      btn.closest('details').open = false;
    });
  });

  // Close dropdown menus on outside click
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.ts__menu[open]').forEach(function (menu) {
      if (!menu.contains(e.target)) menu.open = false;
    });
  });

  table.addEventListener('click', function (e) {
    const toggle = e.target.closest('[data-ts-toggle]');
    if (toggle) {
      const tbody = toggle.closest('tbody');
      const user  = tbody.dataset.username;
      const isCollapsed = !collapsed.has(user);
      if (isCollapsed) collapsed.add(user); else collapsed.delete(user);
      tbody.classList.toggle('is-collapsed', isCollapsed);
      toggle.setAttribute('aria-expanded', String(!isCollapsed));
      return;
    }

    const week = e.target.closest('[data-ts-week]');
    if (week && weekDialog) { openWeek(week.dataset.tsWeek); return; }

    const day = e.target.closest('[data-ts-day]');
    if (day && dayDialog) { openDay(day.dataset.tsUser, day.dataset.tsDay); return; }

    const pick = e.target.closest('[data-ts-day-pick]');
    if (pick && dayDialog) { openDay(pick.dataset.tsDayPick, null); return; }

    const add = e.target.closest('[data-ts-add]');
    if (add && dialog) { openAdd(add.dataset.tsAdd); return; }

    const card = e.target.closest('[data-ts-card]');
    if (card && dialog) openEdit(card.dataset.tsUser, Number(card.dataset.tsCard));
  });

  table.addEventListener('keydown', function (e) {
    const card = e.target.closest && e.target.closest('[data-ts-card]');
    if (e.target.closest && e.target.closest('button')) return; // e.g. the Scheduled hours button
    if (card && dialog && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      openEdit(card.dataset.tsUser, Number(card.dataset.tsCard));
    }
  });

  // --- Add / edit / void time cards (managers) ---

  const errEl     = document.getElementById('tsAddErr');
  const titleEl   = document.getElementById('tsAddTitle');
  const saveBtn   = document.getElementById('tsSave');
  const voidBtn   = document.getElementById('tsVoid');
  const reasonRow = document.getElementById('tsReasonWrap');

  // "2026-10-06 15:00:00" -> "15:00"
  function hhmm(str) { return str ? str.substr(11, 5) : ''; }

  function openDialog(mode, emp, card) {
    const f = addForm.elements;
    addForm.reset();
    addForm.dataset.username = emp.username;
    f.id.value = card ? card.id : '';
    f.date.max = toYmd(new Date());
    f.date.value = card ? card.date : (toYmd(new Date()) <= data.to ? toYmd(new Date()) : data.to);
    f.in.value = card ? hhmm(card.in) : '';
    f.out.value = card ? hhmm(card.out) : '';
    f.breakMinutes.value = card ? card.breakMinutes : 0;
    f.reason.required = mode === 'edit';
    reasonRow.hidden = mode !== 'edit';
    voidBtn.hidden = mode !== 'edit';
    titleEl.textContent = mode === 'edit' ? 'Edit time card' : 'Add time card';
    saveBtn.textContent = mode === 'edit' ? 'Save changes' : 'Add time card';
    document.getElementById('tsAddFor').textContent = emp.name +
      (card ? ' · ' + fmtDate(card.date) + (card.source === 'manual' ? ' · manual entry' : '') : '');
    errEl.textContent = '';
    dialog.showModal();
  }

  function openAdd(username) {
    const emp = data.employees.find(function (x) { return x.username === username; });
    if (emp) openDialog('add', emp, null);
  }

  function openEdit(username, cardId) {
    const emp = data.employees.find(function (x) { return x.username === username; });
    const card = emp && emp.cards.find(function (c) { return c.id === cardId; });
    if (card) openDialog('edit', emp, card);
  }

  async function send(payload) {
    const res = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrfToken || '' },
      body: JSON.stringify(payload)
    });
    const json = await res.json();
    if (!json.ok) throw new Error(json.error || 'Could not save');
  }

  if (dialog) {
    dialog.querySelector('[data-ts-close]').addEventListener('click', function () { dialog.close(); });

    addForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      const f = addForm.elements;
      const editing = f.id.value !== '';
      saveBtn.disabled = true;
      try {
        await send({
          action: editing ? 'edit' : 'add',
          id: editing ? Number(f.id.value) : undefined,
          username: addForm.dataset.username,
          date: f.date.value,
          in: f.in.value,
          out: f.out.value,
          breakMinutes: Number(f.breakMinutes.value || 0),
          reason: f.reason.value
        });
        dialog.close();
        reload();
      } catch (ex) {
        errEl.textContent = ex.message;
      } finally {
        saveBtn.disabled = false;
      }
    });

    voidBtn.addEventListener('click', async function () {
      const f = addForm.elements;
      if (!f.reason.value.trim()) {
        errEl.textContent = 'Enter a reason before voiding this card.';
        f.reason.focus();
        return;
      }
      if (!window.confirm('Void this time card? It will be removed from the timesheet (the change is logged).')) return;
      voidBtn.disabled = true;
      try {
        await send({ action: 'void', id: Number(f.id.value), reason: f.reason.value });
        dialog.close();
        reload();
      } catch (ex) {
        errEl.textContent = ex.message;
      } finally {
        voidBtn.disabled = false;
      }
    });
  }

  // --- Schedules (managers): usual week + one-day changes ---

  const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

  function findEmp(username) {
    return data.employees.find(function (x) { return x.username === username; });
  }

  function openWeek(username) {
    const emp = findEmp(username);
    if (!emp) return;
    weekForm.reset();
    weekForm.dataset.username = username;
    document.getElementById('tsWeekFor').textContent = emp.name;
    document.getElementById('tsWeekErr').textContent = '';
    weekForm.querySelectorAll('tr[data-weekday]').forEach(function (row) {
      const p = emp.pattern[row.dataset.weekday];
      const works = row.querySelector('[name="works"]');
      works.checked = !!p;
      row.querySelector('[name="start"]').value = p ? p.start : '08:00';
      row.querySelector('[name="end"]').value = p ? p.end : '16:30';
      row.querySelector('[name="break"]').value = p ? p.break : 30;
      row.classList.toggle('is-off', !p);
    });
    weekForm.elements.effectiveFrom.value = data.from;
    weekDialog.showModal();
  }

  function openDay(username, date) {
    const emp = findEmp(username);
    if (!emp) return;
    const f = dayForm.elements;
    dayForm.reset();
    dayForm.dataset.username = username;
    f.date.value = date || toYmd(new Date());
    document.getElementById('tsDayFor').textContent = emp.name;
    document.getElementById('tsDayErr').textContent = '';
    fillDay(emp);
    dayDialog.showModal();
  }

  // Show the selected date's current schedule (one-day change, else the usual week).
  function fillDay(emp) {
    const f = dayForm.elements;
    const s = emp.schedule[f.date.value];
    const usual = emp.pattern[parseDate(f.date.value).getDay()];
    const usualText = usual
      ? 'Usual ' + WEEKDAYS[parseDate(f.date.value).getDay()] + ': ' + fmtClock(usual.start) + ' - ' + fmtClock(usual.end)
      : 'Not usually scheduled on ' + WEEKDAYS[parseDate(f.date.value).getDay()] + 's.';
    document.getElementById('tsDayUsual').textContent = usualText;
    const base = s || (usual ? { start: usual.start, end: usual.end, break: usual.break, off: false } : { start: '', end: '', break: 30, off: true });
    f.off.checked = !!base.off;
    f.start.value = base.start || '';
    f.end.value = base.end || '';
    f.break.value = base.break != null ? base.break : 30;
    f.note.value = (s && s.note) || '';
    document.getElementById('tsDayClear').hidden = !(s && s.source === 'override');
    toggleDayTimes();
  }

  function toggleDayTimes() {
    dayForm.querySelectorAll('[data-ts-day-times]').forEach(function (el) {
      el.hidden = dayForm.elements.off.checked;
    });
  }

  if (weekDialog) {
    weekDialog.querySelector('[data-ts-close]').addEventListener('click', function () { weekDialog.close(); });

    weekForm.addEventListener('change', function (e) {
      if (e.target.name === 'works') e.target.closest('tr').classList.toggle('is-off', !e.target.checked);
    });

    weekForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      const err = document.getElementById('tsWeekErr');
      const days = [];
      weekForm.querySelectorAll('tr[data-weekday]').forEach(function (row) {
        if (!row.querySelector('[name="works"]').checked) return;
        days.push({
          weekday: Number(row.dataset.weekday),
          start: row.querySelector('[name="start"]').value,
          end: row.querySelector('[name="end"]').value,
          break: Number(row.querySelector('[name="break"]').value || 0)
        });
      });
      try {
        await send({ action: 'pattern', username: weekForm.dataset.username, effectiveFrom: weekForm.elements.effectiveFrom.value, days: days });
        weekDialog.close();
        reload();
      } catch (ex) {
        err.textContent = ex.message;
      }
    });
  }

  if (dayDialog) {
    dayDialog.querySelector('[data-ts-close]').addEventListener('click', function () { dayDialog.close(); });
    dayForm.elements.off.addEventListener('change', toggleDayTimes);
    dayForm.elements.date.addEventListener('change', function () {
      const emp = findEmp(dayForm.dataset.username);
      if (emp && dayForm.elements.date.value) fillDay(emp);
    });

    dayForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      const f = dayForm.elements;
      try {
        await send({
          action: 'override', username: dayForm.dataset.username, date: f.date.value,
          off: f.off.checked, start: f.start.value, end: f.end.value, break: Number(f.break.value || 0), note: f.note.value
        });
        dayDialog.close();
        reload();
      } catch (ex) {
        document.getElementById('tsDayErr').textContent = ex.message;
      }
    });

    document.getElementById('tsDayClear').addEventListener('click', async function () {
      try {
        await send({ action: 'override_clear', username: dayForm.dataset.username, date: dayForm.elements.date.value });
        dayDialog.close();
        reload();
      } catch (ex) {
        document.getElementById('tsDayErr').textContent = ex.message;
      }
    });
  }

  document.addEventListener('punch:changed', reload);

  // --- Init ---

  load();
})();
