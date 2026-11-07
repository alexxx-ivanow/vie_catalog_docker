<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Data\Cache;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Search\CatalogSearchBackendFactory;

/**
 * Кеш числа совпадений поиска (total) для фразы: ускоряет page&gt;1 без повторного COUNT по тяжёлому подзапросу.
 * Ключ включает версию данных индекса (INDEX_VERSION в meta) — после инкрементальной переиндексации кеш не применяется.
 */
final class CatalogSearchTotalCacheService
{
    private const CACHE_DIR = '/myapp.catalog/search_total/';

    public static function cacheDir(): string
    {
        return self::CACHE_DIR;
    }

    /**
     * Нормализованная строка запроса + конфиг + бэкенд + версия индекса.
     */
    public static function buildCacheKey(string $normalizedQuery): string
    {
        $norm = trim($normalizedQuery);
        return md5(
            CatalogSearchBackendFactory::getBackendId()
            . '|' . CatalogSearchIndexService::searchConfigHash()
            . '|' . CatalogSearchIndexService::getSearchTotalCacheEpoch()
            . '|' . $norm
        );
    }

    public static function get(string $normalizedQuery): ?int
    {
        $norm = trim($normalizedQuery);
        if ($norm === '') {
            return null;
        }

        $cache = Cache::createInstance();
        if (
            !CatalogService::shouldSkipCache()
            && $cache->initCache(Assets::catalogCacheTtl(), self::buildCacheKey($norm), self::CACHE_DIR)
        ) {
            $vars = $cache->getVars();
            if (is_array($vars) && array_key_exists('total', $vars)) {
                return max(0, (int)$vars['total']);
            }
        }

        return null;
    }

    public static function set(string $normalizedQuery, int $total): void
    {
        $norm = trim($normalizedQuery);
        if ($norm === '' || CatalogService::shouldSkipCache()) {
            return;
        }

        $cache = Cache::createInstance();
        $started = $cache->startDataCache();
        if (!$started) {
            return;
        }

        $cache->endDataCache(['total' => max(0, $total)]);
    }

    public static function clearCache(): void
    {
        Cache::clearCache(false, self::CACHE_DIR);
    }
}
