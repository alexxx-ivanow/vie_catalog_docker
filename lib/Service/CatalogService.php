<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Iblock\ElementTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Main\FileTable;
use MyApp\Catalog\Config\Assets;

class CatalogService
{
    public const GOODS_PER_PAGE  = 12;
    public const MAX_GOODS_PER_PAGE = 48;
    private const FALLBACK_PRICE_GROUP_ID = 1;

    private const CACHE_DIR_PRODUCT = '/catalog/product/';

    private const CACHE_DIR_GOODS = '/catalog/goods/';

    private const CACHE_DIR_GOODS_COUNT = '/catalog/goods_count/';

    private const CACHE_DIR_FILTERS = '/catalog/filters/';

    private static function iblockId(): int
    {
        return Assets::catalogIblockId();
    }

    // Категории первого уровня
    public static function getSections(): array
    {
        Loader::includeModule('iblock');

        $db  = Application::getConnection();
        $sql = "
        SELECT ID, NAME, CODE, PICTURE, DESCRIPTION
        FROM b_iblock_section
        WHERE IBLOCK_ID   = " . self::iblockId() . "
          AND ACTIVE       = 'Y'
          AND DEPTH_LEVEL  = 1
        ORDER BY SORT ASC, NAME ASC
    ";

        $res      = $db->query($sql);
        $sections = [];

        while ($row = $res->fetch()) {
            $row['PICTURE'] = (int)$row['PICTURE']
                ? self::getFilePath((int)$row['PICTURE'])
                : Assets::NO_PHOTO_IMAGE;
            $sections[] = $row;
        }

        return $sections;
    }

    // Получить LEFT_MARGIN / RIGHT_MARGIN секции — для выборки всех вложенных товаров
    /** @var array<int, array<string, mixed>|null> */
    private static array $sectionBoundsRequestCache = [];

    public static function getSectionBounds(int $sectionId): ?array
    {
        $sectionId = (int)$sectionId;
        if ($sectionId <= 0) {
            return null;
        }

        if (array_key_exists($sectionId, self::$sectionBoundsRequestCache)) {
            return self::$sectionBoundsRequestCache[$sectionId];
        }

        $db  = Application::getConnection();
        $sql = "
        SELECT LEFT_MARGIN, RIGHT_MARGIN, NAME
        FROM b_iblock_section
        WHERE ID       = {$sectionId}
          AND IBLOCK_ID = " . self::iblockId() . "
          AND ACTIVE    = 'Y'
        LIMIT 1
    ";

        self::$sectionBoundsRequestCache[$sectionId] = $db->query($sql)->fetch() ?: null;

        return self::$sectionBoundsRequestCache[$sectionId];
    }

    // Карточка товара
    public static function getProduct(int $id, ?int $uid = null): ?array
    {
        $id = (int)$id;
        if ($id <= 0) {
            return null;
        }

        $base = self::loadProductBaseCached($id);
        if ($base === null) {
            return null;
        }

        return self::applySkuPresentation($base, $uid);
    }

    /**
     * Загрузка карточки по ID позиции каталога (родитель или оффер).
     *
     * @return array<string, mixed>|null
     */
    public static function getProductForCatalogItem(int $catalogItemId): ?array
    {
        $catalogItemId = (int)$catalogItemId;
        if ($catalogItemId <= 0) {
            return null;
        }

        $parentId = CatalogSkuService::resolveParentIdFromOffer($catalogItemId);
        if ($parentId > 0 && $parentId !== $catalogItemId) {
            $product = self::getProduct($parentId, $catalogItemId);
            if ($product !== null) {
                $product['PARENT_ID'] = $parentId;
                $product['OFFER_ID'] = $catalogItemId;
            }

            return $product;
        }

        return self::getProduct($catalogItemId);
    }

    public static function resolveFilePathPublic(?int $fileId): string
    {
        if (!$fileId) {
            return Assets::NO_PHOTO_IMAGE;
        }

        $path = self::getFilePath($fileId);

        return $path !== '' ? $path : Assets::NO_PHOTO_IMAGE;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadProductBaseCached(int $id): ?array
    {
        $skipCache = self::shouldSkipCache();
        $skuSuffix = '';
        if (CatalogSkuService::getOffersIblockId() > 0) {
            $skuSuffix = '_sku' . CatalogSkuService::getOffersIblockId() . '_v3';
        }
        $cacheKey = 'product_' . $id
            . '_g' . self::catalogPriceGroupId()
            . '_c' . self::catalogDisplayCurrency()
            . '_p' . md5(implode(',', Assets::cardPropertyCodes()))
            . '_a' . md5(Assets::catalogArticlePropertyCode())
            . $skuSuffix;
        $cache = \Bitrix\Main\Data\Cache::createInstance();
        if (!$skipCache && $cache->initCache(Assets::catalogCacheTtl(), $cacheKey, self::CACHE_DIR_PRODUCT)) {
            $cached = $cache->getVars();

            return is_array($cached) && array_key_exists('product', $cached)
                ? $cached['product']
                : null;
        }
        $productCacheStarted = !$skipCache && $cache->startDataCache();

        Loader::includeModule('iblock');
        Loader::includeModule('catalog');

        $result = ElementTable::getList([
            'select' => ['ID', 'NAME', 'CODE', 'DETAIL_TEXT', 'DETAIL_PICTURE'],
            'filter' => ['=IBLOCK_ID' => self::iblockId(), '=ACTIVE' => 'Y', '=ID' => $id],
        ])->fetch();

        if (!$result) {
            if (!$skipCache) {
                self::commitTaggedDataCache(
                    $cache,
                    self::CACHE_DIR_PRODUCT,
                    self::cacheTagsForProduct($id),
                    ['product' => null],
                    $productCacheStarted
                );
            }

            return null;
        }

        if ($result['DETAIL_PICTURE']) {
            $result['DETAIL_PICTURE'] = self::getFilePath((int)$result['DETAIL_PICTURE']);
        } else {
            $result['DETAIL_PICTURE'] = Assets::NO_PHOTO_IMAGE;
        }

        $properties = self::fetchProductCardProperties($id);
        $articlePropertyCode = Assets::catalogArticlePropertyCode();
        $articleCodeUpper = strtoupper($articlePropertyCode);
        $result['articlePropertyCode'] = $articlePropertyCode;
        $result['properties'] = array_values(array_filter(
            $properties,
            static fn(array $property): bool => strtoupper((string)($property['code'] ?? '')) !== $articleCodeUpper
        ));
        $section = self::getMainSectionForProduct($id);
        $result['SECTION_ID'] = (int)($section['ID'] ?? 0);
        $result['SECTION_NAME'] = (string)($section['NAME'] ?? '');

        $hasOffers = CatalogSkuService::isSkuProduct($id);
        $result['hasOffers'] = $hasOffers;

        if ($hasOffers) {
            $skuPayload = CatalogSkuService::buildSkuPayload($id);
            $result['sku'] = [
                'tree' => $skuPayload['tree'],
                'offers' => $skuPayload['offers'],
                'defaultOfferId' => $skuPayload['defaultOfferId'],
            ];
            $result['PRICE'] = 0.0;
            $result['ARTICLE'] = '';
        } else {
            $result['sku'] = null;
            $result['PRICE'] = self::getPrice($id);
            $result['ARTICLE'] = self::extractPropertyValueByCode($properties, $articlePropertyCode);
        }
        $result['CURRENCY'] = self::catalogDisplayCurrency();

        if (!$skipCache) {
            self::commitTaggedDataCache(
                $cache,
                self::CACHE_DIR_PRODUCT,
                self::cacheTagsForProduct($id),
                ['product' => $result],
                $productCacheStarted
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private static function applySkuPresentation(array $product, ?int $uid): array
    {
        if (empty($product['hasOffers']) || !is_array($product['sku'] ?? null)) {
            $product['activeOfferId'] = 0;
            $product['selectedSkuProps'] = new \stdClass();

            return $product;
        }

        $offers = is_array($product['sku']['offers'] ?? null) ? $product['sku']['offers'] : [];
        $parentId = (int)$product['ID'];
        $activeOfferId = CatalogSkuService::resolveActiveOfferId($parentId, $uid);
        $product['activeOfferId'] = $activeOfferId;
        $selectedProps = CatalogSkuService::selectedSkuPropsFromOffer($offers, $activeOfferId);
        $product['selectedSkuProps'] = $selectedProps === [] ? new \stdClass() : $selectedProps;

        foreach ($offers as $offer) {
            if ((int)($offer['id'] ?? 0) !== $activeOfferId) {
                continue;
            }
            $product['PRICE'] = (float)($offer['price'] ?? 0);
            $product['ARTICLE'] = (string)($offer['article'] ?? '');
            if (!empty($offer['picture'])) {
                $product['DETAIL_PICTURE'] = (string)$offer['picture'];
            }
            break;
        }

        return $product;
    }

    public static function getGoods(
        array $filter    = [],
        int   $page      = 1,
        int   $limit     = self::GOODS_PER_PAGE,
        int   $sectionId = 0
    ): array {
        $measurePerf = self::shouldMeasurePerf();
        $skipCache = self::shouldSkipCache();
        $perfStartedAt = microtime(true);
        $perf = self::createPerfBag();

        $page = max(1, $page);
        $limit = self::normalizeGoodsLimit($limit);
        $sort = self::normalizeGoodsSort((string)($filter['sort'] ?? ''));
        $filter['sort'] = $sort;

        // Кеш — включаем sectionId и нормализованный фильтр в ключ.
        ksort($filter);
        $cacheKey = md5(
            serialize($filter) . '|' . $page . '|' . $limit . '|' . $sectionId
            . '|' . (Assets::showCatalogFilter() ? '1' : '0')
            . '|' . Assets::catalogPriceTypeCode()
            . '|' . implode(',', Assets::filterPropertyCodes())
            . '|' . self::catalogDisplayCurrency()
            . '|ix' . (ProductFilterIndexService::shouldUseIndex() ? ProductFilterIndexService::getIndexVersion() : 0)
        );
        $cache = \Bitrix\Main\Data\Cache::createInstance();
        if (!$skipCache && $cache->initCache(Assets::catalogCacheTtl(), $cacheKey, self::CACHE_DIR_GOODS)) {
            $result = $cache->getVars();
            if ($measurePerf) {
                $perf['cache_hit'] = true;
                self::finalizePerf($perf, $perfStartedAt);
                $result['_perf'] = $perf;
            }
            return $result;
        }
        $goodsCacheStarted = !$skipCache && $cache->startDataCache();

        Loader::includeModule('iblock');
        Loader::includeModule('catalog');

        $db = Application::getConnection();

        $sqlHelper = $db->getSqlHelper();
        $whereParts = [
            "e.IBLOCK_ID = " . self::iblockId(),
            "e.ACTIVE = 'Y'",
        ];
        $joinParts = [];
        $needsDistinct = false;
        $sectionName = '';
        $subsections = [];
        $sectionIds = [];
        $sectionIdsSql = '';

        // Фильтр по секции через LEFT/RIGHT MARGIN — захватывает вложенные секции.
        if ($sectionId > 0) {
            $stageStartedAt = microtime(true);

            $t = microtime(true);
            $bounds = self::getSectionBounds($sectionId);
            if ($measurePerf) {
                self::perfAddQuery($perf, 'section_bounds', (microtime(true) - $t) * 1000);
            }
            if (!$bounds) {
                $result = self::emptyResult($page, $limit, '');
                if (!$skipCache) {
                    self::commitTaggedDataCache(
                        $cache,
                        self::CACHE_DIR_GOODS,
                        self::cacheTagsForGoodsListing($sectionId, []),
                        $result,
                        $goodsCacheStarted
                    );
                }
                if ($measurePerf) {
                    self::finalizePerf($perf, $perfStartedAt);
                    $result['_perf'] = $perf;
                }
                return $result;
            }

            $sectionName = (string)$bounds['NAME'];

            $t = microtime(true);
            $subsections = self::getDirectSubsections($sectionId);
            if ($measurePerf) {
                self::perfAddQuery($perf, 'section_subsections', (microtime(true) - $t) * 1000, null, count($subsections));
            }

            $lm = (int)$bounds['LEFT_MARGIN'];
            $rm = (int)$bounds['RIGHT_MARGIN'];

            $t = microtime(true);
            $sectionIds = self::getSectionIdsByBounds($lm, $rm);
            if ($measurePerf) {
                self::perfAddQuery($perf, 'section_ids_by_bounds', (microtime(true) - $t) * 1000, null, count($sectionIds));
            }
            if (empty($sectionIds)) {
                $result = self::emptyResult($page, $limit, $sectionName, $subsections);
                if (!$skipCache) {
                    self::commitTaggedDataCache(
                        $cache,
                        self::CACHE_DIR_GOODS,
                        self::cacheTagsForGoodsListing($sectionId, []),
                        $result,
                        $goodsCacheStarted
                    );
                }
                if ($measurePerf) {
                    self::finalizePerf($perf, $perfStartedAt);
                    $result['_perf'] = $perf;
                }
                return $result;
            }

            $sectionIdsSql = implode(',', array_map('intval', $sectionIds));
            // JOIN по секциям быстрее коррелированного EXISTS; DISTINCT нужен из‑за пересечений.
            $joinParts[] = "INNER JOIN b_iblock_section_element se_scope
                ON se_scope.IBLOCK_ELEMENT_ID = e.ID
               AND se_scope.IBLOCK_SECTION_ID IN ({$sectionIdsSql})";
            $needsDistinct = true;
            if ($measurePerf) {
                $perf['stages_ms']['section_scope'] = round((microtime(true) - $stageStartedAt) * 1000, 2);
                $perf['meta']['section_ids_count'] = count($sectionIds);
            }
        }

        if (ProductFilterIndexService::shouldUseIndex()) {
            return self::getGoodsViaProductIndex(
                $filter,
                $page,
                $limit,
                $sectionId,
                $sectionName,
                $subsections,
                $skipCache,
                $cache,
                $goodsCacheStarted,
                $measurePerf,
                $perf,
                $perfStartedAt
            );
        }

        $article = trim((string)($filter['article'] ?? ''));
        if ($article !== '') {
            $articlePropertyId = self::getPropertyIdByCode(Assets::catalogArticlePropertyCode());
            if ($articlePropertyId > 0) {
                $articleEscaped = $sqlHelper->forSql($article);
                $whereParts[] = "EXISTS (
                    SELECT 1
                    FROM b_iblock_element_property p_article
                    WHERE p_article.IBLOCK_ELEMENT_ID = e.ID
                      AND p_article.IBLOCK_PROPERTY_ID = {$articlePropertyId}
                      AND p_article.VALUE LIKE '%{$articleEscaped}%'
                )";
            }
        }

        $selectedProperties = [];
        $hasPriceFrom = false;
        $hasPriceTo = false;
        if (Assets::showCatalogFilter()) {
            $rawProperties = is_array($filter['properties'] ?? null) ? $filter['properties'] : [];
            $selectedProperties = self::normalizeSelectedProperties($rawProperties);
            foreach ($selectedProperties as $index => $selected) {
                $propertyId = (int)$selected['def']['ID'];
                if ($propertyId <= 0) {
                    continue;
                }

                if (!empty($selected['def']['offersIblock'])) {
                    $offersIblockId = CatalogSkuService::getOffersIblockId();
                    $linkPropId = CatalogSkuService::getLinkPropertyId();
                    if ($offersIblockId <= 0 || $linkPropId <= 0) {
                        continue;
                    }
                    $offerProp = 'p_offer_f_' . $index;
                    if ($selected['def']['valueKind'] === 'enum') {
                        $idsSql = implode(',', array_map('intval', $selected['values']));
                        $valueSql = "({$offerProp}.VALUE_ENUM IN ({$idsSql}) OR {$offerProp}.VALUE IN ({$idsSql}))";
                    } else {
                        $valuesSql = implode(',', array_map(
                            static fn(string $value): string => "'" . $sqlHelper->forSql($value) . "'",
                            $selected['values']
                        ));
                        $valueSql = "{$offerProp}.VALUE IN ({$valuesSql})";
                    }
                    $whereParts[] = "EXISTS (
                        SELECT 1
                        FROM b_iblock_element_property lp_offer_f_{$index}
                        INNER JOIN b_iblock_element o_offer_f_{$index}
                            ON o_offer_f_{$index}.ID = lp_offer_f_{$index}.IBLOCK_ELEMENT_ID
                           AND o_offer_f_{$index}.IBLOCK_ID = {$offersIblockId}
                           AND o_offer_f_{$index}.ACTIVE = 'Y'
                        INNER JOIN b_iblock_element_property {$offerProp}
                            ON {$offerProp}.IBLOCK_ELEMENT_ID = o_offer_f_{$index}.ID
                           AND {$offerProp}.IBLOCK_PROPERTY_ID = {$propertyId}
                           AND {$valueSql}
                        WHERE lp_offer_f_{$index}.IBLOCK_PROPERTY_ID = {$linkPropId}
                          AND lp_offer_f_{$index}.VALUE = e.ID
                    )";
                    continue;
                }

                $alias = 'p_filter_' . $index;
                if ($selected['def']['valueKind'] === 'enum') {
                    $idsSql = implode(',', array_map('intval', $selected['values']));
                    $valueSql = "({$alias}.VALUE_ENUM IN ({$idsSql}) OR {$alias}.VALUE IN ({$idsSql}))";
                } else {
                    $valuesSql = implode(',', array_map(
                        static fn(string $value): string => "'" . $sqlHelper->forSql($value) . "'",
                        $selected['values']
                    ));
                    $valueSql = "{$alias}.VALUE IN ({$valuesSql})";
                }
                $joinParts[] = "INNER JOIN b_iblock_element_property {$alias}
                    ON {$alias}.IBLOCK_ELEMENT_ID = e.ID
                   AND {$alias}.IBLOCK_PROPERTY_ID = {$propertyId}
                   AND {$valueSql}";
                $needsDistinct = true;
            }

            $hasPriceFrom = isset($filter['price_from']) && $filter['price_from'] !== '' && $filter['price_from'] !== null;
            $hasPriceTo = isset($filter['price_to']) && $filter['price_to'] !== '' && $filter['price_to'] !== null;
            if ($hasPriceFrom || $hasPriceTo) {
                $priceFrom = $hasPriceFrom ? (float)$filter['price_from'] : null;
                $priceTo = $hasPriceTo ? (float)$filter['price_to'] : null;
                $priceFrom = $priceFrom !== null
                    ? self::convertCatalogPrice($priceFrom, self::catalogDisplayCurrency(), self::catalogPriceStorageCurrency())
                    : null;
                $priceTo = $priceTo !== null
                    ? self::convertCatalogPrice($priceTo, self::catalogDisplayCurrency(), self::catalogPriceStorageCurrency())
                    : null;

                $priceJoin = "INNER JOIN b_catalog_price cp
                    ON cp.PRODUCT_ID = e.ID
                   AND cp.CATALOG_GROUP_ID = " . self::catalogPriceGroupId();
                if ($priceFrom !== null) {
                    $priceJoin .= " AND cp.PRICE >= {$priceFrom}";
                }
                if ($priceTo !== null) {
                    $priceJoin .= " AND cp.PRICE <= {$priceTo}";
                }
                $joinParts[] = $priceJoin;
            }
        }

        $hasExtraFilters = $article !== ''
            || !empty($selectedProperties)
            || $hasPriceFrom
            || $hasPriceTo;

        $useSectionFastPath = ($sectionId > 0 && $sectionIdsSql !== '' && !$hasExtraFilters);

        $perfForQueries = null;
        if ($measurePerf) {
            $perfForQueries = &$perf;
        }

        $sortParts = self::goodsSortSqlParts($sort);

        $stageStartedAt = microtime(true);
        $scopedIds = null;
        if ($useSectionFastPath) {
            // Один проход по секции даёт и total, и порядок: раньше COUNT и ORDER BY
            // дважды делали один и тот же JOIN по всем товарам секции.
            $scopeSortJoin = $sortParts['join'] !== '' ? "\n" . $sortParts['join'] : '';
            $scopeSql = "
                SELECT e.ID
                FROM b_iblock_section_element se
                INNER JOIN b_iblock_element e
                    ON e.ID = se.IBLOCK_ELEMENT_ID{$scopeSortJoin}
                WHERE se.IBLOCK_SECTION_ID IN ({$sectionIdsSql})
                  AND e.IBLOCK_ID = " . self::iblockId() . "
                  AND e.ACTIVE = 'Y'
                ORDER BY {$sortParts['orderBy']}
            ";
            $scopeRes = self::queryWithPerf($db, $scopeSql, $perfForQueries, 'goods_section_scope');
            $seen = [];
            while ($row = $scopeRes->fetchRaw()) {
                $seen[(int)$row['ID']] = true;
            }
            $scopedIds = array_keys($seen);
            unset($seen);
            $total = count($scopedIds);
            if ($measurePerf) {
                $perf['meta']['count_mode'] = 'section_scope';
            }
        } else {
            $where = implode(' AND ', $whereParts);
            $joins = !empty($joinParts) ? "\n" . implode("\n", $joinParts) : '';
            $fromSql = "FROM b_iblock_element e{$joins}";
            $countCacheKey = md5($fromSql . '|' . $where . '|' . ($needsDistinct ? '1' : '0'));
            $totalInfo = self::getTotalCountCached(
                $db,
                $fromSql,
                $where,
                $needsDistinct,
                $countCacheKey,
                $skipCache,
                $sectionId,
                $perfForQueries
            );
            $total = (int)$totalInfo['total'];
            if ($measurePerf) {
                $perf['count_cache_hit'] = (bool)$totalInfo['cache_hit'];
                $perf['meta']['count_mode'] = 'full';
            }
        }
        if ($measurePerf) {
            $perf['stages_ms']['count'] = round((microtime(true) - $stageStartedAt) * 1000, 2);
        }
        $pages = (int)ceil($total / $limit);
        $page = max(1, min($page, $pages ?: 1));
        $offset = ($page - 1) * $limit;

        $stageStartedAt = microtime(true);
        $items = [];
        $itemIds = [];
        $pictureIds = [];

        if ($scopedIds !== null) {
            $pageIds = array_slice($scopedIds, $offset, $limit);
            if (!empty($pageIds)) {
                $idsList = implode(',', $pageIds);
                $detailsSql = "
                    SELECT e.ID, e.NAME, e.CODE, e.PREVIEW_TEXT, e.DETAIL_PICTURE
                    FROM b_iblock_element e
                    WHERE e.ID IN ({$idsList})
                ";
                $detailsRes = self::queryWithPerf($db, $detailsSql, $perfForQueries, 'goods_list_details');
                $byId = [];
                while ($row = $detailsRes->fetch()) {
                    $byId[(int)$row['ID']] = $row;
                }
                foreach ($pageIds as $pageId) {
                    if (!isset($byId[$pageId])) {
                        continue;
                    }
                    $row = $byId[$pageId];
                    $pictureId = (int)$row['DETAIL_PICTURE'];
                    $itemIds[] = $pageId;
                    if ($pictureId > 0) {
                        $pictureIds[$pictureId] = $pictureId;
                    }
                    $items[] = $row;
                }
            }
        } else {
            $where = implode(' AND ', $whereParts);
            $listJoinParts = $joinParts;
            if ($sortParts['join'] !== '') {
                $listJoinParts[] = $sortParts['join'];
            }
            $joins = !empty($listJoinParts) ? "\n" . implode("\n", $listJoinParts) : '';
            $fromSql = "FROM b_iblock_element e{$joins}";
            $groupBy = $needsDistinct
                ? 'GROUP BY e.ID, e.NAME, e.CODE, e.PREVIEW_TEXT, e.DETAIL_PICTURE, e.SORT' . $sortParts['groupByExtra']
                : '';
            $sql = "
                SELECT e.ID, e.NAME, e.CODE, e.PREVIEW_TEXT, e.DETAIL_PICTURE
                {$fromSql}
                WHERE {$where}
                {$groupBy}
                ORDER BY {$sortParts['orderBy']}
                LIMIT {$limit} OFFSET {$offset}
            ";
            $res = self::queryWithPerf($db, $sql, $perfForQueries, 'goods_list');
            while ($row = $res->fetch()) {
                $itemId = (int)$row['ID'];
                $pictureId = (int)$row['DETAIL_PICTURE'];
                $itemIds[] = $itemId;
                if ($pictureId > 0) {
                    $pictureIds[$pictureId] = $pictureId;
                }
                $items[] = $row;
            }
        }
        if ($measurePerf) {
            $perf['stages_ms']['list'] = round((microtime(true) - $stageStartedAt) * 1000, 2);
            $perf['meta']['items_count'] = count($items);
        }

        $stageStartedAt = microtime(true);
        $pictureMap = self::getFilePaths(array_values($pictureIds));
        if ($measurePerf) {
            $filesMs = (microtime(true) - $stageStartedAt) * 1000;
            $perf['stages_ms']['files'] = round($filesMs, 2);
            self::perfAddQuery($perf, 'files_paths', $filesMs, null, count($pictureIds));
        }

        $stageStartedAt = microtime(true);
        $prices = self::getPrices($itemIds);
        if ($measurePerf) {
            $pricesMs = (microtime(true) - $stageStartedAt) * 1000;
            $perf['stages_ms']['prices'] = round($pricesMs, 2);
            self::perfAddQuery($perf, 'prices', $pricesMs, null, count($itemIds));
        }

        // Свойства (артикул/производитель/…) на карточке списка не нужны — не тянем их.
        foreach ($items as &$item) {
            $id = (int)$item['ID'];
            $detailPictureId = (int)$item['DETAIL_PICTURE'];

            $item['DETAIL_PICTURE'] = $detailPictureId > 0
                ? ($pictureMap[$detailPictureId] ?? Assets::NO_PHOTO_IMAGE)
                : Assets::NO_PHOTO_IMAGE;
        }
        unset($item);
        self::applySkuPricingToListItems($items, $prices);

        $result = [
            'items' => $items,
            'total' => $total,
            'pages' => $pages,
            'page'  => $page,
            'limit' => $limit,
            'sectionName' => $sectionName,
            'subsections' => $subsections,
        ];

        if ($measurePerf) {
            self::finalizePerf($perf, $perfStartedAt);
            $result['_perf'] = $perf;
        }

        if (!$skipCache) {
            $cachePayload = $result;
            unset($cachePayload['_perf']);
            self::commitTaggedDataCache(
                $cache,
                self::CACHE_DIR_GOODS,
                self::cacheTagsForGoodsListing($sectionId, $items),
                $cachePayload,
                $goodsCacheStarted
            );
        }

        return $result;
    }

    public static function normalizeGoodsLimit(mixed $limit): int
    {
        $intLimit = (int)$limit;
        if ($intLimit <= 0) {
            $intLimit = self::GOODS_PER_PAGE;
        }

        return max(1, min(self::MAX_GOODS_PER_PAGE, $intLimit));
    }

    public static function normalizeGoodsSort(?string $sort): string
    {
        $value = trim((string)$sort);
        $allowed = ['default', 'name', 'price_asc', 'price_desc'];

        return in_array($value, $allowed, true) ? $value : 'default';
    }

    /**
     * @return array{join: string, orderBy: string, groupByExtra: string}
     */
    private static function goodsSortSqlParts(string $sort): array
    {
        switch (self::normalizeGoodsSort($sort)) {
            case 'name':
                return [
                    'join' => '',
                    'orderBy' => 'e.NAME ASC, e.ID ASC',
                    'groupByExtra' => '',
                ];
            case 'price_asc':
                $groupId = self::catalogPriceGroupId();

                return [
                    'join' => "LEFT JOIN b_catalog_price cp_sort
                    ON cp_sort.PRODUCT_ID = e.ID
                   AND cp_sort.CATALOG_GROUP_ID = {$groupId}",
                    'orderBy' => 'COALESCE(cp_sort.PRICE, 0) ASC, e.ID ASC',
                    'groupByExtra' => ', cp_sort.PRICE',
                ];
            case 'price_desc':
                $groupId = self::catalogPriceGroupId();

                return [
                    'join' => "LEFT JOIN b_catalog_price cp_sort
                    ON cp_sort.PRODUCT_ID = e.ID
                   AND cp_sort.CATALOG_GROUP_ID = {$groupId}",
                    'orderBy' => 'COALESCE(cp_sort.PRICE, 0) DESC, e.ID ASC',
                    'groupByExtra' => ', cp_sort.PRICE',
                ];
            default:
                return [
                    'join' => '',
                    'orderBy' => 'e.SORT ASC, e.ID DESC',
                    'groupByExtra' => '',
                ];
        }
    }

    // резервный метод, пригодится при импорте товаров
    public static function clearCache(): void
    {
        self::clearCatalogDataCache();
    }

    public static function clearCatalogDataCache(): void
    {
        \Bitrix\Main\Data\Cache::clearCache(false, self::CACHE_DIR_GOODS);
        \Bitrix\Main\Data\Cache::clearCache(false, self::CACHE_DIR_GOODS_COUNT);
        \Bitrix\Main\Data\Cache::clearCache(false, self::CACHE_DIR_FILTERS);
        \Bitrix\Main\Data\Cache::clearCache(false, self::CACHE_DIR_PRODUCT);
    }

    public static function markFilterSnapshotsForRebuild(): void
    {
        FilterAggregateService::markAllStale();
        try {
            FilterRebuildJobService::startJob(FilterRebuildJobService::TYPE_REBUILD_STALE);
        } catch (\Throwable $e) {
            foreach (self::getActiveCatalogSectionIds() as $sectionId) {
                FilterRebuildQueueService::enqueue($sectionId);
            }
        }
    }

    /**
     * @return list<int>
     */
    public static function getActiveCatalogSectionIds(): array
    {
        Loader::includeModule('iblock');

        $db = Application::getConnection();
        $res = $db->query('
            SELECT ID
            FROM b_iblock_section
            WHERE IBLOCK_ID = ' . self::iblockId() . "
              AND ACTIVE = 'Y'
            ORDER BY ID ASC
        ");

        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['ID'];
        }

        return $ids;
    }

    public static function countActiveCatalogSections(): int
    {
        Loader::includeModule('iblock');

        return (int)Application::getConnection()->queryScalar('
            SELECT COUNT(*)
            FROM b_iblock_section
            WHERE IBLOCK_ID = ' . self::iblockId() . "
              AND ACTIVE = 'Y'
        ");
    }

    /**
     * @return list<int>
     */
    public static function getFilterPropertyIds(): array
    {
        $ids = [];
        foreach (self::filterPropertyDefinitions() as $definition) {
            $ids[] = (int)$definition['ID'];
        }

        return $ids;
    }

    /**
     * @param list<int> $extraSectionIds разделы до сохранения (перенос товара)
     * @param bool $invalidateSectionScopes сброс кеша фильтра/счётчика по цепочке разделов
     */
    public static function invalidateByElement(
        int $elementId,
        array $extraSectionIds = [],
        bool $invalidateSectionScopes = false
    ): void {
        $elementId = (int)$elementId;
        if ($elementId <= 0) {
            return;
        }

        $taggedCache = Application::getInstance()->getTaggedCache();
        $taggedCache->clearByTag(self::tagForElement($elementId));

        if (!$invalidateSectionScopes) {
            return;
        }

        $sectionIds = array_unique(array_merge(
            self::getElementSectionIds($elementId),
            array_map('intval', $extraSectionIds)
        ));

        foreach ($sectionIds as $sectionId) {
            if ($sectionId > 0) {
                self::clearSectionPathTags($sectionId);
            }
        }

        ProductFilterIndexQueueService::enqueue($elementId);
    }

    public static function invalidateBySection(int $sectionId, bool $includeDescendants = false): void
    {
        $sectionId = (int)$sectionId;
        if ($sectionId <= 0) {
            return;
        }

        self::clearSectionPathTags($sectionId);

        if (!$includeDescendants) {
            return;
        }

        $taggedCache = Application::getInstance()->getTaggedCache();
        foreach (self::getSectionDescendantIds($sectionId) as $childId) {
            $taggedCache->clearByTag(self::tagForSection($childId));
        }
    }

    /**
     * @return list<int>
     */
    public static function getElementSectionIds(int $elementId): array
    {
        $elementId = (int)$elementId;
        if ($elementId <= 0) {
            return [];
        }

        Loader::includeModule('iblock');

        $db = Application::getConnection();
        $sql = "
            SELECT se.IBLOCK_SECTION_ID
            FROM b_iblock_section_element se
            INNER JOIN b_iblock_section s ON s.ID = se.IBLOCK_SECTION_ID
            WHERE se.IBLOCK_ELEMENT_ID = {$elementId}
              AND s.IBLOCK_ID = " . self::iblockId() . "
        ";
        $res = $db->query($sql);
        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['IBLOCK_SECTION_ID'];
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @return list<string>
     */
    private static function cacheTagsForProduct(int $elementId): array
    {
        return [self::tagForElement($elementId)];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<string>
     */
    private static function cacheTagsForGoodsListing(int $sectionId, array $items): array
    {
        $tags = self::cacheTagsForSectionScope($sectionId);
        foreach ($items as $item) {
            $id = (int)($item['ID'] ?? 0);
            if ($id > 0) {
                $tags[] = self::tagForElement($id);
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @return list<string>
     */
    private static function cacheTagsForSectionScope(int $sectionId): array
    {
        $sectionId = (int)$sectionId;
        if ($sectionId > 0) {
            return [self::tagForSection($sectionId)];
        }

        return [self::tagForIblock()];
    }

    private static function tagForElement(int $elementId): string
    {
        return 'iblock_element_' . (int)$elementId;
    }

    private static function tagForSection(int $sectionId): string
    {
        return 'iblock_section_' . (int)$sectionId;
    }

    private static function tagForIblock(): string
    {
        return 'iblock_id_' . self::iblockId();
    }

    private static function clearSectionPathTags(int $sectionId): void
    {
        $taggedCache = Application::getInstance()->getTaggedCache();
        foreach (self::getSectionPath($sectionId) as $item) {
            $taggedCache->clearByTag(self::tagForSection((int)$item['ID']));
        }

        FilterAggregateService::invalidateSectionPath($sectionId);
    }

    /**
     * @return list<int>
     */
    public static function getSectionPathIds(int $sectionId): array
    {
        $ids = [];
        foreach (self::getSectionPath($sectionId) as $item) {
            $ids[] = (int)$item['ID'];
        }

        return $ids;
    }

    public static function filtersConfigHash(): string
    {
        return md5(
            self::iblockId()
            . '|' . self::catalogPriceGroupId()
            . '|' . self::catalogDisplayCurrency()
            . '|' . implode("\n", self::effectiveFilterPropertyCodes())
        );
    }

    /**
     * Коды свойств фильтра: «Свойства в фильтре» + коды SKU-tree (офферы), без дубликатов.
     *
     * @return list<string>
     */
    public static function effectiveFilterPropertyCodes(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $codes = Assets::filterPropertyCodes();
        foreach (CatalogSkuService::skuPropertyCodes() as $code) {
            $exists = false;
            foreach ($codes as $existing) {
                if (strcasecmp((string)$existing, (string)$code) === 0) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $codes[] = $code;
            }
        }

        return $cache = Assets::normalizePropertyCodes($codes);
    }

    /**
     * @return list<int>
     */
    private static function getSectionDescendantIds(int $sectionId): array
    {
        $sectionId = (int)$sectionId;
        if ($sectionId <= 0) {
            return [];
        }

        $bounds = self::getSectionBounds($sectionId);
        if (!$bounds) {
            return [];
        }

        $db = Application::getConnection();
        $left = (int)$bounds['LEFT_MARGIN'];
        $right = (int)$bounds['RIGHT_MARGIN'];
        $sql = "
            SELECT ID
            FROM b_iblock_section
            WHERE IBLOCK_ID = " . self::iblockId() . "
              AND LEFT_MARGIN > {$left}
              AND RIGHT_MARGIN < {$right}
        ";
        $res = $db->query($sql);
        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['ID'];
        }

        return $ids;
    }

    /**
     * @param list<string> $tags
     */
    private static function commitTaggedDataCache(
        \Bitrix\Main\Data\Cache $cache,
        string $dir,
        array $tags,
        mixed $data,
        bool $started
    ): void {
        if (!$started) {
            return;
        }

        $taggedCache = Application::getInstance()->getTaggedCache();
        $taggedCache->startTagCache($dir);
        foreach (array_unique($tags) as $tag) {
            $tag = trim((string)$tag);
            if ($tag !== '') {
                $taggedCache->registerTag($tag);
            }
        }
        $taggedCache->endTagCache();
        $cache->endDataCache($data);
    }

    // получаем цены набора товаров одним запросом
    private static function getPrices(array $productIds): array
    {
        if (empty($productIds)) return [];

        $res = PriceTable::getList([
            'select' => ['PRODUCT_ID', 'PRICE', 'CURRENCY'],
            'filter' => [
                '@PRODUCT_ID' => $productIds,
                '=CATALOG_GROUP_ID' => self::catalogPriceGroupId(),
            ],
        ]);

        $prices = [];
        $displayCurrency = self::catalogDisplayCurrency();
        $storageCurrency = self::catalogPriceStorageCurrency();
        while ($row = $res->fetch()) {
            $fromCurrency = trim((string)($row['CURRENCY'] ?? ''));
            if ($fromCurrency === '') {
                $fromCurrency = $storageCurrency;
            }
            $prices[(int)$row['PRODUCT_ID']] = self::convertCatalogPrice(
                (float)$row['PRICE'],
                $fromCurrency,
                $displayCurrency
            );
        }

        return $prices;
    }

    // Значения для фильтра
    /**
     * @return array{enabled: bool, properties: array, price: array{min: int, max: int}}
     */
    public static function buildFiltersPayload(int $sectionId, ?array &$perf = null): array
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');

        $scope = self::buildFiltersScopeSql($sectionId);
        if ($scope === null) {
            return self::emptyFiltersPayload();
        }

        $db = Application::getConnection();
        $scopeFrom = $scope['from'];
        $scopeWhere = $scope['where'];
        $perfForQueries = $perf;

        $definitions = self::filterPropertyDefinitions();
        $properties = [];
        if ($definitions !== []) {
            $enumIdsByProp = [];
            $stringValuesByProp = [];

            foreach ($definitions as $definition) {
                $propertyId = (int)$definition['ID'];
                $code = preg_replace('/[^A-Za-z0-9_]/', '_', (string)$definition['CODE']) ?: 'prop';

                if ($definition['valueKind'] === 'enum') {
                    if (empty($definition['offersIblock'])) {
                        $propsSql = "
                        SELECT DISTINCT p.VALUE_ENUM AS VALUE_ENUM
                        {$scopeFrom}
                        INNER JOIN b_iblock_element_property p
                            ON p.IBLOCK_ELEMENT_ID = e.ID
                           AND p.IBLOCK_PROPERTY_ID = {$propertyId}
                        WHERE {$scopeWhere}
                          AND p.VALUE_ENUM IS NOT NULL
                          AND p.VALUE_ENUM > 0
                    ";
                        $res = self::queryWithPerf($db, $propsSql, $perfForQueries, 'filters_prop_' . $code);
                        while ($row = $res->fetch()) {
                            $enumId = (int)$row['VALUE_ENUM'];
                            if ($enumId > 0) {
                                $enumIdsByProp[$propertyId][$enumId] = true;
                            }
                        }
                    }

                    $offersIblockId = CatalogSkuService::getOffersIblockId();
                    $linkPropId = CatalogSkuService::getLinkPropertyId();
                    if (!empty($definition['offersIblock']) && $offersIblockId > 0 && $linkPropId > 0) {
                        $offerSql = "
                            SELECT DISTINCT p.VALUE_ENUM AS VALUE_ENUM
                            {$scopeFrom}
                            INNER JOIN b_iblock_element_property lp
                                ON lp.IBLOCK_PROPERTY_ID = {$linkPropId}
                               AND lp.VALUE = e.ID
                            INNER JOIN b_iblock_element o
                                ON o.ID = lp.IBLOCK_ELEMENT_ID
                               AND o.IBLOCK_ID = {$offersIblockId}
                               AND o.ACTIVE = 'Y'
                            INNER JOIN b_iblock_element_property p
                                ON p.IBLOCK_ELEMENT_ID = o.ID
                               AND p.IBLOCK_PROPERTY_ID = {$propertyId}
                            WHERE {$scopeWhere}
                              AND p.VALUE_ENUM IS NOT NULL
                              AND p.VALUE_ENUM > 0
                        ";
                        $resOffer = self::queryWithPerf($db, $offerSql, $perfForQueries, 'filters_prop_offer_' . $code);
                        while ($row = $resOffer->fetch()) {
                            $enumId = (int)$row['VALUE_ENUM'];
                            if ($enumId > 0) {
                                $enumIdsByProp[$propertyId][$enumId] = true;
                            }
                        }
                    }
                    continue;
                }

                $propsSql = "
                    SELECT DISTINCT p.VALUE AS VALUE
                    {$scopeFrom}
                    INNER JOIN b_iblock_element_property p
                        ON p.IBLOCK_ELEMENT_ID = e.ID
                       AND p.IBLOCK_PROPERTY_ID = {$propertyId}
                    WHERE {$scopeWhere}
                      AND p.VALUE IS NOT NULL
                      AND p.VALUE <> ''
                ";
                $res = self::queryWithPerf($db, $propsSql, $perfForQueries, 'filters_prop_' . $code);
                while ($row = $res->fetch()) {
                    $value = trim((string)$row['VALUE']);
                    if ($value !== '') {
                        $stringValuesByProp[$propertyId][$value] = true;
                    }
                }
            }

            $enumItemsByProp = [];
            $enumIds = [];
            foreach ($enumIdsByProp as $ids) {
                foreach (array_keys($ids) as $enumId) {
                    $enumIds[$enumId] = true;
                }
            }
            if ($enumIds !== []) {
                $enumSql = "
                    SELECT ID, PROPERTY_ID, VALUE
                    FROM b_iblock_property_enum
                    WHERE ID IN (" . implode(',', array_map('intval', array_keys($enumIds))) . ")
                    ORDER BY SORT ASC, VALUE ASC
                ";
                $res = self::queryWithPerf($db, $enumSql, $perfForQueries, 'filters_enum_values');
                while ($row = $res->fetch()) {
                    $propertyId = (int)$row['PROPERTY_ID'];
                    $enumItemsByProp[$propertyId][] = [
                        'id' => (int)$row['ID'],
                        'value' => (string)$row['VALUE'],
                    ];
                }
            }

            foreach ($definitions as $definition) {
                $propertyId = (int)$definition['ID'];
                if ($definition['valueKind'] === 'enum') {
                    $values = $enumItemsByProp[$propertyId] ?? [];
                } else {
                    $list = array_keys($stringValuesByProp[$propertyId] ?? []);
                    sort($list, SORT_STRING);
                    $values = array_map(
                        static fn(string $value): array => ['value' => $value],
                        $list
                    );
                }
                if ($values === []) {
                    continue;
                }
                $properties[] = [
                    'code' => $definition['CODE'],
                    'name' => $definition['NAME'],
                    'valueKind' => $definition['valueKind'],
                    'values' => $values,
                ];
            }
        }

        $priceGroupId = self::catalogPriceGroupId();
        $offersIblockId = CatalogSkuService::getOffersIblockId();
        $linkPropId = CatalogSkuService::getLinkPropertyId();
        if ($offersIblockId > 0 && $linkPropId > 0) {
            $priceSql = "
                SELECT cp.CURRENCY, MIN(cp.PRICE) AS MIN_PRICE, MAX(cp.PRICE) AS MAX_PRICE
                FROM (
                    SELECT e.ID AS PRICE_PRODUCT_ID
                    {$scopeFrom}
                    WHERE {$scopeWhere}
                    UNION
                    SELECT o.ID AS PRICE_PRODUCT_ID
                    {$scopeFrom}
                    INNER JOIN b_iblock_element_property lp
                        ON lp.IBLOCK_PROPERTY_ID = {$linkPropId}
                       AND lp.VALUE = e.ID
                    INNER JOIN b_iblock_element o
                        ON o.ID = lp.IBLOCK_ELEMENT_ID
                       AND o.IBLOCK_ID = {$offersIblockId}
                       AND o.ACTIVE = 'Y'
                    WHERE {$scopeWhere}
                ) price_products
                INNER JOIN b_catalog_price cp
                    ON cp.PRODUCT_ID = price_products.PRICE_PRODUCT_ID
                   AND cp.CATALOG_GROUP_ID = {$priceGroupId}
                GROUP BY cp.CURRENCY
            ";
        } else {
            $priceSql = "
            SELECT cp.CURRENCY, MIN(cp.PRICE) AS MIN_PRICE, MAX(cp.PRICE) AS MAX_PRICE
            {$scopeFrom}
            INNER JOIN b_catalog_price cp
                ON cp.PRODUCT_ID = e.ID
               AND cp.CATALOG_GROUP_ID = {$priceGroupId}
            WHERE {$scopeWhere}
            GROUP BY cp.CURRENCY
        ";
        }
        $priceRes = self::queryWithPerf($db, $priceSql, $perfForQueries, 'filters_price_minmax');
        $displayCurrency = self::catalogDisplayCurrency();
        $storageCurrency = self::catalogPriceStorageCurrency();
        $minPrice = null;
        $maxPrice = null;
        while ($row = $priceRes->fetch()) {
            $fromCurrency = trim((string)($row['CURRENCY'] ?? ''));
            if ($fromCurrency === '') {
                $fromCurrency = $storageCurrency;
            }
            $convertedMin = self::convertCatalogPrice((float)($row['MIN_PRICE'] ?? 0), $fromCurrency, $displayCurrency);
            $convertedMax = self::convertCatalogPrice((float)($row['MAX_PRICE'] ?? 0), $fromCurrency, $displayCurrency);
            $minPrice = $minPrice === null ? $convertedMin : min($minPrice, $convertedMin);
            $maxPrice = $maxPrice === null ? $convertedMax : max($maxPrice, $convertedMax);
        }
        $minPrice = (int)floor($minPrice ?? 0);
        $maxPrice = (int)ceil($maxPrice ?? 100000);
        if ($maxPrice < $minPrice) {
            $maxPrice = $minPrice;
        }

        return [
            'enabled' => true,
            'properties' => $properties,
            'price' => ['min' => $minPrice, 'max' => $maxPrice],
        ];
    }

    /**
     * @return array{from: string, where: string}|null
     */
    private static function buildFiltersScopeSql(int $sectionId): ?array
    {
        $iblockId = self::iblockId();
        $sectionId = (int)$sectionId;

        if ($sectionId <= 0) {
            return [
                'from' => 'FROM b_iblock_element e',
                'where' => "e.IBLOCK_ID = {$iblockId} AND e.ACTIVE = 'Y'",
            ];
        }

        $bounds = self::getSectionBounds($sectionId);
        if (!$bounds) {
            return null;
        }

        $left = (int)$bounds['LEFT_MARGIN'];
        $right = (int)$bounds['RIGHT_MARGIN'];

        return [
            'from' => "FROM b_iblock_section_element se
                INNER JOIN b_iblock_section s_scope
                    ON s_scope.ID = se.IBLOCK_SECTION_ID
                INNER JOIN b_iblock_element e
                    ON e.ID = se.IBLOCK_ELEMENT_ID",
            'where' => "s_scope.IBLOCK_ID = {$iblockId}
                AND s_scope.LEFT_MARGIN >= {$left}
                AND s_scope.RIGHT_MARGIN <= {$right}
                AND e.IBLOCK_ID = {$iblockId}
                AND e.ACTIVE = 'Y'",
        ];
    }

    public static function getFilters(int $sectionId = 0): array
    {
        $measurePerf = self::shouldMeasurePerf();
        $skipCache = self::shouldSkipCache();
        $perfStartedAt = microtime(true);
        $perf = self::createPerfBag();

        if (!Assets::showCatalogFilter()) {
            $result = [
                'enabled' => false,
                'properties' => [],
                'price' => ['min' => 0.0, 'max' => 0.0],
            ];
            if ($measurePerf) {
                self::finalizePerf($perf, $perfStartedAt);
                $result['_perf'] = $perf;
            }
            return $result;
        }

        $configHash = self::filtersConfigHash();
        $cacheKey = 'section_' . max(0, $sectionId)
            . '_g' . self::catalogPriceGroupId()
            . '_c' . self::catalogDisplayCurrency()
            . '_h' . $configHash;
        $cache = \Bitrix\Main\Data\Cache::createInstance();
        if (!$skipCache && $cache->initCache(Assets::catalogCacheTtl(), $cacheKey, self::CACHE_DIR_FILTERS)) {
            $result = $cache->getVars();
            if ($measurePerf) {
                $perf['cache_hit'] = true;
                self::finalizePerf($perf, $perfStartedAt);
                $result['_perf'] = $perf;
            }
            return $result;
        }

        $aggregate = FilterAggregateService::read($sectionId);

        if ($aggregate !== null) {
            $payload = $aggregate['payload'];
            $hashMatches = $aggregate['config_hash'] === $configHash;

            if ($hashMatches && !$aggregate['is_stale']) {
                $result = $payload;
                if ($measurePerf) {
                    $perf['aggregate_hit'] = true;
                    self::finalizePerf($perf, $perfStartedAt);
                    $result['_perf'] = $perf;
                }
                if (!$skipCache) {
                    $filtersCacheStarted = $cache->startDataCache();
                    self::commitTaggedDataCache(
                        $cache,
                        self::CACHE_DIR_FILTERS,
                        self::cacheTagsForSectionScope($sectionId),
                        $result,
                        $filtersCacheStarted
                    );
                }

                return $result;
            }

            if (!$hashMatches) {
                FilterAggregateService::markStale($sectionId);
            }

            FilterAggregateService::scheduleRebuild($sectionId);

            if ($measurePerf) {
                $perf['aggregate_stale'] = true;
                self::finalizePerf($perf, $perfStartedAt);
                $payload['_perf'] = $perf;
            }

            return $payload;
        }

        $filtersCacheStarted = !$skipCache && $cache->startDataCache();
        $filtersCacheTags = self::cacheTagsForSectionScope($sectionId);

        $result = self::buildFiltersPayload($sectionId, $perf);
        FilterAggregateService::write($sectionId, $result, $configHash, false, null);

        if ($measurePerf) {
            self::finalizePerf($perf, $perfStartedAt);
            $result['_perf'] = $perf;
        }

        if (!$skipCache) {
            $cachePayload = $result;
            unset($cachePayload['_perf']);
            self::commitTaggedDataCache($cache, self::CACHE_DIR_FILTERS, $filtersCacheTags, $cachePayload, $filtersCacheStarted);
        }

        return $result;
    }

    public static function getBreadcrumbs(string $pageType, int $sectionId = 0, int $productId = 0): array
    {
        if ($pageType === 'cart') {
            return [
                ['title' => 'Главная', 'url' => '/'],
                ['title' => 'Корзина', 'url' => null],
            ];
        }

        if ($pageType === 'checkout') {
            return [
                ['title' => 'Главная', 'url' => '/'],
                ['title' => 'Корзина', 'url' => '/cart/'],
                ['title' => 'Оформление заказа', 'url' => null],
            ];
        }

        if ($pageType === 'order_success') {
            return [
                ['title' => 'Главная', 'url' => '/'],
                ['title' => 'Корзина', 'url' => '/cart/'],
                ['title' => 'Заказ оформлен', 'url' => null],
            ];
        }

        $breadcrumbs = [
            ['title' => 'Главная', 'url' => '/'],
            ['title' => 'Каталог', 'url' => '/catalog/'],
        ];

        if ($pageType === 'section' && $sectionId > 0) {
            $sectionPath = self::getSectionPath($sectionId);
            $lastIndex = count($sectionPath) - 1;
            foreach ($sectionPath as $index => $section) {
                $breadcrumbs[] = [
                    'title' => $section['NAME'],
                    'url' => $index === $lastIndex ? null : '/catalog/section/' . (int)$section['ID'],
                ];
            }
        }

        if ($pageType === 'product' && $productId > 0) {
            $product = self::getProduct($productId);
            if ($product) {
                if (!empty($product['SECTION_ID'])) {
                    $sectionPath = self::getSectionPath((int)$product['SECTION_ID']);
                    foreach ($sectionPath as $section) {
                        $breadcrumbs[] = [
                            'title' => $section['NAME'],
                            'url' => '/catalog/section/' . (int)$section['ID'],
                        ];
                    }
                }
                $breadcrumbs[] = ['title' => $product['NAME'], 'url' => null];
            }
        }

        if ($pageType === 'catalog') {
            $breadcrumbs[1]['url'] = null;
        }

        return $breadcrumbs;
    }


    // Вспомогательные методы

    /**
     * @return list<array{code: string, name: string, type: string, multiple: bool, value?: string, values?: list<string>}>
     */
    private static function fetchProductCardProperties(int $elementId): array
    {
        $definitions = self::productCardPropertyDefinitions();
        if ($elementId <= 0 || $definitions === []) {
            return [];
        }

        $defsById = [];
        foreach ($definitions as $definition) {
            $defsById[(int)$definition['ID']] = $definition;
        }
        $propIdsSql = implode(',', array_map('intval', array_keys($defsById)));

        $db = Application::getConnection();
        $sql = "
            SELECT
                p.IBLOCK_PROPERTY_ID,
                p.VALUE,
                p.VALUE_ENUM,
                COALESCE(pe.VALUE, p.VALUE) AS ENUM_DISPLAY
            FROM b_iblock_element_property p
            LEFT JOIN b_iblock_property_enum pe
                ON pe.ID = p.VALUE_ENUM
            WHERE p.IBLOCK_ELEMENT_ID = {$elementId}
              AND p.IBLOCK_PROPERTY_ID IN ({$propIdsSql})
        ";
        $res = $db->query($sql);

        $rawByPropId = [];
        while ($row = $res->fetch()) {
            $propId = (int)$row['IBLOCK_PROPERTY_ID'];
            if (!isset($defsById[$propId])) {
                continue;
            }
            $rawByPropId[$propId][] = $row;
        }

        $elementIds = [];
        $sectionIds = [];
        $fileIds = [];
        foreach ($rawByPropId as $propId => $rows) {
            $type = (string)$defsById[$propId]['type'];
            foreach ($rows as $row) {
                $value = trim((string)($row['VALUE'] ?? ''));
                if ($type === 'L') {
                    continue;
                }
                if ($type === 'F') {
                    $fileId = (int)$value;
                    if ($fileId > 0) {
                        $fileIds[$fileId] = $fileId;
                    }
                    continue;
                }
                if ($type === 'E') {
                    $linkedId = (int)$value;
                    if ($linkedId > 0) {
                        $elementIds[$linkedId] = $linkedId;
                    }
                    continue;
                }
                if ($type === 'G') {
                    $linkedId = (int)$value;
                    if ($linkedId > 0) {
                        $sectionIds[$linkedId] = $linkedId;
                    }
                }
            }
        }

        $elementNames = self::resolveElementNames(array_values($elementIds));
        $sectionNames = self::resolveSectionNames(array_values($sectionIds));
        $filePaths = self::getFilePaths(array_values($fileIds));

        $result = [];
        foreach ($definitions as $definition) {
            $propId = (int)$definition['ID'];
            $rows = $rawByPropId[$propId] ?? [];
            if ($rows === []) {
                continue;
            }

            $displayValues = self::formatCardPropertyDisplayValues(
                $definition,
                $rows,
                $elementNames,
                $sectionNames,
                $filePaths
            );
            if ($displayValues === []) {
                continue;
            }

            $item = [
                'code' => $definition['CODE'],
                'name' => $definition['NAME'],
                'type' => $definition['type'],
                'multiple' => (bool)$definition['multiple'],
            ];
            if ($item['multiple']) {
                $item['values'] = $displayValues;
            } else {
                $item['value'] = $displayValues[0];
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * @param array{ID: int, CODE: string, NAME: string, type: string, multiple: bool} $definition
     * @param list<array<string, mixed>> $rows
     * @param array<int, string> $elementNames
     * @param array<int, string> $sectionNames
     * @param array<int, string> $filePaths
     * @return list<string>
     */
    private static function formatCardPropertyDisplayValues(
        array $definition,
        array $rows,
        array $elementNames,
        array $sectionNames,
        array $filePaths
    ): array {
        $type = (string)$definition['type'];
        $values = [];

        foreach ($rows as $row) {
            $stringValue = trim((string)($row['VALUE'] ?? ''));
            $display = '';

            switch ($type) {
                case 'L':
                    $display = trim((string)($row['ENUM_DISPLAY'] ?? ''));
                    if ($display === '' && $stringValue !== '') {
                        $display = $stringValue;
                    }
                    break;
                case 'N':
                    if ($stringValue !== '' && is_numeric($stringValue)) {
                        $display = rtrim(rtrim(number_format((float)$stringValue, 6, '.', ''), '0'), '.');
                    }
                    break;
                case 'F':
                    $fileId = (int)$stringValue;
                    $display = $fileId > 0 ? ($filePaths[$fileId] ?? '') : '';
                    break;
                case 'E':
                    $linkedId = (int)$stringValue;
                    $display = $linkedId > 0 ? ($elementNames[$linkedId] ?? '') : '';
                    break;
                case 'G':
                    $linkedId = (int)$stringValue;
                    $display = $linkedId > 0 ? ($sectionNames[$linkedId] ?? '') : '';
                    break;
                default:
                    if (strcasecmp($type, 'HTML') === 0 || strcasecmp($type, 'S:HTML') === 0) {
                        $display = trim(strip_tags($stringValue));
                    } else {
                        $display = $stringValue;
                    }
                    break;
            }

            $display = trim($display);
            if ($display === '') {
                continue;
            }
            if (!in_array($display, $values, true)) {
                $values[] = $display;
            }
        }

        return $values;
    }

    /**
     * @param list<array{code: string, name: string, type: string, multiple: bool, value?: string, values?: list<string>}> $properties
     */
    private static function extractPropertyValueByCode(array $properties, string $code): string
    {
        $needle = strtoupper(trim($code));
        if ($needle === '') {
            return '';
        }

        foreach ($properties as $property) {
            if (strcasecmp((string)($property['code'] ?? ''), $needle) !== 0) {
                continue;
            }
            if (!empty($property['multiple']) && is_array($property['values'] ?? null)) {
                return (string)($property['values'][0] ?? '');
            }

            return trim((string)($property['value'] ?? ''));
        }

        return '';
    }

    /**
     * @return list<array{ID: int, CODE: string, NAME: string, type: string, multiple: bool}>
     */
    private static function productCardPropertyDefinitions(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $codes = Assets::cardPropertyCodes();
        if ($codes === []) {
            return $cache = [];
        }

        $db = Application::getConnection();
        $sqlHelper = $db->getSqlHelper();
        $inSql = implode(',', array_map(
            static fn(string $code): string => "'" . $sqlHelper->forSql($code) . "'",
            $codes
        ));
        $res = $db->query("
            SELECT ID, CODE, NAME, PROPERTY_TYPE, MULTIPLE
            FROM b_iblock_property
            WHERE IBLOCK_ID = " . self::iblockId() . "
              AND CODE IN ({$inSql})
        ");
        $byCode = [];
        while ($row = $res->fetch()) {
            $code = (string)$row['CODE'];
            $byCode[strtoupper($code)] = [
                'ID' => (int)$row['ID'],
                'CODE' => $code,
                'NAME' => (string)$row['NAME'],
                'type' => (string)$row['PROPERTY_TYPE'],
                'multiple' => ((string)($row['MULTIPLE'] ?? 'N') === 'Y'),
            ];
        }

        $ordered = [];
        foreach ($codes as $code) {
            $key = strtoupper($code);
            if (isset($byCode[$key])) {
                $ordered[] = $byCode[$key];
            }
        }

        return $cache = $ordered;
    }

    /**
     * @param list<int> $elementIds
     * @return array<int, string>
     */
    private static function resolveElementNames(array $elementIds): array
    {
        $elementIds = array_values(array_filter(array_map('intval', $elementIds)));
        if ($elementIds === []) {
            return [];
        }

        $idsSql = implode(',', $elementIds);
        $res = Application::getConnection()->query("
            SELECT ID, NAME
            FROM b_iblock_element
            WHERE ID IN ({$idsSql})
              AND IBLOCK_ID = " . self::iblockId() . "
        ");
        $map = [];
        while ($row = $res->fetch()) {
            $map[(int)$row['ID']] = (string)$row['NAME'];
        }

        return $map;
    }

    /**
     * @param list<int> $sectionIds
     * @return array<int, string>
     */
    private static function resolveSectionNames(array $sectionIds): array
    {
        $sectionIds = array_values(array_filter(array_map('intval', $sectionIds)));
        if ($sectionIds === []) {
            return [];
        }

        $idsSql = implode(',', $sectionIds);
        $res = Application::getConnection()->query("
            SELECT ID, NAME
            FROM b_iblock_section
            WHERE ID IN ({$idsSql})
              AND IBLOCK_ID = " . self::iblockId() . "
        ");
        $map = [];
        while ($row = $res->fetch()) {
            $map[(int)$row['ID']] = (string)$row['NAME'];
        }

        return $map;
    }

    private static function getPrice(int $productId): float
    {
        $row = PriceTable::getList([
            'select' => ['PRICE', 'CURRENCY'],
            'filter' => [
                '=PRODUCT_ID' => $productId,
                '=CATALOG_GROUP_ID' => self::catalogPriceGroupId(),
            ],
        ])->fetch();

        if (!$row) {
            return 0.0;
        }

        $fromCurrency = trim((string)($row['CURRENCY'] ?? ''));
        if ($fromCurrency === '') {
            $fromCurrency = self::catalogPriceStorageCurrency();
        }

        return self::convertCatalogPrice((float)$row['PRICE'], $fromCurrency, self::catalogDisplayCurrency());
    }

    private static function getFilePath(?int $fileId): string
    {
        if (!$fileId) return '';

        $file = FileTable::getList([
            'select' => ['SUBDIR', 'FILE_NAME'],
            'filter' => ['=ID' => $fileId],
        ])->fetch();

        return $file ? '/upload/' . $file['SUBDIR'] . '/' . $file['FILE_NAME'] : '';
    }

    private static function getFilePaths(array $fileIds): array
    {
        if (empty($fileIds)) return [];

        $res = FileTable::getList([
            'select' => ['ID', 'SUBDIR', 'FILE_NAME'],
            'filter' => ['@ID' => $fileIds],
        ]);

        $map = [];
        while ($file = $res->fetch()) {
            $map[(int)$file['ID']] = '/upload/' . $file['SUBDIR'] . '/' . $file['FILE_NAME'];
        }

        return $map;
    }

    private static function normalizeIntFilterList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($items as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $intValue = (int)$item;
            if ($intValue > 0) {
                $result[] = $intValue;
            }
        }

        return array_values(array_unique($result));
    }

    private static function normalizeStringFilterList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($items as $item) {
            if ($item === null) {
                continue;
            }
            $strValue = trim((string)$item);
            if ($strValue !== '') {
                $result[] = $strValue;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @return array{enabled: bool, properties: array, price: array{min: float, max: float}}
     */
    private static function emptyFiltersPayload(): array
    {
        return [
            'enabled' => true,
            'properties' => [],
            'price' => ['min' => 0.0, 'max' => 0.0],
        ];
    }

    private static function catalogPriceGroupId(): int
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $code = Assets::catalogPriceTypeCode();
        $db = Application::getConnection();
        $safeCode = $db->getSqlHelper()->forSql($code);
        $row = $db->query("
            SELECT ID
            FROM b_catalog_group
            WHERE NAME = '{$safeCode}'
            LIMIT 1
        ")->fetch();
        $id = (int)($row['ID'] ?? 0);
        $resolved = $id > 0 ? $id : self::FALLBACK_PRICE_GROUP_ID;

        return $resolved;
    }

    public static function catalogDisplayCurrency(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        Loader::includeModule('currency');
        $currency = '';

        if (Loader::includeModule('sale')) {
            $sessionCurrency = trim((string)($_SESSION['SALE_USER_CURRENCY'] ?? ''));
            if (strlen($sessionCurrency) === 3) {
                $currency = strtoupper($sessionCurrency);
            }

            if ($currency === '') {
                $siteId = (string)Context::getCurrent()->getSite();
                if ($siteId !== '' && class_exists(\Bitrix\Sale\Internals\SiteCurrencyTable::class)) {
                    $siteCurrency = (string)\Bitrix\Sale\Internals\SiteCurrencyTable::getSiteCurrency($siteId);
                    if (strlen($siteCurrency) === 3) {
                        $currency = strtoupper($siteCurrency);
                    }
                }
            }
        }

        if ($currency === '') {
            $currency = strtoupper((string)\Bitrix\Currency\CurrencyManager::getBaseCurrency());
        }

        $resolved = $currency !== '' ? $currency : 'RUB';

        return $resolved;
    }

    private static function catalogPriceStorageCurrency(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        Loader::includeModule('catalog');
        $groupId = self::catalogPriceGroupId();
        $row = Application::getConnection()->query("
            SELECT CURRENCY
            FROM b_catalog_price
            WHERE CATALOG_GROUP_ID = {$groupId}
              AND CURRENCY IS NOT NULL
              AND CURRENCY != ''
            LIMIT 1
        ")->fetch();

        $currency = strtoupper(trim((string)($row['CURRENCY'] ?? '')));
        if ($currency === '') {
            Loader::includeModule('currency');
            $currency = strtoupper((string)\Bitrix\Currency\CurrencyManager::getBaseCurrency());
        }

        $resolved = $currency !== '' ? $currency : 'RUB';

        return $resolved;
    }

    private static function convertCatalogPrice(float $price, string $fromCurrency, string $toCurrency): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        $fromCurrency = strtoupper(trim($fromCurrency));
        $toCurrency = strtoupper(trim($toCurrency));
        if ($fromCurrency === '' || $toCurrency === '' || $fromCurrency === $toCurrency) {
            return round($price, 2);
        }

        Loader::includeModule('currency');
        $converted = (float)\CCurrencyRates::ConvertCurrency($price, $fromCurrency, $toCurrency);
        if ($converted <= 0) {
            return round($price, 2);
        }

        return round($converted, 2);
    }

    /**
     * @return list<array{ID: int, CODE: string, NAME: string, valueKind: string}>
     */
    private static function filterPropertyDefinitions(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $codes = self::effectiveFilterPropertyCodes();
        if ($codes === []) {
            return $cache = [];
        }

        $db = Application::getConnection();
        $sqlHelper = $db->getSqlHelper();
        $inSql = implode(',', array_map(
            static fn(string $code): string => "'" . $sqlHelper->forSql($code) . "'",
            $codes
        ));
        $res = $db->query("
            SELECT ID, CODE, NAME, PROPERTY_TYPE
            FROM b_iblock_property
            WHERE IBLOCK_ID = " . self::iblockId() . "
              AND CODE IN ({$inSql})
        ");
        $byCode = [];
        while ($row = $res->fetch()) {
            $code = (string)$row['CODE'];
            $type = (string)$row['PROPERTY_TYPE'];
            $valueKind = match ($type) {
                'L' => 'enum',
                'N' => 'number',
                default => 'string',
            };
            $byCode[strtoupper($code)] = [
                'ID' => (int)$row['ID'],
                'CODE' => $code,
                'NAME' => (string)$row['NAME'],
                'valueKind' => $valueKind,
            ];
        }

        $skuCodeKeys = array_fill_keys(
            array_map(
                static fn(string $code): string => strtoupper($code),
                CatalogSkuService::skuPropertyCodes()
            ),
            true
        );

        $ordered = [];
        foreach ($codes as $code) {
            $key = strtoupper($code);
            if (isset($byCode[$key])) {
                $ordered[] = $byCode[$key];
            }
        }

        $offersIblockId = CatalogSkuService::getOffersIblockId();
        if ($offersIblockId > 0) {
            $offerLookupCodes = [];
            foreach ($codes as $code) {
                $key = strtoupper($code);
                if (!isset($byCode[$key]) || isset($skuCodeKeys[$key])) {
                    $offerLookupCodes[] = $code;
                }
            }
            $offerLookupCodes = array_values(array_unique($offerLookupCodes));
            if ($offerLookupCodes !== []) {
                $inOffers = implode(',', array_map(
                    static fn(string $code): string => "'" . $sqlHelper->forSql($code) . "'",
                    $offerLookupCodes
                ));
                $resOffers = $db->query("
                    SELECT ID, CODE, NAME, PROPERTY_TYPE
                    FROM b_iblock_property
                    WHERE IBLOCK_ID = {$offersIblockId}
                      AND CODE IN ({$inOffers})
                ");
                while ($row = $resOffers->fetch()) {
                    $code = (string)$row['CODE'];
                    $key = strtoupper($code);
                    $type = (string)$row['PROPERTY_TYPE'];
                    $byCode[$key] = [
                        'ID' => (int)$row['ID'],
                        'CODE' => $code,
                        'NAME' => (string)$row['NAME'],
                        'valueKind' => match ($type) {
                            'L' => 'enum',
                            'N' => 'number',
                            default => 'string',
                        },
                        'offersIblock' => true,
                    ];
                }
            }

            $ordered = [];
            foreach ($codes as $code) {
                $key = strtoupper($code);
                if (isset($byCode[$key])) {
                    $ordered[] = $byCode[$key];
                }
            }
        }

        return $cache = $ordered;
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<array{def: array, values: array}>
     */
    private static function normalizeSelectedProperties(array $raw): array
    {
        $selected = [];
        foreach (self::filterPropertyDefinitions() as $definition) {
            $values = null;
            foreach ($raw as $code => $list) {
                if (strcasecmp((string)$code, $definition['CODE']) === 0) {
                    $values = $list;
                    break;
                }
            }
            if (!is_array($values) || $values === []) {
                continue;
            }
            if ($definition['valueKind'] === 'enum') {
                $normalized = self::normalizeIntFilterList($values);
            } else {
                $normalized = self::normalizeStringFilterList($values);
            }
            if ($normalized === []) {
                continue;
            }
            $selected[] = [
                'def' => $definition,
                'values' => $normalized,
            ];
        }

        return $selected;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function parseRequestProperties(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $code => $values) {
            $code = trim((string)$code);
            if ($code === '' || !preg_match('/^[A-Za-z0-9_]+$/', $code)) {
                continue;
            }
            $items = is_array($values) ? $values : [$values];
            $clean = [];
            foreach ($items as $item) {
                if (is_array($item)) {
                    foreach ($item as $nested) {
                        $str = trim((string)$nested);
                        if ($str !== '') {
                            $clean[] = $str;
                        }
                    }
                    continue;
                }
                $str = trim((string)$item);
                if ($str !== '') {
                    $clean[] = $str;
                }
            }
            if ($clean !== []) {
                $result[$code] = array_values(array_unique($clean));
            }
        }

        return $result;
    }

    private static function getPropertyIdByCode(string $code): int
    {
        static $cache = [];
        if (array_key_exists($code, $cache)) {
            return $cache[$code];
        }

        $db = Application::getConnection();
        $safeCode = $db->getSqlHelper()->forSql($code);
        $sql = "
            SELECT ID
            FROM b_iblock_property
            WHERE IBLOCK_ID = " . self::iblockId() . "
              AND CODE = '{$safeCode}'
            LIMIT 1
        ";
        $row = $db->query($sql)->fetch();
        $cache[$code] = (int)($row['ID'] ?? 0);

        return $cache[$code];
    }

    private static function getTotalCountCached(
        \Bitrix\Main\DB\Connection $db,
        string $fromSql,
        string $where,
        bool $needsDistinct,
        string $countCacheKey,
        bool $skipCache,
        int $sectionId,
        ?array &$perf = null
    ): array {
        $countCache = \Bitrix\Main\Data\Cache::createInstance();
        if (!$skipCache && $countCache->initCache(Assets::catalogCacheTtl(), $countCacheKey, self::CACHE_DIR_GOODS_COUNT)) {
            $cached = $countCache->getVars();
            if ($perf !== null) {
                self::perfAddQuery($perf, 'goods_count_cache_hit', 0.0);
            }
            return [
                'total' => (int)($cached['total'] ?? 0),
                'cache_hit' => true,
            ];
        }

        // Подзапрос + COUNT(*) часто дешевле COUNT(DISTINCT) по широкому JOIN.
        if ($needsDistinct) {
            $countSql = "
                SELECT COUNT(*) as CNT
                FROM (
                    SELECT e.ID
                    {$fromSql}
                    WHERE {$where}
                    GROUP BY e.ID
                ) counted
            ";
        } else {
            $countSql = "
                SELECT COUNT(1) as CNT
                {$fromSql}
                WHERE {$where}
            ";
        }
        $row = self::queryWithPerf($db, $countSql, $perf, 'goods_count')->fetch();
        $total = (int)($row['CNT'] ?? 0);

        if (!$skipCache) {
            $countCacheStarted = $countCache->startDataCache();
            self::commitTaggedDataCache(
                $countCache,
                self::CACHE_DIR_GOODS_COUNT,
                self::cacheTagsForSectionScope($sectionId),
                ['total' => $total],
                $countCacheStarted
            );
        }

        return [
            'total' => $total,
            'cache_hit' => false,
        ];
    }

    private static function createPerfBag(): array
    {
        return [
            'cache_hit' => false,
            'elapsed_ms' => null,
            'sql_count' => 0,
            'sql_time_ms' => 0.0,
            'php_overhead_ms' => null,
            'stages_ms' => [],
            'count_cache_hit' => false,
            'queries' => [],
            'slowest' => null,
            'meta' => [],
        ];
    }

    private static function queryWithPerf(
        \Bitrix\Main\DB\Connection $db,
        string $sql,
        ?array &$perf,
        string $label
    ) {
        if ($perf === null) {
            return $db->query($sql);
        }

        $startedAt = microtime(true);
        $res = $db->query($sql);
        $ms = (microtime(true) - $startedAt) * 1000;
        self::perfAddQuery($perf, $label, $ms, $sql);

        return $res;
    }

    private static function perfAddQuery(
        array &$perf,
        string $label,
        float $ms,
        ?string $sql = null,
        ?int $rows = null
    ): void {
        $entry = [
            'label' => $label,
            'ms' => round($ms, 2),
        ];
        if ($rows !== null) {
            $entry['rows'] = $rows;
        }
        if ($sql !== null && $sql !== '') {
            $entry['sql'] = self::normalizeSqlForPerf($sql);
        }
        $perf['queries'][] = $entry;
    }

    private static function normalizeSqlForPerf(string $sql): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        if (strlen($normalized) > 4000) {
            return substr($normalized, 0, 4000) . '…';
        }

        return $normalized;
    }

    private static function finalizePerf(array &$perf, float $startedAt): void
    {
        $perf['elapsed_ms'] = round((microtime(true) - $startedAt) * 1000, 2);
        $perf['sql_count'] = count($perf['queries']);

        $sqlTime = 0.0;
        $slowest = null;
        foreach ($perf['queries'] as $query) {
            $ms = (float)($query['ms'] ?? 0);
            $sqlTime += $ms;
            if ($slowest === null || $ms > (float)$slowest['ms']) {
                $slowest = [
                    'label' => $query['label'] ?? '',
                    'ms' => $ms,
                ];
            }
        }

        $perf['sql_time_ms'] = round($sqlTime, 2);
        $perf['php_overhead_ms'] = round(max(0, (float)$perf['elapsed_ms'] - $sqlTime), 2);
        $perf['slowest'] = $slowest;

        usort($perf['queries'], static function (array $a, array $b): int {
            return ((float)($b['ms'] ?? 0) <=> (float)($a['ms'] ?? 0));
        });
    }

    /**
     * @return list<array{ID: int, CODE: string, NAME: string, valueKind: string}>
     */
    public static function getFilterPropertyDefinitions(): array
    {
        return self::filterPropertyDefinitions();
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<array{def: array, values: array}>
     */
    public static function normalizeSelectedFilterProperties(array $raw): array
    {
        return self::normalizeSelectedProperties($raw);
    }

    /**
     * @return list<int>
     */
    public static function getSectionIdsInSubtree(int $sectionId): array
    {
        if ($sectionId <= 0) {
            return [];
        }
        $bounds = self::getSectionBounds($sectionId);
        if ($bounds === null) {
            return [];
        }

        return self::getSectionIdsByBounds((int)$bounds['LEFT_MARGIN'], (int)$bounds['RIGHT_MARGIN']);
    }

    public static function getArticlePropertyId(): int
    {
        return self::getPropertyIdByCode(Assets::catalogArticlePropertyCode());
    }

    public static function catalogPriceGroupIdPublic(): int
    {
        return self::catalogPriceGroupId();
    }

    public static function catalogDisplayCurrencyPublic(): string
    {
        return self::catalogDisplayCurrency();
    }

    public static function catalogPriceStorageCurrencyPublic(): string
    {
        return self::catalogPriceStorageCurrency();
    }

    public static function convertCatalogPricePublic(float $price, string $fromCurrency, string $toCurrency): float
    {
        return self::convertCatalogPrice($price, $fromCurrency, $toCurrency);
    }

    public static function perfAddQueryPublic(
        array &$perf,
        string $label,
        float $ms,
        ?string $sql = null,
        ?int $rows = null
    ): void {
        self::perfAddQuery($perf, $label, $ms, $sql, $rows);
    }

    public static function goodsCountCacheDirPublic(): string
    {
        return self::CACHE_DIR_GOODS_COUNT;
    }

    /**
     * @return list<string>
     */
    public static function cacheTagsForSectionScopePublic(int $sectionId): array
    {
        return self::cacheTagsForSectionScope($sectionId);
    }

    /**
     * @param list<string> $tags
     */
    public static function commitTaggedDataCachePublic(
        \Bitrix\Main\Data\Cache $cache,
        string $dir,
        array $tags,
        mixed $data,
        bool $started
    ): void {
        self::commitTaggedDataCache($cache, $dir, $tags, $data, $started);
    }

    /**
     * Нормализованный фильтр для index count SQL и кеша total (без page, limit, sort).
     *
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public static function normalizeIndexFilterForCount(array $filter): array
    {
        $normalized = [
            'article' => trim((string)($filter['article'] ?? '')),
            'price_from' => null,
            'price_to' => null,
            'properties' => [],
        ];

        if (Assets::showCatalogFilter()) {
            $hasPriceFrom = isset($filter['price_from']) && $filter['price_from'] !== '' && $filter['price_from'] !== null;
            $hasPriceTo = isset($filter['price_to']) && $filter['price_to'] !== '' && $filter['price_to'] !== null;
            if ($hasPriceFrom) {
                $normalized['price_from'] = (float)$filter['price_from'];
            }
            if ($hasPriceTo) {
                $normalized['price_to'] = (float)$filter['price_to'];
            }

            $rawProperties = is_array($filter['properties'] ?? null) ? $filter['properties'] : [];
            $selected = self::normalizeSelectedFilterProperties($rawProperties);
            $props = [];
            foreach ($selected as $selectedProp) {
                $code = (string)($selectedProp['def']['CODE'] ?? '');
                if ($code === '') {
                    continue;
                }
                $props[strtoupper($code)] = $selectedProp['values'];
            }
            ksort($props);
            $normalized['properties'] = $props;
        }

        return $normalized;
    }

    /**
     * @param list<int> $sectionIds
     * @param array<string, mixed> $normalizedFilter результат normalizeIndexFilterForCount
     */
    public static function buildIndexGoodsTotalCacheKey(
        int $sectionId,
        array $sectionIds,
        array $normalizedFilter
    ): string {
        $sectionKey = $sectionId > 0
            ? implode(',', array_map('intval', $sectionIds))
            : '0';

        return md5(
            'index|'
            . $sectionId . '|'
            . $sectionKey . '|'
            . serialize($normalizedFilter) . '|'
            . Assets::catalogPriceTypeCode() . '|'
            . self::catalogDisplayCurrencyPublic() . '|'
            . implode(',', Assets::filterPropertyCodes()) . '|'
            . (Assets::showCatalogFilter() ? '1' : '0') . '|'
            . 'ix' . ProductFilterIndexService::getIndexVersion() . '|'
            . ProductFilterIndexService::indexConfigHash()
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function loadGoodsDetailsByIds(array $productIds, ?array &$perf = null): array
    {
        if ($productIds === []) {
            return [];
        }

        $db = Application::getConnection();
        $idsList = implode(',', array_map('intval', $productIds));
        $detailsSql = "
            SELECT e.ID, e.NAME, e.CODE, e.PREVIEW_TEXT, e.DETAIL_PICTURE
            FROM b_iblock_element e
            WHERE e.ID IN ({$idsList})
        ";
        $detailsRes = self::queryWithPerf($db, $detailsSql, $perf, 'goods_list_details');
        $byId = [];
        while ($row = $detailsRes->fetch()) {
            $byId[(int)$row['ID']] = $row;
        }

        $items = [];
        $itemIds = [];
        $pictureIds = [];
        foreach ($productIds as $pageId) {
            if (!isset($byId[$pageId])) {
                continue;
            }
            $row = $byId[$pageId];
            $pictureId = (int)$row['DETAIL_PICTURE'];
            $itemIds[] = $pageId;
            if ($pictureId > 0) {
                $pictureIds[$pictureId] = $pictureId;
            }
            $items[] = $row;
        }

        $pictureMap = self::getFilePaths(array_values($pictureIds));
        foreach ($items as &$item) {
            $detailPictureId = (int)$item['DETAIL_PICTURE'];
            $item['DETAIL_PICTURE'] = $detailPictureId > 0
                ? ($pictureMap[$detailPictureId] ?? Assets::NO_PHOTO_IMAGE)
                : Assets::NO_PHOTO_IMAGE;
        }
        unset($item);

        $prices = self::getPrices($itemIds);
        self::applySkuPricingToListItems($items, $prices);

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<int, float> $parentPrices
     */
    private static function applySkuPricingToListItems(array &$items, array $parentPrices): void
    {
        $itemIds = [];
        foreach ($items as $item) {
            $itemIds[] = (int)$item['ID'];
        }
        $hasOffersMap = self::resolveHasOffersMap($itemIds);

        foreach ($items as &$item) {
            $id = (int)$item['ID'];
            $hasOffers = !empty($hasOffersMap[$id]);
            $item['hasOffers'] = $hasOffers;
            if ($hasOffers) {
                $range = CatalogSkuService::getOfferPriceRange($id);
                $minPrice = $range !== null ? (float)$range['min'] : 0.0;
                $maxPrice = $range !== null ? (float)$range['max'] : $minPrice;
                $item['PRICE_MIN'] = $minPrice;
                $item['PRICE_MAX'] = $maxPrice;
                $item['PRICE'] = $minPrice;
            } else {
                $item['PRICE'] = $parentPrices[$id] ?? 0.0;
                $item['PRICE_MIN'] = $item['PRICE'];
                $item['PRICE_MAX'] = $item['PRICE'];
            }
            $item['CURRENCY'] = self::catalogDisplayCurrency();
        }
        unset($item);
    }

    /**
     * @param list<int> $productIds
     * @return array<int, bool>
     */
    private static function resolveHasOffersMap(array $productIds): array
    {
        $map = [];
        foreach ($productIds as $productId) {
            $productId = (int)$productId;
            if ($productId <= 0) {
                continue;
            }
            $map[$productId] = CatalogSkuService::isSkuProduct($productId);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $filter
     */
    private static function getGoodsViaProductIndex(
        array $filter,
        int $page,
        int $limit,
        int $sectionId,
        string $sectionName,
        array $subsections,
        bool $skipCache,
        \Bitrix\Main\Data\Cache $cache,
        bool $goodsCacheStarted,
        bool $measurePerf,
        array &$perf,
        float $perfStartedAt
    ): array {
        $perf['index_path'] = true;
        $query = ProductFilterQueryService::queryProductIds($sectionId, $filter, $page, $limit, $perf);
        $total = (int)$query['total'];
        $pages = (int)($query['pages'] ?? (int)ceil($total / max(1, $limit)));
        $page = (int)($query['page'] ?? $page);

        $stageStartedAt = microtime(true);
        $items = self::loadGoodsDetailsByIds($query['product_ids'], $perf);
        if ($measurePerf) {
            $perf['stages_ms']['list'] = round((microtime(true) - $stageStartedAt) * 1000, 2);
            $perf['meta']['items_count'] = count($items);
            $perf['meta']['count_mode'] = 'product_index';
        }

        $result = [
            'items' => $items,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'limit' => $limit,
            'sectionName' => $sectionName,
            'subsections' => $subsections,
        ];

        if ($measurePerf) {
            self::finalizePerf($perf, $perfStartedAt);
            $result['_perf'] = $perf;
        }

        if (!$skipCache) {
            $cachePayload = $result;
            unset($cachePayload['_perf']);
            self::commitTaggedDataCache(
                $cache,
                self::CACHE_DIR_GOODS,
                self::cacheTagsForGoodsListing($sectionId, $items),
                $cachePayload,
                $goodsCacheStarted
            );
        }

        return $result;
    }

    private static function getSectionIdsByBounds(int $leftMargin, int $rightMargin): array
    {
        $db = Application::getConnection();
        $sql = "
            SELECT ID
            FROM b_iblock_section
            WHERE IBLOCK_ID = " . self::iblockId() . "
              AND ACTIVE = 'Y'
              AND LEFT_MARGIN >= {$leftMargin}
              AND RIGHT_MARGIN <= {$rightMargin}
        ";

        $res = $db->query($sql);
        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['ID'];
        }

        return $ids;
    }

    private static function emptyResult(int $page, int $limit, string $sectionName = '', array $subsections = []): array
    {
        return [
            'items' => [],
            'total' => 0,
            'pages' => 0,
            'page'  => $page,
            'limit' => $limit,
            'sectionName' => $sectionName,
            'subsections' => $subsections,
        ];
    }

    private static function shouldMeasurePerf(): bool
    {
        try {
            $request = \Bitrix\Main\Context::getCurrent()->getRequest();
            $perf = $request->get('perf');
            return $perf === '1' || $perf === 1 || $perf === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function shouldSkipCache(): bool
    {
        try {
            $request = \Bitrix\Main\Context::getCurrent()->getRequest();
            $skipCache = $request->get('skip_cache');
            return $skipCache === '1' || $skipCache === 1 || $skipCache === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function getMainSectionForProduct(int $productId): ?array
    {
        $db = Application::getConnection();

        $sql = "
        SELECT s.ID, s.NAME
        FROM b_iblock_section_element se
        JOIN b_iblock_section s ON s.ID = se.IBLOCK_SECTION_ID
        WHERE se.IBLOCK_ELEMENT_ID = {$productId}
          AND s.IBLOCK_ID = " . self::iblockId() . "
          AND s.ACTIVE = 'Y'
        ORDER BY s.DEPTH_LEVEL DESC, s.SORT ASC
        LIMIT 1
    ";

        return $db->query($sql)->fetch() ?: null;
    }

    private static function getDirectSubsections(int $sectionId): array
    {
        $db = Application::getConnection();
        $sectionId = (int)$sectionId;
        if ($sectionId <= 0) {
            return [];
        }

        $sql = "
            SELECT ID, NAME
            FROM b_iblock_section
            WHERE IBLOCK_ID = " . self::iblockId() . "
              AND ACTIVE = 'Y'
              AND IBLOCK_SECTION_ID = {$sectionId}
            ORDER BY SORT ASC, NAME ASC
        ";

        $res = $db->query($sql);
        $result = [];
        while ($row = $res->fetch()) {
            $childId = (int)$row['ID'];
            $result[] = [
                'ID' => $childId,
                'NAME' => (string)$row['NAME'],
                'URL' => '/catalog/section/' . $childId,
            ];
        }

        return $result;
    }

    private static function getSectionPath(int $sectionId): array
    {
        $db = Application::getConnection();
        $sectionId = (int)$sectionId;
        if ($sectionId <= 0) {
            return [];
        }

        $sql = "
            SELECT parent.ID, parent.NAME
            FROM b_iblock_section parent
            JOIN b_iblock_section current_section ON current_section.ID = {$sectionId}
            WHERE parent.IBLOCK_ID = " . self::iblockId() . "
              AND parent.ACTIVE = 'Y'
              AND current_section.IBLOCK_ID = " . self::iblockId() . "
              AND current_section.ACTIVE = 'Y'
              AND parent.LEFT_MARGIN <= current_section.LEFT_MARGIN
              AND parent.RIGHT_MARGIN >= current_section.RIGHT_MARGIN
            ORDER BY parent.LEFT_MARGIN ASC
        ";

        $res = $db->query($sql);
        $path = [];
        while ($row = $res->fetch()) {
            $path[] = [
                'ID' => (int)$row['ID'],
                'NAME' => (string)$row['NAME'],
            ];
        }

        return $path;
    }
}
