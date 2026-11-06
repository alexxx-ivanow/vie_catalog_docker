<?php

namespace MyApp\Catalog\Search;

/**
 * Бэкенд полнотекстового поиска каталога (индекс + поиск + подсказки).
 * Реализации: MySQL (лексемы + триграммы), при необходимости Elasticsearch/Sphinx.
 */
interface CatalogSearchBackendInterface
{
    /**
     * Удалить все индексные данные товара (документ/лексемы/старые токены — по реализации).
     */
    public function removeProductIndex(int $productId): void;

    /**
     * Записать индекс товара: нормализованный документ уже в search_document; сюда — лексемы и веса.
     *
     * @param array<string, int> $lexemeWeights ключ — нормализованная лексема (как в extractTokens), значение — max weight
     */
    public function indexProductLexemes(int $productId, array $lexemeWeights): void;

    /**
     * @return array{
     *   product_ids: list<int>,
     *   total: int,
     *   scores: array<int, int>,
     *   search_mode: string
     * }
     */
    public function searchProductIds(string $query, int $page, int $limit): array;

    /**
     * Кандидаты слов для подсказки «возможно вы имели в виду» (частые лексемы с префиксом).
     *
     * @param list<string> $queryTokens
     * @return list<string>
     */
    public function suggestCandidateLexemes(array $queryTokens, int $limit = 400): array;

    /** Число связей в индексе (для meta: TOKENS_COUNT). */
    public function getIndexedLinksCount(): int;
}
