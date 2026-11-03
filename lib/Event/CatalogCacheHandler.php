<?php

namespace MyApp\Catalog\Event;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionTable;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Service\ProductFilterIndexService;
use MyApp\Catalog\Service\CatalogService;

final class CatalogCacheHandler
{
    private const MODULE_ID = 'myapp.catalog';

    private const OPT_HANDLERS_INSTALLED = 'catalog_cache_handlers_installed';

    private const OPT_HANDLERS_V2 = 'catalog_cache_handlers_v2';

    private const OPT_HANDLERS_V3 = 'catalog_cache_handlers_v3';

    /** @var array<int, list<int>> */
    private static array $elementSectionsBeforeUpdate = [];

    /** @var array<int, list<int>> */
    private static array $sectionPathBeforeUpdate = [];

    public static function ensurePersistentHandlers(): void
    {
        self::unregisterLegacyEventHandlers();

        if (Option::get(self::MODULE_ID, self::OPT_HANDLERS_V3, 'N') === 'Y') {
            return;
        }

        if (Option::get(self::MODULE_ID, self::OPT_HANDLERS_INSTALLED, 'N') === 'Y') {
            self::uninstallPersistentHandlers();
        }

        self::installPersistentHandlers();
        Option::set(self::MODULE_ID, self::OPT_HANDLERS_INSTALLED, 'Y');
        Option::set(self::MODULE_ID, self::OPT_HANDLERS_V2, 'Y');
        Option::set(self::MODULE_ID, self::OPT_HANDLERS_V3, 'Y');
    }

    public static function installPersistentHandlers(): void
    {
        $manager = EventManager::getInstance();
        $class = self::class;

        $manager->registerEventHandler('iblock', 'OnBeforeIBlockElementUpdate', self::MODULE_ID, $class, 'onBeforeElementUpdate');
        $manager->registerEventHandler('iblock', 'OnBeforeIBlockElementDelete', self::MODULE_ID, $class, 'onBeforeElementDelete');
        $manager->registerEventHandler('iblock', 'OnAfterIBlockElementAdd', self::MODULE_ID, $class, 'onAfterElementChange');
        $manager->registerEventHandler('iblock', 'OnAfterIBlockElementUpdate', self::MODULE_ID, $class, 'onAfterElementUpdate');
        $manager->registerEventHandler('iblock', 'OnAfterIBlockElementDelete', self::MODULE_ID, $class, 'onAfterElementDelete');
        $manager->registerEventHandler(
            'iblock',
            'OnAfterIBlockElementSetPropertyValues',
            self::MODULE_ID,
            $class,
            'onAfterElementSetPropertyValues'
        );

        $manager->registerEventHandler('iblock', 'OnBeforeIBlockSectionUpdate', self::MODULE_ID, $class, 'onBeforeSectionChange');
        $manager->registerEventHandler('iblock', 'OnBeforeIBlockSectionDelete', self::MODULE_ID, $class, 'onBeforeSectionDelete');
        $manager->registerEventHandler('iblock', 'OnAfterIBlockSectionAdd', self::MODULE_ID, $class, 'onAfterSectionChange');
        $manager->registerEventHandler('iblock', 'OnAfterIBlockSectionUpdate', self::MODULE_ID, $class, 'onAfterSectionUpdate');
        $manager->registerEventHandler('iblock', 'OnAfterIBlockSectionDelete', self::MODULE_ID, $class, 'onAfterSectionDelete');

        $manager->registerEventHandler('catalog', 'OnPriceAdd', self::MODULE_ID, $class, 'onPriceChange');
        $manager->registerEventHandler('catalog', 'OnPriceUpdate', self::MODULE_ID, $class, 'onPriceChange');
        $manager->registerEventHandler('catalog', 'OnPriceDelete', self::MODULE_ID, $class, 'onPriceChange');
    }

    public static function uninstallPersistentHandlers(): void
    {
        $manager = EventManager::getInstance();
        $class = self::class;

        self::unregisterLegacyEventHandlers();

        $manager->unRegisterEventHandler('iblock', 'OnBeforeIBlockElementUpdate', self::MODULE_ID, $class, 'onBeforeElementUpdate');
        $manager->unRegisterEventHandler('iblock', 'OnBeforeIBlockElementDelete', self::MODULE_ID, $class, 'onBeforeElementDelete');
        $manager->unRegisterEventHandler('iblock', 'OnAfterIBlockElementAdd', self::MODULE_ID, $class, 'onAfterElementChange');
        $manager->unRegisterEventHandler('iblock', 'OnAfterIBlockElementUpdate', self::MODULE_ID, $class, 'onAfterElementUpdate');
        $manager->unRegisterEventHandler('iblock', 'OnAfterIBlockElementDelete', self::MODULE_ID, $class, 'onAfterElementDelete');
        $manager->unRegisterEventHandler(
            'iblock',
            'OnAfterIBlockElementSetPropertyValues',
            self::MODULE_ID,
            $class,
            'onAfterElementSetPropertyValues'
        );

        $manager->unRegisterEventHandler('iblock', 'OnBeforeIBlockSectionUpdate', self::MODULE_ID, $class, 'onBeforeSectionChange');
        $manager->unRegisterEventHandler('iblock', 'OnBeforeIBlockSectionDelete', self::MODULE_ID, $class, 'onBeforeSectionDelete');
        $manager->unRegisterEventHandler('iblock', 'OnAfterIBlockSectionAdd', self::MODULE_ID, $class, 'onAfterSectionChange');
        $manager->unRegisterEventHandler('iblock', 'OnAfterIBlockSectionUpdate', self::MODULE_ID, $class, 'onAfterSectionUpdate');
        $manager->unRegisterEventHandler('iblock', 'OnAfterIBlockSectionDelete', self::MODULE_ID, $class, 'onAfterSectionDelete');

        $manager->unRegisterEventHandler('catalog', 'OnPriceAdd', self::MODULE_ID, $class, 'onPriceChange');
        $manager->unRegisterEventHandler('catalog', 'OnPriceUpdate', self::MODULE_ID, $class, 'onPriceChange');
        $manager->unRegisterEventHandler('catalog', 'OnPriceDelete', self::MODULE_ID, $class, 'onPriceChange');

        Option::delete(self::MODULE_ID, ['name' => self::OPT_HANDLERS_INSTALLED]);
        Option::delete(self::MODULE_ID, ['name' => self::OPT_HANDLERS_V2]);
        Option::delete(self::MODULE_ID, ['name' => self::OPT_HANDLERS_V3]);
    }

    /**
     * Ранее OnBefore*Delete вешали на те же методы, что и Update (массив полей).
     * Delete передаёт только ID — такие записи ломают удаление, если остались в b_module_to_module.
     */
    private static function unregisterLegacyEventHandlers(): void
    {
        $manager = EventManager::getInstance();
        $class = self::class;

        $manager->unRegisterEventHandler(
            'iblock',
            'OnBeforeIBlockElementDelete',
            self::MODULE_ID,
            $class,
            'onBeforeElementUpdate'
        );
        $manager->unRegisterEventHandler(
            'iblock',
            'OnBeforeIBlockSectionDelete',
            self::MODULE_ID,
            $class,
            'onBeforeSectionChange'
        );
    }

    public static function onBeforeElementUpdate(array &$fields): void
    {
        if (!self::isCatalogElementFields($fields)) {
            return;
        }

        $elementId = (int)($fields['ID'] ?? 0);
        if ($elementId <= 0) {
            return;
        }

        self::rememberElementSectionsBeforeChange($elementId);
    }

    public static function onBeforeElementDelete(int $elementId): void
    {
        $elementId = (int)$elementId;
        if ($elementId <= 0) {
            return;
        }

        if (!self::isCatalogElementFields(['ID' => $elementId])) {
            return;
        }

        self::rememberElementSectionsBeforeChange($elementId);
    }

    public static function onAfterElementChange(array &$fields): void
    {
        if (!self::isCatalogElementFields($fields)) {
            return;
        }

        $elementId = (int)($fields['ID'] ?? 0);
        if ($elementId > 0) {
            CatalogService::invalidateByElement($elementId, [], true);
        }
    }

    public static function onAfterElementUpdate(array &$fields): void
    {
        if (!self::isCatalogElementFields($fields)) {
            return;
        }

        $elementId = (int)($fields['ID'] ?? 0);
        if ($elementId <= 0) {
            return;
        }

        $oldSectionIds = self::$elementSectionsBeforeUpdate[$elementId] ?? [];
        unset(self::$elementSectionsBeforeUpdate[$elementId]);

        CatalogService::invalidateByElement($elementId, $oldSectionIds, true);
    }

    public static function onAfterElementDelete(array &$fields): void
    {
        if (!self::isCatalogElementFields($fields)) {
            return;
        }

        $elementId = (int)($fields['ID'] ?? 0);
        if ($elementId <= 0) {
            return;
        }

        $oldSectionIds = self::$elementSectionsBeforeUpdate[$elementId] ?? [];
        unset(self::$elementSectionsBeforeUpdate[$elementId]);

        CatalogService::invalidateByElement($elementId, $oldSectionIds, true);
        ProductFilterIndexService::removeProduct($elementId);
    }

    /**
     * @param mixed $elementId
     * @param mixed $iblockId
     * @param mixed $propertyValues
     * @param mixed $propertyCodes
     */
    public static function onAfterElementSetPropertyValues(
        $elementId,
        $iblockId,
        $propertyValues,
        $propertyCodes
    ): void {
        $elementId = (int)$elementId;
        $iblockId = (int)$iblockId;
        if ($elementId <= 0 || !self::isCatalogIblock($iblockId)) {
            return;
        }

        if (!self::touchesFilterProperties($propertyValues, $propertyCodes)) {
            return;
        }

        CatalogService::invalidateByElement($elementId, [], true);
    }

    public static function onBeforeSectionChange(array &$fields): void
    {
        if (!self::isCatalogIblock(self::resolveIblockIdFromFields($fields))) {
            return;
        }

        $sectionId = (int)($fields['ID'] ?? 0);
        if ($sectionId <= 0) {
            return;
        }

        self::rememberSectionPathBeforeChange($sectionId);
    }

    public static function onBeforeSectionDelete(int $sectionId): void
    {
        $sectionId = (int)$sectionId;
        if ($sectionId <= 0) {
            return;
        }

        if (!self::isCatalogIblock(self::resolveIblockIdFromSectionId($sectionId))) {
            return;
        }

        self::rememberSectionPathBeforeChange($sectionId);
    }

    public static function onAfterSectionChange(array &$fields): void
    {
        if (!self::isCatalogIblock(self::resolveIblockIdFromFields($fields))) {
            return;
        }

        $sectionId = (int)($fields['ID'] ?? 0);
        if ($sectionId > 0) {
            CatalogService::invalidateBySection($sectionId, false);
        }
    }

    public static function onAfterSectionUpdate(array &$fields): void
    {
        if (!self::isCatalogIblock(self::resolveIblockIdFromFields($fields))) {
            return;
        }

        $sectionId = (int)($fields['ID'] ?? 0);
        if ($sectionId <= 0) {
            return;
        }

        $oldPath = self::$sectionPathBeforeUpdate[$sectionId] ?? [];
        unset(self::$sectionPathBeforeUpdate[$sectionId]);

        foreach ($oldPath as $oldSectionId) {
            if ($oldSectionId > 0) {
                CatalogService::invalidateBySection($oldSectionId, false);
            }
        }

        CatalogService::invalidateBySection($sectionId, false);
    }

    public static function onAfterSectionDelete(array &$fields): void
    {
        if (!self::isCatalogIblock(self::resolveIblockIdFromFields($fields))) {
            return;
        }

        $sectionId = (int)($fields['ID'] ?? 0);
        if ($sectionId <= 0) {
            return;
        }

        $oldPath = self::$sectionPathBeforeUpdate[$sectionId] ?? CatalogService::getSectionPathIds($sectionId);
        unset(self::$sectionPathBeforeUpdate[$sectionId]);

        foreach ($oldPath as $pathSectionId) {
            if ($pathSectionId > 0) {
                CatalogService::invalidateBySection($pathSectionId, false);
            }
        }

        CatalogService::invalidateBySection($sectionId, true);
    }

    public static function onPriceChange(array &$fields): void
    {
        $productId = (int)($fields['PRODUCT_ID'] ?? 0);
        if ($productId <= 0) {
            return;
        }

        if (!Loader::includeModule('iblock')) {
            return;
        }

        $row = ElementTable::getList([
            'select' => ['IBLOCK_ID'],
            'filter' => ['=ID' => $productId],
            'limit' => 1,
        ])->fetch();

        if (!$row || !self::isCatalogIblock((int)$row['IBLOCK_ID'])) {
            return;
        }

        CatalogService::invalidateByElement($productId, [], true);
    }

    /**
     * @param mixed $propertyValues
     * @param mixed $propertyCodes
     */
    private static function touchesFilterProperties($propertyValues, $propertyCodes): bool
    {
        $filterIds = array_fill_keys(CatalogService::getFilterPropertyIds(), true);
        if ($filterIds === []) {
            return false;
        }

        if (is_array($propertyValues)) {
            foreach ($propertyValues as $key => $value) {
                if (isset($filterIds[(int)$key])) {
                    return true;
                }
                if (is_string($key) && in_array($key, Assets::filterPropertyCodes(), true)) {
                    return true;
                }
            }
        }

        if (is_array($propertyCodes)) {
            foreach ($propertyCodes as $code) {
                if (in_array((string)$code, Assets::filterPropertyCodes(), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function rememberElementSectionsBeforeChange(int $elementId): void
    {
        self::$elementSectionsBeforeUpdate[$elementId] = CatalogService::getElementSectionIds($elementId);
    }

    private static function rememberSectionPathBeforeChange(int $sectionId): void
    {
        self::$sectionPathBeforeUpdate[$sectionId] = CatalogService::getSectionPathIds($sectionId);
    }

    private static function isCatalogIblock(int $iblockId): bool
    {
        return $iblockId > 0 && $iblockId === Assets::catalogIblockId();
    }

    private static function resolveIblockIdFromSectionId(int $sectionId): int
    {
        if ($sectionId <= 0) {
            return 0;
        }

        if (!Loader::includeModule('iblock')) {
            return 0;
        }

        $row = SectionTable::getList([
            'select' => ['IBLOCK_ID'],
            'filter' => ['=ID' => $sectionId],
            'limit' => 1,
        ])->fetch();

        return (int)($row['IBLOCK_ID'] ?? 0);
    }

    private static function isCatalogElementFields(array $fields): bool
    {
        return self::isCatalogIblock(self::resolveIblockIdFromFields($fields));
    }

    private static function resolveIblockIdFromFields(array $fields): int
    {
        $iblockId = (int)($fields['IBLOCK_ID'] ?? 0);
        if ($iblockId > 0) {
            return $iblockId;
        }

        $elementId = (int)($fields['ID'] ?? 0);
        if ($elementId <= 0) {
            return 0;
        }

        if (!Loader::includeModule('iblock')) {
            return 0;
        }

        $row = ElementTable::getList([
            'select' => ['IBLOCK_ID'],
            'filter' => ['=ID' => $elementId],
            'limit' => 1,
        ])->fetch();

        return (int)($row['IBLOCK_ID'] ?? 0);
    }
}
