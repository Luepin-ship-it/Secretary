<?php
/**
 * ลำดับรหัสทรัพย์ (Code list) — แนะนำถัดไป · ห้ามข้ามแม้ Cancel/Sold
 */

require_once __DIR__ . '/tan_workbook_import.php';
require_once __DIR__ . '/flex_theme.php';

/** @return array{prefix:string,num:int,code:string}|null */
function listing_code_seq_parse(string $raw): ?array
{
    $norm = TanWorkbookImport::normalizeListingCode($raw);
    if ($norm === '') {
        return null;
    }
    if (!preg_match(TanWorkbookImport::LISTING_CODE_RE, $norm, $m)) {
        return null;
    }
    return [
        'prefix' => strtoupper($m[1]),
        'num' => (int)$m[2],
        'code' => strtoupper($m[1]) . (string)(int)$m[2],
    ];
}

function listing_code_seq_format(string $prefix, int $num): string
{
    return strtoupper($prefix) . (string)$num;
}

/** ป้ายสถานะ — ข้อความ + ไอคอน (ไม่พึ่งสีอย่างเดียว) */
function listing_code_seq_status_meta(array $row): array
{
    $sales = strtolower(str_replace(' ', '', trim($row['sales_status'] ?? '')));
    $avail = trim($row['availability_status'] ?? '');

    if ($avail === 'ขายได้แล้ว' || $sales === 'sold') {
        return ['label' => 'Sold', 'icon' => '✓', 'line' => 'Sold'];
    }
    if ($avail === 'ยกเลิกการขาย' || $sales === 'cancel') {
        return ['label' => 'Cancel', 'icon' => '⊘', 'line' => 'Cancel'];
    }
    if (strpos($sales, 'sale&available') !== false || $sales === 'saleavailable') {
        return ['label' => 'Sale·Rent', 'icon' => '◎', 'line' => 'Sale·Rent'];
    }
    if (strpos($sales, 'withtenant') !== false || $sales === 'salewithtenant') {
        return ['label' => 'Sale·Tenant', 'icon' => '◎', 'line' => 'Sale·Tenant'];
    }
    if ($sales === 'rent' || $sales === 'rental') {
        return ['label' => 'Rent', 'icon' => '◎', 'line' => 'Rent'];
    }
    return ['label' => 'Sale', 'icon' => '◎', 'line' => 'Sale'];
}

/**
 * @return array{
 *   prefix:string,
 *   last_code:string,
 *   last_num:int,
 *   next_code:string,
 *   recent:list<array{code:string,status:string,status_line:string}>,
 *   total:int,
 *   has_sequence:bool
 * }
 */
function listing_code_seq_for_user(mysqli $conn, int $user_id, ?string $preferPrefix = null): array
{
    $empty = [
        'prefix' => $preferPrefix ? strtoupper($preferPrefix) : 'TAN',
        'last_code' => '',
        'last_num' => 0,
        'next_code' => '',
        'recent' => [],
        'total' => 0,
        'has_sequence' => false,
    ];

    $stmt = $conn->prepare('SELECT code_list, sales_status, availability_status FROM owners WHERE user_id = ?');
    if (!$stmt) {
        return $empty;
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $byPrefix = [];
    while ($row = $res->fetch_assoc()) {
        $parsed = listing_code_seq_parse($row['code_list'] ?? '');
        if (!$parsed) {
            continue;
        }
        $p = $parsed['prefix'];
        if (!isset($byPrefix[$p])) {
            $byPrefix[$p] = ['max' => 0, 'rows' => []];
        }
        $byPrefix[$p]['max'] = max($byPrefix[$p]['max'], $parsed['num']);
        $meta = listing_code_seq_status_meta($row);
        $byPrefix[$p]['rows'][] = [
            'num' => $parsed['num'],
            'code' => $parsed['code'],
            'status' => $meta['label'],
            'status_line' => $meta['line'],
        ];
    }
    $stmt->close();

    if (!$byPrefix) {
        return $empty;
    }

    $prefix = $preferPrefix ? strtoupper($preferPrefix) : '';
    if ($prefix === '' || !isset($byPrefix[$prefix])) {
        $best = 0;
        foreach ($byPrefix as $p => $info) {
            $score = $info['max'];
            if ($score > $best) {
                $best = $score;
                $prefix = $p;
            }
        }
    }

    $info = $byPrefix[$prefix] ?? null;
    if (!$info) {
        return $empty;
    }

    usort($info['rows'], static fn ($a, $b) => $b['num'] <=> $a['num']);
    $recent = array_slice(array_map(static function ($r) {
        return [
            'code' => $r['code'],
            'status' => $r['status'],
            'status_line' => $r['status_line'],
        ];
    }, $info['rows']), 0, 3);

    $lastNum = (int)$info['max'];
    $lastCode = listing_code_seq_format($prefix, $lastNum);
    $nextCode = listing_code_seq_format($prefix, $lastNum + 1);

    return [
        'prefix' => $prefix,
        'last_code' => $lastCode,
        'last_num' => $lastNum,
        'next_code' => $nextCode,
        'recent' => $recent,
        'total' => count($info['rows']),
        'has_sequence' => $lastNum > 0,
    ];
}

/** ตรวจว่ารหัสใหม่ไม่ข้ามลำดับ */
function listing_code_seq_validate_next(mysqli $conn, int $user_id, string $code): ?string
{
    $parsed = listing_code_seq_parse($code);
    if (!$parsed) {
        return null;
    }

    $seq = listing_code_seq_for_user($conn, $user_id, $parsed['prefix']);
    if (!$seq['has_sequence']) {
        return null;
    }

    $expected = $seq['next_code'];
    if ($expected === '') {
        return null;
    }

    $norm = TanWorkbookImport::normalizeListingCode($code);
    if (strcasecmp($norm, $expected) !== 0) {
        return "รหัสถัดไปต้องเป็น {$expected}\n\n"
            . "ล่าสุดในระบบ: {$seq['last_code']}\n"
            . "ห้ามข้ามลำดับ — แม้ทรัพย์เก่า Cancel / Sold แล้วก็ยังนับเลขเดิม";
    }

    return null;
}

function listing_code_seq_table_row(string $code, string $statusLine, bool $highlight = false): array
{
    $c = listing_code_seq_colors();
    $codeText = $highlight ? ('▸ ' . $code . ' · ล่าสุด') : $code;

    $box = [
        'type' => 'box',
        'layout' => 'horizontal',
        'spacing' => 'md',
        'margin' => $highlight ? 'md' : 'sm',
        'contents' => [
            [
                'type' => 'text',
                'text' => $codeText,
                'size' => 'sm',
                'flex' => 5,
                'weight' => $highlight ? 'bold' : 'regular',
                'color' => $highlight ? $c['green_dark'] : $c['text'],
                'wrap' => true,
            ],
            [
                'type' => 'text',
                'text' => $statusLine,
                'size' => 'sm',
                'flex' => 3,
                'align' => 'end',
                'color' => $highlight ? $c['green_dark'] : $c['text_muted'],
                'weight' => $highlight ? 'bold' : 'regular',
                'wrap' => true,
            ],
        ],
    ];

    if ($highlight) {
        $box['backgroundColor'] = $c['green_light'];
        $box['cornerRadius'] = '10px';
        $box['paddingAll'] = '14px';
    }

    return $box;
}

// listing_code_seq_colors() → lib/flex_theme.php

function listing_code_seq_bubble_styles(): array
{
    $bg = listing_code_seq_colors()['bg'];
    return [
        'body' => ['backgroundColor' => $bg],
        'footer' => ['backgroundColor' => $bg],
    ];
}

function listing_code_seq_text_muted(string $text, string $size = 'sm', string $margin = 'md'): array
{
    return [
        'type' => 'text',
        'text' => $text,
        'size' => $size,
        'color' => listing_code_seq_colors()['text_muted'],
        'wrap' => true,
        'margin' => $margin,
    ];
}

/** Flex แบบตาราง (LINE ไม่มี <table> — ใช้ box แถวแทน) */
function listing_code_seq_flex_message(array $seq): array
{
    $c = listing_code_seq_colors();

    $rows = [
        [
            'type' => 'text',
            'text' => 'รหัสทรัพย์ — ลำดับ',
            'weight' => 'bold',
            'size' => 'md',
            'color' => $c['green_dark'],
            'margin' => 'none',
        ],
    ];

    if (!$seq['has_sequence']) {
        $rows[] = ['type' => 'separator', 'margin' => 'lg', 'color' => $c['separator']];
        $rows[] = listing_code_seq_text_muted(
            'ยังไม่มีรหัสในระบบ — ใส่รหัสตาม Sheet ของคุณ (เช่น TAN901)',
            'sm',
            'lg'
        );
    } else {
        $rows[] = [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => $c['green_dark'],
            'cornerRadius' => '12px',
            'paddingAll' => '14px',
            'margin' => 'lg',
            'contents' => [
                [
                    'type' => 'text',
                    'text' => 'ถัดไปใช้',
                    'size' => 'xs',
                    'color' => $c['text_on_green'],
                    'weight' => 'bold',
                ],
                [
                    'type' => 'text',
                    'text' => $seq['next_code'],
                    'size' => 'xl',
                    'weight' => 'bold',
                    'color' => $c['text_on_green'],
                    'margin' => 'sm',
                ],
            ],
        ];
        $rows[] = listing_code_seq_text_muted("ล่าสุด: {$seq['last_code']}", 'sm', 'md');
        $rows[] = listing_code_seq_text_muted(
            'ระบบจะไม่ข้ามเลข ใส่รหัสตามนี้ได้เลย',
            'xs',
            'md'
        );

        if (!empty($seq['recent'])) {
            $rows[] = ['type' => 'separator', 'margin' => 'lg', 'color' => $c['separator']];
            $rows[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'margin' => 'md',
                'contents' => [
                    ['type' => 'text', 'text' => 'Code', 'size' => 'xs', 'flex' => 5, 'color' => $c['text_muted'], 'weight' => 'bold'],
                    ['type' => 'text', 'text' => 'Status', 'size' => 'xs', 'flex' => 3, 'align' => 'end', 'color' => $c['text_muted'], 'weight' => 'bold'],
                ],
            ];
            foreach ($seq['recent'] as $i => $r) {
                $rows[] = listing_code_seq_table_row(
                    $r['code'],
                    $r['status_line'],
                    $i === 0
                );
            }
        }
    }

    return [
        'type' => 'flex',
        'altText' => $seq['has_sequence']
            ? ('รหัสถัดไป ' . $seq['next_code'] . ' — ล่าสุด ' . $seq['last_code'])
            : 'รหัสทรัพย์ — ลำดับ',
        'contents' => [
            'type' => 'bubble',
            'size' => 'mega',
            'styles' => listing_code_seq_bubble_styles(),
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'spacing' => 'md',
                'contents' => $rows,
                'paddingAll' => '16px',
            ],
        ],
    ];
}
