<?php
// 1. Configuration
$to = "steve@turningpointbrands.com";
$subject = "Daily DB Backup: " . date("Y-m-d");
$file = "/home2/xserqhmy/public_html/mysql_db/db_" . date("Y-m-d") . ".sql.gz";

// 2. Validate File Exists
if (!file_exists($file)) {
    die("Backup file not found at: " . $file);
}

// 3. Prepare Attachment
$content = chunk_split(base64_encode(file_get_contents($file)));
$uid = md5(uniqid(time()));

// 4. Build Headers (Crucial: Replace with a real domain you own to pass spam filters)
$header = "From: Backup System <backup@yourdomain.com>\r\n";
$header .= "Reply-To: backup@yourdomain.com\r\n";
$header .= "MIME-Version: 1.0\r\n";
$header .= "Content-Type: multipart/mixed; boundary=\"".$uid."\"\r\n\r\n";

// 5. Build Message Body
$message = "--".$uid."\r\n";
$message .= "Content-type:text/plain; charset=iso-8859-1\r\n";
$message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
$message .= "Please find the database backup attached.\r\n\r\n";

$message .= "--".$uid."\r\n";
$message .= "Content-Type: application/octet-stream; name=\"".basename($file)."\"\r\n";
$message .= "Content-Transfer-Encoding: base64\r\n";
$message .= "Content-Disposition: attachment; filename=\"".basename($file)."\"\r\n\r\n";
$message .= $content."\r\n\r\n";
$message .= "--".$uid."--";

// 6. Execution & Deletion
if (mail($to, $subject, $message, $header)) {
    echo "Email sent successfully. ";
    
    // Attempt deletion only if the email successfully leaves your server
    if (unlink($file)) {
        echo "Backup file deleted from server.";
    } else {
        echo "Error: Failed to delete the backup file. Check file permissions.";
    }
} else {
    echo "Email delivery failed on the server side.";
}
?>
