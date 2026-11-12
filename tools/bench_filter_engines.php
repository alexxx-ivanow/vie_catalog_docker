<?php

/**
 * Сравнение движков фильтрации: legacy / denormalized / bitmap.
 *
 * Usage:
 *   php local/modules/myapp.catalog/tools/bench_filter_engines.php --section=123
 *   php ... --section=123 --props=MATERIAL:1,2 --price_from=100 --runs=3
 */

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Filter\CatalogFilterEngineFactory;
use MyApp\Catalog\Service\BitmapFilterService;
use MyApp\Catalog\Service\CatalogService;
use MyApp\Catalog\Service\ProductFilterQueryService;

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!Loader::includeModule('myapp.catalog')) {
    fwrite(STDERR, "Module myapp.catalog not loaded\n");
    exit(1);
}

$sectionId = 0;
$runs = 3;
$priceFrom = null;
$priceTo = null;
$props = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--section=')) {
        $sectionId = (int)substr($arg, 10);
    } elseif (str_starts_with($arg, '--runs=')) {
        $runs = max(1, (int)substr($arg, 7));
    } elseif (str_starts_with($arg, '--price_from=')) {
        $priceFrom = substr($arg, 13);
    } elseif (str_starts_with($arg, '--price_to=')) {
        $priceTo = substr($arg, 11);
    } elseif (str_starts_with($arg, '--props=')) {
        // CODE:id1,id2;CODE2:id3
        foreach (explode(';', substr($arg, 8)) as $chunk) {
            if ($chunk === '' || !str_contains($chunk, ':')) {
                continue;
            }
            [$code, $vals] = explode(':', $chunk, 2);
            $props[$code] = array_values(array_filter(explode(',', $vals), static fn($v) => $v !== ''));
        }
    }
}

if ($sectionId <= 0) {
    fwrite(STDERR, "Required: --section=<id>\n");
    exit(1);
}

$_REQUEST['skip_cache'] = '1';
$_REQUEST['perf'] = '1';

$filter = [
    'properties' => $props,
    'sort' => 'default',
];
if ($priceFrom !== null) {
    $filter['price_from'] = $priceFrom;
}
if ($priceTo !== null) {
    $filter['price_to'] = $priceTo;
}

$moduleId = 'myapp.catalog';
$prevEngine = Option::get($moduleId, Assets::OPT_CATALOG_FILTER_ENGINE, '');

$engines = [
    CatalogFilterEngineFactory::ENGINE_LEGACY,
    CatalogFilterEngineFactory::ENGINE_DENORMALIZED,
    CatalogFilterEngineFactory::ENGINE_BITMAP,
];

echo "section={$sectionId} runs={$runs}\n";
foreach ($engines as $engineId) {
    Option::set($moduleId, Assets::OPT_CATALOG_FILTER_ENGINE, $engineId);
    $memBefore = memory_get_usage(true);
    $times = [];
    $lastTotal = null;
    $lastCount = null;
    for ($i = 0; $i < $runs; $i++) {
        $t0 = microtime(true);
        $perf = [];
        try {
            if ($engineId === CatalogFilterEngineFactory::ENGINE_BITMAP) {
                $filter['force_bitmap'] = true;
                $result = BitmapFilterService::queryProductIds($sectionId, $filter, 1, 12, $perf);
            } elseif ($engineId === CatalogFilterEngineFactory::ENGINE_DENORMALIZED) {
                $filter['force_index'] = true;
                $result = ProductFilterQueryService::queryProductIds($sectionId, $filter, 1, 12, $perf);
            } else {
                unset($filter['force_index'], $filter['force_bitmap']);
                Option::set($moduleId, Assets::OPT_CATALOG_FILTER_ENGINE, CatalogFilterEngineFactory::ENGINE_LEGACY);
                Option::set($moduleId, Assets::OPT_USE_PRODUCT_FILTER_INDEX, 'N');
                $goods = CatalogService::getGoods($filter, 1, 12, $sectionId);
                $result = [
                    'total' => (int)($goods['total'] ?? 0),
                    'product_ids' => array_map(static fn($item) => (int)$item['ID'], $goods['items'] ?? []),
                    'perf' => $goods['_perf'] ?? [],
                ];
            }
            $lastTotal = (int)$result['total'];
            $lastCount = count($result['product_ids']);
        } catch (\Throwable $e) {
            echo "{$engineId}: ERROR " . $e->getMessage() . "\n";
            $times[] = null;
            continue;
        }
        $times[] = (microtime(true) - $t0) * 1000;
    }
    $valid = array_values(array_filter($times, static fn($v) => $v !== null));
    $avg = $valid !== [] ? array_sum($valid) / count($valid) : 0;
    $memAfter = memory_get_usage(true);
    echo sprintf(
        "%s: avg_ms=%.2f total=%s page_ids=%s mem_delta=%d\n",
        $engineId,
        $avg,
        $lastTotal === null ? 'n/a' : (string)$lastTotal,
        $lastCount === null ? 'n/a' : (string)$lastCount,
        $memAfter - $memBefore
    );
}

Option::set($moduleId, Assets::OPT_CATALOG_FILTER_ENGINE, $prevEngine);
echo "Roaring Bitmap: not implemented; plain binary masks used. Re-evaluate if blob_bytes/memory becomes dominant at 100k+.\n";
