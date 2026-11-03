<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class FilterIndexValueTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'myapp_catalog_filter_value';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))
                ->configurePrimary(true)
                ->configureAutocomplete(true),
            (new IntegerField('PRODUCT_ID'))->configureRequired(true),
            (new IntegerField('PROPERTY_ID'))->configureRequired(true),
            (new IntegerField('VALUE_ENUM'))->configureRequired(false),
            (new FloatField('VALUE_NUM'))->configureRequired(false),
            (new StringField('VALUE_STR'))->configureSize(500)->configureRequired(false),
        ];
    }
}
