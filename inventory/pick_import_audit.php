<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

require_once __DIR__ . '/dbinv.php';
require_once __DIR__ . '/utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';
require_once __DIR__ . '/templates/access_control.php';

if (!isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit;
}



$username = (string) ($_SESSION['username'] ?? '');
$role_id = (int) ($_SESSION['role_id'] ?? 0);

if (!ac_can_access_page($role_id, 'pick_import_audit.php', $page_permissions_by_role)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Connection failed: ' . mysqli_connect_error());
}

function pickImportAuditTableExists(mysqli $conn): bool
{
    $stmt = $conn->prepare('
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
        LIMIT 1
    ');
    if (!$stmt) {
        return false;
    }
    $table = 'pick_import_audits';
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    return $exists;
}

function pickImportAuditH(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}


require_once __DIR__ . '/utils/pick_import_snapshot.php';

function pickAuditCell($value): string
{
    return pickImportAuditH(is_array($value) || is_object($value)
        ? (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
        : (string) $value);
}

$search = trim((string) ($_GET['q'] ?? ''));

$page = max(1, (int) ($_GET['page'] ?? 1));
$selectedId = max(0, (int) ($_GET['run'] ?? 0));
$perPage = 30;
$groups = [];
$runs = [];
$selected = null;
$snapshot = null;
$snapshotNote = '';
$error = '';
$totalGroups = 0;
$totalPages = 1;
$auditsReady = pickImportAuditTableExists($conn);

$sourceExpression = "COALESCE(NULLIF(source_name, ''), NULLIF(source_file, ''), '(Unnamed source)')";
if ($auditsReady && $search !== '') {
    try {
        $whereSql = "WHERE sales_order_numbers LIKE CONCAT('%', ?, '%')";
        $stmt = $conn->prepare("SELECT COUNT(*) FROM (SELECT {$sourceExpression} AS filename FROM pick_import_audits {$whereSql} GROUP BY {$sourceExpression}) AS sources");
        $stmt->bind_param('s', $search);
        $stmt->execute();
        $totalGroups = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        $totalPages = max(1, (int) ceil($totalGroups / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $stmt = $conn->prepare("SELECT {$sourceExpression} AS filename, GROUP_CONCAT(DISTINCT sales_order_numbers SEPARATOR ', ') AS sales_order, MAX(id) AS latest_id, MAX(created_at) AS latest_at FROM pick_import_audits {$whereSql} GROUP BY {$sourceExpression} ORDER BY latest_at DESC, latest_id DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('sii', $search, $perPage, $offset);
        $stmt->execute();
        $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if ($selectedId === 0 && $groups !== []) {
            $selectedId = (int) $groups[0]['latest_id'];
        }
        if ($selectedId > 0) {
            $stmt = $conn->prepare("SELECT *, {$sourceExpression} AS filename FROM pick_import_audits {$whereSql} AND id = ?");
            $stmt->bind_param('si', $search, $selectedId);
            $stmt->execute();
            $selected = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            // Keep the selected run within the current filename results.
            if ($selected && !in_array($selected['filename'], array_column($groups, 'filename'), true)) {
                $selected = null;
            }
        }
        if ($selected) {
            $stmt = $conn->prepare("SELECT id, created_at FROM pick_import_audits {$whereSql} AND {$sourceExpression} = ? ORDER BY created_at DESC, id DESC");
            $stmt->bind_param('ss', $search, $selected['filename']);
            $stmt->execute();
            $runs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $details = json_decode((string) ($selected['details_json'] ?? ''), true);
            if (is_array($details['source_snapshot'] ?? null)) {
                $snapshot = pickAuditFilterSnapshotColumns($details['source_snapshot']);
                $snapshotNote = ($snapshot['kind'] ?? '') === 'parsed_items'
                    ? 'Saved parsed item values. Document formatting and original column names were not retained by this upload parser.'
                    : 'Saved at parsing, before duplicate removal or import changes. Showing the five audit columns used for pick review.';
            } else {
                try {
                    $snapshot = pickAuditRecoverSnapshot($selected);
                    $snapshotNote = 'Read from the archived spreadsheet and verified against the checksum recorded for this run. Showing the five audit columns used for pick review.';
                } catch (Throwable $e) {
                    $snapshotNote = $e instanceof RuntimeException ? $e->getMessage() : 'The archived spreadsheet could not be read.';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Pick source rows: ' . $e->getMessage());
        $error = 'Import records could not be loaded. Please try again.';
    }
}

function pickAuditUrl(string $search, int $page, int $run = 0): string
{
    return '?' . http_build_query(['q' => $search, 'page' => $page, 'run' => $run]);
}

$title = 'Pick Source Rows';
$page_class = 'page-import-audit';
$footer_js = '';
ob_start();
?>
<style>
.audit-shell { display: grid; gap: 1rem; padding: 1.25rem 0 2rem; }
.audit-header, .audit-toolbar, .audit-pager { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; }
.audit-header h1 { margin: 0; }
.audit-muted { color: #64748b; font-size: .88rem; }
.audit-search { display: flex; flex-wrap: wrap; gap: .65rem; align-items: end; }
.audit-search label { display: grid; gap: .3rem; flex: 1; min-width: 240px; }
.audit-source-card { padding: 1rem; border: 1px solid #cbd5e1; border-radius: 12px; background: #fff; min-width: 0; }
.audit-source-card.is-selected { border-color: #2563eb; }
.audit-filename, .audit-sales { font-size: 1.05rem; font-weight: 700; overflow-wrap: anywhere; margin: 0; }
.audit-table-wrap { overflow: auto; max-height: 70vh; margin-top: 1rem; }
.audit-rows { width: 100%; border-collapse: collapse; }
.audit-rows th, .audit-rows td { padding: .55rem .7rem; border: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
.audit-rows th { position: sticky; top: 0; background: #f1f5f9; z-index: 1; white-space: nowrap; }
.audit-rows td { white-space: pre-wrap; min-width: 90px; }
.audit-rows tbody tr:nth-child(even) { background: #f8fafc; }
.audit-run-picker { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: 1rem; }
.audit-empty { padding: 1rem; background: #f8fafc; border: 1px dashed #cbd5e1; }
</style>
<section class="audit-shell">
    <header class="audit-header">
        <div><h1>Pick Source Rows</h1><p>Find a source file and review its line items as captured during parsing.</p></div>
        <a class="btn btn--ghost" href="verify_pick.php">Back to Verify Pick</a>
    </header>
    <form method="get" class="audit-search">
        <label>Sales order number<input class="input" type="search" name="q" value="<?= pickImportAuditH($search) ?>" placeholder="Search sales order number" /></label>
        <button class="btn btn--primary" type="submit">Search</button>
        <a class="btn btn--ghost" href="pick_import_audit.php">Reset</a>
    </form>
    <?php if (!$auditsReady): ?>
        <p class="audit-empty">The pick_import_audits table is not available in this database yet.</p>
    <?php elseif ($search === ''): ?>
        <p class="audit-empty">Enter an order number or partial order number to load source files.</p>
    <?php elseif ($error !== ''): ?>
        <p class="audit-empty" role="alert"><?= pickImportAuditH($error) ?></p>
    <?php elseif ($groups === []): ?>
        <p class="audit-empty">No source files match your sales order search.</p>
    <?php else: ?>
        <div class="audit-pager">
            <span><?= $totalGroups ?> source file(s) · Page <?= $page ?> of <?= $totalPages ?></span>
            <div>
                <?php if ($page > 1): ?><a class="btn btn--ghost" href="<?= pickImportAuditH(pickAuditUrl($search, $page - 1)) ?>">Previous</a><?php endif; ?>
                <?php if ($page < $totalPages): ?><a class="btn btn--ghost" href="<?= pickImportAuditH(pickAuditUrl($search, $page + 1)) ?>">Next</a><?php endif; ?>
            </div>
        </div>
        <?php foreach ($groups as $group): ?>
            <?php $active = $selected && $selected['filename'] === $group['filename']; ?>
            <section class="audit-source-card<?= $active ? ' is-selected' : '' ?>" id="source-<?= (int) $group['latest_id'] ?>">
                <div class="audit-toolbar">
                    <h2 class="audit-filename">
                        <?php if ($active): ?>
                            <?= pickImportAuditH((string) $group['filename']) ?>
                        <?php else: ?>
                            <a href="<?= pickImportAuditH(pickAuditUrl($search, $page, (int) $group['latest_id'])) ?>#source-<?= (int) $group['latest_id'] ?>"><?= pickImportAuditH((string) $group['filename']) ?></a>
                        <?php endif; ?>
                    </h2>
                    <h2 class="audit-sales"><?= pickImportAuditH((string) ($group['sales_order'] ?? '')) ?></h2>
                </div>
                <?php if ($active): ?>
                    <form method="get" class="audit-run-picker" action="#source-<?= (int) $group['latest_id'] ?>">
                        <input type="hidden" name="q" value="<?= pickImportAuditH($search) ?>" />
                        <input type="hidden" name="page" value="<?= $page ?>" />
                        <label for="audit-run">Import run</label>
                        <select class="input" name="run" id="audit-run">
                            <?php foreach ($runs as $run): ?>
                                <option value="<?= (int) $run['id'] ?>"<?= (int) $run['id'] === (int) $selected['id'] ? ' selected' : '' ?>><?= pickImportAuditH((string) $run['created_at']) ?> · #<?= (int) $run['id'] ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn--primary" type="submit">Show run</button>
                    </form>
                    <p class="audit-muted"><?= pickImportAuditH($snapshotNote) ?></p>
                    <?php if (is_array($snapshot) && !empty($snapshot['headers']) && !empty($snapshot['rows'])): ?>
                        <p><?= count($snapshot['rows']) ?> source row(s)</p>
                        <div class="audit-table-wrap" tabindex="0" aria-label="Source line items">
                            <table class="audit-rows">
                                <thead><tr>
                                    <?php foreach ($snapshot['headers'] as $index => $heading): ?><th><?= pickAuditCell((string) $heading) ?></th><?php endforeach; ?>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($snapshot['rows'] as $sourceRow): ?>
                                        <tr>
                                            <?php foreach ($snapshot['headers'] as $index => $heading): ?><td><?= pickAuditCell($sourceRow['cells'][$index] ?? null) ?></td><?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php elseif (is_array($snapshot)): ?>
                        <p class="audit-empty">This source contains no saved line items.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
include __DIR__ . '/templates/layout.php';
