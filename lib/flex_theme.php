<?php
/**
 * โทน Salt & Pepper สำหรับ LINE Flex — อิง Flex listing.json
 * ขาว · ดำ · น้ำตาล · เขียว (ไม่ใช้สีเหลือง/ฟ้า/แดง/ม่วงในการ์ดทั่วไป)
 */

/** @return array<string,string> */
function flex_theme_colors(): array
{
    return [
        'white' => '#FFFFFF',
        'surface' => '#F8F9FA',
        'surface_alt' => '#F0F0F0',
        'bubble_bg' => '#D4D4D4',

        'text' => '#2B2B2B',
        'text_dark' => '#141414',
        'text_black' => '#000000',
        'text_muted' => '#71717a',
        'text_faint' => '#52525b',
        'text_disabled' => '#B3B3B3',

        'brown' => '#5C4E4E',
        'brown_light' => '#988686',

        'green' => '#10B981',
        'green_dark' => '#059669',
        'green_light' => '#ECFDF5',
        'green_border' => '#A7F3D0',
        'text_on_green' => '#F0F0F0',

        'border' => '#D4D4D4',
        'separator' => '#B3B3B3',

        'btn_primary' => '#141414',
        'btn_secondary' => '#5C4E4E',
        'btn_green' => '#10B981',
    ];
}

/** @return array{body:array,footer:array} */
function flex_theme_bubble_styles(): array
{
    $bg = flex_theme_colors()['white'];
    return [
        'body' => ['backgroundColor' => $bg],
        'footer' => ['backgroundColor' => $bg],
    ];
}

function flex_theme_text(
    string $text,
    string $size = 'sm',
    string $role = 'text',
    string $margin = 'md',
    bool $bold = false
): array {
    $c = flex_theme_colors();
    $color = match ($role) {
        'muted' => $c['text_muted'],
        'faint' => $c['text_faint'],
        'disabled' => $c['text_disabled'],
        'dark' => $c['text_dark'],
        'black' => $c['text_black'],
        'brown' => $c['brown'],
        'brown_light' => $c['brown_light'],
        'green' => $c['green'],
        'green_dark' => $c['green_dark'],
        'on_green' => $c['text_on_green'],
        default => $c['text'],
    };
    $box = [
        'type' => 'text',
        'text' => $text,
        'wrap' => true,
        'size' => $size,
        'color' => $color,
        'margin' => $margin,
    ];
    if ($bold) {
        $box['weight'] = 'bold';
    }
    return $box;
}

/** @return array<string,mixed> */
function flex_theme_header_box(string $title, ?string $subtitle = null, string $tone = 'green'): array
{
    $c = flex_theme_colors();
    $bg = match ($tone) {
        'brown' => $c['brown'],
        'neutral' => $c['text_muted'],
        'black' => $c['text_dark'],
        default => $c['green'],
    };
    $contents = [
        flex_theme_text($title, 'sm', 'on_green', 'none', true),
    ];
    if ($subtitle !== null && $subtitle !== '') {
        $contents[] = flex_theme_text($subtitle, 'xs', 'on_green', 'xs');
    }
    return [
        'type' => 'box',
        'layout' => 'vertical',
        'backgroundColor' => $bg,
        'paddingAll' => '14px',
        'contents' => $contents,
    ];
}

function flex_theme_priority_color(int $score): string
{
    $c = flex_theme_colors();
    return match (max(1, min(5, $score))) {
        5 => $c['green'],
        4 => $c['green_dark'],
        3 => $c['brown'],
        2 => $c['brown_light'],
        default => $c['text_muted'],
    };
}

function flex_theme_priority_grade(int $score): string
{
    return match (max(1, min(5, $score))) {
        5 => 'A',
        4 => 'B',
        3 => 'C',
        2 => 'D',
        default => 'F',
    };
}

/** @return array{label:string,bg:string,text:string,accent:string} */
function flex_theme_lead_status_badge(string $status): array
{
    $c = flex_theme_colors();
    return match ($status) {
        'Active' => [
            'label' => 'Active',
            'bg' => $c['green_light'],
            'text' => $c['green_dark'],
            'accent' => $c['green'],
        ],
        'Pending_Cobroker' => [
            'label' => 'Pending Cobroker',
            'bg' => $c['surface'],
            'text' => $c['brown'],
            'accent' => $c['brown'],
        ],
        'Hold_Reject' => [
            'label' => 'Hold / Reject',
            'bg' => $c['surface_alt'],
            'text' => $c['text'],
            'accent' => $c['brown'],
        ],
        'Rejected' => [
            'label' => 'Rejected',
            'bg' => $c['surface'],
            'text' => $c['text_muted'],
            'accent' => $c['text_muted'],
        ],
        default => [
            'label' => $status,
            'bg' => $c['surface'],
            'text' => $c['text_faint'],
            'accent' => $c['text_muted'],
        ],
    };
}

/** คีย์เดิมของ listing_code_seq — ชี้มาโทนเดียวกัน */
function listing_code_seq_colors(): array
{
    $t = flex_theme_colors();
    return [
        'bg' => $t['white'],
        'green_dark' => $t['green'],
        'green_mid' => $t['green_dark'],
        'green_light' => $t['green_light'],
        'text' => $t['text'],
        'text_muted' => $t['text_muted'],
        'text_on_green' => $t['text_on_green'],
        'separator' => $t['border'],
    ];
}
