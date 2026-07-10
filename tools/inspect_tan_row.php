<?php
/** Dump full row for rows where col D has a listing code */
$path = $argv[1] ?? 'c:/Users/ningp/Downloads/Tan New List.xlsx';
$zip = new ZipArchive(); $zip->open($path);
$wb = $zip->getFromName('xl/workbook.xml');
$rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
$map = []; preg_match_all('/Id="([^"]+)"[^>]+Target="([^"]+)"/', $rels, $rm, PREG_SET_ORDER);
foreach ($rm as $r) $map[$r[1]] = $r[2];
$target = null;
preg_match_all('/<sheet[^>]+name="([^"]+)"[^>]+r:id="([^"]+)"/', $wb, $sm, PREG_SET_ORDER);
foreach ($sm as $s) if ($s[1]==='Tan') $target = $map[$s[2]] ?? null;
$shared = [];
$ssXml = $zip->getFromName('xl/sharedStrings.xml');
if ($ssXml && preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ssXml, $tm))
    foreach ($tm[1] as $t) $shared[] = html_entity_decode(strip_tags($t), ENT_QUOTES|ENT_XML1, 'UTF-8');
function cell_value($c,$s){if(preg_match('/t="s".*?<v>(\d+)<\/v>/s',$c,$m))return $s[(int)$m[1]]??'';if(preg_match('/<v>(.*?)<\/v>/s',$c,$m))return $m[1];return '';}
function col_letter($idx){$s='';while($idx>0){$idx--;$s=chr(65+($idx%26)).$s;$idx=intdiv($idx,26);}return $s;}
function col_index($ref){if(!preg_match('/^([A-Z]+)/',$ref,$m))return 0;$col=0;foreach(str_split($m[1]) as $ch)$col=$col*26+(ord($ch)-64);return $col;}
$xml = $zip->getFromName('xl/'.ltrim($target,'/')); $zip->close();
$codeRe = '/^(TAN|NING|DIT|AMPK|FEW)\d{1,4}(\s*\([^)]+\))?$/i';
$shown = 0;
preg_match_all('/<row[^>]*r="(\d+)"[^>]*>(.*?)<\/row>/s', $xml, $rm, PREG_SET_ORDER);
foreach ($rm as $row) {
    $r=(int)$row[1]; if ($r<4) continue;
    $cells=[];
    preg_match_all('/<c[^>]*r="([A-Z]+)(\d+)"[^>]*>.*?<\/c>/s',$row[2],$cm,PREG_SET_ORDER);
    foreach ($cm as $c) { $col=col_index($c[1]); $cells[col_letter($col)]=cell_value($c[0],$shared); }
    $d = trim($cells['D'] ?? '');
    if (!preg_match($codeRe, $d)) continue;
    echo "=== R$r code=$d ===\n";
    foreach ($cells as $col=>$v) {
        $v = mb_substr(str_replace(["\r","\n"],' ',$v),0,50);
        echo "$col=$v\n";
    }
    echo "\n";
    if (++$shown >= 3) break;
}
