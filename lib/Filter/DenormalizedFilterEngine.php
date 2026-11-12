<?php

namespace MyApp\Catalog\Filter;

use MyApp\Catalog\Service\ProductFilterQueryService;

final class DenormalizedFilterEngine implements CatalogFilterEngineInterface
{
    public function queryProductIds(
        int $sectionId,
        array $filter,
        int $page,
        int $limit,
        ?array &$perf = null
    ): array {
        return ProductFilterQueryService::queryProductIds($sectionId, $filter, $page, $limit, $perf);
    }
}
