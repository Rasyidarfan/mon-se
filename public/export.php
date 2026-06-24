<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/helpers.php';

$kabList = list_kab();
$kab = $_GET['kab'] ?? '';
$kec = $_GET['kec'] ?? '';
if ($kab !== '' && !isset($kabList[$kab])) { $kab = ''; $kec = ''; }

if ($kab === '')      { $level = 'kab';  $scope = 'semua_kabupaten'; }
elseif ($kec === '')  { $level = 'kec';  $scope = $kabList[$kab]; }
else                  { $level = 'desa'; $scope = $kabList[$kab] . '_kec' . $kec; }

$rows    = rekap($level, ['kab' => $kab, 'kec' => $kec]);
$colKeys = array_keys(PROGRES_COLS);
$colHead = ['kab' => 'Kabupaten', 'kec' => 'Kecamatan', 'desa' => 'Desa/Kelurahan'][$level];

$fname = 'progres_' . preg_replace('/[^A-Za-z0-9]+/', '_', $scope) . '_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8

$head = array_merge(['Kode', $colHead], array_values(PROGRES_COLS), ['% Responden']);
fputcsv($out, $head, ',', '"', '\\');

$grand = array_fill_keys($colKeys, 0);
foreach ($rows as $r) {
    $line = [$r['code_disp'], $r['name']];
    foreach ($colKeys as $c) {
        $line[] = (int) $r[$c];
        $grand[$c] += (int) $r[$c];
    }
    $line[] = ((int) $r['prelist'] > 0)
        ? number_format((int) $r['responden'] / (int) $r['prelist'] * 100, 2) . '%'
        : '0%';
    fputcsv($out, $line, ',', '"', '\\');
}

$tot = ['', 'TOTAL KESELURUHAN'];
foreach ($colKeys as $c) {
    $tot[] = $grand[$c];
}
$tot[] = ($grand['prelist'] > 0)
    ? number_format($grand['responden'] / $grand['prelist'] * 100, 2) . '%'
    : '0%';
fputcsv($out, $tot, ',', '"', '\\');
fclose($out);
