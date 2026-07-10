<?php
/**
 * Dump rows around suspected header regions for Tan workbook sheets.
 */
$path = $argv[1] ?? 'c:/Users/ningp/Downloads/Tan New List.xlsx';
$sheetName = $argv[2] ?? 'Tan';
$startRow = (int)($argv[3] ?? 1);
$endRow = (int)($argv[4] ?? 30);
$maxCol = (int)($argv[5] ?? 35);

if (!is_readable($path)) {
    fwrite(STDERR, "Cannot read: $path\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($path) !== true) {
    fwrite(STDERR, "Zip open failed\n");
    exit(1);
}

$wb = $zip->getFromName('xl/workbook.xml');
$rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
$map = [];
if (preg_match_all('/Id="([^"]+)"[^>]+Target="([^"]+)"/', $rels, $rm, PREG_SET_ORDER)) {
    foreach ($rm as $r) {
        $map[$r[1]] = $r[2];
    }
}

$targetFile = null;
if (preg_match_all('/<sheet[^>]+name="([^"]+)"[^>]+r:id="([^"]+)"/', $wb, $sm, PREG_SET_ORDER)) {
    foreach ($sm as $s) {
        if ($s[1] === $sheetName) {
            $targetFile = $map[$s[2]] ?? null;
            break;
        }
    }
}
if (!$targetFile) {
    fwrite(STDERR, "Sheet not found: $sheetName\n");
    exit(1);
}

$shared = [];
$ssXml = $zip->getFromName('xl/sharedStrings.xml');
if ($ssXml && preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ssXml, $tm)) {
    foreach ($tm[1] as $t) {
        $shared[] = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

function cell_value($c, array $shared) {
    if (preg_match('/t="s".*?<v>(\d+)<\/v>/s', $c, $m)) {
        return $shared[(int)$m[1]] ?? '';
    }
    if (preg_match('/<v>(.*?)<\/v>/s', $c, $m)) {
        return $m[1];
    }
    return '';
}

function col_letter($idx) {
    $s = '';
    while ($idx > 0) {
        $idx--;
        $s = chr(65 + ($idx % 26)) . $s;
        $idx = intdiv($idx, 26);
    }
    return $s;
}

$xml = $zip->getFromName('xl/' . ltrim($targetFile, '/'));
$zip->close();

echo "Sheet: $sheetName | rows $startRow-$endRow | cols A-" . col_letter($maxCol) . "\n\n";

if (!preg_match_all('/<row[^>]*r="(\d+)"[^>]*>(.*?)<\/row>/s', $xml, $rm, PREG_SET_ORDER)) {
    exit(0);
}

foreach ($rm as $row) {
    $r = (int)$row[1];
    if ($r < $startRow || $r > $endRow) {
        continue;
    }
    $cells = [];
    if (preg_match_all('/<c[^>]*r="([A-Z]+)(\d+)"[^>]*>.*?<\/c>/s', $row[2], $cm, PREG_SET_ORDER)) {
        foreach ($cm as $c) {
            $col = 0;
            foreach (str_split($c[1]) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            if ($col <= $maxCol) {
                $cells[$col] = cell_value($c[0], $shared);
            }
        }
    }
    echo "R$r:";
    for ($c = 1; $c <= $maxCol; $c++) {
        if (!isset($cells[$c]) || $cells[$c] === '') {
            continue;
        }
        $v = str_replace(["\r", "\n"], ' ', $cells[$c]);
        $v = mb_substr($v, 0, 30);
        echo ' ' . col_letter($c) . '=' . $v;
    }
    echo "\n";
}
