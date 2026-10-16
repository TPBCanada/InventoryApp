<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../dbinv.php';
require_once __DIR__ . '/../utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';

$orderNumber = trim((string) ($_GET['order_number'] ?? ''));
$events = [];
$summary = null;
$errorMessage = '';
$formatDuration = static function (int $seconds): string {
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
};

if ($orderNumber !== '') {
    $sql = "
        SELECT
            pve.id AS event_id,
            po.order_number,
            po.status AS order_status,
            pve.workflow_stage,
            pi.item_no,
            (
                SELECT COUNT(*)
                FROM pick_items pi_count
                WHERE pi_count.order_id = po.id
            ) AS total_items_in_order,
            pve.event_type,
            pve.qty_delta,
            pve.expected_sku,
            pve.sku_scanned,
            pve.meta_json,
            pve.created_at,
            pve.user_id,
            COALESCE(u.username, CONCAT('User #', pve.user_id)) AS handler
        FROM pick_verify_events pve
        INNER JOIN pick_orders po ON pve.order_id = po.id
        LEFT JOIN pick_items pi ON pve.pick_item_id = pi.id
        LEFT JOIN xserqhmy_users.users u ON pve.user_id = u.id
        WHERE po.order_number = ?
        ORDER BY pve.created_at ASC, pve.id ASC;
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $errorMessage = 'Failed to prepare pick events query.';
    } elseif (!$stmt->bind_param('s', $orderNumber) || !$stmt->execute()) {
        $errorMessage = 'Failed to execute pick events query.';
        $stmt->close();
    } else {
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $workflowStage = strtolower(trim((string) ($row['workflow_stage'] ?? '')));
                $mode = $workflowStage === 'pack' ? 'packer' : 'picker';
                $events[] = [
                    'event_id' => (int) ($row['event_id'] ?? 0),
                    'order_number' => (string) ($row['order_number'] ?? ''),
                    'order_status' => (string) ($row['order_status'] ?? ''),
                    'workflow_stage' => $workflowStage,
                    'mode' => $mode,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'event_type' => (string) ($row['event_type'] ?? ''),
                    'item_no' => (string) ($row['item_no'] ?? ''),
                    'qty_delta' => (int) ($row['qty_delta'] ?? 0),
                    'handler' => (string) ($row['handler'] ?? ''),
                    'user_id' => (string) ($row['user_id'] ?? ''),
                    'expected_sku' => (string) ($row['expected_sku'] ?? ''),
                    'sku_scanned' => (string) ($row['sku_scanned'] ?? ''),
                    'meta_json' => (string) ($row['meta_json'] ?? ''),
                    'total_items_in_order' => (int) ($row['total_items_in_order'] ?? 0),
                ];
            }
        } else {
            $errorMessage = 'Failed to read pick events results.';
        }

        $stmt->close();

        if ($events) {
            $firstTimestamp = strtotime((string) ($events[0]['created_at'] ?? '')) ?: 0;
            $lastTimestamp = strtotime((string) ($events[array_key_last($events)]['created_at'] ?? '')) ?: 0;

            $summary = [
                'order_number' => $events[0]['order_number'] ?? $orderNumber,
                'status' => $events[0]['order_status'] ?? '',
                'key_user' => $events[0]['handler'] ?? '',
                'total_items' => $events[0]['total_items_in_order'] ?? 0,
                'first_scan_at' => $events[0]['created_at'] ?? '',
                'last_scan_at' => $events[array_key_last($events)]['created_at'] ?? '',
                'elapsed_time' => $firstTimestamp > 0 && $lastTimestamp >= $firstTimestamp
                    ? $formatDuration($lastTimestamp - $firstTimestamp)
                    : '00:00:00',
            ];
        } else {
            $summary = [
                'order_number' => $orderNumber,
                'status' => '',
                'key_user' => '',
                'total_items' => 0,
                'first_scan_at' => '',
                'last_scan_at' => '',
                'elapsed_time' => '00:00:00',
            ];
        }
    }
}


$escape = static function ($value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
};

$activityLabels = [
    'line_add' => 'Line Added',
    'line_delete' => 'Line Deleted',
    'reset_verified' => 'Verification Reset',
    'scan_success' => 'Successful Scan',
    'scan_error' => 'Scan Error',
    'short_ship_on' => 'Shortage Identified',
    'short_ship_off' => 'Shortage Resolved',
    'send_back_to_pick' => 'Returned to Pick',
];

$formatActivity = static function (string $eventType) use ($activityLabels): string {
    $type = strtolower($eventType);
    if (str_contains($type, 'short')) {
        return $activityLabels['short_ship_on'];
    }
    if (str_contains($type, 'error')) {
        return $activityLabels['scan_error'];
    }
    if (str_contains($type, 'resolve')) {
        return $activityLabels['short_ship_off'];
    }
    if (str_contains($type, 'bulk')) {
        return 'Successful Scan (Bulk Pick)';
    }
    if (str_contains($type, 'scan')) {
        return $activityLabels['scan_success'];
    }
    if (isset($activityLabels[$type])) {
        return $activityLabels[$type];
    }
    return $eventType !== '' ? ucwords(str_replace('_', ' ', $eventType)) : 'Pick Event';
};

$formatQty = static function (int $qty): string {
    if ($qty > 0) {
        return $qty . ' ' . ($qty === 1 ? 'unit' : 'units') . ' verified';
    }
    return '0 (Status Flag)';
};

$formatStatusDetails = static function (array $event): string {
    $type = strtolower((string) ($event['event_type'] ?? ''));
    if (str_contains($type, 'short')) {
        return 'Status: In Progress, Item Shorted.';
    }
    if (str_contains($type, 'resolve')) {
        return 'Status: In Progress, Item Not Shorted.';
    }
    return 'Item processed successfully.';
};

$items = array_values(array_unique(array_filter(array_map(
    static fn($event) => (string) ($event['item_no'] ?? ''),
    $events
))));

$activities = array_values(array_unique(array_filter(array_map(
    static fn($event) => (string) $formatActivity((string) ($event['event_type'] ?? '')),
    $events
))));

$statuses = array_values(array_unique(array_filter(array_map(
    static fn($event) => (string) $formatStatusDetails($event),
    $events
))));

$modes = array_values(array_unique(array_filter(array_map(
    static fn($event) => (string) ($event['mode'] ?? ''),
    $events
))));

sort($items);
sort($activities);
sort($statuses);
sort($modes);

$formatDateTime = static function (string $value): string {
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }
    return date('M d Y, g:i:s A', $timestamp);
};

$qtyClass = static function (int $qty): string {
    if ($qty > 1) {
        return 'qty-pill qty-pill--warning';
    }

    if ($qty === 1) {
        return 'qty-pill qty-pill--success';
    }

    return 'qty-pill qty-pill--info';
};

$activityBadgeClass = static function (string $eventType): string {
    $type = strtolower($eventType);
    if (str_contains($type, 'short')) {
        return 'activity-badge activity-badge--short';
    }
    if (str_contains($type, 'error')) {
        return 'activity-badge activity-badge--error';
    }
    if (str_contains($type, 'resolve') || str_contains($type, 'reset_verified')) {
        return 'activity-badge activity-badge--reset';
    }
    if (str_contains($type, 'scan') || str_contains($type, 'bulk') || str_contains($type, 'line_add')) {
        return 'activity-badge activity-badge--scan';
    }
    return 'activity-badge';
};

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pick Events Report</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f7f9;
            --panel: #ffffff;
            --panel-soft: #eef8f7;
            --line: #dbe3ef;
            --text: #18212f;
            --muted: #5d6b7f;
            --accent: #198a8a;
            --accent-2: #0f766e;
            --danger: #991b1b;
            --danger-bg: #fef2f2;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(25, 138, 138, 0.10), transparent 28%),
                linear-gradient(180deg, #ffffff 0%, var(--bg) 100%);
        }

        .page {
            max-width: 1260px;
            margin: 0 auto;
            padding: 24px;
        }

        .hero {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .hero__top {
            padding: 18px 20px;
            background: linear-gradient(135deg, var(--panel-soft), #f7fbfb);
            border-bottom: 1px solid var(--line);
        }

        .hero__eyebrow {
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--accent-2);
            font-weight: 700;
            margin-bottom: 6px;
        }

        .hero__title {
            margin: 0;
            font-size: 28px;
            line-height: 1.1;
        }

        .hero__subtitle {
            margin: 8px 0 0;
            color: var(--muted);
        }

        .toolbar {
            padding: 16px 20px 20px;
            display: flex;
            gap: 10px;
            align-items: end;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--line);
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 280px;
            flex: 1 1 320px;
        }

        .field span {
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
        }

        .input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 15px;
            background: #fff;
        }

        .btn {
            border: 1px solid transparent;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
        }

        .btn--primary {
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            color: #fff;
        }

        .btn--ghost {
            background: #fff;
            border-color: var(--line);
            color: var(--text);
        }

        .content {
            padding: 18px 20px 24px;
        }

        .summary {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(203, 213, 225, 0.75);
            border-radius: 0;
            margin-bottom: 0;
            background: rgba(255, 255, 255, 0.45);
        }

        .summary-divider {
            color: #64748b;
            margin: 0 8px;
        }

        .report-filters {
            display: grid;
            grid-template-columns: repeat(5, minmax(160px, 1fr)) auto;
            gap: 14px;
            align-items: end;
            margin: 14px 0;
        }

        .report-filter {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .report-filter span {
            font-weight: 700;
        }

        .report-filter select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--line);
            border-radius: 9px;
            background: rgba(255, 255, 255, 0.75);
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.08);
        }

        #resetFiltersBtn {
            justify-self: end;
        }

        .report-outcome {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            padding: 14px 16px;
            margin-top: 14px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(248, 250, 252, 0.9));
        }

        .report-outcome__item {
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
        }

        .report-outcome__label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .report-outcome__value {
            font-size: 18px;
            font-weight: 700;
        }

        .qty-pill {
            display: inline-block;
            border-radius: 999px;
            padding: 6px 12px;
            white-space: nowrap;
            box-shadow: 0 0 12px rgba(15, 23, 42, 0.08);
        }

        .qty-pill--success {
            background: #bbf7d0;
        }

        .qty-pill--warning {
            background: #fde68a;
        }

        .qty-pill--info {
            background: #bae6fd;
        }

        .activity-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 9px;
            font-weight: 600;
            background: #e5e7eb;
            color: #334155;
        }

        .activity-badge--short {
            background: #fef3c7;
            color: #92400e;
        }

        .activity-badge--scan {
            background: #dcfce7;
            color: #166534;
        }

        .activity-badge--reset {
            background: #e0f2fe;
            color: #075985;
        }

        .activity-badge--error {
            background: #fee2e2;
            color: #991b1b;
        }

        .error {
            padding: 12px 14px;
            border-radius: 10px;
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid #fecaca;
            margin-bottom: 16px;
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #fff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 960px;
        }

        th,
        td {
            padding: 12px 14px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
            font-size: 14px;
        }

        th {
            position: sticky;
            top: 0;
            background: #f8fafc;
            z-index: 1;
            white-space: nowrap;
        }

        .sort-trigger {
            appearance: none;
            border: 0;
            background: transparent;
            color: inherit;
            font: inherit;
            font-weight: 700;
            padding: 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .sort-trigger:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
            border-radius: 6px;
        }

        .sort-indicator {
            font-size: 11px;
            line-height: 1;
            color: var(--accent-2);
        }

        .muted {
            color: var(--muted);
        }

        .empty {
            padding: 16px;
            color: var(--muted);
            background: #fff;
        }

        .footer-note {
            margin-top: 14px;
            color: var(--muted);
            font-size: 13px;
        }

        @media print {
            body {
                background: #fff;
            }

            .toolbar,
            .footer-note,
            .btn,
            .sort-trigger {
                display: none !important;
            }

            .page {
                max-width: none;
                padding: 0;
            }

            .hero {
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }

            .table-wrap {
                border-radius: 0;
            }
        }

        @media (max-width: 1024px) {
            .report-filters {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }

            #resetFiltersBtn {
                justify-self: stretch;
            }

            .report-outcome {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .report-filters {
                grid-template-columns: 1fr;
            }

            .report-outcome {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="page">
        <div class="hero">
            <div class="hero__top">
                <div class="hero__eyebrow">Read-Only Report</div>
                <h1 class="hero__title">Pick Events</h1>
                <p class="hero__subtitle">View pick verification events for a sales order.</p>
            </div>

            <form class="toolbar" method="get" action="pick_events.php">
                <label class="field" for="order_number">
                    <span>Sales Order #</span>
                    <input class="input" id="order_number" name="order_number" type="text"
                        value="<?= $escape($orderNumber) ?>" placeholder="e.g. SO35858" autocomplete="off">
                </label>
                <button type="submit" class="btn btn--primary">Search</button>
                <?php if ($orderNumber !== ''): ?>
                    <button type="button" class="btn btn--ghost" id="pick-events-csv-btn">Export CSV</button>
                    <button type="button" class="btn btn--ghost" id="pick-events-pdf-btn">Export PDF</button>
                <?php endif; ?>
                <a class="btn btn--ghost" href="verify_pick.php">Back to Verify Pick</a>
            </form>

            <div class="content">
                <?php if ($errorMessage !== ''): ?>
                    <div class="error"><?= $escape($errorMessage) ?></div>
                <?php endif; ?>

                <?php if ($orderNumber !== ''): ?>
                    <!-- <div class="summary">
                        Order Summary:
                        <?= $escape($summary['order_number'] ?? $orderNumber) ?>
                        <span class="summary-divider">|</span>
                        Items: <?= count($items) ?>
                        <span class="summary-divider">|</span>
                        Status: <?= $escape($summary['status'] ?? 'N/A') ?>
                        <span class="summary-divider">|</span>
                        Key User: <?= $escape($summary['key_user'] ?? 'N/A') ?>
                    </div> -->

                    <div class="report-outcome">
                        <div class="report-outcome__item">
                            <span class="report-outcome__label">Order Number</span>
                            <div class="report-outcome__value"><?= $escape($summary['order_number'] ?? $orderNumber) ?>
                            </div>
                        </div>
                        <div class="report-outcome__item">
                            <span class="report-outcome__label">Status</span>
                            <div class="report-outcome__value"><?= $escape($summary['status'] ?? 'N/A') ?></div>
                        </div>
                        <div class="report-outcome__item">
                            <span class="report-outcome__label">Key User</span>
                            <div class="report-outcome__value"><?= $escape($summary['key_user'] ?? 'N/A') ?></div>
                        </div>
                        <div class="report-outcome__item">
                            <span class="report-outcome__label">Total items</span>
                            <div class="report-outcome__value"><?= (int) ($summary['total_items'] ?? 0) ?></div>
                        </div>
                        <div class="report-outcome__item">
                            <span class="report-outcome__label">Elapsed Time</span>
                            <div class="report-outcome__value"><?= $escape($summary['elapsed_time'] ?? '00:00:00') ?></div>
                        </div>
                    </div>

                    <div class="report-filters">
                        <label class="report-filter" for="activityFilter">
                            <span>Activity</span>
                            <select id="activityFilter">
                                <option value="">All Activities</option>
                                <?php foreach ($activities as $activity): ?>
                                    <option value="<?= $escape($activity) ?>"><?= $escape($activity) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="report-filter" for="statusFilter">
                            <span>Status</span>
                            <select id="statusFilter">
                                <option value="">All Statuses</option>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= $escape($status) ?>"><?= $escape($status) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="report-filter" for="itemFilter">
                            <span>Item</span>
                            <select id="itemFilter">
                                <option value="">All Items</option>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?= $escape($item) ?>"><?= $escape($item) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="report-filter" for="modeFilter">
                            <span>Mode</span>
                            <select id="modeFilter">
                                <option value="">All Modes</option>
                                <?php foreach ($modes as $mode): ?>
                                    <option value="<?= $escape($mode) ?>"><?= $escape(ucfirst((string) $mode)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <button type="button" class="btn btn--ghost" id="resetFiltersBtn">Reset Filters</button>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th data-sort-key="event_id"><button type="button" class="sort-trigger">ID <span
                                                class="sort-indicator" aria-hidden="true"></span></button></th>
                                    <th data-sort-key="created_at"><button type="button" class="sort-trigger">Date &amp;
                                            Time <span class="sort-indicator" aria-hidden="true"></span></button></th>
                                    <th data-sort-key="event_type"><button type="button" class="sort-trigger">Activity Type
                                            <span class="sort-indicator" aria-hidden="true"></span></button></th>
                                    <th data-sort-key="item_no"><button type="button" class="sort-trigger">Item Number <span
                                                class="sort-indicator" aria-hidden="true"></span></button></th>
                                    <th data-sort-key="qty_delta"><button type="button" class="sort-trigger">Quantity Change
                                            <span class="sort-indicator" aria-hidden="true"></span></button></th>
                                    <th data-sort-key="handler"><button type="button" class="sort-trigger">Handler <span
                                                class="sort-indicator" aria-hidden="true"></span></button></th>
                                    <th data-sort-key="mode"><button type="button" class="sort-trigger">Mode <span
                                                class="sort-indicator" aria-hidden="true"></span></button></th>
                                    <th data-sort-key="status_details"><button type="button" class="sort-trigger">Status
                                            Details <span class="sort-indicator" aria-hidden="true"></span></button></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($events === []): ?>
                                    <tr>
                                        <td colspan="8" class="empty">No pick events found for this sales order.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($events as $event): ?>
                                        <tr data-event-id="<?= (int) ($event['event_id'] ?? 0) ?>"
                                            data-item="<?= $escape((string) ($event['item_no'] ?? '')) ?>"
                                            data-user="<?= $escape((string) ($event['handler'] ?? '')) ?>"
                                            data-mode="<?= $escape((string) ($event['mode'] ?? '')) ?>"
                                            data-activity="<?= $escape($formatActivity((string) ($event['event_type'] ?? ''))) ?>"
                                            data-status="<?= $escape($formatStatusDetails($event)) ?>">
                                            <td data-sort-value="<?= (int) ($event['event_id'] ?? 0) ?>">
                                                <?= (int) ($event['event_id'] ?? 0) ?>
                                            </td>
                                            <td
                                                data-sort-value="<?= (int) (strtotime((string) ($event['created_at'] ?? '')) ?: 0) ?>">
                                                <?= $escape($formatDateTime((string) ($event['created_at'] ?? ''))) ?>
                                            </td>
                                            <td data-sort-value="<?= $escape(strtolower((string) ($event['event_type'] ?? ''))) ?>">
                                                <span
                                                    class="<?= $escape($activityBadgeClass((string) ($event['event_type'] ?? ''))) ?>">
                                                    <?= $escape($formatActivity((string) ($event['event_type'] ?? ''))) ?>
                                                </span>
                                            </td>
                                            <td data-sort-value="<?= $escape(strtolower((string) ($event['item_no'] ?? ''))) ?>">
                                                <?= $escape($event['item_no']) ?>
                                            </td>
                                            <td data-sort-value="<?= (int) ($event['qty_delta'] ?? 0) ?>">
                                                <span class="<?= $escape($qtyClass((int) ($event['qty_delta'] ?? 0))) ?>">
                                                    <?= $escape($formatQty((int) ($event['qty_delta'] ?? 0))) ?>
                                                </span>
                                            </td>
                                            <td data-sort-value="<?= $escape(strtolower((string) ($event['handler'] ?? ''))) ?>">
                                                <?= $escape($event['handler']) ?>
                                            </td>
                                            <td data-sort-value="<?= $escape(strtolower((string) ($event['mode'] ?? ''))) ?>">
                                                <?= $escape(ucfirst((string) ($event['mode'] ?? ''))) ?>
                                            </td>
                                            <td data-sort-value="<?= $escape(strtolower($formatStatusDetails($event))) ?>">
                                                <?= $escape($formatStatusDetails($event)) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty">Enter a sales order number to load the report.</div>
                <?php endif; ?>

                <div class="footer-note">
                    Source table: <code>pick_verify_events</code>
                </div>
            </div>
        </div>
    </div>
    <?php if ($orderNumber !== ''): ?>
        <script>
            (function () {
                const reportOrderNumber = <?= json_encode($orderNumber !== '' ? $orderNumber : 'report') ?>;
                const csvBtn = document.getElementById('pick-events-csv-btn');
                const pdfBtn = document.getElementById('pick-events-pdf-btn');
                const table = document.querySelector('.table-wrap table');
                const tbody = table ? table.querySelector('tbody') : null;
                const headers = table ? Array.from(table.querySelectorAll('thead th[data-sort-key]')) : [];
                const activityFilter = document.getElementById('activityFilter');
                const statusFilter = document.getElementById('statusFilter');
                const itemFilter = document.getElementById('itemFilter');
                const modeFilter = document.getElementById('modeFilter');
                const resetFiltersBtn = document.getElementById('resetFiltersBtn');
                const state = {
                    key: 'created_at',
                    dir: 'desc',
                };

                if (!pdfBtn) {
                    // continue so sorting still works without the PDF button.
                }

                const updateIndicators = () => {
                    headers.forEach((th) => {
                        const btn = th.querySelector('.sort-trigger');
                        const indicator = th.querySelector('.sort-indicator');
                        const key = th.dataset.sortKey || '';
                        if (!btn || !indicator) {
                            return;
                        }
                        const isActive = key === state.key;
                        indicator.textContent = isActive ? (state.dir === 'asc' ? '^' : 'v') : '';
                        th.setAttribute('aria-sort', isActive ? (state.dir === 'asc' ? 'ascending' : 'descending') : 'none');
                    });
                };

                const sortRows = () => {
                    if (!tbody || !table) {
                        return;
                    }

                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    const colIndexMap = {
                        event_id: 0,
                        created_at: 1,
                        event_type: 2,
                        item_no: 3,
                        qty_delta: 4,
                        handler: 5,
                        mode: 6,
                        status_details: 7,
                    };
                    const columnIndex = colIndexMap[state.key] ?? 0;

                    rows.sort((a, b) => {
                        const aCell = a.children[columnIndex];
                        const bCell = b.children[columnIndex];
                        const aValue = String(aCell?.dataset.sortValue ?? aCell?.textContent ?? '').trim();
                        const bValue = String(bCell?.dataset.sortValue ?? bCell?.textContent ?? '').trim();

                        if (state.key === 'event_id' || state.key === 'qty_delta' || state.key === 'created_at') {
                            const aNum = Number(aValue) || 0;
                            const bNum = Number(bValue) || 0;
                            return state.dir === 'asc' ? aNum - bNum : bNum - aNum;
                        }

                        const comparison = aValue.localeCompare(bValue, undefined, { numeric: true, sensitivity: 'base' });
                        return state.dir === 'asc' ? comparison : -comparison;
                    });

                    rows.forEach((row) => tbody.appendChild(row));
                    updateIndicators();
                    applyFilters();
                };

                const applyFilters = () => {
                    if (!tbody) {
                        return;
                    }

                    const selectedItem = itemFilter ? itemFilter.value : '';
                    const selectedMode = modeFilter ? modeFilter.value : '';
                    const selectedActivity = activityFilter ? activityFilter.value : '';
                    const selectedStatus = statusFilter ? statusFilter.value : '';

                    tbody.querySelectorAll('tr').forEach((row) => {
                        const item = row.getAttribute('data-item') || '';
                        const mode = row.getAttribute('data-mode') || '';
                        const activity = row.getAttribute('data-activity') || '';
                        const status = row.getAttribute('data-status') || '';

                        const itemMatches = !selectedItem || item === selectedItem;
                        const modeMatches = !selectedMode || mode === selectedMode;
                        const activityMatches = !selectedActivity || activity === selectedActivity;
                        const statusMatches = !selectedStatus || status === selectedStatus;

                        row.style.display = itemMatches && modeMatches && activityMatches && statusMatches ? '' : 'none';
                    });
                };

                headers.forEach((th) => {
                    const btn = th.querySelector('.sort-trigger');
                    if (!btn) {
                        return;
                    }

                    btn.addEventListener('click', () => {
                        const key = th.dataset.sortKey || 'created_at';
                        if (state.key === key) {
                            state.dir = state.dir === 'asc' ? 'desc' : 'asc';
                        } else {
                            state.key = key;
                            state.dir = 'asc';
                        }
                        sortRows();
                    });
                });

                updateIndicators();

                if (tbody && tbody.querySelectorAll('tr').length > 1) {
                    sortRows();
                }

                activityFilter?.addEventListener('change', applyFilters);
                statusFilter?.addEventListener('change', applyFilters);
                itemFilter?.addEventListener('change', applyFilters);
                modeFilter?.addEventListener('change', applyFilters);

                resetFiltersBtn?.addEventListener('click', () => {
                    if (activityFilter) activityFilter.value = '';
                    if (statusFilter) statusFilter.value = '';
                    if (itemFilter) itemFilter.value = '';
                    if (modeFilter) modeFilter.value = '';
                    applyFilters();
                });

                if (pdfBtn) {
                    pdfBtn.addEventListener('click', () => {
                        window.print();
                    });
                }

                const csvEscape = (value) => `"${String(value ?? '').replace(/"/g, '""')}"`;

            const downloadCsv = () => {
                if (!table || !tbody) {
                    return;
                }

                const headerLabelMap = {
                    event_id: 'ID',
                    created_at: 'Date & Time',
                    event_type: 'Activity Type',
                    item_no: 'Item Number',
                    qty_delta: 'Quantity Change',
                    handler: 'Handler',
                    mode: 'Mode',
                    status_details: 'Status Details',
                };
                const headers = Array.from(table.querySelectorAll('thead th[data-sort-key]')).map((th) => {
                    const key = th.dataset.sortKey || '';
                    return headerLabelMap[key] || key;
                });
                const rows = Array.from(tbody.querySelectorAll('tr'))
                    .filter((row) => row.style.display !== 'none')
                    .map((row) => Array.from(row.children).map((cell) => cell.textContent.trim()));

                const csv = [
                    headers.map(csvEscape).join(','),
                    ...rows.map((row) => row.map(csvEscape).join(',')),
                ].join('\r\n');

                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `pick-events-${String(reportOrderNumber || 'report').trim() || 'report'}.csv`;
                a.click();
                URL.revokeObjectURL(url);
            };

            csvBtn?.addEventListener('click', downloadCsv);
        })();
    </script>
    <?php endif; ?>
</body>

</html>