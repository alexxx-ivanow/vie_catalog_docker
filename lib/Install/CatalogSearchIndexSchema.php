<?php

namespace MyApp\Catalog\Install;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

final class CatalogSearchIndexSchema
{
    private const MODULE_ID = 'myapp.catalog';

    private const OPT_SCHEMA_READY = 'catalog_search_index_schema_ready';

    /** @see CatalogSearchIndexService */
    private const OPT_INDEX_READY = 'catalog_search_index_ready';

    public static function ensureInfrastructure(): void
    {
        if (Option::get(self::MODULE_ID, self::OPT_SCHEMA_READY, 'N') !== 'Y') {
            self::createTables();
            Option::set(self::MODULE_ID, self::OPT_SCHEMA_READY, 'Y');
        }
        self::ensureLexemeTrigramTables();
    }

    private static function ensureLexemeTrigramTables(): void
    {
        $connection = Application::getConnection();
        if (!$connection->isTableExists('myapp_catalog_search_lexeme')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_search_lexeme (
                    ID INT NOT NULL AUTO_INCREMENT,
                    LEXEME_TEXT VARCHAR(64) NOT NULL,
                    PRIMARY KEY (ID),
                    UNIQUE KEY ux_myapp_csl_text (LEXEME_TEXT)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_search_lexeme_trigram')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_search_lexeme_trigram (
                    LEXEME_ID INT NOT NULL,
                    TRIGRAM CHAR(3) NOT NULL,
                    PRIMARY KEY (LEXEME_ID, TRIGRAM),
                    INDEX ix_myapp_cslt_trigram_lexeme (TRIGRAM, LEXEME_ID)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_search_product_lexeme')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_search_product_lexeme (
                    PRODUCT_ID INT NOT NULL,
                    LEXEME_ID INT NOT NULL,
                    WEIGHT INT NOT NULL DEFAULT 1,
                    PRIMARY KEY (PRODUCT_ID, LEXEME_ID),
                    INDEX ix_myapp_cspl_lexeme_product (LEXEME_ID, PRODUCT_ID)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }

        // Миграция: у старых инсталляций не было лексемных таблиц — флаг вынесен отдельно.
        $flag = 'catalog_search_lexeme_schema_v1';
        if (Option::get(self::MODULE_ID, $flag, 'N') === 'Y') {
            return;
        }

        // Добавляем опционально FULLTEXT на документе для коротких запросов (админка может отключить).
        $ft = $connection->query("SHOW INDEX FROM myapp_catalog_search_document WHERE Key_name = 'ft_myapp_csd_body'")->fetch();
        if ($ft === false) {
            try {
                $connection->queryExecute("
                    ALTER TABLE myapp_catalog_search_document
                    ADD FULLTEXT INDEX ft_myapp_csd_body (NAME_NORM, BODY_NORM)
                ");
            } catch (\Throwable $e) {
                // Нет InnoDB FULLTEXT или версия MySQL — короткие запросы без FULLTEXT недоступны
            }
        }

        Option::set(self::MODULE_ID, $flag, 'Y');
    }

    private static function createTables(): void
    {
        $connection = Application::getConnection();

        if (!$connection->isTableExists('myapp_catalog_search_meta')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_search_meta (
                    ID INT NOT NULL DEFAULT 1,
                    INDEX_VERSION INT NOT NULL DEFAULT 0,
                    CONFIG_HASH VARCHAR(32) NOT NULL DEFAULT '',
                    DOCUMENTS_COUNT INT NOT NULL DEFAULT 0,
                    TOKENS_COUNT INT NOT NULL DEFAULT 0,
                    LAST_FULL_SYNC_AT DATETIME NULL,
                    LAST_ERROR TEXT NULL,
                    PRIMARY KEY (ID)
                )
            ");
            $connection->queryExecute("
                INSERT INTO myapp_catalog_search_meta (ID, INDEX_VERSION, CONFIG_HASH)
                VALUES (1, 0, '')
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_search_document')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_search_document (
                    PRODUCT_ID INT NOT NULL,
                    IBLOCK_ID INT NOT NULL,
                    ACTIVE CHAR(1) NOT NULL DEFAULT 'Y',
                    SORT INT NOT NULL DEFAULT 500,
                    NAME_NORM VARCHAR(500) NOT NULL DEFAULT '',
                    BODY_NORM LONGTEXT NULL,
                    UPDATED_AT DATETIME NOT NULL,
                    LAST_ERROR TEXT NULL,
                    PRIMARY KEY (PRODUCT_ID),
                    INDEX ix_myapp_csd_active_iblock (ACTIVE, IBLOCK_ID)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_search_token')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_search_token (
                    PRODUCT_ID INT NOT NULL,
                    TOKEN VARCHAR(64) NOT NULL,
                    WEIGHT TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    SOURCE VARCHAR(16) NOT NULL DEFAULT 'name',
                    PRIMARY KEY (PRODUCT_ID, TOKEN),
                    INDEX ix_myapp_cst_token_product (TOKEN, PRODUCT_ID)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_search_queue')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_search_queue (
                    PRODUCT_ID INT NOT NULL,
                    JOB_ID INT NOT NULL DEFAULT 0,
                    CREATED_AT DATETIME NOT NULL,
                    PRIMARY KEY (PRODUCT_ID),
                    INDEX ix_myapp_csq_job (JOB_ID)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_search_job')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_search_job (
                    ID INT NOT NULL AUTO_INCREMENT,
                    TYPE VARCHAR(32) NOT NULL,
                    STATUS VARCHAR(16) NOT NULL,
                    TOTAL INT NOT NULL DEFAULT 0,
                    PROCESSED INT NOT NULL DEFAULT 0,
                    ERROR_COUNT INT NOT NULL DEFAULT 0,
                    CURRENT_PRODUCT_ID INT NOT NULL DEFAULT 0,
                    PARAMS_JSON TEXT NULL,
                    LAST_ERROR TEXT NULL,
                    STARTED_AT DATETIME NULL,
                    FINISHED_AT DATETIME NULL,
                    CREATED_AT DATETIME NOT NULL,
                    PRIMARY KEY (ID),
                    INDEX ix_myapp_csj_status (STATUS)
                )
            ");
        }
    }

    public static function resetInstallFlags(): void
    {
        Option::delete(self::MODULE_ID, ['name' => self::OPT_SCHEMA_READY]);
    }

    public static function truncateIndexData(): void
    {
        self::ensureInfrastructure();
        $connection = Application::getConnection();
        foreach ([
            'myapp_catalog_search_product_lexeme',
            'myapp_catalog_search_lexeme_trigram',
            'myapp_catalog_search_lexeme',
            'myapp_catalog_search_token',
            'myapp_catalog_search_document',
        ] as $table) {
            if ($connection->isTableExists($table)) {
                $connection->truncateTable($table);
            }
        }
        Option::set(self::MODULE_ID, self::OPT_INDEX_READY, 'N');
    }
}
