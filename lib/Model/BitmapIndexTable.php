<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;

final class BitmapIndexTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'myapp_catalog_bitmap_index';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))->configurePrimary(true)->configureAutocomplete(true),
            (new IntegerField('PROPERTY_ID'))->configureRequired(true),
            (new IntegerField('VALUE_ID'))->configureRequired(true),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(true)
                ->configureDefaultValue(static fn () => new DateTime()),
        ];
    }
}
