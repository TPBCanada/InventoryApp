// Simple renderer for scan results
(function renderScanResults(container, data) {
  if (!data || !Array.isArray(data.rows) || data.rows.length === 0) {
    container.innerHTML = '<div class="alert alert-warning mb-0">No matching inventory found for this SKU.</div>';
    return;
  }

  // Optional total for this SKU
  var total = 0;
  var firstSku = data.rows[0]?.sku_num || '';
  if (data.totals && data.totals[firstSku]) {
    total = data.totals[firstSku].sum || 0;
  }

  var rowsHtml = data.rows.map(function(r) {
    var locCode = [r.row_code, r.bay_num, r.level_code, r.side].join('-');
    var moved   = r.last_moved_at ? r.last_moved_at : '—';
    // The loc_id is needed for the removal action
    var locId   = r.loc_id;
    var skuNum  = r.sku_num;

    return `
      <tr data-loc-id="${locId}" data-sku="${skuNum}" data-on-hand="${r.on_hand}" class="scan-result-row">
        <td>${skuNum}</td>
        <td>${r.sku_desc}</td>
        <td>${locCode}</td>
        <td class="text-end">${r.on_hand}</td>
        <td>${moved}</td>
        <td class="text-center">
          <a href="#" class="btn btn-sm btn-outline-danger remove-single-item"
             data-loc-id="${locId}" data-sku="${skuNum}" data-quantity="${r.on_hand}"
             title="Remove all ${r.on_hand} from this location">
             Remove All
          </a>
        </td>
      </tr>
    `;
  }).join('');

  container.innerHTML = `
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Scan Results</span>
        <small class="text-muted">Path: ${data.path}${total ? ` • Total on hand: ${total}` : ''}</small>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>SKU</th>
              <th>Description</th>
              <th>Location</th>
              <th class="text-end">On-Hand</th>
              <th>Last Movement</th>
              <th class="text-center">Action</th>
            </tr>
          </thead>
          <tbody>${rowsHtml}</tbody>
        </table>
      </div>
    </div>
  `;

  // Optional: clicking a result can set the quantity’s max or just focus the qty input
  container.querySelectorAll('tbody tr').forEach(function(tr){
    tr.addEventListener('click', function(){
      // Set the main removal form's SKU and Quantity inputs
      var skuInput = document.getElementById('sku_num');
      var qtyInput = document.getElementById('rmQuantity'); // Assuming rmQuantity is the ID for the main Quantity input in the remove form

      if (skuInput) {
        skuInput.value = tr.getAttribute('data-sku');
      }
      
      // OPTIONAL: set the quantity to the on-hand amount
      // if (qtyInput) {
      //   qtyInput.value = tr.getAttribute('data-on-hand'); 
      // }
      
      if (qtyInput) qtyInput.focus();
    });
  });

  // NEW: Add event listeners for the 'Remove All' buttons
  container.querySelectorAll('.remove-single-item').forEach(function(btn){
    btn.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation(); // Stop the click from bubbling up to the TR click event
      
      // **Logic to execute a removal action for this specific row**
      // You would typically use fetch() here to call a server-side script.
      var locId = btn.getAttribute('data-loc-id');
      var skuNum = btn.getAttribute('data-sku');
      var quantity = btn.getAttribute('data-quantity');

      if (confirm(`Are you sure you want to remove all ${quantity} of SKU ${skuNum} from location ${locId}?`)) {
        // Example server call (you'll need to implement this endpoint):
        // fetch(`take.php?action=remove_sku&loc_id=${locId}&sku_num=${skuNum}&quantity=${quantity}`, { method: 'POST' })
        //   .then(...)
        //   .catch(...)
        alert(`Simulated Removal: Removing ${quantity} of ${skuNum} from ${locId}`);
      }
    });
  });
})(document.getElementById('scan-results'), { /* ...data... */ }); // The self-executing function now takes the results element