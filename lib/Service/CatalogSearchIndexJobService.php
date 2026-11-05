<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Install\CatalogSearchIndexSchema;
use MyApp\Catalog\Model\SearchIndexJobTable;

final class CatalogSearchIndexJobService
{
    public const TYPE_FULL = 'full';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    private const MODULE_ID = 'myapp.catalog';

    private const OPT_AGENT_REGISTERED = 'catalog_search_index_agent_registered';

    private const AGENT_FUNCTION = '\\MyApp\\Catalog\\Service\\CatalogSearchIndexJobService::runAgentStep();';

    private const ENQUEUE_CHUNK_SIZE = 800;

    public static function batchSize(): int
    {
        $size = (int)Option::get(self::MODULE_ID, Assets::OPT_CATALOG_SEARCH_INDEX_BATCH_SIZE, '20');

        return $size > 0 ? min(100, $size) : 20;
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
            45
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
        if (!Loader::includeModule(self::MODULE_ID)) {
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
        CatalogSearchIndexSchema::ensureInfrastructure();

        $job = self::getRunningJob();
        if ($job !== null && ($job['STATUS'] ?? '') === self::STATUS_CANCELLED) {
            return 0;
        }

        if ($job !== null && CatalogSearchIndexQueueService::count() === 0) {
            self::fillQueueFromCursor((int)$job['ID'], self::ENQUEUE_CHUNK_SIZE);
        }

        $productIds = CatalogSearchIndexQueueService::dequeueBatch($batchSize);
        if ($productIds === []) {
            if ($job !== null) {
                self::tryCompleteJob((int)$job['ID']);
            }

            return 0;
        }

        $processed = 0;
        foreach ($productIds as $productId) {
            $job = self::getRunningJob();
            if ($job !== null && ($job['STATUS'] ?? '') === self::STATUS_CANCELLED) {
                break;
            }

            if ($job !== null) {
                SearchIndexJobTable::update((int)$job['ID'], [
                    'CURRENT_PRODUCT_ID' => $productId,
                ]);
            }

            try {
                CatalogSearchIndexService::rebuildProduct($productId);
                CatalogSearchIndexQueueService::remove($productId);
                ++$processed;
                if ($job !== null) {
                    self::incrementJobProgress((int)$job['ID'], false);
                }
            } catch (\Throwable $e) {
                self::log("Product {$productId}: " . $e->getMessage());
                CatalogSearchIndexQueueService::remove($productId);
                if ($job !== null) {
                    self::incrementJobProgress((int)$job['ID'], true);
                    SearchIndexJobTable::update((int)$job['ID'], [
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
        CatalogSearchIndexSchema::ensureInfrastructure();

        $running = self::getRunningJob();
        if ($running !== null) {
            throw new \RuntimeException('Уже выполняется задача #' . (int)$running['ID']);
        }

        if ($type === self::TYPE_FULL) {
            CatalogSearchIndexService::markNotReady();
            CatalogSearchIndexSchema::truncateIndexData();
            CatalogSearchIndexQueueService::clear();
        } else {
            throw new \InvalidArgumentException('Unsupported job type: ' . $type);
        }

        $jobParams = $params;
        $jobParams['enqueue_cursor'] = 0;
        $jobParams['enqueue_done'] = false;
        $total = CatalogSearchIndexService::countCatalogProducts();
        if ($total === 0) {
            $jobParams['enqueue_done'] = true;
        }

        $result = SearchIndexJobTable::add([
            'TYPE' => $type,
            'STATUS' => self::STATUS_RUNNING,
            'TOTAL' => $total,
            'PROCESSED' => 0,
            'ERROR_COUNT' => 0,
            'CURRENT_PRODUCT_ID' => 0,
            'PARAMS_JSON' => (string)json_encode($jobParams, JSON_UNESCAPED_UNICODE),
            'STARTED_AT' => new DateTime(),
            'CREATED_AT' => new DateTime(),
        ]);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        $jobId = (int)$result->getId();

        if ($total === 0) {
            SearchIndexJobTable::update($jobId, [
                'STATUS' => self::STATUS_COMPLETED,
                'FINISHED_AT' => new DateTime(),
            ]);
            CatalogSearchIndexService::finalizeFullBuild();
        }

        return $jobId;
    }

    public static function cancelRunningJob(): bool
    {
        $job = self::getRunningJob();
        if ($job === null) {
            return false;
        }

        SearchIndexJobTable::update((int)$job['ID'], [
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
        CatalogSearchIndexSchema::ensureInfrastructure();

        $row = SearchIndexJobTable::getList([
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
        CatalogSearchIndexSchema::ensureInfrastructure();

        $job = self::getRunningJob();
        if ($job === null) {
            $last = SearchIndexJobTable::getList([
                'order' => ['ID' => 'DESC'],
                'limit' => 1,
            ])->fetch();

            return self::buildProgressFromJob(is_array($last) ? $last : null);
        }

        return self::buildProgressFromJob($job);
    }

    public static function fillQueueFromCursor(int $jobId, int $chunkSize): int
    {
        CatalogSearchIndexSchema::ensureInfrastructure();
        Loader::includeModule('iblock');

        $row = SearchIndexJobTable::getByPrimary($jobId)->fetch();
        if (!$row || ($row['STATUS'] ?? '') !== self::STATUS_RUNNING) {
            return 0;
        }

        $params = self::decodeJobParams($row);
        if (!empty($params['enqueue_done'])) {
            return 0;
        }

        $cursor = max(0, (int)($params['enqueue_cursor'] ?? 0));
        $chunkSize = max(1, min(2000, $chunkSize));
        $iblockId = Assets::catalogIblockId();
        $res = Application::getConnection()->query("
            SELECT ID FROM b_iblock_element
            WHERE IBLOCK_ID = {$iblockId} AND ACTIVE = 'Y' AND ID > {$cursor}
            ORDER BY ID ASC
            LIMIT {$chunkSize}
        ");

        $ids = [];
        $lastId = $cursor;
        while ($productRow = $res->fetch()) {
            $lastId = (int)$productRow['ID'];
            $ids[] = $lastId;
        }

        if ($ids === []) {
            $params['enqueue_done'] = true;
            $params['enqueue_cursor'] = $cursor;
            self::saveJobParams($jobId, $params);

            return 0;
        }

        CatalogSearchIndexQueueService::enqueueManyBulk($ids, $jobId);
        $params['enqueue_cursor'] = $lastId;
        self::saveJobParams($jobId, $params);

        return count($ids);
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
                'queue_size' => CatalogSearchIndexQueueService::count(),
                'error_count' => 0,
                'current_product_id' => 0,
                'last_error' => '',
                'finished' => true,
            ];
        }

        $status = (string)($job['STATUS'] ?? '');
        $processed = (int)($job['PROCESSED'] ?? 0);
        $total = max(0, (int)($job['TOTAL'] ?? 0));
        $percent = $total > 0
            ? round(min(100, ($processed / $total) * 100), 1)
            : ($status === self::STATUS_COMPLETED ? 100.0 : 0.0);
        $running = $status === self::STATUS_RUNNING;

        return [
            'active' => $running,
            'job_id' => (int)($job['ID'] ?? 0),
            'status' => $status,
            'type' => (string)($job['TYPE'] ?? ''),
            'processed' => $processed,
            'total' => $total,
            'percent' => $percent,
            'queue_size' => CatalogSearchIndexQueueService::count(),
            'error_count' => (int)($job['ERROR_COUNT'] ?? 0),
            'current_product_id' => (int)($job['CURRENT_PRODUCT_ID'] ?? 0),
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
        SearchIndexJobTable::update($jobId, [
            'PARAMS_JSON' => (string)json_encode($params, JSON_UNESCAPED_UNICODE),
        ]);
    }

    private static function isEnqueueDone(array $row): bool
    {
        $params = self::decodeJobParams($row);

        return !empty($params['enqueue_done']);
    }

    private static function incrementJobProgress(int $jobId, bool $isError): void
    {
        $row = SearchIndexJobTable::getByPrimary($jobId)->fetch();
        if (!$row) {
            return;
        }

        SearchIndexJobTable::update($jobId, [
            'PROCESSED' => (int)$row['PROCESSED'] + 1,
            'ERROR_COUNT' => (int)$row['ERROR_COUNT'] + ($isError ? 1 : 0),
        ]);
    }

    private static function tryCompleteJob(int $jobId): void
    {
        $row = SearchIndexJobTable::getByPrimary($jobId)->fetch();
        if (!$row || ($row['STATUS'] ?? '') !== self::STATUS_RUNNING) {
            return;
        }

        if (CatalogSearchIndexQueueService::count() > 0) {
            return;
        }

        if (!self::isEnqueueDone($row)) {
            return;
        }

        $processed = (int)$row['PROCESSED'];
        $errors = (int)$row['ERROR_COUNT'];

        $allFailed = $processed > 0 && $errors >= $processed;
        $failed = ($errors > 0 && $processed === 0) || $allFailed;

        SearchIndexJobTable::update($jobId, [
            'STATUS' => $failed ? self::STATUS_FAILED : self::STATUS_COMPLETED,
            'FINISHED_AT' => new DateTime(),
            'CURRENT_PRODUCT_ID' => 0,
        ]);

        if ($failed) {
            CatalogSearchIndexService::refreshMetaCounts();
            CatalogSearchIndexService::markNotReady();
            CatalogSearchIndexService::setLastError(
                $allFailed
                    ? 'Построение завершилось с ошибками на всех товарах: ' . $errors
                    : 'Построение не выполнено'
            );
        } else {
            $message = $errors > 0 ? 'Построение завершено с ошибками: ' . $errors : null;
            CatalogSearchIndexService::finalizeFullBuild($message);
        }
    }

    private static function log(string $message): void
    {
        $dir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $dir . '/catalog_search_index.log',
            date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
