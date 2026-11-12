<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Install\BitmapIndexSchema;
use MyApp\Catalog\Model\BitmapMembershipTable;
use MyApp\Catalog\Model\BitmapProductTable;

final class BitmapIndexValidator
{
    /**
     * @return array{ok: bool, issues: list<string>, stats: array<string, int>}
     */
    public static function validate(bool $samplePropertyParity = true): array
    {
        BitmapIndexSchema::ensureInfrastructure();
        $issues = [];
        $connection = Application::getConnection();
        $productTable = BitmapProductTable::getTableName();

        $dupPositions = (int)$connection->queryScalar("
            SELECT COUNT(*) FROM (
                SELECT BITMAP_POSITION FROM {$productTable}
                GROUP BY BITMAP_POSITION HAVING COUNT(*) > 1
            ) t
        ");
        if ($dupPositions > 0) {
            $issues[] = "Дубли BITMAP_POSITION: {$dupPositions}";
        }

        $dupProducts = (int)$connection->queryScalar("
            SELECT COUNT(*) FROM (
                SELECT PRODUCT_ID FROM {$productTable}
                GROUP BY PRODUCT_ID HAVING COUNT(*) > 1
            ) t
        ");
        if ($dupProducts > 0) {
            $issues[] = "Дубли PRODUCT_ID: {$dupProducts}";
        }

        $maxPos = BitmapIndexService::getMaxPosition();
        $bitLength = BitmapIndexService::getBitLength();
        $shortMasks = (int)$connection->queryScalar("
            SELECT COUNT(*) FROM myapp_catalog_bitmap_index
            WHERE LENGTH(BITMAP) < " . (int)ceil(max(0, $bitLength) / 8)
        );
        if ($shortMasks > 0) {
            $issues[] = "Маски короче текущего диапазона позиций: {$shortMasks}";
        }

        $iblockId = Assets::catalogIblockId();
        $activeCatalog = (int)$connection->queryScalar("
            SELECT COUNT(*) FROM b_iblock_element
            WHERE IBLOCK_ID = {$iblockId} AND ACTIVE = 'Y'
        ");
        $activeIndexed = (int)$connection->queryScalar("
            SELECT COUNT(*) FROM {$productTable} WHERE ACTIVE = 'Y'
        ");
        $missingApprox = max(0, $activeCatalog - $activeIndexed);
        if ($missingApprox > 0) {
            $issues[] = "Активных в каталоге больше, чем в bitmap (примерно не хватает {$missingApprox})";
        }

        $inactiveWithBits = 0;
        if ($samplePropertyParity) {
            $res = $connection->query("
                SELECT PRODUCT_ID, BITMAP_POSITION FROM {$productTable}
                WHERE ACTIVE = 'N'
                LIMIT 200
            ");
            while ($row = $res->fetch()) {
                $keys = [];
                $mem = BitmapMembershipTable::getList([
                    'filter' => ['=PRODUCT_ID' => (int)$row['PRODUCT_ID']],
                    'limit' => 5,
                ]);
                while ($m = $mem->fetch()) {
                    $keys[] = $m;
                }
                foreach ($keys as $key) {
                    $mask = BitmapStorage::loadMask((int)$key['PROPERTY_ID'], (int)$key['VALUE_ID']);
                    if (BitmapOperations::getBit($mask, (int)$row['BITMAP_POSITION'])) {
                        ++$inactiveWithBits;
                        break;
                    }
                }
            }
            if ($inactiveWithBits > 0) {
                $issues[] = "Неактивные товары с битами в масках (выборка): {$inactiveWithBits}";
            }
        }

        return [
            'ok' => $issues === [],
            'issues' => $issues,
            'stats' => [
                'active_catalog' => $activeCatalog,
                'active_indexed' => $activeIndexed,
                'max_position' => $maxPos,
                'masks' => BitmapStorage::countMasks(),
                'blob_bytes' => BitmapStorage::approxBlobBytes(),
                'dup_positions' => $dupPositions,
                'dup_products' => $dupProducts,
                'short_masks' => $shortMasks,
            ],
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function repair(): array
    {
        $report = self::validate(false);
        if ($report['ok']) {
            return ['ok' => true, 'message' => 'Целостность в норме, восстановление не требуется'];
        }

        try {
            $jobId = BitmapIndexService::buildFullIndex();

            return [
                'ok' => true,
                'message' => 'Запущена полная пересборка bitmap #' . $jobId . '. Проблемы: '
                    . implode('; ', $report['issues']),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
