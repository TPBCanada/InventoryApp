<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/security.php';
include 'dbinv.php'; 
require_once __DIR__ . '/templates/access_control.php';

if (!isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit;
}

// Check if the user has access to this specific page key
$page_key = 'inventory/damages.php';
$role_id = $_SESSION['role_id'] ?? 0;
$allowed_roles = $page_permissions_by_role[$page_key] ?? [];

// if (!in_array($role_id, $allowed_roles, true)) {
//     // Redirect or show an access denied message if not allowed
//     // For this example, we'll redirect to the main dashboard
//     header("Location: ../dashboard.php?error=access_denied");
//     exit;
// }


$title = 'Inventory Damage Log';
$BASE_URL = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
// Reusing dashboard styles for consistency, or specify a new one
$css = ['/refactor_css/dashboard.css']; 
$page_class = "page-damage-log";

$message = '';
$message_type = '';

// --- Database Interaction Mockup ---
// In a real application, you would handle form submission here (POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_damage') {
    // Sanitize and validate inputs
    $item_sku = $_POST['item_sku'] ?? '';
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $location = $_POST['location'] ?? '';
    $damage_type = $_POST['damage_type'] ?? '';
    $notes = $_POST['notes'] ?? '';
    $user_id = $_SESSION['user_id'];
    $log_time = date('Y-m-d H:i:s');

    if ($item_sku && $quantity > 0) {
        // Mock DB Insert Logic (replace with actual SQL/PDO)
        /*
        $stmt = $pdo->prepare("INSERT INTO inventory_damages 
            (item_sku, quantity, location, damage_type, notes, user_id, log_time) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt->execute([$item_sku, $quantity, $location, $damage_type, $notes, $user_id, $log_time])) {
            $message = "Damage log submitted successfully for {$quantity} units of {$item_sku}!";
            $message_type = 'success';
        } else {
            $message = "Error recording damage. Please try again.";
            $message_type = 'error';
        }
        */

        // Using mock success message for display
        $message = "Damage report logged (Mock Success): {$quantity} units of {$item_sku} marked as {$damage_type}.";
        $message_type = 'success';
        
    } else {
        $message = "Item SKU and Quantity are required.";
        $message_type = 'error';
    }
}

// Mock data retrieval for recent logs
// In a real app, this would be a SELECT query (e.g., SELECT * FROM inventory_damages ORDER BY log_time DESC LIMIT 10)
$recent_damages = [
    ['id' => 103, 'sku' => 'A-472-XL', 'qty' => 5, 'location' => 'R2-S3', 'type' => 'Forklift Impact', 'logged_by' => 'Jane Doe', 'time' => '2025-12-05 09:15'],
    ['id' => 102, 'sku' => 'B-101-SM', 'qty' => 10, 'location' => 'Picking', 'type' => 'Water Damage', 'logged_by' => 'John Smith', 'time' => '2025-12-04 15:30'],
    ['id' => 101, 'sku' => 'C-888-MD', 'qty' => 1, 'location' => 'Receiving', 'type' => 'Vendor Defect', 'logged_by' => 'Jane Doe', 'time' => '2025-12-03 11:00'],
];

ob_start();
?>

<div class="damage-container" style="max-width: 1000px; margin: 0 auto; padding: 20px;">
    <h1 style="font-size: 2em; color: #f23b53; margin-bottom: 20px;">Inventory Damage Management</h1>
    
    <?php if ($message): ?>
        <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>" 
             style="padding: 15px; margin-bottom: 20px; border-radius: 8px; border: 1px solid; 
                    background: <?= $message_type === 'success' ? '#e8f6ed' : '#fdeaea' ?>; 
                    color: <?= $message_type === 'success' ? '#0f7a42' : '#c91f1f' ?>;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Damage Reporting Form -->
    <section class="card damage-form-card" style="margin-bottom: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
        <h2 style="font-size: 1.5em; margin-bottom: 15px; border-bottom: 2px solid #f23b53; padding-bottom: 10px;">
            Log New Damage
        </h2>
        <form method="POST" action="">
            <input type="hidden" name="action" value="log_damage">
            
            <div class="form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                <!-- Item SKU -->
                <div>
                    <label for="item_sku" style="display: block; font-weight: bold; margin-bottom: 5px;">Item SKU/ID <span style="color: #f23b53;">*</span></label>
                    <input type="text" id="item_sku" name="item_sku" required 
                           style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box;">
                </div>
                
                <!-- Quantity -->
                <div>
                    <label for="quantity" style="display: block; font-weight: bold; margin-bottom: 5px;">Quantity Damaged <span style="color: #f23b53;">*</span></label>
                    <input type="number" id="quantity" name="quantity" min="1" required 
                           style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box;">
                </div>

                <!-- Current Location -->
                <div>
                    <label for="location" style="display: block; font-weight: bold; margin-bottom: 5px;">Current Location</label>
                    <input type="text" id="location" name="location" 
                           placeholder="R1-A1-P2"
                           style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box;">
                </div>
                
                <!-- Damage Type -->
                <div>
                    <label for="damage_type" style="display: block; font-weight: bold; margin-bottom: 5px;">Damage Type</label>
                    <select id="damage_type" name="damage_type" required
                            style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; appearance: none; background: white url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22292.4%22%20height%3D%22292.4%22%3E%3Cpath%20fill%3D%22%23666%22%20d%3D%22M287%2069.4a17.6%2017.6%200%200%200-13%205.4L146.2%20208.7%2018%2075a17.6%2017.6%200%200%200-13.6-5.4A17.6%2017.6%200%200%200%200%2087.6v117.8c0%204.8%202%209.4%205.4%2012.9l133.2%20133.5c3.5%203.5%208.1%205.4%2012.9%205.4s9.4-2%2012.9-5.4L287%20113.8c3.5-3.5%205.4-8.1%205.4-12.9V87.6c0-4.8-2-9.4-5.4-13z%22%2F%3E%3C%2Fsvg%3E') no-repeat right 10px center / 10px;">
                        <option value="Packaging Damage">Packaging Damage</option>
                        <option value="Water Damage">Water Damage</option>
                        <option value="Mechanical Failure">Mechanical Failure</option>
                        <option value="Expired/Spoiled">Expired/Spoiled</option>
                        <option value="Vendor Defect">Vendor Defect</option>
                        <option value="Missing Components">Missing Components</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>
            
            <div style="margin-top: 15px;">
                <label for="notes" style="display: block; font-weight: bold; margin-bottom: 5px;">Notes/Details</label>
                <textarea id="notes" name="notes" rows="3" 
                          style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box;"></textarea>
            </div>
            
            <button type="submit" 
                    style="margin-top: 20px; padding: 12px 20px; font-size: 1.1em; background-color: #f23b53; color: white; border: none; border-radius: 8px; cursor: pointer; transition: background-color 0.2s;">
                Submit Damage Report
            </button>
        </form>
    </section>

    <!-- Recent Damage Log -->
    <section class="card damage-log-card" style="padding: 20px; border: 1px solid #ddd; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05);">
        <h2 style="font-size: 1.5em; margin-bottom: 15px; border-bottom: 2px solid #2264d1; padding-bottom: 10px;">
            Recent Damage History
        </h2>
        
        <div class="table-responsive" style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead style="background-color: #f1f4f9;">
                    <tr>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: left;">ID</th>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: left;">SKU</th>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: left;">Qty</th>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: left;">Location</th>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: left;">Type</th>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: left;">Logged By</th>
                        <th style="padding: 10px; border: 1px solid #eee; text-align: left;">Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_damages)): ?>
                        <tr>
                            <td colspan="7" style="padding: 15px; text-align: center; border: 1px solid #eee;">No recent damage reports found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_damages as $damage): ?>
                            <tr style="border-bottom: 1px solid #eee; transition: background-color 0.1s;">
                                <td style="padding: 10px;"><?= htmlspecialchars($damage['id']) ?></td>
                                <td style="padding: 10px; font-weight: bold;"><?= htmlspecialchars($damage['sku']) ?></td>
                                <td style="padding: 10px; color: #f23b53; font-weight: bold;"><?= htmlspecialchars($damage['qty']) ?></td>
                                <td style="padding: 10px;"><?= htmlspecialchars($damage['location']) ?></td>
                                <td style="padding: 10px;"><?= htmlspecialchars($damage['type']) ?></td>
                                <td style="padding: 10px; font-style: italic;"><?= htmlspecialchars($damage['logged_by']) ?></td>
                                <td style="padding: 10px; font-size: 0.9em; color: #666;"><?= htmlspecialchars($damage['time']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>

<?php
$content = ob_get_clean();
$footer_js = ''; // No custom JS needed for this simple page
include __DIR__ . '/templates/layout.php'; // Adjust path
?>