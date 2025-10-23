<?php
declare(strict_types=1);
session_start();
$preview = $_SESSION['ai_preview'] ?? null;
if (!$preview || empty($preview['columns'])) {
  header('Content-Type: text/plain; charset=utf-8'); echo "No results to export. Run a query first."; exit;
}
$filename = 'report_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
$out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $preview['columns']);
foreach ($preview['rows'] as $r) {
  $row = [];
  foreach ($preview['columns'] as $c) $row[] = isset($r[$c]) ? (string)$r[$c] : '';
  fputcsv($out, $row);
}
fclose($out);
