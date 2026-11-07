<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Data\Cache;
use MyApp\Catalog\Config\Assets;

final class ContentPageService
{
    private const CACHE_DIR = '/myapp_catalog/content_page/';

    /** @var list<string> */
    private const RESERVED_CODES = [
        'catalog',
        'cart',
        'order',
        'auth',
        'personal',
        'pages',
        'vue_catalog',
    ];

    public static function isReservedCode(string $code): bool
    {
        $code = strtolower(trim($code));

        return $code === '' || in_array($code, self::RESERVED_CODES, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getActiveByCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '' || self::isReservedCode($code)) {
            return null;
        }

        $iblockId = Assets::pagesIblockId();
        if ($iblockId <= 0) {
            return null;
        }

        $cache = Cache::createInstance();
        $cacheId = 'page|' . $iblockId . '|' . md5($code);
        $ttl = Assets::catalogCacheTtl();
        if ($cache->initCache($ttl, $cacheId, self::CACHE_DIR)) {
            $vars = $cache->getVars();

            return is_array($vars) ? $vars : null;
        }

        if (!$cache->startDataCache()) {
            return null;
        }

        $element = IblockContentReader::getActiveElementByCode($iblockId, $code);
        if ($element === null) {
            $cache->abortDataCache();

            return null;
        }

        $map = Assets::pagesViewFieldMap();
        $propCodes = IblockContentReader::collectPropertyCodesFromMap($map);
        $props = IblockContentReader::loadPropertiesByElementIds($iblockId, [(int)$element['ID']], $propCodes);
        $view = IblockContentReader::mapToView($element, $props[(int)$element['ID']] ?? [], $map);

        $payload = [
            'CODE' => $code,
            'view' => $view,
            'metaTitle' => $view['metaTitle'] ?? '',
            'metaDescription' => $view['metaDescription'] ?? '',
        ];

        $cache->endDataCache($payload);

        return $payload;
    }

    public static function clearCache(): void
    {
        $cache = Cache::createInstance();
        $cache->cleanDir(self::CACHE_DIR);
    }
}
