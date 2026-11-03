<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Install\ProductFilterIndexSchema;
use MyApp\Catalog\Model\FilterIndexJobTable;

final class ProductFilterIndexJobService
{
    public const TYPE_FULL = 'full';

    public const TYPE_SYNC = 'sync';

    public const TYPE_PRODUCT = 'product';

    public const TYPE_SECTION = 'section';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    private const MODULE_ID = 'myapp.catalog';

    private const OPT_AGENT_REGISTERED = 'product_filter_index_agent_registered';

    private const AGENT_FUNCTION = '\\MyApp\\Catalog\\Service\\ProductFilterIndexJobService::runAgentStep();';

    private const ENQUEUE_CHUNK_SIZE = 800;

    public static function batchSize(): int
    {
        $size = (int)Option::get(self::MODULE_ID, Assets::OPT_PRODUCT_FILTER_INDEX_BATCH_SIZE, '20');

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
        ProductFilterIndexSchema::ensureInfrastructure();

        $job = self::getRunningJob();
        if ($job !== null && ($job['STATUS'] ?? '') === self::STATUS_CANCELLED) {
            return 0;
        }

        if ($job !== null && ProductFilterIndexQueueService::count() === 0) {
            self::fillQueueFromCursor((int)$job['ID'], self::ENQUEUE_CHUNK_SIZE);
        }

        $productIds = ProductFilterIndexQueueService::dequeueBatch($batchSize);
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
                FilterIndexJobTable::update((int)$job['ID'], [
                    'CURRENT_PRODUCT_ID' => $productId,
                ]);
            }

            try {
                ProductFilterIndexService::rebuildProduct($productId);
                ProductFilterIndexQueueService::remove($productId);
                ++$processed;
                if ($job !== null) {
                    self::incrementJobProgress((int)$job['ID'], false);
                }
            } catch (\Throwable $e) {
                self::log("Product {$productId}: " . $e->getMessage());
                ProductFilterIndexQueueService::remove($productId);
                if ($job !== null) {
                    self::incrementJobProgress((int)$job['ID'], true);
                    FilterIndexJobTable::update((int)$job['ID'], [
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
        ProductFilterIndexSchema::ensureInfrastructure();

        $running = self::getRunningJob();
        if ($running !== null) {
            throw new \RuntimeException('Уже выполняется задача #' . (int)$running['ID']);
        }

        if ($type === self::TYPE_FULL) {
            ProductFilterIndexService::markNotReady();
            ProductFilterIndexSchema::truncateIndexData();
            ProductFilterIndexQueueService::clear();
        }

        $usesLazyEnqueue = self::usesLazyEnqueue($type);
        $jobParams = $params;
        if ($usesLazyEnqueue) {
            $jobParams['enqueue_cursor'] = 0;
            $jobParams['enqueue_done'] = false;
            if ($type === self::TYPE_SECTION) {
                $sectionId = max(0, (int)($params['section_id'] ?? 0));
                if ($sectionId <= 0) {
                    throw new \RuntimeException('Укажите ID раздела больше 0');
                }
                if (CatalogService::getSectionBounds($sectionId) === null) {
                    throw new \RuntimeException('Раздел #' . $sectionId . ' не найден в каталоге');
                }
                $jobParams['section_id'] = $sectionId;
                $total = ProductFilterIndexService::countProductsInSection($sectionId);
            } else {
                $total = ProductFilterIndexService::countCatalogProducts();
            }
            $productIds = [];
            if ($total === 0) {
                $jobParams['enqueue_done'] = true;
            }
        } else {
            $productIds = self::resolveProductIdsForJob($type, $params);
            $total = count($productIds);
        }

        $result = FilterIndexJobTable::add([
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

        if (!$usesLazyEnqueue && $productIds !== []) {
            ProductFilterIndexQueueService::enqueueManyBulk($productIds, $jobId);
        }

        if ($total === 0) {
            FilterIndexJobTable::update($jobId, [
                'STATUS' => self::STATUS_COMPLETED,
                'FINISHED_AT' => new DateTime(),
            ]);
            if ($type === self::TYPE_FULL) {
                ProductFilterIndexService::finalizeFullBuild();
            } elseif ($type === self::TYPE_SYNC) {
                ProductFilterIndexService::finalizeSyncJob();
            } elseif ($type === self::TYPE_SECTION) {
                $sectionId = max(0, (int)($jobParams['section_id'] ?? 0));
                ProductFilterIndexService::finalizeSectionJob($sectionId);
            }
        }

        return $jobId;
    }

    public static function cancelRunningJob(): bool
    {
        $job = self::getRunningJob();
        if ($job === null) {
            return false;
        }

        FilterIndexJobTable::update((int)$job['ID'], [
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
        ProductFilterIndexSchema::ensureInfrastructure();

        $row = FilterIndexJobTable::getList([
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
        ProductFilterIndexSchema::ensureInfrastructure();

        $job = self::getRunningJob();
        if ($job === null) {
            $last = FilterIndexJobTable::getList([
                'order' => ['ID' => 'DESC'],
                'limit' => 1,
            ])->fetch();

            return self::buildProgressFromJob(is_array($last) ? $last : null);
        }

        return self::buildProgressFromJob($job);
    }

    public static function fillQueueFromCursor(int $jobId, int $chunkSize): int
    {
        ProductFilterIndexSchema::ensureInfrastructure();
        Loader::includeModule('iblock');

        $row = FilterIndexJobTable::getByPrimary($jobId)->fetch();
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

        if ($type === self::TYPE_SECTION) {
            $sectionId = max(0, (int)($params['section_id'] ?? 0));
            $chunk = ProductFilterIndexService::fetchSectionProductIdsAfterCursor($sectionId, $cursor, $chunkSize);
            $ids = $chunk['ids'];
            $lastId = $chunk['last_id'];
        } else {
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
        }

        if ($ids === []) {
            $params['enqueue_done'] = true;
            $params['enqueue_cursor'] = $cursor;
            self::saveJobParams($jobId, $params);

            return 0;
        }

        ProductFilterIndexQueueService::enqueueManyBulk($ids, $jobId);
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
                'queue_size' => ProductFilterIndexQueueService::count(),
                'error_count' => 0,
                'current_product_id' => 0,
                'last_error' => '',
                'finished' => true,
            ];
        }

        $status = (string)($job['STATUS'] ?? '');
        $processed = (int)($job['PROCESSED'] ?? 0);
        $total = max(0, (int)($job['TOTAL'] ?? 0));
        $percent = $total > 0 ? round(min(100, ($processed / $total) * 100), 1) : ($status === self::STATUS_COMPLETED ? 100.0 : 0.0);
        $running = $status === self::STATUS_RUNNING;
        $jobParams = self::decodeJobParams($job);
        $sectionId = (int)($jobParams['section_id'] ?? 0);

        return [
            'active' => $running,
            'job_id' => (int)($job['ID'] ?? 0),
            'status' => $status,
            'type' => (string)($job['TYPE'] ?? ''),
            'section_id' => $sectionId,
            'processed' => $processed,
            'total' => $total,
            'percent' => $percent,
            'queue_size' => ProductFilterIndexQueueService::count(),
            'error_count' => (int)($job['ERROR_COUNT'] ?? 0),
            'current_product_id' => (int)($job['CURRENT_PRODUCT_ID'] ?? 0),
            'last_error' => (string)($job['LAST_ERROR'] ?? ''),
            'finished' => in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true),
        ];
    }

    private static function usesLazyEnqueue(string $type): bool
    {
        return $type === self::TYPE_FULL
            || $type === self::TYPE_SYNC
            || $type === self::TYPE_SECTION;
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
        FilterIndexJobTable::update($jobId, [
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
     * @param array<string, mixed> $params
     * @return list<int>
     */
    private static function resolveProductIdsForJob(string $type, array $params): array
    {
        return match ($type) {
            self::TYPE_PRODUCT => isset($params['product_id']) ? [max(0, (int)$params['product_id'])] : [],
            default => [],
        };
    }

    private static function incrementJobProgress(int $jobId, bool $isError): void
    {
        $row = FilterIndexJobTable::getByPrimary($jobId)->fetch();
        if (!$row) {
            return;
        }

        FilterIndexJobTable::update($jobId, [
            'PROCESSED' => (int)$row['PROCESSED'] + 1,
            'ERROR_COUNT' => (int)$row['ERROR_COUNT'] + ($isError ? 1 : 0),
        ]);
    }

    private static function tryCompleteJob(int $jobId): void
    {
        $row = FilterIndexJobTable::getByPrimary($jobId)->fetch();
        if (!$row || ($row['STATUS'] ?? '') !== self::STATUS_RUNNING) {
            return;
        }

        if (ProductFilterIndexQueueService::count() > 0) {
            return;
        }

        if (!self::isEnqueueDone($row)) {
            return;
        }

        $processed = (int)$row['PROCESSED'];
        $errors = (int)$row['ERROR_COUNT'];
        $type = (string)($row['TYPE'] ?? '');

        FilterIndexJobTable::update($jobId, [
            'STATUS' => $errors > 0 && $processed === 0 ? self::STATUS_FAILED : self::STATUS_COMPLETED,
            'FINISHED_AT' => new DateTime(),
            'CURRENT_PRODUCT_ID' => 0,
        ]);

        if ($type === self::TYPE_FULL) {
            if ($errors > 0 && $processed === 0) {
                ProductFilterIndexService::refreshMetaCounts();
            } else {
                $message = $errors > 0
                    ? 'Построение завершено с ошибками: ' . $errors
                    : null;
                ProductFilterIndexService::finalizeFullBuild($message);
            }
        } elseif ($type === self::TYPE_SYNC) {
            if ($errors > 0 && $processed === 0) {
                ProductFilterIndexService::refreshMetaCounts();
            } else {
                $message = $errors > 0
                    ? 'Синхронизация завершена с ошибками: ' . $errors
                    : null;
                ProductFilterIndexService::finalizeSyncJob($message);
            }
        } elseif ($type === self::TYPE_SECTION) {
            $jobParams = self::decodeJobParams($row);
            $sectionId = max(0, (int)($jobParams['section_id'] ?? 0));
            if ($errors > 0 && $processed === 0) {
                ProductFilterIndexService::refreshMetaCounts();
            } else {
                $message = $errors > 0
                    ? 'Построение раздела завершено с ошибками: ' . $errors
                    : null;
                ProductFilterIndexService::finalizeSectionJob($sectionId, $message);
            }
        }
    }

    private static function log(string $message): void
    {
        $dir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $dir . '/product_filter_index.log',
            date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
