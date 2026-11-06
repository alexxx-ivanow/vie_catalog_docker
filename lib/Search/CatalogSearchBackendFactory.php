<?php

namespace MyApp\Catalog\Search;

use Bitrix\Main\Config\Option;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Service\LegacyTokenCatalogSearchBackend;
use MyApp\Catalog\Service\MysqlLexemeTrigramSearchBackend;

final class CatalogSearchBackendFactory
{
    public const BACKEND_MYSQL_LEXEME_TRIGRAM = 'mysql_lexeme_trigram';

    /** @deprecated сохранён для отката / отладки */
    public const BACKEND_LEGACY_TOKEN = 'legacy_token';

    private const MODULE_ID = 'myapp.catalog';

    public static function getBackendId(): string
    {
        $id = trim((string)Option::get(self::MODULE_ID, Assets::OPT_CATALOG_SEARCH_BACKEND, ''));
        if ($id === '') {
            return self::BACKEND_MYSQL_LEXEME_TRIGRAM;
        }

        return $id;
    }

    public static function create(): CatalogSearchBackendInterface
    {
        return match (self::getBackendId()) {
            self::BACKEND_LEGACY_TOKEN => new LegacyTokenCatalogSearchBackend(),
            default => new MysqlLexemeTrigramSearchBackend(),
        };
    }
}
