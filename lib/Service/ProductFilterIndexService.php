<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Install\ProductFilterIndexSchema;
use MyApp\Catalog\Model\FilterIndexJobTable;
use MyApp\Catalog\Model\FilterIndexMetaTable;
use MyApp\Catalog\Model\FilterIndexProductSectionTable;
use MyApp\Catalog\Model\FilterIndexProductTable;
use MyApp\Catalog\Model\FilterIndexValueTable;

final class ProductFilterIndexService
{
    private const MODULE_ID = 'myapp.catalog';

    private const OPT_READY = 'product_filter_index_ready';

    public static function indexConfigHash(): string
    {
        return md5(
            Assets::catalogIblockId()
            . '|' . CatalogSkuService::getOffersIblockId()
            . '|' . implode("\n", Assets::filterPropertyCodes())
            . '|' . implode("\n", CatalogSkuService::skuPropertyCodes())
            . '|' . Assets::catalogArticlePropertyCode()
            . '|' . Assets::catalogPriceTypeCode()
        );
    }

    public static function useIndexOption(): bool
    {
        // Derived from catalog_filter_engine (single source of truth).
        return \MyApp\Catalog\Filter\CatalogFilterEngineFactory::getEngineId()
            === \MyApp\Catalog\Filter\CatalogFilterEngineFactory::ENGINE_DENORMALIZED;
    }

    public static function isIndexReady(): bool
    {
        if (Option::get(self::MODULE_ID, self::OPT_READY, 'N') !== 'Y') {
            return false;
        }

        $meta = self::getMetaRow();
        if ($meta === null) {
            return false;
        }

        return (string)($meta['CONFIG_HASH'] ?? '') === self::indexConfigHash();
    }

    /** Active denormalized engine for catalog/goods (engine selected + index ready). */
    public static function shouldUseIndex(): bool
    {
        return self::useIndexOption() && self::isIndexReady();
    }

    /**
     * Incremental queue/remove for denormalized index: only while it is the active engine
     * or a denormalized index job is running (admin rebuild of this index).
     */
    public static function shouldMaintain(): bool
    {
        if (self::useIndexOption()) {
            return true;
        }

        return ProductFilterIndexJobService::getRunningJob() !== null;
    }

    public static function isIndexConfigStale(): bool
    {
        ProductFilterIndexSchema::ensureInfrastructure();

        $expected = self::indexConfigHash();
        $meta = self::getMetaRow();
        if ($meta === null) {
            return false;
        }

        $stored = (string)($meta['CONFIG_HASH'] ?? '');
        if ($stored === $expected) {
            return false;
        }

        $productsCount = (int)($meta['PRODUCTS_COUNT'] ?? 0);
        if ($stored === '' && $productsCount <= 0) {
            return false;
        }

        return true;
    }

    public static function getIndexVersion(): int
    {
        $meta = self::getMetaRow();

        return (int)($meta['INDEX_VERSION'] ?? 0);
    }

    public static function markNotReady(): void
    {
        Option::set(self::MODULE_ID, self::OPT_READY, 'N');
    }

    public static function markReady(): void
    {
        Option::set(self::MODULE_ID, self::OPT_READY, 'Y');
    }

    public static function buildFullIndex(): int
    {
        return ProductFilterIndexJobService::startJob(ProductFilterIndexJobService::TYPE_FULL);
    }

    /**
     * Если full/sync job уже completed, очередь пуста, но finalize не вызывался (старая логика errors>0).
     */
    public static function repairReadyAfterCompletedBuildJob(): void
    {
        if (self::isIndexReady()) {
            return;
        }
        if (ProductFilterIndexJobService::getRunningJob() !== null) {
            return;
        }
        if (ProductFilterIndexQueueService::count() > 0) {
            return;
        }

        $connection = Application::getConnection();
        $productCount = (int)$connection->queryScalar(
            'SELECT COUNT(*) FROM ' . FilterIndexProductTable::getTableName()
        );
        if ($productCount <= 0) {
            return;
        }

        $job = FilterIndexJobTable::getList([
            'filter' => ['=STATUS' => ProductFilterIndexJobService::STATUS_COMPLETED],
            'order' => ['ID' => 'DESC'],
            'limit' => 1,
        ])->fetch();

        if (!is_array($job)) {
            return;
        }

        $type = (string)($job['TYPE'] ?? '');
        if (
            $type !== ProductFilterIndexJobService::TYPE_FULL
            && $type !== ProductFilterIndexJobService::TYPE_SYNC
            && $type !== ProductFilterIndexJobService::TYPE_SECTION
        ) {
            return;
        }

        $processed = (int)($job['PROCESSED'] ?? 0);
        $total = (int)($job['TOTAL'] ?? 0);
        if ($processed <= 0) {
            return;
        }
        if ($total > 0 && $processed < $total) {
            return;
        }

        $errors = (int)($job['ERROR_COUNT'] ?? 0);
        $message = $errors > 0
            ? 'Построение завершено с ошибками: ' . $errors
            : null;
        if ($type === ProductFilterIndexJobService::TYPE_FULL) {
            self::finalizeFullBuild($message);
        } elseif ($type === ProductFilterIndexJobService::TYPE_SYNC) {
            self::finalizeSyncJob($message);
        } else {
            $paramsRaw = (string)($job['PARAMS_JSON'] ?? '');
            $params = $paramsRaw !== '' ? json_decode($paramsRaw, true) : [];
            $sectionId = is_array($params) ? max(0, (int)($params['section_id'] ?? 0)) : 0;
            if ($sectionId <= 0) {
                return;
            }
            if ($errors > 0) {
                $message = 'Построение раздела завершено с ошибками: ' . $errors;
            }
            self::finalizeSectionJob($sectionId, $message);
        }
    }

    public static function buildSectionIndex(int $sectionId): int
    {
        $sectionId = max(0, (int)$sectionId);

        return ProductFilterIndexJobService::startJob(
            ProductFilterIndexJobService::TYPE_SECTION,
            ['section_id' => $sectionId]
        );
    }

    public static function rebuildProduct(int $productId): void
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return;
        }

        ProductFilterIndexSchema::ensureInfrastructure();
        Loader::includeModule('iblock');

        self::removeProduct($productId);

        $db = Application::getConnection();
        $iblockId = Assets::catalogIblockId();

        $element = $db->query("
            SELECT ID, IBLOCK_ID, ACTIVE, SORT
            FROM b_iblock_element
            WHERE ID = {$productId} AND IBLOCK_ID = {$iblockId}
            LIMIT 1
        ")->fetch();

        if (!$element) {
            return;
        }

        if ((string)$element['ACTIVE'] !== 'Y') {
            return;
        }

        FilterIndexProductTable::add([
            'PRODUCT_ID' => $productId,
            'IBLOCK_ID' => (int)$element['IBLOCK_ID'],
            'ACTIVE' => 'Y',
            'SORT' => (int)$element['SORT'],
            'PRICE' => self::resolveIndexedPrice($productId),
            'UPDATED_AT' => new DateTime(),
            'LAST_ERROR' => null,
        ]);

        $sectionRes = $db->query("
            SELECT IBLOCK_SECTION_ID AS SECTION_ID
            FROM b_iblock_section_element
            WHERE IBLOCK_ELEMENT_ID = {$productId}
        ");
        while ($row = $sectionRes->fetch()) {
            $sectionId = (int)$row['SECTION_ID'];
            if ($sectionId <= 0) {
                continue;
            }
            FilterIndexProductSectionTable::add([
                'PRODUCT_ID' => $productId,
                'SECTION_ID' => $sectionId,
            ]);
        }

        self::indexProductPropertyValues($productId);
        self::indexMergedOfferPropertyValues($productId);
    }

    public static function rebuildSection(int $sectionId): int
    {
        return self::buildSectionIndex($sectionId);
    }

    public static function countProductsInSection(int $sectionId): int
    {
        $sectionId = max(0, (int)$sectionId);
        if ($sectionId <= 0) {
            return 0;
        }

        ProductFilterIndexSchema::ensureInfrastructure();
        Loader::includeModule('iblock');

        $sectionFilter = self::buildSectionElementFilterSql($sectionId, Assets::catalogIblockId());
        if ($sectionFilter === null) {
            return 0;
        }

        $iblockId = Assets::catalogIblockId();

        return (int)Application::getConnection()->queryScalar("
            SELECT COUNT(DISTINCT se.IBLOCK_ELEMENT_ID)
            FROM b_iblock_section_element se
            INNER JOIN b_iblock_element e ON e.ID = se.IBLOCK_ELEMENT_ID
            WHERE e.IBLOCK_ID = {$iblockId}
              AND e.ACTIVE = 'Y'
              {$sectionFilter}
        ");
    }

    /**
     * @return array{ids: list<int>, last_id: int}
     */
    public static function fetchSectionProductIdsAfterCursor(int $sectionId, int $cursor, int $limit): array
    {
        $sectionId = max(0, (int)$sectionId);
        $cursor = max(0, (int)$cursor);
        $limit = max(1, min(2000, (int)$limit));

        ProductFilterIndexSchema::ensureInfrastructure();
        Loader::includeModule('iblock');

        $iblockId = Assets::catalogIblockId();
        $sectionFilter = self::buildSectionElementFilterSql($sectionId, $iblockId);
        if ($sectionFilter === null) {
            return ['ids' => [], 'last_id' => $cursor];
        }

        $res = Application::getConnection()->query("
            SELECT DISTINCT se.IBLOCK_ELEMENT_ID AS ID
            FROM b_iblock_section_element se
            INNER JOIN b_iblock_element e ON e.ID = se.IBLOCK_ELEMENT_ID
            WHERE e.IBLOCK_ID = {$iblockId}
              AND e.ACTIVE = 'Y'
              AND se.IBLOCK_ELEMENT_ID > {$cursor}
              {$sectionFilter}
            ORDER BY se.IBLOCK_ELEMENT_ID ASC
            LIMIT {$limit}
        ");

        $ids = [];
        $lastId = $cursor;
        while ($row = $res->fetch()) {
            $lastId = (int)$row['ID'];
            $ids[] = $lastId;
        }

        return ['ids' => $ids, 'last_id' => $lastId];
    }

    public static function resolveIndexedPrice(int $productId): ?float
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return null;
        }

        if (!Loader::includeModule('catalog')) {
            return null;
        }

        if (CatalogSkuService::isSkuProduct($productId)) {
            $range = CatalogSkuService::getOfferPriceRange($productId);
            if ($range === null) {
                return null;
            }
            $displayCurrency = CatalogService::catalogDisplayCurrencyPublic();
            $storageCurrency = CatalogService::catalogPriceStorageCurrencyPublic();

            return CatalogService::convertCatalogPricePublic(
                (float)$range['min'],
                $displayCurrency,
                $storageCurrency
            );
        }

        $groupId = CatalogService::catalogPriceGroupIdPublic();
        if ($groupId <= 0) {
            return null;
        }

        $storageCurrency = CatalogService::catalogPriceStorageCurrencyPublic();
        $db = Application::getConnection();
        $res = $db->query("
            SELECT PRICE, CURRENCY
            FROM b_catalog_price
            WHERE PRODUCT_ID = {$productId}
              AND CATALOG_GROUP_ID = {$groupId}
        ");

        $min = null;
        while ($row = $res->fetch()) {
            $fromCurrency = trim((string)($row['CURRENCY'] ?? ''));
            if ($fromCurrency === '') {
                $fromCurrency = $storageCurrency;
            }
            $converted = CatalogService::convertCatalogPricePublic(
                (float)($row['PRICE'] ?? 0),
                $fromCurrency,
                $storageCurrency
            );
            $min = $min === null ? $converted : min($min, $converted);
        }

        return $min;
    }

    public static function removeProduct(int $productId): void
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return;
        }

        ProductFilterIndexSchema::ensureInfrastructure();

        $connection = Application::getConnection();
        $valueTable = FilterIndexValueTable::getTableName();
        $connection->queryExecute("DELETE FROM {$valueTable} WHERE PRODUCT_ID = {$productId}");

        $sections = FilterIndexProductSectionTable::getTableName();
        $connection->queryExecute("DELETE FROM {$sections} WHERE PRODUCT_ID = {$productId}");

        if (FilterIndexProductTable::getByPrimary($productId)->fetch()) {
            FilterIndexProductTable::delete($productId);
        }
    }

    /**
     * Пересборка индекса без truncate (job TYPE_SYNC).
     *
     * Не вызывается при Save настроек модуля: фоновая job на весь каталог через агента
     * слишком медленна; после смены iblock / свойств фильтра / артикула нужно
     * {@see buildFullIndex()} из админки. Метод оставлен для редкого программного вызова.
     */
    public static function syncSettings(): void
    {
        ProductFilterIndexSchema::ensureInfrastructure();

        $allowedIds = self::allowedPropertyIds();
        if ($allowedIds === []) {
            $connection = Application::getConnection();
            $connection->queryExecute('DELETE FROM ' . FilterIndexValueTable::getTableName());
        } else {
            $inSql = implode(',', $allowedIds);
            Application::getConnection()->queryExecute(
                'DELETE FROM ' . FilterIndexValueTable::getTableName()
                . " WHERE PROPERTY_ID NOT IN ({$inSql})"
            );
        }

        self::updateMeta([
            'CONFIG_HASH' => self::indexConfigHash(),
            'LAST_ERROR' => null,
        ]);

        if (!self::isIndexReady()) {
            self::markNotReady();
        }

        ProductFilterIndexJobService::startJob(ProductFilterIndexJobService::TYPE_SYNC);
    }

    public static function clearIndexAndQueue(): void
    {
        ProductFilterIndexSchema::truncateIndexData();
        ProductFilterIndexQueueService::clear();
        self::markNotReady();
        Option::set(self::MODULE_ID, Assets::OPT_PRODUCT_FILTER_INDEX_PARTIAL_SECTION_ID, '0');
        self::updateMeta([
            'PRODUCTS_COUNT' => 0,
            'VALUES_COUNT' => 0,
            'LAST_ERROR' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getIndexStatus(): array
    {
        ProductFilterIndexSchema::ensureInfrastructure();

        self::repairReadyAfterCompletedBuildJob();

        $meta = self::getMetaRow() ?? [];
        $partialSectionId = (int)Option::get(
            self::MODULE_ID,
            Assets::OPT_PRODUCT_FILTER_INDEX_PARTIAL_SECTION_ID,
            '0'
        );

        return [
            'index_version' => (int)($meta['INDEX_VERSION'] ?? 0),
            'config_hash' => (string)($meta['CONFIG_HASH'] ?? ''),
            'expected_config_hash' => self::indexConfigHash(),
            'products_count' => (int)($meta['PRODUCTS_COUNT'] ?? 0),
            'values_count' => (int)($meta['VALUES_COUNT'] ?? 0),
            'last_full_sync_at' => $meta['LAST_FULL_SYNC_AT'] ?? null,
            'last_error' => $meta['LAST_ERROR'] ?? null,
            'index_ready' => self::isIndexReady(),
            'index_config_stale' => self::isIndexConfigStale(),
            'use_index_option' => self::useIndexOption(),
            'partial_section_id' => $partialSectionId,
            'queue_size' => ProductFilterIndexQueueService::count(),
            'running_job' => ProductFilterIndexJobService::getRunningJob(),
            'indexed_properties_count' => count(CatalogService::getFilterPropertyDefinitions()),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getRecentProductErrors(int $limit = 15): array
    {
        ProductFilterIndexSchema::ensureInfrastructure();
        $limit = max(1, min(100, $limit));
        $table = FilterIndexProductTable::getTableName();
        $res = Application::getConnection()->query("
            SELECT PRODUCT_ID, LAST_ERROR, UPDATED_AT
            FROM {$table}
            WHERE LAST_ERROR IS NOT NULL AND LAST_ERROR <> ''
            ORDER BY UPDATED_AT DESC
            LIMIT {$limit}
        ");
        $items = [];
        while ($row = $res->fetch()) {
            $items[] = $row;
        }

        return $items;
    }

    public static function refreshMetaCounts(): void
    {
        ProductFilterIndexSchema::ensureInfrastructure();
        $connection = Application::getConnection();
        $products = (int)$connection->queryScalar(
            'SELECT COUNT(*) FROM ' . FilterIndexProductTable::getTableName()
        );
        $values = (int)$connection->queryScalar(
            'SELECT COUNT(*) FROM ' . FilterIndexValueTable::getTableName()
        );
        self::updateMeta([
            'PRODUCTS_COUNT' => $products,
            'VALUES_COUNT' => $values,
        ]);
    }

    public static function finalizeFullBuild(?string $lastError = null): void
    {
        self::refreshMetaCounts();
        $meta = self::getMetaRow() ?? [];
        $version = (int)($meta['INDEX_VERSION'] ?? 0);
        self::updateMeta([
            'INDEX_VERSION' => $version + 1,
            'CONFIG_HASH' => self::indexConfigHash(),
            'LAST_FULL_SYNC_AT' => new DateTime(),
            'LAST_ERROR' => $lastError !== null && $lastError !== '' ? $lastError : null,
        ]);
        Option::set(self::MODULE_ID, Assets::OPT_PRODUCT_FILTER_INDEX_PARTIAL_SECTION_ID, '0');
        self::markReady();
        CatalogService::clearCatalogDataCache();
    }

    public static function finalizeSectionJob(int $sectionId, ?string $lastError = null): void
    {
        self::refreshMetaCounts();
        self::updateMeta([
            'CONFIG_HASH' => self::indexConfigHash(),
            'LAST_ERROR' => $lastError !== null && $lastError !== '' ? $lastError : null,
        ]);
        Option::set(
            self::MODULE_ID,
            Assets::OPT_PRODUCT_FILTER_INDEX_PARTIAL_SECTION_ID,
            (string)max(0, (int)$sectionId)
        );
        self::markReady();
        CatalogService::clearCatalogDataCache();
    }

    public static function finalizeSyncJob(?string $lastError = null): void
    {
        self::refreshMetaCounts();
        self::updateMeta([
            'CONFIG_HASH' => self::indexConfigHash(),
            'LAST_ERROR' => $lastError !== null && $lastError !== '' ? $lastError : null,
        ]);
        self::markReady();
        CatalogService::clearCatalogDataCache();
    }

    /**
     * @return list<int>
     */
    public static function collectAllCatalogProductIds(): array
    {
        Loader::includeModule('iblock');
        $db = Application::getConnection();
        $iblockId = Assets::catalogIblockId();
        $res = $db->query("
            SELECT ID FROM b_iblock_element
            WHERE IBLOCK_ID = {$iblockId} AND ACTIVE = 'Y'
            ORDER BY ID ASC
        ");
        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['ID'];
        }

        return $ids;
    }

    public static function countCatalogProducts(): int
    {
        Loader::includeModule('iblock');
        $iblockId = Assets::catalogIblockId();

        return (int)Application::getConnection()->queryScalar("
            SELECT COUNT(*) FROM b_iblock_element
            WHERE IBLOCK_ID = {$iblockId} AND ACTIVE = 'Y'
        ");
    }

    /**
     * @return list<int>
     */
    private static function allowedPropertyIds(): array
    {
        $ids = [];
        foreach (CatalogService::getFilterPropertyDefinitions() as $definition) {
            $ids[] = (int)$definition['ID'];
        }
        $articleId = CatalogService::getArticlePropertyId();
        if ($articleId > 0) {
            $ids[] = $articleId;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private static function indexProductPropertyValues(int $productId): void
    {
        $propertyIds = self::allowedPropertyIds();
        if ($propertyIds === []) {
            return;
        }

        $db = Application::getConnection();
        $inSql = implode(',', $propertyIds);
        $definitions = [];
        foreach (CatalogService::getFilterPropertyDefinitions() as $def) {
            $definitions[(int)$def['ID']] = $def;
        }

        $res = $db->query("
            SELECT IBLOCK_PROPERTY_ID, VALUE, VALUE_ENUM, VALUE_NUM
            FROM b_iblock_element_property
            WHERE IBLOCK_ELEMENT_ID = {$productId}
              AND IBLOCK_PROPERTY_ID IN ({$inSql})
        ");

        /** @var array<string, true> $seenValueKeys */
        $seenValueKeys = [];

        while ($row = $res->fetch()) {
            $propertyId = (int)$row['IBLOCK_PROPERTY_ID'];
            $def = $definitions[$propertyId] ?? null;
            $valueKind = is_array($def) ? (string)$def['valueKind'] : 'string';

            $enumVal = (int)$row['VALUE_ENUM'];
            $numVal = $row['VALUE_NUM'];
            $strVal = trim((string)($row['VALUE'] ?? ''));

            if ($valueKind === 'enum' && $enumVal > 0) {
                $dedupeKey = 'e:' . $propertyId . ':' . $enumVal;
                if (isset($seenValueKeys[$dedupeKey])) {
                    continue;
                }
                $seenValueKeys[$dedupeKey] = true;
                FilterIndexValueTable::add([
                    'PRODUCT_ID' => $productId,
                    'PROPERTY_ID' => $propertyId,
                    'VALUE_ENUM' => $enumVal,
                    'VALUE_NUM' => null,
                    'VALUE_STR' => null,
                ]);
                continue;
            }

            if ($valueKind === 'number') {
                $num = $numVal !== null && $numVal !== '' ? (float)$numVal : (float)str_replace(',', '.', $strVal);
                $dedupeKey = 'n:' . $propertyId . ':' . $num;
                if (isset($seenValueKeys[$dedupeKey])) {
                    continue;
                }
                $seenValueKeys[$dedupeKey] = true;
                FilterIndexValueTable::add([
                    'PRODUCT_ID' => $productId,
                    'PROPERTY_ID' => $propertyId,
                    'VALUE_ENUM' => null,
                    'VALUE_NUM' => $num,
                    'VALUE_STR' => null,
                ]);
                continue;
            }

            if ($strVal === '' && $enumVal > 0) {
                $strVal = (string)$enumVal;
            }
            if ($strVal === '') {
                continue;
            }

            $strStored = mb_substr($strVal, 0, 500);
            $dedupeKey = 's:' . $propertyId . ':' . $strStored;
            if (isset($seenValueKeys[$dedupeKey])) {
                continue;
            }
            $seenValueKeys[$dedupeKey] = true;
            FilterIndexValueTable::add([
                'PRODUCT_ID' => $productId,
                'PROPERTY_ID' => $propertyId,
                'VALUE_ENUM' => null,
                'VALUE_NUM' => null,
                'VALUE_STR' => $strStored,
            ]);
        }
    }

    private static function indexMergedOfferPropertyValues(int $productId): void
    {
        $offerIds = CatalogSkuService::getOfferIdsForProduct($productId);
        if ($offerIds === []) {
            return;
        }

        $offersIblockId = CatalogSkuService::getOffersIblockId();
        if ($offersIblockId <= 0) {
            return;
        }

        $codes = CatalogService::effectiveFilterPropertyCodes();
        if ($codes === []) {
            return;
        }

        $propertyMap = [];
        foreach ($codes as $code) {
            $row = \Bitrix\Iblock\PropertyTable::getList([
                'select' => ['ID', 'CODE', 'PROPERTY_TYPE'],
                'filter' => ['=IBLOCK_ID' => $offersIblockId, '=CODE' => $code],
                'limit' => 1,
            ])->fetch();
            if ($row) {
                $propertyMap[$code] = [
                    'ID' => (int)$row['ID'],
                    'TYPE' => (string)$row['PROPERTY_TYPE'],
                ];
            }
        }

        if ($propertyMap === []) {
            return;
        }

        $idsSql = implode(',', array_map('intval', $offerIds));
        $propIdsSql = implode(',', array_map(static fn(array $p): int => (int)$p['ID'], $propertyMap));

        $db = Application::getConnection();
        $res = $db->query("
            SELECT IBLOCK_PROPERTY_ID, VALUE, VALUE_ENUM, VALUE_NUM
            FROM b_iblock_element_property
            WHERE IBLOCK_ELEMENT_ID IN ({$idsSql})
              AND IBLOCK_PROPERTY_ID IN ({$propIdsSql})
        ");

        /** @var array<string, true> $seenValueKeys */
        $seenValueKeys = [];

        while ($row = $res->fetch()) {
            $propertyId = (int)$row['IBLOCK_PROPERTY_ID'];
            $enumVal = (int)$row['VALUE_ENUM'];
            if ($enumVal <= 0 && is_numeric($row['VALUE'] ?? null)) {
                $enumVal = (int)$row['VALUE'];
            }
            $numVal = $row['VALUE_NUM'];
            $strVal = trim((string)($row['VALUE'] ?? ''));

            if ($enumVal > 0) {
                $dedupeKey = 'e:' . $propertyId . ':' . $enumVal;
                if (isset($seenValueKeys[$dedupeKey])) {
                    continue;
                }
                $seenValueKeys[$dedupeKey] = true;
                FilterIndexValueTable::add([
                    'PRODUCT_ID' => $productId,
                    'PROPERTY_ID' => $propertyId,
                    'VALUE_ENUM' => $enumVal,
                    'VALUE_NUM' => null,
                    'VALUE_STR' => null,
                ]);
                continue;
            }

            if ($numVal !== null && $numVal !== '') {
                $num = (float)$numVal;
                $dedupeKey = 'n:' . $propertyId . ':' . $num;
                if (isset($seenValueKeys[$dedupeKey])) {
                    continue;
                }
                $seenValueKeys[$dedupeKey] = true;
                FilterIndexValueTable::add([
                    'PRODUCT_ID' => $productId,
                    'PROPERTY_ID' => $propertyId,
                    'VALUE_ENUM' => null,
                    'VALUE_NUM' => $num,
                    'VALUE_STR' => null,
                ]);
                continue;
            }

            if ($strVal === '') {
                continue;
            }

            $strStored = mb_substr($strVal, 0, 500);
            $dedupeKey = 's:' . $propertyId . ':' . $strStored;
            if (isset($seenValueKeys[$dedupeKey])) {
                continue;
            }
            $seenValueKeys[$dedupeKey] = true;
            FilterIndexValueTable::add([
                'PRODUCT_ID' => $productId,
                'PROPERTY_ID' => $propertyId,
                'VALUE_ENUM' => null,
                'VALUE_NUM' => null,
                'VALUE_STR' => $strStored,
            ]);
        }
    }

    /**
     * @return string|null SQL AND-фрагмент для фильтра по поддереву раздела; null — раздел не найден
     */
    private static function buildSectionElementFilterSql(int $sectionId, int $iblockId): ?string
    {
        $sectionId = max(0, (int)$sectionId);
        if ($sectionId <= 0) {
            return '';
        }

        $bounds = CatalogService::getSectionBounds($sectionId);
        if ($bounds === null) {
            return null;
        }

        $lm = (int)$bounds['LEFT_MARGIN'];
        $rm = (int)$bounds['RIGHT_MARGIN'];

        return "
            AND se.IBLOCK_SECTION_ID IN (
                SELECT ID FROM b_iblock_section
                WHERE IBLOCK_ID = {$iblockId}
                  AND ACTIVE = 'Y'
                  AND LEFT_MARGIN >= {$lm}
                  AND RIGHT_MARGIN <= {$rm}
            )
        ";
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function updateMeta(array $fields): void
    {
        ProductFilterIndexSchema::ensureInfrastructure();
        if (!FilterIndexMetaTable::getByPrimary(FilterIndexMetaTable::ROW_ID)->fetch()) {
            FilterIndexMetaTable::add(array_merge(['ID' => FilterIndexMetaTable::ROW_ID], $fields));
            return;
        }
        FilterIndexMetaTable::update(FilterIndexMetaTable::ROW_ID, $fields);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getMetaRow(): ?array
    {
        ProductFilterIndexSchema::ensureInfrastructure();
        $row = FilterIndexMetaTable::getByPrimary(FilterIndexMetaTable::ROW_ID)->fetch();

        return is_array($row) ? $row : null;
    }
}
