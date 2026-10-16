<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/security.php';
require_once __DIR__ . '/../dbinv.php';
require_once __DIR__ . '/helpers.php';

if (!isset($_SESSION['username'], $_SESSION['user_id'])) {
  header('Location: ../login.php');
  exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

$user_id = (int) $_SESSION['user_id'];

const BARCODE_UOMS = ['UNIT', 'CARTON', 'MASTERCASE'];

/* Get all dimension data for a SKU (all UOMs) */
if (isset($_GET['sku_id']) && $_GET['sku_id'] !== '') {
  header('Content-Type: application/json; charset=utf-8');
  $skuId = filter_var($_GET['sku_id'], FILTER_VALIDATE_INT);

  if (!$skuId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid SKU ID']);
    exit;
  }

  try {
    $stmt = $conn->prepare(
      'SELECT id, uom_label, length, width, height, weight_lbs
       FROM product_uom_barcodes
       WHERE sku_id = ? AND uom_label IN (?, ?, ?)'
    );
    $stmt->bind_param('isss', $skuId, $uom1, $uom2, $uom3);
    $uom1 = 'UNIT';
    $uom2 = 'CARTON';
    $uom3 = 'MASTERCASE';
    $stmt->execute();
    $result = $stmt->get_result();

    $dimensions = [];
    while ($row = $result->fetch_assoc()) {
      $dimensions[$row['uom_label']] = [
        'id' => (int) $row['id'],
        'length' => $row['length'] ? (float) $row['length'] : null,
        'width' => $row['width'] ? (float) $row['width'] : null,
        'height' => $row['height'] ? (float) $row['height'] : null,
        'weight_lbs' => $row['weight_lbs'] ? (float) $row['weight_lbs'] : null
      ];
    }
    $stmt->close();

    // Ensure all UOMs are present (even if empty)
    foreach (BARCODE_UOMS as $uom) {
      if (!isset($dimensions[$uom])) {
        $dimensions[$uom] = [
          'id' => null,
          'length' => null,
          'width' => null,
          'height' => null,
          'weight_lbs' => null
        ];
      }
    }

    echo json_encode([
      'ok' => true,
      'sku_id' => $skuId,
      'dimensions' => $dimensions
    ], JSON_INVALID_UTF8_SUBSTITUTE);
  } catch (Throwable $e) {
    error_log('[manageDimensions get] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
  }
  exit;
}

/* Save dimensions for a specific UOM */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  header('Content-Type: application/json; charset=utf-8');

  $data = json_decode(file_get_contents('php://input'), true);

  if (!$data) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request']);
    exit;
  }

  $barcodeId = isset($data['barcode_id']) ? filter_var($data['barcode_id'], FILTER_VALIDATE_INT) : null;
  $skuId = filter_var($data['sku_id'] ?? null, FILTER_VALIDATE_INT);
  $uomLabel = (string) ($data['uom_label'] ?? '');
  $length = isset($data['length']) && $data['length'] !== '' ? (float) $data['length'] : null;
  $width = isset($data['width']) && $data['width'] !== '' ? (float) $data['width'] : null;
  $height = isset($data['height']) && $data['height'] !== '' ? (float) $data['height'] : null;
  $weightLbs = isset($data['weight_lbs']) && $data['weight_lbs'] !== '' ? (float) $data['weight_lbs'] : null;

  if (!$skuId || !in_array($uomLabel, BARCODE_UOMS, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
  }

  try {
    if ($barcodeId) {
      // Update existing barcode row
      $stmt = $conn->prepare(
        'UPDATE product_uom_barcodes
         SET length = ?, width = ?, height = ?, weight_lbs = ?,
             updated_at = CURRENT_TIMESTAMP, updated_by = ?
         WHERE id = ? AND sku_id = ?'
      );

      $stmt->bind_param(
        'ddddiii',
        $length,
        $width,
        $height,
        $weightLbs,
        $user_id,
        $barcodeId,
        $skuId
      );

      $stmt->execute();
      $affected = $stmt->affected_rows;
      $stmt->close();

      if ($affected > 0) {
        echo json_encode(['ok' => true, 'message' => 'Dimensions saved']);
      } else {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Barcode not found']);
      }
    } else {
      // No barcode ID (row doesn't exist yet)
      echo json_encode(['ok' => true, 'message' => 'Dimensions cleared']);
    }
  } catch (Throwable $e) {
    error_log('[manageDimensions save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save dimensions']);
  }
  exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
exit;
