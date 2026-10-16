(function () {
  'use strict';

  const config = window.dashboardCalendarConfig || {};

  const refs = {
    root: document.getElementById('dashboardCalendar'),
    prev: document.getElementById('calPrev'),
    next: document.getElementById('calNext'),
    label: document.getElementById('calLabel'),
    grid: document.getElementById('calGrid'),
    startInput: document.getElementById('calStart'),
    endInput: document.getElementById('calEnd'),
    noteInput: document.getElementById('calNote'),
    emailSelect: document.getElementById('calEmailSelect'),
    customEmailWrap: document.getElementById('calCustomEmailWrap'),
    customEmailInput: document.getElementById('calCustomEmail'),
    submitButton: document.getElementById('calSubmit'),
    notesList: document.getElementById('calNotesList'),
    datesList: document.getElementById('calDatesList'),
    approvalTrigger: document.getElementById('calApprovalTrigger'),
    approvalTriggerText: document.querySelector('#calApprovalTrigger .cal-approval-trigger__text'),
    approvalTriggerBadge: document.querySelector('#calApprovalTrigger .cal-approval-trigger__badge'),
    approvalTriggerChevron: document.querySelector('#calApprovalTrigger .cal-approval-trigger__chevron'),
    dayModal: document.getElementById('calDayModal'),
    dayModalTitle: document.getElementById('calDayModalTitle'),
    dayModalDate: document.getElementById('calDayModalDate'),
    dayModalBody: document.getElementById('calDayModalBody'),
    dayModalUseDate: document.getElementById('calDayModalUseDate'),
    dayModalClose: document.getElementById('calDayModalClose'),
    approvalDrawer: document.getElementById('calApprovalDrawer'),
    approvalDrawerTitle: document.getElementById('calApprovalDrawerTitle'),
    approvalDrawerDate: document.getElementById('calApprovalDrawerDate'),
    approvalDrawerBody: document.getElementById('calApprovalDrawerBody'),
    approvalDrawerClose: document.getElementById('calApprovalDrawerClose'),
    cancelConfirmModal: document.getElementById('calCancelConfirmModal'),
    cancelConfirmText: document.getElementById('calCancelConfirmText'),
    cancelConfirmClose: document.getElementById('calCancelConfirmClose'),
    cancelKeepButton: document.getElementById('calCancelKeepBtn'),
    cancelYesButton: document.getElementById('calCancelYesBtn'),
    mgrTools: document.getElementById('calMgrTools'),
    addDateToggle: document.getElementById('calAddDateToggle'),
    addDatePanel: document.getElementById('calAddDatePanel'),
  };

  const state = {
    year: 0,
    month: 0,
    loading: false,
    requests: [],
    notes: [],
    dates: [],
    customDates: [],
    birthdays: [],
    activeDayModalDate: null,
    activeDayModalItems: [],
    activeApprovalDate: null,
    activeApprovalLabel: '',
    cancelConfirmAction: null,
    expandedRequestKeys: new Set(),
    approvalSheetItems: [],
  };

  const MONTH_LABELS = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ];

  const WEEKDAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  function canManage() {
    const roleId = Number(config.currentUser && config.currentUser.roleId ? config.currentUser.roleId : 0);
    return roleId > 0 && roleId <= 2;
  }

  function normalizeStatus(status) {
    return String(status || '').trim().toLowerCase();
  }

  function getDisplayName(value) {
    return String(value || '').trim();
  }

  function cloneDate(value) {
    const copy = new Date(value.getTime());
    copy.setHours(0, 0, 0, 0);
    return copy;
  }

  function parseDateValue(value) {
    if (!value && value !== 0) return null;
    if (value instanceof Date) {
      return isNaN(value.getTime()) ? null : cloneDate(value);
    }

    if (typeof value === 'number') {
      const fromNumber = new Date(value);
      return isNaN(fromNumber.getTime()) ? null : cloneDate(fromNumber);
    }

    const raw = String(value).trim();
    if (!raw) return null;

    const candidate = /^\d{4}-\d{2}-\d{2}$/.test(raw) ? `${raw}T00:00:00` : raw;
    const parsed = new Date(candidate);
    if (isNaN(parsed.getTime())) return null;
    parsed.setHours(0, 0, 0, 0);
    return parsed;
  }

  function sameMonthYear(date, year, month) {
    return date && date.getFullYear() === year && date.getMonth() === month;
  }

  function formatDateParts(date) {
    if (!date) return '';
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function formatDateForInput(date) {
    return formatDateParts(date);
  }

  function formatRange(start, end) {
    if (!start) return '';
    if (!end || formatDateParts(start) === formatDateParts(end)) {
      return formatDateParts(start);
    }
    return `${formatDateParts(start)} to ${formatDateParts(end)}`;
  }

  function formatDisplayDate(date) {
    if (!date) return '';
    return `${MONTH_LABELS[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
  }

  function syncCustomEmailVisibility() {
    if (!refs.emailSelect || !refs.customEmailWrap) return;

    const isCustom = String(refs.emailSelect.value || '') === '__custom__';
    refs.customEmailWrap.style.display = isCustom ? 'grid' : 'none';
    if (refs.customEmailInput) {
      refs.customEmailInput.required = isCustom;
    }
  }

  function getSelectedRecipientEmail() {
    if (!refs.emailSelect) return '';

    const selected = String(refs.emailSelect.value || '').trim();
    if (selected === '__custom__') {
      return String(refs.customEmailInput && refs.customEmailInput.value ? refs.customEmailInput.value : '').trim();
    }

    if (selected) {
      return selected;
    }

    return String(config.defaultEmail || config.adminEmail || '').trim();
  }

  async function handleSubmit() {
    const requestType = String(document.getElementById('calRequestType')?.value || 'timeoff').trim().toLowerCase();
    const recurring = String(document.getElementById('calRecurring')?.value || 'none').trim();
    const startDate = String(refs.startInput && refs.startInput.value ? refs.startInput.value : '').trim();
    const endDate = String(refs.endInput && refs.endInput.value ? refs.endInput.value : '').trim() || startDate;
    const note = String(refs.noteInput && refs.noteInput.value ? refs.noteInput.value : '').trim();

    if (!startDate || !endDate) {
      window.alert('Select a start and end date before submitting.');
      return;
    }

    if (requestType !== 'timeoff' && requestType !== 'vacation') {
      let category = 'INFO';
      let label = 'Calendar Item';

      if (requestType === 'holiday' || requestType === 'holiday_note') {
        category = 'HOLIDAY';
        label = 'Holiday';
      }

      if (requestType === 'message' || requestType === 'message_note' || requestType === 'info' || requestType === 'advisory_note') {
        category = 'INFO';
        label = 'Message';
      }

      if (!config.notesEndpoint) {
        window.alert('Notes endpoint is not configured.');
        return;
      }

      const notePayload = {
        note_date: startDate,
        end_date: endDate,
        recurring: recurring,
        recurring_until: recurring !== 'none' ? endDate : null,
        title: label,
        description: note || label,
        category,
      };

      try {
        const response = await fetch(config.notesEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify(notePayload),
        });

        if (!response.ok) {
          const text = await response.text().catch(() => '');
          throw new Error(text || `Request failed with status ${response.status}`);
        }

        window.alert('Calendar item saved.');
        if (refs.noteInput) refs.noteInput.value = '';
        loadMonth();
      } catch (err) {
        window.alert(`Submit failed: ${err.message || err}`);
      }

      return;
    }

    const recipientEmail = getSelectedRecipientEmail();

    if (!recipientEmail) {
      window.alert('Select a recipient email or choose Custom email.');
      return;
    }

    const payload = {
      request_type: requestType,
      recurring,
      start: startDate,
      end: endDate,
      start_date: startDate,
      end_date: endDate,
      note,
      recipientEmail,
      recipient_email: recipientEmail,
      sender_email: String(config.adminEmail || '').trim(),
      user_id: config.currentUser && config.currentUser.id ? Number(config.currentUser.id) : 0,
      username: config.currentUser && config.currentUser.username ? String(config.currentUser.username) : '',
    };

    const endpoints = [config.requestsEndpoint, config.emailEndpoint].filter(Boolean);
    if (!endpoints.length) {
      window.alert('Submission endpoint is not configured.');
      return;
    }

    try {
      for (const endpoint of endpoints) {
        const response = await fetch(endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify(payload),
        });

        if (!response.ok) {
          const text = await response.text().catch(() => '');
          throw new Error(text || `Request failed with status ${response.status}`);
        }
      }

      window.alert('Request submitted.');
      if (refs.noteInput) refs.noteInput.value = '';
      loadMonth();
    } catch (err) {
      window.alert(`Submit failed: ${err.message || err}`);
    }
  }

  function populateEmailSelect() {
    if (!refs.emailSelect) return;

    const options = Array.isArray(config.emailOptions) ? config.emailOptions : [];
    const currentValue = refs.emailSelect.value;
    const fallbackEmail = String(config.adminEmail || '').trim();
    const defaultEmail = String(config.defaultEmail || '').trim();

    refs.emailSelect.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select recipient';
    refs.emailSelect.appendChild(placeholder);

    const seen = new Set();
    options.forEach((opt) => {
      const value = String(opt && opt.value ? opt.value : '').trim();
      if (!value || seen.has(value.toLowerCase())) return;
      seen.add(value.toLowerCase());

      const option = document.createElement('option');
      option.value = value;
      option.textContent = String(opt && opt.label ? opt.label : value);
      refs.emailSelect.appendChild(option);
    });

    if (fallbackEmail && !seen.has(fallbackEmail.toLowerCase())) {
      const option = document.createElement('option');
      option.value = fallbackEmail;
      option.textContent = `Admin <${fallbackEmail}>`;
      refs.emailSelect.appendChild(option);
    }

    if (defaultEmail && !seen.has(defaultEmail.toLowerCase())) {
      const option = document.createElement('option');
      option.value = defaultEmail;
      option.textContent = defaultEmail;
      refs.emailSelect.appendChild(option);
      seen.add(defaultEmail.toLowerCase());
    }

    const customOption = document.createElement('option');
    customOption.value = '__custom__';
    customOption.textContent = 'Custom email';
    refs.emailSelect.appendChild(customOption);

    if (defaultEmail) {
      const match = Array.from(refs.emailSelect.options).find(
        (opt) => String(opt.value || '').toLowerCase() === defaultEmail.toLowerCase()
      );
      if (match) {
        refs.emailSelect.value = match.value;
      }
    }

    if (currentValue && Array.from(refs.emailSelect.options).some((opt) => opt.value === currentValue)) {
      refs.emailSelect.value = currentValue;
    } else if (!refs.emailSelect.value && refs.emailSelect.options.length > 1) {
      refs.emailSelect.selectedIndex = 1;
    }

    syncCustomEmailVisibility();
  }

  function normalizeCollection(payload) {
    if (Array.isArray(payload)) return payload;
    if (!payload || typeof payload !== 'object') return [];

    const keys = ['items', 'data', 'rows', 'results', 'requests', 'notes', 'dates', 'birthdays'];
    for (const key of keys) {
      if (Array.isArray(payload[key])) return payload[key];
    }

    return [];
  }

  async function fetchCollection(url, params) {
    if (!url) return [];

    try {
      const target = new URL(url, window.location.href);
      Object.entries(params || {}).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') {
          target.searchParams.set(key, String(value));
        }
      });

      const response = await fetch(target.toString(), {
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
        },
      });

      if (!response.ok) return [];
      const payload = await response.json();
      return normalizeCollection(payload);
    } catch (err) {
      return [];
    }
  }

  function requestIsCancelled(req) {
    const status = normalizeStatus(req.status || req.request_status);
    return status === 'cancelled' || status === 'canceled' || status === 'denied' || status === 'rejected';
  }

  function requestIsPending(req) {
    return normalizeStatus(req.status || req.request_status) === 'pending';
  }

  function requestRange(req) {
    const start = parseDateValue(
      req && (
        req.start ||
        req.start_date ||
        req.startDate ||
        req.from ||
        req.request_start ||
        req.requestStart ||
        req.requestStartDate ||
        req.request_start_date ||
        req.requestStartAt ||
        req.request_start_at ||
        req.requestStartDateTime
      )
    );
    const end = parseDateValue(
      req && (
        req.end ||
        req.end_date ||
        req.endDate ||
        req.to ||
        req.request_end ||
        req.requestEnd ||
        req.requestEndDate ||
        req.request_end_date ||
        req.requestEndAt ||
        req.request_end_at ||
        req.requestEndDateTime
      )
    ) || start;

    return { start: start, end: end };
  }

  function requestDateLabel(req) {
    const range = requestRange(req);
    if (!range.start) return 'Unknown date';
    return formatRange(range.start, range.end);
  }

  function requestDurationLabel(req) {
    const range = requestRange(req);
    if (!range.start) return '';

    const start = range.start;
    const end = range.end || range.start;
    const diffDays = Math.max(1, Math.round((end.getTime() - start.getTime()) / 86400000) + 1);

    if (diffDays === 1) {
      return '1 day';
    }

    return `${diffDays} days`;
  }

  function requestReasonText(req) {
    return String((req && (req.note || req.description || req.reason || req.text)) || '').trim();
  }

  function requestKey(req) {
    const id = Number(req && (req.id || req.request_id || req.requestId || 0));
    if (id > 0) return String(id);

    const range = requestRange(req || {});
    return [
      requestDisplayName(req || {}),
      requestTypeClass(req || {}),
      range.start ? formatDateParts(range.start) : '',
      range.end ? formatDateParts(range.end) : '',
      requestReasonText(req || {}),
    ].join('|');
  }

  function requestBadgeLabel(item) {
    const type = normalizeStatus(item && item.type);
    if (type === 'timeoff') return 'TIMEOFF';
    if (type === 'holiday') return 'HOLIDAY';
    if (type === 'message' || type === 'note') return 'MESSAGE';
    if (type === 'bday' || type === 'birthday') return 'BIRTHDAY';
    return (type || 'ITEM').toUpperCase();
  }

  function normalizeApprovalItem(item) {
    if (!item) return null;

    const request = item.request ? item.request : item;
    const type = requestTypeClass(request);
    const range = requestRange(request);
    const status = normalizeStatus(request.status || request.request_status);
    const text = requestDisplayName(request);
    const typeLabel = requestTypeLabel(request);

    return {
      type,
      status,
      text: type === 'timeoff' ? text : `${typeLabel}: ${text}`,
      title: type === 'timeoff'
        ? text
        : `${typeLabel} request from ${text} - ${formatRange(range.start, range.end)}${status ? ` (${status})` : ''}`,
      requestId: Number(request.id || request.request_id || request.requestId || 0),
      request,
      requestStart: range.start,
      requestEnd: range.end,
      requestDateLabel: formatRange(range.start, range.end),
      pendingApproval: type === 'timeoff' && status === 'pending',
    };
  }

  function requestDisplayName(req) {
    return (
      getDisplayName(req.createdByName) ||
      getDisplayName(req.created_by_name) ||
      getDisplayName(req.fullName) ||
      getDisplayName(req.name) ||
      getDisplayName(req.createdByUsername) ||
      getDisplayName(req.username) ||
      'User'
    );
  }

  function requestTypeLabel(req) {
    const note = String(req.note || req.description || req.text || '').trim();

    if (/holiday/i.test(note)) return 'Holiday';
    if (/vacation/i.test(note)) return 'Vacation';
    if (/sick/i.test(note)) return 'Sick';
    if (/personal/i.test(note)) return 'Personal';

    return 'Time Off';
  }

  function requestTypeClass(req) {
    const type = normalizeStatus(req.type || req.request_type || 'timeoff');
    if (type === 'holiday_note' || type === 'holiday') return 'holiday';
    if (type === 'advisory_note' || type === 'message' || type === 'note') return 'message';
    if (type === 'birthday' || type === 'bday') return 'bday';
    return 'timeoff';
  }

  function easterSunday(year) {
    const a = year % 19;
    const b = Math.floor(year / 100);
    const c = year % 100;
    const d = Math.floor(b / 4);
    const e = b % 4;
    const f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4);
    const k = c % 4;
    const l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);
    const month = Math.floor((h + l - 7 * m + 114) / 31) - 1;
    const day = ((h + l - 7 * m + 114) % 31) + 1;
    return new Date(year, month, day);
  }

  function nthWeekdayOfMonth(year, month, weekday, n) {
    const date = new Date(year, month, 1);
    const offset = (weekday - date.getDay() + 7) % 7;
    return new Date(year, month, 1 + offset + (n - 1) * 7);
  }

  function firstMondayOfSeptember(year) {
    return nthWeekdayOfMonth(year, 8, 1, 1);
  }

  function secondMondayOfOctober(year) {
    return nthWeekdayOfMonth(year, 9, 1, 2);
  }

  function thirdMondayOfMay(year) {
    return nthWeekdayOfMonth(year, 4, 1, 3);
  }

  function getBuiltInHolidays(year) {
    const holidays = [];

    holidays.push({ date: new Date(year, 0, 1), name: "New Year's Day" });
    holidays.push({ date: new Date(year, 0, 2), name: "Day after New Year's" });

    const easter = easterSunday(year);

    const goodFriday = new Date(easter);
    goodFriday.setDate(easter.getDate() - 2);
    holidays.push({ date: goodFriday, name: 'Good Friday' });

    const easterMonday = new Date(easter);
    easterMonday.setDate(easter.getDate() + 1);
    holidays.push({ date: easterMonday, name: 'Easter Monday' });

    holidays.push({ date: thirdMondayOfMay(year), name: "National Patriots' Day" });

    let canadaDay = new Date(year, 6, 1);
    if (canadaDay.getDay() === 0) canadaDay = new Date(year, 6, 2);
    holidays.push({ date: canadaDay, name: 'Canada Day' });

    holidays.push({ date: new Date(year, 5, 24), name: 'Saint-Jean-Baptiste Day' });
    holidays.push({ date: firstMondayOfSeptember(year), name: 'Labour Day' });
    holidays.push({ date: secondMondayOfOctober(year), name: 'Thanksgiving' });
    holidays.push({ date: new Date(year, 11, 25), name: 'Christmas Day' });

    return holidays;
  }

  function getImportantDateItems(year, month) {
    const items = [];

    function addItem(day, item) {
      items.push(Object.assign({ day: day }, item));
    }

    state.notes.forEach((note) => {
      if (!note) return;
      const raw = note.note_date || note.date || note.noteDate;
      const dt = parseDateValue(raw);
      if (!dt || !sameMonthYear(dt, year, month)) return;

      const category = normalizeStatus(note.category || note.type);
      const text = getDisplayName(note.description || note.text || note.title || note.message);
      const isHoliday = category === 'holiday' || category === 'holiday_note';
      if (!isHoliday) return;

      addItem(dt.getDate(), {
        type: 'holiday',
        text: text || 'Holiday',
        title: text || 'Calendar note',
      });
    });

    const customDates = Array.isArray(state.customDates) ? state.customDates : state.dates;

    customDates.forEach((entry) => {
      if (!entry) return;

      const raw = entry.date || entry.note_date || entry.day_date || entry.start;
      const dt = parseDateValue(raw);
      const monthValue = Number(entry.month);
      const dayValue = Number(entry.day);
      const recurringMonth = Number.isFinite(monthValue) ? monthValue - 1 : null;
      const recurringDay = Number.isFinite(dayValue) ? dayValue : null;
      let day = null;
      let match = false;

      if (dt) {
        match = sameMonthYear(dt, year, month);
        day = dt.getDate();
      } else if (recurringMonth !== null && recurringDay !== null) {
        match = recurringMonth === month;
        day = recurringDay;
      }

      if (!match || !day) return;

      const category = normalizeStatus(entry.category || entry.type || entry.kind);
      const text = getDisplayName(entry.name || entry.title || entry.description || entry.text);
      const type = category === 'birthday' ? 'bday' : 'holiday';

      addItem(day, {
        type,
        text: text || (type === 'bday' ? 'Birthday' : 'Holiday'),
        title: text || 'Calendar date',
      });
    });

    state.birthdays.forEach((person) => {
      if (!person) return;

      const birthday = parseDateValue(person.birthday || person.date || person.birth_date);
      const monthValue = Number(person.month);
      const dayValue = Number(person.day);
      const monthMatch = birthday ? birthday.getMonth() === month : Number.isFinite(monthValue) && monthValue - 1 === month;
      const dayMatch = birthday ? true : Number.isFinite(dayValue);
      const day = birthday ? birthday.getDate() : dayValue;

      if (!monthMatch || !dayMatch || !day) return;

      const name = getDisplayName(person.name || `${person.first_name || ''} ${person.last_name || ''}`.trim());
      addItem(day, {
        type: 'bday',
        text: name || 'Birthday',
        title: name ? `${name}'s birthday` : 'Birthday',
      });
    });

    items.sort((a, b) => {
      const priority = {
        holiday: 1,
        bday: 2,
      };
      const aPriority = priority[a.type] ?? 99;
      const bPriority = priority[b.type] ?? 99;
      if (aPriority !== bPriority) return aPriority - bPriority;
      return Number(a.day || 0) - Number(b.day || 0);
    });

    return items;
  }

  function makeClampedDate(year, month, day) {
    const lastDay = new Date(year, month + 1, 0).getDate();

    const date = new Date(
      year,
      month,
      Math.min(day, lastDay)
    );

    date.setHours(0, 0, 0, 0);

    return date;
  }

function getRecurringNoteDates(note) {
  const start = parseDateValue(
    note.note_date ||
    note.date ||
    note.noteDate
  );

  if (!start) return [];

  const recurring = normalizeStatus(note.recurring || 'none');

  const until = parseDateValue(
    note.recurring_until ||
    note.recurringUntil
  );

  // Normal note
  if (recurring === 'none') {
    return [start];
  }

  // Recurring note requires an end boundary
  if (!until) {
    return [start];
  }

  const dates = [];
  let cursor = cloneDate(start);

  while (cursor <= until) {
    dates.push(cloneDate(cursor));

    switch (recurring) {
      case 'weekly':
        cursor.setDate(cursor.getDate() + 7);
        break;

      case 'monthly': {
        const originalDay = start.getDate();

        cursor = new Date(
          cursor.getFullYear(),
          cursor.getMonth() + 1,
          1
        );

        const lastDay = new Date(
          cursor.getFullYear(),
          cursor.getMonth() + 1,
          0
        ).getDate();

        cursor.setDate(Math.min(originalDay, lastDay));
        cursor.setHours(0, 0, 0, 0);
        break;
      }

      case 'yearly': {
        const originalMonth = start.getMonth();
        const originalDay = start.getDate();

        const nextYear = cursor.getFullYear() + 1;

        const lastDay = new Date(
          nextYear,
          originalMonth + 1,
          0
        ).getDate();

        cursor = new Date(
          nextYear,
          originalMonth,
          Math.min(originalDay, lastDay)
        );

        cursor.setHours(0, 0, 0, 0);
        break;
      }

      default:
        return dates;
    }
  }

  return dates;
}

  function buildDayItems(year, month) {
    const items = {};

    function addItem(day, item) {
      if (!items[day]) items[day] = [];
      items[day].push(item);
    }

    state.requests
      .slice()
      .sort((a, b) => (Number(a.id || 0) - Number(b.id || 0)))
      .forEach((req) => {
        if (!req || requestIsCancelled(req)) return;

        const range = requestRange(req);
        const start = range.start;
        const end = range.end;

        if (!start || !end) return;

        const cursor = cloneDate(start);
        const finalDay = cloneDate(end);
        const status = normalizeStatus(req.status || req.request_status);
        const text = requestDisplayName(req);
        const typeLabel = requestTypeLabel(req);
        const type = requestTypeClass(req);
        const requestId = Number(req.id || req.request_id || req.requestId || 0);

        while (cursor <= finalDay) {
          if (sameMonthYear(cursor, year, month)) {
            addItem(cursor.getDate(), {
              type,
              status,
              text: type === 'timeoff' ? text : `${typeLabel}: ${text}`,
              title: type === 'timeoff'
                ? text
                : `${typeLabel} request from ${text} - ${formatRange(start, end)}${status ? ` (${status})` : ''}`,
              requestId,
              request: req,
              requestStart: start,
              requestEnd: end,
              requestDateLabel: formatRange(start, end),
              pendingApproval: type === 'timeoff' && status === 'pending',
            });
          }
          cursor.setDate(cursor.getDate() + 1);
        }
      });

    state.notes.forEach((note) => {
      if (!note) return;

      const category = normalizeStatus(note.category || note.type);

      const text = getDisplayName(note.description || note.text || note.title || note.message);

      const type =
        category === 'holiday' ||
          category === 'holiday_note'
          ? null
          : 'message';

      // Holidays are currently handled by getImportantDateItems()
      if (!type) return;

      const occurrenceDates = getRecurringNoteDates(note);

      occurrenceDates.forEach((dt) => {
        // buildDayItems only needs dates for the displayed month
        if (!sameMonthYear(dt, year, month)) {
          return;
        }

        addItem(dt.getDate(), {
          type: 'message',
          text: text || 'Message',
          title: text || 'Calendar note',

          // useful later for Day Details
          note,
          occurrenceDate: cloneDate(dt),
          recurring: normalizeStatus(note.recurring || 'none'),
        });
      });
    });

    getBuiltInHolidays(year).forEach((holiday) => {
      const dt = parseDateValue(holiday.date);
      if (!dt || !sameMonthYear(dt, year, month)) return;

      addItem(dt.getDate(), {
        type: 'holiday',
        text: holiday.name,
        title: holiday.name,
      });
    });

    getImportantDateItems(year, month).forEach((entry) => {
      addItem(entry.day, entry);
    });

    Object.keys(items).forEach((dayKey) => {
      items[dayKey].sort((a, b) => {
        const priority = {
          holiday: 1,
          timeoff: 2,
          bday: 3,
          message: 4,
        };
        const aPriority = priority[a.type] ?? 99;
        const bPriority = priority[b.type] ?? 99;
        if (aPriority !== bPriority) return aPriority - bPriority;
        return String(a.text || '').localeCompare(String(b.text || ''));
      });
    });

    return items;
  }

  function getPendingApprovalItems(dayItems) {
    const unique = new Map();

    (dayItems || []).forEach((item) => {
      const normalized = normalizeApprovalItem(item);
      if (!normalized || normalized.type !== 'timeoff' || normalizeStatus(normalized.status) !== 'pending') return;

      const requestId = Number(normalized.requestId || normalized.request?.id || 0);
      const key = requestId > 0 ? String(requestId) : `${normalized.title || normalized.text || 'pending'}|${normalized.requestDateLabel || ''}`;
      if (!unique.has(key)) {
        unique.set(key, normalized);
      }
    });

    return Array.from(unique.values()).sort((a, b) => Number(a.requestId || 0) - Number(b.requestId || 0));
  }

  function getRequestTitle(item) {
    const req = item && item.request ? item.request : null;
    const typeLabel = req ? requestTypeLabel(req) : 'Time Off';
    const person = req ? requestDisplayName(req) : getDisplayName(item && item.text);
    return `${typeLabel}: ${person}`;
  }

  function dayCellClasses(dayItems) {
    const classes = ['cal__cell'];
    const types = new Set((dayItems || []).map((item) => item.type));
    const pendingApprovals = getPendingApprovalItems(dayItems);

    if (types.has('timeoff')) {
      const statuses = new Set((dayItems || []).filter((item) => item.type === 'timeoff').map((item) => item.status));
      if (statuses.has('approved')) classes.push('is-timeoff-approved');
      else if (statuses.has('pending')) classes.push('is-timeoff-pending');
      else classes.push('is-timeoff');
    }

    if (pendingApprovals.length) {
      classes.push('has-pending-approval');
    }

    if (types.has('holiday')) classes.push('is-holiday-qc');
    if (types.has('bday')) classes.push('is-bday');
    if (types.has('message')) classes.push('is-message');

    return classes.join(' ');
  }

  function renderDayBadges(cell, dayItems) {
    if (!dayItems || dayItems.length === 0) return;

    const labelWrap = document.createElement('div');
    labelWrap.className = 'cal__cell-labels';

    const pendingApprovals = getPendingApprovalItems(dayItems);
    const otherItems = dayItems.filter((item) => !item.pendingApproval);

    if (pendingApprovals.length) {
      const badge = document.createElement('div');
      badge.className = 'cal__approval-badge';
      badge.textContent = 'Pending Approval';

      if (pendingApprovals.length > 1) {
        const count = document.createElement('span');
        count.className = 'cal__approval-badge-count';
        count.textContent = String(pendingApprovals.length);
        badge.appendChild(count);
      }

      badge.title = pendingApprovals.map((item) => item.title || item.text || 'Pending request').join('\n');
      labelWrap.appendChild(badge);
    }

    const visible = otherItems.slice(0, pendingApprovals.length ? 2 : 3);
    visible.forEach((item) => {
      const badge = document.createElement('div');
      badge.className = `cal__cell-label cal__cell-label--${item.type}`;
      if (item.type === 'timeoff' && item.status) {
        badge.classList.add(`is-${item.status}`);
      }
      badge.textContent = item.text;
      badge.title = item.title || item.text;
      labelWrap.appendChild(badge);
    });

    const hiddenCount = Math.max(0, otherItems.length - visible.length);
    if (hiddenCount > 0) {
      const more = document.createElement('div');
      more.className = 'cal__cell-label cal__cell-label--more';
      more.textContent = `+${hiddenCount} more`;
      more.title = dayItems.map((item) => item.title || item.text).join('\n');
      labelWrap.appendChild(more);
    }

    cell.appendChild(labelWrap);
  }

  function hideDayModal() {
    if (!refs.dayModal) return;

    refs.dayModal.classList.remove('is-open');
    refs.dayModal.setAttribute('aria-hidden', 'true');
    state.activeDayModalDate = null;
    state.activeDayModalItems = [];
  }

  function hideApprovalDrawer() {
    if (!refs.approvalDrawer) return;

    refs.approvalDrawer.classList.remove('is-open');
    refs.approvalDrawer.setAttribute('aria-hidden', 'true');
    state.activeApprovalDate = null;
    state.activeApprovalLabel = '';
    state.approvalSheetItems = [];
    if (refs.approvalTrigger) {
      refs.approvalTrigger.setAttribute('aria-expanded', 'false');
      refs.approvalTrigger.hidden = false;
    }
  }

  function hideCancelConfirmModal() {
    if (!refs.cancelConfirmModal) return;

    refs.cancelConfirmModal.classList.remove('is-open');
    refs.cancelConfirmModal.setAttribute('aria-hidden', 'true');
    state.cancelConfirmAction = null;
  }

  function openCancelConfirmModal(message, onConfirm) {
    if (!refs.cancelConfirmModal) {
      if (typeof onConfirm === 'function' && window.confirm(message)) {
        onConfirm();
      }
      return;
    }

    state.cancelConfirmAction = typeof onConfirm === 'function' ? onConfirm : null;

    if (refs.cancelConfirmText) {
      refs.cancelConfirmText.textContent = message;
    }

    refs.cancelConfirmModal.classList.add('is-open');
    refs.cancelConfirmModal.setAttribute('aria-hidden', 'false');

    if (refs.cancelKeepButton) {
      refs.cancelKeepButton.focus();
    }
  }

  function confirmCancelAction() {
    const action = state.cancelConfirmAction;
    hideCancelConfirmModal();
    if (typeof action === 'function') {
      action();
    }
  }

  function setCanceledCardState(card, item) {
    if (!card) return;

    card.classList.add('cal-modal__item--expanded');
    card.classList.add('is-cancelled');
    card.setAttribute('aria-expanded', 'true');

    const badge = card.querySelector('.cal-modal__badge');
    if (badge) {
      badge.className = 'cal-modal__badge cal-modal__badge--status-cancelled';
      badge.textContent = 'CANCELED';
    }

    const chevron = card.querySelector('.cal-modal__item-chevron');
    if (chevron) {
      chevron.textContent = '';
    }

    const actions = card.querySelector('.cal-modal__cancel-expand');
    if (actions) {
      actions.innerHTML = '';
      const note = document.createElement('div');
      note.className = 'cal-modal__expanded-note';
      note.textContent = `Canceled for ${requestDisplayName(item && item.request ? item.request : item)}.`;
      actions.appendChild(note);
    }

    const details = card.querySelector('.cal-modal__item-details');
    if (details) {
      details.setAttribute('aria-hidden', 'false');
    }
  }

  function setCanceledSheetState(card, item) {
    if (!card) return;

    card.classList.add('is-cancelled');

    const avatar = card.querySelector('.cal-sheet-item__avatar');
    if (avatar) {
      avatar.style.background = 'linear-gradient(180deg, #e2e8f0 0%, #cbd5e1 100%)';
      avatar.style.color = '#475569';
      avatar.textContent = 'CA';
    }

    const title = card.querySelector('.cal-sheet-item__title');
    if (title) {
      title.textContent = `${requestDisplayName(item && item.request ? item.request : item)} (Canceled)`;
    }

    const meta = card.querySelector('.cal-sheet-item__meta');
    if (meta) {
      meta.textContent = 'Canceled';
    }

    const actions = card.querySelector('.cal-sheet-item__actions');
    if (actions) {
      actions.innerHTML = '';
      const badge = document.createElement('div');
      badge.className = 'cal-modal__badge cal-modal__badge--status-cancelled';
      badge.textContent = 'CANCELED';
      actions.appendChild(badge);
    }
  }

  function updateApprovalTrigger(items, label) {
    const pendingItems = Array.isArray(items) ? items.filter((item) => item && requestIsPending(item)) : [];
    const count = pendingItems.length;
    const isManager = canManage();

    if (refs.approvalTrigger) {
      refs.approvalTrigger.hidden = !isManager;
      refs.approvalTrigger.disabled = !isManager;
      refs.approvalTrigger.setAttribute('aria-expanded', refs.approvalDrawer && refs.approvalDrawer.classList.contains('is-open') ? 'true' : 'false');
    }

    if (!isManager) return;

    if (refs.approvalTriggerBadge) {
      refs.approvalTriggerBadge.textContent = String(count);
      refs.approvalTriggerBadge.dataset.state = count > 0 ? 'active' : 'idle';
    }

    if (refs.approvalTriggerText) {
      refs.approvalTriggerText.textContent = count > 0
        ? `${count} pending request${count === 1 ? '' : 's'} awaiting approval${label ? ` · ${label}` : ''}`
        : `No pending requests${label ? ` · ${label}` : ''}`;
    }

    if (refs.approvalTriggerChevron) {
      refs.approvalTriggerChevron.textContent = '⌃';
    }
  }

  function renderApprovalRequestCard(item) {
    const normalized = normalizeApprovalItem(item);
    const request = normalized ? normalized.request : null;
    const card = document.createElement('article');
    card.className = 'cal-sheet-item';

    const avatar = document.createElement('div');
    avatar.className = 'cal-sheet-item__avatar';
    const displayName = requestDisplayName(request || item) || 'Pending request';
    avatar.textContent = displayName.split(/\s+/).slice(0, 2).map((part) => part.charAt(0).toUpperCase()).join('').slice(0, 2) || 'PR';
    card.appendChild(avatar);

    const body = document.createElement('div');
    body.className = 'cal-sheet-item__body';

    const topRow = document.createElement('div');
    topRow.className = 'cal-sheet-item__top';

    const title = document.createElement('div');
    title.className = 'cal-sheet-item__title';
    title.textContent = displayName;
    topRow.appendChild(title);

    const quickDate = document.createElement('div');
    quickDate.className = 'cal-sheet-item__date';
    quickDate.textContent = requestDateLabel(request || item);
    topRow.appendChild(quickDate);

    body.appendChild(topRow);

    const meta = document.createElement('div');
    meta.className = 'cal-sheet-item__meta';
    const metaBits = [];
    if (request) {
      metaBits.push(requestTypeLabel(request));
    }
    const duration = requestDurationLabel(request || item);
    if (duration) {
      metaBits.push(duration);
    }
    const reason = requestReasonText(request || item);
    if (reason) {
      metaBits.push(reason);
    }
    meta.textContent = metaBits.join(' · ');
    body.appendChild(meta);

    card.appendChild(body);

    const actions = document.createElement('div');
    actions.className = 'cal-sheet-item__actions';

    if (canManage() && request && (request.id || request.request_id || request.requestId)) {
      const requestId = Number(request.id || request.request_id || request.requestId || 0);
      const approve = document.createElement('button');
      approve.type = 'button';
      approve.className = 'cal-sheet-item__action cal-sheet-item__action--approve';
      approve.setAttribute('aria-label', `Approve ${displayName}`);
      approve.textContent = '✓';
      approve.addEventListener('click', () => {
        updateRequestStatus(requestId, 'approved')
          .then(() => {
            hideApprovalDrawer();
            loadMonth();
          })
          .catch((err) => {
            window.alert('Approve failed: ' + (err && err.message ? err.message : err));
          });
      });
      actions.appendChild(approve);

      const deny = document.createElement('button');
      deny.type = 'button';
      deny.className = 'cal-sheet-item__action cal-sheet-item__action--deny';
      deny.setAttribute('aria-label', `Deny ${displayName}`);
      deny.textContent = '✕';
      deny.addEventListener('click', () => {
        openCancelConfirmModal(`Are you sure you want to deny this pending request for ${displayName}?`, () => {
          updateRequestStatus(requestId, 'cancelled')
            .then(() => {
              setCanceledSheetState(card, request);
              window.setTimeout(() => {
                hideApprovalDrawer();
                loadMonth();
              }, 1600);
            })
            .catch((err) => {
              window.alert('Cancel failed: ' + (err && err.message ? err.message : err));
            });
        });
      });
      actions.appendChild(deny);
    }

    card.appendChild(actions);
    return card;
  }

  function openApprovalDrawer(dateOrLabel, pendingItems) {
    if (!refs.approvalDrawer || !refs.approvalDrawerBody) return;

    const items = getPendingApprovalItems(pendingItems);
    state.activeApprovalDate = dateOrLabel instanceof Date ? cloneDate(dateOrLabel) : null;
    state.activeApprovalLabel = dateOrLabel instanceof Date
      ? formatDisplayDate(dateOrLabel)
      : String(dateOrLabel || '').trim();
    state.approvalSheetItems = items.slice();

    if (refs.approvalDrawerTitle) {
      refs.approvalDrawerTitle.textContent = 'Pending Approvals Dashboard';
    }
    if (refs.approvalDrawerDate) {
      const scopeLabel = state.activeApprovalLabel || 'Current view';
      refs.approvalDrawerDate.textContent = `${scopeLabel} • ${items.length} pending ${items.length === 1 ? 'request' : 'requests'}`;
    }

    refs.approvalDrawerBody.classList.add('cal-drawer__body--sheet');
    refs.approvalDrawerBody.innerHTML = '';

    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'cal-drawer__empty';
      empty.textContent = state.activeApprovalDate
        ? 'No pending approvals for this day.'
        : 'No pending approvals in the current view.';
      refs.approvalDrawerBody.appendChild(empty);
    } else {
      items.forEach((item) => {
        refs.approvalDrawerBody.appendChild(renderApprovalRequestCard(item));
      });
    }

    refs.approvalDrawer.classList.add('is-open');
    refs.approvalDrawer.setAttribute('aria-hidden', 'false');

    if (refs.approvalDrawerClose) {
      refs.approvalDrawerClose.focus();
    }
    if (refs.approvalTrigger) {
      refs.approvalTrigger.setAttribute('aria-expanded', 'true');
      refs.approvalTrigger.hidden = true;
    }
  }

  function selectCalendarDate(date) {
    if (!refs.startInput || !refs.endInput) return;

    const selectedVal = formatDateForInput(date);
    refs.startInput.value = selectedVal;
    refs.endInput.value = selectedVal;
    render();

    if (refs.noteInput) {
      refs.noteInput.focus();
    }
  }

  function isCancelableTimeoffItem(item) {
    return !!item && item.type === 'timeoff' && normalizeStatus(item.status) === 'approved';
  }

  function renderDayModalRequestCard(item) {
    const request = item && item.request ? item.request : null;
    const card = document.createElement('div');
    card.className = 'cal-modal__item';

    const isTimeoff = item && item.type === 'timeoff';
    const isPending =
      isTimeoff &&
      normalizeStatus(item.status) === 'pending';

    const canCancel = isCancelableTimeoffItem(item);
    const canExpand = canCancel || isPending;

    const expanded = canCancel && state.expandedRequestKeys.has(requestKey(request || item));

    if (canExpand) {
      card.classList.add('cal-modal__item--expandable');
      if (expanded) {
        card.classList.add('cal-modal__item--expanded');
      }
      card.setAttribute('role', 'button');
      card.setAttribute('tabindex', '0');
      card.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    const badge = document.createElement('span');
    badge.className = `cal-modal__badge cal-modal__badge--${item.type || 'message'}`;
    if (item.type === 'timeoff' && item.status) {
      badge.classList.add(`is-${item.status}`);
    }

    if (item.type === 'timeoff' && normalizeStatus(item.status) === 'cancelled') {
      badge.classList.add('cal-modal__badge--status-cancelled');
      badge.textContent = 'CANCELED';

    } else if (isPending) {
      badge.textContent = 'PENDING';

    } else {
      badge.textContent = requestBadgeLabel(item);
    }

    const chevron = document.createElement('span');
    chevron.className = 'cal-modal__item-chevron';
    chevron.textContent = canExpand ? '∨' : '';

    const content = document.createElement('div');
    content.className = 'cal-modal__item-main';

    const header = document.createElement('div');
    header.className = 'cal-modal__item-header';

    const titleBlock = document.createElement('div');
    titleBlock.className = 'cal-modal__content';

    const title = document.createElement('div');
    title.className = 'cal-modal__item-title';
    title.textContent = item.title || item.text || 'Item';
    titleBlock.appendChild(title);

    if (item.text && item.text !== item.title) {
      const text = document.createElement('div');
      text.className = 'cal-modal__item-text';
      text.textContent = item.text;
      titleBlock.appendChild(text);
    }

    header.appendChild(badge);
    header.appendChild(titleBlock);
    header.appendChild(chevron);
    content.appendChild(header);

    if (canExpand) {
      const details = document.createElement('div');
      details.className = 'cal-modal__item-details';
      details.setAttribute('aria-hidden', expanded ? 'false' : 'true');

      const detailGrid = document.createElement('div');
      detailGrid.className = 'cal-modal__detail-grid';

      const durationRow = document.createElement('div');
      durationRow.className = 'cal-modal__detail-row';
      durationRow.innerHTML = '';
      const durationLabel = document.createElement('div');
      durationLabel.className = 'cal-modal__detail-label';
      durationLabel.textContent = 'Duration';
      const durationValue = document.createElement('div');
      durationValue.className = 'cal-modal__detail-value';
      durationValue.textContent = `${requestDateLabel(request || item)}${requestDurationLabel(request || item) ? ` (${requestDurationLabel(request || item)})` : ''}`;
      durationRow.appendChild(durationLabel);
      durationRow.appendChild(durationValue);
      detailGrid.appendChild(durationRow);

      const reasonRow = document.createElement('div');
      reasonRow.className = 'cal-modal__detail-row';
      const reasonLabel = document.createElement('div');
      reasonLabel.className = 'cal-modal__detail-label';
      reasonLabel.textContent = 'Reason';
      const reasonValue = document.createElement('div');
      reasonValue.className = 'cal-modal__detail-value';
      reasonValue.textContent = requestReasonText(request || item) || 'No reason provided.';
      reasonRow.appendChild(reasonLabel);
      reasonRow.appendChild(reasonValue);
      detailGrid.appendChild(reasonRow);

      details.appendChild(detailGrid);

      const cancelWrap = document.createElement('div');
      cancelWrap.className = 'cal-modal__cancel-expand';

      const cancelNote = document.createElement('div');
      cancelNote.className = 'cal-modal__expanded-note';
      cancelNote.textContent = 'Canceling this request will update the request status and keep the change visible until the calendar refreshes.';
      cancelWrap.appendChild(cancelNote);

      const cancelButton = document.createElement('button');
      cancelButton.type = 'button';
      cancelButton.className = 'cal-modal__cancel-btn';
      cancelButton.textContent = 'Cancel Request';
      cancelButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        const displayName = requestDisplayName(request || item);
        openCancelConfirmModal(
          `Are you sure you want to cancel this approved time off for ${displayName}?`,
          () => {
            const targetRequest = request || item.request || item;
            const targetId = Number(targetRequest && (targetRequest.id || targetRequest.request_id || targetRequest.requestId || 0));
            if (!targetId) {
              window.alert('Cancel failed: missing request ID.');
              return;
            }

            updateRequestStatus(targetId, 'cancelled')
              .then(() => {
                setCanceledCardState(card, item);
                state.expandedRequestKeys.delete(requestKey(targetRequest));
                window.setTimeout(() => {
                  if (refs.dayModal && refs.dayModal.classList.contains('is-open')) {
                    loadMonth();
                  }
                }, 1800);
              })
              .catch((err) => {
                window.alert('Cancel failed: ' + (err && err.message ? err.message : err));
              });
          }
        );
      });

      cancelWrap.appendChild(cancelButton);
      details.appendChild(cancelWrap);
      content.appendChild(details);

      const setExpandedState = (nextExpanded) => {
        const isExpanded = !!nextExpanded;
        if (isExpanded) {
          state.expandedRequestKeys.add(requestKey(request || item));
        } else {
          state.expandedRequestKeys.delete(requestKey(request || item));
        }

        card.classList.toggle('cal-modal__item--expanded', isExpanded);
        card.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        details.setAttribute('aria-hidden', isExpanded ? 'false' : 'true');
      };

      setExpandedState(expanded);

      card.addEventListener('click', (event) => {
        if (event.target.closest('button')) return;
        setExpandedState(!card.classList.contains('cal-modal__item--expanded'));
      });

      card.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        setExpandedState(!card.classList.contains('cal-modal__item--expanded'));
      });
    }

    card.appendChild(content);

    return card;
  }

  function openDayModal(date, dayItems) {
    if (!refs.dayModal || !refs.dayModalBody) return;

    const nextDate = cloneDate(date);
    if (!state.activeDayModalDate || state.activeDayModalDate.getTime() !== nextDate.getTime()) {
      state.expandedRequestKeys.clear();
    }

    state.activeDayModalDate = nextDate;
    state.activeDayModalItems = Array.isArray(dayItems) ? dayItems.slice() : [];

    if (refs.dayModalTitle) {
      refs.dayModalTitle.textContent = 'Day Details';
    }
    if (refs.dayModalDate) {
      refs.dayModalDate.textContent = formatDisplayDate(date);
    }

    refs.dayModalBody.innerHTML = '';

    const summary = document.createElement('div');
    summary.className = 'cal-modal__summary';
    summary.textContent = `${dayItems.length} item${dayItems.length === 1 ? '' : 's'} on this day`;
    refs.dayModalBody.appendChild(summary);

    dayItems.forEach((item) => {
      refs.dayModalBody.appendChild(renderDayModalRequestCard(item));
    });

    if (refs.dayModalUseDate) {
      refs.dayModalUseDate.onclick = function () {
        if (state.activeDayModalDate) {
          selectCalendarDate(state.activeDayModalDate);
        }
        hideDayModal();
      };
    }

    refs.dayModal.classList.add('is-open');
    refs.dayModal.setAttribute('aria-hidden', 'false');
  }

  function renderList(container, items, emptyText, className) {
    if (!container) return;
    container.innerHTML = '';

    if (!items.length) {
      const empty = document.createElement('div');
      empty.className = 'cal__empty';
      empty.textContent = emptyText;
      container.appendChild(empty);
      return;
    }

    items.forEach((item) => {
      const row = document.createElement('div');
      row.className = `cal__list-item ${className || ''} ${item.type ? `cal__list-item--type-${item.type}` : ''}`.trim();

      if (item.type) {
        row.dataset.itemType = item.type;
      }

      if (item.type) {
        const accent = document.createElement('span');
        accent.className = `cal__item-accent cal__item-accent--${item.type}`;
        accent.setAttribute('aria-hidden', 'true');
        row.appendChild(accent);
      }

      const title = document.createElement('div');
      title.className = 'cal__list-title';
      title.textContent = item.title || item.text || 'Item';

      row.appendChild(title);

      if (item.meta) {
        const meta = document.createElement('div');
        meta.className = 'cal__list-meta';
        meta.textContent = item.meta;
        row.appendChild(meta);
      }

      container.appendChild(row);
    });
  }

  function updateRequestStatus(id, status) {
    if (!config.requestsEndpoint) {
      return Promise.reject(new Error('Requests endpoint is not configured.'));
    }

    return fetch(config.requestsEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        action: 'status',
        id: Number(id) || 0,
        status: String(status || ''),
      }),
    }).then(async (response) => {
      const text = await response.text().catch(() => '');
      let payload = null;

      try {
        payload = text ? JSON.parse(text) : null;
      } catch (_) {
        payload = null;
      }

      if (!response.ok || (payload && payload.ok === false)) {
        const message = (payload && payload.error) || text || `Request failed with status ${response.status}`;
        throw new Error(message);
      }

      return payload || { ok: true };
    });
  }

  function renderQueueItem(req, statusLabel) {
    var status = String(req.status || req.request_status || '').trim().toLowerCase();

    var bar = document.createElement('div');
    bar.className = 'toast-bar';
    if (status === 'approved') bar.classList.add('is-approved');
    if (status === 'pending') bar.classList.add('is-pending');
    if (status === 'denied' || status === 'rejected') bar.classList.add('is-denied');

    var info = document.createElement('div');
    info.className = 'toast-info';
    info.textContent = req.createdByName || req.createdByUsername || 'User';
    info.title =
      'Time off request: ' +
      info.textContent +
      ' - ' +
      formatRange(
        parseDateValue(req.start || req.start_date || req.startDate || req.from || req.request_start),
        parseDateValue(req.end || req.end_date || req.endDate || req.to || req.request_end)
      ) +
      (statusLabel ? ' (' + statusLabel + ')' : '');

    var actions = document.createElement('div');
    actions.className = 'toast-actions';

    if (status === 'pending') {
      var approve = document.createElement('button');
      approve.type = 'button';
      approve.className = 'toast-btn btn-approve';
      approve.textContent = 'Approve';
      approve.onclick = function () {
        updateRequestStatus(req.id, 'approved').then(function () {
          bar.classList.add('is-approved', 'is-actioned');
          info.textContent = 'Request Approved';
          loadMonth();
        }).catch(function (err) {
          window.alert('Approve failed: ' + (err && err.message ? err.message : err));
        });
      };

      var deny = document.createElement('button');
      deny.type = 'button';
      deny.className = 'toast-btn btn-deny';
      deny.textContent = 'Deny';
      deny.onclick = function () {
        updateRequestStatus(req.id, 'denied').then(function () {
          bar.classList.add('is-denied', 'is-actioned');
          info.textContent = 'Request Denied';
          loadMonth();
        }).catch(function (err) {
          window.alert('Deny failed: ' + (err && err.message ? err.message : err));
        });
      };

      actions.appendChild(approve);
      actions.appendChild(deny);
    } else if (status === 'approved' && canManage()) {
      var cancel = document.createElement('button');
      cancel.type = 'button';
      cancel.className = 'toast-btn btn-deny';
      cancel.textContent = 'Cancel';
      cancel.onclick = function () {
        openCancelConfirmModal(`Are you sure you want to cancel this approved time off for ${requestDisplayName(req)}?`, function () {
          updateRequestStatus(req.id, 'cancelled').then(function () {
            bar.classList.add('is-actioned');
            info.textContent = 'Request Canceled';
            loadMonth();
          }).catch(function (err) {
            window.alert('Cancel failed: ' + (err && err.message ? err.message : err));
          });
        });
      };
      actions.appendChild(cancel);
    }

    bar.appendChild(info);
    if (actions.children.length) bar.appendChild(actions);

    if (refs.queueList) {
      refs.queueList.appendChild(bar);
    }
  }

  function renderManagementQueue(y, m) {
    const pending = canManage()
      ? state.requests.filter((r) => requestIsPending(r))
      : [];
    updateApprovalTrigger(pending, `${MONTH_LABELS[m] || 'Current month'} ${y}`);
  }

  function renderMessageCenter(dayItems) {
    const notes = [];
    const dates = [];

    state.notes.forEach((note) => {
      const dt = parseDateValue(note.note_date || note.date || note.noteDate);
      const title = getDisplayName(note.description || note.text || note.title || note.message);
      if (dt) {
        notes.push({
          title: title || 'Calendar note',
          meta: formatDateParts(dt),
        });
      }
    });

    getImportantDateItems(state.year, state.month).forEach((entry) => {
      dates.push({
        title: entry.title || entry.text || 'Important date',
        meta: entry.day ? `${MONTH_LABELS[state.month]} ${entry.day}` : '',
        type: entry.type || 'holiday',
      });
    });

    getBuiltInHolidays(state.year).forEach((holiday) => {
      const dt = parseDateValue(holiday.date);
      if (!dt || !sameMonthYear(dt, state.year, state.month)) return;

      dates.push({
        title: holiday.name,
        meta: formatDateParts(dt),
      });
    });

    renderList(refs.notesList, notes.slice(0, 8), 'No notes for this month.', 'cal__list-item--note');
    renderList(refs.datesList, dates.slice(0, 8), 'No important dates for this month.', 'cal__list-item--date');
    renderManagementQueue(state.year, state.month);
  }

  function render() {
    if (!refs.grid || !refs.label) return;

    const year = state.year;
    const month = state.month;
    const monthLabel = MONTH_LABELS[month] || 'Month';
    refs.label.textContent = `${monthLabel} ${year}`;
    refs.grid.innerHTML = '';
    refs.grid.className = 'cal__grid';

    WEEKDAY_LABELS.forEach((dayName) => {
      const header = document.createElement('div');
      header.className = 'cal__weekday';
      header.textContent = dayName;
      refs.grid.appendChild(header);
    });

    const firstDay = new Date(year, month, 1);
    const firstWeekday = firstDay.getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const dayItems = buildDayItems(year, month);

    for (let i = 0; i < firstWeekday; i += 1) {
      const filler = document.createElement('div');
      filler.className = 'cal__cell cal__cell--empty';
      refs.grid.appendChild(filler);
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    for (let day = 1; day <= daysInMonth; day += 1) {
      const date = new Date(year, month, day);
      date.setHours(0, 0, 0, 0);
      const itemsForDay = dayItems[day] || [];
      const cellDateVal = formatDateForInput(date);

      const cell = document.createElement('div');
      cell.className = dayCellClasses(itemsForDay);
      if (date.getTime() === today.getTime()) {
        cell.classList.add('is-today');
      }

      if (refs.startInput && refs.endInput) {
        if (refs.startInput.value && refs.endInput.value) {
          if (cellDateVal >= refs.startInput.value && cellDateVal <= refs.endInput.value) {
            cell.classList.add('is-selected');
          }
        } else if (refs.startInput.value && cellDateVal === refs.startInput.value) {
          cell.classList.add('is-selected');
        }
      }

      const number = document.createElement('div');
      number.className = 'cal__day-num cal__cell-number';
      number.textContent = String(day);
      cell.appendChild(number);

      renderDayBadges(cell, itemsForDay);

      if (itemsForDay.length) {
        cell.title = itemsForDay.map((item) => item.title || item.text).join('\n');
      }

      cell.addEventListener('click', (event) => {
        event.preventDefault();

        // const pendingApprovals = getPendingApprovalItems(itemsForDay);
        // if (pendingApprovals.length) {
        //   hideDayModal();
        //   openApprovalDrawer(date, pendingApprovals);
        //   return;
        // }

        // Open appointment details even when the appointment is the only item
        // on the day.  The request note/comment is rendered in this modal;
        // previously single-item appointments fell through to date selection
        // and never exposed their details.
        const hasAppointment = itemsForDay.some((item) => item && item.type === 'timeoff');
        // if (itemsForDay.length > 1 || hasAppointment) {
        if (itemsForDay.length) {
          openDayModal(date, itemsForDay);
          return;
        }

        const selectedDate = new Date(year, month, day);
        selectedDate.setHours(0, 0, 0, 0);
        const selectedVal = formatDateForInput(selectedDate);

        if (refs.startInput && refs.endInput) {
          const currentStart = refs.startInput.value;
          const currentEnd = refs.endInput.value;

          if (!currentStart || currentStart > selectedVal || currentStart !== currentEnd) {
            refs.startInput.value = selectedVal;
            refs.endInput.value = selectedVal;
          } else {
            refs.endInput.value = selectedVal;
          }

          if (refs.endInput.value < refs.startInput.value) {
            refs.endInput.value = refs.startInput.value;
          }
        }

        render();

        if (refs.noteInput) {
          refs.noteInput.focus();
        }
      });

      refs.grid.appendChild(cell);
    }

    renderMessageCenter(dayItems);
  }

  function setLoading(isLoading) {
    state.loading = isLoading;
    if (refs.root) {
      refs.root.classList.toggle('is-loading', isLoading);
    }
  }

  async function loadMonth() {
    setLoading(true);
    const query = { year: state.year, month: state.month + 1 };

    const [requests, notes, dates, birthdays] = await Promise.all([
      fetchCollection(config.requestsEndpoint, query),
      fetchCollection(config.notesEndpoint, query),
      fetchCollection(config.datesEndpoint, query),
      fetchCollection(config.birthdaysEndpoint, query),
    ]);

    state.requests = requests;
    state.notes = notes;
    state.dates = dates;
    state.customDates = dates;
    state.birthdays = birthdays;

    render();
    setLoading(false);
  }

  function moveMonth(delta) {
    const next = new Date(state.year, state.month + delta, 1);
    state.year = next.getFullYear();
    state.month = next.getMonth();
    loadMonth();
  }

  function initManagerTools() {
    if (refs.mgrTools) refs.mgrTools.style.display = 'none';
    if (refs.addDateToggle) refs.addDateToggle.style.display = 'none';
    if (refs.addDatePanel) refs.addDatePanel.style.display = 'none';

    if (refs.addDateToggle && refs.addDatePanel) {
      refs.addDateToggle.addEventListener('click', () => {
        const isOpen = refs.addDatePanel.style.display === 'block';
        refs.addDatePanel.style.display = isOpen ? 'none' : 'block';
        if (!isOpen && !refs.addDatePanel.textContent.trim()) {
          refs.addDatePanel.innerHTML = '';
          const note = document.createElement('div');
          note.className = 'cal__empty';
          note.textContent = 'Date import tools are not configured in this build.';
          refs.addDatePanel.appendChild(note);
        }
      });
    }
  }

  function bindEvents() {
    if (refs.prev) {
      refs.prev.addEventListener('click', () => moveMonth(-1));
    }
    if (refs.next) {
      refs.next.addEventListener('click', () => moveMonth(1));
    }
    if (refs.submitButton) {
      refs.submitButton.addEventListener('click', () => {
        void handleSubmit();
      });
    }
    if (refs.emailSelect) {
      refs.emailSelect.addEventListener('change', () => {
        syncCustomEmailVisibility();
        if (refs.emailSelect.value === '__custom__' && refs.customEmailInput) {
          refs.customEmailInput.focus();
        }
      });
    }
    if (refs.dayModalClose) {
      refs.dayModalClose.addEventListener('click', hideDayModal);
    }
    if (refs.dayModal) {
      refs.dayModal.addEventListener('click', (event) => {
        if (event.target && event.target.matches('[data-cal-day-modal-close], .cal-modal__backdrop')) {
          hideDayModal();
        }
      });
    }
    if (refs.approvalDrawerClose) {
      refs.approvalDrawerClose.addEventListener('click', hideApprovalDrawer);
    }
    if (refs.approvalTrigger) {
      refs.approvalTrigger.addEventListener('click', () => {
        if (!canManage()) return;
        if (refs.approvalDrawer && refs.approvalDrawer.classList.contains('is-open')) {
          hideApprovalDrawer();
          return;
        }
        const pending = state.requests.filter((req) => requestIsPending(req));
        openApprovalDrawer(`${MONTH_LABELS[state.month] || 'Current month'} ${state.year}`, pending);
      });
    }
    if (refs.approvalDrawer) {
      refs.approvalDrawer.addEventListener('click', (event) => {
        if (event.target && event.target.matches('[data-cal-approval-drawer-close], .cal-drawer__backdrop')) {
          hideApprovalDrawer();
        }
      });
    }
    if (refs.cancelConfirmClose) {
      refs.cancelConfirmClose.addEventListener('click', hideCancelConfirmModal);
    }
    if (refs.cancelKeepButton) {
      refs.cancelKeepButton.addEventListener('click', hideCancelConfirmModal);
    }
    if (refs.cancelYesButton) {
      refs.cancelYesButton.addEventListener('click', confirmCancelAction);
    }
    if (refs.cancelConfirmModal) {
      refs.cancelConfirmModal.addEventListener('click', (event) => {
        if (event.target && event.target.matches('[data-cal-cancel-confirm-close], .cal-confirm-modal__backdrop')) {
          hideCancelConfirmModal();
        }
      });
    }
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        hideDayModal();
        hideApprovalDrawer();
        hideCancelConfirmModal();
      }
    });
  }

  function init() {
    if (!refs.root || !refs.grid || !refs.label) return;

    const now = new Date();
    state.year = now.getFullYear();
    state.month = now.getMonth();

    populateEmailSelect();
    bindEvents();
    initManagerTools();
    loadMonth();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
