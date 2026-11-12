<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class BitmapValueDictTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'myapp_catalog_bitmap_value_dict';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('VALUE_ID'))->configurePrimary(true)->configureAutocomplete(true),
            (new IntegerField('PROPERTY_ID'))->configureRequired(true),
            (new StringField('VALUE_KIND'))->configureSize(1)->configureRequired(true),
            (new StringField('VALUE_NORM'))->configureSize(500)->configureRequired(true),
        ];
    }
}
