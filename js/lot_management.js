// document.addEventListener('DOMContentLoaded', () => {
//   const modal = document.getElementById('globalLotModal');
//   if (!modal) return;

//   const closeButtons = modal.querySelectorAll('.lot__menu-close');
//   const lotForm = document.getElementById('lotManagerForm');
//   const lotsContainer = document.getElementById('lots-list-container');
//   const skuIdInput = document.getElementById('modal_sku_id');
//   const skuNumInput = document.getElementById('modal_sku_num');
//   const lotRequiredInput = document.getElementById('modal_lot_required');
//   const title = document.getElementById('lotModalTitle');

//   const base = (window.INVAPP_BASE || '').replace(/\/$/, '');
//   const handler = `${base}/ajax_lot_handler.php`;

//   const endpoints = {
//     list: `${handler}?action=fetch`,
//     create: `${handler}?action=create`,
//     update: `${handler}?action=update`,
//   };

//   const renderLotRow = (lot) => {
//     const row = document.createElement('div');
//     row.className = 'lot-row';
//     row.dataset.lotId = lot.id ? String(lot.id) : '';

//     const codeCell = document.createElement('span');
//     codeCell.textContent = lot.lot_code || '';

//     const expiryInput = document.createElement('input');
//     expiryInput.type = 'date';
//     expiryInput.className = 'input--small';
//     expiryInput.value = lot.expiry_date || '';

//     const onHandCell = document.createElement('span');
//     onHandCell.textContent = lot.on_hand != null ? String(lot.on_hand) : '0';

//     const actions = document.createElement('div');
//     actions.style.display = 'flex';
//     actions.style.gap = '8px';
//     actions.style.justifyContent = 'flex-end';

//     const saveButton = document.createElement('button');
//     saveButton.type = 'button';
//     saveButton.className = 'btn--save-lot';
//     saveButton.textContent = 'Save';

//     const deleteButton = document.createElement('button');
//     deleteButton.type = 'button';
//     deleteButton.className = 'btn--status';
//     deleteButton.textContent = 'Delete';

//     row.appendChild(codeCell);
//     row.appendChild(expiryInput);
//     row.appendChild(onHandCell);
//     actions.appendChild(saveButton);
//     actions.appendChild(deleteButton);
//     row.appendChild(actions);

//     return row;
//   };

//   const parseJson = async (response, label) => {
//     const text = await response.text();
//     try {
//       return JSON.parse(text);
//     } catch (err) {
//       console.error(`${label} response was not JSON:`, text);
//       throw err;
//     }
//   };

//   const loadLots = async (skuId) => {
//     if (!lotsContainer) return;
//     lotsContainer.innerHTML = '<div class="small muted">Loading lots...</div>';
//     const url = `${endpoints.list}&sku_id=${encodeURIComponent(skuId)}`;

//     try {
//       const response = await fetch(url);
//       if (!response.ok) throw new Error('Network error');
//       const data = await parseJson(response, 'Lot list');

//       if (!data || !data.ok) {
//         lotsContainer.innerHTML = '<div class="small" style="color:#b91c1c;">Failed to load lots.</div>';
//         return;
//       }

//       const items = Array.isArray(data.items) ? data.items : (Array.isArray(data) ? data : []);
//       lotsContainer.innerHTML = '';

//       if (items.length === 0) {
//         lotsContainer.innerHTML = '<div class="small muted">No lots found.</div>';
//         return;
//       }

//       items.forEach((lot) => {
//         lotsContainer.appendChild(renderLotRow(lot));
//       });
//     } catch (_) {
//       lotsContainer.innerHTML = '<div class="small" style="color:#b91c1c;">Failed to load lots.</div>';
//     }
//   };

//   const openModal = (skuId, skuNum, lotRequired) => {
//     if (skuIdInput) skuIdInput.value = skuId;
//     if (skuNumInput) skuNumInput.value = skuNum;
//     if (lotRequiredInput) lotRequiredInput.value = lotRequired ? '1' : '0';
//     if (title) title.textContent = `Manage Lots: ${skuNum}`;
//     modal.style.display = 'flex';
//     modal.setAttribute('aria-hidden', 'false');
//     if (lotForm) lotForm.reset();
//     loadLots(skuId);
//   };

//   const closeModal = () => {
//     modal.style.display = 'none';
//     modal.setAttribute('aria-hidden', 'true');
//     if (lotsContainer) lotsContainer.innerHTML = '';
//     if (lotForm) lotForm.reset();
//   };

//   document.addEventListener('click', (e) => {
//     const target = e.target;
//     if (!(target instanceof HTMLElement)) return;
//     if (!target.classList.contains('btn-lot')) return;

//     const skuId = target.getAttribute('data-sku-id') || '';
//     const skuNum = target.getAttribute('data-sku-num') || '';
//     const lotRequired = target.getAttribute('data-lot-required') === '1';
//     if (!skuId || !skuNum) return;
//     openModal(skuId, skuNum, lotRequired);
//   });

//   closeButtons.forEach((btn) => {
//     btn.addEventListener('click', closeModal);
//   });

//   if (lotForm) {
//     lotForm.addEventListener('submit', async (e) => {
//       e.preventDefault();

//       const skuId = skuIdInput ? skuIdInput.value : '';
//       const lotRequired = lotRequiredInput ? lotRequiredInput.value === '1' : false;
//       const lotCode = (lotForm.querySelector('[name="lot_code"]')?.value || '').trim();
//       const expiryDate = (lotForm.querySelector('[name="expiry_date"]')?.value || '').trim();

//       if (!lotCode || (lotRequired && !expiryDate)) {
//         alert('Lot code and expiry date are required for this SKU.');
//         return;
//       }

//       const formData = new FormData();
//       formData.append('sku_id', skuId);
//       formData.append('lot_code', lotCode);
//       formData.append('expiry_date', expiryDate);

//       try {
//         const response = await fetch(endpoints.create, {
//           method: 'POST',
//           body: formData,
//         });
//         const data = await parseJson(response, 'Lot create');
//         if (!data || !data.ok) {
//           alert(data && data.message ? data.message : 'Lot create failed.');
//           return;
//         }
//         lotForm.reset();
//         loadLots(skuId);
//       } catch (_) {
//         alert('Lot create failed.');
//       }
//     });
//   }

//   if (lotsContainer) {
//     lotsContainer.addEventListener('click', async (e) => {
//       const target = e.target;
//       if (!(target instanceof HTMLElement)) return;
//       const row = target.closest('.lot-row');
//       if (!row) return;

//       if (target.classList.contains('btn--status')) {
//         row.remove();
//         return;
//       }

//       if (target.classList.contains('btn--save-lot')) {
//         const lotId = row.dataset.lotId || '';
//         if (!lotId) {
//           alert('This lot has not been saved yet.');
//           return;
//         }

//         const expiryInput = row.querySelector('input[type="date"]');
//         const expiryDate = expiryInput ? expiryInput.value : '';

//         const formData = new FormData();
//         formData.append('lot_id', lotId);
//         formData.append('expiry_date', expiryDate);

//         try {
//           const response = await fetch(endpoints.update, {
//             method: 'POST',
//             body: formData,
//           });
//           const data = await parseJson(response, 'Lot update');
//           if (!data || !data.ok) {
//             alert(data && data.message ? data.message : 'Lot update failed.');
//             return;
//           }
//           alert(data.message || 'Lot updated.');
//         } catch (_) {
//           alert('Lot update failed.');
//         }
//       }
//     });
//   }

//   window.LotManager = {
//     init: () => {},
//     openModal,
//     fetchLots: loadLots,
//   };
// });
