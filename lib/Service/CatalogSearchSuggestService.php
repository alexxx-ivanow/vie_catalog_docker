<?php

namespace MyApp\Catalog\Service;

use MyApp\Catalog\Install\CatalogSearchIndexSchema;
use MyApp\Catalog\Search\CatalogSearchBackendFactory;

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
        $candidates = self::loadCandidateLexemesForBackend($queryTokens, 400);
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
    public static function loadCandidateLexemesForBackend(array $queryTokens, int $limit = 400): array
    {
        return CatalogSearchBackendFactory::create()->suggestCandidateLexemes($queryTokens, $limit);
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
