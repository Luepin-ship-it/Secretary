<?php
require __DIR__ . '/../lib/xlsx_reader.php';

$path = $argv[1] ?? '';
if (!is_readable($path)) {
    fwrite(STDERR, "Cannot read: $path\n");
    exit(1);
}

$reader = new XlsxReader($path);
foreach ($reader->sheetNames() as $sn) {
    echo "=== Sheet: $sn ===\n";
    $rows = $reader->readSheet($sn);
    for ($r = 1; $r <= min(8, max(array_keys($rows) ?: [1])); $r++) {
        if (!isset($rows[$r])) continue;
        $cells = $rows[$r];
        ksort($cells);
        $parts = [];
        foreach ($cells as $col => $v) {
            if (trim($v) === '') continue;
            $parts[] = "$col=" . mb_substr(str_replace(["\r","\n"], ' ', $v), 0, 40);
        }
        if ($parts) echo "R$r: " . implode(' | ', $parts) . "\n";
    }
    echo "\n";
}
