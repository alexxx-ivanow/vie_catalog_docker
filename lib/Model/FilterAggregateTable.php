<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

final class FilterAggregateTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'myapp_catalog_filter_aggregate';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('SECTION_ID'))
                ->configurePrimary(true)
                ->configureRequired(true),
            (new StringField('CONFIG_HASH'))
                ->configureRequired(true)
                ->configureSize(32),
            (new TextField('PAYLOAD_JSON'))
                ->configureRequired(true),
            (new BooleanField('IS_STALE'))
                ->configureValues('N', 'Y')
                ->configureDefaultValue('N'),
            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(true)
                ->configureDefaultValue(static fn () => new DateTime()),
            (new TextField('LAST_ERROR'))
                ->configureRequired(false),
        ];
    }
}
