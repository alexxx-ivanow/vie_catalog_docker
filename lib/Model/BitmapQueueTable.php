<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;

final class BitmapQueueTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'myapp_catalog_bitmap_queue';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('PRODUCT_ID'))->configurePrimary(true),
            (new IntegerField('JOB_ID'))->configureDefaultValue(0),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(true)
                ->configureDefaultValue(static fn () => new DateTime()),
        ];
    }
}
