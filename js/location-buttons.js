// js/location-buttons.js  (PURE JS — no PHP/HTML)
(function () {
  function init() {
    if (!Array.isArray(window.PLACE_LOCATIONS)) return;

    // Inject minimal styling for selected buttons (white fill + green border)
    (function ensureLocBtnStyles() {
      const id = "loc-btn-style";
      if (document.getElementById(id)) return;
      const css = `
        .loc-btn { border:1px solid transparent; }
        .loc-btn.is-selected {
          background:#fff !important;
          color:#1b7f5f !important;
          border:2px solid #1b7f5f !important;
          box-shadow:none !important;
        }
      `;
      const styleEl = document.createElement("style");
      styleEl.id = id;
      styleEl.textContent = css;
      document.head.appendChild(styleEl);
    })();

    // Keep only active locations; tolerate different flag shapes (bool/int/string)
    const LOCS = window.PLACE_LOCATIONS.filter((l) => {
      const flag = l?.is_disabled ?? l?.disabled ?? 0;
      return String(flag) !== "1" && flag !== true;
    });
    const rowWrap   = document.getElementById("rowButtons");
    const bayWrap   = document.getElementById("bayButtons");
    const levelWrap = document.getElementById("levelButtons");
    const sideWrap  = document.getElementById("sideButtons");

    const inRow   = document.getElementById("row_code_input");
    const inBay   = document.getElementById("bay_num_input");
    const inLevel = document.getElementById("level_code_input");
    const inSide  = document.getElementById("side_input");

    if (!rowWrap || !bayWrap || !levelWrap || !sideWrap) return;

    const uniq = (arr) => [...new Set(arr)];
    const sortAlpha = (a, b) => String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: "base" });
    const sortNumeric = (arr) => arr.sort((a, b) => {
      const na = Number(a), nb = Number(b);
      const bothNums = !Number.isNaN(na) && !Number.isNaN(nb);
      return bothNums ? na - nb : sortAlpha(a, b);
    });
    const mkBtn = (label, value, onClick, disabled = false) => {
      const b = document.createElement("button");
      b.type = "button";
      b.textContent = label;
      b.dataset.value = String(value);
      b.className = disabled ? "btn btn--ghost loc-btn" : "btn btn--primary loc-btn";
      b.disabled = !!disabled;
      if (!disabled) b.addEventListener("click", onClick);
      return b;
    };
    const selectToggle = (wrap, value) => {
      [...wrap.querySelectorAll("button")].forEach((b) => {
        const selected = b.dataset.value === String(value);
        b.classList.toggle("btn--ghost", selected);
        b.classList.toggle("btn--primary", !selected);
        b.classList.toggle("is-selected", selected);
        b.setAttribute("aria-pressed", selected ? "true" : "false");
        if (selected) b.setAttribute("aria-current", "true"); else b.removeAttribute("aria-current");
      });
    };

    const state = { row: null, bay: null, level: null, side: null };

    function setHidden() {
      if (inRow)   inRow.value   = state.row   ?? "";
      if (inBay)   inBay.value   = state.bay   ?? "";
      if (inLevel) inLevel.value = state.level ?? "";
      if (inSide)  inSide.value  = state.side  ?? "";
    }
    function updatePath() {
      const el = document.getElementById("selPath");
      if (!el) return;
      const parts = [state.row, state.bay, state.level, state.side].filter(Boolean);
      el.textContent = parts.length ? parts.join(" / ") : "—";
    }

    const rows   = uniq(LOCS.map((l) => String(l.row_code))).sort(sortAlpha);
    const bays   = sortNumeric(uniq(LOCS.map((l) => String(l.bay_num))));
    const levels = sortNumeric(uniq(LOCS.map((l) => String(l.level_code))));
    const sides  = uniq(LOCS.map((l) => String(l.side))).sort(sortAlpha);

    rows.forEach((v)   => rowWrap.appendChild(mkBtn(v, v, () => select("row", v))));
    bays.forEach((v)   => bayWrap.appendChild(mkBtn(v, v, () => select("bay", v))));
    levels.forEach((v) => levelWrap.appendChild(mkBtn(v, v, () => select("level", v))));
    sides.forEach((v)  => sideWrap.appendChild(mkBtn(v, v, () => select("side", v))));

    function toggleGroup(wrap, validSet) {
      [...wrap.querySelectorAll("button")].forEach((b) => {
        const enable = validSet.has(b.dataset.value);
        b.disabled = !enable;
        b.title = enable ? "" : "Not available for current selection";
      });
    }

    function updateAvailability() {
      const valid = { row:new Set(), bay:new Set(), level:new Set(), side:new Set() };

      LOCS.forEach((l) => {
        const R = String(l.row_code), B = String(l.bay_num), L = String(l.level_code), S = String(l.side);
        const ok =
          (!state.row   || state.row   === R) &&
          (!state.bay   || state.bay   === B) &&
          (!state.level || state.level === L) &&
          (!state.side  || state.side  === S);
        if (ok) { valid.row.add(R); valid.bay.add(B); valid.level.add(L); valid.side.add(S); }
      });

      toggleGroup(rowWrap,   valid.row);
      toggleGroup(bayWrap,   valid.bay);
      toggleGroup(levelWrap, valid.level);
      toggleGroup(sideWrap,  valid.side);

      if (state.row && typeof window.filterSides === "function") {
        const filtered = window.filterSides({ row: state.row, bay: state.bay, level: state.level, sides: [...valid.side] }) || [...valid.side];
        const filteredSet = new Set(filtered.map(String));
        [...sideWrap.querySelectorAll("button")].forEach((b) => {
          const allow = filteredSet.has(b.dataset.value);
          b.disabled = b.disabled || !allow;
          if (!allow && state.side === b.dataset.value) {
            state.side = null;
            selectToggle(sideWrap, null);
            setHidden();
          }
        });
      }
    }

    function select(group, value) {
      state[group] = String(value);
      if (group === "row")  { state.bay = state.level = state.side = null; }
      if (group === "bay")  { state.level = state.side = null; }
      if (group === "level"){ state.side = null; }

      selectToggle(rowWrap,   state.row);
      selectToggle(bayWrap,   state.bay);
      selectToggle(levelWrap, state.level);
      selectToggle(sideWrap,  state.side);

      setHidden(); updatePath(); updateAvailability();
    }

    const clearBtn = document.getElementById("clearSel");
    if (clearBtn) {
      clearBtn.addEventListener("click", () => {
        state.row = state.bay = state.level = state.side = null;
        selectToggle(rowWrap, null);
        selectToggle(bayWrap, null);
        selectToggle(levelWrap, null);
        selectToggle(sideWrap, null);
        setHidden(); updatePath(); updateAvailability();
      });
    }

    setHidden(); updatePath(); updateAvailability();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init, { once: true });
  } else {
    init();
  }
})();
