<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory; 

// NOTE: This file assumes $conn, h(), and file reading helpers are available 
// via includes in the main import.php script.

/**
 * Processes a file and imports/updates SKU records in the 'sku' table.
 * (Row-by-Row Method)
 */
function import_sku_details_from_file(
    \mysqli $conn, 
    string $filePath, 
    int $userId, 
    array $fileColumns, 
    string $extension,
    array $userColumnMap 
): int {
    
    $columnMap = $userColumnMap;
    $importedCount = 0;
    
    // Initialize statement variables for proper resource cleanup
    $stmt = null; 
    $stmt_get_upc = null;
    $stmt_update_upc = null;

    try {
        $dataRows = []; 

        // --- 1. Read Data (Keeping your existing file reading logic) ---
        if ($extension === 'csv') {
            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open the temporary CSV file for reading.');
            }
            fgetcsv($handle); 
            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === false) continue;
                $dataRows[] = array_combine($fileColumns, array_pad($row, count($fileColumns), ''));
            }
            fclose($handle);

        } elseif ($extension === 'xlsx') {
            // NOTE: Requires 'use PhpOffice\PhpSpreadsheet\IOFactory;' at the file top
            $spreadsheet = IOFactory::load($filePath);
            $data = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
            array_shift($data); // Remove header
            
            foreach ($data as $row) {
                $dataRows[] = array_combine($fileColumns, array_pad($row, count($fileColumns), ''));
            }
        } else {
            throw new RuntimeException("Unsupported file extension: " . $extension);
        }

        // --- 2. Phase 1: Main UPSERT (Insert/Update DESC and STATUS) ---
        // SQL now uses a placeholder for STATUS to allow user import
        $sql = "INSERT INTO `sku` (`sku_num`, `desc`, `status`) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE 
        `desc` = VALUES(`desc`), `status` = VALUES(`status`)";
        
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException("SQL preparation failed: " . $conn->error);
        }
        
        // --- 3. Execute Phase 1 ---
        foreach ($dataRows as $row) {
    
            // Apply Mapping and Extract/Sanitize
            $sku_num = trim((string) ($row[$columnMap['sku_num']] ?? ''));
            $upc     = trim((string) ($row[$columnMap['upc']] ?? null)); 
            $desc    = trim((string) ($row[$columnMap['desc']] ?? ''));
            
            // ✅ NEW: Extract status from file, default to 'ACTIVE' if missing or empty
            $status  = trim((string) ($row[$columnMap['status']] ?? 'ACTIVE'));
            if (empty($status)) {
                $status = 'ACTIVE';
            }
        
            // Basic Validation 
            if (empty($sku_num) || empty($desc)) {
                error_log("SKU import skipped row due to missing required data: " . json_encode($row));
                continue; 
            }
            if (strlen($sku_num) > 50 || strlen($desc) > 255 || (isset($upc) && strlen($upc) > 32)) {
                 error_log("SKU import skipped row due to data length constraints: " . json_encode($row));
                continue;
            }
            // Add status length check if needed (e.g., if status column is VARCHAR(10))
            if (strlen($status) > 10) { 
                 error_log("SKU import skipped row due to status length constraints: " . json_encode($row));
                continue;
            }
            
            // ✅ CORRECT BINDING: Now binds three strings: 'sss' (sku_num, desc, status)
            if (!$stmt->bind_param('sss', $sku_num, $desc, $status)) { 
                error_log("SKU bind_param failed for {$sku_num}: " . $stmt->error);
                continue; 
            }
            
            if ($stmt->execute()) {
                $importedCount++;
            } else {
                error_log("SKU insertion failed for row: " . $stmt->error);
            }
        }
        $stmt->close(); // Close Phase 1 statement immediately

        // --- 4. Phase 2: Conditional UPC Update Logic ---
        $stmt_get_upc = $conn->prepare("SELECT id, upc FROM sku WHERE sku_num = ?");
        if (!$stmt_get_upc) { throw new RuntimeException("UPC Lookup preparation failed: " . $conn->error); }

        $stmt_update_upc = $conn->prepare("UPDATE sku SET upc = ? WHERE id = ?");
        if (!$stmt_update_upc) { throw new RuntimeException("UPC Update preparation failed: " . $conn->error); }


        foreach ($dataRows as $row) {
            // 1. Extraction and Sanitization
            $sku_num_raw = trim((string) ($row[$columnMap['sku_num']] ?? ''));
            $upc_new_clean = preg_replace('/\s+/', '', (string) ($row[$columnMap['upc']] ?? ''));
            
            if (empty($sku_num_raw) || $upc_new_clean === '') {
                continue;
            }
            
            // 2. Look up the existing UPC and ID
            $stmt_get_upc->bind_param("s", $sku_num_raw);
            $stmt_get_upc->execute();
            $result = $stmt_get_upc->get_result();
            $sku_data = $result->fetch_assoc();

            if ($sku_data) {
                $sku_id = (int) $sku_data['id'];
                $existing_upc_clean = preg_replace('/\s+/', '', (string) ($sku_data['upc'] ?? ''));

                if ($upc_new_clean !== $existing_upc_clean) {
                    
                    // 3. Attempt to update the UPC
                    try {
                        $stmt_update_upc->bind_param("si", $upc_new_clean, $sku_id); 
                        $stmt_update_upc->execute(); 
                        
                        // 4. Check for errors AFTER execution
                        if ($conn->errno !== 0) { 
                            if ($conn->errno === 1062) {
                                error_log("UPC Update FAILED (Duplicate): SKU {$sku_num_raw} skipped. New UPC: '{$upc_new_clean}'.");
                            } else {
                                error_log("UPC Update FAILED (Error {$conn->errno}): SKU {$sku_num_raw}. Error: " . $conn->error);
                            }
                        } else {
                            error_log("UPC Update SUCCESS: SKU {$sku_num_raw} updated to '{$upc_new_clean}'.");
                        }
                    } catch (Throwable $e) {
                        // Correctly placed inner catch
                        error_log("Unhandled exception during UPC update for {$sku_num_raw}: " . $e->getMessage());
                    }
                }
            }
        } // end foreach ($dataRows as $row)

        $stmt_get_upc->close(); // Close Phase 2 statements
        $stmt_update_upc->close();
        
        return $importedCount; // Successful exit

    } catch (Throwable $e) {
        // Correct resource cleanup on error
        if ($stmt) $stmt->close(); 
        if ($stmt_get_upc) $stmt_get_upc->close();
        if ($stmt_update_upc) $stmt_update_upc->close();

        throw new RuntimeException("Import processing failed: " . $e->getMessage());
    }
}


/**
 * Processes a file and imports/updates location records in the 'location' table.
 */
function import_location_details_from_file(
    mysqli $conn, 
    string $filePath, 
    int $userId, 
    array $fileColumns, 
    string $extension,
    array $userColumnMap // Must now map to row_code, bay_num, level_code, side, and possibly is_disabled
): int {
    $columnMap = $userColumnMap;
    $importedCount = 0;
    $stmt = null; 

    try {
        // --- 1. Read Data (File reading logic must be included here) ---
        $dataRows = []; 
        // ... (Your CSV/XLSX reading logic to populate $dataRows) ...

        // --- 2. Prepare Prepared Statement for Insertion (Upsert) ---
        // SQL now targets the four component columns and the status column (is_disabled).
        $sql = "INSERT INTO `location` (`row_code`, `bay_num`, `level_code`, `side`, `is_disabled`) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    `is_disabled` = VALUES(`is_disabled`)"; 
                    // IMPORTANT: Only 'is_disabled' is updated on conflict. 
                    // The four dimensional columns are treated as immutable keys.
        
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException("SQL preparation failed for location: " . $conn->error);
        }
        
        // --- 3. Process Rows and Insert/Update ---
        foreach ($dataRows as $row) {
            
            // Apply Mapping and Extract/Sanitize
            // These MUST exist in the $userColumnMap for the function to work correctly
            $row_code   = trim((string) ($row[$columnMap['row_code']] ?? ''));
            $bay_num    = trim((string) ($row[$columnMap['bay_num']] ?? ''));
            $level_code = trim((string) ($row[$columnMap['level_code']] ?? ''));
            $side       = trim(strtoupper((string) ($row[$columnMap['side']] ?? ''))); // Ensure F/B is uppercase
            
            // Extract the 'is_disabled' flag or default to Active (0)
            $is_disabled_raw = trim((string) ($row[$columnMap['is_disabled']] ?? '0'));

            // Convert raw input to 0 (Active) or 1 (Disabled)
            // Accepts '1', 'T', 'TRUE', 'DISABLED' or similar as 1. Everything else is 0.
            $is_disabled = filter_var($is_disabled_raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ? 1 : 0;
            if ($is_disabled_raw === '0') $is_disabled = 0; // Ensure explicit '0' is 0
            
            // Basic Validation: All four unique key parts are required
            if (empty($row_code) || empty($bay_num) || empty($level_code) || empty($side)) {
                error_log("Location import skipped row due to missing required key data: " . json_encode($row));
                continue; 
            }
            // Side constraint check
            if ($side !== 'F' && $side !== 'B') {
                error_log("Location import skipped row: Side '{$side}' is invalid (must be F or B).");
                continue; 
            }
            
            // ✅ BINDING: ssssi (four strings, one integer/boolean)
            if (!$stmt->bind_param("ssssi", $row_code, $bay_num, $level_code, $side, $is_disabled)) {
                error_log("Location bind_param failed: " . $stmt->error);
                continue;
            }
            
            if ($stmt->execute()) {
                $importedCount++;
            } else {
                error_log("Location insertion failed for row: " . $stmt->error);
            }
        }

        $stmt->close();
        return $importedCount;

    } catch (Throwable $e) {
        if ($stmt) $stmt->close(); 
        throw new RuntimeException("Location Import processing failed: " . $e->getMessage());
    }
}

/**
 * Processes a file and imports/updates inventory counts in the 'inventory' table.
 */
function import_inventory_counts_from_file(
    mysqli $conn, 
    string $filePath, 
    int $userId, 
    array $fileColumns, 
    string $extension,
    array $userColumnMap
): int {
    // --- SETUP ---
    $columnMap = $userColumnMap;
    $importedCount = 0;
    
    // Initialize ALL statements for proper cleanup
    $stmt_sku_upsert = null;
    $stmt_inventory_upsert = null;
    
    // ⚠️ IMPORTANT: Replace 999 with the actual ID of your STAGING/IMPORT_HOLD location.
    $DEFAULT_LOCATION_ID = 999; 
    
    $skuIdCache = []; 
    $locationIdCache = []; 

    try {
        // --- 1. Read Data (File reading logic must be included here) ---
        $dataRows = []; 
        // ... (Your file reading logic to populate $dataRows goes here) ...

        // --- 2. Prepare Prepared Statements ---
        
        // Phase 1: SKU UPSERT (Requires 3 strings: sku_num, desc, status)
        $sql_sku = "INSERT INTO `sku` (`sku_num`, `desc`, `status`) 
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        `desc` = VALUES(`desc`), 
                        `status` = VALUES(`status`)";
        $stmt_sku_upsert = $conn->prepare($sql_sku);
        if ($stmt_sku_upsert === false) {
            throw new RuntimeException("SKU UPSERT preparation failed: " . $conn->error);
        }
        
        // Phase 3: Inventory UPSERT
        // SQL is updated to match your table: (sku_id, loc_id, quantity)
        // updated_at is handled by NOW() and implicitly set by the database
        $sql_inventory = "INSERT INTO `inventory` (`sku_id`, `loc_id`, `quantity`) 
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                            `quantity` = VALUES(`quantity`), 
                            `updated_at` = NOW()"; // <-- Explicitly set updated_at
                            
        $stmt_inventory_upsert = $conn->prepare($sql_inventory);
        if ($stmt_inventory_upsert === false) {
            throw new RuntimeException("Inventory UPSERT preparation failed: " . $conn->error);
        }
        
        // --- 3. Process Rows (The Combined Logic) ---
        foreach ($dataRows as $row) {
            
            // --- 3.1. Extraction & Validation ---
            $sku_num_raw  = trim((string) ($row[$columnMap['sku_num']] ?? ''));
            $loc_code_raw = trim((string) ($row[$columnMap['loc_code']] ?? ''));
            $quantity     = (int) trim((string) ($row[$columnMap['quantity']] ?? '0'));
            
            // SKU Data for Phase 1
            $desc   = trim((string) ($row[$columnMap['desc']] ?? ''));
            $status = trim((string) ($row[$columnMap['status']] ?? 'ACTIVE'));
            if (empty($status)) $status = 'ACTIVE';
            
            // Basic Validation
            if (empty($sku_num_raw) || empty($desc) || $quantity < 0) {
                error_log("Combined import skipped row: invalid SKU, description, or quantity. " . json_encode($row));
                continue; 
            }

            // --- 3.2. Phase 1: Execute SKU UPSERT (Ensures Parent Record Exists) ---
            if (!$stmt_sku_upsert->bind_param("sss", $sku_num_raw, $desc, $status)) {
                error_log("SKU bind_param failed: " . $stmt_sku_upsert->error);
                continue;
            }
            if (!$stmt_sku_upsert->execute()) {
                error_log("SKU insertion failed for: {$sku_num_raw}. Error: " . $stmt_sku_upsert->error);
                continue;
            }

            // --- 3.3. Phase 2: ID Lookups ---
            
            // SKU Lookup (Get ID for the Inventory table)
            if (!isset($skuIdCache[$sku_num_raw])) {
                // Requires find_sku_id_by_num helper function
                $skuIdCache[$sku_num_raw] = find_sku_id_by_num($conn, $sku_num_raw); 
            }
            $sku_id = $skuIdCache[$sku_num_raw];
            if ($sku_id === 0) continue; 

            // Location Lookup
            $loc_id = $DEFAULT_LOCATION_ID; 
            if (!empty($loc_code_raw)) {
                if (!isset($locationIdCache[$loc_code_raw])) {
                    // Requires find_loc_id helper function
                    $locationIdCache[$loc_code_raw] = find_loc_id($conn, $loc_code_raw); 
                }
                $temp_loc_id = $locationIdCache[$loc_code_raw];

                if ($temp_loc_id !== 0) {
                    $loc_id = $temp_loc_id;
                } else {
                    error_log("Location '{$loc_code_raw}' not found, defaulting to ID: {$DEFAULT_LOCATION_ID}");
                }
            }

            // --- 3.4. Phase 3: Execute Inventory UPSERT ---
            // Binding: iii (sku_id, loc_id, quantity)
            if (!$stmt_inventory_upsert->bind_param("iii", $sku_id, $loc_id, $quantity)) {
                 error_log("Inventory bind_param failed: " . $stmt_inventory_upsert->error);
                 continue;
            }
            
            if ($stmt_inventory_upsert->execute()) {
                $importedCount++;
            } else {
                error_log("Inventory insertion failed for SKU: {$sku_num_raw}. Error: " . $stmt_inventory_upsert->error);
            }
        }

        // --- 4. Cleanup ---
        $stmt_sku_upsert->close();
        $stmt_inventory_upsert->close();
        return $importedCount;

    } catch (Throwable $e) {
        // Cleanup all statements on failure
        if ($stmt_sku_upsert) $stmt_sku_upsert->close(); 
        if ($stmt_inventory_upsert) $stmt_inventory_upsert->close();
        
        throw new RuntimeException("Inventory Import processing failed: " . $e->getMessage());
    }
}