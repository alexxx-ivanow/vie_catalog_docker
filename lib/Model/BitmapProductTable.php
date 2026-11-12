<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

final class BitmapProductTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'myapp_catalog_bitmap_products';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary(true)->configureAutocomplete(true),
            (new IntegerField('PRODUCT_ID'))->configureRequired(true),
            (new IntegerField('BITMAP_POSITION'))->configureRequired(true),
            (new IntegerField('IBLOCK_ID'))->configureRequired(true),
            (new StringField('ACTIVE'))->configureSize(1)->configureDefaultValue('Y'),
            (new IntegerField('SORT'))->configureDefaultValue(500),
            (new FloatField('PRICE'))->configureRequired(false),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(true)
                ->configureDefaultValue(static fn () => new DateTime()),
            (new TextField('LAST_ERROR'))->configureRequired(false),
        ];
    }
}
