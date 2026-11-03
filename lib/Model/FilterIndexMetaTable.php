<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

final class FilterIndexMetaTable extends DataManager
{
    public const ROW_ID = 1;

    public static function getTableName(): string
    {
        return 'myapp_catalog_filter_index_meta';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary(true)->configureDefaultValue(self::ROW_ID),
            (new IntegerField('INDEX_VERSION'))->configureDefaultValue(0),
            (new StringField('CONFIG_HASH'))->configureSize(32)->configureDefaultValue(''),
            (new IntegerField('PRODUCTS_COUNT'))->configureDefaultValue(0),
            (new IntegerField('VALUES_COUNT'))->configureDefaultValue(0),
            (new DatetimeField('LAST_FULL_SYNC_AT'))->configureRequired(false),
            (new TextField('LAST_ERROR'))->configureRequired(false),
        ];
    }
}
