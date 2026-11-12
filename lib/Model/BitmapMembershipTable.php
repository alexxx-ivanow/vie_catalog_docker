<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;

final class BitmapMembershipTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'myapp_catalog_bitmap_membership';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('PRODUCT_ID'))->configurePrimary(true),
            (new IntegerField('PROPERTY_ID'))->configurePrimary(true),
            (new IntegerField('VALUE_ID'))->configurePrimary(true),
        ];
    }
}
