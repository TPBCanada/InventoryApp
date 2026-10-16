// Dimension Modal Management
function openDimensionModal(barcodeId, skuId, uomLabel) {
  document.getElementById('dimBarcodeId').value = barcodeId || '';
  document.getElementById('dimSkuId').value = skuId;
  document.getElementById('dimUomLabel').value = uomLabel;
  document.getElementById('dimModalSku').textContent = uomLabel;

  fetch(window.INVAPP_BASE + '/utils/dimensions-window.php?sku_id=' + skuId).then(r => r.json()).then(data => {
    if (data.ok && data.dimensions[uomLabel]) {
      const d = data.dimensions[uomLabel];
      document.getElementById('dimWidth').value = d.width || '';
      document.getElementById('dimHeight').value = d.height || '';
      document.getElementById('dimLength').value = d.length || '';
      document.getElementById('dimWeight').value = d.weight_lbs || '';
    }
    document.getElementById('dimensionModal').style.display = 'flex';
  }).catch(err => alert('Error loading dimensions'));
}

function closeDimensionModal() {
  document.getElementById('dimensionModal').style.display = 'none';
  document.getElementById('dimensionForm').reset();
}

function initDimensionModal() {
  const form = document.getElementById('dimensionForm');
  if (!form) return;

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    const payload = {
      barcode_id: document.getElementById('dimBarcodeId').value ? parseInt(document.getElementById('dimBarcodeId').value) : null,
      sku_id: parseInt(document.getElementById('dimSkuId').value),
      uom_label: document.getElementById('dimUomLabel').value,
      width: parseFloat(document.getElementById('dimWidth').value) || null,
      height: parseFloat(document.getElementById('dimHeight').value) || null,
      length: parseFloat(document.getElementById('dimLength').value) || null,
      weight_lbs: parseFloat(document.getElementById('dimWeight').value) || null
    };
    fetch(window.INVAPP_BASE + '/utils/dimensions-window.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)})
      .then(r => r.json()).then(data => {
        if (data.ok) { closeDimensionModal(); alert('Saved ' + payload.uom_label); }
        else alert('Error: ' + (data.error || 'Failed'));
      }).catch(err => alert('Error saving'));
  });

  const modal = document.getElementById('dimensionModal');
  modal.addEventListener('click', function(e) {
    if (e.target === this) closeDimensionModal();
  });
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', initDimensionModal);
