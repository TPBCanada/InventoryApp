<?php
/**
 * Database connection file
 * Author: TPB Canada
 * Date: <?= date('Y') ?>
 */

// --- Database credentials ---
$servername = "50.6.108.227";
$username   = "xserqhmy_web";
$password   = "TPBCanada2025**";
$database   = "xserqhmy_whse";

// --- Create PDO connection ---
try {
    $dsn = "mysql:host=$servername;dbname=$database;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);

    // PDO options
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Optional success message (for testing only)
    // echo "✅ Database connection established successfully!";
} catch (PDOException $e) {
    // Display error message if connection fails
    die("❌ Database connection failed: " . $e->getMessage());
}
?>
