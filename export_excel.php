<?php
declare(strict_types=1);
session_start();
$preview = $_SESSION['ai_preview'] ?? null;
if (!$preview || empty($preview['columns'])) {
  header('Content-Type: text/plain; charset=utf-8'); echo "No results to export. Run a query first."; exit;
}
$filenameBase = 'report_' . date('Ymd_His');
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require __DIR__ . '/vendor/autoload.php';
  try {
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $ss->getActiveSheet();
    $ci=1; foreach ($preview['columns'] as $col) $sheet->setCellValueByColumnAndRow($ci++,1,$col);
    $ri=2; foreach ($preview['rows'] as $r){ $ci=1; foreach ($preview['columns'] as $c){ $sheet->setCellValueByColumnAndRow($ci++,$ri, isset($r[$c])?(string)$r[$c]:''); } $ri++; }
    foreach (range(1,count($preview['columns'])) as $i) $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filenameBase.'.xlsx"');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output'); exit;
  } catch (Throwable $e) { /* fall back */ }
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filenameBase.'.csv"');
$out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $preview['columns']);
foreach ($preview['rows'] as $r) { $row=[]; foreach ($preview['columns'] as $c) $row[] = isset($r[$c])?(string)$r[$c]:''; fputcsv($out,$row); }
fclose($out);
