<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

final class BitmapStatusTable extends DataManager
{
    public const ROW_ID = 1;

    public static function getTableName(): string
    {
        return 'myapp_catalog_bitmap_status';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary(true)->configureDefaultValue(self::ROW_ID),
            (new IntegerField('INDEX_VERSION'))->configureDefaultValue(0),
            (new StringField('CONFIG_HASH'))->configureSize(32)->configureDefaultValue(''),
            (new IntegerField('PRODUCTS_TOTAL'))->configureDefaultValue(0),
            (new IntegerField('PRODUCTS_INDEXED'))->configureDefaultValue(0),
            (new IntegerField('MASKS_COUNT'))->configureDefaultValue(0),
            (new IntegerField('MAX_POSITION'))->configureDefaultValue(-1),
            (new StringField('STATUS'))->configureSize(32)->configureDefaultValue('idle'),
            (new DatetimeField('LAST_FULL_SYNC_AT'))->configureRequired(false),
            (new TextField('LAST_ERROR'))->configureRequired(false),
            (new DatetimeField('UPDATED_AT'))->configureRequired(false),
        ];
    }
}
