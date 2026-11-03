<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Service\FilterRebuildJobService;
use MyApp\Catalog\Service\ProductFilterIndexJobService;
use MyApp\Catalog\Service\ProductFilterIndexService;

global $USER;

header('Content-Type: application/json; charset=utf-8');

$respond = static function (array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    die();
};

if (!is_object($USER) || !$USER->IsAdmin()) {
    $respond(['ok' => false, 'error' => 'Access denied'], 403);
}

if (!Loader::includeModule('myapp.catalog')) {
    $respond(['ok' => false, 'error' => 'Module not loaded']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_bitrix_sessid()) {
    $respond(['ok' => false, 'error' => 'Invalid session']);
}

$domain = trim((string)($_POST['domain'] ?? ''));
$action = trim((string)($_POST['action'] ?? ''));

if (!in_array($domain, ['index', 'snapshots'], true)) {
    $respond(['ok' => false, 'error' => 'Invalid domain']);
}

$normalizeValue = static function ($value) use (&$normalizeValue) {
    if ($value instanceof DateTime) {
        return $value->toString();
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $normalizeValue($v);
        }

        return $out;
    }

    return $value;
};

try {
    switch ($action) {
        case 'status':
            if ($domain === 'index') {
                $respond([
                    'ok' => true,
                    'progress' => ProductFilterIndexJobService::getProgressPayload(),
                    'stats' => $normalizeValue(ProductFilterIndexService::getIndexStatus()),
                ]);
            }

            $respond([
                'ok' => true,
                'progress' => FilterRebuildJobService::getProgressPayload(),
                'stats' => $normalizeValue(FilterRebuildJobService::getStatistics()),
            ]);

        case 'start':
            $type = trim((string)($_POST['type'] ?? ''));
            if ($domain === 'index') {
                if (!in_array($type, ['full', 'section'], true)) {
                    throw new \InvalidArgumentException('Invalid job type');
                }
                if ($type === 'full') {
                    ProductFilterIndexService::buildFullIndex();
                } else {
                    $sectionId = (int)($_POST['section_id'] ?? 0);
                    ProductFilterIndexService::buildSectionIndex($sectionId);
                }
                $respond([
                    'ok' => true,
                    'progress' => ProductFilterIndexJobService::getProgressPayload(),
                    'stats' => $normalizeValue(ProductFilterIndexService::getIndexStatus()),
                ]);
            }

            $params = [];
            if ($type === 'section') {
                $params['section_id'] = (int)($_POST['section_id'] ?? 0);
            }
            $allowed = [
                FilterRebuildJobService::TYPE_FILL_ALL,
                FilterRebuildJobService::TYPE_REBUILD_ALL,
                FilterRebuildJobService::TYPE_REBUILD_STALE,
                FilterRebuildJobService::TYPE_SECTION,
            ];
            if (!in_array($type, $allowed, true)) {
                throw new \InvalidArgumentException('Invalid job type');
            }
            FilterRebuildJobService::startJob($type, $params);
            $respond([
                'ok' => true,
                'progress' => FilterRebuildJobService::getProgressPayload(),
                'stats' => $normalizeValue(FilterRebuildJobService::getStatistics()),
            ]);

        case 'step':
            $batch = (int)($_POST['batch'] ?? 20);
            if ($domain === 'index') {
                $batch = max(1, min(Assets::MAX_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH, $batch));
                $processed = ProductFilterIndexJobService::runStep($batch);
                $respond([
                    'ok' => true,
                    'processed' => $processed,
                    'progress' => ProductFilterIndexJobService::getProgressPayload(),
                    'stats' => $normalizeValue(ProductFilterIndexService::getIndexStatus()),
                ]);
            }

            $batch = max(1, min(50, $batch));
            $processed = FilterRebuildJobService::runStep($batch);
            $respond([
                'ok' => true,
                'processed' => $processed,
                'progress' => FilterRebuildJobService::getProgressPayload(),
                'stats' => $normalizeValue(FilterRebuildJobService::getStatistics()),
            ]);

        case 'cancel':
            if ($domain === 'index') {
                ProductFilterIndexJobService::cancelRunningJob();
                $respond([
                    'ok' => true,
                    'progress' => ProductFilterIndexJobService::getProgressPayload(),
                    'stats' => $normalizeValue(ProductFilterIndexService::getIndexStatus()),
                ]);
            }

            FilterRebuildJobService::cancelRunningJob();
            $respond([
                'ok' => true,
                'progress' => FilterRebuildJobService::getProgressPayload(),
                'stats' => $normalizeValue(FilterRebuildJobService::getStatistics()),
            ]);

        case 'clear':
            if ($domain === 'index') {
                ProductFilterIndexService::clearIndexAndQueue();
                $respond([
                    'ok' => true,
                    'progress' => ProductFilterIndexJobService::getProgressPayload(),
                    'stats' => $normalizeValue(ProductFilterIndexService::getIndexStatus()),
                ]);
            }

            FilterRebuildJobService::clearSnapshotsAndQueue();
            $respond([
                'ok' => true,
                'progress' => FilterRebuildJobService::getProgressPayload(),
                'stats' => $normalizeValue(FilterRebuildJobService::getStatistics()),
            ]);

        default:
            throw new \InvalidArgumentException('Unknown action');
    }
} catch (\Throwable $e) {
    $respond(['ok' => false, 'error' => $e->getMessage()]);
}
