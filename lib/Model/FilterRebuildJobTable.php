<?php

namespace MyApp\Catalog\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

final class FilterRebuildJobTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'myapp_catalog_filter_rebuild_job';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))
                ->configurePrimary(true)
                ->configureAutocomplete(true),
            (new StringField('TYPE'))
                ->configureRequired(true)
                ->configureSize(32),
            (new StringField('STATUS'))
                ->configureRequired(true)
                ->configureSize(16),
            (new IntegerField('TOTAL'))
                ->configureDefaultValue(0),
            (new IntegerField('PROCESSED'))
                ->configureDefaultValue(0),
            (new IntegerField('ERROR_COUNT'))
                ->configureDefaultValue(0),
            (new IntegerField('CURRENT_SECTION_ID'))
                ->configureDefaultValue(0),
            (new TextField('PARAMS_JSON'))
                ->configureRequired(false),
            (new TextField('LAST_ERROR'))
                ->configureRequired(false),
            (new DatetimeField('STARTED_AT'))
                ->configureRequired(false),
            (new DatetimeField('FINISHED_AT'))
                ->configureRequired(false),
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(true)
                ->configureDefaultValue(static fn () => new DateTime()),
        ];
    }
}
