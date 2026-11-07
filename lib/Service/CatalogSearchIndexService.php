<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Install\CatalogSearchIndexSchema;
use MyApp\Catalog\Model\SearchIndexDocumentTable;
use MyApp\Catalog\Model\SearchIndexMetaTable;
use MyApp\Catalog\Search\CatalogSearchBackendFactory;
use MyApp\Catalog\Search\CatalogSearchBackendInterface;

final class CatalogSearchIndexService
{
    private const MODULE_ID = 'myapp.catalog';

    private const OPT_READY = 'catalog_search_index_ready';

    /** Инкремент при любом изменении поискового индекса — ключ кеша total (см. CatalogSearchTotalCacheService). */
    private const OPT_SEARCH_TOTAL_CACHE_EPOCH = 'catalog_search_total_cache_epoch';

    public const WEIGHT_NAME = 10;

    public const WEIGHT_PARENT_PROP = 6;

    public const WEIGHT_OFFER = 5;

    public const WEIGHT_PREVIEW = 3;

    public const WEIGHT_DETAIL = 2;

    public const WEIGHT_STEM = 4;

    public static function searchConfigHash(): string
    {
        return md5(
            Assets::catalogIblockId()
            . '|' . CatalogSkuService::getOffersIblockId()
            . '|' . implode("\n", Assets::searchProductPropertyCodes())
            . '|' . implode("\n", Assets::searchOfferPropertyCodes())
            . '|' . (Assets::indexProductPreviewText() ? '1' : '0')
            . '|' . (Assets::indexProductDetailText() ? '1' : '0')
            . '|' . CatalogSearchBackendFactory::getBackendId()
            . '|v3_lexeme_trigram'
        );
    }

    public static function getSearchTotalCacheEpoch(): int
    {
        return max(0, (int)Option::get(self::MODULE_ID, self::OPT_SEARCH_TOTAL_CACHE_EPOCH, '0'));
    }

    private static function bumpSearchTotalCacheEpoch(): void
    {
        $next = self::getSearchTotalCacheEpoch() + 1;
        Option::set(self::MODULE_ID, self::OPT_SEARCH_TOTAL_CACHE_EPOCH, (string)$next);
    }

    private static function searchBackend(): CatalogSearchBackendInterface
    {
        return CatalogSearchBackendFactory::create();
    }

    public static function isIndexReady(): bool
    {
        if (Option::get(self::MODULE_ID, self::OPT_READY, 'N') !== 'Y') {
            return false;
        }

        $meta = self::getMetaRow();
        if ($meta === null) {
            return false;
        }

        return (string)($meta['CONFIG_HASH'] ?? '') === self::searchConfigHash();
    }

    public static function isIndexConfigStale(): bool
    {
        CatalogSearchIndexSchema::ensureInfrastructure();

        $expected = self::searchConfigHash();
        $meta = self::getMetaRow();
        if ($meta === null) {
            return false;
        }

        $stored = (string)($meta['CONFIG_HASH'] ?? '');
        if ($stored === $expected) {
            return false;
        }

        $documentsCount = (int)($meta['DOCUMENTS_COUNT'] ?? 0);
        if ($stored === '' && $documentsCount <= 0) {
            return false;
        }

        return true;
    }

    public static function markNotReady(): void
    {
        Option::set(self::MODULE_ID, self::OPT_READY, 'N');
    }

    public static function markReady(): void
    {
        Option::set(self::MODULE_ID, self::OPT_READY, 'Y');
    }

    public static function buildFullIndex(): int
    {
        return CatalogSearchIndexJobService::startJob(CatalogSearchIndexJobService::TYPE_FULL);
    }

    public static function countCatalogProducts(): int
    {
        CatalogSearchIndexSchema::ensureInfrastructure();
        Loader::includeModule('iblock');
        $iblockId = Assets::catalogIblockId();

        return (int)Application::getConnection()->queryScalar("
            SELECT COUNT(*)
            FROM b_iblock_element
            WHERE IBLOCK_ID = {$iblockId} AND ACTIVE = 'Y'
        ");
    }

    public static function removeProduct(int $productId): void
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return;
        }

        CatalogSearchIndexSchema::ensureInfrastructure();

        self::searchBackend()->removeProductIndex($productId);
        self::bumpSearchTotalCacheEpoch();
    }

    public static function rebuildProduct(int $productId): void
    {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return;
        }

        CatalogSearchIndexSchema::ensureInfrastructure();
        Loader::includeModule('iblock');

        self::searchBackend()->removeProductIndex($productId);

        $iblockId = Assets::catalogIblockId();
        $row = Application::getConnection()->query("
            SELECT ID, IBLOCK_ID, ACTIVE, SORT, NAME, PREVIEW_TEXT, DETAIL_TEXT
            FROM b_iblock_element
            WHERE ID = {$productId} AND IBLOCK_ID = {$iblockId}
            LIMIT 1
        ")->fetch();

        if (!$row || (string)$row['ACTIVE'] !== 'Y') {
            self::bumpSearchTotalCacheEpoch();

            return;
        }

        $nameNorm = CatalogSearchTextNormalizer::normalizeText((string)$row['NAME']);
        $bodyParts = [];

        if (Assets::indexProductPreviewText()) {
            $bodyParts[] = CatalogSearchTextNormalizer::normalizeText((string)($row['PREVIEW_TEXT'] ?? ''));
        }
        if (Assets::indexProductDetailText()) {
            $bodyParts[] = CatalogSearchTextNormalizer::normalizeText((string)($row['DETAIL_TEXT'] ?? ''));
        }

        $productPropCodes = Assets::searchProductPropertyCodes();
        if ($productPropCodes !== []) {
            foreach (self::collectPropertyTexts($productId, $iblockId, $productPropCodes) as $text) {
                $bodyParts[] = $text;
            }
        }

        $offerTexts = self::collectOfferTexts($productId);
        $bodyParts = array_values(array_filter($bodyParts, static fn(string $p): bool => $p !== ''));
        $bodyNorm = implode(' ', $bodyParts);

        /** @var array<string, int> $lexemeWeights lexeme => max weight */
        $lexemeWeights = [];

        self::addLexemesFromText($lexemeWeights, $nameNorm, self::WEIGHT_NAME, true);
        if (Assets::indexProductPreviewText()) {
            self::addLexemesFromText(
                $lexemeWeights,
                CatalogSearchTextNormalizer::normalizeText((string)($row['PREVIEW_TEXT'] ?? '')),
                self::WEIGHT_PREVIEW,
                false
            );
        }
        if (Assets::indexProductDetailText()) {
            self::addLexemesFromText(
                $lexemeWeights,
                CatalogSearchTextNormalizer::normalizeText((string)($row['DETAIL_TEXT'] ?? '')),
                self::WEIGHT_DETAIL,
                false
            );
        }

        foreach (self::collectPropertyTexts($productId, $iblockId, $productPropCodes) as $text) {
            self::addLexemesFromText($lexemeWeights, $text, self::WEIGHT_PARENT_PROP, false);
        }

        foreach ($offerTexts as $chunk) {
            self::addLexemesFromText($lexemeWeights, $chunk['text'], self::WEIGHT_OFFER, false);
        }

        SearchIndexDocumentTable::add([
            'PRODUCT_ID' => $productId,
            'IBLOCK_ID' => (int)$row['IBLOCK_ID'],
            'ACTIVE' => 'Y',
            'SORT' => (int)$row['SORT'],
            'NAME_NORM' => mb_substr($nameNorm, 0, 500, 'UTF-8'),
            'BODY_NORM' => $bodyNorm !== '' ? $bodyNorm : null,
            'UPDATED_AT' => new DateTime(),
            'LAST_ERROR' => null,
        ]);

        self::searchBackend()->indexProductLexemes($productId, $lexemeWeights);
        self::bumpSearchTotalCacheEpoch();
    }

    /**
     * @param array<string, int> $lexemeWeights
     */
    private static function addLexemesFromText(
        array &$lexemeWeights,
        string $text,
        int $weight,
        bool $withStem
    ): void {
        $norm = CatalogSearchTextNormalizer::normalizeText($text);
        if ($norm === '') {
            return;
        }

        $tokens = CatalogSearchTextNormalizer::extractTokens($norm, true, $withStem);
        foreach ($tokens as $token) {
            if (!CatalogSearchTextNormalizer::isValidTokenForSql($token)) {
                continue;
            }
            $lexemeWeights[$token] = max($lexemeWeights[$token] ?? 0, $weight);
        }

        if (!$withStem) {
            return;
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $norm, $matches);
        foreach ($matches[0] ?? [] as $piece) {
            $len = mb_strlen((string)$piece, 'UTF-8');
            if ($len < 6) {
                continue;
            }
            $stem = CatalogSearchTextNormalizer::clipToken(
                mb_substr((string)$piece, 0, $len - 2, 'UTF-8')
            );
            if (mb_strlen($stem, 'UTF-8') >= 3 && CatalogSearchTextNormalizer::isValidTokenForSql($stem)) {
                $lexemeWeights[$stem] = max($lexemeWeights[$stem] ?? 0, self::WEIGHT_STEM);
            }
        }
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    private static function collectPropertyTexts(int $elementId, int $iblockId, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $codeMap = array_fill_keys($codes, true);
        $texts = [];

        $res = \CIBlockElement::GetProperty($iblockId, $elementId, ['sort' => 'asc'], ['ACTIVE' => 'Y']);
        while ($prop = $res->Fetch()) {
            $code = (string)($prop['CODE'] ?? '');
            if ($code === '' || !isset($codeMap[$code])) {
                continue;
            }
            $value = self::propertyValueToString($prop);
            if ($value !== '') {
                $texts[] = CatalogSearchTextNormalizer::normalizeText($value);
            }
        }

        return $texts;
    }

    /**
     * @return list<array{text: string, source: string}>
     */
    private static function collectOfferTexts(int $parentId): array
    {
        $offersIblockId = CatalogSkuService::getOffersIblockId();
        if ($offersIblockId <= 0) {
            return [];
        }

        $offerCodes = Assets::searchOfferPropertyCodes();
        $chunks = [];
        $offerIds = CatalogSkuService::getOfferIdsForProduct($parentId);
        if ($offerIds === []) {
            return [];
        }

        $connection = Application::getConnection();
        $idsSql = implode(',', array_map('intval', $offerIds));
        $res = $connection->query("
            SELECT ID, NAME
            FROM b_iblock_element
            WHERE ID IN ({$idsSql}) AND IBLOCK_ID = {$offersIblockId} AND ACTIVE = 'Y'
        ");

        while ($offer = $res->fetch()) {
            $offerId = (int)$offer['ID'];
            $name = CatalogSearchTextNormalizer::normalizeText((string)$offer['NAME']);
            if ($name !== '') {
                $chunks[] = ['text' => $name, 'source' => 'offer_name'];
            }
            foreach (self::collectPropertyTexts($offerId, $offersIblockId, $offerCodes) as $text) {
                $chunks[] = ['text' => $text, 'source' => 'offer_prop'];
            }
        }

        return $chunks;
    }

    /**
     * Текст для индекса: подписи enum, имена связей, не сырые ID (и для товара, и для ТП).
     *
     * @param array<string, mixed> $prop
     */
    private static function propertyValueToString(array $prop): string
    {
        $type = (string)($prop['PROPERTY_TYPE'] ?? '');
        $userType = (string)($prop['USER_TYPE'] ?? '');

        if ($type === 'L') {
            $enumId = (int)($prop['VALUE_ENUM_ID'] ?? 0);
            if ($enumId <= 0 && is_numeric($prop['VALUE'] ?? '')) {
                $enumId = (int)$prop['VALUE'];
            }
            if ($enumId > 0) {
                $enum = \CIBlockPropertyEnum::GetByID($enumId);
                if (is_array($enum) && !empty($enum['VALUE'])) {
                    return trim((string)$enum['VALUE']);
                }
            }
            $label = trim((string)($prop['VALUE_ENUM'] ?? ''));
            if ($label !== '' && !ctype_digit($label)) {
                return $label;
            }

            return '';
        }

        $value = $prop['VALUE'] ?? '';
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $part = self::scalarPropertyValueToString($type, $userType, $item);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }

            return implode(' ', $parts);
        }

        return self::scalarPropertyValueToString($type, $userType, $value);
    }

    private static function scalarPropertyValueToString(string $type, string $userType, mixed $value): string
    {
        $stringValue = trim((string)$value);
        if ($stringValue === '') {
            return '';
        }

        switch ($type) {
            case 'N':
                if (is_numeric($stringValue)) {
                    return rtrim(rtrim(number_format((float)$stringValue, 6, '.', ''), '0'), '.');
                }

                return $stringValue;
            case 'E':
                $linkedId = (int)$stringValue;
                if ($linkedId <= 0) {
                    return '';
                }
                $row = \CIBlockElement::GetByID($linkedId)->GetNext();

                return $row ? trim((string)($row['NAME'] ?? '')) : '';
            case 'G':
                $linkedId = (int)$stringValue;
                if ($linkedId <= 0) {
                    return '';
                }
                $row = \CIBlockSection::GetByID($linkedId)->GetNext();

                return $row ? trim((string)($row['NAME'] ?? '')) : '';
            case 'F':
                $fileId = (int)$stringValue;
                if ($fileId <= 0) {
                    return '';
                }
                $file = \CFile::GetFileArray($fileId);
                if (!is_array($file)) {
                    return '';
                }
                $originalName = trim((string)($file['ORIGINAL_NAME'] ?? ''));
                if ($originalName !== '') {
                    return $originalName;
                }

                return trim((string)($file['FILE_NAME'] ?? ''));
            default:
                if (
                    strcasecmp($userType, 'HTML') === 0
                    || strcasecmp($type, 'HTML') === 0
                    || strcasecmp($type, 'S:HTML') === 0
                ) {
                    $decoded = html_entity_decode($stringValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    return trim(strip_tags($decoded));
                }

                return $stringValue;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getMetaRow(): ?array
    {
        CatalogSearchIndexSchema::ensureInfrastructure();
        $row = SearchIndexMetaTable::getByPrimary(SearchIndexMetaTable::ROW_ID)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function updateMeta(array $fields): void
    {
        CatalogSearchIndexSchema::ensureInfrastructure();
        $exists = SearchIndexMetaTable::getByPrimary(SearchIndexMetaTable::ROW_ID)->fetch();
        if ($exists) {
            SearchIndexMetaTable::update(SearchIndexMetaTable::ROW_ID, $fields);
        } else {
            SearchIndexMetaTable::add(array_merge(['ID' => SearchIndexMetaTable::ROW_ID], $fields));
        }
    }

    public static function refreshMetaCounts(): void
    {
        CatalogSearchIndexSchema::ensureInfrastructure();
        $connection = Application::getConnection();
        $documents = (int)$connection->queryScalar(
            'SELECT COUNT(*) FROM ' . SearchIndexDocumentTable::getTableName()
        );
        $tokens = self::searchBackend()->getIndexedLinksCount();
        self::updateMeta([
            'DOCUMENTS_COUNT' => $documents,
            'TOKENS_COUNT' => $tokens,
        ]);
    }

    public static function finalizeFullBuild(?string $lastError = null): void
    {
        self::refreshMetaCounts();
        $meta = self::getMetaRow() ?? [];
        $version = (int)($meta['INDEX_VERSION'] ?? 0);
        self::updateMeta([
            'INDEX_VERSION' => $version + 1,
            'CONFIG_HASH' => self::searchConfigHash(),
            'LAST_FULL_SYNC_AT' => new DateTime(),
            'LAST_ERROR' => $lastError !== null && $lastError !== '' ? $lastError : null,
        ]);
        self::markReady();
        self::bumpSearchTotalCacheEpoch();
        CatalogSearchTotalCacheService::clearCache();
    }

    /**
     * @return array<string, mixed>
     */
    public static function getIndexStatus(): array
    {
        CatalogSearchIndexSchema::ensureInfrastructure();

        $meta = self::getMetaRow() ?? [];

        return [
            'index_version' => (int)($meta['INDEX_VERSION'] ?? 0),
            'config_hash' => (string)($meta['CONFIG_HASH'] ?? ''),
            'expected_config_hash' => self::searchConfigHash(),
            'search_backend' => CatalogSearchBackendFactory::getBackendId(),
            'documents_count' => (int)($meta['DOCUMENTS_COUNT'] ?? 0),
            'tokens_count' => (int)($meta['TOKENS_COUNT'] ?? 0),
            'last_full_sync_at' => $meta['LAST_FULL_SYNC_AT'] ?? null,
            'last_error' => $meta['LAST_ERROR'] ?? null,
            'index_ready' => self::isIndexReady(),
            'index_config_stale' => self::isIndexConfigStale(),
            'queue_size' => CatalogSearchIndexQueueService::count(),
            'running_job' => CatalogSearchIndexJobService::getRunningJob(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function getRecentProductErrors(int $limit = 15): array
    {
        CatalogSearchIndexSchema::ensureInfrastructure();
        $limit = max(1, min(100, $limit));
        $table = SearchIndexDocumentTable::getTableName();
        $res = Application::getConnection()->query("
            SELECT PRODUCT_ID, LAST_ERROR, UPDATED_AT
            FROM {$table}
            WHERE LAST_ERROR IS NOT NULL AND LAST_ERROR <> ''
            ORDER BY UPDATED_AT DESC
            LIMIT {$limit}
        ");
        $items = [];
        while ($row = $res->fetch()) {
            $items[] = $row;
        }

        return $items;
    }

    public static function onSearchConfigChanged(): void
    {
        self::markNotReady();
    }

    public static function setLastError(?string $message): void
    {
        self::updateMeta([
            'LAST_ERROR' => $message !== null && $message !== '' ? $message : null,
        ]);
    }

    public static function clearIndexAndQueue(): void
    {
        CatalogSearchIndexSchema::truncateIndexData();
        CatalogSearchIndexQueueService::clear();
        self::markNotReady();
        self::updateMeta([
            'DOCUMENTS_COUNT' => 0,
            'TOKENS_COUNT' => 0,
            'LAST_ERROR' => null,
        ]);
        self::bumpSearchTotalCacheEpoch();
        CatalogSearchTotalCacheService::clearCache();
    }
}
