(function () {
  var data = window.INSPECTION_DASHBOARD || {};
  if (!data.schema_ready) {
    return;
  }

  var hasChart = typeof window.Chart !== 'undefined';
  var byTemplate = Array.isArray(data.by_template) ? data.by_template : [];
  var templateLabels = byTemplate.map(function (row) { return row.name; });

  var checklistCtx = document.getElementById('inspectionsByChecklistChart');
  if (checklistCtx && hasChart) {
    new Chart(checklistCtx, {
      type: 'bar',
      data: {
        labels: templateLabels,
        datasets: [{
          label: 'Inspections',
          data: byTemplate.map(function (row) { return row.inspections; }),
          backgroundColor: '#0ea5e9',
          borderRadius: 6,
          maxBarThickness: 48
        }]
      },
      options: {
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
          x: { ticks: { maxRotation: 0, minRotation: 0 } }
        }
      }
    });
  }

  var issueShareCtx = document.getElementById('issueShareChart');
  if (issueShareCtx && hasChart) {
    new Chart(issueShareCtx, {
      type: 'doughnut',
      data: {
        labels: templateLabels,
        datasets: [{
          label: 'Open Issues',
          data: byTemplate.map(function (row) { return row.issues; }),
          backgroundColor: ['#ef4444', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7', '#14b8a6']
        }]
      },
      options: { maintainAspectRatio: false }
    });
  }

  var trendRows = Array.isArray(data.daily_pass_rate) ? data.daily_pass_rate : [];
  var trendCtx = document.getElementById('passRateTrendChart');
  if (trendCtx && hasChart) {
    new Chart(trendCtx, {
      type: 'line',
      data: {
        labels: trendRows.map(function (row) { return row.day; }),
        datasets: [{
          label: 'Pass Rate %',
          data: trendRows.map(function (row) { return row.pass_rate_pct; }),
          borderColor: '#22c55e',
          backgroundColor: 'rgba(34, 197, 94, 0.15)',
          fill: true,
          pointRadius: 3,
          tension: 0.25,
          yAxisID: 'yRate'
        }, {
          label: 'Inspections',
          data: trendRows.map(function (row) { return row.inspections; }),
          borderColor: '#0ea5e9',
          backgroundColor: 'rgba(14, 165, 233, 0.12)',
          fill: false,
          pointRadius: 2,
          tension: 0.2,
          yAxisID: 'yCount'
        }]
      },
      options: {
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          yRate: { type: 'linear', position: 'left', beginAtZero: true, max: 100, title: { display: true, text: 'Pass Rate (%)' } },
          yCount: { type: 'linear', position: 'right', beginAtZero: true, ticks: { precision: 0 }, grid: { drawOnChartArea: false }, title: { display: true, text: 'Inspections' } }
        }
      }
    });
  }

  var matrixRows = Array.isArray(data.category_matrix) ? data.category_matrix : [];
  var matrixCtx = document.getElementById('issueMatrixChart');
  if (matrixCtx && matrixRows.length && hasChart) {
    var xLabels = [];
    var yLabels = [];
    matrixRows.forEach(function (row) {
      if (xLabels.indexOf(row.checklist) === -1) xLabels.push(row.checklist);
      if (yLabels.indexOf(row.category) === -1) yLabels.push(row.category);
    });

    var maxIssueValue = 0;
    var matrixData = matrixRows.map(function (row) {
      if (row.issues > maxIssueValue) maxIssueValue = row.issues;
      return { x: row.checklist, y: row.category, v: row.issues };
    });

    try {
      new Chart(matrixCtx, {
        type: 'matrix',
        data: {
          datasets: [{
            label: 'Issue Count',
            data: matrixData,
            backgroundColor: function (ctx) {
              var value = (ctx.raw && ctx.raw.v) || 0;
              var alpha = maxIssueValue > 0 ? (0.12 + (value / maxIssueValue) * 0.78) : 0.12;
              return 'rgba(239, 68, 68, ' + alpha.toFixed(2) + ')';
            },
            borderColor: 'rgba(15, 23, 42, 0.18)',
            borderWidth: 1,
            width: function (ctx) {
              var area = ctx.chart.chartArea;
              return area ? Math.max(22, (area.width / Math.max(xLabels.length, 1)) - 6) : 24;
            },
            height: function (ctx) {
              var area = ctx.chart.chartArea;
              return area ? Math.max(18, (area.height / Math.max(yLabels.length, 1)) - 6) : 20;
            }
          }]
        },
        options: {
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            x: { type: 'category', labels: xLabels, offset: true, grid: { display: false } },
            y: { type: 'category', labels: yLabels, offset: true, reverse: true, grid: { display: false } }
          }
        }
      });
    } catch (error) {
      console.warn('Matrix chart unavailable:', error);
    }
  }

  var dz = data.damage_zone || {};
  var mapWrap = document.getElementById('damageMapWrap');
  var mapImg = document.getElementById('damageMapImage');
  var mapCanvas = document.getElementById('damageMapCanvas');
  var pendingMarker = document.getElementById('damagePendingMarker');
  var form = document.getElementById('damageZoneForm');
  var formActionInput = form ? form.querySelector('input[name="action"]') : null;
  var saveBtn = form ? form.querySelector('button[type="submit"]') : null;
  var xInput = document.getElementById('damageXInput');
  var yInput = document.getElementById('damageYInput');
  var statusEl = document.getElementById('damageZoneStatus');
  var table = document.getElementById('damageZoneTable');
  var filterMarkerType = document.getElementById('zoneFilterMarkerType');
  var filterSeverity = document.getElementById('zoneFilterSeverity');
  var filterFrom = document.getElementById('zoneFilterFrom');
  var filterTo = document.getElementById('zoneFilterTo');
  var filterSummary = document.getElementById('damageFilterSummary');
  var historyDialog = document.getElementById('damageHistoryDialog');
  var historyBody = document.getElementById('damageHistoryBody');
  var panelTitle = document.getElementById('damageZonePanelTitle');
  var panelMeta = document.getElementById('damageZoneMeta');
  var cancelBtn = document.getElementById('damageZoneCancel');
  var clearBtn = document.getElementById('damageZoneClear');

  if (!mapWrap || !mapImg || !mapCanvas || !form || !xInput || !yInput) {
    return;
  }

  var zones = Array.isArray(dz.zones) ? dz.zones.slice() : [];
  var editZoneId = null;

  function normalizeMarkerType(raw) {
    var t = String(raw || '').toLowerCase();
    if (t === 'damage' || t === 'caution') return 'advisory';
    if (t === 'fire_extinguisher' || t === 'emergency_exit' || t === 'advisory') return t;
    return 'advisory';
  }

  function resizeCanvas() {
    var rect = mapImg.getBoundingClientRect();
    mapCanvas.width = Math.max(1, Math.floor(rect.width));
    mapCanvas.height = Math.max(1, Math.floor(rect.height));
    drawZones();
  }

  function getFilteredZones() {
    return zones.filter(function (z) {
      if (Number(z.is_active || 1) !== 1) {
        return false;
      }
      var zMarkerType = normalizeMarkerType(z.marker_type);
      var zSeverity = String(z.severity || '').trim().toLowerCase();
      var zDate = String(z.created_at || '').slice(0, 10);

      if (filterMarkerType && filterMarkerType.value && zMarkerType !== filterMarkerType.value) {
        return false;
      }
      if (filterSeverity && filterSeverity.value && zSeverity !== filterSeverity.value) {
        return false;
      }
      if (filterFrom && filterFrom.value && zDate < filterFrom.value) {
        return false;
      }
      if (filterTo && filterTo.value && zDate > filterTo.value) {
        return false;
      }
      return true;
    });
  }

  function drawZones() {
    var ctx = mapCanvas.getContext('2d');
    var w = mapCanvas.width;
    var h = mapCanvas.height;
    ctx.clearRect(0, 0, w, h);
    var activeZones = getFilteredZones();

    activeZones.forEach(function (z) {
      var x = (Number(z.x_pct) / 100) * w;
      var y = (Number(z.y_pct) / 100) * h;
      var markerType = normalizeMarkerType(z.marker_type);
      if (markerType === 'fire_extinguisher') {
        ctx.fillStyle = 'rgba(239,68,68,0.95)';
        ctx.strokeStyle = 'rgba(15,23,42,0.65)';
        ctx.lineWidth = 1;
        ctx.fillRect(x - 7, y - 7, 14, 14);
        ctx.strokeRect(x - 7, y - 7, 14, 14);
        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 9px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText('F', x, y);
      } else if (markerType === 'emergency_exit') {
        ctx.fillStyle = 'rgba(34,197,94,0.95)';
        ctx.strokeStyle = 'rgba(15,23,42,0.65)';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(x, y - 8);
        ctx.lineTo(x + 8, y + 7);
        ctx.lineTo(x - 8, y + 7);
        ctx.closePath();
        ctx.fill();
        ctx.stroke();
      } else if (markerType === 'advisory') {
        ctx.fillStyle = 'rgba(245, 158, 11, 0.98)';
        ctx.strokeStyle = 'rgba(15,23,42,0.68)';
        ctx.lineWidth = 1;
        var r = 8;
        var step = Math.PI / 3;
        ctx.beginPath();
        for (var i = 0; i < 6; i++) {
          var px = x + r * Math.cos(step * i);
          var py = y + r * Math.sin(step * i);
          if (i === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
        }
        ctx.closePath();
        ctx.fill();
        ctx.stroke();
      }
    });
  }

  function setPendingFromEvent(evt) {
    var rect = mapCanvas.getBoundingClientRect();
    var x = evt.clientX - rect.left;
    var y = evt.clientY - rect.top;
    var xPct = Math.max(0, Math.min(100, (x / rect.width) * 100));
    var yPct = Math.max(0, Math.min(100, (y / rect.height) * 100));

    xInput.value = xPct.toFixed(3);
    yInput.value = yPct.toFixed(3);
    pendingMarker.style.left = xPct + '%';
    pendingMarker.style.top = yPct + '%';
    pendingMarker.hidden = false;
    if (statusEl) statusEl.textContent = 'Zone selected at ' + xPct.toFixed(1) + '%, ' + yPct.toFixed(1) + '%. Save to record it.';
  }

  function escapeHtml(text) {
    return String(text || '').replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
    });
  }

  function renderZoneTable() {
    if (!table) return;
    var tbody = table.querySelector('tbody');
    if (!tbody) return;
    var activeZones = getFilteredZones();
    var rows = activeZones.slice(0, 12).map(function (z) {
      var when = escapeHtml(z.created_at || '');
      var typeRaw = normalizeMarkerType(z.marker_type);
      var type = escapeHtml(typeRaw.replace(/_/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); }));
      var severity = escapeHtml((z.severity || 'medium').charAt(0).toUpperCase() + (z.severity || 'medium').slice(1));
      var coords = Number(z.x_pct).toFixed(1) + '%, ' + Number(z.y_pct).toFixed(1) + '%';
      return '<tr><td>' + when + '</td><td>' + type + '</td><td>' + severity + '</td><td>' + escapeHtml(coords) + '</td><td class="zone-actions"><button type="button" class="btn secondary btn-zone-edit" data-zone-id="' + Number(z.id) + '">Edit</button> <button type="button" class="btn secondary btn-zone-clear" data-zone-id="' + Number(z.id) + '">Clear</button> <button type="button" class="btn secondary btn-zone-history" data-zone-id="' + Number(z.id) + '">History</button></td></tr>';
    });
    if (!rows.length) {
      rows = ['<tr><td colspan="5">No zones reported yet.</td></tr>'];
    }
    tbody.innerHTML = rows.join('');
    if (filterSummary) {
      filterSummary.textContent = 'Showing ' + activeZones.length + ' of ' + zones.length + ' zone(s).';
    }
  }

  function enterEditMode(zone) {
    if (!zone) return;
    editZoneId = Number(zone.id);
    var markerType = document.getElementById('damageMarkerType');
    var severity = document.getElementById('damageSeverity');
    var notes = document.getElementById('damageNotes');
    if (markerType) markerType.value = normalizeMarkerType(zone.marker_type);
    if (severity) severity.value = String(zone.severity || 'medium').toLowerCase();
    if (notes) notes.value = String(zone.notes || '');
    xInput.value = Number(zone.x_pct || 0).toFixed(3);
    yInput.value = Number(zone.y_pct || 0).toFixed(3);
    pendingMarker.style.left = Number(zone.x_pct || 0) + '%';
    pendingMarker.style.top = Number(zone.y_pct || 0) + '%';
    pendingMarker.hidden = false;
    if (saveBtn) saveBtn.textContent = 'Save Changes';
    if (panelTitle) panelTitle.textContent = 'Map Item #' + editZoneId;
    if (panelMeta) {
      panelMeta.textContent = 'Reported by ' + (zone.created_by || 'Unknown') + ' on ' + (zone.created_at || 'unknown date');
      panelMeta.hidden = false;
    }
    if (cancelBtn) cancelBtn.hidden = false;
    if (clearBtn) clearBtn.hidden = false;
    if (statusEl) statusEl.textContent = 'Click the map to move this item, then save changes.';
  }

  function exitEditMode() {
    editZoneId = null;
    if (saveBtn) saveBtn.textContent = 'Save Zone';
    if (panelTitle) panelTitle.textContent = 'Add Map Item';
    if (panelMeta) panelMeta.hidden = true;
    if (cancelBtn) cancelBtn.hidden = true;
    if (clearBtn) clearBtn.hidden = true;
  }

  function resetForm(message) {
    pendingMarker.hidden = true;
    xInput.value = '';
    yInput.value = '';
    form.reset();
    if (formActionInput) formActionInput.value = 'save_damage_zone';
    exitEditMode();
    var markerType = document.getElementById('damageMarkerType');
    if (markerType) markerType.value = 'advisory';
    var severity = document.getElementById('damageSeverity');
    if (severity) severity.value = 'medium';
    if (statusEl) statusEl.textContent = message;
  }

  // Returns the visible marker under the click, if any (within ~12px).
  function zoneAtEvent(evt) {
    var rect = mapCanvas.getBoundingClientRect();
    var x = evt.clientX - rect.left;
    var y = evt.clientY - rect.top;
    var hit = null;
    var best = 12 * 12;
    getFilteredZones().forEach(function (z) {
      var dx = (Number(z.x_pct) / 100) * rect.width - x;
      var dy = (Number(z.y_pct) / 100) * rect.height - y;
      var d = dx * dx + dy * dy;
      if (d <= best) {
        best = d;
        hit = z;
      }
    });
    return hit;
  }

  function clearZone(zoneId) {
    var fd = new FormData();
    fd.set('action', 'clear_damage_zone');
    fd.set('zone_id', String(zoneId));
    fd.set('reason', 'Cleared on map');
    fd.set('csrf_token', String(dz.csrf_token || data.csrf_token || ''));
    fetch(dz.save_url || window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        if (!res || !res.ok) throw new Error((res && res.error) || 'Failed to clear zone');
        zones = zones.map(function (z) { return Number(z.id) === zoneId ? Object.assign({}, z, { is_active: 0, last_event_type: 'cleared' }) : z; });
        drawZones();
        renderZoneTable();
        if (editZoneId === zoneId) {
          resetForm('Zone cleared from active map.');
        } else if (statusEl) {
          statusEl.textContent = 'Zone cleared from active map.';
        }
      })
      .catch(function (err) {
        if (statusEl) statusEl.textContent = err.message || 'Unable to clear zone';
      });
  }

  function renderHistory(events) {
    if (!historyBody) return;
    if (!events || !events.length) {
      historyBody.innerHTML = '<p class="muted">No history events.</p>';
      return;
    }
    var html = events.map(function (e) {
      var when = escapeHtml(e.created_at || '');
      var who = escapeHtml(e.created_by || '');
      var eventType = escapeHtml(String(e.event_type || '').replace(/_/g, ' ').toUpperCase());
      var payload = escapeHtml(JSON.stringify(e.payload || {}));
      return '<article class="history-item"><div><strong>' + eventType + '</strong> <span class="muted">by ' + who + ' at ' + when + '</span></div><pre>' + payload + '</pre></article>';
    }).join('');
    historyBody.innerHTML = html;
  }

  mapCanvas.addEventListener('click', function (evt) {
    var hit = zoneAtEvent(evt);
    if (hit && Number(hit.id) !== editZoneId) {
      enterEditMode(hit);
      return;
    }
    setPendingFromEvent(evt);
  });
  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      resetForm('Click a marker to view it, or click an empty spot to add one.');
    });
  }
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      if (editZoneId) clearZone(editZoneId);
    });
  }
  [filterMarkerType, filterSeverity, filterFrom, filterTo].forEach(function (el) {
    if (!el) return;
    el.addEventListener('change', function () {
      drawZones();
      renderZoneTable();
    });
  });
  window.addEventListener('resize', resizeCanvas);
  if (mapImg.complete) {
    resizeCanvas();
  } else {
    mapImg.addEventListener('load', resizeCanvas, { once: true });
  }

  form.addEventListener('submit', function (evt) {
    evt.preventDefault();
    if (!xInput.value || !yInput.value) {
      if (statusEl) statusEl.textContent = 'Select a zone on the map before saving.';
      return;
    }

    var fd = new FormData(form);
    if (editZoneId) {
      fd.set('action', 'update_damage_zone');
      fd.set('zone_id', String(editZoneId));
    } else {
      fd.set('action', 'save_damage_zone');
    }
    var saveUrl = dz.save_url || window.location.pathname;

    fetch(saveUrl, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    })
      .then(function (res) { return res.json(); })
      .then(function (res) {
        var wasEditing = editZoneId !== null;
        if (!res || !res.ok) {
          throw new Error((res && res.error) || 'Failed to save zone');
        }
        if (res.zone) {
          if (wasEditing) {
            zones = zones.map(function (z) { return Number(z.id) === Number(res.zone.id) ? Object.assign({}, z, res.zone, { is_active: 1 }) : z; });
          } else {
            zones.unshift(Object.assign({}, res.zone, { is_active: 1 }));
          }
          drawZones();
          renderZoneTable();
        }
        resetForm(wasEditing ? 'Zone updated.' : 'Zone saved.');
      })
      .catch(function (err) {
        if (statusEl) statusEl.textContent = err.message || 'Unable to save zone';
      });
  });

  if (table) {
    table.addEventListener('click', function (evt) {
      var t = evt.target;
      if (!(t instanceof HTMLElement)) return;
      var idAttr = t.getAttribute('data-zone-id');
      var zoneId = idAttr ? Number(idAttr) : 0;
      if (!zoneId) return;
      var zone = zones.find(function (z) { return Number(z.id) === zoneId; });
      if (!zone) return;

      if (t.classList.contains('btn-zone-edit')) {
        enterEditMode(zone);
        return;
      }

      if (t.classList.contains('btn-zone-clear')) {
        clearZone(zoneId);
        return;
      }

      if (t.classList.contains('btn-zone-history')) {
        var fd2 = new FormData();
        fd2.set('action', 'get_damage_zone_events');
        fd2.set('zone_id', String(zoneId));
        fd2.set('csrf_token', String(dz.csrf_token || data.csrf_token || ''));
        fetch(dz.save_url || window.location.pathname, { method: 'POST', body: fd2, credentials: 'same-origin' })
          .then(function (res) { return res.json(); })
          .then(function (res) {
            if (!res || !res.ok) throw new Error((res && res.error) || 'Failed to load history');
            renderHistory(res.events || []);
            if (historyDialog && typeof historyDialog.showModal === 'function') {
              historyDialog.showModal();
            }
          })
          .catch(function (err) {
            if (statusEl) statusEl.textContent = err.message || 'Unable to load history';
          });
      }
    });
  }

  renderZoneTable();
})();
