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
    public function searchProductIds(string $query, int $page, int $limit, bool $requireTotalCount = true): array
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

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $plTable = 'myapp_catalog_search_product_lexeme';
        $docTable = SearchIndexDocumentTable::getTableName();

        $minScore = $this->minimumScoreThreshold($tokens);

        $productInConditions = [];
        $maxWeightSelects = [];
        $mwAliases = [];
        foreach (array_values($tokens) as $ti => $token) {
            $lexIds = $tokenToLexemeIds[$token] ?? [];
            $lexIds = array_values(array_filter(array_map('intval', $lexIds), static fn(int $id): bool => $id > 0));
            if ($lexIds === []) {
                return self::emptyResult('strict');
            }

            $lexIn = self::columnInIntChunks('plx.LEXEME_ID', $lexIds);
            $productInConditions[] = 'd.PRODUCT_ID IN (
                SELECT DISTINCT plx.PRODUCT_ID
                FROM ' . $plTable . ' plx
                INNER JOIN ' . $docTable . ' dx ON dx.PRODUCT_ID = plx.PRODUCT_ID AND dx.ACTIVE = \'Y\'
                WHERE ' . $lexIn . '
            )';

            $lexInMw = self::columnInIntChunks('plw.LEXEME_ID', $lexIds);
            $alias = 'mw' . $ti;
            $mwAliases[] = $alias;
            $maxWeightSelects[] = '(
                SELECT MAX(plw.WEIGHT) FROM ' . $plTable . ' plw
                WHERE plw.PRODUCT_ID = d.PRODUCT_ID AND (' . $lexInMw . ')
            ) AS ' . $alias;
        }

        $whereProductIntersection = implode("\n                AND ", $productInConditions);

        // Два уровня: сначала mw* как столбцы (без total_score), иначе MySQL ругается на ссылку mw0 в том же SELECT.
        $selectMw = implode(', ', $maxWeightSelects);
        $baseSubquery = '
            SELECT d.PRODUCT_ID, d.NAME_NORM, ' . $selectMw . '
            FROM ' . $docTable . ' d
            WHERE d.ACTIVE = \'Y\'
            AND ' . $whereProductIntersection . '
        ';

        // MySQL: имя алиаса «inner» — зарезервированное слово; без бэктиков подзапрос теряет алиас (ошибка 1248).
        $mwAlias = 'doc_mw';
        $nameBonusInner = self::buildNamePrefixBonusExpressionSql($helper, $tokens, $mwAlias . '.NAME_NORM');
        $sumMwInner = implode(
            ' + ',
            array_map(static fn(string $a): string => 'COALESCE(' . $mwAlias . '.' . $a . ',0)', $mwAliases)
        );
        $totalScoreExpr = '(' . $sumMwInner . ' + (' . $nameBonusInner . '))';

        $whereFilters = [];
        foreach ($mwAliases as $alias) {
            $whereFilters[] = 'COALESCE(scored.' . $alias . ',0) > 0';
        }
        $whereFilters[] = 'scored.total_score >= ' . (int)$minScore;
        $whereRankedSql = implode(' AND ', $whereFilters);

        $scoredSubquery = '
            SELECT ' . $mwAlias . '.PRODUCT_ID, ' . $mwAlias . '.NAME_NORM, ' . implode(
                ', ',
                array_map(static fn(string $a): string => $mwAlias . '.' . $a, $mwAliases)
            ) . ', ' . $totalScoreExpr . ' AS total_score
            FROM (' . $baseSubquery . ') ' . $mwAlias . '
        ';

        $rankedSubquery = '
            SELECT scored.PRODUCT_ID, scored.NAME_NORM, ' . implode(
                ', ',
                array_map(static fn(string $a): string => 'scored.' . $a, $mwAliases)
            ) . ', scored.total_score
            FROM (' . $scoredSubquery . ') scored
            WHERE ' . $whereRankedSql . '
        ';

        $page = max(1, $page);
        $limit = max(1, $limit);
        $offset = ($page - 1) * $limit;

        $total = 0;
        if ($requireTotalCount) {
            $countSql = 'SELECT COUNT(*) AS CNT FROM (' . $rankedSubquery . ') t_count';
            $total = (int)$connection->queryScalar($countSql);
        } else {
            // Маркер: клиент подставляет total из кеша (первая страница того же запроса).
            $total = -1;
        }

        if ($requireTotalCount && $total <= 0) {
            return [
                'product_ids' => [],
                'total' => 0,
                'scores' => [],
                'search_mode' => 'strict',
            ];
        }

        $outerMw = [];
        foreach ($mwAliases as $alias) {
            $outerMw[] = 't_ranked.' . $alias;
        }

        $listSql = '
            SELECT t_ranked.PRODUCT_ID, t_ranked.NAME_NORM, ' . implode(', ', $outerMw) . '
            FROM (' . $rankedSubquery . ') t_ranked
            ORDER BY t_ranked.total_score DESC, t_ranked.PRODUCT_ID ASC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset . '
        ';

        $scores = [];
        $productIds = [];
        $res = $connection->query($listSql);
        while ($row = $res->fetch()) {
            $pid = (int)$row['PRODUCT_ID'];
            $productIds[] = $pid;
            $sum = 0;
            foreach ($mwAliases as $alias) {
                $sum += (int)($row[$alias] ?? 0);
            }
            $nameNorm = (string)($row['NAME_NORM'] ?? '');
            $bonus = 0;
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }
                if (
                    str_starts_with($nameNorm, $token)
                    || preg_match('/\s' . preg_quote($token, '/') . '/u', ' ' . $nameNorm)
                ) {
                    $bonus = self::NAME_PREFIX_BONUS;
                    break;
                }
            }
            $scores[$pid] = $sum + $bonus;
        }

        return [
            'product_ids' => $productIds,
            'total' => $total,
            'scores' => $scores,
            'search_mode' => 'strict',
        ];
    }

    private const IN_LIST_CHUNK_SIZE = 500;

    /**
     * @param list<int> $ids
     */
    private static function columnInIntChunks(string $columnSql, array $ids): string
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return '0';
        }

        $chunks = array_chunk($ids, self::IN_LIST_CHUNK_SIZE);
        if (count($chunks) === 1) {
            return $columnSql . ' IN (' . implode(',', $chunks[0]) . ')';
        }

        $parts = [];
        foreach ($chunks as $chunk) {
            $parts[] = $columnSql . ' IN (' . implode(',', $chunk) . ')';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * Бонус за слово в NAME_NORM (как в scoreProducts): префикс имени или после пробела.
     *
     * @param list<string> $tokens
     * @param string $nameColumn выражение столбца нормализованного имени (напр. doc_mw.NAME_NORM)
     */
    private static function buildNamePrefixBonusExpressionSql(
        \Bitrix\Main\DB\SqlHelper $helper,
        array $tokens,
        string $nameColumn = 'd.NAME_NORM'
    ): string {
        $ors = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $esc = $helper->forSql($token);
            $ors[] = "(LOCATE('{$esc}', {$nameColumn}) = 1 OR LOCATE(CONCAT(' ', '{$esc}'), CONCAT(' ', {$nameColumn})) > 0)";
        }

        if ($ors === []) {
            return '0';
        }

        return 'CASE WHEN ' . implode(' OR ', $ors) . ' THEN ' . self::NAME_PREFIX_BONUS . ' ELSE 0 END';
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
