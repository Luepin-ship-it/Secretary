<?php
/**
 * Minimal XLSX reader (no external deps). Reads cell values by sheet name.
 */
class XlsxReader
{
    private ZipArchive $zip;
    private array $shared = [];
    private array $sheetFiles = [];

    public function __construct(string $path)
    {
        if (!is_readable($path)) {
            throw new RuntimeException("Cannot read file: $path");
        }
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException("Failed to open xlsx: $path");
        }
        $this->loadSharedStrings();
        $this->loadSheetMap();
    }

    public function __destruct()
    {
        if ($this->zip->status === ZipArchive::ER_OK) {
            $this->zip->close();
        }
    }

    public function sheetNames(): array
    {
        return array_keys($this->sheetFiles);
    }

    /** @return array<int, array<string, string>> rowNum => [colLetter => value] */
    public function readSheet(string $name): array
    {
        $file = $this->sheetFiles[$name] ?? null;
        if (!$file) {
            throw new RuntimeException("Sheet not found: $name");
        }
        $xml = $this->zip->getFromName('xl/' . ltrim($file, '/'));
        if (!$xml) {
            return [];
        }
        $rows = [];
        if (!preg_match_all('/<row[^>]*r="(\d+)"[^>]*>(.*?)<\/row>/s', $xml, $rm, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($rm as $row) {
            $r = (int)$row[1];
            $cells = [];
            if (preg_match_all('/<c[^>]*r="([A-Z]+)(\d+)"[^>]*>.*?<\/c>/s', $row[2], $cm, PREG_SET_ORDER)) {
                foreach ($cm as $c) {
                    $col = self::colLetter((int)self::colIndex($c[1]));
                    $cells[$col] = self::cellValue($c[0], $this->shared);
                }
            }
            $rows[$r] = $cells;
        }
        return $rows;
    }

    public static function colIndex(string $letters): int
    {
        $col = 0;
        foreach (str_split($letters) as $ch) {
            $col = $col * 26 + (ord($ch) - 64);
        }
        return $col;
    }

    public static function colLetter(int $idx): string
    {
        $s = '';
        while ($idx > 0) {
            $idx--;
            $s = chr(65 + ($idx % 26)) . $s;
            $idx = intdiv($idx, 26);
        }
        return $s;
    }

    private function loadSharedStrings(): void
    {
        $ssXml = $this->zip->getFromName('xl/sharedStrings.xml');
        if (!$ssXml) {
            return;
        }
        if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $ssXml, $tm)) {
            foreach ($tm[1] as $t) {
                $this->shared[] = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }

    private function loadSheetMap(): void
    {
        $wb = $this->zip->getFromName('xl/workbook.xml');
        $rels = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        $map = [];
        if ($rels && preg_match_all('/Id="([^"]+)"[^>]+Target="([^"]+)"/', $rels, $rm, PREG_SET_ORDER)) {
            foreach ($rm as $r) {
                $map[$r[1]] = $r[2];
            }
        }
        if ($wb && preg_match_all('/<sheet[^>]+name="([^"]+)"[^>]+r:id="([^"]+)"/', $wb, $sm, PREG_SET_ORDER)) {
            foreach ($sm as $s) {
                $this->sheetFiles[$s[1]] = $map[$s[2]] ?? '';
            }
        }
    }

    private static function cellValue(string $xml, array $shared): string
    {
        if (preg_match('/t="s".*?<v>(\d+)<\/v>/s', $xml, $m)) {
            return trim($shared[(int)$m[1]] ?? '');
        }
        if (preg_match('/<v>(.*?)<\/v>/s', $xml, $m)) {
            return trim($m[1]);
        }
        return '';
    }
}
