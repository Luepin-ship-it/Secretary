<?php
/**
 * LIFF อัปรูปทรัพย์ — เรียงลำดับ · ตั้งชื่อ 1,2,3… · คัดลอกชื่อโฟลเดอร์ · เปิด Drive อัปเอง
 */

function liff_project_photos_id(): string
{
    if (defined('LINE_LIFF_PROJECT_PHOTOS_ID') && LINE_LIFF_PROJECT_PHOTOS_ID !== '') {
        return trim(LINE_LIFF_PROJECT_PHOTOS_ID);
    }
    return '';
}

function liff_project_photos_enabled(): bool
{
    return liff_project_photos_id() !== '';
}

function liff_project_photos_url(string $code, string $folderHint, string $driveUrl = ''): string
{
    $id = liff_project_photos_id();
    if ($id === '') {
        return '';
    }
    $params = array_filter([
        'code' => trim($code),
        'folder' => trim($folderHint),
        'drive' => trim($driveUrl),
    ], static fn ($v) => $v !== '');
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return 'https://liff.line.me/' . $id . ($query !== '' ? '?' . $query : '');
}

/** @return list<array<string,mixed>> */
function liff_project_photos_line_actions(string $code, string $folderHint, string $driveUrl = ''): array
{
    $actions = [];
    $guideUrl = liff_project_photos_url($code, $folderHint, $driveUrl);
    if ($guideUrl !== '') {
        $actions[] = [
            'type' => 'uri',
            'label' => 'อัปรูป',
            'uri' => $guideUrl,
        ];
    }
    if ($driveUrl !== '') {
        $actions[] = [
            'type' => 'uri',
            'label' => 'เปิด Drive',
            'uri' => $driveUrl,
        ];
    }
    return $actions;
}
