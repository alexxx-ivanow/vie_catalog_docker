<?php

namespace MyApp\Catalog\Service;

/** Утилиты триграмм UTF-8 (символы, не байты). */
final class CatalogSearchTrigram
{
    public const N = 3;

    /**
     * @return list<string>
     */
    public static function extractTrigrams(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $len = mb_strlen($text, 'UTF-8');
        if ($len < self::N) {
            return [];
        }
        $out = [];
        $max = $len - self::N;
        for ($i = 0; $i <= $max; ++$i) {
            $tri = mb_substr($text, $i, self::N, 'UTF-8');
            if ($tri !== '' && mb_strlen($tri, 'UTF-8') === self::N) {
                $out[] = $tri;
            }
        }

        return array_values(array_unique($out));
    }
}
