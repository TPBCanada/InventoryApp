<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/security.php';


if (isset($_SESSION['username'])) {
    
    header("Location: dashboard.php");
    exit;
    
} else {
    
    header("Location: login.php");
    exit;
}
?>