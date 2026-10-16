<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
require_once __DIR__ . '/../dbinv.php';
require_once __DIR__ . '/../utils/helpers.php';
require_once __DIR__ . '/../utils/config_init.php';
require_once __DIR__ . "/utils/db_importers.php";
require_once __DIR__ . "/templates/access_control.php";
foreach (
  [
    __DIR__ . "/../vendor/autoload.php",
    __DIR__ . "/../../vendor/autoload.php",
  ] as $autoloadPath
) {
  if (is_file($autoloadPath)) {
    require_once $autoloadPath;
    break;
  }
}
// require_once __DIR__ . '/../templates/auth_guard.php';
// app_require_role([1, 2, 3]);

// --- INITIAL AUTH CHECK ---
if (!isset($_SESSION["username"])) {
  header("Location: ../login.php");
  exit();
}
$user_id = (int) ($_SESSION["user_id"] ?? 0);
$username = $_SESSION["username"] ?? "User";
$role_id = (int) ($_SESSION["role_id"] ?? 0);

if (!ac_can_access_page($role_id, "inventory/import.php", $page_permissions_by_role)) {
  http_response_code(403);
  echo "Access Denied. You will be redirected to the home screen.";
  echo '
        <script>
            setTimeout(function() {
                window.location.href = "dashboard.php";
            }, 2000); 
        </script>
    ';
  exit();
}

// Restrict to admins/managers/analysts
// $can_use_ai = in_array($role_id, [1, 2, 3], true);
// if (!$can_use_ai) {
//   http_response_code(403);
//   echo "Access denied.";
//   exit();
// }

// --- Configuration and Setup ---

// Check for the OpenAI Key
$OPENAI_KEY =
  defined("OPENAI_API_KEY") && OPENAI_API_KEY !== ""
  ? OPENAI_API_KEY
  : (getenv("OPENAI_API_KEY") ?:
    "");
$AI_ENABLED = $OPENAI_KEY !== "";

// Max rows to show in preview
$PREVIEW_ROW_CAP = 10;

// Path for import attempts log (JSONL for easy parsing)
$IMPORT_LOG_PATH = __DIR__ . "/import_log.jsonl";
// Directory for per-row logs (one file per import run)
$IMPORT_ROW_LOG_DIR = __DIR__ . "/import_row_logs";
// Directory for error logs (one file per import run)
$IMPORT_ERROR_LOG_DIR = __DIR__ . "/error_log";

// Define the required database fields for the current import target
$IMPORT_TARGETS = [
  "sku" => [
    "name" => "Import SKU Details (sku_num, desc, status)",
    "required_fields" => [
      "sku_num" => ["label" => "SKU Number (sku_num)", "required" => true],
      "desc" => ["label" => "Description (desc)", "required" => true],
      "status" => ["label" => "Status (status)", "required" => false],
    ],
    "import_function" => "import_sku_details_from_file",
  ],
  "sku_shelf" => [
    "name" => "SKU Shelf / Aisle Assignments",
    "required_fields" => [
      "sku_num" => ["label" => "SKU Number (sku_num)", "required" => true],
      "shelf" => [
        "label" => "Shelf / Aisle / Isle (shelf)",
        "required" => true,
        "aliases" => ["aisle", "isle"],
      ],
    ],
    "import_function" => "import_sku_shelf_from_file",
  ],
  "location" => [
    "name" => "Location Details (Bins/Areas)",
    "required_fields" => [
      "loc_code" => ["label" => "Location Code (loc_code)", "required" => true],
      "warehouse" => ["label" => "Warehouse (warehouse)", "required" => true],
      "loc_type" => [
        "label" => "Location Type (loc_type)",
        "required" => false,
      ],
    ],
    "import_function" => "import_location_details_from_file",
  ],
  "inventory" => [
    "name" => "Inventory Counts",
    "required_fields" => [
      "sku_num" => ["label" => "SKU Number (sku_num)", "required" => true],
      "quantity" => ["label" => "Quantity (qty)", "required" => true],
      "loc_code" => [
        "label" => "Location Code (loc_code)",
        "required" => false,
      ],
    ],
    "import_function" => "import_inventory_counts_from_file",
  ],
  "outwhse_inventory" => [
    "name" => "OUTWHSE Inventory (In Storage / Units)",
    "required_fields" => [
      "warehouse" => [
        "label" => "Warehouse",
        "required" => true,
        "aliases" => ["Warehouse"],
      ],
      "sku" => [
        "label" => "SKU",
        "required" => true,
        "aliases" => ["SKU"],
      ],
      "description" => [
        "label" => "Description",
        "required" => false,
        "aliases" => ["Description"],
      ],
      "lot_number" => [
        "label" => "Lot # / Exp",
        "required" => false,
        "aliases" => ["Lot # / Exp", "Lot Number", "Lot #"],
      ],
      "qty_unit" => [
        "label" => "QTY (CTN / UNIT)",
        "required" => true,
        "aliases" => ["QTY (CTN / UNIT)", "QTY UNIT", "UNIT QTY"],
      ],
      "pallet_number" => [
        "label" => "PALLET #",
        "required" => false,
        "aliases" => ["PALLET #", "Pallet Number", "Pallet #"],
      ],
      "status" => [
        "label" => "Operational Status (legacy)",
        "required" => false,
        "aliases" => [],
      ],
      "import_status_raw" => [
        "label" => "Imported Status / CSV Status",
        "required" => false,
        "aliases" => ["STATUS", "Status", "Imported Status", "CSV Status"],
      ],
      "inventory_notes" => [
        "label" => "Comments / Notes",
        "required" => false,
        "aliases" => ["Comments", "Notes", "Comment", "Inventory Notes"],
      ],
      "dangerous_goods" => [
        "label" => "DANGEROUS GOODS",
        "required" => false,
        "aliases" => [
          "DANGEROUS GOODS",
          "Dangerous Goods",
        ],
      ],
      "dg_class" => [
        "label" => "DG Class",
        "required" => false,
        "aliases" => ["DG CLASS", "DG Class", "Dangerous Goods Class"],
      ],
      "un_number" => [
        "label" => "UN Number",
        "required" => false,
        "aliases" => ["UN NUMBER", "UN Number", "UN #", "UN"],
      ],
      "is_dangerous_good" => [
        "label" => "Is Dangerous Good",
        "required" => false,
        "aliases" => [
          "IS DANGEROUS GOOD",
          "Is Dangerous Good",
          "DG",
          "Dangerous Good",
          "DG Flag",
        ],
      ],
    ],
    "import_function" => "import_outwhse_inventory_from_file",
  ],
  "barcode_uom" => [
    "name" => "Barcode Data (multi-UOM)",
    "required_fields" => [
      "code" => ["label" => "Barcode (code)", "required" => true],
      "item_no" => ["label" => "Item No. (sku_num)", "required" => true],
      "unit_of_measure_code" => [
        "label" => "Unit of Measure Code (UNIT/CARTON/CASE/MASTERCASE)",
        "required" => false,
      ],
      "multiplier" => [
        "label" => "Multiplier override (optional)",
        "required" => false,
      ],
    ],
    "import_function" => "import_uom_barcodes_from_file",
  ],
  "shipping_address" => [
    "name" => "Shipping Address",
    "required_fields" => [
      "document_type" => [
        "label" => "Document Type",
        "required" => true,
        "aliases" => ["Document Type"],
      ],
      "document_no" => [
        "label" => "No.",
        "required" => true,
        "aliases" => ["No.", "No"],
      ],
      "ship_to_address" => [
        "label" => "Ship-to Address",
        "required" => false,
        "aliases" => ["Ship-to Address"],
      ],
      "ship_to_address_2" => [
        "label" => "Ship-to Address 2",
        "required" => false,
        "aliases" => ["Ship-to Address 2"],
      ],
      "ship_to_city" => [
        "label" => "Ship-to City",
        "required" => false,
        "aliases" => ["Ship-to City"],
      ],
      "ship_to_zip_code" => [
        "label" => "Ship-to ZIP Code",
        "required" => false,
        "aliases" => ["Ship-to ZIP Code", "Ship-to Zip Code"],
      ],
      "ship_to_state" => [
        "label" => "Ship-to State",
        "required" => false,
        "aliases" => ["Ship-to State"],
      ],
      "ship_to_country_region_code" => [
        "label" => "Ship-to Country/Region Code",
        "required" => false,
        "aliases" => ["Ship-to Country/Region Code"],
      ],
    ],
    "import_function" => "import_shipping_addresses_from_file",
  ],
  "shelf_tags" => [
    "name" => "Shelf Tags",
    "required_fields" => [
      "aisle" => [
        "label" => "Aisle",
        "required" => true,
        "aliases" => ["Aisle", "Isle", "aisle"],
      ],
      "section" => [
        "label" => "Section",
        "required" => true,
        "aliases" => ["Section", "section"],
      ],
      "shelf_level" => [
        "label" => "Shelf Level",
        "required" => true,
        "aliases" => ["Shelf Level", "Level", "shelf_level"],
      ],
    ],
    "import_function" => "import_shelf_tags_from_file",
  ],
];

// Initialize variables
$target_key = $_POST["import_target"] ?? "barcode_uom"; // Default to barcode import
$current_target = $IMPORT_TARGETS[$target_key] ?? $IMPORT_TARGETS["sku"];
$DB_TARGET_FIELDS = $current_target["required_fields"]; // Dynamic field list
$import_func = $current_target["import_function"]; // Used in the confirmation step
$default_carton_multiplier = (int) ($_POST["default_carton_multiplier"] ?? 0);
$default_case_multiplier = (int) ($_POST["default_case_multiplier"] ?? 0);
$default_mastercase_multiplier = (int) ($_POST["default_mastercase_multiplier"] ?? 0);

/**
 * Append a structured line to the import log without breaking the main flow.
 */
function log_import_event(
  string $status,
  string $target,
  string $filename,
  array $details = []
): void {
  global $IMPORT_LOG_PATH, $username;

  $dir = dirname($IMPORT_LOG_PATH);
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }

  $payload = [
    "timestamp" => gmdate("c"),
    "user" => $username ?? "unknown",
    "target" => $target,
    "filename" => $filename,
    "status" => $status,
    "details" => $details,
  ];

  $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
  if ($line === false) {
    return;
  }

  @file_put_contents($IMPORT_LOG_PATH, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Produce a compact summary of per-row results to keep the log readable.
 */
function summarize_row_log(array $rowLog, int $limitPerBucket = 30): array
{
  $imported = $rowLog["imported"] ?? [];
  $skipped = $rowLog["skipped"] ?? [];

  return [
    "imported_count" => count($imported),
    "skipped_count" => count($skipped),
    "imported" => array_slice($imported, 0, $limitPerBucket),
    "skipped" => array_slice($skipped, 0, $limitPerBucket),
    "truncated" => [
      "imported" => count($imported) > $limitPerBucket || !empty($rowLog["truncated"]["imported"] ?? false),
      "skipped" => count($skipped) > $limitPerBucket || !empty($rowLog["truncated"]["skipped"] ?? false),
    ],
  ];
}

/**
 * Detect the actual header row for Sales Header exports, which may start on row 3.
 */
function detect_sales_header_row(
  \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet,
  int $maxScanRows = 10,
): int {
  $highestColumn = $worksheet->getHighestDataColumn();

  for ($row = 1; $row <= $maxScanRows; $row++) {
    $rowData = $worksheet->rangeToArray(
      "A{$row}:{$highestColumn}{$row}",
      "",
      false,
      true,
      true,
    );

    $values = array_map(
      static fn($value): string => trim((string) $value),
      $rowData[$row] ?? [],
    );

    if (
      in_array("Document Type", $values, true) &&
      in_array("No.", $values, true) &&
      in_array("Ship-to Address", $values, true)
    ) {
      return $row;
    }
  }

  return 1;
}

/**
 * Reads a file (CSV or XLSX) and returns the header and a sample of rows.
 * This is the only file processing function remaining here, as it uses
 * PhpSpreadsheet specific to this page.
 * @return array{columns: array<int,string>, rows: array<int,array<int,string>>, error: string, total_rows: int}
 */
function read_file_for_preview(
  string $filePath,
  string $extension,
  int $cap
): array {
  // ... (Your existing read_file_for_preview function logic remains here) ...
  $results = ["columns" => [], "rows" => [], "error" => "", "total_rows" => 0];

  try {
    if ($extension === "csv") {
      $handle = fopen($filePath, "rb");
      if ($handle === false) {
        throw new RuntimeException("Unable to open the file.");
      }

      $results["columns"] = fgetcsv($handle, 0, ",", '"', "\\") ?: [];
      while (($row = fgetcsv($handle, 0, ",", '"', "\\")) !== false) {
        if ($row === [null] || $row === false) {
          continue;
        }

        if ($results["total_rows"] < $cap) {
          $results["rows"][] = $row;
        }
        $results["total_rows"]++;
      }
      fclose($handle);
    } elseif ($extension === "xlsx") {
      $spreadsheet = inv_load_spreadsheet_file($filePath);
      $worksheet = $spreadsheet->getActiveSheet();
      $highestRow = $worksheet->getHighestDataRow();
      $highestColumn = $worksheet->getHighestDataColumn();
      $headerRowNumber = function_exists("inv_detect_header_row")
        ? inv_detect_header_row($worksheet)
        : detect_sales_header_row($worksheet);
      $headerData = $worksheet->rangeToArray(
        "A{$headerRowNumber}:{$highestColumn}{$headerRowNumber}",
        "",
        false,
        true,
        true,
      );
      $headerRow = $headerData[$headerRowNumber] ?? [];

      if ($highestRow < $headerRowNumber || empty($headerRow)) {
        throw new RuntimeException("The spreadsheet is empty or missing headers.");
      }

      $results["columns"] = array_values(
        array_filter(
          array_map("strval", $headerRow),
          static fn($value): bool => trim($value) !== "",
        ),
      );
      $results["total_rows"] = max(0, $highestRow - $headerRowNumber);

      $previewEndRow = min($highestRow, $headerRowNumber + $cap);
      for ($rowNumber = $headerRowNumber + 1; $rowNumber <= $previewEndRow; $rowNumber++) {
        $rowData = $worksheet->rangeToArray(
          "A{$rowNumber}:{$highestColumn}{$rowNumber}",
          "",
          false,
          true,
          false,
        );
        $results["rows"][] = array_map(
          "strval",
          array_values($rowData[0] ?? []),
        );
      }
    } else {
      $results["error"] = "Unsupported file type.";
    }
  } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
    $results["error"] = "Error reading Excel file: " . $e->getMessage();
  } catch (Throwable $e) {
    $results["error"] = "File processing error: " . $e->getMessage();
  }

  return $results;
}

/**
 * Import multi-UOM barcode data into product_uom_barcodes.
 * Returns number of rows inserted/updated. Populates $row_log with imported/skipped samples.
 */
function import_uom_barcodes_from_file(
  mysqli $conn,
  string $filePath,
  int $user_id,
  array $columns,
  string $extension,
  array $columnMap,
  array &$row_log = []
): int {
  global $default_carton_multiplier, $default_case_multiplier, $default_mastercase_multiplier;

  $row_log = ["imported" => [], "skipped" => []];
  $inserted = 0;
  $updated = 0;
  $skipped = 0;

  $read_rows = [];
  if ($extension === "csv") {
    if (($handle = fopen($filePath, "rb")) === false) {
      throw new RuntimeException("Unable to open CSV file for reading.");
    }
    // discard header
    fgetcsv($handle, 0, ",", '"', "\\");
    while (($row = fgetcsv($handle, 0, ",", '"', "\\")) !== false) {
      $read_rows[] = $row;
    }
    fclose($handle);
  } elseif ($extension === "xlsx") {
    $spreadsheet = inv_load_spreadsheet_file($filePath);
    $data = inv_sheet_to_array($spreadsheet->getActiveSheet(), true);
    array_shift($data); // remove header
    $read_rows = $data;
  } else {
    throw new RuntimeException("Unsupported file type for barcode import.");
  }

  $stmt_find_sku = $conn->prepare("SELECT id FROM sku WHERE sku_num = ? LIMIT 1");
  $stmt_upsert = $conn->prepare("
    INSERT INTO product_uom_barcodes (upc, sku_id, uom_label, multiplier)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
      sku_id = VALUES(sku_id),
      uom_label = VALUES(uom_label),
      multiplier = VALUES(multiplier)
  ");

  $max_samples = 30;

  foreach ($read_rows as $row) {
    $rowAssoc = array_combine($columns, array_values($row));
    $code_col = $columnMap["code"] ?? "";
    $item_col = $columnMap["item_no"] ?? "";
    $uom_col = $columnMap["unit_of_measure_code"] ?? "";
    $mult_col = $columnMap["multiplier"] ?? "";

    $upc = trim((string) ($rowAssoc[$code_col] ?? ""));
    $sku_num = trim((string) ($rowAssoc[$item_col] ?? ""));
    $uom_label = strtoupper(trim((string) ($rowAssoc[$uom_col] ?? "")));

    if ($uom_label === "") {
      $uom_label = "UNIT";
    }

    if ($uom_label === "MAST-CASE" || $uom_label === "CASE") {
      $uom_label = "MASTERCASE";
    }

    $multiplier_raw = $mult_col !== "" ? ($rowAssoc[$mult_col] ?? "") : "";
    $multiplier = is_numeric($multiplier_raw) ? (int) $multiplier_raw : null;

    if ($multiplier === null) {
      if ($uom_label === "UNIT") {
        $multiplier = 1;
      } elseif ($uom_label === "CARTON" && $default_carton_multiplier > 0) {
        $multiplier = $default_carton_multiplier;
      } elseif ($uom_label === "MASTERCASE" && $default_mastercase_multiplier > 0) {
        $multiplier = $default_mastercase_multiplier;
      } elseif ($uom_label === "CASE" && $default_case_multiplier > 0) {
        $multiplier = $default_case_multiplier;
      } else {
        $multiplier = 1; // permissive fallback to avoid skips
      }
    }

    if ($upc === "" || $sku_num === "" || $uom_label === "" || $multiplier === null || $multiplier <= 0) {
      $skipped++;
      if (count($row_log["skipped"]) < $max_samples) {
        $row_log["skipped"][] = [
          "code" => $upc,
          "sku_num" => $sku_num,
          "uom" => $uom_label,
          "reason" => "Missing required value or multiplier",
        ];
      }
      continue;
    }

    $stmt_find_sku->bind_param("s", $sku_num);
    $stmt_find_sku->execute();
    $sku_res = $stmt_find_sku->get_result();
    $sku_id = ($sku_res && ($r = $sku_res->fetch_assoc())) ? (int) $r["id"] : 0;
    if ($sku_res instanceof mysqli_result) {
      $sku_res->free();
    }

    if ($sku_id <= 0) {
      $skipped++;
      if (count($row_log["skipped"]) < $max_samples) {
        $row_log["skipped"][] = [
          "code" => $upc,
          "sku_num" => $sku_num,
          "uom" => $uom_label,
          "reason" => "SKU not found",
        ];
      }
      continue;
    }

    $stmt_upsert->bind_param("sisi", $upc, $sku_id, $uom_label, $multiplier);
    $stmt_upsert->execute();
    $aff = $stmt_upsert->affected_rows;
    if ($aff === 1) {
      $inserted++;
    } elseif ($aff >= 2) {
      $updated++;
    } else {
      $skipped++;
    }

    if (count($row_log["imported"]) < $max_samples) {
      $row_log["imported"][] = [
        "code" => $upc,
        "sku_num" => $sku_num,
        "uom" => $uom_label,
        "multiplier" => $multiplier,
      ];
    }
  }

  $stmt_find_sku->close();
  $stmt_upsert->close();

  return $inserted + $updated;
}

// --- Main Page Logic ---

$title = "Data Import";
$error = "";
$message = "";
$importSuccess = $_SESSION['import_success'] ?? null;
unset($_SESSION['import_success']);
$previewData = [];
$filename = "";
$tempPath = "";
$aiSuggestion = "";
$imported_rows = 0; // Initialize for use in the POST block

// Do not accept display HTML or decode messages from the query string.
// PHP has already decoded query parameters before they reach $_GET.

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (isset($_FILES["import_file"])) {
    $file = $_FILES["import_file"];

    if ($file["error"] !== UPLOAD_ERR_OK) {
      $error = "File upload failed with error code: " . $file["error"];
      log_import_event(
        "not-imported",
        $current_target["name"],
        (string) ($file["name"] ?? "unknown"),
        ["reason" => $error],
      );
    } elseif ($file["size"] === 0) {
      $error = "The uploaded file is empty.";
      log_import_event(
        "not-imported",
        $current_target["name"],
        (string) ($file["name"] ?? "unknown"),
        ["reason" => $error],
      );
    } else {
      $extension = strtolower(
        (string) pathinfo((string) $file["name"], PATHINFO_EXTENSION),
      );

      if (!in_array($extension, ["csv", "xlsx"])) {
        $error = "Only CSV and XLSX files are supported.";
        log_import_event(
          "not-imported",
          $current_target["name"],
          (string) ($file["name"] ?? "unknown"),
          ["reason" => $error],
        );
      } else {
        // Determine the correct temp file extension
        $tempPath =
          sys_get_temp_dir() .
          "/" .
          basename((string) $file["tmp_name"]) .
          "." .
          $extension;

        if (move_uploaded_file((string) $file["tmp_name"], $tempPath)) {
          $filename = h((string) $file["name"]);
          $previewResult = read_file_for_preview(
            $tempPath,
            $extension,
            $PREVIEW_ROW_CAP,
          );

          if ($previewResult["error"] === "") {
            $previewData["columns"] = $previewResult["columns"];
            $previewData["rows"] = $previewResult["rows"];
            $totalRows = $previewResult["total_rows"];

            // Store for confirmation step and AI analysis
            $_SESSION["import_file_path"] = $tempPath;
            $_SESSION["import_columns"] = $previewData["columns"];
            $_SESSION["import_filename"] = $filename;

            // Set simple success message for upload - NO BANNER/TOAST HERE
            $message =
              "File **{$filename}** ({$extension}) uploaded successfully. Preview below. Total rows detected: **" .
              number_format($totalRows) .
              "**.";

            // AI Feature: Call the centralized AiService class
            if ($AI_ENABLED && !empty($previewData["columns"])) {
              try {
                $aiService = new AiService($OPENAI_KEY);
                $aiSuggestion = $aiService->getFormatSuggestion(
                  $conn,
                  $previewData["columns"],
                );
              } catch (InvalidArgumentException $e) {
                $aiSuggestion = "AI Initialization Error: " . $e->getMessage();
              }
            }
          } else {
            $error = $previewResult["error"];
            if (is_file($tempPath)) {
              unlink($tempPath); // Clean up temp file on read error
            }
            log_import_event(
              "not-imported",
              $current_target["name"],
              (string) ($file["name"] ?? "unknown"),
              ["reason" => $error],
            );
          }
        } else {
          $error = "Failed to move the uploaded file to temp storage.";
          log_import_event(
            "not-imported",
            $current_target["name"],
            (string) ($file["name"] ?? "unknown"),
            ["reason" => $error],
          );
        }
      }
    }
  } elseif (isset($_POST["confirm_insert"])) {
    $path = $_SESSION["import_file_path"] ?? "";
    $filename = $_SESSION["import_filename"] ?? "uploaded file";
    $columns = $_SESSION["import_columns"] ?? [];
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

    $target_key = $_POST["import_target"] ?? "sku";
    $current_target = $IMPORT_TARGETS[$target_key] ?? $IMPORT_TARGETS["sku"];
    $import_func = $current_target["import_function"];
    $target_table = $current_target["name"]; // Use dynamic name
    $DB_TARGET_FIELDS = $current_target["required_fields"];

    // --- NEW: Retrieve the user-defined column map ---
    $userColumnMap = $_POST["column_map"] ?? [];

    // --- NEW: Basic validation for required fields using the $DB_TARGET_FIELDS array ---
    $requiredMapValid = true;
    foreach ($DB_TARGET_FIELDS as $db_col => $info) {
      if (
        $info["required"] &&
        (empty($userColumnMap[$db_col]) || $userColumnMap[$db_col] === "")
      ) {
        $error = "Required database field **{$info["label"]}** must be mapped to a file column.";
        $requiredMapValid = false;
        log_import_event(
          "not-imported",
          $target_table,
          $filename,
          [
            "reason" => "Missing required mapping",
            "field" => $info["label"],
            "extension" => $extension,
          ],
        );
        break;
      }
    }

    if (!$requiredMapValid) {
      // Stop execution, the form will be re-displayed with the error.
    } elseif ($path === "" || !is_file($path)) {
      $error = "Import session expired or file not found. Please re-upload.";
      log_import_event(
        "not-imported",
        $target_table,
        $filename,
        [
          "reason" => "Session expired or temp file missing",
          "extension" => $extension,
        ],
      );
    } else {
      try {
        $conn->begin_transaction();

        if (!function_exists($import_func)) {
          throw new RuntimeException(
            "Database import function '{$import_func}' is not defined in the import helpers.",
          );
        }
        // Set a fresh per-row log file for this import run
        if (!is_dir($IMPORT_ROW_LOG_DIR)) {
          @mkdir($IMPORT_ROW_LOG_DIR, 0775, true);
        }
        $GLOBALS["INV_ERROR_LOG_PATH_OVERRIDE"] =
          $IMPORT_ERROR_LOG_DIR .
          "/errors_" .
          gmdate("Ymd_His") .
          "_" .
          bin2hex(random_bytes(4)) .
          ".log";
        $current_row_log_path =
          $IMPORT_ROW_LOG_DIR .
          "/rows_" .
          gmdate("Ymd_His") .
          "_" .
          bin2hex(random_bytes(4)) .
          ".log";
        $GLOBALS["INV_ROW_LOG_PATH_OVERRIDE"] = $current_row_log_path;
        $row_log = [];
        $imported_rows = $import_func(
          $conn,
          $path,
          $user_id,
          $columns,
          $extension,
          $userColumnMap,
          $row_log,
        );

        $conn->commit();

        // Fallback: if no row-level samples were captured, build a small sample from the file for visibility.
        if (
          ($row_log["imported"] ?? []) === [] &&
          $imported_rows > 0 &&
          is_file($path)
        ) {
          $fallbackLimit = 50;
          $fallbackImported = [];
          try {
            if ($extension === "csv") {
              $handle = fopen($path, "rb");
              if ($handle !== false) {
                // Skip header
                fgetcsv($handle, 0, ",", '"', "\\");
                while (
                  count($fallbackImported) < $fallbackLimit &&
                  ($row = fgetcsv($handle, 0, ",", '"', "\\")) !== false
                ) {
                  $row = array_slice(array_values($row), 0, count($columns));
                  $rowAssoc = array_combine(
                    $columns,
                    array_pad($row, count($columns), ""),
                  );
                  $mapped = [];
                  foreach ($userColumnMap as $dbCol => $fileCol) {
                    $mapped[$dbCol] = $rowAssoc[$fileCol] ?? "";
                  }
                  $fallbackImported[] = $mapped;
                }
                fclose($handle);
              }
            } elseif ($extension === "xlsx") {
              $spreadsheet = inv_load_spreadsheet_file($path);
              $worksheet = $spreadsheet->getActiveSheet();
              $data = inv_sheet_to_array($worksheet, true);
              if (function_exists("inv_detect_header_row")) {
                $headerRowNumber = inv_detect_header_row($worksheet);
                // inv_sheet_to_array() is zero-indexed; slicing at N skips rows 1..N.
                $data = array_slice($data, $headerRowNumber);
              } elseif (function_exists("detect_sales_header_row")) {
                $headerRowNumber = detect_sales_header_row($worksheet);
                $data = array_slice($data, $headerRowNumber);
              } else {
                array_shift($data); // remove row-1 header
              }
              foreach ($data as $row) {
                if (count($fallbackImported) >= $fallbackLimit) {
                  break;
                }
                $row = array_slice(array_values($row), 0, count($columns));
                $rowAssoc = array_combine(
                  $columns,
                  array_pad($row, count($columns), ""),
                );
                $mapped = [];
                foreach ($userColumnMap as $dbCol => $fileCol) {
                  $mapped[$dbCol] = $rowAssoc[$fileCol] ?? "";
                }
                $fallbackImported[] = $mapped;
              }
            }
          } catch (Throwable $e) {
            // If fallback fails, leave samples empty.
          }

          if (!empty($fallbackImported)) {
            $row_log["imported"] = $fallbackImported;
            if (!isset($row_log["truncated"])) {
              $row_log["truncated"] = [];
            }
            $row_log["truncated"]["imported"] =
              count($fallbackImported) >= $fallbackLimit;
          }
        }

        // 2. CONFIGURE TOAST MESSAGE FOR REDIRECT (This is the required banner)
        $formatted_rows = number_format($imported_rows);
        $badge_html = '<span class="badge-ok">' . $formatted_rows . "</span>";
        $target_name_escaped = h($target_table);
        $filename_escaped = h($filename);

        log_import_event(
          "imported",
          $target_table,
          $filename,
          [
            "rows_imported" => $imported_rows,
            "extension" => $extension,
            "row_summary" => summarize_row_log($row_log),
            "row_log_file" => basename($GLOBALS["INV_ROW_LOG_PATH_OVERRIDE"] ?? ""),
            "error_log_file" => basename($GLOBALS["INV_ERROR_LOG_PATH_OVERRIDE"] ?? ""),
          ],
        );

        $_SESSION['import_success'] = [
          'filename' => $filename,
          'target' => $target_table,
          'rows_imported' => $imported_rows,
        ];

        header('Location: import.php');
        exit();
      } catch (Throwable $e) {
        // ... (Error handling remains the same) ...
        if (isset($conn)) {
          $conn->rollback();
        }
        log_import_event(
          "not-imported",
          $target_table,
          $filename,
          [
            "reason" => $e->getMessage(),
            "extension" => $extension,
            "row_summary" => summarize_row_log($row_log ?? []),
          ],
        );
        $error = "Import failed due to an error: " . h($e->getMessage());
      }
    }
  }
}

// Ensure clean up if session is present and file is gone (e.g., manual deletion)
if (
  !empty($_SESSION["import_file_path"]) &&
  !is_file($_SESSION["import_file_path"])
) {
  unset(
    $_SESSION["import_file_path"],
    $_SESSION["import_columns"],
    $_SESSION["import_filename"],
  );
}

// Check if preview data exists and extract the first row
$firstRowData = $previewData["rows"][0] ?? [];

if (isset($_POST["confirm_insert"]) && empty($previewData["rows"])) {
  if (!empty($_SESSION["import_columns"])) {
    if (isset($_POST["confirm_insert"])) {
      $target_key = $_POST["import_target"] ?? "sku";
      $current_target = $IMPORT_TARGETS[$target_key] ?? $IMPORT_TARGETS["sku"];
      $DB_TARGET_FIELDS = $current_target["required_fields"];

      // Re-populate preview data from session/POST if mapping fails validation
      if (isset($_SESSION["import_columns"])) {
        $previewData["columns"] = $_SESSION["import_columns"];
      }
    }
  }
}

// Extract the first row, only if preview data exists.
$firstRowData = $previewData["rows"][0] ?? [];

ob_start();

// --- Optional: Latest import log preview for quick visibility ---
$latestLogEntry = null;
if (is_file($IMPORT_LOG_PATH)) {
  $lines = file($IMPORT_LOG_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!empty($lines)) {
    $latestLogEntry = json_decode((string) end($lines), true);
  }
}

// --- Error log reader (focus on skipped rows) ---
$latestErrorLogPath = null;
$errorLogEntries = [];
$errStartRaw = $_GET["err_start"] ?? "";
$errEndRaw = $_GET["err_end"] ?? "";
$defaultStart = (new DateTimeImmutable("now"))->sub(new DateInterval("P1D"));
$defaultEnd = new DateTimeImmutable("now");
$parseIso = function (string $val): ?DateTimeImmutable {
  $val = trim($val);
  if ($val === "") {
    return null;
  }
  try {
    return new DateTimeImmutable($val);
  } catch (Throwable $e) {
    return null;
  }
};

$errStart = $parseIso($errStartRaw) ?? $defaultStart;
$errEnd = $parseIso($errEndRaw) ?? $defaultEnd;

// Resolve error logs only from the application-owned error-log directory.
$errorLogDir = realpath($IMPORT_ERROR_LOG_DIR);
if ($errorLogDir !== false && is_dir($errorLogDir)) {
  $errorLogs = glob($errorLogDir . DIRECTORY_SEPARATOR . "errors_*.log") ?: [];
  if (!empty($errorLogs)) {
    usort($errorLogs, function ($a, $b) {
      return filemtime($a) <=> filemtime($b);
    });
    $latestErrorLogPath = end($errorLogs);
  }
}

// Gather available row logs for download from the approved directory only.
$downloadableLogs = [];
$rowLogDir = realpath($IMPORT_ROW_LOG_DIR);
if ($rowLogDir !== false && is_dir($rowLogDir)) {
  $downloadableLogs = glob($rowLogDir . DIRECTORY_SEPARATOR . "*.log") ?: [];
  $downloadableLogs = array_values(array_filter($downloadableLogs, static function (string $path) use ($rowLogDir): bool {
    $resolved = realpath($path);
    return $resolved !== false && is_file($resolved)
      && dirname($resolved) === $rowLogDir
      && strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) === 'log';
  }));
  usort($downloadableLogs, function ($a, $b) {
    return filemtime($b) <=> filemtime($a);
  });
}
$selectedDownloadLog = basename((string) ($_GET["download_log_file"] ?? ""));
$selectedDownloadPath = null;
foreach ($downloadableLogs as $logPath) {
  if (basename($logPath) === $selectedDownloadLog) {
    $selectedDownloadPath = $logPath;
    break;
  }
}

// If download requested, stream selected log (row logs list)
if (isset($_GET["download_error_log"])) {
  $pathToDownload = $selectedDownloadPath ?? ($downloadableLogs[0] ?? null);
  if ($pathToDownload !== null) {
    header("Content-Type: text/plain");
    header(
      'Content-Disposition: attachment; filename="' .
      basename($pathToDownload) .
      '"',
    );
    readfile($pathToDownload);
    exit();
  } else {
    $error =
      trim($error . " | ") .
      "Requested log not found. Checked: " .
      h((string) ($selectedDownloadLog ?: $IMPORT_ROW_LOG_DIR));
  }
}

// Build skipped entries (for UI display)
if ($latestErrorLogPath && is_file($latestErrorLogPath)) {
  $lines = file($latestErrorLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
  foreach ($lines as $line) {
    if (!preg_match('/^\\[(.+?)\\]\\s+(.*)$/', $line, $m)) {
      continue;
    }
    [$full, $ts, $msg] = $m;
    try {
      $dt = new DateTimeImmutable($ts);
    } catch (Throwable $e) {
      continue;
    }
    if ($dt < $errStart || $dt > $errEnd) {
      continue;
    }
    if (stripos($msg, "skipped") === false) {
      continue;
    }
    $errorLogEntries[] = [
      "ts" => $dt->format(DateTimeInterface::ATOM),
      "msg" => $msg,
    ];
  }
}
?>

<h2 class="title">Data Import Wizard</h2>

<?php if ($error !== ""): ?>
  <p class="message message--bad" style="margin-bottom: 20px;"><?= h(
    $error,
  ) ?></p>
<?php endif; ?>

<?php if ($message !== ""): ?>
  <p style="font-weight: bold; margin-bottom: 20px; color: #008000;">
    <?= h($message) ?>
  </p>
<?php endif; ?>

<?php if (is_array($importSuccess)): ?>
  <div class="toast is-success"
    style="align-items: center; justify-content: space-between; max-width: 800px; margin: 20px 0;">
    <div>
      Import Complete for <strong><?= h($importSuccess['filename'] ?? '') ?></strong>
      into <strong><?= h($importSuccess['target'] ?? '') ?></strong><br>
      Rows Imported: <span class="badge-ok"><?= (int) ($importSuccess['rows_imported'] ?? 0) ?></span>
    </div>
  </div>
<?php endif; ?>


<div class="card card--pad">
  <h3>Step 1: Select Target & Upload File</h3>
  <form class="form" method="post" enctype="multipart/form-data" action="">

    <label for="import_target" style="display: block; margin-bottom: 5px; font-weight: bold;">Select Target
      Table:</label>
    <select class="input" id="import_target" name="import_target" required style="margin-bottom: 15px;">
      <?php foreach ($IMPORT_TARGETS as $key => $target): ?>
        <option value="<?= h($key) ?>" <?= $target_key === $key
            ? "selected"
            : "" ?>>
          <?= h($target["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label for="import_file" style="display: block; margin-bottom: 5px; font-weight: bold;">Select File (.csv or
      .xlsx):</label>
    <input class="input" type="file" name="import_file" id="import_file" accept=".csv, .xlsx" required />

    <button class="btn btn--primary" type="submit" style="margin-top:15px;">Upload & Preview Data</button>
  </form>
</div>

<?php if (!empty($previewData["rows"])): ?>

  <div id="import-preview-modal-backdrop" class="modal-backdrop" style="display: none;"></div>

  <div id="import-preview-modal" class="modal" style="display: none;">
    <div class="modal-content card card--pad">

      <h3>Preview: <?= $_SESSION["import_filename"] ??
        "" ?> (showing first <?= $PREVIEW_ROW_CAP ?> rows)</h3>

      <form method="post" action="import.php">
        <input type="hidden" name="confirm_insert" value="1" />
        <input type="hidden" name="import_target" value="<?= h(
          $target_key,
        ) ?>" />
        <h4><?= empty($error)
          ? "Step 2: Map Columns and Confirm Import"
          : "Step 2: Map Columns (Resolve Errors to Proceed)" ?></h4>

        <p>Match your uploaded file's columns to the required database fields for the **<?= h(
          $current_target["name"],
        ) ?>** table. The mapped columns will be used for insertion.</p>

        <?php if ($target_key === 'shelf_tags'): ?>
          <p>Shelf tags use aisle, section, and shelf_level as the source of truth.
            tag_code is generated as aisle-section-shelf_level (for example, S11-10-12).
            Barcode joins the same values without separators (S111012).
            Any tag_code or barcode columns in the file are ignored.</p>
          <p id="shelf-tag-generated-preview" aria-live="polite"></p>
        <?php endif; ?>

        <div class="table-wrap">
          <table class="table table--map">
            <thead>
              <tr>
                <th>Database Field</th>
                <th>Required?</th>
                <th>Sample Data (First Row)</th>
                <th>Select Matching File Column</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (
                $DB_TARGET_FIELDS
                as $db_col => $info
              ):

                $isRequired = $info["required"];
                $label = $info["label"]; // 1. Check for persistence (if user submitted the form before)
                $final_selected_file_col =
                  $_POST["column_map"][$db_col] ?? null; // 2. Apply Heuristic if no persistence exists (first load after upload)
                if ($final_selected_file_col === null) {
                  $db_col_normalized = strtolower($db_col);
                  $label_normalized = strtolower($label);
                  $aliases = array_map(
                    static fn($alias) => strtolower(
                      trim((string) $alias),
                    ),
                    $info["aliases"] ?? [],
                  );
                  foreach ($previewData["columns"] as $file_col) {
                    $file_col_normalized = strtolower(
                      trim($file_col),
                    );
                    if (
                      $file_col_normalized ===
                      $db_col_normalized ||
                      $file_col_normalized === $label_normalized
                    ) {
                      $final_selected_file_col = $file_col;
                      break;
                    }
                    if (
                      in_array(
                        $file_col_normalized,
                        $aliases,
                        true,
                      )
                    ) {
                      $final_selected_file_col = $file_col;
                      break;
                    }
                    if (
                      str_replace(
                        "_num",
                        "",
                        $db_col_normalized,
                      ) === $file_col_normalized
                    ) {
                      $final_selected_file_col = $file_col;
                      break;
                    }
                  }
                } // Use the selected column name to find the corresponding sample data
                $sample_data_for_preview = "";
                if ($final_selected_file_col !== null) {
                  $col_index = array_search(
                    $final_selected_file_col,
                    $previewData["columns"],
                  );
                  if ($col_index !== false) {
                    $sample_data_for_preview =
                      $firstRowData[$col_index] ?? "";
                  }
                }
                ?>
                <tr>
                  <td style="font-weight: bold;"><?= h(
                    $label,
                  ) ?></td>
                  <td style="color: <?= $isRequired
                    ? "#b00020"
                    : "#008000" ?>;"><?= $isRequired
                      ? "Yes"
                      : "No" ?></td>

                  <td>
                    <span class="first-row-preview" data-db-col="<?= h(
                      $db_col,
                    ) ?>">
                      <?php if (
                        $final_selected_file_col === null
                      ) {
                        echo "(Select column above)";
                      } else {
                        echo h(
                          $sample_data_for_preview,
                        ) ?:
                          "(Empty Value)";
                      } ?>
                    </span>
                  </td>

                  <td>
                    <select name="column_map[<?= h(
                      $db_col,
                    ) ?>]" class="input column-mapper" <?= $isRequired ? "required" : "" ?>
                      style="width: 100%; min-width: 250px;">
                      <option value="" <?= $final_selected_file_col ===
                        null
                        ? "selected"
                        : "" ?>>-- DO NOT IMPORT --</option>
                      <?php foreach (
                        $previewData["columns"]
                        as $col_index => $file_col
                      ): ?>
                        <option value="<?= h($file_col) ?>" data-col-index="<?= h(
                            $col_index,
                          ) ?>" data-sample-data="<?= h(
                             $firstRowData[
                               $col_index
                             ] ?? "",
                           ) ?>" <?= $final_selected_file_col ===
                              $file_col
                              ? "selected"
                              : "" ?>>
                          <?= h($file_col) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                </tr>
                <?php
              endforeach; ?>
            </tbody>
          </table>
        </div>

        <p style="margin-top:20px;">Review the preview and column mapping. Ready to insert this data?</p>

        <div style="display: flex; gap: 10px; margin-top: 15px;">
          <button class="btn btn--primary" type="submit">Confirm and Insert Data</button>
          <button type="button" class="btn btn--secondary modal-close">Cancel / Close Preview</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if (!empty($latestLogEntry) || !empty($downloadableLogs)): ?>
  <div class="card card--pad" style="margin-top:20px;">
    <h3 style="margin:0 0 10px 0;">Latest Import Log & Download Logs</h3>

    <?php if (!empty($latestLogEntry)): ?>
      <div style="margin-bottom:16px;">
        <p><strong>Timestamp:</strong> <?= h($latestLogEntry["timestamp"] ?? "") ?></p>
        <p><strong>User:</strong> <?= h($latestLogEntry["user"] ?? "") ?></p>
        <p><strong>File:</strong> <?= h($latestLogEntry["filename"] ?? "") ?> (<?= h(
               $latestLogEntry["details"]["extension"] ?? "",
             ) ?>)</p>
        <p><strong>Imported Count:</strong> <?= h(
          $latestLogEntry["details"]["rows_imported"] ?? ($latestLogEntry["details"]["row_summary"]["imported_count"] ?? 0),
        ) ?> (showing up to 30)</p>
        <p><strong>Skipped Count:</strong> <?= h(
          $latestLogEntry["details"]["row_summary"]["skipped_count"] ?? 0,
        ) ?> (showing up to 30)</p>

        <?php
        $importedSample = $latestLogEntry["details"]["row_summary"]["imported"] ?? [];
        $importedSampleSlice = array_slice($importedSample, 0, 30);
        $skippedSample = $latestLogEntry["details"]["row_summary"]["skipped"] ?? [];
        $skippedSampleSlice = array_slice($skippedSample, 0, 30);
        ?>

        <details>
          <summary>Imported sample</summary>
          <?php
          if (!empty($importedSampleSlice)) {
            echo "<pre style=\"white-space:pre-wrap;max-height:300px;overflow:auto;\">" .
              h(json_encode($importedSampleSlice, JSON_PRETTY_PRINT)) .
              "</pre>";
          } else {
            echo "<p style=\"margin-top:6px;color:#555;\">No row samples recorded for this entry. Re-run an import to capture samples.</p>";
          }
          ?>
        </details>

        <details style="margin-top:8px;">
          <summary>Skipped sample</summary>
          <?php
          if (!empty($skippedSampleSlice)) {
            echo "<pre style=\"white-space:pre-wrap;max-height:300px;overflow:auto;\">" .
              h(json_encode($skippedSampleSlice, JSON_PRETTY_PRINT)) .
              "</pre>";
          } else {
            echo "<p style=\"margin-top:6px;color:#555;\">No skipped rows recorded for this entry.</p>";
          }
          ?>
        </details>
      </div>
    <?php else: ?>
      <p style="margin-bottom:12px;">No import log found yet.</p>
    <?php endif; ?>

    <div style="border-top:1px solid #e0e0e0; padding-top:12px; margin-top:12px;">
      <h4 style="margin:0 0 8px 0;">Download Log</h4>
      <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <div>
          <label for="download_log_file" style="font-weight:bold;">Select log</label>
          <select class="input" id="download_log_file" name="download_log_file" style="min-width:320px;">
            <?php foreach ($downloadableLogs as $logPath): ?>
              <option value="<?= h(basename($logPath)) ?>" <?= $selectedDownloadLog === basename($logPath) ? "selected" : "" ?>>
                <?= h(basename($logPath)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <input type="hidden" name="download_error_log" value="1" />
        <button class="btn btn--primary" type="submit">Download</button>
      </form>
      <?php if (empty($downloadableLogs)): ?>
        <p style="margin-top:10px;">No logs found in <?= h($IMPORT_ROW_LOG_DIR) ?>.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php
$content = ob_get_clean();

// Simple cache-busted import preview script (relative to this module directory).
$js_fs = __DIR__ . '/js/import-preview.js';
$ver = is_file($js_fs) ? filemtime($js_fs) : time();
$script_src = 'js/import-preview.js?v=' . $ver;
$footer_js = '<script src="' . htmlspecialchars($script_src) . '"></script>' . "\n";

include __DIR__ . '/templates/layout.php';
