<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php'; // Required for h() helper

if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}

$title = 'Print Barcodes';
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$css = ['/refactor_css/dashboard.css', '/refactor_css/print.css']; // Use dashboard style plus a special print CSS file
$page_class = "page-print-barcodes";

// --- Access control check (assuming 'inventory/print_barcodes.php' is in access_control.php) ---
$page_key = 'inventory/print_barcodes.php';
$role_id = $_SESSION['role_id'] ?? 0;
// Note: If you want to use this page, ensure this key is added to $page_permissions_by_role
// if (!in_array($role_id, $page_permissions_by_role[$page_key] ?? [], true)) { ... }
// -------------------------------------------------------------------------------------------------

// SKU Data structure for the frontend
$skus_to_print = [];
$message = '';
$message_type = '';

// Handle SKU lookup/search (GET request)
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    // This logic mimics the sku search functionality from manage_product.php
    $sql = "
        SELECT sku_num, upc, `desc`
        FROM sku
        WHERE sku_num = ?
        OR upc = ?
        LIMIT 1
    ";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $q, $q);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            // Add the found item to the print queue (default quantity 1)
            $skus_to_print[] = [
                'sku_num' => (string) $row['sku_num'],
                'upc' => (string) ($row['upc'] ?? $row['sku_num']), // Use UPC if exists, otherwise SKU
                'desc' => (string) ($row['desc'] ?? ''),
                'quantity' => 1,
                'id' => uniqid(), // Unique ID for keying in JS
            ];
            $message = "Product found and added to print queue. Adjust quantity if needed.";
            $message_type = "success";
        } else {
            $message = "No product found matching SKU or UPC '{$q}'.";
            $message_type = "warning";
        }
        $stmt->close();

    } catch (\mysqli_sql_exception $e) {
        $message = "Database error: Could not fetch product details.";
        $message_type = "error";
    }
}

ob_start();
?>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>

<div class="print-container no-print" style="max-width: 1200px; margin: 0 auto; padding: 20px;">
    <h1 class="title" style="margin-bottom: 20px;">Barcode Label Printing</h1>
    <p class="small text-muted" style="margin-bottom: 20px;">
        Use this tool to generate and print labels for inventory items. We recommend using Code 128 for general inventory.
    </p>

    <?php if ($message): ?>
        <?php
        $cls = 'message';
        if ($message_type === 'success') $cls = 'message message--good';
        elseif ($message_type === 'error') $cls = 'message message--bad';
        ?>
        <div class="card card--pad <?= h($cls) ?>" style="margin-top:12px;">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <!-- 1. Search/Add Form -->
    <section class="card card--pad" style="margin-top: 16px;">
        <h2 style="font-size: 1.3em; margin-bottom: 10px;">Add Items to Print Queue</h2>
        <form method="GET" class="form" style="display:flex; gap:10px; align-items:center;">
            <input class="input" type="text" name="q" value="<?= h($q) ?>"
                placeholder="Enter SKU or UPC to find product" style="flex:1; min-width:260px;">
            <button class="btn btn--primary" type="submit">Search & Add</button>
            <?php if ($q !== ''): ?>
                <a class="btn btn--ghost" href="<?= h($_SERVER['PHP_SELF']) ?>">Clear Search</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- 2. Print Queue Management -->
    <section class="card card--pad" style="margin-top: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h2 style="font-size: 1.3em; margin:0;">Print Queue (<span id="queue-count">0</span> Items)</h2>
            <div>
                <button id="clear-queue-btn" class="link-danger" type="button" style="margin-right: 15px;">Clear All</button>
                <button id="print-labels-btn" class="btn btn--secondary" type="button">
                    <span style="font-weight: bold;">&#128438;</span> Generate & Print
                </button>
            </div>
        </div>

        <div class="table-wrap" id="print-queue-table-container">
            <!-- Queue Table will be dynamically populated by JS -->
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: left;">SKU</th>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: left;">Description</th>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: center; width: 120px;">Code Value</th>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: center; width: 100px;">Quantity</th>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: center; width: 80px;">Action</th>
                    </tr>
                </thead>
                <tbody id="print-queue-body">
                    <tr id="empty-queue-row">
                        <td colspan="5" class="text-center" style="padding: 15px; text-align: center;">Add items using the search bar above to start building your print queue.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div id="print-preview-area" class="print-preview-area print-only">
    <div id="barcode-grid" class="barcode-grid"></div>
</div>

<style>

.print-only {
    display: none;
}

@media print {
    body {
        margin: 0 !important;
        padding: 0 !important;
        font-family: Arial, sans-serif;
    }
    .no-print {
        display: none !important;
    }
    .print-only {
        display: block !important;
    }
    .barcode-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        width: 100%;
        margin: 0;
        padding: 0;
    }
    
    .barcode-label {
        width: 100%; 
        height: 100%;
        padding: 10px; 
        box-sizing: border-box;
        text-align: center;
        overflow: hidden; 
    }

    .barcode-label__sku {
        font-weight: bold;
        font-size: 10pt;
        line-height: 1.1;
        margin-top: 2px;
    }

    .barcode-label__desc {
        font-size: 8pt;
        color: #555;
        line-height: 1.1;
    }

    .barcode-svg {
        display: block;
        margin: 0 auto;
        height: 35px; 
        width: 90%; 
    }
}
</style>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        let printQueue = <?= json_encode($skus_to_print) ?>;

        const queueBody = document.getElementById('print-queue-body');
        const queueCountSpan = document.getElementById('queue-count');
        const emptyRow = document.getElementById('empty-queue-row');
        const printBtn = document.getElementById('print-labels-btn');
        const clearBtn = document.getElementById('clear-queue-btn');
        const barcodeGrid = document.getElementById('barcode-grid');
        
        if (printQueue.length > 0) {
            renderQueue();
        }

        function renderQueue() {
            // Clear existing rows
            queueBody.innerHTML = '';
            
            if (printQueue.length === 0) {
                // Show the empty message
                queueBody.appendChild(emptyRow);
                printBtn.disabled = true;
                clearBtn.disabled = true;
            } else {
                // Remove empty message if it exists
                if (emptyRow.parentNode) emptyRow.remove();
                printBtn.disabled = false;
                clearBtn.disabled = false;

                printQueue.forEach(item => {
                    const row = document.createElement('tr');
                    row.dataset.id = item.id;
                    row.innerHTML = `
                        <td style="padding: 10px; font-weight: bold;">${item.sku_num}</td>
                        <td style="padding: 10px;">${item.desc}</td>
                        <td style="padding: 10px; text-align: center;">${item.upc}</td>
                        <td style="padding: 10px; text-align: center;">
                            <input type="number" min="1" value="${item.quantity}" data-id="${item.id}"
                                class="quantity-input" style="width: 60px; padding: 5px; text-align: center; border: 1px solid #ccc; border-radius: 4px;">
                        </td>
                        <td style="padding: 10px; text-align: center;">
                            <button class="link-danger remove-item-btn" data-id="${item.id}">Remove</button>
                        </td>
                    `;
                    queueBody.appendChild(row);
                });
            }

            queueCountSpan.textContent = printQueue.length;
            attachEventListeners();
        }

        /**
         * Attaches listeners to the dynamically created elements (quantity, remove buttons).
         */
        function attachEventListeners() {
            // Quantity Input Listener
            document.querySelectorAll('.quantity-input').forEach(input => {
                input.onchange = function() {
                    const itemId = this.dataset.id;
                    let newQty = parseInt(this.value, 10);
                    
                    if (isNaN(newQty) || newQty < 1) {
                        newQty = 1;
                        this.value = 1; // Correct input visually
                    }

                    const itemIndex = printQueue.findIndex(i => i.id === itemId);
                    if (itemIndex !== -1) {
                        printQueue[itemIndex].quantity = newQty;
                    }
                };
            });

            // Remove Button Listener
            document.querySelectorAll('.remove-item-btn').forEach(button => {
                button.onclick = function() {
                    const itemId = this.dataset.id;
                    printQueue = printQueue.filter(i => i.id !== itemId);
                    renderQueue(); // Re-render the queue after removal
                };
            });
        }

        // Clear All Button
        clearBtn.onclick = () => {
            printQueue = [];
            renderQueue();
            // Clear search input and URL param
            document.querySelector('input[name="q"]').value = '';
            window.history.pushState({}, '', window.location.pathname);
        };

        // Print Logic
        printBtn.onclick = () => {
            if (printQueue.length === 0) {
                console.warn("Print queue is empty.");
                return;
            }
            
            // 1. Clear the print area
            barcodeGrid.innerHTML = '';
            
            // 2. Generate all labels
            let labelCount = 0;
            const fragment = document.createDocumentFragment();

            printQueue.forEach(item => {
                const totalCopies = item.quantity;
                const barcodeData = item.upc || item.sku_num; // Use UPC or SKU for the barcode value

                for (let i = 0; i < totalCopies; i++) {
                    labelCount++;
                    const labelDiv = document.createElement('div');
                    labelDiv.className = 'barcode-label';
                    
                    // The SVG element that JsBarcode will target
                    const svgId = `barcode-${item.id}-${i}`;
                    
                    labelDiv.innerHTML = `
                        <div class="barcode-label__desc">${item.desc.substring(0, 30)}</div>
                        <svg id="${svgId}" class="barcode-svg"></svg>
                        <div class="barcode-label__sku">${item.sku_num}</div>
                    `;
                    fragment.appendChild(labelDiv);
                    
                    // Note: We append to the DOM first, then generate the barcode
                    // We must do the actual generation AFTER the element is attached to the DOM
                }
            });

            barcodeGrid.appendChild(fragment);

            // 3. Generate the barcodes using JsBarcode
            // Must run after the SVGs are added to the DOM (even if hidden)
            printQueue.forEach(item => {
                const totalCopies = item.quantity;
                const barcodeData = item.upc || item.sku_num;

                for (let i = 0; i < totalCopies; i++) {
                    const svgId = `barcode-${item.id}-${i}`;
                    
                    try {
                         // JsBarcode configuration: Use Code128, quiet zone, and hide text (as we display text separately)
                        JsBarcode(`#${svgId}`, barcodeData, {
                            format: "CODE128", 
                            displayValue: false,
                            width: 1.5, // Bar width
                            height: 35, // Bar height
                            margin: 2,
                            // Ensure the value used for the barcode is a valid string for Code 128
                            valid: (valid) => {
                                if (!valid) {
                                    document.getElementById(svgId).innerHTML = 'ERROR: Invalid Barcode Data';
                                }
                            }
                        });

                    } catch(e) {
                         document.getElementById(svgId).innerHTML = 'ERROR: Barcode generation failed';
                         console.error("Barcode generation error:", e);
                    }
                }
            });

            // 4. Trigger the native browser print dialog
            window.print();
        };

        // On initial load, attach listeners and render queue
        renderQueue();
    });
</script>

<?php
$content = ob_get_clean();
$footer_js = ''; 
include __DIR__ . '/templates/layout.php'; // Adjust path
?>