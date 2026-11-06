<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use MyApp\Catalog\Search\CatalogSearchBackendInterface;
use MyApp\Catalog\Model\SearchIndexDocumentTable;
use MyApp\Catalog\Model\SearchIndexTokenTable;

/**
 * Старый поиск: токены привязаны к PRODUCT_ID; префиксное совпадение.
 */
final class LegacyTokenCatalogSearchBackend implements CatalogSearchBackendInterface
{
    public function removeProductIndex(int $productId): void
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return;
        }
        $connection = Application::getConnection();
        $tokenTable = SearchIndexTokenTable::getTableName();
        $connection->queryExecute("DELETE FROM {$tokenTable} WHERE PRODUCT_ID = {$productId}");
        if (SearchIndexDocumentTable::getByPrimary($productId)->fetch()) {
            SearchIndexDocumentTable::delete($productId);
        }
    }

    public function indexProductLexemes(int $productId, array $lexemeWeights): void
    {
        $productId = (int)$productId;
        foreach ($lexemeWeights as $token => $weight) {
            if (!CatalogSearchTextNormalizer::isValidTokenForSql((string)$token)) {
                continue;
            }
            SearchIndexTokenTable::add([
                'PRODUCT_ID' => $productId,
                'TOKEN' => (string)$token,
                'WEIGHT' => min(255, max(1, (int)$weight)),
                'SOURCE' => 'mixed',
            ]);
        }
    }

    public function searchProductIds(string $query, int $page, int $limit): array
    {
        return CatalogSearchQueryService::searchProductIds($query, $page, $limit);
    }

    public function suggestCandidateLexemes(array $queryTokens, int $limit = 400): array
    {
        return CatalogSearchSuggestService::loadCandidateLexemesForBackend($queryTokens, $limit);
    }

    public function getIndexedLinksCount(): int
    {
        $table = SearchIndexTokenTable::getTableName();

        return (int)Application::getConnection()->queryScalar("SELECT COUNT(*) FROM {$table}");
    }
}
