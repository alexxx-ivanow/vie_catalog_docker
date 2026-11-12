<?php

namespace MyApp\Catalog\Filter;

use Bitrix\Main\Config\Option;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Service\BitmapIndexService;
use MyApp\Catalog\Service\ProductFilterIndexService;

final class CatalogFilterEngineFactory
{
    public const ENGINE_LEGACY = 'legacy';

    public const ENGINE_DENORMALIZED = 'denormalized';

    public const ENGINE_BITMAP = 'bitmap';

    private const MODULE_ID = 'myapp.catalog';

    /**
     * Single source of truth for the active filter engine.
     * Empty option migrates from legacy use_product_filter_index=Y → denormalized.
     */
    public static function getEngineId(): string
    {
        $id = trim((string)Option::get(self::MODULE_ID, Assets::OPT_CATALOG_FILTER_ENGINE, ''));
        if ($id === '') {
            $legacyFlag = Option::get(self::MODULE_ID, Assets::OPT_USE_PRODUCT_FILTER_INDEX, 'N') === 'Y';

            return $legacyFlag ? self::ENGINE_DENORMALIZED : self::ENGINE_LEGACY;
        }

        return match ($id) {
            self::ENGINE_BITMAP, self::ENGINE_DENORMALIZED, self::ENGINE_LEGACY => $id,
            default => self::ENGINE_LEGACY,
        };
    }

    /**
     * Effective engine for a goods request.
     * Selected engine wins; if not ready → legacy (no silent switch to another index).
     * Bitmap + article → legacy (article is out of bitmap scope).
     *
     * @param array<string, mixed> $filter
     */
    public static function resolveEngineIdForFilter(array $filter): string
    {
        $engineId = self::getEngineId();
        $article = trim((string)($filter['article'] ?? ''));

        if ($engineId === self::ENGINE_BITMAP && $article !== '') {
            return self::ENGINE_LEGACY;
        }

        if ($engineId === self::ENGINE_BITMAP && !BitmapIndexService::isIndexReady()) {
            return self::ENGINE_LEGACY;
        }

        if ($engineId === self::ENGINE_DENORMALIZED && !ProductFilterIndexService::isIndexReady()) {
            return self::ENGINE_LEGACY;
        }

        return $engineId;
    }

    public static function create(?string $engineId = null): ?CatalogFilterEngineInterface
    {
        $engineId = $engineId ?? self::getEngineId();

        return match ($engineId) {
            self::ENGINE_DENORMALIZED => new DenormalizedFilterEngine(),
            self::ENGINE_BITMAP => new BitmapFilterEngine(),
            default => null,
        };
    }

    public static function createForFilter(array $filter): ?CatalogFilterEngineInterface
    {
        $engineId = self::resolveEngineIdForFilter($filter);
        if ($engineId === self::ENGINE_LEGACY) {
            return null;
        }

        return self::create($engineId);
    }

    public static function engineVersionToken(string $engineId): string
    {
        return match ($engineId) {
            self::ENGINE_BITMAP => 'bm' . BitmapIndexService::getIndexVersion(),
            self::ENGINE_DENORMALIZED => 'ix' . ProductFilterIndexService::getIndexVersion(),
            default => 'lg0',
        };
    }
}
