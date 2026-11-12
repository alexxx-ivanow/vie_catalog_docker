<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Install\BitmapIndexSchema;
use MyApp\Catalog\Model\BitmapIndexTable;

/**
 * Чтение/запись MEDIUMBLOB масок. Request-local cache.
 *
 * PROPERTY_ID = {@see SECTION_PROPERTY_ID} (0) — не свойство инфоблока:
 * зарезервированный namespace масок разделов, VALUE_ID = SECTION_ID.
 */
final class BitmapStorage
{
    /** Sentinel: маски разделов каталога (VALUE_ID = ID раздела). */
    public const SECTION_PROPERTY_ID = 0;

    /** @var array<string, string> */
    private static array $requestCache = [];

    public static function clearRequestCache(): void
    {
        self::$requestCache = [];
    }

    public static function cacheKey(int $propertyId, int $valueId): string
    {
        return $propertyId . ':' . $valueId;
    }

    public static function loadMask(int $propertyId, int $valueId, ?int $bitLength = null): string
    {
        BitmapIndexSchema::ensureInfrastructure();
        $key = self::cacheKey($propertyId, $valueId);
        if (isset(self::$requestCache[$key])) {
            $mask = self::$requestCache[$key];
        } else {
            $table = BitmapIndexTable::getTableName();
            $row = Application::getConnection()->query("
                SELECT BITMAP
                FROM {$table}
                WHERE PROPERTY_ID = {$propertyId} AND VALUE_ID = {$valueId}
                LIMIT 1
            ")->fetch();
            $mask = is_array($row) ? (string)($row['BITMAP'] ?? '') : '';
            self::$requestCache[$key] = $mask;
        }

        if ($bitLength !== null && $bitLength >= 0) {
            return BitmapOperations::ensureLength($mask, $bitLength);
        }

        return $mask;
    }

    /**
     * @param list<array{property_id: int, value_id: int}> $keys
     * @return array<string, string>
     */
    public static function loadMasks(array $keys, ?int $bitLength = null): array
    {
        $result = [];
        $missing = [];
        foreach ($keys as $item) {
            $propertyId = (int)$item['property_id'];
            $valueId = (int)$item['value_id'];
            $key = self::cacheKey($propertyId, $valueId);
            if (isset(self::$requestCache[$key])) {
                $result[$key] = self::$requestCache[$key];
            } else {
                $missing[$key] = [$propertyId, $valueId];
            }
        }

        if ($missing !== []) {
            $table = BitmapIndexTable::getTableName();
            $parts = [];
            foreach ($missing as [$propertyId, $valueId]) {
                $parts[] = '(' . $propertyId . ',' . $valueId . ')';
            }
            $res = Application::getConnection()->query("
                SELECT PROPERTY_ID, VALUE_ID, BITMAP
                FROM {$table}
                WHERE (PROPERTY_ID, VALUE_ID) IN (" . implode(',', $parts) . ')
            ');
            while ($row = $res->fetch()) {
                $key = self::cacheKey((int)$row['PROPERTY_ID'], (int)$row['VALUE_ID']);
                $mask = (string)($row['BITMAP'] ?? '');
                self::$requestCache[$key] = $mask;
                $result[$key] = $mask;
                unset($missing[$key]);
            }
            foreach ($missing as $key => $_) {
                self::$requestCache[$key] = '';
                $result[$key] = '';
            }
        }

        if ($bitLength !== null) {
            foreach ($result as $key => $mask) {
                $result[$key] = BitmapOperations::ensureLength($mask, $bitLength);
            }
        }

        return $result;
    }

    public static function saveMask(int $propertyId, int $valueId, string $mask): void
    {
        BitmapIndexSchema::ensureInfrastructure();
        $table = BitmapIndexTable::getTableName();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $now = $helper->forSql((new DateTime())->toString());
        $hex = bin2hex($mask);
        $hexSql = $hex === '' ? "''" : "UNHEX('{$hex}')";

        $exists = $connection->query("
            SELECT ID FROM {$table}
            WHERE PROPERTY_ID = {$propertyId} AND VALUE_ID = {$valueId}
            LIMIT 1
        ")->fetch();

        if ($exists) {
            $connection->queryExecute("
                UPDATE {$table}
                SET BITMAP = {$hexSql}, UPDATED_AT = '{$now}'
                WHERE PROPERTY_ID = {$propertyId} AND VALUE_ID = {$valueId}
            ");
        } else {
            $connection->queryExecute("
                INSERT INTO {$table} (PROPERTY_ID, VALUE_ID, BITMAP, UPDATED_AT)
                VALUES ({$propertyId}, {$valueId}, {$hexSql}, '{$now}')
            ");
        }

        self::$requestCache[self::cacheKey($propertyId, $valueId)] = $mask;
    }

    public static function setProductBit(int $propertyId, int $valueId, int $position, bool $on, int $bitLength): void
    {
        $mask = self::loadMask($propertyId, $valueId, $bitLength);
        $mask = BitmapOperations::setBit($mask, $position, $on);
        self::saveMask($propertyId, $valueId, $mask);
    }

    public static function deleteMask(int $propertyId, int $valueId): void
    {
        BitmapIndexSchema::ensureInfrastructure();
        $table = BitmapIndexTable::getTableName();
        Application::getConnection()->queryExecute("
            DELETE FROM {$table}
            WHERE PROPERTY_ID = {$propertyId} AND VALUE_ID = {$valueId}
        ");
        unset(self::$requestCache[self::cacheKey($propertyId, $valueId)]);
    }

    public static function countMasks(): int
    {
        BitmapIndexSchema::ensureInfrastructure();

        return (int)Application::getConnection()->queryScalar(
            'SELECT COUNT(*) FROM ' . BitmapIndexTable::getTableName()
        );
    }

    public static function approxBlobBytes(): int
    {
        BitmapIndexSchema::ensureInfrastructure();
        $table = BitmapIndexTable::getTableName();
        $sum = Application::getConnection()->queryScalar(
            "SELECT COALESCE(SUM(LENGTH(BITMAP)), 0) FROM {$table}"
        );

        return (int)$sum;
    }
}
