<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Install\CatalogSearchIndexSchema;
use MyApp\Catalog\Model\SearchIndexQueueTable;

final class CatalogSearchIndexQueueService
{
    public static function enqueue(int $productId, int $jobId = 0): void
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return;
        }

        CatalogSearchIndexSchema::ensureInfrastructure();

        if (SearchIndexQueueTable::getByPrimary($productId)->fetch()) {
            return;
        }

        SearchIndexQueueTable::add([
            'PRODUCT_ID' => $productId,
            'JOB_ID' => max(0, $jobId),
            'CREATED_AT' => new DateTime(),
        ]);
    }

    /**
     * @param list<int> $productIds
     */
    public static function enqueueManyBulk(array $productIds, int $jobId = 0): int
    {
        CatalogSearchIndexSchema::ensureInfrastructure();

        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        $productIds = array_values(array_filter($productIds, static fn(int $id): bool => $id > 0));
        if ($productIds === []) {
            return 0;
        }

        $table = SearchIndexQueueTable::getTableName();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $now = $helper->forSql((new DateTime())->toString());
        $jobId = max(0, $jobId);

        $added = 0;
        foreach (array_chunk($productIds, 500) as $chunk) {
            $values = [];
            foreach ($chunk as $productId) {
                $values[] = '(' . $productId . ', ' . $jobId . ", '" . $now . "')";
            }
            if ($values === []) {
                continue;
            }
            $connection->queryExecute(
                'INSERT IGNORE INTO ' . $table . ' (PRODUCT_ID, JOB_ID, CREATED_AT) VALUES '
                . implode(', ', $values)
            );
            $added += count($chunk);
        }

        return $added;
    }

    /**
     * @return list<int>
     */
    public static function dequeueBatch(int $limit): array
    {
        $limit = max(1, min(120, $limit));
        CatalogSearchIndexSchema::ensureInfrastructure();

        $res = SearchIndexQueueTable::getList([
            'select' => ['PRODUCT_ID'],
            'order' => ['CREATED_AT' => 'ASC'],
            'limit' => $limit,
        ]);

        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['PRODUCT_ID'];
        }

        return $ids;
    }

    public static function remove(int $productId): void
    {
        CatalogSearchIndexSchema::ensureInfrastructure();
        SearchIndexQueueTable::delete($productId);
    }

    public static function count(): int
    {
        CatalogSearchIndexSchema::ensureInfrastructure();

        return (int)SearchIndexQueueTable::getCount();
    }

    public static function clear(): void
    {
        CatalogSearchIndexSchema::ensureInfrastructure();
        Application::getConnection()->truncateTable(SearchIndexQueueTable::getTableName());
    }
}
