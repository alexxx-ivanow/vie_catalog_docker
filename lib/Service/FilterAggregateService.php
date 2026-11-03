<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Install\FilterAggregateSchema;
use MyApp\Catalog\Model\FilterAggregateTable;

final class FilterAggregateService
{
    /**
     * @return array{payload: array, config_hash: string, is_stale: bool}|null
     */
    public static function read(int $sectionId): ?array
    {
        $sectionId = max(0, $sectionId);
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $row = FilterAggregateTable::getByPrimary($sectionId)->fetch();
        if (!$row) {
            return null;
        }

        $payload = json_decode((string)($row['PAYLOAD_JSON'] ?? ''), true);
        if (!is_array($payload)) {
            return null;
        }

        return [
            'payload' => $payload,
            'config_hash' => (string)($row['CONFIG_HASH'] ?? ''),
            'is_stale' => ((string)($row['IS_STALE'] ?? 'N')) === 'Y',
        ];
    }

    /**
     * @param array{enabled?: bool, properties?: array, price?: array} $payload
     */
    public static function write(
        int $sectionId,
        array $payload,
        string $configHash,
        bool $isStale = false,
        ?string $lastError = null
    ): void {
        $sectionId = max(0, $sectionId);
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $json = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === '' || $json === 'null') {
            throw new \RuntimeException('Invalid filter payload JSON');
        }

        $primary = ['SECTION_ID' => $sectionId];
        $fields = [
            'CONFIG_HASH' => $configHash,
            'PAYLOAD_JSON' => $json,
            'IS_STALE' => $isStale ? 'Y' : 'N',
            'UPDATED_AT' => new DateTime(),
            'LAST_ERROR' => $lastError,
        ];

        if (FilterAggregateTable::getByPrimary($primary)->fetch()) {
            $result = FilterAggregateTable::update($primary, $fields);
        } else {
            $result = FilterAggregateTable::add(array_merge($primary, $fields));
        }

        if (!$result->isSuccess()) {
            throw new \RuntimeException(
                'Filter aggregate write failed: ' . implode('; ', $result->getErrorMessages())
            );
        }
    }

    public static function invalidateSectionPath(int $sectionId): void
    {
        $sectionId = (int)$sectionId;
        if ($sectionId <= 0) {
            return;
        }

        self::markStaleForSectionPath($sectionId);

        foreach (CatalogService::getSectionPathIds($sectionId) as $pathSectionId) {
            FilterRebuildQueueService::enqueue($pathSectionId);
        }
    }

    public static function markStaleForSectionPath(int $sectionId): void
    {
        $sectionId = (int)$sectionId;
        if ($sectionId <= 0) {
            return;
        }

        FilterAggregateSchema::ensureRebuildInfrastructure();

        foreach (CatalogService::getSectionPathIds($sectionId) as $pathSectionId) {
            self::markStale($pathSectionId);
        }
    }

    public static function markStale(int $sectionId): void
    {
        $sectionId = max(0, $sectionId);
        FilterAggregateSchema::ensureRebuildInfrastructure();

        if (!FilterAggregateTable::getByPrimary($sectionId)->fetch()) {
            return;
        }

        $result = FilterAggregateTable::update($sectionId, ['IS_STALE' => 'Y']);
        if (!$result->isSuccess()) {
            throw new \RuntimeException(
                'Filter aggregate markStale failed: ' . implode('; ', $result->getErrorMessages())
            );
        }
    }

    public static function markAllStale(): void
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();

        Application::getConnection()->queryExecute(
            'UPDATE ' . FilterAggregateTable::getTableName() . " SET IS_STALE = 'Y'"
        );
    }

    public static function recordBuildError(int $sectionId, string $message): void
    {
        $sectionId = max(0, $sectionId);
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $error = mb_substr(trim($message), 0, 65000);
        if (!FilterAggregateTable::getByPrimary($sectionId)->fetch()) {
            return;
        }

        FilterAggregateTable::update($sectionId, [
            'IS_STALE' => 'Y',
            'LAST_ERROR' => $error,
        ]);
    }

    public static function clearAll(): void
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();

        Application::getConnection()->truncateTable(FilterAggregateTable::getTableName());
    }

    public static function rebuildAndPersist(int $sectionId): void
    {
        self::rebuildAndPersistSafe($sectionId);
    }

    public static function rebuildAndPersistSafe(int $sectionId): void
    {
        $sectionId = max(0, $sectionId);

        try {
            $payload = CatalogService::buildFiltersPayload($sectionId);
            self::write($sectionId, $payload, CatalogService::filtersConfigHash(), false, null);
        } catch (\Throwable $e) {
            self::recordBuildError($sectionId, $e->getMessage());
            throw $e;
        }
    }

    public static function scheduleRebuild(int $sectionId): void
    {
        $sectionId = (int)$sectionId;
        if ($sectionId < 0) {
            return;
        }

        FilterRebuildQueueService::enqueue($sectionId);
    }

    /**
     * Прогрев snapshot (агент / ручной вызов после импорта).
     */
    public static function warmSection(int $sectionId): void
    {
        self::rebuildAndPersistSafe($sectionId);
    }
}
