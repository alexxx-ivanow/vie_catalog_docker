<?php

namespace MyApp\Catalog\Filter;

use MyApp\Catalog\Service\BitmapFilterService;

final class BitmapFilterEngine implements CatalogFilterEngineInterface
{
    public function queryProductIds(
        int $sectionId,
        array $filter,
        int $page,
        int $limit,
        ?array &$perf = null
    ): array {
        return BitmapFilterService::queryProductIds($sectionId, $filter, $page, $limit, $perf);
    }
}
