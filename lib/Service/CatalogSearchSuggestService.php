<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use MyApp\Catalog\Install\CatalogSearchIndexSchema;
use MyApp\Catalog\Model\SearchIndexTokenTable;

final class CatalogSearchSuggestService
{
    /**
     * @param list<string> $queryTokens
     * @return list<string>
     */
    public static function suggestQueries(string $originalQuery, array $queryTokens, int $limit = 3): array
    {
        CatalogSearchIndexSchema::ensureInfrastructure();
        if ($queryTokens === []) {
            return [];
        }

        $limit = max(1, min(5, $limit));
        $candidates = self::loadCandidateTokens($queryTokens);
        if ($candidates === []) {
            return [];
        }

        $replacements = [];
        foreach ($queryTokens as $index => $token) {
            $replacements[$index] = self::bestReplacement($token, $candidates);
        }

        $suggestions = [];
        $baseTokens = $queryTokens;
        $hasChange = false;
        foreach ($replacements as $index => $replacement) {
            if ($replacement !== null && $replacement !== $queryTokens[$index]) {
                $hasChange = true;
                break;
            }
        }
        if (!$hasChange) {
            return [];
        }

        $variantTokens = $baseTokens;
        foreach ($replacements as $index => $replacement) {
            if ($replacement !== null) {
                $variantTokens[$index] = $replacement;
            }
        }
        $suggestions[] = implode(' ', $variantTokens);

        if (count($suggestions) >= $limit) {
            return array_slice($suggestions, 0, $limit);
        }

        foreach ($queryTokens as $index => $token) {
            $replacement = $replacements[$index] ?? null;
            if ($replacement === null || $replacement === $token) {
                continue;
            }
            $alt = $baseTokens;
            $alt[$index] = $replacement;
            $phrase = implode(' ', $alt);
            if (!in_array($phrase, $suggestions, true)) {
                $suggestions[] = $phrase;
            }
            if (count($suggestions) >= $limit) {
                break;
            }
        }

        $originalNorm = CatalogSearchTextNormalizer::normalizeText($originalQuery);
        $filtered = [];
        foreach ($suggestions as $phrase) {
            $norm = CatalogSearchTextNormalizer::normalizeText($phrase);
            if ($norm === '' || $norm === $originalNorm) {
                continue;
            }
            $filtered[] = $phrase;
        }

        return array_slice(array_values(array_unique($filtered)), 0, $limit);
    }

    /**
     * @param list<string> $queryTokens
     * @return list<string>
     */
    private static function loadCandidateTokens(array $queryTokens): array
    {
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $table = SearchIndexTokenTable::getTableName();
        $prefixes = [];
        foreach ($queryTokens as $token) {
            $prefix = mb_substr($token, 0, 2, 'UTF-8');
            if ($prefix !== '') {
                $prefixes[$prefix] = true;
            }
        }
        if ($prefixes === []) {
            return [];
        }

        $conditions = [];
        foreach (array_keys($prefixes) as $prefix) {
            $conditions[] = "TOKEN LIKE '" . $helper->forSql($prefix) . "%'";
        }

        $res = $connection->query("
            SELECT TOKEN, COUNT(*) AS CNT
            FROM {$table}
            WHERE " . implode(' OR ', $conditions) . "
            GROUP BY TOKEN
            ORDER BY CNT DESC
            LIMIT 400
        ");

        $tokens = [];
        while ($row = $res->fetch()) {
            $token = (string)$row['TOKEN'];
            if (CatalogSearchTextNormalizer::isValidTokenForSql($token)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * @param list<string> $candidates
     */
    private static function bestReplacement(string $token, array $candidates): ?string
    {
        if (CatalogSearchTextNormalizer::isNumericToken($token)) {
            return null;
        }

        $best = null;
        $bestDistance = 999;
        foreach ($candidates as $candidate) {
            if ($candidate === $token) {
                return $token;
            }
            $maxLen = max(mb_strlen($token, 'UTF-8'), mb_strlen($candidate, 'UTF-8'));
            if ($maxLen > 255) {
                continue;
            }
            $distance = levenshtein($token, $candidate);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        if ($best === null || $bestDistance > 2) {
            return null;
        }

        return $best;
    }
}
