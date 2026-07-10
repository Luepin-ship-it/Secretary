<?php
/**
 * Resolve Google Drive cover at import time only (no per-user OAuth).
 * Works for: direct file URLs, folder URLs, or subfolder names under a shared root.
 */
class GdriveCoverResolver
{
    private static array $folderCache = [];
    private static array $firstImageCache = [];

    public static function fileIdFromUrl(string $url): ?string
    {
        if (preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function folderIdFromUrl(string $url): ?string
    {
        if (preg_match('#drive\.google\.com/drive(?:/u/\d+)?/folders/([a-zA-Z0-9_-]+)#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m) && strpos($url, 'folder') !== false) {
            return $m[1];
        }
        return null;
    }

    /** URL เก็บใน cover_image_url — dashboard แปลงเป็น thumbnail ตอนแสดง */
    public static function fileUrlFromId(string $fileId): string
    {
        return 'https://drive.google.com/file/d/' . $fileId . '/view';
    }

    public static function isLikelyFolderCode(string $value): bool
    {
        return (bool)preg_match('/^(TAN|NING|Tan|Ning|AMPK|FEW)\d{1,4}$/i', trim($value));
    }

    /** @return string[] */
    public static function folderNameCandidates(string $photosLink, string $codeList): array
    {
        $out = [];
        foreach ([trim($photosLink), trim($codeList)] as $v) {
            if ($v === '' || in_array($v, $out, true)) {
                continue;
            }
            $out[] = $v;
            if (preg_match('/^(TAN|NING|AMPK|FEW)(\d{1,4})$/i', $v, $m)) {
                $alt = ucfirst(strtolower($m[1])) . $m[2];
                if (!in_array($alt, $out, true)) {
                    $out[] = $alt;
                }
                $alt2 = strtoupper($m[1]) . $m[2];
                if (!in_array($alt2, $out, true)) {
                    $out[] = $alt2;
                }
            }
        }
        return $out;
    }

    /**
     * @param string $photosLink ค่าดิบจากคอลัมน์ photos_link
     * @param string $codeList    รหัสทรัพย์
     * @param string|null $driveRootId โฟลเดอร์รากที่แชร์สาธารณะ (เช่น House pic)
     * @param string|null $apiKey Google API key + Drive API enabled
     */
    public static function resolveAtImport(string $photosLink, string $codeList, ?string $driveRootId, ?string $apiKey): ?string
    {
        $link = trim($photosLink);
        if ($link === '') {
            return null;
        }

        $fileId = self::fileIdFromUrl($link);
        if ($fileId !== null) {
            return self::fileUrlFromId($fileId);
        }

        $folderId = self::folderIdFromUrl($link);
        if ($folderId !== null) {
            $img = self::firstImageInFolderWithFallback($folderId, $apiKey);
            return $img ? self::fileUrlFromId($img) : null;
        }

        if ($driveRootId !== null && $driveRootId !== '' && $apiKey !== null && $apiKey !== '') {
            foreach (self::folderNameCandidates($link, $codeList) as $name) {
                if (!self::isLikelyFolderCode($name) && $name !== $codeList) {
                    continue;
                }
                $subId = self::findSubfolderByName($driveRootId, $name, $apiKey);
                if ($subId === null) {
                    continue;
                }
                $img = self::firstImageInFolderWithFallback($subId, $apiKey);
                if ($img !== null) {
                    return self::fileUrlFromId($img);
                }
            }
        }

        return null;
    }

    private static function findSubfolderByName(string $parentId, string $name, string $apiKey): ?string
    {
        $cacheKey = $parentId . '|' . $name;
        if (array_key_exists($cacheKey, self::$folderCache)) {
            return self::$folderCache[$cacheKey];
        }

        $q = sprintf(
            "'%s' in parents and mimeType='application/vnd.google-apps.folder' and name='%s' and trashed=false",
            str_replace("'", "\\'", $parentId),
            str_replace("'", "\\'", $name)
        );
        $data = self::driveGet('files', [
            'q' => $q,
            'fields' => 'files(id)',
            'pageSize' => 1,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ], $apiKey);

        $id = $data['files'][0]['id'] ?? null;
        self::$folderCache[$cacheKey] = $id;
        return $id;
    }

    private static function firstImageInFolderWithFallback(string $folderId, ?string $apiKey): ?string
    {
        if ($apiKey !== null && $apiKey !== '') {
            $img = self::firstImageInFolder($folderId, $apiKey);
            if ($img !== null) {
                return $img;
            }
        }
        return self::firstImageFromPublicFolder($folderId);
    }

    /** โฟลเดอร์ที่แชร์ Anyone with link — ไม่ต้อง OAuth/API key */
    private static function firstImageFromPublicFolder(string $folderId): ?string
    {
        $cacheKey = 'pub|' . $folderId;
        if (array_key_exists($cacheKey, self::$firstImageCache)) {
            return self::$firstImageCache[$cacheKey];
        }

        $urls = [
            'https://drive.google.com/embeddedfolderview?id=' . rawurlencode($folderId) . '#grid',
            'https://drive.google.com/drive/folders/' . rawurlencode($folderId),
        ];

        foreach ($urls as $url) {
            $html = self::httpGet($url);
            if ($html === '') {
                continue;
            }
            if (preg_match_all('#/file/d/([a-zA-Z0-9_-]{10,})#', $html, $m)) {
                foreach ($m[1] as $fileId) {
                    if ($fileId !== $folderId) {
                        self::$firstImageCache[$cacheKey] = $fileId;
                        return $fileId;
                    }
                }
            }
        }

        self::$firstImageCache[$cacheKey] = null;
        return null;
    }

    private static function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LUEPiN/1.0)',
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? $body : '';
    }

    private static function firstImageInFolder(string $folderId, string $apiKey): ?string
    {
        if (array_key_exists($folderId, self::$firstImageCache)) {
            return self::$firstImageCache[$folderId];
        }

        $q = sprintf(
            "'%s' in parents and trashed=false and (mimeType contains 'image/')",
            str_replace("'", "\\'", $folderId)
        );
        $data = self::driveGet('files', [
            'q' => $q,
            'fields' => 'files(id,name,mimeType)',
            'orderBy' => 'name',
            'pageSize' => 1,
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ], $apiKey);

        $id = $data['files'][0]['id'] ?? null;
        self::$firstImageCache[$folderId] = $id;
        return $id;
    }

    private static function driveGet(string $resource, array $params, string $apiKey): array
    {
        $params['key'] = $apiKey;
        $url = 'https://www.googleapis.com/drive/v3/' . $resource . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || $body === false) {
            return [];
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : [];
    }
}
