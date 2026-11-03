<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;

final class FilterIndexProductSectionTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'myapp_catalog_filter_product_section';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('PRODUCT_ID'))->configurePrimary(true),
            (new IntegerField('SECTION_ID'))->configurePrimary(true),
        ];
    }
}
