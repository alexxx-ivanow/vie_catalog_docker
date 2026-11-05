<?php

namespace MyApp\Catalog\Service;

final class CatalogSearchTextNormalizer
{
    /** @var list<string> */
    private const STOP_WORDS = [
        'и', 'в', 'во', 'на', 'с', 'со', 'к', 'ко', 'у', 'о', 'об', 'от', 'до', 'по', 'за', 'из', 'для', 'не', 'но',
        'а', 'или', 'как', 'что', 'это', 'при', 'без', 'над', 'под', 'the', 'and', 'or',
    ];

    public static function normalizeText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace('ё', 'е', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return list<string>
     */
    public static function tokenizeQuery(string $query): array
    {
        $norm = self::normalizeText($query);
        if ($norm === '') {
            return [];
        }

        // В запросе stem не добавляем: иначе AND требует лишние токены.
        $tokens = self::extractTokens($norm, true, false);
        $filtered = [];
        foreach ($tokens as $token) {
            if (in_array($token, self::STOP_WORDS, true)) {
                continue;
            }
            $filtered[] = $token;
        }

        return array_values(array_unique($filtered));
    }

    /**
     * @return list<string>
     */
    public static function extractTokens(
        string $normalizedText,
        bool $includeShortAlphanumeric,
        bool $withStem = true
    ): array {
        if ($normalizedText === '') {
            return [];
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $normalizedText, $matches);
        $raw = $matches[0] ?? [];
        $tokens = [];

        foreach ($raw as $piece) {
            $piece = mb_strtolower((string)$piece, 'UTF-8');
            $piece = str_replace('ё', 'е', $piece);
            if ($piece === '') {
                continue;
            }
            $len = mb_strlen($piece, 'UTF-8');
            if ($len >= 3) {
                $tokens[] = self::clipToken($piece);
                if ($withStem && $len >= 6) {
                    $stem = mb_substr($piece, 0, $len - 2, 'UTF-8');
                    if (mb_strlen($stem, 'UTF-8') >= 3) {
                        $tokens[] = self::clipToken($stem);
                    }
                }
                continue;
            }
            if ($includeShortAlphanumeric && $len >= 2 && preg_match('/^[a-z0-9]+$/u', $piece)) {
                $tokens[] = self::clipToken($piece);
            }
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    public static function clipToken(string $token): string
    {
        if (mb_strlen($token, 'UTF-8') <= 64) {
            return $token;
        }

        return mb_substr($token, 0, 64, 'UTF-8');
    }

    public static function isValidTokenForSql(string $token): bool
    {
        return $token !== '' && (bool)preg_match('/^[a-z0-9а-я]+$/u', $token);
    }

    /** Чисто числовые токены (артикулы, ID в тексте) — без fuzzy по Levenshtein. */
    public static function isNumericToken(string $token): bool
    {
        return $token !== '' && (bool)preg_match('/^\d+$/u', $token);
    }
}
