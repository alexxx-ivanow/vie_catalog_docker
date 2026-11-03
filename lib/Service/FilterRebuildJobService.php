<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Install\FilterAggregateSchema;
use MyApp\Catalog\Model\FilterAggregateTable;
use MyApp\Catalog\Model\FilterRebuildJobTable;

final class FilterRebuildJobService
{
    public const TYPE_FILL_ALL = 'fill_all';

    public const TYPE_REBUILD_ALL = 'rebuild_all';

    public const TYPE_REBUILD_STALE = 'rebuild_stale';

    public const TYPE_SECTION = 'section';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    private const MODULE_ID = 'myapp.catalog';

    private const OPT_AGENT_REGISTERED = 'filter_rebuild_agent_registered';

    private const AGENT_FUNCTION = '\\MyApp\\Catalog\\Service\\FilterRebuildJobService::runAgentStep();';

    private const ENQUEUE_CHUNK_SIZE = 500;

    public static function batchSize(): int
    {
        $size = (int)Option::get(self::MODULE_ID, Assets::OPT_FILTER_REBUILD_BATCH_SIZE, '10');

        return $size > 0 ? min(50, $size) : 10;
    }

    public static function agentIntervalSeconds(): int
    {
        return 90;
    }

    public static function ensureAgentRegistered(): void
    {
        if (!class_exists(\CAgent::class)) {
            return;
        }

        if (Option::get(self::MODULE_ID, self::OPT_AGENT_REGISTERED, 'N') === 'Y') {
            return;
        }

        \CAgent::AddAgent(
            self::AGENT_FUNCTION,
            self::MODULE_ID,
            'N',
            self::agentIntervalSeconds(),
            '',
            'Y',
            '',
            30
        );

        Option::set(self::MODULE_ID, self::OPT_AGENT_REGISTERED, 'Y');
    }

    public static function uninstallAgent(): void
    {
        if (!class_exists(\CAgent::class)) {
            return;
        }

        \CAgent::RemoveAgent(self::AGENT_FUNCTION, self::MODULE_ID);
        Option::delete(self::MODULE_ID, ['name' => self::OPT_AGENT_REGISTERED]);
    }

    public static function runAgentStep(): string
    {
        if (!\Bitrix\Main\Loader::includeModule(self::MODULE_ID)) {
            return self::AGENT_FUNCTION;
        }

        try {
            self::runStep(self::batchSize());
        } catch (\Throwable $e) {
            self::log('Agent step failed: ' . $e->getMessage());
        }

        return self::AGENT_FUNCTION;
    }

    public static function runStep(int $batchSize): int
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $job = self::getRunningJob();
        if ($job !== null && ($job['STATUS'] ?? '') === self::STATUS_CANCELLED) {
            return 0;
        }

        if ($job !== null && FilterRebuildQueueService::count() === 0) {
            self::fillQueueFromCursor((int)$job['ID'], self::ENQUEUE_CHUNK_SIZE);
        }

        $sectionIds = FilterRebuildQueueService::dequeueBatch($batchSize);
        if ($sectionIds === []) {
            if ($job !== null) {
                self::tryCompleteJob((int)$job['ID']);
            }

            return 0;
        }

        $processed = 0;
        foreach ($sectionIds as $sectionId) {
            $job = self::getRunningJob();
            if ($job !== null && ($job['STATUS'] ?? '') === self::STATUS_CANCELLED) {
                break;
            }

            if ($job !== null) {
                FilterRebuildJobTable::update((int)$job['ID'], [
                    'CURRENT_SECTION_ID' => $sectionId,
                ]);
            }

            try {
                FilterAggregateService::rebuildAndPersistSafe($sectionId);
                FilterRebuildQueueService::remove($sectionId);
                ++$processed;

                if ($job !== null) {
                    self::incrementJobProgress((int)$job['ID'], false);
                }
            } catch (\Throwable $e) {
                self::log("Section {$sectionId}: " . $e->getMessage());
                FilterRebuildQueueService::remove($sectionId);

                if ($job !== null) {
                    self::incrementJobProgress((int)$job['ID'], true);
                    FilterRebuildJobTable::update((int)$job['ID'], [
                        'LAST_ERROR' => mb_substr($e->getMessage(), 0, 2000),
                    ]);
                }
            }
        }

        if ($job !== null) {
            self::tryCompleteJob((int)$job['ID']);
        }

        return $processed;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function startJob(string $type, array $params = []): int
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $running = self::getRunningJob();
        if ($running !== null) {
            throw new \RuntimeException('Уже выполняется задача #' . (int)$running['ID']);
        }

        $usesLazyEnqueue = self::usesLazyEnqueue($type);
        $jobParams = $params;
        if ($usesLazyEnqueue) {
            $jobParams['enqueue_cursor'] = 0;
            $jobParams['enqueue_done'] = false;
            $jobParams['enqueue_mode'] = self::enqueueModeForType($type);
            $total = self::countTotalForJobType($type);
            $sectionIds = [];
            if ($total === 0) {
                $jobParams['enqueue_done'] = true;
            }
        } else {
            $sectionIds = self::resolveSectionIdsForJob($type, $params);
            $total = count($sectionIds);
        }

        $result = FilterRebuildJobTable::add([
            'TYPE' => $type,
            'STATUS' => self::STATUS_RUNNING,
            'TOTAL' => $total,
            'PROCESSED' => 0,
            'ERROR_COUNT' => 0,
            'CURRENT_SECTION_ID' => 0,
            'PARAMS_JSON' => (string)json_encode($jobParams, JSON_UNESCAPED_UNICODE),
            'STARTED_AT' => new DateTime(),
            'CREATED_AT' => new DateTime(),
        ]);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        $jobId = (int)$result->getId();

        if (!$usesLazyEnqueue && $sectionIds !== []) {
            FilterRebuildQueueService::enqueueManyBulk($sectionIds, $jobId);
        }

        if ($total === 0) {
            FilterRebuildJobTable::update($jobId, [
                'STATUS' => self::STATUS_COMPLETED,
                'FINISHED_AT' => new DateTime(),
            ]);
        }

        return $jobId;
    }

    public static function cancelRunningJob(): bool
    {
        $job = self::getRunningJob();
        if ($job === null) {
            return false;
        }

        FilterRebuildJobTable::update((int)$job['ID'], [
            'STATUS' => self::STATUS_CANCELLED,
            'FINISHED_AT' => new DateTime(),
        ]);

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getRunningJob(): ?array
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $row = FilterRebuildJobTable::getList([
            'filter' => ['=STATUS' => self::STATUS_RUNNING],
            'order' => ['ID' => 'DESC'],
            'limit' => 1,
        ])->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getProgressPayload(): array
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $job = self::getRunningJob();
        if ($job === null) {
            $last = FilterRebuildJobTable::getList([
                'order' => ['ID' => 'DESC'],
                'limit' => 1,
            ])->fetch();

            return self::buildProgressFromJob(is_array($last) ? $last : null);
        }

        return self::buildProgressFromJob($job);
    }

    public static function fillQueueFromCursor(int $jobId, int $chunkSize): int
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $row = FilterRebuildJobTable::getByPrimary($jobId)->fetch();
        if (!$row || ($row['STATUS'] ?? '') !== self::STATUS_RUNNING) {
            return 0;
        }

        $type = (string)($row['TYPE'] ?? '');
        if (!self::usesLazyEnqueue($type)) {
            return 0;
        }

        $params = self::decodeJobParams($row);
        if (!empty($params['enqueue_done'])) {
            return 0;
        }

        $cursor = max(0, (int)($params['enqueue_cursor'] ?? 0));
        $chunkSize = max(1, min(2000, $chunkSize));
        $mode = (string)($params['enqueue_mode'] ?? self::enqueueModeForType($type));
        $connection = Application::getConnection();

        if ($mode === 'stale') {
            $table = FilterAggregateTable::getTableName();
            $res = $connection->query("
                SELECT SECTION_ID FROM {$table}
                WHERE IS_STALE = 'Y' AND SECTION_ID > {$cursor}
                ORDER BY SECTION_ID ASC
                LIMIT {$chunkSize}
            ");
        } else {
            $iblockId = Assets::catalogIblockId();
            $res = $connection->query("
                SELECT ID FROM b_iblock_section
                WHERE IBLOCK_ID = {$iblockId} AND ACTIVE = 'Y' AND ID > {$cursor}
                ORDER BY ID ASC
                LIMIT {$chunkSize}
            ");
        }

        $ids = [];
        $lastId = $cursor;
        while ($sectionRow = $res->fetch()) {
            $lastId = (int)($sectionRow['SECTION_ID'] ?? $sectionRow['ID'] ?? 0);
            if ($lastId > 0) {
                $ids[] = $lastId;
            }
        }

        if ($ids === []) {
            $params['enqueue_done'] = true;
            $params['enqueue_cursor'] = $cursor;
            self::saveJobParams($jobId, $params);

            return 0;
        }

        FilterRebuildQueueService::enqueueManyBulk($ids, $jobId);
        $params['enqueue_cursor'] = $lastId;
        self::saveJobParams($jobId, $params);

        return count($ids);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getStatistics(): array
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $connection = \Bitrix\Main\Application::getConnection();
        $iblockId = Assets::catalogIblockId();

        $sectionsTotal = (int)$connection->queryScalar("
            SELECT COUNT(*) FROM b_iblock_section
            WHERE IBLOCK_ID = {$iblockId} AND ACTIVE = 'Y'
        ");

        $snapshotsTotal = (int)FilterAggregateTable::getCount();
        $staleTotal = (int)FilterAggregateTable::getCount(['=IS_STALE' => 'Y']);
        $freshTotal = max(0, $snapshotsTotal - $staleTotal);
        $errorsTotal = (int)$connection->queryScalar("
            SELECT COUNT(*) FROM " . FilterAggregateTable::getTableName() . "
            WHERE LAST_ERROR IS NOT NULL AND LAST_ERROR <> ''
        ");

        $lastFill = FilterRebuildJobTable::getList([
            'filter' => [
                '=TYPE' => self::TYPE_FILL_ALL,
                '=STATUS' => self::STATUS_COMPLETED,
            ],
            'order' => ['FINISHED_AT' => 'DESC'],
            'limit' => 1,
        ])->fetch();

        return [
            'sections_total' => $sectionsTotal,
            'snapshots_total' => $snapshotsTotal,
            'snapshots_fresh' => $freshTotal,
            'snapshots_stale' => $staleTotal,
            'queue_size' => FilterRebuildQueueService::count(),
            'errors_total' => $errorsTotal,
            'running_job' => self::getRunningJob(),
            'last_fill_completed_at' => $lastFill['FINISHED_AT'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getRecentErrors(int $limit = 20): array
    {
        FilterAggregateSchema::ensureRebuildInfrastructure();

        $connection = Application::getConnection();
        $table = FilterAggregateTable::getTableName();
        $limit = max(1, min(100, $limit));
        $res = $connection->query("
            SELECT SECTION_ID, LAST_ERROR, UPDATED_AT
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

    public static function clearSnapshotsAndQueue(): void
    {
        FilterAggregateService::clearAll();
        FilterRebuildQueueService::clear();
    }

    /**
     * @param array<string, mixed> $params
     * @return list<int>
     */
    private static function resolveSectionIdsForJob(string $type, array $params): array
    {
        return match ($type) {
            self::TYPE_SECTION => self::resolveSingleSectionId($params),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return list<int>
     */
    private static function resolveSingleSectionId(array $params): array
    {
        if (!array_key_exists('section_id', $params)) {
            return [];
        }

        return [max(0, (int)$params['section_id'])];
    }

    private static function usesLazyEnqueue(string $type): bool
    {
        return $type === self::TYPE_FILL_ALL
            || $type === self::TYPE_REBUILD_ALL
            || $type === self::TYPE_REBUILD_STALE;
    }

    private static function enqueueModeForType(string $type): string
    {
        return $type === self::TYPE_REBUILD_STALE ? 'stale' : 'catalog_sections';
    }

    private static function countTotalForJobType(string $type): int
    {
        return match ($type) {
            self::TYPE_FILL_ALL, self::TYPE_REBUILD_ALL => CatalogService::countActiveCatalogSections(),
            self::TYPE_REBUILD_STALE => (int)FilterAggregateTable::getCount(['=IS_STALE' => 'Y']),
            default => 0,
        };
    }

    /**
     * @param array<string, mixed>|null $job
     * @return array<string, mixed>
     */
    private static function buildProgressFromJob(?array $job): array
    {
        if ($job === null) {
            return [
                'active' => false,
                'status' => '',
                'type' => '',
                'processed' => 0,
                'total' => 0,
                'percent' => 0.0,
                'queue_size' => FilterRebuildQueueService::count(),
                'error_count' => 0,
                'current_section_id' => 0,
                'last_error' => '',
                'finished' => true,
            ];
        }

        $status = (string)($job['STATUS'] ?? '');
        $processed = (int)($job['PROCESSED'] ?? 0);
        $total = max(0, (int)($job['TOTAL'] ?? 0));
        $percent = $total > 0 ? round(min(100, ($processed / $total) * 100), 1) : ($status === self::STATUS_COMPLETED ? 100.0 : 0.0);
        $running = $status === self::STATUS_RUNNING;

        return [
            'active' => $running,
            'job_id' => (int)($job['ID'] ?? 0),
            'status' => $status,
            'type' => (string)($job['TYPE'] ?? ''),
            'processed' => $processed,
            'total' => $total,
            'percent' => $percent,
            'queue_size' => FilterRebuildQueueService::count(),
            'error_count' => (int)($job['ERROR_COUNT'] ?? 0),
            'current_section_id' => (int)($job['CURRENT_SECTION_ID'] ?? 0),
            'last_error' => (string)($job['LAST_ERROR'] ?? ''),
            'finished' => in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decodeJobParams(array $row): array
    {
        $raw = (string)($row['PARAMS_JSON'] ?? '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function saveJobParams(int $jobId, array $params): void
    {
        FilterRebuildJobTable::update($jobId, [
            'PARAMS_JSON' => (string)json_encode($params, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isEnqueueDone(array $row): bool
    {
        if (!self::usesLazyEnqueue((string)($row['TYPE'] ?? ''))) {
            return true;
        }

        $params = self::decodeJobParams($row);

        return !empty($params['enqueue_done']);
    }

    /**
     * @return list<int>
     */
    private static function collectStaleSectionIds(): array
    {
        $ids = [];
        $res = FilterAggregateTable::getList([
            'select' => ['SECTION_ID'],
            'filter' => ['=IS_STALE' => 'Y'],
        ]);
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['SECTION_ID'];
        }

        $queueRes = \MyApp\Catalog\Model\FilterRebuildQueueTable::getList([
            'select' => ['SECTION_ID'],
        ]);
        while ($row = $queueRes->fetch()) {
            $ids[] = (int)$row['SECTION_ID'];
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private static function incrementJobProgress(int $jobId, bool $isError): void
    {
        $row = FilterRebuildJobTable::getByPrimary($jobId)->fetch();
        if (!$row) {
            return;
        }

        FilterRebuildJobTable::update($jobId, [
            'PROCESSED' => (int)$row['PROCESSED'] + 1,
            'ERROR_COUNT' => (int)$row['ERROR_COUNT'] + ($isError ? 1 : 0),
        ]);
    }

    private static function tryCompleteJob(int $jobId): void
    {
        $row = FilterRebuildJobTable::getByPrimary($jobId)->fetch();
        if (!$row || ($row['STATUS'] ?? '') !== self::STATUS_RUNNING) {
            return;
        }

        if (FilterRebuildQueueService::count() > 0) {
            return;
        }

        if (!self::isEnqueueDone($row)) {
            return;
        }

        $processed = (int)$row['PROCESSED'];
        $total = (int)$row['TOTAL'];
        $errors = (int)$row['ERROR_COUNT'];

        FilterRebuildJobTable::update($jobId, [
            'STATUS' => $errors > 0 && $processed === 0 ? self::STATUS_FAILED : self::STATUS_COMPLETED,
            'FINISHED_AT' => new DateTime(),
            'CURRENT_SECTION_ID' => 0,
        ]);
    }

    private static function log(string $message): void
    {
        $dir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $line = date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
        @file_put_contents($dir . '/filter_rebuild.log', $line, FILE_APPEND | LOCK_EX);
    }
}
