<?php
require __DIR__ . '/../lib/xlsx_reader.php';
$rows = (new XlsxReader($argv[1]))->readSheet('ชีต1');
for ($i = 2; $i <= 45; $i++) {
    if (!isset($rows[$i])) continue;
    $c = $rows[$i];
    $b = trim($c['B'] ?? '');
    if ($b === '') continue;
    printf("R%d %-12s E=%-8s H=%-10s O=%-10s S=%s T=%s\n", $i, mb_substr($b,0,12),
        $c['E'] ?? '', $c['H'] ?? '', $c['O'] ?? '', $c['S'] ?? '', $c['T'] ?? '');
}
