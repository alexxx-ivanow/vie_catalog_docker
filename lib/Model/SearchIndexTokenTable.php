<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

final class SearchIndexTokenTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'myapp_catalog_search_token';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('PRODUCT_ID'))->configurePrimary(true),
            (new StringField('TOKEN'))->configurePrimary(true)->configureSize(64),
            (new IntegerField('WEIGHT')),
            (new StringField('SOURCE'))->configureSize(16)->configureDefaultValue('name'),
        ];
    }
}
