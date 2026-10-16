<?php
// access_control.php

// Page filename (key) => Array of allowed Role IDs (value).
$page_permissions_by_role = [
 'dashboard.php'   => [1, 2, 3, 4],
//  'ai_reports.php'   => [1, 2],
//  'import.php'     => [1], 
 'inspection.php' => [1, 2, 3, 4],
 'save_inspection.php' => [1, 2, 3, 4],
 'checklist_lift_truck.php' => [1, 2, 3, 4],
 'checklist_health_safety.php' => [1, 2, 3, 4],
 'checklist_warehouse.php' => [1, 2, 3, 4],
 'checklist_pest_control.php' => [1, 2, 3, 4],
];

$NAVBAR_DISPLAY_MAP = [
 'dashboard.php' => 'Dashboard',
 'checklist_lift_truck.php' => 'Lift Truck',
 'checklist_health_safety.php' => 'Health & Safety',
 'checklist_warehouse.php' => 'Warehouse',
 'checklist_pest_control.php' => 'Pest Control',
//  'ai_reports.php'      => 'Reports',
//  'import.php'          => 'Import',
];

// Nav target URLs (keyed by logical page key). Checklist routes are slug-driven.
$NAVBAR_ROUTE_MAP = [
 'dashboard.php' => 'dashboard.php',
 'checklist_lift_truck.php' => 'inspection.php?type=lift_truck_checklist',
 'checklist_health_safety.php' => 'inspection.php?type=health_safety_checklist',
 'checklist_warehouse.php' => 'inspection.php?type=warehouse_checklist',
 'checklist_pest_control.php' => 'inspection.php?type=pest_control_checklist',
//  'ai_reports.php' => 'ai_reports.php',
 'import.php' => 'import.php',
];


// --- UTILITY/HELPER PAGES (Require check, but not in navbar) ---
// Keys here can be full paths (e.g., /utils/import.php) for source clarity.
$utility_permissions_by_role_raw = [
 '/utils/helpers.php'  => [1, 2, 3, 4], 
 '/utils/export_csv.php' => [1, 2, 3, 4],
 'logout.php'    => [1, 2, 3, 4],
];



// --- 1. PREPARE DATA FOR CHECK AND NAVBAR ---

// 1a. List of files to EXCLUDE from the navbar (uses simple filename)
// This array is used by templates/navbar.php for filtering.
$EXCLUDED_MENU_FILES = array_map('basename', array_keys($utility_permissions_by_role_raw));
$EXCLUDED_MENU_FILES[] = 'save_inspection.php';
$EXCLUDED_MENU_FILES[] = 'inspection.php';


// 1b. Normalize the utility keys to simple filenames for the security check
// The security check in step 2 uses the simple basename (e.g., 'import.php').
$utility_permissions_by_role_normalized = [];
foreach ($utility_permissions_by_role_raw as $path => $roles) {
 $utility_permissions_by_role_normalized[basename($path)] = $roles;
}

// 1c. Normalize any accidental spaces in page keys (e.g., "ai reports.php")
// so nav/security lookups always use canonical filenames.
$normalized_page_permissions = [];
foreach ($page_permissions_by_role as $file => $roles) {
 $canonical = str_replace(' ', '_', basename((string) $file));
 $normalized_page_permissions[$canonical] = $roles;
}
$page_permissions_by_role = $normalized_page_permissions;

$normalized_nav_map = [];
foreach ($NAVBAR_DISPLAY_MAP as $file => $label) {
 $canonical = str_replace(' ', '_', basename((string) $file));
 $normalized_nav_map[$canonical] = $label;
}
$NAVBAR_DISPLAY_MAP = $normalized_nav_map;

// 1c. Merge the two maps for the final comprehensive access control check.
$page_permissions_by_role = array_merge($page_permissions_by_role, $utility_permissions_by_role_normalized);


// ----------------------------------------------------------------------
// SECURITY CHECK IMPLEMENTATION
// ----------------------------------------------------------------------

// 1. Get the current user role ID (Default to 0 if not logged in)
$current_role_id = $_SESSION['role_id'] ?? 0;

// 2. Determine the current script filename
// FIX: Use SCRIPT_NAME to get the filename reliably, ignoring path prefixes.
$current_page = basename($_SERVER['SCRIPT_NAME'] ?? '');

// 3. Look up the allowed roles for the current page
// This lookup uses the simple filename (e.g., 'import.php')
$allowed_roles = $page_permissions_by_role[$current_page] ?? [];

// 4. Check if the user's role is in the allowed list
if (empty($allowed_roles) || !in_array($current_role_id, $allowed_roles, true)) {
 

}
?>
