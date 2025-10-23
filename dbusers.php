<?php 
$servername     = "50.6.108.227";
$db_user        = "xserqhmy_web";
$db_pass        = "TPBCanada2025**";
$db_name        = "xserqhmy_users";

$conn = new mysqli($servername, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
