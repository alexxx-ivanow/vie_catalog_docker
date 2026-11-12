<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Filter\CatalogFilterEngineFactory;
use MyApp\Catalog\Service\BitmapFilterService;
use MyApp\Catalog\Service\CatalogService;
use MyApp\Catalog\Service\ProductFilterQueryService;

/**
 * Сравнение ID товаров: legacy vs denormalized vs bitmap.
 *
 * Usage:
 *   php local/modules/myapp.catalog/tools/compare_filter_query.php --section=123
 *   php ... --section=123 --engine=bitmap
 *   php ... --section=123 --price_from=100 --price_to=5000
 */

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!Loader::includeModule('myapp.catalog')) {
    fwrite(STDERR, "Module myapp.catalog not loaded\n");
    exit(1);
}

$sectionId = 0;
$sort = 'default';
$engine = 'denormalized';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--section=')) {
        $sectionId = (int)substr($arg, 10);
    } elseif (str_starts_with($arg, '--sort=')) {
        $sort = substr($arg, 7);
    } elseif (str_starts_with($arg, '--engine=')) {
        $engine = substr($arg, 9);
    } elseif (str_starts_with($arg, '--price_from=')) {
        $priceFrom = substr($arg, 13);
    } elseif (str_starts_with($arg, '--price_to=')) {
        $priceTo = substr($arg, 11);
    }
}

if ($sectionId <= 0) {
    fwrite(STDERR, "Required: --section=<id>\n");
    exit(1);
}

$filter = [
    'properties' => [],
    'force_index' => true,
    'force_bitmap' => true,
    'sort' => $sort,
];
if (isset($priceFrom)) {
    $filter['price_from'] = $priceFrom;
}
if (isset($priceTo)) {
    $filter['price_to'] = $priceTo;
}

$_REQUEST['skip_cache'] = '1';

$moduleId = 'myapp.catalog';
$prevEngine = Option::get($moduleId, Assets::OPT_CATALOG_FILTER_ENGINE, '');
$useOpt = Assets::OPT_USE_PRODUCT_FILTER_INDEX;
$prevUse = Option::get($moduleId, $useOpt, 'N');
Option::set($moduleId, Assets::OPT_CATALOG_FILTER_ENGINE, CatalogFilterEngineFactory::ENGINE_LEGACY);
Option::set($moduleId, $useOpt, 'N');

$legacyIds = [];
$page = 1;
$pages = 1;
do {
    $goods = CatalogService::getGoods($filter, $page, 500, $sectionId);
    foreach ($goods['items'] as $item) {
        $legacyIds[] = (int)$item['ID'];
    }
    $pages = (int)($goods['pages'] ?? 1);
    ++$page;
} while ($page <= $pages);

Option::set($moduleId, $useOpt, $prevUse);
Option::set($moduleId, Assets::OPT_CATALOG_FILTER_ENGINE, $prevEngine);

$perf = [];
try {
    if ($engine === 'bitmap') {
        $indexResult = BitmapFilterService::queryProductIds(
            $sectionId,
            $filter,
            1,
            max(1, count($legacyIds) ?: 50000),
            $perf
        );
    } else {
        $indexResult = ProductFilterQueryService::queryProductIds(
            $sectionId,
            $filter,
            1,
            max(1, count($legacyIds) ?: 50000),
            $perf
        );
    }
} catch (\Throwable $e) {
    fwrite(STDERR, $engine . ' query failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$indexIds = array_map('intval', $indexResult['product_ids'] ?? []);

$legacySet = array_fill_keys($legacyIds, true);
$indexSet = array_fill_keys($indexIds, true);
$onlyLegacy = array_keys(array_diff_key($legacySet, $indexSet));
$onlyIndex = array_keys(array_diff_key($indexSet, $legacySet));

$out = [
    'section_id' => $sectionId,
    'engine' => $engine,
    'legacy_total' => count($legacyIds),
    'index_total' => (int)($indexResult['total'] ?? count($indexIds)),
    'legacy_ids_count' => count($legacyIds),
    'index_ids_count' => count($indexIds),
    'only_legacy_count' => count($onlyLegacy),
    'only_index_count' => count($onlyIndex),
    'only_legacy_sample' => array_slice($onlyLegacy, 0, 20),
    'only_index_sample' => array_slice($onlyIndex, 0, 20),
    'match' => $onlyLegacy === [] && $onlyIndex === [],
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($out['match'] ? 0 : 2);
