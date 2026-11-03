<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlHelper;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Install\ProductFilterIndexSchema;
use MyApp\Catalog\Model\FilterIndexProductTable;

final class ProductFilterQueryService
{
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
        ProductFilterIndexSchema::ensureInfrastructure();

        if (!ProductFilterIndexService::shouldUseIndex() && !($filter['force_index'] ?? false)) {
            throw new \RuntimeException('Product filter index is not ready');
        }

        $perfBag = $perf ?? [];
        if ($perf !== null) {
            $perf['count_cache_hit'] = false;
        }
        $startedAt = microtime(true);
        $db = Application::getConnection();

        $sectionIds = CatalogService::getSectionIdsInSubtree($sectionId);
        if ($sectionId > 0 && $sectionIds === []) {
            return ['total' => 0, 'product_ids' => [], 'perf' => self::finalizeQueryPerf($perfBag, $startedAt)];
        }

        $filterForCount = CatalogService::normalizeIndexFilterForCount($filter);
        $skipCache = CatalogService::shouldSkipCache();
        $totalCacheKey = CatalogService::buildIndexGoodsTotalCacheKey($sectionId, $sectionIds, $filterForCount);

        $base = self::buildFilterBaseSql($sectionIds, $filterForCount, $db->getSqlHelper());
        $sort = CatalogService::normalizeGoodsSort((string)($filter['sort'] ?? ''));
        $sortParts = self::sortSqlParts($sort);

        $fromAndJoins = "
            FROM {$base['productTable']} p
            {$base['joinsSql']}
        ";
        $whereSql = $base['whereSql'];

        $countSql = "
            SELECT COUNT(DISTINCT p.PRODUCT_ID) AS CNT
            {$fromAndJoins}
            WHERE {$whereSql}
        ";

        $total = null;
        $countCache = \Bitrix\Main\Data\Cache::createInstance();
        if (!$skipCache && $countCache->initCache(
            Assets::catalogCacheTtl(),
            $totalCacheKey,
            CatalogService::goodsCountCacheDirPublic()
        )) {
            $cached = $countCache->getVars();
            $total = (int)($cached['total'] ?? 0);
            if ($perf !== null) {
                $perf['count_cache_hit'] = true;
                CatalogService::perfAddQueryPublic($perf, 'index_goods_count_cache_hit', 0.0);
            }
        }

        if ($total === null) {
            $t = microtime(true);
            $total = (int)$db->query($countSql)->fetch()['CNT'];
            if ($perf !== null) {
                CatalogService::perfAddQueryPublic($perf, 'index_goods_count', (microtime(true) - $t) * 1000, $countSql);
            }

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

        $pages = (int)ceil($total / max(1, $limit));
        $page = max(1, min($page, $pages ?: 1));
        $offset = (int)(($page - 1) * $limit);

        $listSql = "
            SELECT DISTINCT p.PRODUCT_ID
            {$fromAndJoins}
            {$sortParts['join']}
            WHERE {$whereSql}
            ORDER BY {$sortParts['orderBy']}
            LIMIT {$limit} OFFSET {$offset}
        ";
        $t = microtime(true);
        $res = $db->query($listSql);
        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['PRODUCT_ID'];
        }
        if ($perf !== null) {
            CatalogService::perfAddQueryPublic($perf, 'index_goods_list', (microtime(true) - $t) * 1000, $listSql);
        }

        return [
            'total' => $total,
            'product_ids' => $ids,
            'page' => $page,
            'pages' => $pages,
            'perf' => self::finalizeQueryPerf($perfBag, $startedAt),
        ];
    }

    /**
     * @param list<int> $sectionIds
     * @param array<string, mixed> $filter
     * @return array{productTable: string, joinsSql: string, whereSql: string}
     */
    private static function buildFilterBaseSql(array $sectionIds, array $filter, SqlHelper $helper): array
    {
        $productTable = FilterIndexProductTable::getTableName();
        $sectionTable = 'myapp_catalog_filter_product_section';
        $valueTable = 'myapp_catalog_filter_value';

        $joins = [];
        $where = ["p.ACTIVE = 'Y'", 'p.IBLOCK_ID = ' . Assets::catalogIblockId()];

        if ($sectionIds !== []) {
            $sectionIn = implode(',', array_map('intval', $sectionIds));
            $where[] = "EXISTS (
                SELECT 1 FROM {$sectionTable} ps
                WHERE ps.PRODUCT_ID = p.PRODUCT_ID
                  AND ps.SECTION_ID IN ({$sectionIn})
            )";
        }

        $article = trim((string)($filter['article'] ?? ''));
        if ($article !== '') {
            $articlePropertyId = CatalogService::getArticlePropertyId();
            if ($articlePropertyId > 0) {
                $escaped = $helper->forSql($article);
                $joins[] = "INNER JOIN {$valueTable} v_art ON v_art.PRODUCT_ID = p.PRODUCT_ID"
                    . " AND v_art.PROPERTY_ID = {$articlePropertyId}"
                    . " AND v_art.VALUE_STR LIKE '%{$escaped}%'";
            }
        }

        if (Assets::showCatalogFilter()) {
            $rawProperties = is_array($filter['properties'] ?? null) ? $filter['properties'] : [];
            $selected = CatalogService::normalizeSelectedFilterProperties($rawProperties);
            $valueJoinIndex = 0;
            foreach ($selected as $selectedProp) {
                $propertyId = (int)$selectedProp['def']['ID'];
                if ($propertyId <= 0) {
                    continue;
                }
                $alias = 'v_prop_' . $valueJoinIndex;
                ++$valueJoinIndex;

                if ($selectedProp['def']['valueKind'] === 'enum') {
                    $idsSql = implode(',', array_map('intval', $selectedProp['values']));
                    $joins[] = "INNER JOIN {$valueTable} {$alias} ON {$alias}.PRODUCT_ID = p.PRODUCT_ID"
                        . " AND {$alias}.PROPERTY_ID = {$propertyId}"
                        . " AND {$alias}.VALUE_ENUM IN ({$idsSql})";
                } elseif ($selectedProp['def']['valueKind'] === 'number') {
                    $parts = [];
                    foreach ($selectedProp['values'] as $val) {
                        $num = (float)str_replace(',', '.', (string)$val);
                        $parts[] = $alias . '.VALUE_NUM = ' . $num;
                    }
                    if ($parts !== []) {
                        $joins[] = "INNER JOIN {$valueTable} {$alias} ON {$alias}.PRODUCT_ID = p.PRODUCT_ID"
                            . " AND {$alias}.PROPERTY_ID = {$propertyId}"
                            . ' AND (' . implode(' OR ', $parts) . ')';
                    }
                } else {
                    $parts = [];
                    foreach ($selectedProp['values'] as $val) {
                        $parts[] = "'" . $helper->forSql((string)$val) . "'";
                    }
                    if ($parts !== []) {
                        $joins[] = "INNER JOIN {$valueTable} {$alias} ON {$alias}.PRODUCT_ID = p.PRODUCT_ID"
                            . " AND {$alias}.PROPERTY_ID = {$propertyId}"
                            . " AND {$alias}.VALUE_STR IN (" . implode(',', $parts) . ')';
                    }
                }
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
        }

        return [
            'productTable' => $productTable,
            'joinsSql' => $joins !== [] ? implode("\n            ", $joins) : '',
            'whereSql' => implode(' AND ', $where),
        ];
    }

    /**
     * @return array{join: string, orderBy: string}
     */
    private static function sortSqlParts(string $sort): array
    {
        $sort = CatalogService::normalizeGoodsSort($sort);
        switch ($sort) {
            case 'name':
                return [
                    'join' => 'INNER JOIN b_iblock_element e_sort ON e_sort.ID = p.PRODUCT_ID',
                    'orderBy' => 'e_sort.NAME ASC, p.PRODUCT_ID ASC',
                ];
            case 'price_asc':
                return [
                    'join' => '',
                    'orderBy' => 'COALESCE(p.PRICE, 0) ASC, p.PRODUCT_ID ASC',
                ];
            case 'price_desc':
                return [
                    'join' => '',
                    'orderBy' => 'COALESCE(p.PRICE, 0) DESC, p.PRODUCT_ID ASC',
                ];
            default:
                return [
                    'join' => '',
                    'orderBy' => 'p.SORT ASC, p.PRODUCT_ID DESC',
                ];
        }
    }

    /**
     * @param array<string, mixed> $perf
     * @return array<string, mixed>
     */
    private static function finalizeQueryPerf(array $perf, float $startedAt): array
    {
        $perf['index_query_ms'] = round((microtime(true) - $startedAt) * 1000, 2);

        return $perf;
    }

    private static function formatSqlDecimal(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $formatted !== '' ? $formatted : '0';
    }
}
