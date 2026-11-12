<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Filter\CatalogFilterEngineFactory;
use MyApp\Catalog\Install\BitmapIndexSchema;
use MyApp\Catalog\Model\BitmapMembershipTable;
use MyApp\Catalog\Model\BitmapProductTable;
use MyApp\Catalog\Model\BitmapStatusTable;
use MyApp\Catalog\Model\BitmapValueDictTable;

final class BitmapIndexService
{
    private const MODULE_ID = 'myapp.catalog';

    private const OPT_READY = 'bitmap_index_ready';

    public static function indexConfigHash(): string
    {
        return md5(
            Assets::catalogIblockId()
            . '|' . CatalogSkuService::getOffersIblockId()
            . '|' . implode("\n", Assets::filterPropertyCodes())
            . '|' . implode("\n", CatalogSkuService::skuPropertyCodes())
            . '|' . Assets::catalogPriceTypeCode()
            . '|bitmap_v1'
        );
    }

    /**
     * Incremental queue/remove for bitmap: only while bitmap is the active engine
     * or a bitmap job is running (admin rebuild).
     */
    public static function shouldMaintain(): bool
    {
        if (CatalogFilterEngineFactory::getEngineId() === CatalogFilterEngineFactory::ENGINE_BITMAP) {
            return true;
        }

        return BitmapIndexJobService::getRunningJob() !== null;
    }

    public static function isIndexReady(): bool
    {
        if (Option::get(self::MODULE_ID, self::OPT_READY, 'N') !== 'Y') {
            return false;
        }

        $meta = self::getStatusRow();
        if ($meta === null) {
            return false;
        }

        return (string)($meta['CONFIG_HASH'] ?? '') === self::indexConfigHash();
    }

    public static function shouldUseIndex(): bool
    {
        return CatalogFilterEngineFactory::getEngineId() === CatalogFilterEngineFactory::ENGINE_BITMAP
            && self::isIndexReady();
    }

    public static function isIndexConfigStale(): bool
    {
        BitmapIndexSchema::ensureInfrastructure();
        $expected = self::indexConfigHash();
        $meta = self::getStatusRow();
        if ($meta === null) {
            return false;
        }
        $stored = (string)($meta['CONFIG_HASH'] ?? '');
        if ($stored === $expected) {
            return false;
        }
        $indexed = (int)($meta['PRODUCTS_INDEXED'] ?? 0);
        if ($stored === '' && $indexed <= 0) {
            return false;
        }

        return true;
    }

    public static function getIndexVersion(): int
    {
        $meta = self::getStatusRow();

        return (int)($meta['INDEX_VERSION'] ?? 0);
    }

    public static function getMaxPosition(): int
    {
        $meta = self::getStatusRow();

        return (int)($meta['MAX_POSITION'] ?? -1);
    }

    public static function getBitLength(): int
    {
        return max(0, self::getMaxPosition() + 1);
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
        return BitmapIndexJobService::startJob(BitmapIndexJobService::TYPE_FULL);
    }

    public static function rebuildProduct(int $productId): void
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return;
        }

        BitmapIndexSchema::ensureInfrastructure();
        Loader::includeModule('iblock');
        BitmapStorage::clearRequestCache();

        $connection = Application::getConnection();
        $connection->startTransaction();
        try {
            $existing = BitmapProductTable::getList([
                'filter' => ['=PRODUCT_ID' => $productId],
                'limit' => 1,
            ])->fetch();

            $oldPosition = is_array($existing) ? (int)$existing['BITMAP_POSITION'] : null;
            $oldKeys = self::loadMembershipKeys($productId);

            $iblockId = Assets::catalogIblockId();
            $element = $connection->query("
                SELECT ID, IBLOCK_ID, ACTIVE, SORT
                FROM b_iblock_element
                WHERE ID = {$productId} AND IBLOCK_ID = {$iblockId}
                LIMIT 1
            ")->fetch();

            if (!$element || (string)$element['ACTIVE'] !== 'Y') {
                if ($oldPosition !== null) {
                    self::clearBits($oldKeys, $oldPosition);
                    self::replaceMembership($productId, []);
                    BitmapProductTable::update((int)$existing['ID'], [
                        'ACTIVE' => 'N',
                        'UPDATED_AT' => new DateTime(),
                        'LAST_ERROR' => null,
                    ]);
                }
                $connection->commitTransaction();
                self::bumpSoftVersion();

                return;
            }

            $position = $oldPosition;
            if ($position === null) {
                $position = self::allocatePosition();
            }

            $bitLength = max(self::getBitLength(), $position + 1);
            self::clearBits($oldKeys, $position);

            $newKeys = self::collectProductValueKeys($productId);
            foreach ($newKeys as $key) {
                BitmapStorage::setProductBit(
                    (int)$key['property_id'],
                    (int)$key['value_id'],
                    $position,
                    true,
                    $bitLength
                );
            }
            self::replaceMembership($productId, $newKeys);

            $fields = [
                'PRODUCT_ID' => $productId,
                'BITMAP_POSITION' => $position,
                'IBLOCK_ID' => (int)$element['IBLOCK_ID'],
                'ACTIVE' => 'Y',
                'SORT' => (int)$element['SORT'],
                'PRICE' => ProductFilterIndexService::resolveIndexedPrice($productId),
                'UPDATED_AT' => new DateTime(),
                'LAST_ERROR' => null,
            ];

            if (is_array($existing)) {
                BitmapProductTable::update((int)$existing['ID'], $fields);
            } else {
                BitmapProductTable::add($fields);
            }

            self::ensureMaxPosition($position);
            $connection->commitTransaction();
            self::bumpSoftVersion();
        } catch (\Throwable $e) {
            $connection->rollbackTransaction();
            throw $e;
        }
    }

    public static function removeProduct(int $productId): void
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return;
        }

        BitmapIndexSchema::ensureInfrastructure();
        $existing = BitmapProductTable::getList([
            'filter' => ['=PRODUCT_ID' => $productId],
            'limit' => 1,
        ])->fetch();
        if (!is_array($existing)) {
            self::replaceMembership($productId, []);

            return;
        }

        $position = (int)$existing['BITMAP_POSITION'];
        $keys = self::loadMembershipKeys($productId);
        self::clearBits($keys, $position);
        self::replaceMembership($productId, []);
        BitmapProductTable::delete((int)$existing['ID']);
        self::bumpSoftVersion();
    }

    public static function clearIndexAndQueue(): void
    {
        BitmapIndexSchema::truncateIndexData();
        BitmapIndexQueueService::clear();
        self::markNotReady();
        self::updateStatus([
            'PRODUCTS_TOTAL' => 0,
            'PRODUCTS_INDEXED' => 0,
            'MASKS_COUNT' => 0,
            'MAX_POSITION' => -1,
            'STATUS' => 'idle',
            'LAST_ERROR' => null,
            'UPDATED_AT' => new DateTime(),
        ]);
        BitmapStorage::clearRequestCache();
    }

    public static function finalizeFullBuild(?string $lastError = null): void
    {
        self::refreshStatusCounts();
        $meta = self::getStatusRow() ?? [];
        $version = (int)($meta['INDEX_VERSION'] ?? 0);
        self::updateStatus([
            'INDEX_VERSION' => $version + 1,
            'CONFIG_HASH' => self::indexConfigHash(),
            'LAST_FULL_SYNC_AT' => new DateTime(),
            'STATUS' => 'ready',
            'LAST_ERROR' => $lastError !== null && $lastError !== '' ? $lastError : null,
            'UPDATED_AT' => new DateTime(),
        ]);
        self::markReady();
        CatalogService::clearCatalogDataCache();
        BitmapStorage::clearRequestCache();
    }

    public static function refreshStatusCounts(): void
    {
        BitmapIndexSchema::ensureInfrastructure();
        $connection = Application::getConnection();
        $products = (int)$connection->queryScalar(
            "SELECT COUNT(*) FROM " . BitmapProductTable::getTableName() . " WHERE ACTIVE = 'Y'"
        );
        $maxPos = (int)$connection->queryScalar(
            'SELECT COALESCE(MAX(BITMAP_POSITION), -1) FROM ' . BitmapProductTable::getTableName()
        );
        self::updateStatus([
            'PRODUCTS_INDEXED' => $products,
            'PRODUCTS_TOTAL' => ProductFilterIndexService::countCatalogProducts(),
            'MASKS_COUNT' => BitmapStorage::countMasks(),
            'MAX_POSITION' => $maxPos,
            'UPDATED_AT' => new DateTime(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getIndexStatus(): array
    {
        BitmapIndexSchema::ensureInfrastructure();
        $meta = self::getStatusRow() ?? [];

        return [
            'index_version' => (int)($meta['INDEX_VERSION'] ?? 0),
            'config_hash' => (string)($meta['CONFIG_HASH'] ?? ''),
            'expected_config_hash' => self::indexConfigHash(),
            'products_total' => (int)($meta['PRODUCTS_TOTAL'] ?? 0),
            'products_indexed' => (int)($meta['PRODUCTS_INDEXED'] ?? 0),
            'masks_count' => (int)($meta['MASKS_COUNT'] ?? 0),
            'max_position' => (int)($meta['MAX_POSITION'] ?? -1),
            'blob_bytes' => BitmapStorage::approxBlobBytes(),
            'status' => (string)($meta['STATUS'] ?? 'idle'),
            'last_full_sync_at' => $meta['LAST_FULL_SYNC_AT'] ?? null,
            'last_error' => $meta['LAST_ERROR'] ?? null,
            'index_ready' => self::isIndexReady(),
            'index_config_stale' => self::isIndexConfigStale(),
            'engine' => CatalogFilterEngineFactory::getEngineId(),
            'queue_size' => BitmapIndexQueueService::count(),
            'running_job' => BitmapIndexJobService::getRunningJob(),
        ];
    }

    /**
     * Resolve VALUE_ID for enum / string / number filter values.
     */
    public static function resolveValueId(int $propertyId, string $valueKind, mixed $rawValue, bool $create = true): ?int
    {
        $propertyId = (int)$propertyId;
        if ($propertyId <= 0 && $propertyId !== BitmapStorage::SECTION_PROPERTY_ID) {
            return null;
        }

        if ($valueKind === 'enum') {
            $enumId = (int)$rawValue;

            return $enumId > 0 ? $enumId : null;
        }

        if ($valueKind === 'number') {
            $num = is_numeric($rawValue)
                ? (float)$rawValue
                : (float)str_replace(',', '.', (string)$rawValue);
            $norm = rtrim(rtrim(sprintf('%.6F', $num), '0'), '.');
            if ($norm === '') {
                $norm = '0';
            }

            return self::resolveDictValueId($propertyId, 'N', $norm, $create);
        }

        $str = mb_substr(trim((string)$rawValue), 0, 500);
        if ($str === '') {
            return null;
        }

        return self::resolveDictValueId($propertyId, 'S', $str, $create);
    }

    public static function resolveDictValueId(int $propertyId, string $kind, string $norm, bool $create = true): ?int
    {
        BitmapIndexSchema::ensureInfrastructure();
        $row = BitmapValueDictTable::getList([
            'filter' => [
                '=PROPERTY_ID' => $propertyId,
                '=VALUE_KIND' => $kind,
                '=VALUE_NORM' => $norm,
            ],
            'limit' => 1,
        ])->fetch();
        if (is_array($row)) {
            return (int)$row['VALUE_ID'];
        }
        if (!$create) {
            return null;
        }

        $result = BitmapValueDictTable::add([
            'PROPERTY_ID' => $propertyId,
            'VALUE_KIND' => $kind,
            'VALUE_NORM' => $norm,
        ]);
        if (!$result->isSuccess()) {
            $row = BitmapValueDictTable::getList([
                'filter' => [
                    '=PROPERTY_ID' => $propertyId,
                    '=VALUE_KIND' => $kind,
                    '=VALUE_NORM' => $norm,
                ],
                'limit' => 1,
            ])->fetch();

            return is_array($row) ? (int)$row['VALUE_ID'] : null;
        }

        return (int)$result->getId();
    }

    /**
     * @return list<array{property_id: int, value_id: int}>
     */
    public static function collectProductValueKeys(int $productId): array
    {
        $keys = [];
        $seen = [];

        $db = Application::getConnection();
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
            $dedupe = '0:' . $sectionId;
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $keys[] = ['property_id' => BitmapStorage::SECTION_PROPERTY_ID, 'value_id' => $sectionId];
        }

        foreach (self::loadElementPropertyKeys($productId) as $key) {
            $dedupe = $key['property_id'] . ':' . $key['value_id'];
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $keys[] = $key;
        }

        foreach (self::loadOfferPropertyKeys($productId) as $key) {
            $dedupe = $key['property_id'] . ':' . $key['value_id'];
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $keys[] = $key;
        }

        return $keys;
    }

    private static function allocatePosition(): int
    {
        $connection = Application::getConnection();
        $connection->queryExecute("
            UPDATE " . BitmapStatusTable::getTableName() . "
            SET MAX_POSITION = MAX_POSITION + 1
            WHERE ID = " . BitmapStatusTable::ROW_ID
        );
        $max = (int)$connection->queryScalar(
            'SELECT MAX_POSITION FROM ' . BitmapStatusTable::getTableName() . ' WHERE ID = 1'
        );

        return $max;
    }

    private static function ensureMaxPosition(int $position): void
    {
        $meta = self::getStatusRow();
        $current = (int)($meta['MAX_POSITION'] ?? -1);
        if ($position > $current) {
            self::updateStatus(['MAX_POSITION' => $position, 'UPDATED_AT' => new DateTime()]);
        }
    }

    /**
     * @param list<array{property_id: int, value_id: int}> $keys
     */
    private static function clearBits(array $keys, int $position): void
    {
        $bitLength = max(self::getBitLength(), $position + 1);
        foreach ($keys as $key) {
            BitmapStorage::setProductBit(
                (int)$key['property_id'],
                (int)$key['value_id'],
                $position,
                false,
                $bitLength
            );
        }
    }

    /**
     * @return list<array{property_id: int, value_id: int}>
     */
    private static function loadMembershipKeys(int $productId): array
    {
        $keys = [];
        $res = BitmapMembershipTable::getList([
            'filter' => ['=PRODUCT_ID' => $productId],
        ]);
        while ($row = $res->fetch()) {
            $keys[] = [
                'property_id' => (int)$row['PROPERTY_ID'],
                'value_id' => (int)$row['VALUE_ID'],
            ];
        }

        return $keys;
    }

    /**
     * @param list<array{property_id: int, value_id: int}> $keys
     */
    private static function replaceMembership(int $productId, array $keys): void
    {
        $table = BitmapMembershipTable::getTableName();
        Application::getConnection()->queryExecute(
            "DELETE FROM {$table} WHERE PRODUCT_ID = {$productId}"
        );
        if ($keys === []) {
            return;
        }
        $values = [];
        foreach ($keys as $key) {
            $values[] = '(' . $productId . ',' . (int)$key['property_id'] . ',' . (int)$key['value_id'] . ')';
        }
        foreach (array_chunk($values, 200) as $chunk) {
            Application::getConnection()->queryExecute(
                "INSERT IGNORE INTO {$table} (PRODUCT_ID, PROPERTY_ID, VALUE_ID) VALUES "
                . implode(',', $chunk)
            );
        }
    }

    /**
     * @return list<array{property_id: int, value_id: int}>
     */
    private static function loadElementPropertyKeys(int $productId): array
    {
        $definitions = [];
        foreach (CatalogService::getFilterPropertyDefinitions() as $def) {
            $definitions[(int)$def['ID']] = $def;
        }
        if ($definitions === []) {
            return [];
        }

        $inSql = implode(',', array_keys($definitions));
        $db = Application::getConnection();
        $res = $db->query("
            SELECT IBLOCK_PROPERTY_ID, VALUE, VALUE_ENUM, VALUE_NUM
            FROM b_iblock_element_property
            WHERE IBLOCK_ELEMENT_ID = {$productId}
              AND IBLOCK_PROPERTY_ID IN ({$inSql})
        ");

        $keys = [];
        while ($row = $res->fetch()) {
            $propertyId = (int)$row['IBLOCK_PROPERTY_ID'];
            $def = $definitions[$propertyId] ?? null;
            if (!is_array($def)) {
                continue;
            }
            $valueId = self::valueIdFromPropertyRow($propertyId, (string)$def['valueKind'], $row);
            if ($valueId === null) {
                continue;
            }
            $keys[] = ['property_id' => $propertyId, 'value_id' => $valueId];
        }

        return $keys;
    }

    /**
     * @return list<array{property_id: int, value_id: int}>
     */
    private static function loadOfferPropertyKeys(int $productId): array
    {
        $offerIds = CatalogSkuService::getOfferIdsForProduct($productId);
        if ($offerIds === []) {
            return [];
        }
        $offersIblockId = CatalogSkuService::getOffersIblockId();
        if ($offersIblockId <= 0) {
            return [];
        }
        $codes = CatalogService::effectiveFilterPropertyCodes();
        if ($codes === []) {
            return [];
        }

        $propertyMap = [];
        foreach ($codes as $code) {
            $row = \Bitrix\Iblock\PropertyTable::getList([
                'select' => ['ID', 'CODE', 'PROPERTY_TYPE'],
                'filter' => ['=IBLOCK_ID' => $offersIblockId, '=CODE' => $code],
                'limit' => 1,
            ])->fetch();
            if ($row) {
                $type = (string)$row['PROPERTY_TYPE'];
                $valueKind = $type === 'L' ? 'enum' : ($type === 'N' ? 'number' : 'string');
                $propertyMap[(int)$row['ID']] = $valueKind;
            }
        }
        if ($propertyMap === []) {
            return [];
        }

        $idsSql = implode(',', array_map('intval', $offerIds));
        $propIdsSql = implode(',', array_keys($propertyMap));
        $res = Application::getConnection()->query("
            SELECT IBLOCK_PROPERTY_ID, VALUE, VALUE_ENUM, VALUE_NUM
            FROM b_iblock_element_property
            WHERE IBLOCK_ELEMENT_ID IN ({$idsSql})
              AND IBLOCK_PROPERTY_ID IN ({$propIdsSql})
        ");

        $keys = [];
        while ($row = $res->fetch()) {
            $propertyId = (int)$row['IBLOCK_PROPERTY_ID'];
            $valueKind = $propertyMap[$propertyId] ?? 'string';
            if ($valueKind === 'enum') {
                $enumVal = (int)$row['VALUE_ENUM'];
                if ($enumVal <= 0 && is_numeric($row['VALUE'] ?? null)) {
                    $enumVal = (int)$row['VALUE'];
                }
                $row['VALUE_ENUM'] = $enumVal;
            }
            $valueId = self::valueIdFromPropertyRow($propertyId, $valueKind, $row);
            if ($valueId === null) {
                continue;
            }
            $keys[] = ['property_id' => $propertyId, 'value_id' => $valueId];
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function valueIdFromPropertyRow(int $propertyId, string $valueKind, array $row): ?int
    {
        if ($valueKind === 'enum') {
            return self::resolveValueId($propertyId, 'enum', (int)$row['VALUE_ENUM'], true);
        }
        if ($valueKind === 'number') {
            $numVal = $row['VALUE_NUM'];
            $raw = $numVal !== null && $numVal !== ''
                ? $numVal
                : str_replace(',', '.', (string)($row['VALUE'] ?? ''));

            return self::resolveValueId($propertyId, 'number', $raw, true);
        }

        $strVal = trim((string)($row['VALUE'] ?? ''));
        if ($strVal === '' && (int)($row['VALUE_ENUM'] ?? 0) > 0) {
            $strVal = (string)(int)$row['VALUE_ENUM'];
        }

        return self::resolveValueId($propertyId, 'string', $strVal, true);
    }

    private static function bumpSoftVersion(): void
    {
        $meta = self::getStatusRow() ?? [];
        $version = (int)($meta['INDEX_VERSION'] ?? 0);
        self::updateStatus([
            'INDEX_VERSION' => $version + 1,
            'UPDATED_AT' => new DateTime(),
        ]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function updateStatus(array $fields): void
    {
        BitmapIndexSchema::ensureInfrastructure();
        if (!BitmapStatusTable::getByPrimary(BitmapStatusTable::ROW_ID)->fetch()) {
            BitmapStatusTable::add(array_merge(['ID' => BitmapStatusTable::ROW_ID], $fields));

            return;
        }
        BitmapStatusTable::update(BitmapStatusTable::ROW_ID, $fields);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getStatusRow(): ?array
    {
        BitmapIndexSchema::ensureInfrastructure();
        $row = BitmapStatusTable::getByPrimary(BitmapStatusTable::ROW_ID)->fetch();

        return is_array($row) ? $row : null;
    }
}
