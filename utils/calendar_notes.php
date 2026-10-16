<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../lib/security.php';

if (!isset($_SESSION['username'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
  exit;
}

require_once __DIR__ . '/../dbinv.php';

$role_id = (int) ($_SESSION['role_id'] ?? 0);
$is_approver = in_array($role_id, [1, 2], true);

function respond($payload, $code = 200)
{
  http_response_code($code);
  echo json_encode($payload);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input');
$json = $body ? json_decode($body, true) : null;

if ($method === 'GET') {



  $sql = "SELECT id, note_date, title, description, category, recurring, recurring_until, is_recurring_yearly, created_by, created_at
          FROM calendar_notes
          WHERE is_active = 1
          ORDER BY note_date DESC, id DESC";
  $result = $conn->query($sql);
  $notes = [];
  if ($result) {
    while ($row = $result->fetch_assoc()) {
      $notes[] = $row;
    }
    $result->free();
  }
  respond(['ok' => true, 'notes' => $notes]);
}

if ($method === 'POST') {

  if (!$json) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
  }

  $action = $json['action'] ?? 'create';


  /*
   * =====================================================
   * DELETE CALENDAR NOTE
   * Admin / Manager only.
   * =====================================================
   */

  if ($action === 'delete') {

  if (!$is_approver) {
      respond(['ok' => false, 'error' => 'Forbidden'], 403);
    }

    $id = isset($json['id']) ? (int) $json['id'] : 0;

    if ($id <= 0) {
      respond(['ok' => false, 'error' => 'Invalid id'], 400);
    }

    $stmt = $conn->prepare(
      "UPDATE calendar_notes
       SET is_active = 0
       WHERE id = ?"
    );

    if (!$stmt) {
      respond(['ok' => false, 'error' => 'Prepare failed'], 500);
    }

    $stmt->bind_param('i', $id);

    $stmt->execute();
    $stmt->close();


    respond(['ok' => true]);
  }


  /*
   * =====================================================
   * CREATE CALENDAR NOTE
   * Admin / Manager only.
   * =====================================================
   */

  if (!$is_approver) {
    respond(['ok' => false, 'error' => 'Forbidden'], 403);
  }

  $note_date = isset($json['note_date']) ? $json['note_date'] : null;

  $title = isset($json['title']) && trim($json['title']) !== '' ? trim($json['title']) : 'Note';
  $description = isset($json['description']) ? trim($json['description']) : '';
  $category = isset($json['category']) ? strtoupper(trim($json['category'])) : 'CUSTOM';
  $recurring = strtolower(trim($json['recurring'] ?? 'none'));

  $allowed_recurring = [
    'none',
    'weekly',
    'monthly',
    'yearly'
  ];

  $allowed_categories = [
    'HOLIDAY',
    'BIRTHDAY',
    'INFO',
    'CUSTOM'
  ];

  if (!in_array($category, $allowed_categories, true)) {
    $category = 'CUSTOM';
  }

  if (!in_array($recurring, $allowed_recurring, true)) {
    $recurring = 'none';
  }

  if (!$note_date) {
    respond(['ok' => false, 'error' => 'Missing note_date'], 400);
  }

  $recurring_until = null;

  if ($recurring !== 'none' && !empty($json['recurring_until'])) {
    $recurring_until = $json['recurring_until'];
  }

  $created_by = (int) ($_SESSION['user_id'] ?? 0);


  $stmt = $conn->prepare(
    "INSERT INTO calendar_notes
      (
        note_date,
        title,
        description,
        category,
        recurring,
        recurring_until,
        created_by
      )
     VALUES (?, ?, ?, ?, ?, ?, ?)"
  );

  if (!$stmt) {
    respond(['ok' => false, 'error' => 'Prepare failed'], 500);
  }

  $stmt->bind_param(
    'ssssssi',
    $note_date,
    $title,
    $description,
    $category,
    $recurring,
    $recurring_until,
    $created_by
  );

  $stmt->execute();
  $new_id = $stmt->insert_id;
  $stmt->close();

  respond(['ok' => true, 'id' => $new_id]);
}

respond(['ok' => false, 'error' => 'Method not allowed'], 405);
