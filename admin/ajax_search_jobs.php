<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Service\CatalogSearchIndexJobService;
use MyApp\Catalog\Service\CatalogSearchIndexService;

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

$action = trim((string)($_POST['action'] ?? ''));

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
            $respond([
                'ok' => true,
                'progress' => CatalogSearchIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(CatalogSearchIndexService::getIndexStatus()),
            ]);

        case 'start':
            $type = trim((string)($_POST['type'] ?? ''));
            if ($type !== 'full') {
                throw new \InvalidArgumentException('Invalid job type');
            }
            CatalogSearchIndexService::buildFullIndex();
            $respond([
                'ok' => true,
                'progress' => CatalogSearchIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(CatalogSearchIndexService::getIndexStatus()),
            ]);

        case 'step':
            $batch = (int)($_POST['batch'] ?? 20);
            $batch = max(1, min(Assets::MAX_CATALOG_SEARCH_INDEX_ADMIN_STEP_BATCH, $batch));
            $processed = CatalogSearchIndexJobService::runStep($batch);
            $respond([
                'ok' => true,
                'processed' => $processed,
                'progress' => CatalogSearchIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(CatalogSearchIndexService::getIndexStatus()),
            ]);

        case 'cancel':
            CatalogSearchIndexJobService::cancelRunningJob();
            $respond([
                'ok' => true,
                'progress' => CatalogSearchIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(CatalogSearchIndexService::getIndexStatus()),
            ]);

        case 'clear':
            CatalogSearchIndexService::clearIndexAndQueue();
            $respond([
                'ok' => true,
                'progress' => CatalogSearchIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(CatalogSearchIndexService::getIndexStatus()),
            ]);

        default:
            throw new \InvalidArgumentException('Unknown action');
    }
} catch (\Throwable $e) {
    $respond(['ok' => false, 'error' => $e->getMessage()]);
}
