<?php

namespace MyApp\Catalog\Install;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

final class ProductFilterIndexSchema
{
    private const MODULE_ID = 'myapp.catalog';

    private const OPT_SCHEMA_READY = 'product_filter_index_schema_ready';

    private const OPT_PRICE_COLUMN_READY = 'product_filter_index_schema_price_v1';

    /** @see ProductFilterIndexService — дублируем имя option, чтобы не тянуть Service при InstallDB */
    private const OPT_INDEX_READY = 'product_filter_index_ready';

    public static function ensureInfrastructure(): void
    {
        if (Option::get(self::MODULE_ID, self::OPT_SCHEMA_READY, 'N') !== 'Y') {
            self::createTables();
            Option::set(self::MODULE_ID, self::OPT_SCHEMA_READY, 'Y');
        }

        self::ensurePriceColumn();
    }

    private static function createTables(): void
    {
        $connection = Application::getConnection();

        if (!$connection->isTableExists('myapp_catalog_filter_product')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_filter_product (
                    PRODUCT_ID INT NOT NULL,
                    IBLOCK_ID INT NOT NULL,
                    ACTIVE CHAR(1) NOT NULL DEFAULT 'Y',
                    SORT INT NOT NULL DEFAULT 500,
                    PRICE DECIMAL(18,4) NULL,
                    UPDATED_AT DATETIME NOT NULL,
                    LAST_ERROR TEXT NULL,
                    PRIMARY KEY (PRODUCT_ID),
                    INDEX ix_myapp_fp_active_iblock (ACTIVE, IBLOCK_ID)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_filter_product_section')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_filter_product_section (
                    PRODUCT_ID INT NOT NULL,
                    SECTION_ID INT NOT NULL,
                    PRIMARY KEY (PRODUCT_ID, SECTION_ID),
                    INDEX ix_myapp_fps_section_product (SECTION_ID, PRODUCT_ID)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_filter_value')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_filter_value (
                    ID BIGINT NOT NULL AUTO_INCREMENT,
                    PRODUCT_ID INT NOT NULL,
                    PROPERTY_ID INT NOT NULL,
                    VALUE_ENUM INT NULL,
                    VALUE_NUM DECIMAL(18,4) NULL,
                    VALUE_STR VARCHAR(500) NULL,
                    PRIMARY KEY (ID),
                    INDEX ix_myapp_fv_prop_enum (PROPERTY_ID, VALUE_ENUM, PRODUCT_ID),
                    INDEX ix_myapp_fv_prop_num (PROPERTY_ID, VALUE_NUM, PRODUCT_ID),
                    INDEX ix_myapp_fv_product_prop (PRODUCT_ID, PROPERTY_ID)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_filter_index_meta')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_filter_index_meta (
                    ID INT NOT NULL DEFAULT 1,
                    INDEX_VERSION INT NOT NULL DEFAULT 0,
                    CONFIG_HASH VARCHAR(32) NOT NULL DEFAULT '',
                    PRODUCTS_COUNT INT NOT NULL DEFAULT 0,
                    VALUES_COUNT INT NOT NULL DEFAULT 0,
                    LAST_FULL_SYNC_AT DATETIME NULL,
                    LAST_ERROR TEXT NULL,
                    PRIMARY KEY (ID)
                )
            ");
            $connection->queryExecute("
                INSERT INTO myapp_catalog_filter_index_meta (ID, INDEX_VERSION, CONFIG_HASH)
                VALUES (1, 0, '')
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_filter_index_queue')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_filter_index_queue (
                    PRODUCT_ID INT NOT NULL,
                    JOB_ID INT NOT NULL DEFAULT 0,
                    CREATED_AT DATETIME NOT NULL,
                    PRIMARY KEY (PRODUCT_ID),
                    INDEX ix_myapp_fiq_job (JOB_ID)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_filter_index_job')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_filter_index_job (
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
                    INDEX ix_myapp_fij_status (STATUS)
                )
            ");
        }
    }

    private static function ensurePriceColumn(): void
    {
        if (Option::get(self::MODULE_ID, self::OPT_PRICE_COLUMN_READY, 'N') === 'Y') {
            return;
        }

        $connection = Application::getConnection();
        if (!$connection->isTableExists('myapp_catalog_filter_product')) {
            return;
        }

        $added = self::addColumnIfMissing(
            $connection,
            'myapp_catalog_filter_product',
            'PRICE',
            'DECIMAL(18,4) NULL'
        );
        if ($added) {
            Option::set(self::MODULE_ID, self::OPT_INDEX_READY, 'N');
        }

        Option::set(self::MODULE_ID, self::OPT_PRICE_COLUMN_READY, 'Y');
    }

    /**
     * @return bool true if column was added
     */
    private static function addColumnIfMissing(
        \Bitrix\Main\DB\Connection $connection,
        string $table,
        string $column,
        string $definition
    ): bool {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: $table;
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column) ?: $column;

        $exists = $connection->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetch();
        if ($exists) {
            return false;
        }

        try {
            $connection->queryExecute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function resetInstallFlags(): void
    {
        Option::delete(self::MODULE_ID, ['name' => self::OPT_SCHEMA_READY]);
        Option::delete(self::MODULE_ID, ['name' => self::OPT_PRICE_COLUMN_READY]);
    }

    public static function truncateIndexData(): void
    {
        self::ensureInfrastructure();
        $connection = Application::getConnection();
        foreach ([
            'myapp_catalog_filter_value',
            'myapp_catalog_filter_product_section',
            'myapp_catalog_filter_product',
        ] as $table) {
            if ($connection->isTableExists($table)) {
                $connection->truncateTable($table);
            }
        }
    }
}
