<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Install\FilterAggregateSchema;
use MyApp\Catalog\Model\FilterRebuildQueueTable;

final class FilterRebuildQueueService
{
    public static function enqueue(int $sectionId, int $jobId = 0): void
    {
        $sectionId = (int)$sectionId;
        if ($sectionId < 0) {
            return;
        }

        FilterAggregateSchema::ensureRebuildInfrastructure();

        if (FilterRebuildQueueTable::getByPrimary($sectionId)->fetch()) {
            return;
        }

        FilterRebuildQueueTable::add([
            'SECTION_ID' => $sectionId,
            'JOB_ID' => max(0, $jobId),
            'CREATED_AT' => new DateTime(),
        ]);
    }

    /**
     * @param list<int> $sectionIds
     */
    public static function enqueueMany(array $sectionIds, int $jobId = 0): int
    {
        if ($sectionIds === []) {
            return 0;
        }

        return self::enqueueManyBulk($sectionIds, $jobId);
    }

    /**
     * @param list<int> $sectionIds
     */
    public static function enqueueManyBulk(array $sectionIds, int $jobId = 0): int
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $sectionIds = array_values(array_unique(array_map('intval', $sectionIds)));
        $sectionIds = array_values(array_filter($sectionIds, static fn(int $id): bool => $id >= 0));
        if ($sectionIds === []) {
            return 0;
        }

        $table = FilterRebuildQueueTable::getTableName();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $now = $helper->forSql((new DateTime())->toString());
        $jobId = max(0, $jobId);

        $added = 0;
        foreach (array_chunk($sectionIds, 500) as $chunk) {
            $values = [];
            foreach ($chunk as $sectionId) {
                $values[] = '(' . $sectionId . ', ' . $jobId . ", '" . $now . "')";
            }
            if ($values === []) {
                continue;
            }
            $connection->queryExecute(
                'INSERT IGNORE INTO ' . $table . ' (SECTION_ID, JOB_ID, CREATED_AT) VALUES '
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
        $limit = max(1, min(100, $limit));
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $res = FilterRebuildQueueTable::getList([
            'select' => ['SECTION_ID'],
            'order' => ['CREATED_AT' => 'ASC'],
            'limit' => $limit,
        ]);

        $ids = [];
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['SECTION_ID'];
        }

        return $ids;
    }

    public static function remove(int $sectionId): void
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();
        FilterRebuildQueueTable::delete($sectionId);
    }

    public static function count(): int
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();

        return (int)FilterRebuildQueueTable::getCount();
    }

    public static function clear(): void
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();
        Application::getConnection()->truncateTable(FilterRebuildQueueTable::getTableName());
    }
}
