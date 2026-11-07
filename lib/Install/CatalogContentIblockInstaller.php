<?php

namespace MyApp\Catalog\Install;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\TypeLanguageTable;
use Bitrix\Iblock\TypeTable;
use Bitrix\Iblock\PropertyTable;
use MyApp\Catalog\Config\Assets;

final class CatalogContentIblockInstaller
{
    private const MODULE_ID = 'myapp.catalog';

    private const FLAG = 'catalog_content_iblock_v1';

    public const TYPE_PAGES = 'myapp_catalog_pages';

    public const TYPE_MAIN = 'myapp_catalog_main';

    public const IBLOCK_PAGES = 'myapp_site_pages';

    public const IBLOCK_SLIDER = 'myapp_main_slider';

    public const IBLOCK_ADVANTAGES = 'myapp_main_advantages';

    public const IBLOCK_ABOUT = 'myapp_main_about';

    public const IBLOCK_SEO = 'myapp_main_seo';

    private const OPT_LAST_ERRORS = 'catalog_content_iblock_install_errors';

    /** @var list<string> */
    private static array $lastErrors = [];

    public static function ensureInfrastructure(): void
    {
        if (!Loader::includeModule('iblock')) {
            self::rememberError('Модуль iblock не загружен');

            return;
        }

        self::$lastErrors = [];
        self::ensureTypesAndIblocks();
        self::syncOptionIds();
        self::ensureCatalogSectionUserFields();
        CatalogSiteBootstrapInstaller::ensureRootIndex();

        if (self::isInstallationComplete()) {
            Option::set(self::MODULE_ID, self::FLAG, 'Y');
            Option::delete(self::MODULE_ID, ['name' => self::OPT_LAST_ERRORS]);
        } else {
            Option::set(self::MODULE_ID, self::FLAG, 'N');
            Option::set(
                self::MODULE_ID,
                self::OPT_LAST_ERRORS,
                (string)json_encode(self::$lastErrors, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * @return list<string>
     */
    public static function getLastInstallErrors(): array
    {
        $raw = (string)Option::get(self::MODULE_ID, self::OPT_LAST_ERRORS, '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    private static function ensureTypesAndIblocks(): void
    {
        self::ensureType(self::TYPE_PAGES, 'Контентные страницы', false);
        self::ensureType(self::TYPE_MAIN, 'Главная страница', false);

        $pagesId = self::ensureIblock(
            self::TYPE_PAGES,
            self::IBLOCK_PAGES,
            'Служебные страницы',
            'pages',
            'pages/#ELEMENT_CODE#/'
        );
        if ($pagesId > 0) {
            self::ensureStringProperty($pagesId, 'META_TITLE', 'Meta title');
            self::ensureStringProperty($pagesId, 'META_DESCRIPTION', 'Meta description');
            self::ensureStringProperty($pagesId, 'BREADCRUMB_TITLE', 'Заголовок в крошках');
            Option::set(self::MODULE_ID, Assets::OPT_PAGES_IBLOCK_ID, (string)$pagesId);
        }

        $sliderId = self::ensureIblock(
            self::TYPE_MAIN,
            self::IBLOCK_SLIDER,
            'Слайдер главной',
            'main-slider',
            ''
        );
        if ($sliderId > 0) {
            self::ensureStringProperty($sliderId, 'LINK', 'Ссылка');
            self::ensureStringProperty($sliderId, 'CAPTION', 'Подпись');
            Option::set(self::MODULE_ID, Assets::OPT_MAIN_IBLOCK_SLIDER_ID, (string)$sliderId);
        }

        $advId = self::ensureIblock(self::TYPE_MAIN, self::IBLOCK_ADVANTAGES, 'Преимущества', 'main-advantages', '');
        if ($advId > 0) {
            self::ensureFileProperty($advId, 'ICON', 'Иконка');
            self::ensureStringProperty($advId, 'TEXT', 'Текст');
            Option::set(self::MODULE_ID, Assets::OPT_MAIN_IBLOCK_ADVANTAGES_ID, (string)$advId);
        }

        $aboutId = self::ensureIblock(self::TYPE_MAIN, self::IBLOCK_ABOUT, 'О нас', 'main-about', '');
        if ($aboutId > 0) {
            self::ensureFileProperty($aboutId, 'ABOUT_IMAGE', 'Изображение');
            Option::set(self::MODULE_ID, Assets::OPT_MAIN_IBLOCK_ABOUT_ID, (string)$aboutId);
        }

        $seoId = self::ensureIblock(self::TYPE_MAIN, self::IBLOCK_SEO, 'SEO-текст главной', 'main-seo', '');
        if ($seoId > 0) {
            Option::set(self::MODULE_ID, Assets::OPT_MAIN_IBLOCK_SEO_ID, (string)$seoId);
        }
    }

    public static function isInstallationComplete(): bool
    {
        $pairs = [
            [self::TYPE_PAGES, self::IBLOCK_PAGES],
            [self::TYPE_MAIN, self::IBLOCK_SLIDER],
            [self::TYPE_MAIN, self::IBLOCK_ADVANTAGES],
            [self::TYPE_MAIN, self::IBLOCK_ABOUT],
            [self::TYPE_MAIN, self::IBLOCK_SEO],
        ];

        foreach ($pairs as [$typeId, $code]) {
            if (self::findIblockId($typeId, $code) <= 0) {
                return false;
            }
        }

        return true;
    }

    private static function syncOptionIds(): void
    {
        if (!Loader::includeModule('iblock')) {
            return;
        }

        $map = [
            Assets::OPT_PAGES_IBLOCK_ID => [self::TYPE_PAGES, self::IBLOCK_PAGES],
            Assets::OPT_MAIN_IBLOCK_SLIDER_ID => [self::TYPE_MAIN, self::IBLOCK_SLIDER],
            Assets::OPT_MAIN_IBLOCK_ADVANTAGES_ID => [self::TYPE_MAIN, self::IBLOCK_ADVANTAGES],
            Assets::OPT_MAIN_IBLOCK_ABOUT_ID => [self::TYPE_MAIN, self::IBLOCK_ABOUT],
            Assets::OPT_MAIN_IBLOCK_SEO_ID => [self::TYPE_MAIN, self::IBLOCK_SEO],
        ];

        foreach ($map as $optName => [$typeId, $code]) {
            if ((int)Option::get(self::MODULE_ID, $optName, '0') > 0) {
                continue;
            }
            $id = self::findIblockId($typeId, $code);
            if ($id > 0) {
                Option::set(self::MODULE_ID, $optName, (string)$id);
            }
        }
    }

    public static function ensureCatalogSectionUserFields(): void
    {
        $iblockId = Assets::catalogIblockId();
        if ($iblockId <= 0) {
            return;
        }

        $entityId = 'IBLOCK_' . $iblockId . '_SECTION';
        self::ensureBooleanUserField($entityId, Assets::defaultHomeSectionsUfShowCode(), 'Показывать на главной');
        self::ensureIntegerUserField($entityId, Assets::defaultHomeSectionsUfSortCode(), 'Сортировка на главной');
    }

    private static function ensureType(string $id, string $name, bool $sections): void
    {
        $row = TypeTable::getList(['filter' => ['=ID' => $id], 'select' => ['ID']])->fetch();
        if (!$row) {
            if (!class_exists(\CIBlockType::class)) {
                self::rememberError('CIBlockType недоступен для типа ' . $id);

                return;
            }

            $iblockType = new \CIBlockType();
            $added = $iblockType->Add([
                'ID' => $id,
                'SECTIONS' => $sections ? 'Y' : 'N',
                'EDIT_FILE_BEFORE' => '',
                'EDIT_FILE_AFTER' => '',
                'IN_RSS' => 'N',
                'SORT' => 500,
                'LANG' => self::buildTypeLangFields($name, $sections),
            ]);
            if (!$added) {
                $error = trim((string)$iblockType->LAST_ERROR);
                self::rememberError(
                    'Не удалось создать тип ' . $id . ($error !== '' ? ': ' . $error : '')
                );
            }
        }

        self::ensureTypeLanguages($id, $name, $sections);
        if (class_exists(TypeTable::class)) {
            TypeTable::cleanCache();
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function buildTypeLangFields(string $name, bool $sections): array
    {
        $elementName = $sections ? 'Раздел' : 'Элемент';
        $sectionName = $sections ? 'Разделы' : '';
        $result = [];
        foreach (self::resolveLanguageIds() as $lid) {
            $result[$lid] = [
                'NAME' => $name,
                'SECTION_NAME' => $sectionName,
                'ELEMENT_NAME' => $elementName,
            ];
        }

        if ($result === []) {
            $result['ru'] = [
                'NAME' => $name,
                'SECTION_NAME' => $sectionName,
                'ELEMENT_NAME' => $elementName,
            ];
        }

        return $result;
    }

    private static function ensureTypeLanguages(string $id, string $name, bool $sections): void
    {
        $elementName = $sections ? 'Раздел' : 'Элемент';
        $sectionName = $sections ? 'Разделы' : '';

        foreach (self::resolveLanguageIds() as $lid) {
            $exists = TypeLanguageTable::getList([
                'filter' => [
                    '=IBLOCK_TYPE_ID' => $id,
                    '=LANGUAGE_ID' => $lid,
                ],
                'select' => ['IBLOCK_TYPE_ID'],
                'limit' => 1,
            ])->fetch();
            if ($exists) {
                continue;
            }

            $addResult = TypeLanguageTable::add([
                'IBLOCK_TYPE_ID' => $id,
                'LANGUAGE_ID' => $lid,
                'NAME' => $name,
                'SECTION_NAME' => $sectionName,
                'ELEMENT_NAME' => $elementName,
            ]);
            if (!$addResult->isSuccess()) {
                foreach ($addResult->getErrorMessages() as $message) {
                    self::rememberError('TypeLanguage ' . $id . '/' . $lid . ': ' . $message);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function resolveLanguageIds(): array
    {
        $langIds = [];
        if (Loader::includeModule('main')) {
            $siteResult = \Bitrix\Main\SiteTable::getList([
                'filter' => ['=ACTIVE' => 'Y'],
                'select' => ['LANGUAGE_ID', 'LID', 'DEF'],
                'order' => ['DEF' => 'DESC'],
            ]);
            while ($site = $siteResult->fetch()) {
                $lang = trim((string)($site['LANGUAGE_ID'] ?? ''));
                if ($lang === '') {
                    $lang = trim((string)($site['LID'] ?? ''));
                }
                if ($lang !== '') {
                    $langIds[] = $lang;
                }
            }
        }

        if ($langIds === []) {
            $langIds[] = defined('LANGUAGE_ID') ? (string)LANGUAGE_ID : 'ru';
        }

        return array_values(array_unique($langIds));
    }

    private static function ensureIblock(
        string $typeId,
        string $code,
        string $name,
        string $listUrl,
        string $detailUrl
    ): int {
        $existing = self::findIblockId($typeId, $code);
        if ($existing > 0) {
            return $existing;
        }

        $siteIds = self::resolveSiteIds();
        $fields = [
            'ACTIVE' => 'Y',
            'NAME' => $name,
            'CODE' => $code,
            'IBLOCK_TYPE_ID' => $typeId,
            'SITE_ID' => $siteIds,
            'SORT' => 500,
            'LIST_PAGE_URL' => $listUrl,
            'DETAIL_PAGE_URL' => $detailUrl,
            'INDEX_ELEMENT' => 'Y',
            'INDEX_SECTION' => 'N',
            'VERSION' => 1,
            'WORKFLOW' => 'N',
            'BIZPROC' => 'N',
            'RIGHTS_MODE' => 'S',
            'GROUP_ID' => ['2' => 'R'],
        ];

        $id = self::ensureIblockLegacy($fields);
        if ($id > 0) {
            return $id;
        }

        $ormFields = $fields;
        unset($ormFields['WORKFLOW'], $ormFields['BIZPROC'], $ormFields['GROUP_ID']);
        $ormFields['VERSION'] = 2;
        $result = IblockTable::add($ormFields);
        if ($result->isSuccess()) {
            $id = (int)$result->getId();
            self::ensureIblockSites($id, $siteIds);

            return $id;
        }

        foreach ($result->getErrorMessages() as $message) {
            self::rememberError($code . ' (D7): ' . $message);
        }

        return 0;
    }

    /**
     * @param list<string> $siteIds
     */
    private static function ensureIblockSites(int $iblockId, array $siteIds): void
    {
        if ($iblockId <= 0 || $siteIds === []) {
            return;
        }

        foreach ($siteIds as $siteId) {
            $exists = \Bitrix\Iblock\IblockSiteTable::getList([
                'filter' => ['=IBLOCK_ID' => $iblockId, '=SITE_ID' => $siteId],
                'select' => ['IBLOCK_ID'],
                'limit' => 1,
            ])->fetch();
            if ($exists) {
                continue;
            }
            \Bitrix\Iblock\IblockSiteTable::add([
                'IBLOCK_ID' => $iblockId,
                'SITE_ID' => $siteId,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function ensureIblockLegacy(array $fields): int
    {
        if (!class_exists(\CIBlock::class)) {
            return 0;
        }

        $iblock = new \CIBlock();
        $id = (int)$iblock->Add($fields);
        if ($id > 0) {
            return $id;
        }

        $error = (string)$iblock->LAST_ERROR;
        if ($error !== '') {
            self::rememberError((string)($fields['CODE'] ?? 'iblock') . ' (legacy): ' . $error);
        }

        return 0;
    }

    private static function rememberError(string $message): void
    {
        $message = trim($message);
        if ($message !== '') {
            self::$lastErrors[] = $message;
        }
    }

    private static function findIblockId(string $typeId, string $code): int
    {
        $row = IblockTable::getList([
            'filter' => ['=IBLOCK_TYPE_ID' => $typeId, '=CODE' => $code],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();

        return $row ? (int)$row['ID'] : 0;
    }

    private static function ensureStringProperty(int $iblockId, string $code, string $name): void
    {
        self::ensureProperty($iblockId, $code, $name, 'S');
    }

    private static function ensureFileProperty(int $iblockId, string $code, string $name): void
    {
        self::ensureProperty($iblockId, $code, $name, 'F');
    }

    private static function ensureProperty(int $iblockId, string $code, string $name, string $propertyType): void
    {
        $row = PropertyTable::getList([
            'filter' => ['=IBLOCK_ID' => $iblockId, '=CODE' => $code],
            'select' => ['ID'],
            'limit' => 1,
        ])->fetch();
        if ($row) {
            return;
        }

        PropertyTable::add([
            'IBLOCK_ID' => $iblockId,
            'NAME' => $name,
            'CODE' => $code,
            'ACTIVE' => 'Y',
            'SORT' => 500,
            'PROPERTY_TYPE' => $propertyType,
            'MULTIPLE' => 'N',
            'IS_REQUIRED' => 'N',
        ]);
    }

    private static function ensureBooleanUserField(string $entityId, string $fieldName, string $label): void
    {
        if (!class_exists(\CUserTypeEntity::class)) {
            return;
        }

        $exists = \CUserTypeEntity::GetList([], [
            'ENTITY_ID' => $entityId,
            'FIELD_NAME' => $fieldName,
        ])->Fetch();
        if ($exists) {
            return;
        }

        $userField = new \CUserTypeEntity();
        $userField->Add([
            'ENTITY_ID' => $entityId,
            'FIELD_NAME' => $fieldName,
            'USER_TYPE_ID' => 'boolean',
            'XML_ID' => $fieldName,
            'SORT' => 100,
            'MULTIPLE' => 'N',
            'MANDATORY' => 'N',
            'EDIT_FORM_LABEL' => ['ru' => $label, 'en' => $label],
            'LIST_COLUMN_LABEL' => ['ru' => $label, 'en' => $label],
            'LIST_FILTER_LABEL' => ['ru' => $label, 'en' => $label],
        ]);
    }

    private static function ensureIntegerUserField(string $entityId, string $fieldName, string $label): void
    {
        if (!class_exists(\CUserTypeEntity::class)) {
            return;
        }

        $exists = \CUserTypeEntity::GetList([], [
            'ENTITY_ID' => $entityId,
            'FIELD_NAME' => $fieldName,
        ])->Fetch();
        if ($exists) {
            return;
        }

        $userField = new \CUserTypeEntity();
        $userField->Add([
            'ENTITY_ID' => $entityId,
            'FIELD_NAME' => $fieldName,
            'USER_TYPE_ID' => 'integer',
            'XML_ID' => $fieldName,
            'SORT' => 110,
            'MULTIPLE' => 'N',
            'MANDATORY' => 'N',
            'EDIT_FORM_LABEL' => ['ru' => $label, 'en' => $label],
        ]);
    }

    /**
     * @return list<string>
     */
    private static function resolveSiteIds(): array
    {
        $siteIds = [];
        if (Loader::includeModule('main')) {
            $result = \Bitrix\Main\SiteTable::getList([
                'filter' => ['=ACTIVE' => 'Y'],
                'select' => ['LID'],
                'order' => ['DEF' => 'DESC', 'SORT' => 'ASC'],
            ]);
            while ($row = $result->fetch()) {
                $lid = trim((string)($row['LID'] ?? ''));
                if ($lid !== '') {
                    $siteIds[] = $lid;
                }
            }
        }

        if ($siteIds === []) {
            $siteIds[] = 's1';
        }

        return array_values(array_unique($siteIds));
    }
}
