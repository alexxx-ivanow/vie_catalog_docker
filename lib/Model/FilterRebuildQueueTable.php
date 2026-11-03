<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;

final class FilterRebuildQueueTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'myapp_catalog_filter_rebuild_queue';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('SECTION_ID'))
                ->configurePrimary(true)
                ->configureRequired(true),
            (new IntegerField('JOB_ID'))
                ->configureDefaultValue(0),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(true)
                ->configureDefaultValue(static fn () => new DateTime()),
        ];
    }
}
