<?php
$path = $argv[1] ?? 'c:/Users/ningp/Downloads/Tan New List.xlsx';
$zip = new ZipArchive();
$zip->open($path);

$shared = [];
$ssXml = $zip->getFromName('xl/sharedStrings.xml');
if ($ssXml && preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ssXml, $tm)) {
    foreach ($tm[1] as $t) {
        $shared[] = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

function find_in_sheet(ZipArchive $zip, string $sheetFile, array $shared, array $needles) {
    $xml = $zip->getFromName('xl/' . ltrim($sheetFile, '/'));
    $hits = [];
    if (preg_match_all('/<c[^>]*r="([A-Z]+)(\d+)"[^>]*>(.*?)<\/c>/s', $xml, $cm, PREG_SET_ORDER)) {
        foreach ($cm as $c) {
            $val = '';
            if (preg_match('/t="s".*?<v>(\d+)<\/v>/s', $c[0], $m)) {
                $val = $shared[(int)$m[1]] ?? '';
            } elseif (preg_match('/<v>(.*?)<\/v>/s', $c[0], $m)) {
                $val = $m[1];
            }
            foreach ($needles as $n) {
                if ($val === $n || stripos($val, $n) !== false) {
                    $hits[] = $c[1] . $c[2] . ': ' . mb_substr(str_replace("\n", ' ', $val), 0, 60);
                }
            }
        }
    }
    return $hits;
}

$wb = $zip->getFromName('xl/workbook.xml');
$rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
$map = [];
preg_match_all('/Id="([^"]+)"[^>]+Target="([^"]+)"/', $rels, $rm, PREG_SET_ORDER);
foreach ($rm as $r) { $map[$r[1]] = $r[2]; }
preg_match_all('/<sheet[^>]+name="([^"]+)"[^>]+r:id="([^"]+)"/', $wb, $sm, PREG_SET_ORDER);

$targets = ['Tan' => ['Code', 'Owner\'s Name', 'Asking Price'], 'Tan UCP' => ['Lead', 'Listing Code', 'Budget', 'Pain Point', 'Status', 'Call', 'Win'], 'Owner Focus (OF)' => ['Grade', 'Listing Code', 'Owner\'s Name', 'Total Score']];

foreach ($sm as $s) {
    $name = $s[1];
    if (!isset($targets[$name])) continue;
    $file = $map[$s[2]] ?? '';
    echo "=== $name ===\n";
    $hits = find_in_sheet($zip, $file, $shared, $targets[$name]);
    foreach (array_slice($hits, 0, 25) as $h) echo "  $h\n";
    echo "  (total hits: " . count($hits) . ")\n\n";
}

$zip->close();
