<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use MyApp\Catalog\Install\CatalogSearchIndexSchema;
use MyApp\Catalog\Model\SearchIndexDocumentTable;
use MyApp\Catalog\Search\CatalogSearchBackendInterface;

/**
 * Поиск: словарь лексем + триграммы на лексеме; связь товар–лексема с весом.
 * Подстрока в слове: пересечение по триграммам → подтверждение LOCATE на LEXEME_TEXT.
 */
final class MysqlLexemeTrigramSearchBackend implements CatalogSearchBackendInterface
{
    private const NAME_PREFIX_BONUS = 5;

    public function removeProductIndex(int $productId): void
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return;
        }

        CatalogSearchIndexSchema::ensureInfrastructure();
        $connection = Application::getConnection();
        $plTable = 'myapp_catalog_search_product_lexeme';
        $connection->queryExecute("DELETE FROM {$plTable} WHERE PRODUCT_ID = {$productId}");

        if (SearchIndexDocumentTable::getByPrimary($productId)->fetch()) {
            SearchIndexDocumentTable::delete($productId);
        }
    }

    /**
     * @param array<string, int> $lexemeWeights
     */
    public function indexProductLexemes(int $productId, array $lexemeWeights): void
    {
        $productId = (int)$productId;
        if ($productId <= 0 || $lexemeWeights === []) {
            return;
        }

        CatalogSearchIndexSchema::ensureInfrastructure();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $plTable = 'myapp_catalog_search_product_lexeme';
        $lexTable = 'myapp_catalog_search_lexeme';
        $ltTable = 'myapp_catalog_search_lexeme_trigram';

        $connection->queryExecute("DELETE FROM {$plTable} WHERE PRODUCT_ID = {$productId}");

        foreach ($lexemeWeights as $text => $weight) {
            $text = (string)$text;
            if ($text === '' || !CatalogSearchTextNormalizer::isValidTokenForSql($text)) {
                continue;
            }
            $text = CatalogSearchTextNormalizer::clipToken($text);
            $esc = $helper->forSql($text);
            $exists = $connection->query("SELECT ID FROM {$lexTable} WHERE LEXEME_TEXT = '{$esc}' LIMIT 1")->fetch();
            if ($exists) {
                $lexemeId = (int)$exists['ID'];
            } else {
                $connection->queryExecute("INSERT INTO {$lexTable} (LEXEME_TEXT) VALUES ('{$esc}')");
                $lexemeId = (int)$connection->getInsertedId();
                foreach (CatalogSearchTrigram::extractTrigrams($text) as $tri) {
                    if (!self::isValidTrigram($tri)) {
                        continue;
                    }
                    $tesc = $helper->forSql($tri);
                    $connection->queryExecute("
                        INSERT IGNORE INTO {$ltTable} (LEXEME_ID, TRIGRAM)
                        VALUES ({$lexemeId}, '{$tesc}')
                    ");
                }
            }

            $w = min(255, max(1, (int)$weight));
            $connection->queryExecute("
                INSERT INTO {$plTable} (PRODUCT_ID, LEXEME_ID, WEIGHT)
                VALUES ({$productId}, {$lexemeId}, {$w})
                ON DUPLICATE KEY UPDATE WEIGHT = GREATEST(WEIGHT, VALUES(WEIGHT))
            ");
        }
    }

    /**
     * @return array{product_ids: list<int>, total: int, scores: array<int, int>, search_mode: string}
     */
    public function searchProductIds(string $query, int $page, int $limit): array
    {
        CatalogSearchIndexSchema::ensureInfrastructure();

        $normQuery = CatalogSearchTextNormalizer::normalizeText($query);
        if (mb_strlen($normQuery, 'UTF-8') < 2) {
            return self::emptyResult('empty');
        }

        $tokens = CatalogSearchTextNormalizer::tokenizeQuery($normQuery);
        if ($tokens === []) {
            return self::emptyResult('empty');
        }

        $tokenToLexemeIds = [];
        foreach ($tokens as $token) {
            if (!CatalogSearchTextNormalizer::isValidTokenForSql($token)) {
                return self::emptyResult('empty');
            }
            $lexIds = $this->resolveLexemesForQueryToken($token);
            if ($lexIds === []) {
                return self::emptyResult('strict');
            }
            $tokenToLexemeIds[$token] = $lexIds;
        }

        /** @var list<list<int>> $productIdSets */
        $productIdSets = [];
        foreach ($tokens as $token) {
            $pids = $this->productIdsForLexemeIds($tokenToLexemeIds[$token]);
            if ($pids === []) {
                return self::emptyResult('strict');
            }
            $productIdSets[] = $pids;
        }

        $productIds = $productIdSets[0];
        for ($i = 1, $n = count($productIdSets); $i < $n; ++$i) {
            $productIds = array_values(array_intersect($productIds, $productIdSets[$i]));
            if ($productIds === []) {
                return self::emptyResult('strict');
            }
        }
        sort($productIds);

        $scores = $this->scoreProducts($productIds, $tokenToLexemeIds);
        $minScore = $this->minimumScoreThreshold($tokens);
        $filtered = [];
        foreach ($productIds as $pid) {
            $pid = (int)$pid;
            if ((int)($scores[$pid] ?? 0) >= $minScore) {
                $filtered[] = $pid;
            }
        }

        usort($filtered, static function (int $a, int $b) use ($scores): int {
            $sa = (int)($scores[$a] ?? 0);
            $sb = (int)($scores[$b] ?? 0);
            return $sa !== $sb ? $sb <=> $sa : $a <=> $b;
        });

        $total = count($filtered);
        $page = max(1, $page);
        $limit = max(1, $limit);
        $offset = ($page - 1) * $limit;

        return [
            'product_ids' => array_slice($filtered, $offset, $limit),
            'total' => $total,
            'scores' => $scores,
            'search_mode' => 'strict',
        ];
    }

    /**
     * @param list<string> $queryTokens
     * @return list<string>
     */
    public function suggestCandidateLexemes(array $queryTokens, int $limit = 400): array
    {
        CatalogSearchIndexSchema::ensureInfrastructure();
        if ($queryTokens === []) {
            return [];
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $lexTable = 'myapp_catalog_search_lexeme';
        $plTable = 'myapp_catalog_search_product_lexeme';
        $docTable = SearchIndexDocumentTable::getTableName();

        $limit = max(1, min(500, $limit));
        $conditions = [];
        foreach ($queryTokens as $token) {
            if (mb_strlen($token, 'UTF-8') < 2) {
                continue;
            }
            $prefix = $helper->forSql($token) . '%';
            $conditions[] = "l.LEXEME_TEXT LIKE '{$prefix}'";
        }
        if ($conditions === []) {
            return [];
        }

        $where = implode(' OR ', $conditions);
        $res = $connection->query("
            SELECT l.LEXEME_TEXT AS T, COUNT(DISTINCT pl.PRODUCT_ID) AS CNT
            FROM {$lexTable} l
            INNER JOIN {$plTable} pl ON pl.LEXEME_ID = l.ID
            INNER JOIN {$docTable} d ON d.PRODUCT_ID = pl.PRODUCT_ID AND d.ACTIVE = 'Y'
            WHERE ({$where})
            GROUP BY l.LEXEME_TEXT
            ORDER BY CNT DESC
            LIMIT {$limit}
        ");

        $out = [];
        while ($row = $res->fetch()) {
            $t = (string)($row['T'] ?? '');
            if ($t !== '' && CatalogSearchTextNormalizer::isValidTokenForSql($t)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    public function getIndexedLinksCount(): int
    {
        CatalogSearchIndexSchema::ensureInfrastructure();

        return (int)Application::getConnection()->queryScalar('SELECT COUNT(*) FROM myapp_catalog_search_product_lexeme');
    }

    /**
     * Лексемы, в которых есть подстрока $token (через триграммы + LOCATE).
     *
     * @return list<int>
     */
    private function resolveLexemesForQueryToken(string $token): array
    {
        $len = mb_strlen($token, 'UTF-8');
        if ($len >= 3) {
            return $this->resolveLexemesByTrigrams($token);
        }

        return $this->resolveShortLexemes($token);
    }

    /**
     * @return list<int>
     */
    private function resolveLexemesByTrigrams(string $token): array
    {
        $tris = CatalogSearchTrigram::extractTrigrams($token);
        if ($tris === []) {
            return [];
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $ltTable = 'myapp_catalog_search_lexeme_trigram';
        $lexTable = 'myapp_catalog_search_lexeme';

        $stats = [];
        foreach ($tris as $tri) {
            if (!self::isValidTrigram($tri)) {
                continue;
            }
            $tesc = $helper->forSql($tri);
            $stats[$tri] = (int)$connection->queryScalar("SELECT COUNT(*) FROM {$ltTable} WHERE TRIGRAM = '{$tesc}'");
        }
        if ($stats === []) {
            return [];
        }

        asort($stats);
        $orderedTris = array_keys($stats);
        $firstTri = $orderedTris[0];
        $fesc = $helper->forSql($firstTri);

        $ids = [];
        $res = $connection->query("SELECT LEXEME_ID FROM {$ltTable} WHERE TRIGRAM = '{$fesc}'");
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['LEXEME_ID'];
        }
        if ($ids === []) {
            return [];
        }

        for ($i = 1, $n = count($orderedTris); $i < $n; ++$i) {
            $tri = $orderedTris[$i];
            $tesc = $helper->forSql($tri);
            $next = [];
            $chunkSize = 400;
            $chunks = array_chunk($ids, $chunkSize);
            foreach ($chunks as $chunk) {
                $inSql = implode(',', array_map('intval', $chunk));
                $sub = $connection->query("
                    SELECT LEXEME_ID FROM {$ltTable}
                    WHERE TRIGRAM = '{$tesc}' AND LEXEME_ID IN ({$inSql})
                ");
                while ($row = $sub->fetch()) {
                    $next[] = (int)$row['LEXEME_ID'];
                }
            }
            $ids = array_values(array_unique($next));
            if ($ids === []) {
                return [];
            }
        }

        $tokenEsc = $helper->forSql($token);
        $confirmed = [];
        foreach (array_chunk($ids, 300) as $chunk) {
            $inSql = implode(',', array_map('intval', $chunk));
            $res = $connection->query("
                SELECT ID FROM {$lexTable}
                WHERE ID IN ({$inSql})
                  AND LOCATE('{$tokenEsc}', LEXEME_TEXT) > 0
            ");
            while ($row = $res->fetch()) {
                $confirmed[] = (int)$row['ID'];
            }
        }

        return $confirmed;
    }

    /**
     * @return list<int>
     */
    private function resolveShortLexemes(string $token): array
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $lexTable = 'myapp_catalog_search_lexeme';

        $tokenEsc = $helper->forSql($token);
        $like = $helper->forSql($token) . '%';

        $ids = [];
        $res = $connection->query("
            SELECT ID FROM {$lexTable}
            WHERE LEXEME_TEXT = '{$tokenEsc}' OR LEXEME_TEXT LIKE '{$like}'
            LIMIT 500
        ");
        while ($row = $res->fetch()) {
            $ids[] = (int)$row['ID'];
        }

        return $ids;
    }

    /**
     * @param list<int> $lexemeIds
     * @return list<int>
     */
    private function productIdsForLexemeIds(array $lexemeIds): array
    {
        $lexemeIds = array_values(array_filter(array_map('intval', $lexemeIds), static fn(int $id): bool => $id > 0));
        if ($lexemeIds === []) {
            return [];
        }

        $connection = Application::getConnection();
        $plTable = 'myapp_catalog_search_product_lexeme';
        $docTable = SearchIndexDocumentTable::getTableName();
        $pids = [];
        foreach (array_chunk($lexemeIds, 400) as $chunk) {
            $inLex = implode(',', $chunk);
            $res = $connection->query("
                SELECT DISTINCT pl.PRODUCT_ID
                FROM {$plTable} pl
                INNER JOIN {$docTable} d ON d.PRODUCT_ID = pl.PRODUCT_ID AND d.ACTIVE = 'Y'
                WHERE pl.LEXEME_ID IN ({$inLex})
            ");
            while ($row = $res->fetch()) {
                $pids[] = (int)$row['PRODUCT_ID'];
            }
        }

        return array_values(array_unique($pids));
    }

    /**
     * @param list<int> $productIds
     * @param array<string, list<int>> $tokenToLexemeIds
     * @return array<int, int>
     */
    private function scoreProducts(array $productIds, array $tokenToLexemeIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $connection = Application::getConnection();
        $plTable = 'myapp_catalog_search_product_lexeme';
        $docTable = SearchIndexDocumentTable::getTableName();
        $idsSql = implode(',', array_map('intval', $productIds));

        $scores = array_fill_keys($productIds, 0);

        foreach ($tokenToLexemeIds as $token => $lexIds) {
            $lexIds = array_values(array_filter(array_map('intval', $lexIds), static fn(int $id): bool => $id > 0));
            if ($lexIds === []) {
                continue;
            }
            $inLex = implode(',', $lexIds);
            $res = $connection->query("
                SELECT pl.PRODUCT_ID, MAX(pl.WEIGHT) AS MW
                FROM {$plTable} pl
                WHERE pl.PRODUCT_ID IN ({$idsSql})
                  AND pl.LEXEME_ID IN ({$inLex})
                GROUP BY pl.PRODUCT_ID
            ");
            while ($row = $res->fetch()) {
                $pid = (int)$row['PRODUCT_ID'];
                $scores[$pid] = (int)($scores[$pid] ?? 0) + (int)$row['MW'];
            }
        }

        $nameRes = $connection->query("
            SELECT PRODUCT_ID, NAME_NORM
            FROM {$docTable}
            WHERE PRODUCT_ID IN ({$idsSql})
        ");
        while ($row = $nameRes->fetch()) {
            $productId = (int)$row['PRODUCT_ID'];
            $nameNorm = (string)($row['NAME_NORM'] ?? '');
            foreach (array_keys($tokenToLexemeIds) as $token) {
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

    /**
     * Порог по сумме весов (MAX на токен): не ниже суммы минимальных весовых вкладов — по одному
     * на токен с полем DETAIL (2), иначе совпадение только в PREVIEW (3) или одно слово в анонсе
     * отсекалось бы при старом пороге 5.
     *
     * @param list<string> $tokens
     */
    private function minimumScoreThreshold(array $tokens): int
    {
        $n = count($tokens);

        return CatalogSearchIndexService::WEIGHT_DETAIL * $n;
    }

    private static function isValidTrigram(string $tri): bool
    {
        return mb_strlen($tri, 'UTF-8') === CatalogSearchTrigram::N
            && CatalogSearchTextNormalizer::isValidTokenForSql($tri);
    }

    /**
     * @return array{product_ids: list<int>, total: int, scores: array<int, int>, search_mode: string}
     */
    private static function emptyResult(string $mode): array
    {
        return [
            'product_ids' => [],
            'total' => 0,
            'scores' => [],
            'search_mode' => $mode,
        ];
    }
}
