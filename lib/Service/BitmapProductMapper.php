<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use MyApp\Catalog\Install\BitmapIndexSchema;
use MyApp\Catalog\Model\BitmapProductTable;

final class BitmapProductMapper
{
    /**
     * @param list<int> $positions
     * @return array<int, int> position => productId
     */
    public static function positionsToProductIds(array $positions): array
    {
        $positions = array_values(array_unique(array_filter(array_map('intval', $positions), static fn(int $p): bool => $p >= 0)));
        if ($positions === []) {
            return [];
        }

        BitmapIndexSchema::ensureInfrastructure();
        $table = BitmapProductTable::getTableName();
        $map = [];
        foreach (array_chunk($positions, 1000) as $chunk) {
            $in = implode(',', $chunk);
            $res = Application::getConnection()->query("
                SELECT BITMAP_POSITION, PRODUCT_ID
                FROM {$table}
                WHERE BITMAP_POSITION IN ({$in})
                  AND ACTIVE = 'Y'
            ");
            while ($row = $res->fetch()) {
                $map[(int)$row['BITMAP_POSITION']] = (int)$row['PRODUCT_ID'];
            }
        }

        return $map;
    }

    public static function getPositionByProductId(int $productId): ?int
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return null;
        }
        BitmapIndexSchema::ensureInfrastructure();
        $row = BitmapProductTable::getList([
            'select' => ['BITMAP_POSITION'],
            'filter' => ['=PRODUCT_ID' => $productId],
            'limit' => 1,
        ])->fetch();

        return is_array($row) ? (int)$row['BITMAP_POSITION'] : null;
    }

    /**
     * @param list<int> $productIds
     * @return array<int, int> productId => position
     */
    public static function productIdsToPositions(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            return [];
        }

        BitmapIndexSchema::ensureInfrastructure();
        $table = BitmapProductTable::getTableName();
        $map = [];
        foreach (array_chunk($productIds, 1000) as $chunk) {
            $in = implode(',', $chunk);
            $res = Application::getConnection()->query("
                SELECT PRODUCT_ID, BITMAP_POSITION
                FROM {$table}
                WHERE PRODUCT_ID IN ({$in})
            ");
            while ($row = $res->fetch()) {
                $map[(int)$row['PRODUCT_ID']] = (int)$row['BITMAP_POSITION'];
            }
        }

        return $map;
    }
}
