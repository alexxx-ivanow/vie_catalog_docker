<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Сравнение ID товаров: legacy getGoods vs ProductFilterQueryService (§13 QA).
 *
 * Usage:
 *   php local/modules/myapp.catalog/tools/compare_filter_query.php --section=123
 *   php ... --section=123 --price_from=100 --price_to=5000
 *
 * Checklist before prod flag use_product_filter_index:
 * 1. Full index built, CONFIG_HASH matches, index_ready=Y
 * 2. This script: symmetric diff = 0 for key sections and filter combos
 * 3. AJAX catalog/goods?skip_cache=1&perf=1 — compare sql_time_ms / sql_count cold vs legacy
 * 4. Scenarios: section only, 2–3 props AND, multi-value OR, price range, sort, pagination page 2
 */

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!Loader::includeModule('myapp.catalog')) {
    fwrite(STDERR, "Module myapp.catalog not loaded\n");
    exit(1);
}

$sectionId = 0;
$sort = 'default';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--section=')) {
        $sectionId = (int)substr($arg, 10);
    } elseif (str_starts_with($arg, '--sort=')) {
        $sort = substr($arg, 7);
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
$useOpt = \MyApp\Catalog\Config\Assets::OPT_USE_PRODUCT_FILTER_INDEX;
$prevUse = Option::get($moduleId, $useOpt, 'N');
Option::set($moduleId, $useOpt, 'N');

$legacyIds = [];
$page = 1;
$pages = 1;
do {
    $goods = \MyApp\Catalog\Service\CatalogService::getGoods(
        $filter,
        $page,
        500,
        $sectionId
    );
    foreach ($goods['items'] as $item) {
        $legacyIds[] = (int)$item['ID'];
    }
    $pages = (int)($goods['pages'] ?? 1);
    ++$page;
} while ($page <= $pages);

Option::set($moduleId, $useOpt, $prevUse);

$indexResult = \MyApp\Catalog\Service\ProductFilterQueryService::queryProductIds(
    $sectionId,
    $filter,
    1,
    max(1, count($legacyIds) ?: 50000),
    $perf
);
$indexIds = array_map('intval', $indexResult['product_ids'] ?? []);

$legacySet = array_fill_keys($legacyIds, true);
$indexSet = array_fill_keys($indexIds, true);
$onlyLegacy = array_keys(array_diff_key($legacySet, $indexSet));
$onlyIndex = array_keys(array_diff_key($indexSet, $legacySet));

$out = [
    'section_id' => $sectionId,
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
