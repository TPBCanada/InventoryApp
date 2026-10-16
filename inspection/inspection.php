<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/config_init.php';
require_once __DIR__ . '/../utils/helpers.php';
require_once __DIR__ . '/templates/access_control.php';


if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}

if (!$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

function table_exists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function display_category_name(string $categoryName): string
{
    return preg_replace('/^\d+\.\s*/', '', trim($categoryName)) ?? trim($categoryName);
}

function render_checklist_item_label(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return '';
    }

    if (preg_match('/^([A-F]-\d+)\s*(.*)$/', $label, $matches) !== 1) {
        return h($label);
    }

    $code = (string) $matches[1];
    $rest = trim((string) ($matches[2] ?? ''));
    $letter = strtolower((string) $code[0]);

    return '<span class="checklist-label-code checklist-label-code--' . h($letter) . '">' . h($code) . '</span>'
        . ($rest !== '' ? ' ' . h($rest) : '');
}

$slug = trim((string) ($_GET['type'] ?? 'warehouse_checklist'));
if ($slug === '') {
    $slug = 'warehouse_checklist';
}

$page_class = 'page-inspection page-inspection-checklist';
$css[] = '/inspection/refactor_css/checklist.css';
$js[] = '/inspection/js/checklist.js';
$saveUrl = rtrim((defined('APP_ROOT_URL') ? APP_ROOT_URL : ''), '/') . '/inspection/utils/save_inspection.php';

$title = 'Inspection Checklist';
$templateId = 0;
$items = [];
$groupedItems = [];
$groupedKeys = [];
$errorMessage = null;
$liftTruckOptions = ['Lift Truck 1', 'Lift Truck 2', 'Lift Truck 3'];

$requiredTables = ['checklist_templates', 'checklist_items', 'inspections', 'inspection_responses'];
$missingTables = [];
foreach ($requiredTables as $tableName) {
    if (!table_exists($conn, $tableName)) {
        $missingTables[] = $tableName;
    }
}
$schemaReady = empty($missingTables);

if (!$schemaReady) {
    $dbName = isset($database) ? (string) $database : '';
    if ($dbName === '' && $res = $conn->query('SELECT DATABASE() AS db_name')) {
        if ($row = $res->fetch_assoc()) {
            $dbName = (string) ($row['db_name'] ?? '');
        }
        $res->free();
    }
    $dbLabel = $dbName !== '' ? (' Active DB: ' . $dbName . '.') : '';
    $errorMessage = 'Inspection checklist schema is not installed. Missing tables: ' . implode(', ', $missingTables) . '.' . $dbLabel . ' Run the SQL scripts in /inspection/sql first.';
} else {
    $stmt = $conn->prepare('SELECT id, name FROM checklist_templates WHERE slug = ? LIMIT 1');
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $template = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$template) {
        $errorMessage = 'Checklist template not found for slug: ' . $slug;
    } else {
        $templateId = (int) $template['id'];
        $title = (string) $template['name'];

        $itemStmt = $conn->prepare(
            'SELECT ci.id, ci.label, cic.name AS category_name
             FROM checklist_items ci
             LEFT JOIN checklist_item_categories cic ON cic.id = ci.category_id
             WHERE ci.template_id = ? AND ci.is_active = 1
             ORDER BY ci.sort_order ASC, ci.id ASC'
        );
        $itemStmt->bind_param('i', $templateId);
        $itemStmt->execute();
        $itemRows = $itemStmt->get_result();

        while ($row = $itemRows->fetch_assoc()) {
            $items[] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'category_name' => isset($row['category_name']) ? (string) $row['category_name'] : '',
            ];
        }
        $itemStmt->close();

        if (!$items) {
            $errorMessage = 'No active checklist items found for this template.';
        } else {
            foreach ($items as $item) {
                $categoryName = trim((string) ($item['category_name'] ?? ''));
                if ($categoryName === '') {
                    $categoryName = 'Uncategorized';
                }
                if (!isset($groupedItems[$categoryName])) {
                    $groupedItems[$categoryName] = [];
                }
                $groupedItems[$categoryName][] = $item;
            }
            $groupedKeys = array_keys($groupedItems);
        }
    }
}

if ($errorMessage !== null) {
    http_response_code(503);
}

ob_start();
?>

<div class="card">
    <?php if ($errorMessage !== null): ?>
        <h1><?= h($title); ?></h1>
        <p class="muted" style="color:#b00020;"><?= h($errorMessage); ?></p>
    <?php else: ?>
        <div class="checklist-sticky-shell">
            <div class="checklist-sticky-header">
                <h2><?= h($title); ?></h2>
            </div>

            <form id="checklistForm" method="post" action="<?= h($saveUrl); ?>" data-template-id="<?= $templateId; ?>" data-save-url="<?= h($saveUrl); ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>" />
                <input type="hidden" name="template_id" value="<?= $templateId; ?>" />
                <input type="hidden" name="inspection_type" value="<?= h($title); ?>" />
                <div class="checklist-progress" id="checklistProgress" aria-live="polite">
                    <div class="checklist-progress__meta">
                        <strong id="checklistProgressLabel">0 / <?= count($items); ?> items complete</strong>
                        <span id="checklistProgressPercent">0%</span>
                    </div>
                    <div class="checklist-progress__bar" aria-hidden="true">
                        <span id="checklistProgressFill"></span>
                    </div>
                </div>
                <?php if ($slug === 'lift_truck_checklist'): ?>
                    <div class="checklist-intro">
                        <label for="truck_label">Lift Truck</label>
                        <select id="truck_label" name="truck_label" required>
                            <option value="">Select a lift truck before starting</option>
                            <?php foreach ($liftTruckOptions as $truckLabel): ?>
                                <option value="<?= h($truckLabel); ?>"><?= h($truckLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="muted">Choose the truck being inspected before completing the checklist.</p>
                    </div>
                <?php endif; ?>
                <?php if ($groupedKeys): ?>
                    <div class="checklist-tabs" data-checklist-tabs>
                        <div class="checklist-tablist" role="tablist" aria-label="Checklist categories">
                            <?php foreach ($groupedKeys as $index => $categoryName): ?>
                                <?php $tabId = 'checklist-tab-' . $index; ?>
                                <?php $panelId = 'checklist-panel-' . $index; ?>
                                <button type="button" class="checklist-category-button checklist-tab<?= $index === 0 ? ' is-active' : ''; ?>"
                                    id="<?= h($tabId); ?>" role="tab" aria-selected="<?= $index === 0 ? 'true' : 'false'; ?>"
                                    aria-controls="<?= h($panelId); ?>" tabindex="<?= $index === 0 ? '0' : '-1'; ?>">
                                    <?= h(display_category_name($categoryName)); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>

                        <?php foreach ($groupedKeys as $index => $categoryName): ?>
                            <?php $categoryItems = $groupedItems[$categoryName]; ?>
                            <?php $tabId = 'checklist-tab-' . $index; ?>
                            <?php $panelId = 'checklist-panel-' . $index; ?>
                            <section class="checklist-panel<?= $index === 0 ? ' is-active' : ''; ?>" id="<?= h($panelId); ?>"
                                role="tabpanel" aria-labelledby="<?= h($tabId); ?>" <?= $index === 0 ? '' : 'hidden'; ?>>
                                <div class="checklist-group">
                                    <div class="checklist-group__header">
                                        <h2><?= h(display_category_name($categoryName)); ?></h2>
                                    </div>
                                    <ul class="checklist">
                                        <?php foreach ($categoryItems as $item): ?>
                                            <li>
                                                <div class="checklist-item-main">
                                                    <div class="checklist-item-label"><?= render_checklist_item_label((string) $item['label']); ?></div>
                                                    <div class="checklist-status" role="group"
                                                        aria-label="Inspection result for <?= h($item['label']); ?>">
                                                        <input type="hidden" name="item_status_<?= $item['id']; ?>"
                                                            data-item-id="<?= $item['id']; ?>" value="" />
                                                        <button type="button" class="checklist-status__option" data-status-option="pass"
                                                            data-item-status-id="<?= $item['id']; ?>">Pass</button>
                                                        <button type="button" class="checklist-status__option" data-status-option="fail"
                                                            data-item-status-id="<?= $item['id']; ?>">Fail</button>
                                                        <button type="button" class="checklist-status__option" data-status-option="na"
                                                            data-item-status-id="<?= $item['id']; ?>">N/A</button>
                                                    </div>
                                                </div>
                                                <textarea class="checklist-detail-field" name="item_note_<?= $item['id']; ?>"
                                                    data-item-note-id="<?= $item['id']; ?>" rows="2" maxlength="1000"
                                                    placeholder="Add a note for this checklist item." hidden></textarea>
                                                <div class="checklist-media checklist-detail-field"
                                                    data-item-media-wrap="<?= $item['id']; ?>" hidden>
                                                    <label class="checklist-media__button" for="media_item_<?= $item['id']; ?>">Take
                                                        Photo</label>
                                                    <input id="media_item_<?= $item['id']; ?>" class="checklist-media__input"
                                                        type="file" name="media_item_<?= $item['id']; ?>"
                                                        data-item-media-id="<?= $item['id']; ?>" accept="image/*"
                                                        capture="environment" />
                                                    <div class="checklist-media__filename" data-item-media-name="<?= $item['id']; ?>">No
                                                        photo selected</div>
                                                    <div class="checklist-media__preview" data-item-media-preview="<?= $item['id']; ?>"
                                                        hidden></div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="actions" id="finalizeSectionLauncher">
                    <div class="actions__nav">
                        <button type="button" id="prevCategoryBtn" class="btn btn--ghost">Previous Category</button>
                        <span id="categoryStepText" class="status-pill">Category 1 of
                            <?= max(1, count($groupedKeys)); ?></span>
                        <button type="button" id="nextCategoryBtn" class="btn">Next Category</button>
                    </div>
                    <!-- <button type="button" id="showFinalizeBtn" class="btn btn--ghost">Finalize &amp; Sign</button> -->
                </div>

                <div class="actions" id="finalizeSection" hidden>
                    <label for="comments">Comments:</label>
                    <textarea id="comments" name="comments" rows="4" maxlength="2000"
                        placeholder="Add details about any issues found during this checklist."></textarea>
                    <label for="recipient_email">Email report to</label>
                    <input id="recipient_email" name="recipient_email" type="email" autocomplete="email"
                        placeholder="Leave blank to use the configured inspection email"
                        value="olivia@turningpointbrands.ca; lavalwarehouse@turningpointbrands.ca" multiple />
                    <!-- <p class="muted">Optional. Separate multiple addresses with commas. If left blank, the configured
                        inspection recipient is used.</p> -->
                    <div class="actions__submit">
                        <button type="submit" id="saveBtn">Finalize &amp; Sign</button>
                        <span id="statusText" class="status-pill">Awaiting Submission</span>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<a href="../inspection/dashboard.php" class="back-to-main btn btn-ghost">
    &larr; Back to Dashboard
</a>

<?php
$content = ob_get_clean();
include __DIR__ . '/templates/layout.php';
