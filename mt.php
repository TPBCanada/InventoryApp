<?php
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Blank Page</title>
<style>
html, body {
    margin: 0;
    padding: 0;
    background-color: #ffffff;
    height: 100%;
    width: 100%;
}
</style>
</head>
<body>
</body>
</html>
