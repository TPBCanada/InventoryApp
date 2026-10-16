// /inventory/js/verify_pick.js

// --- Configuration and State ---
const APP_BASE = (window.INVAPP_BASE || '/dev').replace(/\/+$/, '') + '/inventory';

// Global state object
window.VERIFY_PICK_STATE = {
    userId: null,       // Must be set via PHP, e.g., <script>window.VERIFY_PICK_STATE.userId = 123;</script>
    orderId: null,
    items: []           // The structured list of items (SKU, Qty) from OCR
};

// --- Element References ---
const orderScanDiv = document.getElementById('orderScanDiv');
const orderChecklistDiv = document.getElementById('orderChecklistDiv');
const scanButton = document.getElementById('scanButton');
const imageInput = document.getElementById('orderImageInput');
const checklistBody = document.getElementById('checklistBody');
const confirmButton = document.getElementById('confirmPickButton');

// --- Utility Functions ---

/** Displays a temporary toast message. */
function toast(message) {
    console.log('[TOAST]', message);
    // You would integrate this with your application's actual toast UI component here.
    // For now, we'll just log it.
    const feedback = document.getElementById('feedbackMessage'); 
    if (feedback) {
        feedback.textContent = message;
        feedback.style.display = 'block';
        setTimeout(() => feedback.style.display = 'none', 3000);
    }
}

/** Converts a File object to a Base64 string. */
function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result.split(',')[1]); // Only need the data part
        reader.onerror = error => reject(error);
        reader.readAsDataURL(file);
    });
}

/** Sends the image to the PHP backend for OCR processing. */
async function callOcrApi(base64Image) {
    toast('Processing image via OCR...');
    try {
        const response = await fetch(`${APP_BASE}/verify_pick.php?action=ocr`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                image_data: base64Image,
                user_id: window.VERIFY_PICK_STATE.userId
            })
        });

        if (!response.ok) {
            const errorText = await response.text();
            throw new Error(`Server returned status ${response.status}: ${errorText}`);
        }

        const data = await response.json();
        
        // --- SyntaxError: Unexpected token '<' Fix Guard ---
        // This is where the JS fails if PHP outputs HTML (or non-JSON text) first.
        // The check (if (!response.ok)) should catch most server errors.
        // If it still fails, the PHP output buffering fix is required.

        if (data.ok) {
            window.VERIFY_PICK_STATE.orderId = data.order_id;
            window.VERIFY_PICK_STATE.items = data.items;
            return data;
        } else {
            throw new Error(data.error || 'OCR lookup failed.');
        }

    } catch (e) {
        console.error('OCR API Call Error:', e);
        toast(`OCR Failed: ${e.message}`);
        return null;
    }
}

/** Dynamically generates and displays the interactive checklist. */
function renderChecklist(items, orderId) {
    if (!orderChecklistDiv || !checklistBody) return;

    orderChecklistDiv.querySelector('h2').textContent = `2. Order ${orderId || 'Checklist'}`;
    checklistBody.innerHTML = '';

    if (!items || items.length === 0) {
        checklistBody.innerHTML = '<tr><td colspan="4">No items found in the scanned order sheet.</td></tr>';
        return;
    }

    items.forEach((item, index) => {
        const tr = document.createElement('tr');
        tr.dataset.sku = item.sku;
        tr.dataset.requiredQty = item.quantity;

        tr.innerHTML = `
            <td>${item.sku}</td>
            <td>${item.description}</td>
            <td class="text-right">${item.quantity}</td>
            <td>
                <input type="checkbox" class="item-checkbox" data-index="${index}">
            </td>
        `;
        checklistBody.appendChild(tr);
    });

    orderScanDiv.style.display = 'none';
    orderChecklistDiv.style.display = 'block';
    toast('Checklist ready. Start picking.');
}

/** Handles the final submission of the confirmed pick list. */
async function confirmPickCompletion() {
    const checkedItems = [];
    const checkboxes = checklistBody.querySelectorAll('.item-checkbox:checked');

    if (checkboxes.length === 0) {
        toast('Please check at least one item to confirm.');
        return;
    }

    checkboxes.forEach(cb => {
        const index = cb.dataset.index;
        const originalItem = window.VERIFY_PICK_STATE.items[index];
        checkedItems.push({
            sku: originalItem.sku,
            quantity_picked: originalItem.quantity // Assume all required quantity was picked
        });
    });

    if (!window.VERIFY_PICK_STATE.orderId) {
        toast('Error: Order ID is missing.');
        return;
    }

    confirmButton.disabled = true;
    toast('Submitting pick confirmation...');

    try {
        const response = await fetch(`${APP_BASE}/verify_pick.php?action=confirm`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                order_id: window.VERIFY_PICK_STATE.orderId,
                user_id: window.VERIFY_PICK_STATE.userId,
                confirmed_items: checkedItems
            })
        });

        const data = await response.json();

        if (data.ok) {
            toast('✅ Pick confirmed successfully!');
            // Reset UI for next order
            setTimeout(() => window.location.reload(), 1500);
        } else {
            throw new Error(data.error || 'Confirmation failed.');
        }
    } catch (e) {
        console.error('Confirmation Error:', e);
        toast(`Confirmation Failed: ${e.message}`);
        confirmButton.disabled = false;
    }
}

// --- Event Listeners and Initialization ---

document.addEventListener('DOMContentLoaded', () => {
    // 1. Hook up the "Take Picture" button to the hidden file input
    if (scanButton && imageInput) {
        scanButton.addEventListener('click', () => {
            imageInput.click(); // This is the standard, reliable camera launch
        });
    }

    // 2. Handle the image file once it's captured
    if (imageInput) {
        imageInput.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) {
                toast('No image captured.');
                return;
            }

            scanButton.disabled = true;
            
            try {
                // Convert file to Base64 and call OCR API
                const base64Image = await fileToBase64(file);
                const ocrResult = await callOcrApi(base64Image);
                
                if (ocrResult) {
                    renderChecklist(ocrResult.items, ocrResult.order_id);
                }
            } finally {
                scanButton.disabled = false;
                // Clear the input value so 'change' event fires again if the user takes the same picture again
                imageInput.value = ''; 
            }
        });
    }

    // 3. Hook up the "Confirm" button
    if (confirmButton) {
        confirmButton.addEventListener('click', confirmPickCompletion);
    }
});