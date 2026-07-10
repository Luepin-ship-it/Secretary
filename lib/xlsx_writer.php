<?php
/**
 * Minimal XLSX writer (single workbook, shared strings).
 */
class XlsxWriter
{
    private array $sheets = [];
    private array $shared = [];
    private array $sharedIndex = [];

    public function addSheet(string $name, array $rows): void
    {
        $this->sheets[$name] = $rows;
    }

    public function save(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $tmp = $path . '.tmp.zip';
        if (file_exists($tmp)) {
            unlink($tmp);
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create xlsx zip');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        $sheetXmls = [];
        foreach ($this->sheets as $rows) {
            $sheetXmls[] = $this->sheetXml($rows);
        }
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml());

        $i = 1;
        foreach ($sheetXmls as $xml) {
            $zip->addFromString("xl/worksheets/sheet{$i}.xml", $xml);
            $i++;
        }

        $zip->close();
        if (file_exists($path)) {
            unlink($path);
        }
        rename($tmp, $path);
    }

    private function si(string $value): int
    {
        if (!array_key_exists($value, $this->sharedIndex)) {
            $this->sharedIndex[$value] = count($this->shared);
            $this->shared[] = $value;
        }
        return $this->sharedIndex[$value];
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypesXml(): string
    {
        $n = count($this->sheets);
        $overrides = '';
        for ($i = 1; $i <= $n; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . $overrides
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        $sheets = '';
        $i = 1;
        foreach (array_keys($this->sheets) as $name) {
            $sheets .= '<sheet name="' . $this->esc($name) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $i++;
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets>'
            . '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        $rels = '';
        $n = count($this->sheets);
        for ($i = 1; $i <= $n; $i++) {
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $rels .= '<Relationship Id="rId' . ($n + 2) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }

    private function sharedStringsXml(): string
    {
        $items = '';
        foreach ($this->shared as $s) {
            $items .= '<si><t xml:space="preserve">' . $this->esc($s) . '</t></si>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($this->shared) . '" uniqueCount="' . count($this->shared) . '">'
            . $items . '</sst>';
    }

    private function sheetXml(array $rows): string
    {
        $sheetRows = '';
        foreach ($rows as $r => $cells) {
            $cellXml = '';
            foreach ($cells as $col => $val) {
                if ($val === '' || $val === null) {
                    continue;
                }
                $ref = $col . $r;
                $idx = $this->si((string)$val);
                $cellXml .= '<c r="' . $ref . '" t="s"><v>' . $idx . '</v></c>';
            }
            $sheetRows .= '<row r="' . $r . '">' . $cellXml . '</row>';
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetRows . '</sheetData>'
            . '</worksheet>';
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
}
