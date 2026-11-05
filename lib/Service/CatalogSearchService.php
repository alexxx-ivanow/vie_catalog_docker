<?php

namespace MyApp\Catalog\Service;

final class CatalogSearchService
{
    /**
     * @return array<string, mixed>
     */
    public static function search(string $query, int $page, int $limit, ?string $sort = null): array
    {
        $query = trim($query);
        $page = max(1, $page);
        $limit = CatalogService::normalizeGoodsLimit($limit);

        if (!CatalogSearchIndexService::isIndexReady()) {
            return [
                'items' => [],
                'total' => 0,
                'pages' => 0,
                'page' => $page,
                'limit' => $limit,
                'query' => $query,
                'index_ready' => false,
                'message' => 'Поиск временно недоступен',
                'suggestions' => [],
                'searchMode' => 'unavailable',
            ];
        }

        if (mb_strlen(CatalogSearchTextNormalizer::normalizeText($query), 'UTF-8') < 2) {
            return [
                'items' => [],
                'total' => 0,
                'pages' => 0,
                'page' => $page,
                'limit' => $limit,
                'query' => $query,
                'index_ready' => true,
                'message' => 'Введите не менее 2 символов',
                'suggestions' => [],
                'searchMode' => 'empty',
            ];
        }

        $result = CatalogSearchQueryService::searchProductIds($query, $page, $limit);
        $total = (int)$result['total'];
        $pages = $limit > 0 ? (int)ceil($total / $limit) : 0;
        $productIds = $result['product_ids'];
        $items = CatalogService::loadGoodsDetailsByIdsForSearch($productIds);

        $sortKey = CatalogService::normalizeGoodsSort($sort);
        if ($sortKey !== 'default') {
            $items = self::sortItems($items, $sortKey);
        } else {
            $items = self::orderByIds($items, $productIds);
        }

        $tokens = CatalogSearchTextNormalizer::tokenizeQuery($query);
        $suggestions = [];
        if ($total === 0) {
            $suggestions = CatalogSearchSuggestService::suggestQueries($query, $tokens);
        }

        return [
            'items' => $items,
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'limit' => $limit,
            'query' => $query,
            'index_ready' => true,
            'suggestions' => $suggestions,
            'searchMode' => (string)($result['search_mode'] ?? 'strict'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    private static function orderByIds(array $items, array $productIds): array
    {
        if ($items === [] || $productIds === []) {
            return $items;
        }

        $map = [];
        foreach ($items as $item) {
            $id = (int)($item['ID'] ?? $item['id'] ?? 0);
            if ($id > 0) {
                $map[$id] = $item;
            }
        }

        $ordered = [];
        foreach ($productIds as $id) {
            if (isset($map[$id])) {
                $ordered[] = $map[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function sortItems(array $items, string $sortKey): array
    {
        $items = array_values($items);
        usort($items, static function (array $a, array $b) use ($sortKey): int {
            $priceA = (float)($a['PRICE'] ?? $a['price'] ?? 0);
            $priceB = (float)($b['PRICE'] ?? $b['price'] ?? 0);
            $nameA = (string)($a['NAME'] ?? $a['name'] ?? '');
            $nameB = (string)($b['NAME'] ?? $b['name'] ?? '');

            return match ($sortKey) {
                'price_asc' => $priceA <=> $priceB,
                'price_desc' => $priceB <=> $priceA,
                'name' => strcmp($nameA, $nameB),
                default => 0,
            };
        });

        return $items;
    }
}
