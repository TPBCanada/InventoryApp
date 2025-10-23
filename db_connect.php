<?php
$servername = "50.6.108.227";
$username = "xserqhmy_web";
$password = "TPBCanada2025**";
$database = "xserqhmy_users";

$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
