<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Install\BitmapIndexSchema;
use MyApp\Catalog\Model\BitmapProductTable;

final class BitmapFilterService
{
    /** Above this count: TEMP TABLE + JOIN instead of BITMAP_POSITION IN (...). */
    private const POSITION_IN_THRESHOLD = 512;

    private const POSITION_TEMP_INSERT_BATCH = 1000;

    private const TEMP_POSITIONS_TABLE = 'myapp_catalog_bmp_filter_pos';

    /**
     * @param array<string, mixed> $filter
     * @return array{total: int, product_ids: list<int>, page?: int, pages?: int, perf: array<string, mixed>}
     */
    public static function queryProductIds(
        int $sectionId,
        array $filter,
        int $page,
        int $limit,
        ?array &$perf = null
    ): array {
        BitmapIndexSchema::ensureInfrastructure();
        BitmapStorage::clearRequestCache();

        if (!BitmapIndexService::shouldUseIndex() && !($filter['force_bitmap'] ?? false)) {
            throw new \RuntimeException('Bitmap filter index is not ready');
        }

        if ($perf !== null) {
            $perf['count_cache_hit'] = false;
            $perf['bitmap_path'] = true;
            $perf['meta']['bitmap_bit_length'] = BitmapIndexService::getBitLength();
            $perf['meta']['bitmap_version'] = BitmapIndexService::getIndexVersion();
        }
        $startedAt = microtime(true);

        $t = microtime(true);
        $sectionIds = CatalogService::getSectionIdsInSubtree($sectionId);
        if ($perf !== null) {
            CatalogService::perfAddQueryPublic(
                $perf,
                'bitmap_section_ids',
                (microtime(true) - $t) * 1000,
                null,
                count($sectionIds)
            );
        }
        if ($sectionId > 0 && $sectionIds === []) {
            if ($perf !== null) {
                $perf['index_query_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
            }

            return ['total' => 0, 'product_ids' => [], 'perf' => $perf ?? []];
        }

        $filterForCount = CatalogService::normalizeIndexFilterForCount($filter);
        $bitLength = BitmapIndexService::getBitLength();
        $resultMask = null;
        $sectionMasksLoaded = 0;
        $propMasksLoaded = 0;

        if ($sectionId > 0) {
            $t = microtime(true);
            $built = self::buildSectionMask($sectionIds, $bitLength);
            $resultMask = $built['mask'];
            $sectionMasksLoaded = $built['masks_loaded'];
            if ($perf !== null) {
                CatalogService::perfAddQueryPublic(
                    $perf,
                    'bitmap_section_or',
                    (microtime(true) - $t) * 1000,
                    null,
                    $sectionMasksLoaded
                );
            }
        }

        if (Assets::showCatalogFilter()) {
            $t = microtime(true);
            $built = self::buildPropertiesMask($filterForCount, $bitLength);
            if ($built !== null) {
                $propMasksLoaded = $built['masks_loaded'];
                $resultMask = $resultMask === null
                    ? $built['mask']
                    : BitmapOperations::andMasks($resultMask, $built['mask']);
            }
            if ($perf !== null) {
                CatalogService::perfAddQueryPublic(
                    $perf,
                    'bitmap_props_and_or',
                    (microtime(true) - $t) * 1000,
                    null,
                    $propMasksLoaded
                );
            }
        }

        if ($perf !== null) {
            $perf['meta']['bitmap_section_masks'] = $sectionMasksLoaded;
            $perf['meta']['bitmap_prop_masks'] = $propMasksLoaded;
        }

        if ($resultMask !== null && BitmapOperations::isEmpty($resultMask)) {
            if ($perf !== null) {
                $perf['meta']['bitmap_popcount'] = 0;
                $perf['index_query_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
            }

            return [
                'total' => 0,
                'product_ids' => [],
                'page' => 1,
                'pages' => 0,
                'perf' => $perf ?? [],
            ];
        }

        $positions = null;
        if ($resultMask !== null) {
            $t = microtime(true);
            $positions = BitmapOperations::iterateSetBits($resultMask, max(0, $bitLength - 1));
            if ($perf !== null) {
                CatalogService::perfAddQueryPublic(
                    $perf,
                    'bitmap_iterate_bits',
                    (microtime(true) - $t) * 1000,
                    null,
                    count($positions)
                );
                $perf['meta']['bitmap_popcount'] = count($positions);
            }
        } elseif ($perf !== null) {
            $perf['meta']['bitmap_popcount'] = null;
        }

        $skipCache = CatalogService::shouldSkipCache();
        $totalCacheKey = 'bm|' . CatalogService::buildIndexGoodsTotalCacheKey($sectionId, $sectionIds, $filterForCount)
            . '|v' . BitmapIndexService::getIndexVersion();

        $total = null;
        $countCache = \Bitrix\Main\Data\Cache::createInstance();
        if (!$skipCache && $countCache->initCache(
            Assets::catalogCacheTtl(),
            md5($totalCacheKey),
            CatalogService::goodsCountCacheDirPublic()
        )) {
            $cached = $countCache->getVars();
            $total = (int)($cached['total'] ?? 0);
            if ($perf !== null) {
                $perf['count_cache_hit'] = true;
                CatalogService::perfAddQueryPublic($perf, 'bitmap_goods_count_cache_hit', 0.0);
            }
        }

        $page = max(1, $page);
        $limit = max(1, $limit);
        $sort = CatalogService::normalizeGoodsSort((string)($filter['sort'] ?? ''));

        $query = self::querySortedPage($positions, $filterForCount, $sort, $page, $limit, $total, $perf);

        if ($total === null) {
            $total = (int)$query['total'];
            if (!$skipCache) {
                $countCacheStarted = $countCache->startDataCache();
                CatalogService::commitTaggedDataCachePublic(
                    $countCache,
                    CatalogService::goodsCountCacheDirPublic(),
                    CatalogService::cacheTagsForSectionScopePublic($sectionId),
                    ['total' => $total],
                    $countCacheStarted
                );
            }
        }

        $ids = $query['product_ids'];
        $page = (int)$query['page'];
        $pages = (int)ceil($total / max(1, $limit));

        if ($perf !== null) {
            $perf['index_query_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
        }

        return [
            'total' => $total,
            'product_ids' => $ids,
            'page' => $page,
            'pages' => $pages,
            'perf' => $perf ?? [],
        ];
    }

    /**
     * @param list<int> $sectionIds
     * @return array{mask: string, masks_loaded: int}
     */
    private static function buildSectionMask(array $sectionIds, int $bitLength): array
    {
        $keys = [];
        foreach ($sectionIds as $sectionId) {
            $sectionId = (int)$sectionId;
            if ($sectionId > 0) {
                $keys[] = [
                    'property_id' => BitmapStorage::SECTION_PROPERTY_ID,
                    'value_id' => $sectionId,
                ];
            }
        }
        if ($keys === []) {
            return ['mask' => BitmapOperations::emptyMask($bitLength), 'masks_loaded' => 0];
        }

        $masks = BitmapStorage::loadMasks($keys, $bitLength);
        $combined = null;
        foreach ($masks as $mask) {
            $combined = $combined === null
                ? $mask
                : BitmapOperations::orMasks($combined, $mask);
        }

        return [
            'mask' => $combined ?? BitmapOperations::emptyMask($bitLength),
            'masks_loaded' => count($masks),
        ];
    }

    /**
     * @param array<string, mixed> $filter
     * @return array{mask: string, masks_loaded: int}|null
     */
    private static function buildPropertiesMask(array $filter, int $bitLength): ?array
    {
        $rawProperties = is_array($filter['properties'] ?? null) ? $filter['properties'] : [];
        $selected = CatalogService::normalizeSelectedFilterProperties($rawProperties);
        if ($selected === []) {
            return null;
        }

        $combined = null;
        $masksLoaded = 0;
        foreach ($selected as $selectedProp) {
            $propertyId = (int)$selectedProp['def']['ID'];
            if ($propertyId <= 0) {
                continue;
            }
            $valueKind = (string)$selectedProp['def']['valueKind'];
            $valueKeys = [];
            foreach ($selectedProp['values'] as $value) {
                $valueId = BitmapIndexService::resolveValueId($propertyId, $valueKind, $value, false);
                if ($valueId === null) {
                    continue;
                }
                $valueKeys[] = ['property_id' => $propertyId, 'value_id' => $valueId];
            }
            if ($valueKeys === []) {
                return [
                    'mask' => BitmapOperations::emptyMask($bitLength),
                    'masks_loaded' => $masksLoaded,
                ];
            }

            $masks = BitmapStorage::loadMasks($valueKeys, $bitLength);
            $masksLoaded += count($masks);
            $propMask = null;
            foreach ($masks as $mask) {
                $propMask = $propMask === null
                    ? $mask
                    : BitmapOperations::orMasks($propMask, $mask);
            }
            $propMask = $propMask ?? BitmapOperations::emptyMask($bitLength);
            $combined = $combined === null
                ? $propMask
                : BitmapOperations::andMasks($combined, $propMask);
        }

        if ($combined === null) {
            return null;
        }

        return ['mask' => $combined, 'masks_loaded' => $masksLoaded];
    }

    /**
     * @param list<int>|null $positions
     * @param array<string, mixed> $filter
     * @param array<string, mixed>|null $perf
     * @return array{total: int, product_ids: list<int>, page: int}
     */
    private static function querySortedPage(
        ?array $positions,
        array $filter,
        string $sort,
        int $page,
        int $limit,
        ?int $knownTotal,
        ?array &$perf = null
    ): array {
        $table = BitmapProductTable::getTableName();
        $iblockId = Assets::catalogIblockId();
        $where = [
            "p.ACTIVE = 'Y'",
            'p.IBLOCK_ID = ' . $iblockId,
        ];

        $db = Application::getConnection();
        $positionJoin = '';
        $positionCleanup = null;

        if ($positions !== null) {
            if ($positions === []) {
                return ['total' => 0, 'product_ids' => [], 'page' => 1];
            }
            $constraint = self::buildPositionSqlConstraint($db, $positions, $perf);
            $positionJoin = $constraint['join'];
            if ($constraint['where'] !== '') {
                $where[] = $constraint['where'];
            }
            $positionCleanup = $constraint['cleanup'];
            if ($perf !== null) {
                $perf['meta']['bitmap_id_resolve'] = $constraint['resolve_mode'];
            }
        } elseif ($perf !== null) {
            $perf['meta']['bitmap_id_resolve'] = 'full_scan_active';
        }

        $hasPriceFrom = isset($filter['price_from']) && $filter['price_from'] !== '' && $filter['price_from'] !== null;
        $hasPriceTo = isset($filter['price_to']) && $filter['price_to'] !== '' && $filter['price_to'] !== null;
        if ($hasPriceFrom || $hasPriceTo) {
            $priceFrom = $hasPriceFrom ? (float)$filter['price_from'] : null;
            $priceTo = $hasPriceTo ? (float)$filter['price_to'] : null;
            $priceFrom = $priceFrom !== null
                ? CatalogService::convertCatalogPricePublic(
                    $priceFrom,
                    CatalogService::catalogDisplayCurrencyPublic(),
                    CatalogService::catalogPriceStorageCurrencyPublic()
                )
                : null;
            $priceTo = $priceTo !== null
                ? CatalogService::convertCatalogPricePublic(
                    $priceTo,
                    CatalogService::catalogDisplayCurrencyPublic(),
                    CatalogService::catalogPriceStorageCurrencyPublic()
                )
                : null;
            $where[] = 'p.PRICE IS NOT NULL';
            if ($priceFrom !== null) {
                $where[] = 'p.PRICE >= ' . self::formatSqlDecimal($priceFrom);
            }
            if ($priceTo !== null) {
                $where[] = 'p.PRICE <= ' . self::formatSqlDecimal($priceTo);
            }
        }

        $whereSql = implode(' AND ', $where);

        try {
            if ($knownTotal === null) {
                $countSql = "SELECT COUNT(*) FROM {$table} p{$positionJoin} WHERE {$whereSql}";
                $t = microtime(true);
                $total = (int)$db->queryScalar($countSql);
                if ($perf !== null) {
                    CatalogService::perfAddQueryPublic(
                        $perf,
                        'bitmap_goods_count',
                        (microtime(true) - $t) * 1000,
                        $countSql,
                        $total
                    );
                }
            } else {
                $total = $knownTotal;
            }

            $pages = (int)ceil($total / max(1, $limit));
            $page = max(1, min($page, $pages ?: 1));
            $offset = (int)(($page - 1) * $limit);

            $join = '';
            $orderBy = 'p.SORT ASC, p.PRODUCT_ID DESC';
            if ($sort === 'name') {
                $join = ' INNER JOIN b_iblock_element e_sort ON e_sort.ID = p.PRODUCT_ID';
                $orderBy = 'e_sort.NAME ASC, p.PRODUCT_ID ASC';
            } elseif ($sort === 'price_asc') {
                $orderBy = 'COALESCE(p.PRICE, 0) ASC, p.PRODUCT_ID ASC';
            } elseif ($sort === 'price_desc') {
                $orderBy = 'COALESCE(p.PRICE, 0) DESC, p.PRODUCT_ID ASC';
            }

            $listSql = "
            SELECT p.PRODUCT_ID
            FROM {$table} p{$positionJoin}{$join}
            WHERE {$whereSql}
            ORDER BY {$orderBy}
            LIMIT {$limit} OFFSET {$offset}
        ";
            $t = microtime(true);
            $res = $db->query($listSql);
            $ids = [];
            while ($row = $res->fetch()) {
                $ids[] = (int)$row['PRODUCT_ID'];
            }
            if ($perf !== null) {
                CatalogService::perfAddQueryPublic(
                    $perf,
                    'bitmap_goods_list',
                    (microtime(true) - $t) * 1000,
                    $listSql,
                    count($ids)
                );
            }

            return ['total' => $total, 'product_ids' => $ids, 'page' => $page];
        } finally {
            if ($positionCleanup !== null) {
                $positionCleanup();
            }
        }
    }

    /**
     * @param list<int> $positions
     * @param array<string, mixed>|null $perf
     * @return array{
     *   join: string,
     *   where: string,
     *   cleanup: (callable(): void)|null,
     *   resolve_mode: string
     * }
     */
    private static function buildPositionSqlConstraint(
        \Bitrix\Main\DB\Connection $db,
        array $positions,
        ?array &$perf = null
    ): array {
        $positions = array_values(array_unique(array_map('intval', $positions)));
        $count = count($positions);

        if ($count <= self::POSITION_IN_THRESHOLD) {
            return [
                'join' => '',
                'where' => 'p.BITMAP_POSITION IN (' . implode(',', $positions) . ')',
                'cleanup' => null,
                'resolve_mode' => 'position_in',
            ];
        }

        $tempTable = self::TEMP_POSITIONS_TABLE;
        $t0 = microtime(true);
        $db->query("
            CREATE TEMPORARY TABLE {$tempTable} (
                BITMAP_POSITION INT NOT NULL,
                PRIMARY KEY (BITMAP_POSITION)
            ) ENGINE=Memory
        ");

        foreach (array_chunk($positions, self::POSITION_TEMP_INSERT_BATCH) as $chunk) {
            $values = implode(',', array_map(static fn (int $p): string => '(' . $p . ')', $chunk));
            $db->query("INSERT INTO {$tempTable} (BITMAP_POSITION) VALUES {$values}");
        }

        if ($perf !== null) {
            CatalogService::perfAddQueryPublic(
                $perf,
                'bitmap_positions_temp',
                (microtime(true) - $t0) * 1000,
                null,
                $count
            );
        }

        return [
            'join' => " INNER JOIN {$tempTable} bmp_f ON bmp_f.BITMAP_POSITION = p.BITMAP_POSITION",
            'where' => '',
            'cleanup' => static function () use ($db, $tempTable): void {
                $db->query("DROP TEMPORARY TABLE IF EXISTS {$tempTable}");
            },
            'resolve_mode' => 'position_temp',
        ];
    }

    private static function formatSqlDecimal(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $formatted !== '' ? $formatted : '0';
    }
}
