<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

require_once __DIR__ . '/../dbinv.php';
require_once __DIR__ . '/../utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';


if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2], true)) {
    http_response_code(403);
    exit('<p style="color:red; text-align:center; margin-top:50px;">Access denied.</p>');
}

if (!isset($conn) || !$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}

$title = 'Bulk Complete Pick Orders';
$page_class = 'page-bulk-complete-pick-orders';
$css[] = '/refactor_css/verify_pick.css';

$orders = [];
$stmt = $conn->prepare("
    SELECT id, order_number, status, updated_at
    FROM pick_orders
    WHERE UPPER(COALESCE(status, '')) NOT IN ('COMPLETED', 'SHIPPED')
    ORDER BY
        updated_at DESC,
        id DESC
");

if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $orders[] = $row;
        }
        $res->free();
    }
    $stmt->close();

}

if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2], true)) {
    http_response_code(403);
    exit('<p style="color:red; text-align:center; margin-top:50px;">Access denied.</p>');
}

if (!isset($conn) || !$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}

$title = 'Bulk Complete Pick Orders';
$page_class = 'page-bulk-complete-pick-orders';
$css[] = '/refactor_css/verify_pick.css';

$orders = [];
$stmt = $conn->prepare("
    SELECT id, order_number, status, updated_at
    FROM pick_orders
    WHERE UPPER(COALESCE(status, '')) NOT IN ('COMPLETED', 'SHIPPED')
    ORDER BY
        updated_at DESC,
        id DESC
");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            $orders[] = $row;
        }
        $res->free();
    }
    $stmt->close();
}

ob_start();
?>
<section class="verify-home-shell">
    <div class="verify-home-dashboard">
        <div class="verify-home-dashboard__header bulk-complete-pick-orders__header">
            <div>
                <div class="verify-home-dashboard__eyebrow">Admin / Manager Only</div>
                <h1 class="verify-home-dashboard__title">Bulk Complete Pick Orders</h1>
            </div>
            <a class="btn btn--ghost" href="verify_pick.php">Back to Verify Pick</a>
        </div>

        <div class="muted bulk-complete-pick-orders__lede">
            Select pick orders from any current status. Completed orders are listed for reference but disabled.
        </div>

        <div class="bulk-complete-search">
            <div class="bulk-complete-search__field">
                <input
                    type="search"
                    class="form-control bulk-complete-search__input"
                    id="bulkCompleteSearchInput"
                    placeholder="Search by sales order number"
                    autocomplete="off"
                    spellcheck="false">
                <button type="button" class="btn btn--ghost bulk-complete-search__clear" id="bulkCompleteSearchClearBtn">
                    Clear
                </button>
            </div>
        </div>

        <div class="bulk-complete-controls">
            <label class="bulk-complete-controls__select-all">
                <input type="checkbox" id="bulkCompleteSelectAll" aria-label="Select all active orders">
                <span>Select visible</span>
            </label>
            <button type="button" class="btn btn--ghost" id="bulkCompleteOrdersBtn" disabled>Complete Selected</button>
            <button type="button" class="btn btn--danger" id="bulkDeleteOrdersBtn" disabled>Delete Selected</button>
            <span class="muted bulk-complete-controls__count" id="bulkCompleteSelectionCount">0 selected</span>
        </div>

        <div id="bulkCompleteOrdersList" class="warehouse-orders-list bulkCompleteOrdersList">
            <?php if (empty($orders)): ?>
                <div class="muted">No pick orders found.</div>
            <?php else: ?>
                <?php foreach ($orders as $order): ?>
                    <?php
                    $orderId = (int) ($order['id'] ?? 0);
                    $orderNumber = (string) ($order['order_number'] ?? '');
                    $status = strtoupper(trim((string) ($order['status'] ?? '')));
                    $isCompleted = $status === 'COMPLETED';
                    ?>
                    <div
                        class="order-entry warehouse-orders-list__item warehouse-orders-list__item--bulk-complete bulk-complete-order-entry<?= $isCompleted ? ' order-entry--disabled is-completed' : '' ?>"
                        data-sales-order="<?= htmlspecialchars($orderNumber, ENT_QUOTES) ?>">
                        <input type="checkbox" class="bulk-complete-order-check"
                            value="<?= $orderId ?>"
                            <?= $isCompleted ? ' disabled' : '' ?>
                            aria-label="Select order <?= htmlspecialchars($orderNumber, ENT_QUOTES) ?>">
                        <div class="bulk-complete-order-entry__meta">
                            <span class="order-entry__main bulk-complete-order-entry__main">
                                <?= htmlspecialchars($orderNumber) ?>
                            </span>
                            <span class="order-entry__status muted bulk-complete-order-entry__status">
                                <?= htmlspecialchars($status === '' ? 'UNKNOWN' : $status) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="muted bulk-complete-orders__empty" id="bulkCompleteNoResults" hidden>
            No sales orders match your search.
        </div>
    </div>
</section>

<script>
    window.addEventListener('DOMContentLoaded', () => {
        const list = document.getElementById('bulkCompleteOrdersList');
        const selectAll = document.getElementById('bulkCompleteSelectAll');
        const button = document.getElementById('bulkCompleteOrdersBtn');
        const deleteButton = document.getElementById('bulkDeleteOrdersBtn');
        const count = document.getElementById('bulkCompleteSelectionCount');
        const searchInput = document.getElementById('bulkCompleteSearchInput');
        const searchClear = document.getElementById('bulkCompleteSearchClearBtn');
        const emptyState = document.getElementById('bulkCompleteNoResults');
        if (!list || !button || !deleteButton || !selectAll || !count || !searchInput || !searchClear || !emptyState) return;

        const getActiveChecks = () => Array.from(
            list.querySelectorAll('input.bulk-complete-order-check:not(:disabled)')
        );
        const getVisibleActiveChecks = () => Array.from(
            list.querySelectorAll('.bulk-complete-order-entry input.bulk-complete-order-check:not(:disabled)')
        ).filter((checkbox) => checkbox.closest('.bulk-complete-order-entry')?.style.display !== 'none');
        const getVisibleEntries = () => Array.from(
            list.querySelectorAll('.bulk-complete-order-entry')
        );
        const normalize = (value) => String(value || '').trim().toLowerCase();

        const applySearch = () => {
            const term = normalize(searchInput.value);
            const entries = getVisibleEntries();
            let visibleCount = 0;

            entries.forEach((entry) => {
                const checkbox = entry.querySelector('input.bulk-complete-order-check');
                const searchableText = normalize([
                    entry.dataset.salesOrder || '',
                    checkbox ? checkbox.getAttribute('aria-label') || '' : '',
                    entry.textContent || '',
                ].join(' '));
                const matches = term === '' || searchableText.includes(term);
                entry.style.display = matches ? '' : 'none';
                if (matches) {
                    visibleCount += 1;
                }
            });

            emptyState.hidden = visibleCount > 0;
            return visibleCount;
        };

        const sync = () => {
            const visibleActiveChecks = getVisibleActiveChecks();
            const selectedVisibleChecks = visibleActiveChecks.filter((checkbox) => checkbox.checked);
            const selectedAllActiveChecks = getActiveChecks().filter((checkbox) => checkbox.checked);

            count.textContent = `${selectedVisibleChecks.length} visible selected`;
            button.disabled = selectedAllActiveChecks.length === 0;
            deleteButton.disabled = selectedAllActiveChecks.length === 0;
            selectAll.checked = visibleActiveChecks.length > 0 && selectedVisibleChecks.length === visibleActiveChecks.length;
            selectAll.indeterminate = selectedVisibleChecks.length > 0 && selectedVisibleChecks.length < visibleActiveChecks.length;
            selectAll.disabled = visibleActiveChecks.length === 0;
        };

        list.addEventListener('click', (event) => {
            const entry = event.target.closest('.bulk-complete-order-entry');
            if (!entry) return;

            const checkbox = entry.querySelector('.bulk-complete-order-check');
            if (!checkbox || checkbox.disabled) return;

            // If the user clicked the checkbox itself, let the browser handle it normally.
            if (event.target === checkbox) return;

            checkbox.checked = !checkbox.checked;

            // Trigger your existing selection/count/button update.
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        });
        
        list.addEventListener('change', (event) => {
            const target = event.target;
            if (target && target.classList && target.classList.contains('bulk-complete-order-check')) {
                sync();
            }
        });

        searchInput.addEventListener('input', () => {
            applySearch();
            sync();
        });

        searchClear.addEventListener('click', () => {
            searchInput.value = '';
            applySearch();
            searchInput.focus();
            sync();
        });

        selectAll.addEventListener('change', () => {
            const shouldCheck = selectAll.checked;
            getVisibleActiveChecks().forEach((checkbox) => {
                checkbox.checked = shouldCheck;
            });
            sync();
        });

        button.addEventListener('click', async () => {
            const selectedIds = getActiveChecks()
                .filter((checkbox) => checkbox.checked)
                .map((checkbox) => Number(checkbox.value || 0))
                .filter((value) => value > 0);
            if (!selectedIds.length) {
                alert('Select at least one active order.');
                return;
            }
            const ok = confirm(`Mark ${selectedIds.length} selected order(s) as COMPLETED?`);
            if (!ok) {
                return;
            }
            button.disabled = true;
            try {
                const url = new URL('verify_pick.php', window.location.href);
                url.searchParams.set('action', 'bulk_complete_orders');
                const res = await fetch(url.toString(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ order_ids: selectedIds }),
                });
                const responseText = await res.text();
                let data = {};
                try {
                    data = responseText ? JSON.parse(responseText) : {};
                } catch (_) {
                    data = {};
                }
                if (!res.ok || !data?.ok) {
                    alert(data?.error || responseText || 'Unable to complete the selected orders.');
                    return;
                }
                alert(`${data.updated_count || 0} order(s) completed.`);
                window.location.reload();
            } catch (err) {
                alert(err?.message || 'Unable to complete the selected orders.');
            } finally {
                applySearch();
                sync();
            }
        });

        deleteButton.addEventListener('click', async () => {
            const selectedIds = getActiveChecks()
                .filter((checkbox) => checkbox.checked)
                .map((checkbox) => Number(checkbox.value || 0))
                .filter((value) => value > 0);
            if (!selectedIds.length) {
                alert('Select at least one active order.');
                return;
            }
            const reason = window.prompt('Reason for deleting these orders?');
            if (!reason || !reason.trim()) {
                alert('Deletion reason is required.');
                return;
            }
            const ok = confirm(`Delete ${selectedIds.length} selected order(s)? This cannot be undone.`);
            if (!ok) {
                return;
            }
            deleteButton.disabled = true;
            try {
                const url = new URL('verify_pick.php', window.location.href);
                url.searchParams.set('action', 'bulk_delete_orders');
                const res = await fetch(url.toString(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ order_ids: selectedIds, reason: reason.trim() }),
                });
                const responseText = await res.text();
                let data = {};
                try {
                    data = responseText ? JSON.parse(responseText) : {};
                } catch (_) {
                    data = {};
                }
                if (!res.ok || !data?.ok) {
                    alert(data?.error || responseText || 'Unable to delete the selected orders.');
                    return;
                }
                alert(`${data.deleted_count || 0} order(s) deleted.`);
                window.location.reload();
            } catch (err) {
                alert(err?.message || 'Unable to delete the selected orders.');
            } finally {
                applySearch();
                sync();
            }
        });

        applySearch();
        sync();
    });
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/templates/layout.php';
