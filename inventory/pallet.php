<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';

if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}

$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'] ?? 0;

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn->set_charset('utf8mb4');
} catch (\Throwable $_) {
}

if (!function_exists('log_inventory_movement')) {
    function log_inventory_movement(mysqli $conn, int $sku_id, int $loc_id, int $qty_change, string $movement_type, string $reference, int $user_id): void
    {
        $stmt = $conn->prepare("
            INSERT INTO inventory_movements (sku_id, loc_id, quantity_change, movement_type, reference, user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iiissi', $sku_id, $loc_id, $qty_change, $movement_type, $reference, $user_id);
        $stmt->execute();
        $stmt->close();
    }
}

$message = '';
$message_type = '';
$selected_manage_pallet_id = (int) ($_GET['manage_pallet_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'create_pallet':
                $location_code = trim($_POST['location_code'] ?? '');

                if ($location_code === '') {
                    $message = 'Location is required.';
                    $message_type = 'error';
                    break;
                }

                $parsed = parse_location_code($location_code);
                if (!$parsed || $parsed['row_code'] === '') {
                    $message = 'Invalid location format. Use ROW-BAY-LEVEL-SIDE.';
                    $message_type = 'error';
                    break;
                }

                $loc_id = ensure_location(
                    $conn,
                    $parsed['row_code'],
                    $parsed['bay_num'],
                    $parsed['level_code'],
                    $parsed['side']
                );

                $created = false;
                $attempts = 0;
                $pallet_tag = '';
                while (!$created && $attempts < 3) {
                    $attempts++;
                    $pallet_tag = generate_next_pallet_tag($conn);
                    try {
                        $stmt = $conn->prepare("INSERT INTO pallet (pallet_tag, loc_id, status) VALUES (?, ?, 'ACTIVE')");
                        $stmt->bind_param('si', $pallet_tag, $loc_id);
                        $stmt->execute();
                        $stmt->close();
                        $created = true;
                    } catch (\mysqli_sql_exception $dup) {
                        if ((int) $dup->getCode() === 1062) {
                            continue;
                        }
                        throw $dup;
                    }
                }

                if (!$created) {
                    $message = 'Unable to assign a pallet tag. Please try again.';
                    $message_type = 'error';
                    break;
                }

                $message = "Pallet {$pallet_tag} created.";
                $message_type = 'success';
                break;

            case 'merge_pallets':
                $source_id = (int) ($_POST['source_pallet_id'] ?? 0);
                $target_id = (int) ($_POST['target_pallet_id'] ?? 0);

                if ($source_id <= 0 || $target_id <= 0 || $source_id === $target_id) {
                    $message = 'Choose two different pallets.';
                    $message_type = 'error';
                    break;
                }

                $source_info = get_pallet_info($conn, $source_id);
                $target_info = get_pallet_info($conn, $target_id);
                if (!$source_info || !$target_info) {
                    $message = 'Unable to load pallet details for merge.';
                    $message_type = 'error';
                    break;
                }

                $stmt_contents = $conn->prepare('SELECT sku_id, quantity FROM inventory WHERE pallet_id = ?');
                $stmt_contents->bind_param('i', $source_id);
                $stmt_contents->execute();
                $source_contents = $stmt_contents->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_contents->close();

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("
                        INSERT INTO inventory (sku_id, pallet_id, quantity)
                        SELECT sku_id, ?, quantity
                        FROM inventory
                        WHERE pallet_id = ?
                        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = CURRENT_TIMESTAMP
                    ");
                    $stmt->bind_param('ii', $target_id, $source_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare('DELETE FROM inventory WHERE pallet_id = ?');
                    $stmt->bind_param('i', $source_id);
                    $stmt->execute();
                    $stmt->close();

                    foreach ($source_contents as $item) {
                        $sku_id = (int) $item['sku_id'];
                        $qty = (int) $item['quantity'];
                        if ($qty <= 0) {
                            continue;
                        }
                        $reference = sprintf(
                            'Merged pallet %s into %s',
                            $source_info['pallet_tag'],
                            $target_info['pallet_tag']
                        );
                        log_inventory_movement($conn, $sku_id, $source_info['loc_id'], -$qty, 'TRANSFER', $reference, $user_id);
                        log_inventory_movement($conn, $sku_id, $target_info['loc_id'], $qty, 'TRANSFER', $reference, $user_id);
                    }

                    $conn->commit();
                } catch (\Throwable $inner) {
                    $conn->rollback();
                    throw $inner;
                }

                $message = 'Pallets merged successfully.';
                $message_type = 'success';
                $selected_manage_pallet_id = $target_id;
                break;
            case 'relocate_pallet':
                $move_pallet_id = (int) ($_POST['relocate_pallet_id'] ?? 0);
                $new_location_code = trim($_POST['relocate_location_code'] ?? '');

                if ($move_pallet_id <= 0 || $new_location_code === '') {
                    $message = 'Choose a pallet and enter a location code.';
                    $message_type = 'error';
                    break;
                }

                $pallet_info = get_pallet_info($conn, $move_pallet_id);
                if (!$pallet_info) {
                    $message = 'Pallet not found.';
                    $message_type = 'error';
                    break;
                }

                $parsed = parse_location_code($new_location_code);
                if (!$parsed || $parsed['row_code'] === '') {
                    $message = 'Invalid location format. Use ROW-BAY-LEVEL-SIDE.';
                    $message_type = 'error';
                    break;
                }

                $new_loc_id = ensure_location(
                    $conn,
                    $parsed['row_code'],
                    $parsed['bay_num'],
                    $parsed['level_code'],
                    $parsed['side']
                );

                if ($new_loc_id === (int) $pallet_info['loc_id']) {
                    $message = 'Pallet is already at that location.';
                    $message_type = 'warning';
                    break;
                }

                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('UPDATE pallet SET loc_id = ? WHERE id = ?');
                    $stmt->bind_param('ii', $new_loc_id, $move_pallet_id);
                    $stmt->execute();
                    $stmt->close();

                    $itemsStmt = $conn->prepare('SELECT sku_id, quantity FROM inventory WHERE pallet_id = ? AND quantity > 0');
                    $itemsStmt->bind_param('i', $move_pallet_id);
                    $itemsStmt->execute();
                    $itemsRes = $itemsStmt->get_result();
                    $reference = sprintf('Pallet %s relocated to %s', $pallet_info['pallet_tag'], strtoupper($new_location_code));
                    while ($item = $itemsRes->fetch_assoc()) {
                        $sku_id = (int) $item['sku_id'];
                        $qty = (int) $item['quantity'];
                        if ($qty <= 0) {
                            continue;
                        }
                        log_inventory_movement($conn, $sku_id, (int) $pallet_info['loc_id'], -$qty, 'TRANSFER', $reference, $user_id);
                        log_inventory_movement($conn, $sku_id, $new_loc_id, $qty, 'TRANSFER', $reference, $user_id);
                    }
                    $itemsStmt->close();

                    $conn->commit();
                } catch (\Throwable $inner) {
                    $conn->rollback();
                    throw $inner;
                }

                $message = sprintf('Pallet %s moved to %s.', $pallet_info['pallet_tag'], strtoupper($new_location_code));
                $message_type = 'success';
                if ($selected_manage_pallet_id === $move_pallet_id) {
                    $selected_manage_pallet_id = $move_pallet_id;
                }
                break;

            case 'move_skus':
                $source_id = (int) ($_POST['move_source_pallet_id'] ?? 0);
                $target_id = (int) ($_POST['move_target_pallet_id'] ?? 0);
                $sku_ids = array_filter(array_map('intval', $_POST['sku_ids'] ?? []));
                $selected_manage_pallet_id = $source_id;

                if ($source_id <= 0 || $target_id <= 0 || $source_id === $target_id) {
                    $message = 'Choose a valid source and destination pallet.';
                    $message_type = 'error';
                    break;
                }

                if (empty($sku_ids)) {
                    $message = 'Select at least one SKU to move.';
                    $message_type = 'error';
                    break;
                }

                $source_info = get_pallet_info($conn, $source_id);
                $target_info = get_pallet_info($conn, $target_id);
                if (!$source_info || !$target_info) {
                    $message = 'Unable to load pallet details for move.';
                    $message_type = 'error';
                    break;
                }

                $conn->begin_transaction();
                try {
                    $selectStmt = $conn->prepare('SELECT quantity FROM inventory WHERE pallet_id = ? AND sku_id = ?');
                    $insertStmt = $conn->prepare("
                        INSERT INTO inventory (sku_id, pallet_id, quantity)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = CURRENT_TIMESTAMP
                    ");
                    $deleteStmt = $conn->prepare('DELETE FROM inventory WHERE pallet_id = ? AND sku_id = ?');

                    $moved = 0;
                    foreach ($sku_ids as $sku_id) {
                        $selectStmt->bind_param('ii', $source_id, $sku_id);
                        $selectStmt->execute();
                        $res = $selectStmt->get_result();
                        $row = $res->fetch_assoc();
                        $res->free();

                        if (!$row) {
                            continue;
                        }

                        $qty = (int) $row['quantity'];
                        if ($qty <= 0) {
                            continue;
                        }

                        $insertStmt->bind_param('iii', $sku_id, $target_id, $qty);
                        $insertStmt->execute();

                        $deleteStmt->bind_param('ii', $source_id, $sku_id);
                        $deleteStmt->execute();

                        $reference = sprintf(
                            'Moved inventory from %s to %s',
                            $source_info['pallet_tag'],
                            $target_info['pallet_tag']
                        );
                        log_inventory_movement($conn, $sku_id, $source_info['loc_id'], -$qty, 'TRANSFER', $reference, $user_id);
                        log_inventory_movement($conn, $sku_id, $target_info['loc_id'], $qty, 'TRANSFER', $reference, $user_id);

                        $moved++;
                    }

                    $selectStmt->close();
                    $insertStmt->close();
                    $deleteStmt->close();

                    $conn->commit();
                } catch (\Throwable $inner) {
                    $conn->rollback();
                    throw $inner;
                }

                if ($moved === 0) {
                    $message = 'No SKUs were moved.';
                    $message_type = 'warning';
                } else {
                    $message = "Moved {$moved} SKU(s) to the target pallet.";
                    $message_type = 'success';
                }
                break;

        }
    } catch (\Throwable $e) {
        error_log('Pallet action failed: ' . $e->getMessage());
        if (!$message) {
            $message = 'Action failed. Please try again.';
            $message_type = 'error';
        }
    }
}

$next_pallet_tag = generate_next_pallet_tag($conn);

$pallet_data = [];
$error_msg = '';

$sql = "
    SELECT
        p.id AS pallet_id,
        p.pallet_tag,
        l.row_code,
        l.bay_num,
        l.level_code,
        l.side,
        COALESCE(SUM(i.quantity), 0) AS total_qty,
        COUNT(DISTINCT i.sku_id) AS total_skus
    FROM pallet p
    JOIN location l ON l.id = p.loc_id
    LEFT JOIN inventory i ON i.pallet_id = p.id
    WHERE p.status = 'ACTIVE'
    GROUP BY p.id, p.pallet_tag, l.id, l.row_code, l.bay_num, l.level_code, l.side
    ORDER BY p.pallet_tag ASC
";

try {
    $res = $conn->query($sql);
    while ($r = $res->fetch_assoc()) {
        $pallet_data[] = [
            'id' => (int) $r['pallet_id'],
            'tag' => (string) $r['pallet_tag'],
            'location' => getPalletLocationDisplay($r),
            'location_code' => sprintf(
                '%s-%s-%s-%s',
                $r['row_code'],
                $r['bay_num'],
                $r['level_code'],
                $r['side']
            ),
            'total_skus' => (int) $r['total_skus'],
            'total_qty' => (int) $r['total_qty'],
        ];
    }
} catch (\Throwable $e) {
    error_log("Pallet Query Failed: " . $e->getMessage());
    $error_msg = 'Could not load pallet data.';
    $pallet_data = [];
}

$manage_pallet = null;
$manage_skus = [];

if ($selected_manage_pallet_id > 0) {
    $stmt = $conn->prepare("
        SELECT p.id, p.pallet_tag, l.row_code, l.bay_num, l.level_code, l.side
        FROM pallet p
        JOIN location l ON l.id = p.loc_id
        WHERE p.id = ?
    ");
    $stmt->bind_param('i', $selected_manage_pallet_id);
    $stmt->execute();
    $manage_pallet = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($manage_pallet) {
        $stmt = $conn->prepare("
            SELECT i.sku_id, s.sku_num, s.`desc`, i.quantity
            FROM inventory i
            JOIN sku s ON s.id = i.sku_id
            WHERE i.pallet_id = ?
            ORDER BY s.sku_num ASC
        ");
        $stmt->bind_param('i', $selected_manage_pallet_id);
        $stmt->execute();
        $manage_skus = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $selected_manage_pallet_id = 0;
    }
}

$pallet_options = array_map(function ($row) {
    return ['id' => $row['id'], 'tag' => $row['tag']];
}, $pallet_data);

$location_options = [];
$manage_search_options = [];
try {
    $locRes = $conn->query("
        SELECT row_code, bay_num, level_code, side
        FROM location
        WHERE is_disabled = 0
        ORDER BY row_code, bay_num, level_code, side
    ");
    while ($loc = $locRes->fetch_assoc()) {
        $code = sprintf(
            '%s-%s-%s-%s',
            $loc['row_code'],
            $loc['bay_num'],
            $loc['level_code'],
            $loc['side']
        );
        $location_options[] = [
            'code' => $code,
            'label' => sprintf('%s (Row %s, Bay %s, Level %s, %s)', $code, $loc['row_code'], $loc['bay_num'], $loc['level_code'], $loc['side'])
        ];
    }
} catch (\Throwable $e) {
    error_log('Location options load failed: ' . $e->getMessage());
}

$selected_manage_label = '';
foreach ($pallet_data as $row) {
    $opt = [
        'id' => (int) $row['id'],
        'tag' => $row['tag'],
        'location' => $row['location'],
        'location_code' => $row['location_code'] ?? '',
        'label' => sprintf('%s — %s', $row['location_code'] ?? '', $row['tag']),
    ];
    $manage_search_options[] = $opt;
    if ($selected_manage_pallet_id && (int) $row['id'] === $selected_manage_pallet_id) {
        $selected_manage_label = $opt['label'];
    }
}

$title = 'Active Pallets';
$page_class = 'page-active-pallets';
ob_start();
?>

<h2 class="title">Pallet Management</h2>

<?php if ($message): ?>
    <?php
    $alert_class = 'alert-info';
    if ($message_type === 'success') {
        $alert_class = 'alert-success';
    } elseif ($message_type === 'error') {
        $alert_class = 'alert-danger';
    } elseif ($message_type === 'warning') {
        $alert_class = 'alert-warning';
    }
    ?>
    <div class="alert <?= h($alert_class) ?>"><?= h($message) ?></div>
<?php endif; ?>

<?php if (!empty($error_msg)): ?>
    <div class="alert alert-danger"><?= h($error_msg) ?></div>
<?php endif; ?>

<div class="card card--pad h-100">
    <div class="grid-2col mb-4">
        <div>
            <h3 class="h5 mb-3">Create Pallet</h3>
            <form method="post" class="form">
                <input type="hidden" name="action" value="create_pallet">
                <label class="label">Next Pallet Tag</label>
                <input type="text" class="input" value="<?= h($next_pallet_tag) ?>" readonly>
                <p class="small text-muted mt-1">Tags auto-increment (e.g., P001, P002).</p>

                <label class="label mt-2">Location Code (ROW-BAY-LEVEL-SIDE)</label>
                <input type="text" name="location_code" class="input" placeholder="e.g. R10-01-11-F" required>

                <button type="submit" class="btn btn--primary mt-3">Create Pallet</button>
            </form>
        </div>

        <div>
            <h3 class="h5 mb-3">Manage Pallet</h3>
            <form method="get" class="form" id="managePalletForm">
                <label class="label">Search by Pallet Location</label>
                <input type="text" class="input" id="managePalletInput" name="manage_pallet_search"
                    placeholder="Start typing ROW-BAY-LEVEL-SIDE" autocomplete="off" list="managePalletOptionsList"
                    value="<?= h($selected_manage_label) ?>">
                <input type="hidden" name="manage_pallet_id" id="managePalletId"
                    value="<?= (int) $selected_manage_pallet_id ?>">
                <p class="small text-muted mt-2 mb-0">Pick a pallet location from the list to load its contents.</p>
            </form>
        </div>
    </div>
</div>


<div class="card card--pad h-100">
    <div class="mb-4" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1.5rem;">
        <div>
            <h3 class="h5 mb-3">Merge Pallets</h3>
            <form method="post" class="form">
                <input type="hidden" name="action" value="merge_pallets">
                <label class="label">Source Pallet</label>
                <select name="source_pallet_id" class="select" required>
                    <option value="">Select</option>
                    <?php foreach ($pallet_options as $opt): ?>
                        <option value="<?= (int) $opt['id'] ?>"><?= h($opt['tag']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="label mt-2">Destination Pallet</label>
                <select name="target_pallet_id" class="select" required>
                    <option value="">Select</option>
                    <?php foreach ($pallet_options as $opt): ?>
                        <option value="<?= (int) $opt['id'] ?>"><?= h($opt['tag']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="small text-muted mt-2">All SKUs from the source pallet will be moved to the destination
                    pallet.</p>
                <button type="submit" class="btn btn-outline mt-2">Merge Pallets</button>
            </form>
        </div>
        <div>
            <h3 class="h5 mb-3">Move Pallet Location</h3>
            <form method="post" class="form">
                <input type="hidden" name="action" value="relocate_pallet">
                <label class="label">Pallet</label>
                <select name="relocate_pallet_id" class="select" required>
                    <option value="">Choose pallet</option>
                    <?php foreach ($pallet_options as $opt): ?>
                        <option value="<?= (int) $opt['id'] ?>"><?= h($opt['tag']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="label mt-2">New Location (ROW-BAY-LEVEL-SIDE)</label>
                <input type="text" name="relocate_location_code" class="input" placeholder="e.g. R10-02-12-B"
                    list="locationOptionsList" autocomplete="off" required>
                <p class="small text-muted mt-2">Updates the pallet record and logs movement for all SKUs on it.</p>
                <button type="submit" class="btn btn-outline mt-2">Move Pallet</button>
            </form>
        </div>
    </div>
</div>

<?php if ($location_options): ?>
    <datalist id="locationOptionsList">
        <?php foreach ($location_options as $loc): ?>
            <option value="<?= h($loc['code']) ?>"><?= h($loc['label']) ?></option>
        <?php endforeach; ?>
    </datalist>
<?php endif; ?>
<?php if ($manage_search_options): ?>
    <datalist id="managePalletOptionsList">
        <?php foreach ($manage_search_options as $opt): ?>
            <option value="<?= h($opt['label']) ?>"><?= h($opt['tag']) ?></option>
        <?php endforeach; ?>
    </datalist>
<?php endif; ?>

<div class="card" style="overflow:auto;">
    <table class="table table-sm table-striped align-middle mb-0" id="palletTable">
        <thead class="table-light">
            <tr>
                <th>PALLET TAG</th>
                <th>LOCATION</th>
                <th class="text-end">TOTAL SKUS</th>
                <th class="text-end">TOTAL QTY</th>
                <th class="text-end">ACTIONS</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($pallet_data)): ?>
                <tr>
                    <td colspan="5" class="text-muted text-center">No active pallets found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($pallet_data as $p): ?>
                    <tr class="js-pallet-row" id="pallet-row-<?= $p['id'] ?>" data-pallet-row="<?= $p['id'] ?>"
                        data-location-code="<?= h($p['location_code'] ?? '') ?>" data-location-text="<?= h($p['location']) ?>"
                        data-pallet-tag="<?= h($p['tag']) ?>">
                        <td><strong><?= h($p['tag']) ?></strong></td>
                        <td><?= h($p['location']) ?></td>
                        <td class="text-end"><?= number_format($p['total_skus']) ?></td>
                        <td class="text-end"><?= number_format($p['total_qty']) ?></td>
                        <td class="text-end">
                            <a href="?manage_pallet_id=<?= $p['id'] ?>#manageCard" class="btn btn-outline-secondary btn-sm"
                                title="Load pallet in manager">
                                Manage
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="js-pallet-filter-empty" style="display:none;">
                    <td colspan="5" class="text-center text-muted">No pallet locations match this search.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($manage_pallet): ?>
    <div class="card card--pad mt-4" id="manageCard">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h3 class="h5 mb-1"><?= h($manage_pallet['pallet_tag']) ?></h3>
                <div class="text-muted small"><?= h(getPalletLocationDisplay($manage_pallet)) ?></div>
            </div>
        </div>

        <?php if (empty($manage_skus)): ?>
            <p class="text-muted mb-0">No SKUs on this pallet.</p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="action" value="move_skus">
                <input type="hidden" name="move_source_pallet_id" value="<?= (int) $selected_manage_pallet_id ?>">
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th style="width:32px;">
                                    <input type="checkbox" id="checkAllSkus">
                                </th>
                                <th>SKU</th>
                                <th>Description</th>
                                <th class="text-end">Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($manage_skus as $sku): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="sku_ids[]" value="<?= (int) $sku['sku_id'] ?>">
                                    </td>
                                    <td><?= h($sku['sku_num']) ?></td>
                                    <td><?= h($sku['desc']) ?></td>
                                    <td class="text-end"><?= number_format((int) $sku['quantity']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="label">Move Selected SKUs To</label>
                        <select name="move_target_pallet_id" class="select" required>
                            <option value="">Select destination</option>
                            <?php foreach ($pallet_options as $opt): ?>
                                <?php if ((int) $opt['id'] === (int) $selected_manage_pallet_id)
                                    continue; ?>
                                <option value="<?= (int) $opt['id'] ?>"><?= h($opt['tag']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" class="btn btn--primary mt-3 mt-md-0">Move Selected SKUs</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();

$manageOptionsJson = json_encode(
    $manage_search_options,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
$footer_js = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    var checkAll = document.getElementById('checkAllSkus');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            var boxes = document.querySelectorAll('#manageCard input[type="checkbox"][name="sku_ids[]"]');
            boxes.forEach(function (box) {
                box.checked = checkAll.checked;
            });
        });
    }

    var relocateInput = document.querySelector('input[name="relocate_location_code"]');
    if (relocateInput && relocateInput.showPicker) {
        relocateInput.addEventListener('focus', function () {
            try { relocateInput.showPicker(); } catch (_) {}
        });
    }

    var manageOptions = $manageOptionsJson;
    var manageInput = document.getElementById('managePalletInput');
    var manageHidden = document.getElementById('managePalletId');
    var manageForm = document.getElementById('managePalletForm');
    var palletTable = document.getElementById('palletTable');
    var palletRows = palletTable ? Array.prototype.slice.call(palletTable.querySelectorAll('tbody tr[data-location-code]')) : [];
    var noPalletMatchesRow = palletTable ? palletTable.querySelector('tbody tr.js-pallet-filter-empty') : null;

    function syncManageSelection() {
        if (!manageInput || !manageHidden) return null;
        var val = manageInput.value.trim().toLowerCase();
        if (!val) {
            manageHidden.value = '';
            return null;
        }
        var match = manageOptions.find(function (opt) {
            return (opt.label && opt.label.toLowerCase() === val)
                || (opt.location_code && opt.location_code.toLowerCase() === val)
                || (opt.tag && opt.tag.toLowerCase() === val);
        });
        manageHidden.value = match ? match.id : '';
        return match || null;
    }

    function filterPalletTable() {
        if (!manageInput || !palletRows.length) {
            if (noPalletMatchesRow) {
                noPalletMatchesRow.style.display = 'none';
            }
            return;
        }
        var query = manageInput.value.trim().toLowerCase();
        var visibleCount = 0;
        palletRows.forEach(function (row) {
            var locationCode = (row.getAttribute('data-location-code') || '').toLowerCase();
            var locationText = (row.getAttribute('data-location-text') || '').toLowerCase();
            var palletTag = (row.getAttribute('data-pallet-tag') || '').toLowerCase();
            var matches = !query
                || locationCode.indexOf(query) !== -1
                || locationText.indexOf(query) !== -1
                || palletTag.indexOf(query) !== -1;
            row.style.display = matches ? '' : 'none';
            if (matches) {
                visibleCount++;
            }
        });
        if (noPalletMatchesRow) {
            noPalletMatchesRow.style.display = (visibleCount === 0 && query) ? '' : 'none';
        }
    }

    function handleManageInputEvent() {
        var match = syncManageSelection();
        if (match && manageInput) {
            var currentVal = manageInput.value.trim().toLowerCase();
            var matchLabel = (match.label || '').toLowerCase();
            if (matchLabel && currentVal === matchLabel) {
                manageInput.value = match.tag || manageInput.value;
                syncManageSelection();
            }
        }
        filterPalletTable();
    }

    if (manageInput) {
        manageInput.addEventListener('input', handleManageInputEvent);
        manageInput.addEventListener('change', handleManageInputEvent);
        if (manageInput.showPicker) {
            manageInput.addEventListener('focus', function () {
                try { manageInput.showPicker(); } catch (_) {}
            });
        }
        handleManageInputEvent();
    }

    if (manageForm) {
        manageForm.addEventListener('submit', function (e) {
            if (!manageHidden.value) {
                e.preventDefault();
                manageInput && manageInput.focus();
            }
        });
    }
});
</script>
HTML;

require __DIR__ . '/templates/layout.php';
