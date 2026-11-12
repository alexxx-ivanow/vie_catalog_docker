<?php

namespace MyApp\Catalog\Install;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

final class BitmapIndexSchema
{
    private const MODULE_ID = 'myapp.catalog';

    private const OPT_SCHEMA_READY = 'bitmap_index_schema_ready';

    private const OPT_INDEX_READY = 'bitmap_index_ready';

    public static function ensureInfrastructure(): void
    {
        if (Option::get(self::MODULE_ID, self::OPT_SCHEMA_READY, 'N') !== 'Y') {
            self::createTables();
            Option::set(self::MODULE_ID, self::OPT_SCHEMA_READY, 'Y');
        }
    }

    private static function createTables(): void
    {
        $connection = Application::getConnection();

        if (!$connection->isTableExists('myapp_catalog_bitmap_products')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_bitmap_products (
                    ID INT NOT NULL AUTO_INCREMENT,
                    PRODUCT_ID INT NOT NULL,
                    BITMAP_POSITION INT NOT NULL,
                    IBLOCK_ID INT NOT NULL,
                    ACTIVE CHAR(1) NOT NULL DEFAULT 'Y',
                    SORT INT NOT NULL DEFAULT 500,
                    PRICE DECIMAL(18,4) NULL,
                    UPDATED_AT DATETIME NOT NULL,
                    LAST_ERROR TEXT NULL,
                    PRIMARY KEY (ID),
                    UNIQUE KEY ux_myapp_bmp_product (PRODUCT_ID),
                    UNIQUE KEY ux_myapp_bmp_position (BITMAP_POSITION),
                    INDEX ix_myapp_bmp_active_iblock (ACTIVE, IBLOCK_ID),
                    INDEX ix_myapp_bmp_price (PRICE)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_bitmap_index')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_bitmap_index (
                    ID INT NOT NULL AUTO_INCREMENT,
                    PROPERTY_ID INT NOT NULL,
                    VALUE_ID INT NOT NULL,
                    BITMAP MEDIUMBLOB NOT NULL,
                    UPDATED_AT DATETIME NOT NULL,
                    PRIMARY KEY (ID),
                    UNIQUE KEY ux_myapp_bmi_prop_value (PROPERTY_ID, VALUE_ID)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_bitmap_value_dict')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_bitmap_value_dict (
                    VALUE_ID INT NOT NULL AUTO_INCREMENT,
                    PROPERTY_ID INT NOT NULL,
                    VALUE_KIND CHAR(1) NOT NULL,
                    VALUE_NORM VARCHAR(500) NOT NULL,
                    PRIMARY KEY (VALUE_ID),
                    UNIQUE KEY ux_myapp_bmvd_prop_kind_norm (PROPERTY_ID, VALUE_KIND, VALUE_NORM)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_bitmap_membership')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_bitmap_membership (
                    PRODUCT_ID INT NOT NULL,
                    PROPERTY_ID INT NOT NULL,
                    VALUE_ID INT NOT NULL,
                    PRIMARY KEY (PRODUCT_ID, PROPERTY_ID, VALUE_ID),
                    INDEX ix_myapp_bmm_prop_value (PROPERTY_ID, VALUE_ID)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_bitmap_status')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_bitmap_status (
                    ID INT NOT NULL DEFAULT 1,
                    INDEX_VERSION INT NOT NULL DEFAULT 0,
                    CONFIG_HASH VARCHAR(32) NOT NULL DEFAULT '',
                    PRODUCTS_TOTAL INT NOT NULL DEFAULT 0,
                    PRODUCTS_INDEXED INT NOT NULL DEFAULT 0,
                    MASKS_COUNT INT NOT NULL DEFAULT 0,
                    MAX_POSITION INT NOT NULL DEFAULT -1,
                    STATUS VARCHAR(32) NOT NULL DEFAULT 'idle',
                    LAST_FULL_SYNC_AT DATETIME NULL,
                    LAST_ERROR TEXT NULL,
                    UPDATED_AT DATETIME NULL,
                    PRIMARY KEY (ID)
                )
            ");
            $connection->queryExecute("
                INSERT INTO myapp_catalog_bitmap_status (ID, INDEX_VERSION, CONFIG_HASH, MAX_POSITION, STATUS)
                VALUES (1, 0, '', -1, 'idle')
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_bitmap_queue')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_bitmap_queue (
                    PRODUCT_ID INT NOT NULL,
                    JOB_ID INT NOT NULL DEFAULT 0,
                    CREATED_AT DATETIME NOT NULL,
                    PRIMARY KEY (PRODUCT_ID),
                    INDEX ix_myapp_bmq_job (JOB_ID)
                )
            ");
        }

        if (!$connection->isTableExists('myapp_catalog_bitmap_job')) {
            $connection->queryExecute("
                CREATE TABLE myapp_catalog_bitmap_job (
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
                    INDEX ix_myapp_bmj_status (STATUS)
                )
            ");
        }
    }

    public static function resetInstallFlags(): void
    {
        Option::delete(self::MODULE_ID, ['name' => self::OPT_SCHEMA_READY]);
        Option::delete(self::MODULE_ID, ['name' => self::OPT_INDEX_READY]);
    }

    public static function truncateIndexData(): void
    {
        self::ensureInfrastructure();
        $connection = Application::getConnection();
        foreach ([
            'myapp_catalog_bitmap_membership',
            'myapp_catalog_bitmap_index',
            'myapp_catalog_bitmap_value_dict',
            'myapp_catalog_bitmap_products',
        ] as $table) {
            if ($connection->isTableExists($table)) {
                $connection->truncateTable($table);
            }
        }

        if ($connection->isTableExists('myapp_catalog_bitmap_status')) {
            $connection->queryExecute("
                UPDATE myapp_catalog_bitmap_status
                SET PRODUCTS_TOTAL = 0,
                    PRODUCTS_INDEXED = 0,
                    MASKS_COUNT = 0,
                    MAX_POSITION = -1,
                    STATUS = 'idle',
                    LAST_ERROR = NULL
                WHERE ID = 1
            ");
        }
    }
}
