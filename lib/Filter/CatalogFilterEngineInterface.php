<?php

namespace MyApp\Catalog\Filter;

interface CatalogFilterEngineInterface
{
    /**
     * @param array<string, mixed> $filter
     * @return array{total: int, product_ids: list<int>, page?: int, pages?: int, perf: array<string, mixed>}
     */
    public function queryProductIds(
        int $sectionId,
        array $filter,
        int $page,
        int $limit,
        ?array &$perf = null
    ): array;
}
