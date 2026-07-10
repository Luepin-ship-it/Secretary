<?php
/** Count listing codes per sheet column and find data regions */
$path = $argv[1] ?? 'c:/Users/ningp/Downloads/Tan New List.xlsx';
$zip = new ZipArchive();
$zip->open($path);

$wb = $zip->getFromName('xl/workbook.xml');
$rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
$map = [];
preg_match_all('/Id="([^"]+)"[^>]+Target="([^"]+)"/', $rels, $rm, PREG_SET_ORDER);
foreach ($rm as $r) $map[$r[1]] = $r[2];

$sheets = [];
preg_match_all('/<sheet[^>]+name="([^"]+)"[^>]+r:id="([^"]+)"/', $wb, $sm, PREG_SET_ORDER);
foreach ($sm as $s) $sheets[$s[1]] = $map[$s[2]] ?? '';

$shared = [];
$ssXml = $zip->getFromName('xl/sharedStrings.xml');
if ($ssXml && preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ssXml, $tm)) {
    foreach ($tm[1] as $t) $shared[] = html_entity_decode(strip_tags($t), ENT_QUOTES|ENT_XML1, 'UTF-8');
}

function cell_value($c, $shared) {
    if (preg_match('/t="s".*?<v>(\d+)<\/v>/s', $c, $m)) return $shared[(int)$m[1]] ?? '';
    if (preg_match('/<v>(.*?)<\/v>/s', $c, $m)) return $m[1];
    return '';
}

function col_letter($idx) {
    $s = '';
    while ($idx > 0) { $idx--; $s = chr(65 + ($idx % 26)) . $s; $idx = intdiv($idx, 26); }
    return $s;
}

function col_index($ref) {
    if (!preg_match('/^([A-Z]+)/', $ref, $m)) return 0;
    $col = 0;
    foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
    return $col;
}

$codeRe = '/^(TAN|Tan|NING|DIT|AMPK|FEW)\d{1,4}(\s*\([^)]+\))?$/i';

foreach (['Tan', 'Owner Focus (OF)', 'Tan UCP'] as $name) {
    $xml = $zip->getFromName('xl/' . ltrim($sheets[$name], '/'));
    $byCol = [];
    $samples = [];
    preg_match_all('/<row[^>]*r="(\d+)"[^>]*>(.*?)<\/row>/s', $xml, $rm, PREG_SET_ORDER);
    foreach ($rm as $row) {
        $r = (int)$row[1];
        preg_match_all('/<c[^>]*r="([A-Z]+)(\d+)"[^>]*>.*?<\/c>/s', $row[2], $cm, PREG_SET_ORDER);
        foreach ($cm as $c) {
            $v = trim(cell_value($c[0], $shared));
            if ($v === '' || !preg_match($codeRe, $v)) continue;
            $col = col_index($c[1]);
            $byCol[$col] = ($byCol[$col] ?? 0) + 1;
            if (count($samples) < 8) $samples[] = "R$r " . col_letter($col) . "=$v";
        }
    }
    arsort($byCol);
    echo "=== $name ===\n";
    echo "Code hits by column: ";
    foreach (array_slice($byCol, 0, 5, true) as $c => $n) echo col_letter($c) . "=$n ";
    echo "\nSamples: " . implode(' | ', $samples) . "\n\n";
}

// Tan UCP: find rows where C = known field labels
$labels = ['Listing Code','Name','Phone','Budget','Status','Pain Point','Project Interest','Potential'];
$xml = $zip->getFromName('xl/' . ltrim($sheets['Tan UCP'], '/'));
$labelRows = [];
preg_match_all('/<row[^>]*r="(\d+)"[^>]*>(.*?)<\/row>/s', $xml, $rm, PREG_SET_ORDER);
foreach ($rm as $row) {
    $r = (int)$row[1];
    $cells = [];
    preg_match_all('/<c[^>]*r="([A-Z]+)(\d+)"[^>]*>.*?<\/c>/s', $row[2], $cm, PREG_SET_ORDER);
    foreach ($cm as $c) {
        $col = col_index($c[1]);
        $cells[$col] = trim(cell_value($c[0], $shared));
    }
    foreach ($labels as $lb) {
        foreach ($cells as $col => $v) {
            if ($v === $lb) $labelRows[$lb][] = "R$r col=" . col_letter($col);
        }
    }
}
echo "=== Tan UCP field label positions ===\n";
foreach ($labels as $lb) {
    $hits = $labelRows[$lb] ?? [];
    echo "$lb: " . count($hits) . " — " . implode(', ', array_slice($hits, 0, 3)) . "\n";
}

$zip->close();
