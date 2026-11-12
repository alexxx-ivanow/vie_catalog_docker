<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Service\BitmapIndexJobService;
use MyApp\Catalog\Service\BitmapIndexService;
use MyApp\Catalog\Service\BitmapIndexValidator;

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
                'progress' => BitmapIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(BitmapIndexService::getIndexStatus()),
            ]);

        case 'start':
            BitmapIndexService::buildFullIndex();
            $respond([
                'ok' => true,
                'progress' => BitmapIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(BitmapIndexService::getIndexStatus()),
            ]);

        case 'step':
            $batch = (int)($_POST['batch'] ?? Assets::bitmapIndexAdminStepBatch());
            $batch = max(1, min(Assets::MAX_BITMAP_INDEX_ADMIN_STEP_BATCH, $batch));
            $processed = BitmapIndexJobService::runStep($batch);
            $respond([
                'ok' => true,
                'processed' => $processed,
                'progress' => BitmapIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(BitmapIndexService::getIndexStatus()),
            ]);

        case 'cancel':
            BitmapIndexJobService::cancelRunningJob();
            $respond([
                'ok' => true,
                'progress' => BitmapIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(BitmapIndexService::getIndexStatus()),
            ]);

        case 'clear':
            if (BitmapIndexJobService::getRunningJob() !== null) {
                throw new \RuntimeException('Сначала остановите текущую задачу');
            }
            BitmapIndexService::clearIndexAndQueue();
            $respond([
                'ok' => true,
                'progress' => BitmapIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(BitmapIndexService::getIndexStatus()),
            ]);

        case 'validate':
            $report = BitmapIndexValidator::validate(true);
            $respond([
                'ok' => true,
                'report' => $report,
                'progress' => BitmapIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(BitmapIndexService::getIndexStatus()),
            ]);

        case 'repair':
            $result = BitmapIndexValidator::repair();
            $respond([
                'ok' => (bool)$result['ok'],
                'message' => (string)$result['message'],
                'progress' => BitmapIndexJobService::getProgressPayload(),
                'stats' => $normalizeValue(BitmapIndexService::getIndexStatus()),
            ]);

        default:
            $respond(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (\Throwable $e) {
    $respond(['ok' => false, 'error' => $e->getMessage()]);
}
