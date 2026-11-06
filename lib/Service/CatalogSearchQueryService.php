<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use MyApp\Catalog\Install\CatalogSearchIndexSchema;
use MyApp\Catalog\Model\SearchIndexDocumentTable;
use MyApp\Catalog\Model\SearchIndexTokenTable;

final class CatalogSearchQueryService
{
    private const NAME_PREFIX_BONUS = 5;

    /**
     * @return array{
     *   product_ids: list<int>,
     *   total: int,
     *   scores: array<int, int>,
     *   search_mode: string
     * }
     */
    public static function searchProductIds(string $query, int $page, int $limit): array
    {
        CatalogSearchIndexSchema::ensureInfrastructure();

        $normQuery = CatalogSearchTextNormalizer::normalizeText($query);
        if (mb_strlen($normQuery, 'UTF-8') < 2) {
            return [
                'product_ids' => [],
                'total' => 0,
                'scores' => [],
                'search_mode' => 'empty',
            ];
        }

        $tokens = CatalogSearchTextNormalizer::tokenizeQuery($normQuery);
        if ($tokens === []) {
            return [
                'product_ids' => [],
                'total' => 0,
                'scores' => [],
                'search_mode' => 'empty',
            ];
        }

        $strict = self::runStrictPhase($tokens, $normQuery);
        $searchMode = 'strict';
        // Fuzzy только если strict ничего не нашёл — иначе «похожие» вытесняли точные совпадения.
        if ($strict['total'] === 0 && self::hasFuzzyEligibleToken($tokens)) {
            $fuzzy = self::runFuzzyPhase($tokens, $normQuery, []);
            if ($fuzzy['total'] > 0) {
                $strict = $fuzzy;
                $searchMode = 'fuzzy';
            }
        }

        $total = $strict['total'];
        $page = max(1, $page);
        $limit = max(1, $limit);
        $offset = ($page - 1) * $limit;
        $pageIds = array_slice($strict['ordered_ids'], $offset, $limit);

        return [
            'product_ids' => $pageIds,
            'total' => $total,
            'scores' => $strict['scores'],
            'search_mode' => $searchMode,
        ];
    }

    /**
     * @param list<string> $tokens
     * @return array{ordered_ids: list<int>, total: int, scores: array<int, int>, product_ids: list<int>}
     */
    private static function runStrictPhase(array $tokens, string $normQuery): array
    {
        $matchedSets = [];
        foreach ($tokens as $token) {
            if (!CatalogSearchTextNormalizer::isValidTokenForSql($token)) {
                return self::emptyPhaseResult();
            }
            $ids = self::findProductIdsForToken($token, false);
            if ($ids === []) {
                return self::emptyPhaseResult();
            }
            $matchedSets[] = $ids;
        }

        $intersection = $matchedSets[0];
        for ($i = 1, $n = count($matchedSets); $i < $n; ++$i) {
            $intersection = array_values(array_intersect($intersection, $matchedSets[$i]));
            if ($intersection === []) {
                return self::emptyPhaseResult();
            }
        }

        $scores = self::scoreProducts($intersection, $tokens, $normQuery);
        $minScore = self::minimumStrictScoreThreshold($tokens);
        $filtered = [];
        foreach ($intersection as $productId) {
            $score = (int)($scores[$productId] ?? 0);
            if ($score >= $minScore) {
                $filtered[] = $productId;
            }
        }

        usort($filtered, static function (int $a, int $b) use ($scores): int {
            $sa = (int)($scores[$a] ?? 0);
            $sb = (int)($scores[$b] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return $a <=> $b;
        });

        return [
            'ordered_ids' => $filtered,
            'total' => count($filtered),
            'scores' => $scores,
            'product_ids' => $filtered,
        ];
    }

    /**
     * Как в лексемном бэкенде: порог = WEIGHT_DETAIL × число токенов (минимальный реалистичный вклад по полям).
     *
     * @param list<string> $tokens
     */
    private static function minimumStrictScoreThreshold(array $tokens): int
    {
        return CatalogSearchIndexService::WEIGHT_DETAIL * count($tokens);
    }

    /**
     * @param list<string> $tokens
     * @param list<int> $excludeIds
     * @return array{ordered_ids: list<int>, total: int, scores: array<int, int>, product_ids: list<int>}
     */
    private static function runFuzzyPhase(array $tokens, string $normQuery, array $excludeIds): array
    {
        $matchedSets = [];
        foreach ($tokens as $token) {
            if (mb_strlen($token, 'UTF-8') < 4) {
                $ids = self::findProductIdsForToken($token, false);
            } else {
                $ids = self::findProductIdsForToken($token, true);
            }
            if ($ids === []) {
                return self::emptyPhaseResult();
            }
            $matchedSets[] = $ids;
        }

        $unionMode = false;
        $intersection = $matchedSets[0];
        for ($i = 1, $n = count($matchedSets); $i < $n; ++$i) {
            $next = array_values(array_intersect($intersection, $matchedSets[$i]));
            if ($next === []) {
                $unionMode = true;
                break;
            }
            $intersection = $next;
        }

        if ($unionMode) {
            $intersection = [];
            foreach ($matchedSets as $set) {
                $intersection = array_values(array_unique(array_merge($intersection, $set)));
            }
        }

        if ($excludeIds !== []) {
            $exclude = array_fill_keys($excludeIds, true);
            $intersection = array_values(array_filter(
                $intersection,
                static fn(int $id): bool => !isset($exclude[$id])
            ));
        }

        $scores = self::scoreProducts($intersection, $tokens, $normQuery);
        $minScore = self::minimumStrictScoreThreshold($tokens);
        $filtered = [];
        foreach ($intersection as $productId) {
            $score = (int)($scores[$productId] ?? 0);
            if ($score >= $minScore) {
                $filtered[] = $productId;
            }
        }

        usort($filtered, static function (int $a, int $b) use ($scores): int {
            $sa = (int)($scores[$a] ?? 0);
            $sb = (int)($scores[$b] ?? 0);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return $a <=> $b;
        });

        return [
            'ordered_ids' => $filtered,
            'total' => count($filtered),
            'scores' => $scores,
            'product_ids' => $filtered,
        ];
    }

    /**
     * @return array{ordered_ids: list<int>, total: int, scores: array<int, int>, product_ids: list<int>}
     */
    private static function emptyPhaseResult(): array
    {
        return [
            'ordered_ids' => [],
            'total' => 0,
            'scores' => [],
            'product_ids' => [],
        ];
    }

    /**
     * @param list<string> $tokens
     */
    private static function hasFuzzyEligibleToken(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (mb_strlen($token, 'UTF-8') >= 4) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private static function findProductIdsForToken(string $token, bool $allowFuzzy): array
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $tokenSql = $helper->forSql($token);
        $prefixSql = $helper->forSql($token) . '%';
        $tokenTable = SearchIndexTokenTable::getTableName();
        $docTable = SearchIndexDocumentTable::getTableName();

        $ids = [];
        $res = $connection->query("
            SELECT DISTINCT t.PRODUCT_ID
            FROM {$tokenTable} t
            INNER JOIN {$docTable} d ON d.PRODUCT_ID = t.PRODUCT_ID AND d.ACTIVE = 'Y'
            WHERE t.TOKEN = '{$tokenSql}' OR t.TOKEN LIKE '{$prefixSql}'
        ");
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['PRODUCT_ID'];
        }

        if (
            !$allowFuzzy
            || mb_strlen($token, 'UTF-8') < 4
            || CatalogSearchTextNormalizer::isNumericToken($token)
        ) {
            return array_values(array_unique($ids));
        }

        $prefix2 = mb_substr($token, 0, 2, 'UTF-8');
        if ($prefix2 === '') {
            return array_values(array_unique($ids));
        }
        $prefix2Sql = $helper->forSql($prefix2) . '%';
        $res = $connection->query("
            SELECT DISTINCT t.TOKEN, t.PRODUCT_ID
            FROM {$tokenTable} t
            INNER JOIN {$docTable} d ON d.PRODUCT_ID = t.PRODUCT_ID AND d.ACTIVE = 'Y'
            WHERE t.TOKEN LIKE '{$prefix2Sql}'
            LIMIT 5000
        ");
        $existing = array_fill_keys($ids, true);
        while ($row = $res->fetch()) {
            $candidate = (string)$row['TOKEN'];
            if ($candidate === $token) {
                continue;
            }
            if (levenshtein($token, $candidate) > 1) {
                continue;
            }
            $productId = (int)$row['PRODUCT_ID'];
            if (!isset($existing[$productId])) {
                $ids[] = $productId;
                $existing[$productId] = true;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<int> $productIds
     * @param list<string> $tokens
     * @return array<int, int>
     */
    private static function scoreProducts(array $productIds, array $tokens, string $normQuery): array
    {
        if ($productIds === []) {
            return [];
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $tokenTable = SearchIndexTokenTable::getTableName();
        $docTable = SearchIndexDocumentTable::getTableName();
        $idsSql = implode(',', array_map('intval', $productIds));

        $tokenConditions = [];
        foreach ($tokens as $token) {
            if (!CatalogSearchTextNormalizer::isValidTokenForSql($token)) {
                continue;
            }
            $tokenSql = $helper->forSql($token);
            $prefixSql = $helper->forSql($token) . '%';
            $tokenConditions[] = "(t.TOKEN = '{$tokenSql}' OR t.TOKEN LIKE '{$prefixSql}')";
        }
        if ($tokenConditions === []) {
            return [];
        }

        $whereTokens = implode(' OR ', $tokenConditions);
        $scores = [];
        $res = $connection->query("
            SELECT t.PRODUCT_ID, SUM(t.WEIGHT) AS SCORE
            FROM {$tokenTable} t
            WHERE t.PRODUCT_ID IN ({$idsSql}) AND ({$whereTokens})
            GROUP BY t.PRODUCT_ID
        ");
        while ($row = $res->fetch()) {
            $scores[(int)$row['PRODUCT_ID']] = (int)$row['SCORE'];
        }

        $nameRes = $connection->query("
            SELECT PRODUCT_ID, NAME_NORM
            FROM {$docTable}
            WHERE PRODUCT_ID IN ({$idsSql})
        ");
        while ($row = $nameRes->fetch()) {
            $productId = (int)$row['PRODUCT_ID'];
            $nameNorm = (string)$row['NAME_NORM'];
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                if (str_starts_with($nameNorm, $token) || preg_match('/\s' . preg_quote($token, '/') . '/u', ' ' . $nameNorm)) {
                    $scores[$productId] = (int)($scores[$productId] ?? 0) + self::NAME_PREFIX_BONUS;
                    break;
                }
            }
        }

        return $scores;
    }
}
