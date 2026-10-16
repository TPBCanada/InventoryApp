document.addEventListener('DOMContentLoaded', function () {
 // FIX #1: Use DOMContentLoaded guard AND try to read from the global variable
 let boot = window.HISTORY_BOOT || null;
 if (!boot) {
  // Fallback: try reading directly from the JSON script tag
  const bootEl = document.getElementById('history-boot');
  if (bootEl) {
   try { boot = JSON.parse(bootEl.textContent); } catch (e) {
    console.error("Failed to parse history-boot data:", e);
   }
  }
 }

 if (!boot) {
   console.warn("HISTORY_BOOT data not found. Selectors will not initialize.");
   return; // Nothing to do
 }

 const rows    = boot.rows || [];
 const baysByRow = boot.baysByRow || {};
 const levelsByRow = boot.levelsByRow || {};
 const sidesByRow = boot.sidesByRow || {};
 const sel    = boot.currentSelection || {};

 // Location Selectors
 const $row = document.getElementById('row');
 const $bay = document.getElementById('bay');
 const $lvl = document.getElementById('lvl');
 const $side = document.getElementById('side');

 // Filter Elements
 // CORRECTED: Targeting the SKU input by its correct ID 'sku' from the HTML form.
 const $skuFilter = document.getElementById('sku'); 
 const $resetBtn = document.getElementById('resetBtn');

 if (!$row || !$bay || !$lvl || !$side) {
   console.warn("One or more location selectors not found in the DOM.");
   return;
 }

 /**
  * Populates a select element with options.
  * @param {HTMLSelectElement} el - The select element.
  * @param {Array<string>} items - The values to populate.
  * @param {string} value - The value to pre-select.
  */
 function fill(el, items, value, placeholder = '-- Select --'){
  el.innerHTML = `<option value="">${placeholder}</option>`; // Always start with blank option
  items.forEach(v=>{
   const opt = document.createElement('option');
   opt.value = v; opt.textContent = v;
   // Pre-select logic: check against the value passed from PHP
   if (String(v) === String(value)) opt.selected = true;
   el.appendChild(opt);
  });
  el.disabled = items.length === 0;
 }

 function onRow(){
  const r = $row.value;
  // Get bays for the selected row, sort numerically, and fill the bay selector
  const bays = (baysByRow[r] || []).slice().sort((a,b)=>Number(a)-Number(b));
  fill($bay, bays, sel.bay, 'Bay');
  // Continue the chain
  onBay();
 }

 function onBay(){
  const r = $row.value, b = $bay.value;
  const lvls = ((levelsByRow[r]||{})[b] || []);
  fill($lvl, lvls, sel.lvl, 'Level');
  // Continue the chain
  onLvl();
 }

 function onLvl(){
  const r = $row.value, b = $bay.value, l = $lvl.value;
  const sides = (((sidesByRow[r]||{})[b]||{})[l] || []);
  fill($side, sides, sel.side, 'Side');
 }

 // --- Initialization ---

 // 1. Prime the Row selector first
 fill($row, rows, sel.row, 'Row'); // Use 'Row' as the placeholder for the main selector

 // 2. Attach Event Listeners for cascading updates
 $row.addEventListener('change', onRow);
 $bay.addEventListener('change', onBay);
 $lvl.addEventListener('change', onLvl);

 // 3. Kick off the chain to restore the user's previous selection
 onRow();

 // --- Reset Button Logic ---
 if ($resetBtn) {
  $resetBtn.addEventListener('click', function(e) {
   e.preventDefault(); 
  
   // 1. Clear SKU Filter
   if ($skuFilter) {
    $skuFilter.value = '';
   }

   // 2. Clear Location Filters
   $row.value = '';
   // Visually clear dependent selectors by triggering onRow which resets the others
   onRow(); 

   // 3. Submit the Form to refresh the list with no filters
   const form = $resetBtn.closest('form');
   if (form) {
    // Use a slight delay to ensure the browser has updated the form values
    setTimeout(() => form.submit(), 0);
   }
  });
 }
});