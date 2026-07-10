<?php
// One-off inspector for Tan New List.xlsx
$path = $argv[1] ?? 'c:/Users/ningp/Downloads/Tan New List.xlsx';
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

$sheets = [];
if (preg_match_all('/<sheet[^>]+name="([^"]+)"[^>]+r:id="([^"]+)"/', $wb, $sm, PREG_SET_ORDER)) {
    foreach ($sm as $s) {
        $target = $map[$s[2]] ?? '';
        $sheets[] = ['name' => $s[1], 'file' => $target];
    }
}

// shared strings
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

function col_index($ref) {
    if (!preg_match('/^([A-Z]+)/', $ref, $m)) {
        return 0;
    }
    $col = 0;
    foreach (str_split($m[1]) as $ch) {
        $col = $col * 26 + (ord($ch) - 64);
    }
    return $col;
}

function sample_sheet(ZipArchive $zip, string $file, array $shared, int $maxRow = 8, int $maxCol = 20) {
    $xml = $zip->getFromName('xl/' . ltrim($file, '/'));
    if (!$xml) {
        return [];
    }
    $rows = [];
    if (!preg_match_all('/<row[^>]*r="(\d+)"[^>]*>(.*?)<\/row>/s', $xml, $rm, PREG_SET_ORDER)) {
        return [];
    }
    foreach ($rm as $row) {
        $r = (int)$row[1];
        if ($r > $maxRow) {
            break;
        }
        $cells = [];
        if (preg_match_all('/<c[^>]*r="([A-Z]+\d+)"[^>]*>.*?<\/c>/s', $row[2], $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) {
                $cells[col_index($c[1])] = cell_value($c[0], $shared);
            }
        }
        ksort($cells);
        $rows[$r] = array_values($cells);
    }
    ksort($rows);
    return $rows;
}

echo "Workbook: $path\n";
echo "Sheet count: " . count($sheets) . "\n\n";

$focus = ['Tan', 'Owner Focus (OF)', 'Tan UCP'];
foreach ($sheets as $sh) {
    echo "=== {$sh['name']} ({$sh['file']}) ===\n";
    if (!in_array($sh['name'], $focus, true)) {
        echo "(skipped detail)\n\n";
        continue;
    }
    $rows = sample_sheet($zip, $sh['file'], $shared, 12, 25);
    foreach ($rows as $rnum => $cells) {
        $preview = array_slice($cells, 0, 12);
        echo "R$rnum: " . implode(' | ', array_map(function ($v) {
            $v = str_replace(["\r", "\n"], ' ', $v);
            return mb_substr($v, 0, 40);
        }, $preview)) . "\n";
    }
    echo "\n";
}

// row counts (approx) for key sheets
foreach ($sheets as $sh) {
    if (!in_array($sh['name'], $focus, true)) {
        continue;
    }
    $xml = $zip->getFromName('xl/' . ltrim($sh['file'], '/'));
    $count = $xml ? preg_match_all('/<row[^>]*r="/', $xml) : 0;
    echo "Rows in {$sh['name']}: ~$count\n";
}

$zip->close();
