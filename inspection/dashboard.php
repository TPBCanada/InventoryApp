<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';

include 'dbinv.php';
require_once __DIR__ . '/utils/helpers.php';


if (!isset($_SESSION['username']) || (int) ($_SESSION['role_id'] ?? 0) < 1) {
  exit;
}

$username = (string) ($_SESSION['username'] ?? 'User');
$role_id = (int) ($_SESSION['role_id'] ?? 0);

$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
require_once __DIR__ . '/templates/access_control.php';

if (!$conn) {
  die('Connection failed: ' . mysqli_connect_error());
}

function json_response(int $status, array $payload): void
{
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function format_last_completed(string $raw): string
{
  if ($raw === '') {
    return 'No inspections yet';
  }
  $ts = strtotime($raw);
  return $ts ? date('M j, Y g:i A', $ts) : $raw;
}

function normalize_marker_type(string $marker_type): string
{
  $type = strtolower(trim($marker_type));
  if ($type === 'damage' || $type === 'caution') {
    return 'advisory';
  }
  if (!in_array($type, ['advisory', 'fire_extinguisher', 'emergency_exit'], true)) {
    return 'advisory';
  }
  return $type;
}

function classify_issue_urgency(string $updatedAt): array
{
  $timestamp = strtotime($updatedAt);
  if (!$timestamp) {
    return ['label' => 'Action Required', 'class' => 'critical'];
  }

  $ageHours = (time() - $timestamp) / 3600;
  if ($ageHours >= 72) {
    return ['label' => 'Action Required', 'class' => 'critical'];
  }
  if ($ageHours >= 24) {
    return ['label' => 'Warning', 'class' => 'warning'];
  }
  return ['label' => 'New', 'class' => 'fresh'];
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
  $safeTable = $conn->real_escape_string($table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function table_exists(mysqli $conn, string $table): bool
{
  $safeTable = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function append_damage_zone_event(mysqli $conn, int $zone_id, string $event_type, array $payload, string $username): void
{
  $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $stmt = $conn->prepare(
    'INSERT INTO inspection_damage_zone_events (zone_id, event_type, payload_json, created_by) VALUES (?, ?, ?, ?)'
  );
  $stmt->bind_param('isss', $zone_id, $event_type, $payload_json, $username);
  $stmt->execute();
  $stmt->close();
}

function fetch_damage_zone(mysqli $conn, int $zone_id): ?array
{
  $stmt = $conn->prepare(
    'SELECT id, marker_type, severity, x_pct, y_pct, notes, created_by, created_at FROM inspection_damage_zones WHERE id = ? LIMIT 1'
  );
  $stmt->bind_param('i', $zone_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();
  return $row;
}

$session_csrf = (string) ($_SESSION['csrf_token'] ?? '');
if ($session_csrf === '') {
  $session_csrf = bin2hex(random_bytes(32));
  $_SESSION['csrf_token'] = $session_csrf;
}
$dashboard_flash = $_SESSION['inspection_dashboard_flash'] ?? null;
unset($_SESSION['inspection_dashboard_flash']);
// Any signed-in user who can open the dashboard (role 1+, checked above) can review and close issues.
$can_manage_checklist_issues = $role_id >= 1;
$selected_issue_checklist = trim((string) ($_GET['issue_checklist'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string) ($_POST['action'] ?? '');
  if ($action !== '') {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
      $conn->set_charset('utf8mb4');

      $token = (string) ($_POST['csrf_token'] ?? '');
      if ($token === '' || !hash_equals($session_csrf, $token)) {
        json_response(403, ['ok' => false, 'error' => 'Invalid CSRF token']);
      }
      if ($action === 'resolve_checklist_issue') {
        if (!$can_manage_checklist_issues) {
          $_SESSION['inspection_dashboard_flash'] = ['type' => 'error', 'message' => 'You do not have permission to address checklist issues.'];
          header('Location: ' . $BASE_URL . '/dashboard.php');
          exit;
        }
        if (!table_exists($conn, 'inspection_responses')) {
          $_SESSION['inspection_dashboard_flash'] = ['type' => 'error', 'message' => 'Checklist response table is missing.'];
          header('Location: ' . $BASE_URL . '/dashboard.php');
          exit;
        }

        $response_id = isset($_POST['response_id']) ? (int) $_POST['response_id'] : 0;
        if ($response_id <= 0 || !column_exists($conn, 'inspection_responses', 'id')) {
          $_SESSION['inspection_dashboard_flash'] = ['type' => 'error', 'message' => 'Invalid checklist response selected.'];
          header('Location: ' . $BASE_URL . '/dashboard.php');
          exit;
        }

        $resolution_note = trim((string) ($_POST['resolution_note'] ?? ''));
        if (strlen($resolution_note) > 500) {
          $resolution_note = substr($resolution_note, 0, 500);
        }

        $note_column = null;
        foreach (['notes', 'note', 'comments', 'comment'] as $candidate) {
          if (column_exists($conn, 'inspection_responses', $candidate)) {
            $note_column = $candidate;
            break;
          }
        }

        $sql = 'UPDATE inspection_responses SET is_checked = 1';
        $types = '';
        $params = [];

        if (column_exists($conn, 'inspection_responses', 'updated_at')) {
          $sql .= ', updated_at = NOW()';
        }
        if ($note_column !== null && $resolution_note !== '') {
          $resolution_entry = '[Addressed by ' . $username . ' on ' . date('Y-m-d H:i') . '] ' . $resolution_note;
          $sql .= ", `{$note_column}` = CONCAT(COALESCE(`{$note_column}`, ''), CASE WHEN COALESCE(`{$note_column}`, '') = '' THEN '' ELSE '\n' END, ?)";
          $types .= 's';
          $params[] = $resolution_entry;
        }

        $sql .= ' WHERE id = ?';
        $types .= 'i';
        $params[] = $response_id;

        $resolve_stmt = $conn->prepare($sql);
        $resolve_stmt->bind_param($types, ...$params);
        $resolve_stmt->execute();
        $resolved_rows = $resolve_stmt->affected_rows;
        $resolve_stmt->close();

        $_SESSION['inspection_dashboard_flash'] = [
          'type' => $resolved_rows > 0 ? 'success' : 'error',
          'message' => $resolved_rows > 0 ? 'Checklist issue marked as addressed.' : 'Checklist issue could not be updated.',
        ];
        header('Location: ' . $BASE_URL . '/dashboard.php');
        exit;
      }
      if (!table_exists($conn, 'inspection_damage_zones') || !table_exists($conn, 'inspection_damage_zone_events')) {
        json_response(503, ['ok' => false, 'error' => 'Damage zone schema missing. Run SQL migration first.']);
      }

      if ($action === 'save_damage_zone' || $action === 'update_damage_zone') {
        $x_pct = isset($_POST['x_pct']) ? (float) $_POST['x_pct'] : -1;
        $y_pct = isset($_POST['y_pct']) ? (float) $_POST['y_pct'] : -1;
        if ($x_pct < 0 || $x_pct > 100 || $y_pct < 0 || $y_pct > 100) {
          json_response(422, ['ok' => false, 'error' => 'Invalid map coordinates']);
        }

        $severity = strtolower(trim((string) ($_POST['severity'] ?? 'medium')));
        if (!in_array($severity, ['low', 'medium', 'high'], true)) {
          $severity = 'medium';
        }

        $marker_type = normalize_marker_type((string) ($_POST['marker_type'] ?? 'advisory'));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if ($notes === '') {
          $notes = null;
        }
        if ($notes !== null && strlen($notes) > 500) {
          $notes = substr($notes, 0, 500);
        }
      }

      if ($action === 'save_damage_zone') {
        $insert = $conn->prepare(
          'INSERT INTO inspection_damage_zones (marker_type, severity, x_pct, y_pct, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->bind_param('ssddss', $marker_type, $severity, $x_pct, $y_pct, $notes, $username);
        $insert->execute();
        $new_id = (int) $conn->insert_id;
        $insert->close();

        append_damage_zone_event($conn, $new_id, 'created', [
          'marker_type' => $marker_type,
          'severity' => $severity,
          'x_pct' => $x_pct,
          'y_pct' => $y_pct,
          'notes' => $notes,
        ], $username);

        $saved = fetch_damage_zone($conn, $new_id) ?: [];
        json_response(200, ['ok' => true, 'zone' => $saved]);
      }

      if ($action === 'update_damage_zone') {
        $zone_id = isset($_POST['zone_id']) ? (int) $_POST['zone_id'] : 0;
        if ($zone_id <= 0) {
          json_response(422, ['ok' => false, 'error' => 'Invalid zone id']);
        }
        $before = fetch_damage_zone($conn, $zone_id);
        if (!$before) {
          json_response(404, ['ok' => false, 'error' => 'Zone not found']);
        }

        $update = $conn->prepare(
          'UPDATE inspection_damage_zones SET marker_type = ?, severity = ?, x_pct = ?, y_pct = ?, notes = ? WHERE id = ?'
        );
        $update->bind_param('ssddsi', $marker_type, $severity, $x_pct, $y_pct, $notes, $zone_id);
        $update->execute();
        $update->close();

        append_damage_zone_event($conn, $zone_id, 'updated', [
          'before' => $before,
          'after' => [
            'marker_type' => $marker_type,
            'severity' => $severity,
            'x_pct' => $x_pct,
            'y_pct' => $y_pct,
            'notes' => $notes,
          ],
        ], $username);

        $saved = fetch_damage_zone($conn, $zone_id) ?: [];
        json_response(200, ['ok' => true, 'zone' => $saved]);
      }

      if ($action === 'clear_damage_zone') {
        $zone_id = isset($_POST['zone_id']) ? (int) $_POST['zone_id'] : 0;
        if ($zone_id <= 0) {
          json_response(422, ['ok' => false, 'error' => 'Invalid zone id']);
        }
        $existing = fetch_damage_zone($conn, $zone_id);
        if (!$existing) {
          json_response(404, ['ok' => false, 'error' => 'Zone not found']);
        }
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '') {
          $reason = 'Cleared';
        }
        append_damage_zone_event($conn, $zone_id, 'cleared', ['reason' => $reason], $username);
        json_response(200, ['ok' => true, 'zone_id' => $zone_id]);
      }

      if ($action === 'get_damage_zone_events') {
        $zone_id = isset($_POST['zone_id']) ? (int) $_POST['zone_id'] : 0;
        if ($zone_id <= 0) {
          json_response(422, ['ok' => false, 'error' => 'Invalid zone id']);
        }
        $events_stmt = $conn->prepare(
          'SELECT id, zone_id, event_type, payload_json, created_by, created_at
           FROM inspection_damage_zone_events
           WHERE zone_id = ?
           ORDER BY id DESC
           LIMIT 50'
        );
        $events_stmt->bind_param('i', $zone_id);
        $events_stmt->execute();
        $events_res = $events_stmt->get_result();
        $events = [];
        while ($row = $events_res->fetch_assoc()) {
          $row['payload'] = json_decode((string) ($row['payload_json'] ?? ''), true);
          unset($row['payload_json']);
          $events[] = $row;
        }
        $events_stmt->close();
        json_response(200, ['ok' => true, 'events' => $events]);
      }

      json_response(400, ['ok' => false, 'error' => 'Unsupported action']);
    } catch (Throwable $e) {
      json_response(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
  }
}



$payload = [
  'schema_ready' => false,
  'generated_at' => date('c'),
  'csrf_token' => $session_csrf,
  'selected_issue_checklist' => $selected_issue_checklist,
  'damage_zone' => [
    'save_url' => $BASE_URL . '/dashboard.php',
    'zones' => [],
  ],
  'summary' => [
    'inspections_30d' => 0,
    'active_checklists_30d' => 0,
    'responses_30d' => 0,
    'issues_30d' => 0,
    'issues_addressed_30d' => 0,
    'pass_rate_30d' => 0,
    'inspections_7d' => 0,
    'open_issues_total' => 0,
    'last_completed_at' => '',
    'last_completed_checklist' => '',
  ],
  'by_template' => [],
  'daily_pass_rate' => [],
  'top_issues' => [],
  'open_issues' => [],
  'category_matrix' => [],
];

$last_completed_by_slug = [];

$required_tables = ['inspections', 'inspection_responses', 'checklist_items', 'checklist_templates', 'inspection_damage_zones', 'inspection_damage_zone_events'];
$missing_tables = [];
foreach ($required_tables as $table_name) {
  if (!table_exists($conn, $table_name)) {
    $missing_tables[] = $table_name;
  }
}

if (empty($missing_tables)) {
  $payload['schema_ready'] = true;

  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
  try {
    $conn->set_charset('utf8mb4');

    $latest_events = [];
    if (table_exists($conn, 'inspection_damage_zone_events')) {
      $latest_res = $conn->query(
        'SELECT e.zone_id, e.event_type
         FROM inspection_damage_zone_events e
         JOIN (
           SELECT zone_id, MAX(id) AS max_id
           FROM inspection_damage_zone_events
           GROUP BY zone_id
         ) last_e ON e.id = last_e.max_id'
      );
      while ($er = $latest_res->fetch_assoc()) {
        $latest_events[(int) ($er['zone_id'] ?? 0)] = (string) ($er['event_type'] ?? '');
      }
    }

    $zones_res = $conn->query('SELECT id, marker_type, severity, x_pct, y_pct, notes, created_by, created_at FROM inspection_damage_zones ORDER BY created_at DESC LIMIT 400');
    while ($z = $zones_res->fetch_assoc()) {
      $zone_id = (int) ($z['id'] ?? 0);
      $last_event_type = (string) ($latest_events[$zone_id] ?? '');
      $is_active = ($last_event_type !== 'cleared');
      $payload['damage_zone']['zones'][] = [
        'id' => $zone_id,
        'marker_type' => (string) ($z['marker_type'] ?? 'damage'),
        'severity' => (string) ($z['severity'] ?? 'medium'),
        'x_pct' => (float) ($z['x_pct'] ?? 0),
        'y_pct' => (float) ($z['y_pct'] ?? 0),
        'notes' => (string) ($z['notes'] ?? ''),
        'created_by' => (string) ($z['created_by'] ?? ''),
        'created_at' => (string) ($z['created_at'] ?? ''),
        'is_active' => $is_active ? 1 : 0,
        'last_event_type' => $last_event_type,
      ];
    }

    $summary_sql = "
      SELECT
        COUNT(DISTINCT CASE WHEN ir.updated_at >= (NOW() - INTERVAL 30 DAY) THEN ir.inspection_id END) AS inspections_30d,
        COUNT(DISTINCT CASE WHEN ir.updated_at >= (NOW() - INTERVAL 30 DAY) THEN ci.template_id END) AS active_checklists_30d,
        SUM(CASE WHEN ir.updated_at >= (NOW() - INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS responses_30d,
        SUM(CASE WHEN ir.updated_at >= (NOW() - INTERVAL 30 DAY) AND ir.is_checked = 0 THEN 1 ELSE 0 END) AS issues_30d,
        SUM(CASE WHEN ir.updated_at >= (NOW() - INTERVAL 30 DAY) AND ir.is_checked = 1 THEN 1 ELSE 0 END) AS issues_addressed_30d,
        ROUND(100 * AVG(CASE WHEN ir.updated_at >= (NOW() - INTERVAL 30 DAY) THEN ir.is_checked END), 1) AS pass_rate_30d,
        COUNT(DISTINCT CASE WHEN ir.updated_at >= (NOW() - INTERVAL 7 DAY) THEN ir.inspection_id END) AS inspections_7d,
        SUM(ir.is_checked = 0) AS open_issues_total
      FROM inspection_responses ir
      JOIN checklist_items ci ON ci.id = ir.item_id
    ";

    $summary_row = $conn->query($summary_sql)->fetch_assoc();
    if ($summary_row) {
      $payload['summary'] = [
        'inspections_30d' => (int) ($summary_row['inspections_30d'] ?? 0),
        'active_checklists_30d' => (int) ($summary_row['active_checklists_30d'] ?? 0),
        'responses_30d' => (int) ($summary_row['responses_30d'] ?? 0),
        'issues_30d' => (int) ($summary_row['issues_30d'] ?? 0),
        'issues_addressed_30d' => (int) ($summary_row['issues_addressed_30d'] ?? 0),
        'pass_rate_30d' => (float) ($summary_row['pass_rate_30d'] ?? 0),
        'inspections_7d' => (int) ($summary_row['inspections_7d'] ?? 0),
        'open_issues_total' => (int) ($summary_row['open_issues_total'] ?? 0),
        'last_completed_at' => '',
        'last_completed_checklist' => '',
      ];
    }

    $last_sql = "
      SELECT ir.inspection_id, MAX(ir.updated_at) AS completed_at, ct.name AS checklist_name
      FROM inspection_responses ir
      JOIN checklist_items ci ON ci.id = ir.item_id
      JOIN checklist_templates ct ON ct.id = ci.template_id
      GROUP BY ir.inspection_id, ct.name
      ORDER BY completed_at DESC
      LIMIT 1
    ";

    $last_row = $conn->query($last_sql)->fetch_assoc();
    if ($last_row) {
      $payload['summary']['last_completed_at'] = (string) ($last_row['completed_at'] ?? '');
      $payload['summary']['last_completed_checklist'] = (string) ($last_row['checklist_name'] ?? '');
    }

    $last_by_slug_sql = "
      SELECT ct.slug, MAX(ir.updated_at) AS completed_at
      FROM inspection_responses ir
      JOIN checklist_items ci ON ci.id = ir.item_id
      JOIN checklist_templates ct ON ct.id = ci.template_id
      GROUP BY ct.slug
    ";

    $last_by_slug_res = $conn->query($last_by_slug_sql);
    while ($row = $last_by_slug_res->fetch_assoc()) {
      $slug = (string) ($row['slug'] ?? '');
      if ($slug === '') {
        continue;
      }
      $last_completed_by_slug[$slug] = format_last_completed((string) ($row['completed_at'] ?? ''));
    }

    $template_sql = "
      SELECT
        ct.name AS checklist_name,
        ct.slug AS checklist_slug,
        COUNT(DISTINCT ir.inspection_id) AS inspections,
        SUM(ir.is_checked = 0) AS issues,
        ROUND(100 * AVG(ir.is_checked), 1) AS pass_rate_pct
      FROM inspection_responses ir
      JOIN checklist_items ci ON ci.id = ir.item_id
      JOIN checklist_templates ct ON ct.id = ci.template_id
      GROUP BY ct.id, ct.name, ct.slug
      ORDER BY inspections DESC, ct.name ASC
    ";

    $template_res = $conn->query($template_sql);
    while ($row = $template_res->fetch_assoc()) {
      $payload['by_template'][] = [
        'name' => (string) ($row['checklist_name'] ?? ''),
        'slug' => (string) ($row['checklist_slug'] ?? ''),
        'inspections' => (int) ($row['inspections'] ?? 0),
        'issues' => (int) ($row['issues'] ?? 0),
        'pass_rate_pct' => (float) ($row['pass_rate_pct'] ?? 0),
      ];
    }

    $available_checklist_slugs = array_column($payload['by_template'], 'slug');
    if ($selected_issue_checklist !== '' && !in_array($selected_issue_checklist, $available_checklist_slugs, true)) {
      $selected_issue_checklist = '';
      $payload['selected_issue_checklist'] = '';
    }

    $daily_sql = "
      SELECT
        DATE(ir.updated_at) AS day,
        COUNT(DISTINCT ir.inspection_id) AS inspections,
        ROUND(100 * AVG(ir.is_checked), 1) AS pass_rate_pct
      FROM inspection_responses ir
      WHERE ir.updated_at >= (NOW() - INTERVAL 30 DAY)
      GROUP BY DATE(ir.updated_at)
      ORDER BY day ASC
    ";

    $daily_res = $conn->query($daily_sql);
    while ($row = $daily_res->fetch_assoc()) {
      $payload['daily_pass_rate'][] = [
        'day' => (string) ($row['day'] ?? ''),
        'inspections' => (int) ($row['inspections'] ?? 0),
        'pass_rate_pct' => (float) ($row['pass_rate_pct'] ?? 0),
      ];
    }

    $issues_sql = "
      SELECT
        ct.name AS checklist_name,
        ci.label,
        COUNT(*) AS fail_count
      FROM inspection_responses ir
      JOIN checklist_items ci ON ci.id = ir.item_id
      JOIN checklist_templates ct ON ct.id = ci.template_id
      WHERE ir.is_checked = 0
      GROUP BY ct.id, ci.id, ct.name, ci.label
      ORDER BY fail_count DESC, ct.name ASC, ci.sort_order ASC
      LIMIT 10
    ";

    $issues_res = $conn->query($issues_sql);
    while ($row = $issues_res->fetch_assoc()) {
      $payload['top_issues'][] = [
        'checklist' => (string) ($row['checklist_name'] ?? ''),
        'item' => (string) ($row['label'] ?? ''),
        'count' => (int) ($row['fail_count'] ?? 0),
      ];
    }

    $response_note_column = null;
    foreach (['notes', 'note', 'comments', 'comment'] as $candidate) {
      if (column_exists($conn, 'inspection_responses', $candidate)) {
        $response_note_column = $candidate;
        break;
      }
    }
    $inspection_truck_column = null;
    foreach (['truck_label', 'lift_truck', 'asset_name', 'equipment_name'] as $candidate) {
      if (column_exists($conn, 'inspections', $candidate)) {
        $inspection_truck_column = $candidate;
        break;
      }
    }
    $inspection_user_column = null;
    foreach (['inspected_by', 'created_by', 'username'] as $candidate) {
      if (column_exists($conn, 'inspections', $candidate)) {
        $inspection_user_column = $candidate;
        break;
      }
    }
    $response_user_column = null;
    foreach (['inspected_by', 'created_by', 'username'] as $candidate) {
      if (column_exists($conn, 'inspection_responses', $candidate)) {
        $response_user_column = $candidate;
        break;
      }
    }
    $response_media_available = table_exists($conn, 'inspection_response_media');

    $open_issues_sql = "
      SELECT
        ir.id AS response_id,
        ir.inspection_id,
        ct.name AS checklist_name,
        ct.slug AS checklist_slug,
        ci.label AS item_label,
        ir.updated_at,
        " . ($response_note_column !== null ? "COALESCE(ir.`{$response_note_column}`, '')" : "''") . " AS response_note,
        " . ($inspection_truck_column !== null ? "COALESCE(i.`{$inspection_truck_column}`, '')" : "''") . " AS truck_label,
        " . ($inspection_user_column !== null ? "COALESCE(i.`{$inspection_user_column}`, '')" : ($response_user_column !== null ? "COALESCE(ir.`{$response_user_column}`, '')" : "''")) . " AS inspector_name,
        " . ($response_media_available ? "(SELECT irm.file_path FROM inspection_response_media irm WHERE irm.inspection_response_id = ir.id ORDER BY irm.id DESC LIMIT 1)" : "NULL") . " AS media_path
      FROM inspection_responses ir
      JOIN checklist_items ci ON ci.id = ir.item_id
      JOIN checklist_templates ct ON ct.id = ci.template_id
      LEFT JOIN inspections i ON i.id = ir.inspection_id
    ";
    $open_issues_where = ' WHERE ir.is_checked = 0';
    $open_issues_order = ' ORDER BY ct.name ASC, ci.label ASC, ir.updated_at DESC, ir.id DESC LIMIT 12';

    if ($selected_issue_checklist !== '') {
      $open_issues_stmt = $conn->prepare($open_issues_sql . $open_issues_where . ' AND ct.slug = ?' . $open_issues_order);
      $open_issues_stmt->bind_param('s', $selected_issue_checklist);
      $open_issues_stmt->execute();
      $open_issues_res = $open_issues_stmt->get_result();
    } else {
      $open_issues_res = $conn->query($open_issues_sql . $open_issues_where . $open_issues_order);
    }

    while ($row = $open_issues_res->fetch_assoc()) {
      $urgency = classify_issue_urgency((string) ($row['updated_at'] ?? ''));
      $payload['open_issues'][] = [
        'response_id' => (int) ($row['response_id'] ?? 0),
        'inspection_id' => (int) ($row['inspection_id'] ?? 0),
        'checklist' => (string) ($row['checklist_name'] ?? ''),
        'slug' => (string) ($row['checklist_slug'] ?? ''),
        'item' => (string) ($row['item_label'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'note' => (string) ($row['response_note'] ?? ''),
        'truck_label' => (string) ($row['truck_label'] ?? ''),
        'inspector_name' => (string) ($row['inspector_name'] ?? ''),
        'media_path' => (string) ($row['media_path'] ?? ''),
        'urgency_label' => (string) ($urgency['label'] ?? 'Action Required'),
        'urgency_class' => (string) ($urgency['class'] ?? 'critical'),
      ];
    }
    if (isset($open_issues_stmt) && $open_issues_stmt instanceof mysqli_stmt) {
      $open_issues_stmt->close();
    }

    $matrix_sql = "
      SELECT
        ct.name AS checklist_name,
        COALESCE(cic.name, 'Uncategorized') AS category_name,
        SUM(ir.is_checked = 0) AS issue_count
      FROM inspection_responses ir
      JOIN checklist_items ci ON ci.id = ir.item_id
      JOIN checklist_templates ct ON ct.id = ci.template_id
      LEFT JOIN checklist_item_categories cic ON cic.id = ci.category_id
      GROUP BY ct.id, ct.name, cic.id, category_name
      ORDER BY ct.name ASC, issue_count DESC, category_name ASC
    ";

    $matrix_res = $conn->query($matrix_sql);
    while ($row = $matrix_res->fetch_assoc()) {
      $payload['category_matrix'][] = [
        'checklist' => (string) ($row['checklist_name'] ?? ''),
        'category' => (string) ($row['category_name'] ?? ''),
        'issues' => (int) ($row['issue_count'] ?? 0),
      ];
    }
  } catch (Throwable $e) {
    $payload['schema_ready'] = false;
    $payload['error'] = $e->getMessage();
  }
} else {
  $payload['missing_tables'] = $missing_tables;
}

$firstName = $username;
$roleId = $role_id;
$title = 'Inspection Dashboard';
$page_class = 'page-inspection page-inspection-dashboard';
$css[] = '/refactor_css/dashboard.css';
$payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

ob_start();
?>


<?php
$issues_by_slug = array_column($payload['by_template'], 'issues', 'slug');
$checklist_cards = [
  ['slug' => 'lift_truck_checklist', 'title' => 'Lift Truck', 'icon' => 'M3 14h2.5l1.3-3.4A1.5 1.5 0 0 1 8.2 9.6h4.3V7.8c0-.4.3-.8.8-.8h2.7c.4 0 .8.4.8.8V11h1.7a2.5 2.5 0 0 1 2.5 2.5V16h-1.4a2.6 2.6 0 0 1-5.2 0h-4.7a2.6 2.6 0 0 1-5.2 0H3v-2Zm7.7-2.9-1 2.9h2.8v-2.9h-1.8Zm7.1 6.4a1.2 1.2 0 1 0 0-2.4 1.2 1.2 0 0 0 0 2.4Zm-10.4 0a1.2 1.2 0 1 0 0-2.4 1.2 1.2 0 0 0 0 2.4Z'],
  ['slug' => 'health_safety_checklist', 'title' => 'Health &amp; Safety', 'icon' => 'M12 2 4 5.5V12c0 5.2 3.6 8.5 8 10 4.4-1.5 8-4.8 8-10V5.5L12 2Zm3.6 8.4-4 4a1 1 0 0 1-1.4 0l-2.2-2.2 1.4-1.4 1.5 1.5 3.3-3.3 1.4 1.4Z'],
  ['slug' => 'warehouse_checklist', 'title' => 'Warehouse', 'icon' => 'M3 21V7l6-3 6 3v2h6v12H3Zm2-2h2v-2H5v2Zm0-4h2v-2H5v2Zm0-4h2V9H5v2Zm4 8h2v-2H9v2Zm0-4h2v-2H9v2Zm0-4h2V9H9v2Zm4 8h8v-8h-8v8Zm2-2h2v-2h-2v2Zm3 0h2v-2h-2v2Z'],
  ['slug' => 'pest_control_checklist', 'title' => 'Pest Control', 'icon' => 'M3 21V7l6-3 6 3v2h6v12H3Zm2-2h2v-2H5v2Zm0-4h2v-2H5v2Zm0-4h2V9H5v2Zm4 8h2v-2H9v2Zm0-4h2v-2H9v2Zm0-4h2V9H9v2Zm4 8h8v-8h-8v8Zm2-2h2v-2h-2v2Zm3 0h2v-2h-2v2Z'],
];
$floor_plan_url = (defined('APP_ROOT_URL') ? APP_ROOT_URL : '') . '/img/whs-flr-plan.png';
$open_issue_count = count($payload['open_issues']);
?>

<section class="dashboard-page">
  <header class="dashboard-header">
    <h1>Inspection Reports</h1>
    <p class="muted">Monitor inspections, identify risks, and resolve issues.</p>
  </header>

  <?php if (is_array($dashboard_flash) && !empty($dashboard_flash['message'])): ?>
    <div class="dashboard-alert dashboard-alert--<?= ($dashboard_flash['type'] ?? '') === 'success' ? 'success' : 'error' ?>">
      <strong><?= ($dashboard_flash['type'] ?? '') === 'success' ? 'Update saved.' : 'Action required.' ?></strong>
      <span><?= htmlspecialchars((string) $dashboard_flash['message']) ?></span>
    </div>
  <?php endif; ?>

  <?php if (!$payload['schema_ready']): ?>
    <div class="dashboard-alert dashboard-alert--error">
      <strong>Reporting unavailable.</strong>
      <span>
        <?php if (!empty($payload['missing_tables'])): ?>
          Missing tables: <?= htmlspecialchars(implode(', ', $payload['missing_tables'])) ?>.
        <?php else: ?>
          <?= htmlspecialchars((string) ($payload['error'] ?? 'Unable to load dashboard data.')) ?>
        <?php endif; ?>
      </span>
    </div>
  <?php else: ?>

    <!-- KPI strip -->
    <section class="dash-card dash-kpis" aria-label="Key performance indicators">
      <div class="dash-kpi dash-kpi--danger">
        <div class="dash-kpi__value"><?= number_format((int) $payload['summary']['open_issues_total']) ?></div>
        <div class="dash-kpi__label">Open Issues</div>
      </div>
      <div class="dash-kpi dash-kpi--warning">
        <div class="dash-kpi__value"><?= number_format((int) $payload['summary']['issues_30d']) ?></div>
        <div class="dash-kpi__label">Issues Found (30d)</div>
      </div>
      <div class="dash-kpi">
        <div class="dash-kpi__value"><?= number_format((int) $payload['summary']['issues_addressed_30d']) ?></div>
        <div class="dash-kpi__label">Issues Addressed (30d)</div>
      </div>
      <div class="dash-kpi">
        <div class="dash-kpi__value"><?= number_format((float) $payload['summary']['pass_rate_30d'], 1) ?>%</div>
        <div class="dash-kpi__label">Pass Rate (30d)</div>
      </div>
      <div class="dash-kpi">
        <div class="dash-kpi__value"><?= number_format((int) $payload['summary']['inspections_7d']) ?></div>
        <div class="dash-kpi__label">Inspections This Week</div>
      </div>
    </section>

    <!-- Checklist status cards -->
    <section class="dash-checklists" aria-label="Checklists">
      <?php foreach ($checklist_cards as $card): ?>
        <?php
        $slug = $card['slug'];
        $last_label = (string) ($last_completed_by_slug[$slug] ?? 'No inspections yet');
        $last_ts = $last_label !== 'No inspections yet' ? strtotime($last_label) : false;
        $done_today = $last_ts && date('Y-m-d', $last_ts) === date('Y-m-d');
        $open_count = (int) ($issues_by_slug[$slug] ?? 0);
        ?>
        <article class="dash-card dash-checklist">
          <div class="dash-checklist__head">
            <span class="dash-checklist__icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="<?= $card['icon'] ?>" /></svg>
            </span>
            <h2><?= $card['title'] ?></h2>
          </div>
          <ul class="dash-checklist__status">
            <li class="<?= $done_today ? 'is-ok' : 'is-muted' ?>">
              <?php if ($done_today): ?>
                Completed today, <?= htmlspecialchars(date('g:i A', $last_ts)) ?>
              <?php elseif ($last_ts): ?>
                Last completed <?= htmlspecialchars(date('M j', $last_ts)) ?>
              <?php else: ?>
                No inspections yet
              <?php endif; ?>
            </li>
            <li class="<?= $open_count > 0 ? 'is-alert' : 'is-ok' ?>">
              <?= $open_count > 0 ? number_format($open_count) . ' open issue' . ($open_count === 1 ? '' : 's') : 'No open issues' ?>
            </li>
          </ul>
          <div class="dash-checklist__actions">
            <?php if ($open_count > 0): ?>
              <a class="dash-link" href="<?= htmlspecialchars($BASE_URL . '/dashboard.php?issue_checklist=' . rawurlencode($slug) . '#issue-history') ?>">View Issues</a>
            <?php endif; ?>
            <a class="btn" href="<?= htmlspecialchars($BASE_URL . '/inspection.php?type=' . rawurlencode($slug)) ?>">Run Checklist</a>
          </div>
        </article>
      <?php endforeach; ?>
    </section>

    <!-- Map: where issues are happening -->
    <section class="dash-section">
      <div class="dash-section__head">
        <h2>Where Issues Are Happening</h2>
        <div class="damage-zone-filters">
          <label for="zoneFilterMarkerType" class="visually-hidden">Map Item</label>
          <select id="zoneFilterMarkerType">
            <option value="">All map items</option>
            <option value="fire_extinguisher">Fire Extinguisher</option>
            <option value="emergency_exit">Emergency Exit</option>
            <option value="advisory">Advisory</option>
          </select>

          <label for="zoneFilterSeverity" class="visually-hidden">Severity</label>
          <select id="zoneFilterSeverity">
            <option value="">All severities</option>
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
          </select>

          <label for="zoneFilterFrom">From</label>
          <input id="zoneFilterFrom" type="date" />

          <label for="zoneFilterTo">To</label>
          <input id="zoneFilterTo" type="date" />
        </div>
      </div>

      <div class="damage-zone-layout">
        <div class="dash-card dash-map">
          <div class="damage-map-wrap" id="damageMapWrap">
            <img id="damageMapImage" src="<?= htmlspecialchars($floor_plan_url) ?>" alt="Warehouse floor plan" />
            <canvas id="damageMapCanvas" aria-label="Map overlay. Click a marker to view it, or click an empty spot to add one."></canvas>
            <div id="damagePendingMarker" class="damage-pending-marker" hidden></div>
          </div>
          <div class="dash-map__footer">
            <div class="damage-map-legend" aria-label="Map legend">
              <span class="legend-item"><span class="legend-swatch legend-swatch--extinguisher">F</span>Fire Extinguisher</span>
              <span class="legend-item"><span class="legend-swatch legend-swatch--exit"></span>Emergency Exit</span>
              <span class="legend-item"><span class="legend-swatch legend-swatch--advisory"></span>Advisory</span>
            </div>
            <p class="muted" id="damageFilterSummary">Showing all zones.</p>
          </div>
        </div>

        <aside class="dash-card dash-details">
          <h3 id="damageZonePanelTitle">Add Map Item</h3>
          <p class="muted" id="damageZoneMeta" hidden></p>
          <form id="damageZoneForm" class="damage-zone-form" autocomplete="off">
            <input type="hidden" name="action" value="save_damage_zone" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($payload['csrf_token']) ?>" />
            <input type="hidden" name="x_pct" id="damageXInput" value="" />
            <input type="hidden" name="y_pct" id="damageYInput" value="" />

            <label for="damageMarkerType">Map Item</label>
            <select id="damageMarkerType" name="marker_type">
              <option value="fire_extinguisher">Fire Extinguisher</option>
              <option value="emergency_exit">Emergency Exit</option>
              <option value="advisory" selected>Advisory</option>
            </select>

            <label for="damageSeverity">Severity</label>
            <select id="damageSeverity" name="severity">
              <option value="low">Low</option>
              <option value="medium" selected>Medium</option>
              <option value="high">High</option>
            </select>

            <label for="damageNotes">Notes</label>
            <textarea id="damageNotes" name="notes" rows="3" maxlength="500"
              placeholder="Describe what was damaged"></textarea>

            <p id="damageZoneStatus" class="muted">Click a marker to view it, or click an empty spot to add one.</p>

            <div class="dash-details__actions">
              <button type="button" class="btn btn--ghost" id="damageZoneCancel" hidden>Cancel</button>
              <button type="button" class="btn btn--ghost" id="damageZoneClear" hidden>Clear from Map</button>
              <button type="submit" class="btn">Save Zone</button>
            </div>
          </form>
        </aside>
      </div>

      <details class="dashboard-history-disclosure">
        <summary>Map Item History</summary>
        <div class="table-wrap">
          <table class="dashboard-table" id="damageZoneTable">
            <thead>
              <tr>
                <th>When</th>
                <th>Type</th>
                <th>Severity</th>
                <th>Coordinates</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($payload['damage_zone']['zones'])): ?>
                <tr>
                  <td colspan="5">No zones reported yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach (array_slice($payload['damage_zone']['zones'], 0, 12) as $zone): ?>
                  <tr>
                    <td><?= htmlspecialchars((string) $zone['created_at']) ?></td>
                    <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', normalize_marker_type((string) ($zone['marker_type'] ?? 'advisory'))))) ?></td>
                    <td><?= htmlspecialchars(ucfirst((string) $zone['severity'])) ?></td>
                    <td><?= htmlspecialchars(number_format((float) $zone['x_pct'], 1) . '%, ' . number_format((float) $zone['y_pct'], 1) . '%') ?></td>
                    <td><span class="muted">JS actions enabled</span></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </details>

      <dialog id="damageHistoryDialog" class="damage-history-dialog">
        <form method="dialog" class="damage-history-dialog__head">
          <h3>Zone History</h3>
          <button value="cancel" aria-label="Close">Close</button>
        </form>
        <div id="damageHistoryBody" class="damage-history-dialog__body"></div>
      </dialog>
    </section>

    <!-- Open checklist issues -->
    <section class="dash-card dash-issues" id="issue-history">
      <div class="dash-section__head">
        <h2>Open Issues <span class="dash-count"><?= number_format($open_issue_count) ?></span></h2>
        <form method="get" action="#issue-history" class="dash-issues__filter">
          <label for="issue_checklist" class="visually-hidden">Checklist Type</label>
          <select id="issue_checklist" name="issue_checklist" onchange="this.form.submit()">
            <option value="">All checklist types</option>
            <?php foreach ($payload['by_template'] as $template): ?>
              <option value="<?= htmlspecialchars((string) $template['slug']) ?>" <?= ((string) ($payload['selected_issue_checklist'] ?? '') === (string) $template['slug']) ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) $template['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <noscript><button type="submit" class="btn">Filter</button></noscript>
          <?php if ((string) ($payload['selected_issue_checklist'] ?? '') !== ''): ?>
            <a href="<?= htmlspecialchars($BASE_URL . '/dashboard.php#issue-history') ?>" class="dash-link">Clear</a>
          <?php endif; ?>
        </form>
      </div>
      <p class="muted dash-issues__hint">
        Failed inspection items. Confirm the correction, add a resolution note, then close the issue.
      </p>

      <div class="table-wrap">
        <table class="dashboard-table">
          <thead>
            <tr>
              <th>Status</th>
              <th>Issue</th>
              <th>Reported</th>
              <th>Inspector</th>
              <th>Inspection</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($payload['open_issues'])): ?>
              <tr>
                <td colspan="6">No failed inspection items currently require action.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($payload['open_issues'] as $issue): ?>
                <tr>
                  <td>
                    <span class="issue-urgency-badge issue-urgency-badge--<?= htmlspecialchars((string) $issue['urgency_class']) ?>">
                      <?= htmlspecialchars((string) $issue['urgency_label']) ?>
                    </span>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars((string) $issue['item']) ?></strong>
                    <div class="muted">
                      <?= htmlspecialchars((string) $issue['checklist']) ?>
                      <?php if ((string) $issue['truck_label'] !== ''): ?>
                        &middot; <?= htmlspecialchars((string) $issue['truck_label']) ?>
                      <?php endif; ?>
                      <?php if ((string) $issue['media_path'] !== ''): ?>
                        &middot; Photo
                      <?php endif; ?>
                    </div>
                    <?php if ((string) $issue['note'] !== ''): ?>
                      <div class="issue-reference__note"><?= nl2br(htmlspecialchars((string) $issue['note'])) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="dash-nowrap"><?= htmlspecialchars((string) $issue['updated_at']) ?></td>
                  <td><?= htmlspecialchars((string) ($issue['inspector_name'] !== '' ? $issue['inspector_name'] : 'Unknown')) ?></td>
                  <td class="dash-nowrap">#<?= (int) $issue['inspection_id'] ?></td>
                  <td>
                    <?php if ($can_manage_checklist_issues): ?>
                      <button
                        type="button"
                        class="btn issue-review-trigger"
                        data-issue-response-id="<?= (int) $issue['response_id'] ?>"
                        data-issue-inspection-id="<?= (int) $issue['inspection_id'] ?>"
                        data-issue-updated-at="<?= htmlspecialchars((string) $issue['updated_at'], ENT_QUOTES) ?>"
                        data-issue-checklist="<?= htmlspecialchars((string) $issue['checklist'], ENT_QUOTES) ?>"
                        data-issue-truck-label="<?= htmlspecialchars((string) $issue['truck_label'], ENT_QUOTES) ?>"
                        data-issue-item="<?= htmlspecialchars((string) $issue['item'], ENT_QUOTES) ?>"
                        data-issue-note="<?= htmlspecialchars((string) $issue['note'], ENT_QUOTES) ?>"
                        data-issue-inspector-name="<?= htmlspecialchars((string) $issue['inspector_name'], ENT_QUOTES) ?>"
                        data-issue-media-path="<?= htmlspecialchars((string) $issue['media_path'], ENT_QUOTES) ?>">
                        Review
                      </button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

        <?php if ($can_manage_checklist_issues): ?>
          <dialog id="issueReviewDialog" class="issue-review-dialog">
            <div class="issue-review-dialog__shell">
              <div class="issue-review-dialog__header">
                <div>
                  <h3>Review Inspection Issue</h3>
                  <p class="muted">Confirm the correction, add a short note, and then close the issue.</p>
                </div>
                <button type="button" id="issueReviewClose" class="btn btn--ghost" aria-label="Close review panel">Close</button>
              </div>

              <form method="post" id="issueReviewForm" class="issue-review-form">
                <div class="issue-review-dialog__body">
                  <div class="issue-review-summary">
                    <div class="issue-review-summary__row">
                      <span class="issue-review-summary__label">When Reported</span>
                      <span id="issueReviewWhen"></span>
                    </div>
                <div class="issue-review-summary__row">
                  <span class="issue-review-summary__label">Inspection Reference</span>
                  <span id="issueReviewInspectionReference"></span>
                </div>
                <div class="issue-review-summary__row">
                  <span class="issue-review-summary__label">Inspector</span>
                  <span id="issueReviewInspector"></span>
                </div>
                <div class="issue-review-summary__row">
                  <span class="issue-review-summary__label">Checklist Type</span>
                  <span id="issueReviewChecklist"></span>
                </div>
                    <div class="issue-review-summary__row">
                      <span class="issue-review-summary__label">Issue Found</span>
                      <span id="issueReviewItem"></span>
                    </div>
                    <div class="issue-review-summary__row">
                      <span class="issue-review-summary__label">Inspector Note</span>
                      <span id="issueReviewNote"></span>
                    </div>
                  </div>

                  <div class="issue-review-media" id="issueReviewMediaSection" hidden>
                    <span class="issue-review-summary__label">Photo Evidence</span>
                    <a id="issueReviewMediaLink" href="#" target="_blank" rel="noopener noreferrer">
                      <img id="issueReviewMediaImage" src="" alt="Inspection issue evidence photo" />
                    </a>
                  </div>

                  <input type="hidden" name="action" value="resolve_checklist_issue" />
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($payload['csrf_token']) ?>" />
                  <input type="hidden" name="response_id" id="issueReviewResponseId" value="" />

                  <label for="issueReviewResolutionNote">Resolution Note</label>
                  <textarea
                    id="issueReviewResolutionNote"
                    name="resolution_note"
                    rows="4"
                    maxlength="500"
                    placeholder="Describe what was corrected or what you verified before closing this issue."
                    required></textarea>

                  <div class="issue-review-form__actions">
                    <button type="button" class="btn btn--ghost" id="issueReviewKeepOpen">Keep Open</button>
                    <button type="submit" class="btn">Verify and Close Issue</button>
                  </div>
                </div>
              </form>
            </div>
          </dialog>
        <?php endif; ?>
    </section>


    <!-- Most Frequent Failed Items -->
    <!-- <section class="card dashboard-shell">
      <article class="dashboard-panel top-issues-panel">
        <header>
          <h2>Most Frequent Failed Items</h2>
        </header>
        <div class="table-wrap">
          <table class="dashboard-table">
            <thead>
              <tr>
                <th>Checklist</th>
                <th>Item</th>
                <th>Failures</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($payload['top_issues'])): ?>
                <tr>
                  <td colspan="3">No issues logged yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($payload['top_issues'] as $issue): ?>
                  <tr>
                    <td><?= htmlspecialchars($issue['checklist']) ?></td>
                    <td><?= htmlspecialchars($issue['item']) ?></td>
                    <td><?= (int) $issue['count'] ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </article>
    </section> -->

  <?php endif; ?>
</section>

<?php
$content = ob_get_clean();

$footer_js = <<<HTML
<script nonce="<?= $cspNonceAttr ?>">window.INSPECTION_DASHBOARD = {$payload_json};</script>
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script defer src="https://cdn.jsdelivr.net/npm/chartjs-chart-matrix@2"></script>
<script nonce="<?= $cspNonceAttr ?>">
document.addEventListener('DOMContentLoaded', function () {
  const dialog = document.getElementById('issueReviewDialog');
  if (!dialog) {
    return;
  }

  const responseIdField = document.getElementById('issueReviewResponseId');
  const whenField = document.getElementById('issueReviewWhen');
  const inspectionReferenceField = document.getElementById('issueReviewInspectionReference');
  const inspectorField = document.getElementById('issueReviewInspector');
  const checklistField = document.getElementById('issueReviewChecklist');
  const itemField = document.getElementById('issueReviewItem');
  const noteField = document.getElementById('issueReviewNote');
  const mediaSection = document.getElementById('issueReviewMediaSection');
  const mediaImage = document.getElementById('issueReviewMediaImage');
  const mediaLink = document.getElementById('issueReviewMediaLink');
  const resolutionField = document.getElementById('issueReviewResolutionNote');
  const keepOpenButton = document.getElementById('issueReviewKeepOpen');
  const closeButton = document.getElementById('issueReviewClose');

  document.querySelectorAll('.issue-review-trigger').forEach(function (button) {
    button.addEventListener('click', function () {
      responseIdField.value = button.dataset.issueResponseId || '';
      whenField.textContent = button.dataset.issueUpdatedAt || '';
      const inspectionId = button.dataset.issueInspectionId || '';
      inspectionReferenceField.textContent = inspectionId ? 'Inspection #' + inspectionId : 'Not available';
      inspectorField.textContent = button.dataset.issueInspectorName || 'Not available';

      const checklist = button.dataset.issueChecklist || '';
      const truckLabel = button.dataset.issueTruckLabel || '';
      checklistField.textContent = truckLabel ? checklist + ' (' + truckLabel + ')' : checklist;

      itemField.textContent = button.dataset.issueItem || '';
      noteField.textContent = button.dataset.issueNote || 'No note provided.';
      const mediaPath = button.dataset.issueMediaPath || '';
      if (mediaSection && mediaImage && mediaLink) {
        if (mediaPath) {
          const mediaUrl = ('{$BASE_URL}/' + mediaPath).replace(/\/+/g, '/');
          mediaImage.src = mediaUrl;
          mediaLink.href = mediaUrl;
          mediaSection.hidden = false;
        } else {
          mediaImage.removeAttribute('src');
          mediaLink.setAttribute('href', '#');
          mediaSection.hidden = true;
        }
      }
      resolutionField.value = '';

      if (typeof dialog.showModal === 'function') {
        dialog.showModal();
      } else {
        dialog.setAttribute('open', 'open');
      }
      resolutionField.focus();
    });
  });

  keepOpenButton?.addEventListener('click', function () {
    if (typeof dialog.close === 'function') {
      dialog.close();
    } else {
      dialog.removeAttribute('open');
    }
  });

  closeButton?.addEventListener('click', function () {
    if (typeof dialog.close === 'function') {
      dialog.close();
    } else {
      dialog.removeAttribute('open');
    }
  });
});
</script>
<script src="{$BASE_URL}/js/dashboard.js" defer></script>
HTML;

include __DIR__ . '/templates/layout.php';
