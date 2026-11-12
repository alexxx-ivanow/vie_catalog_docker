<?php

namespace MyApp\Catalog\Config;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;

final class Assets
{
    public const MODULE_ID = 'myapp.catalog';

    public const OPT_USE_BITRIX_VUE = 'use_bitrix_vue';

    public const OPT_CATALOG_IBLOCK_ID = 'catalog_iblock_id';

    public const OPT_AUTO_LOGIN_AFTER_GUEST_ORDER = 'auto_login_after_guest_order';

    public const OPT_SHOW_CATALOG_FILTER = 'show_catalog_filter';

    public const OPT_CATALOG_PRICE_TYPE_CODE = 'catalog_price_type_code';

    public const OPT_FILTER_PROPERTY_CODES = 'catalog_filter_property_codes';

    public const OPT_CARD_PROPERTY_CODES = 'catalog_card_property_codes';

    public const OPT_CATALOG_ARTICLE_PROPERTY_CODE = 'catalog_article_property_code';

    public const OPT_FILTER_REBUILD_BATCH_SIZE = 'filter_rebuild_batch_size';

    public const OPT_USE_PRODUCT_FILTER_INDEX = 'use_product_filter_index';

    /** legacy | denormalized | bitmap */
    public const OPT_CATALOG_FILTER_ENGINE = 'catalog_filter_engine';

    public const OPT_PRODUCT_FILTER_INDEX_BATCH_SIZE = 'product_filter_index_batch_size';

    public const OPT_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH = 'product_filter_index_admin_step_batch';

    public const OPT_PRODUCT_FILTER_INDEX_PARTIAL_SECTION_ID = 'product_filter_index_partial_section_id';

    public const OPT_BITMAP_INDEX_BATCH_SIZE = 'bitmap_index_batch_size';

    public const OPT_BITMAP_INDEX_ADMIN_STEP_BATCH = 'bitmap_index_admin_step_batch';

    public const DEFAULT_BITMAP_INDEX_ADMIN_STEP_BATCH = 20;

    public const MAX_BITMAP_INDEX_ADMIN_STEP_BATCH = 120;

    public const OPT_SKU_PROPERTY_CODES = 'sku_property_codes';

    public const OPT_OFFERS_IBLOCK_ID = 'offers_iblock_id';

    public const OPT_SEARCH_PRODUCT_PROPERTY_CODES = 'catalog_search_product_property_codes';

    public const OPT_SEARCH_OFFER_PROPERTY_CODES = 'catalog_search_offer_property_codes';

    public const OPT_INDEX_PRODUCT_PREVIEW_TEXT = 'index_product_preview_text';

    public const OPT_INDEX_PRODUCT_DETAIL_TEXT = 'index_product_detail_text';

    public const OPT_CATALOG_SEARCH_INDEX_ADMIN_STEP_BATCH = 'search_index_admin_step_batch';

    public const OPT_CATALOG_SEARCH_INDEX_BATCH_SIZE = 'catalog_search_index_batch_size';

    /** mysql_lexeme_trigram | legacy_token */
    public const OPT_CATALOG_SEARCH_BACKEND = 'catalog_search_backend';

    public const OPT_PAGES_IBLOCK_ID = 'pages_iblock_id';

    public const OPT_PAGES_VIEW_FIELD_MAP = 'pages_view_field_map';

    public const OPT_MAIN_IBLOCK_SLIDER_ID = 'main_iblock_slider_id';

    public const OPT_MAIN_IBLOCK_ADVANTAGES_ID = 'main_iblock_advantages_id';

    public const OPT_MAIN_IBLOCK_ABOUT_ID = 'main_iblock_about_id';

    public const OPT_MAIN_IBLOCK_SEO_ID = 'main_iblock_seo_id';

    public const OPT_HOME_VIEW_FIELD_MAP = 'home_view_field_map';

    public const OPT_HOME_SECTIONS_UF_SHOW = 'home_sections_uf_show_code';

    public const OPT_HOME_SECTIONS_UF_SORT = 'home_sections_uf_sort_code';

    public const OPT_HOME_SECTIONS_LIMIT = 'home_sections_limit';

    public const OPT_HOME_SHOW_SLIDER = 'home_show_slider';

    public const OPT_HOME_SHOW_ADVANTAGES = 'home_show_advantages';

    public const OPT_HOME_SHOW_ABOUT = 'home_show_about';

    public const OPT_HOME_SHOW_SEO = 'home_show_seo';

    public const OPT_HOME_SHOW_CATALOG = 'home_show_catalog';

    public const DEFAULT_HOME_SECTIONS_LIMIT = 4;

    public const DEFAULT_HOME_SECTIONS_UF_SHOW = 'UF_SHOW_ON_MAIN';

    public const DEFAULT_HOME_SECTIONS_UF_SORT = 'UF_MAIN_SORT';

    public const DEFAULT_CATALOG_SEARCH_INDEX_ADMIN_STEP_BATCH = 30;

    public const MAX_CATALOG_SEARCH_INDEX_ADMIN_STEP_BATCH = 120;

    public const DEFAULT_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH = 30;

    public const MAX_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH = 120;

    public const DEFAULT_CATALOG_IBLOCK_ID = 2;

    public const DEFAULT_CATALOG_CACHE_TTL = 3600;

    public const OPT_CATALOG_CACHE_TTL = 'catalog_cache_ttl';

    public const DEFAULT_CATALOG_PRICE_TYPE_CODE = 'BASE';

    /** @var list<string> */
    public const DEFAULT_FILTER_PROPERTY_CODES = ['MANUFACTURER', 'SIZE', 'MATERIAL'];

    /** @var list<string> */
    public const DEFAULT_CARD_PROPERTY_CODES = ['ARTICLE', 'MANUFACTURER', 'SIZE', 'MATERIAL'];

    public const DEFAULT_ARTICLE_PROPERTY_CODE = 'ARTICLE';

    /** Базовый URL JSON API Vue-каталога (от document root, без завершающего слэша). */
    public const API_V1_BASE = '/vue_catalog/api/v1';

    /** Базовый URL ES-модулей Vue-каталога (от document root). */
    public const JS_CATALOG_BASE = '/local/modules/myapp.catalog/js/catalog/';

    /**
     * Заглушка без фото (файл в install: templates/assets/img/home/no_photo.png).
     */
    public const NO_PHOTO_IMAGE = '/local/templates/assets/img/home/no_photo.png';

    public const JS_VUE_VENDOR = '/local/modules/myapp.catalog/js/vendor/vue.esm-browser.min.js';

    /** Исходник для пересборки min (esbuild --minify). */
    public const JS_VUE_VENDOR_SOURCE = '/local/modules/myapp.catalog/js/vendor/vue.esm-browser.js';

    public const JS_VUE_BITRIX_BRIDGE = '/local/modules/myapp.catalog/js/vendor/vue-bitrix-bridge.js';

    /** @deprecated Используйте JS_VUE_VENDOR или vueModuleUrl() */
    public const JS_VUE = self::JS_VUE_VENDOR;

    /** Фактический режим после prepareVueForPage() (с учётом fallback). */
    private static ?bool $bitrixVueActive = null;

    public static function jsCatalogUrl(string $entry): string
    {
        return self::JS_CATALOG_BASE . ltrim($entry, '/');
    }

    public static function useBitrixVueOption(): bool
    {
        return Option::get(self::MODULE_ID, self::OPT_USE_BITRIX_VUE, 'N') === 'Y';
    }

    public static function catalogCacheTtl(): int
    {
        $ttl = (int)Option::get(
            self::MODULE_ID,
            self::OPT_CATALOG_CACHE_TTL,
            (string)self::DEFAULT_CATALOG_CACHE_TTL
        );

        return $ttl > 0 ? $ttl : self::DEFAULT_CATALOG_CACHE_TTL;
    }

    public static function productFilterIndexAdminStepBatch(): int
    {
        $size = (int)Option::get(
            self::MODULE_ID,
            self::OPT_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH,
            (string)self::DEFAULT_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH
        );
        if ($size <= 0) {
            $size = self::DEFAULT_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH;
        }

        return min(self::MAX_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH, max(1, $size));
    }

    public static function bitmapIndexAdminStepBatch(): int
    {
        $size = (int)Option::get(
            self::MODULE_ID,
            self::OPT_BITMAP_INDEX_ADMIN_STEP_BATCH,
            (string)self::DEFAULT_BITMAP_INDEX_ADMIN_STEP_BATCH
        );
        if ($size <= 0) {
            $size = self::DEFAULT_BITMAP_INDEX_ADMIN_STEP_BATCH;
        }

        return min(self::MAX_BITMAP_INDEX_ADMIN_STEP_BATCH, max(1, $size));
    }

    public static function searchIndexAdminStepBatch(): int
    {
        $size = (int)Option::get(
            self::MODULE_ID,
            self::OPT_CATALOG_SEARCH_INDEX_ADMIN_STEP_BATCH,
            (string)self::DEFAULT_CATALOG_SEARCH_INDEX_ADMIN_STEP_BATCH
        );
        if ($size <= 0) {
            $size = self::DEFAULT_CATALOG_SEARCH_INDEX_ADMIN_STEP_BATCH;
        }

        return min(self::MAX_CATALOG_SEARCH_INDEX_ADMIN_STEP_BATCH, max(1, $size));
    }

    /**
     * @return list<string>
     */
    public static function searchProductPropertyCodes(): array
    {
        $raw = (string)Option::get(self::MODULE_ID, self::OPT_SEARCH_PRODUCT_PROPERTY_CODES, '');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return self::normalizePropertyCodes(is_array($decoded) ? $decoded : []);
    }

    /**
     * @return list<string>
     */
    public static function searchOfferPropertyCodes(): array
    {
        $raw = (string)Option::get(self::MODULE_ID, self::OPT_SEARCH_OFFER_PROPERTY_CODES, '');
        if ($raw === '') {
            return self::skuPropertyCodesFromOption();
        }
        $decoded = json_decode($raw, true);
        $codes = self::normalizePropertyCodes(is_array($decoded) ? $decoded : []);

        return $codes !== [] ? $codes : self::skuPropertyCodesFromOption();
    }

    public static function indexProductPreviewText(): bool
    {
        return Option::get(self::MODULE_ID, self::OPT_INDEX_PRODUCT_PREVIEW_TEXT, 'Y') === 'Y';
    }

    public static function indexProductDetailText(): bool
    {
        return Option::get(self::MODULE_ID, self::OPT_INDEX_PRODUCT_DETAIL_TEXT, 'N') === 'Y';
    }

    /**
     * @return list<string>
     */
    private static function skuPropertyCodesFromOption(): array
    {
        $raw = (string)Option::get(self::MODULE_ID, self::OPT_SKU_PROPERTY_CODES, '');
        if ($raw === '') {
            return ['COLOR', 'SIZE'];
        }
        $decoded = json_decode($raw, true);
        $codes = self::normalizePropertyCodes(is_array($decoded) ? $decoded : []);

        return $codes !== [] ? $codes : ['COLOR', 'SIZE'];
    }

    public static function catalogIblockId(): int
    {
        $iblockId = (int)Option::get(
            self::MODULE_ID,
            self::OPT_CATALOG_IBLOCK_ID,
            (string)self::DEFAULT_CATALOG_IBLOCK_ID
        );

        return $iblockId > 0 ? $iblockId : self::DEFAULT_CATALOG_IBLOCK_ID;
    }

    public static function autoLoginAfterGuestOrder(): bool
    {
        return Option::get(self::MODULE_ID, self::OPT_AUTO_LOGIN_AFTER_GUEST_ORDER, 'N') === 'Y';
    }

    public static function showCatalogFilter(): bool
    {
        return Option::get(self::MODULE_ID, self::OPT_SHOW_CATALOG_FILTER, 'Y') === 'Y';
    }

    public static function catalogPriceTypeCode(): string
    {
        $code = trim((string)Option::get(
            self::MODULE_ID,
            self::OPT_CATALOG_PRICE_TYPE_CODE,
            self::DEFAULT_CATALOG_PRICE_TYPE_CODE
        ));

        return $code !== '' ? $code : self::DEFAULT_CATALOG_PRICE_TYPE_CODE;
    }

    /**
     * Коды свойств фильтра. Пустая строка в опции — значения по умолчанию.
     * Сохранённый JSON `[]` — в фильтре только цена.
     *
     * @return list<string>
     */
    public static function filterPropertyCodes(): array
    {
        $raw = (string)Option::get(self::MODULE_ID, self::OPT_FILTER_PROPERTY_CODES, '');
        if ($raw === '') {
            return self::DEFAULT_FILTER_PROPERTY_CODES;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::DEFAULT_FILTER_PROPERTY_CODES;
        }

        return self::normalizePropertyCodes($decoded);
    }

    /**
     * Коды свойств на детальной карточке. Пустая опция — DEFAULT_CARD_PROPERTY_CODES;
     * сохранённый JSON [] — свойства не выводятся.
     *
     * @return list<string>
     */
    public static function cardPropertyCodes(): array
    {
        $raw = (string)Option::get(self::MODULE_ID, self::OPT_CARD_PROPERTY_CODES, '');
        if ($raw === '') {
            return self::DEFAULT_CARD_PROPERTY_CODES;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::DEFAULT_CARD_PROPERTY_CODES;
        }

        return self::normalizePropertyCodes($decoded);
    }

    public static function catalogArticlePropertyCode(): string
    {
        $code = trim((string)Option::get(
            self::MODULE_ID,
            self::OPT_CATALOG_ARTICLE_PROPERTY_CODE,
            self::DEFAULT_ARTICLE_PROPERTY_CODE
        ));

        if ($code === '' || !preg_match('/^[A-Za-z0-9_]+$/', $code)) {
            return self::DEFAULT_ARTICLE_PROPERTY_CODE;
        }

        return $code;
    }

    /**
     * @param mixed $codes
     * @return list<string>
     */
    public static function normalizePropertyCodes(mixed $codes): array
    {
        $items = is_array($codes) ? $codes : [$codes];
        $result = [];
        foreach ($items as $code) {
            $normalized = trim((string)$code);
            if ($normalized === '' || !preg_match('/^[A-Za-z0-9_]+$/', $normalized)) {
                continue;
            }
            $result[] = $normalized;
        }

        return array_values(array_unique($result));
    }

    /**
     * Готовит Vue для страницы: при включённой опции грузит ui.vue3.
     * Возвращает true, если активен режим Bitrix Vue (import map → bridge).
     */
    public static function prepareVueForPage(): bool
    {
        self::$bitrixVueActive = false;

        if (!self::useBitrixVueOption()) {
            return false;
        }

        try {
            if (!Loader::includeModule('ui')) {
                return false;
            }

            Extension::load('ui.vue3');
            self::$bitrixVueActive = true;
            return true;
        } catch (\Throwable $e) {
            self::$bitrixVueActive = false;
            return false;
        }
    }

    public static function useBitrixVue(): bool
    {
        if (self::$bitrixVueActive !== null) {
            return self::$bitrixVueActive;
        }

        return self::useBitrixVueOption();
    }

    public static function vueModuleUrl(): string
    {
        if (self::useBitrixVue()) {
            return self::JS_VUE_BITRIX_BRIDGE;
        }

        return self::JS_VUE_VENDOR;
    }

    public static function pagesIblockId(): int
    {
        return max(0, (int)Option::get(self::MODULE_ID, self::OPT_PAGES_IBLOCK_ID, '0'));
    }

    public static function mainIblockSliderId(): int
    {
        return max(0, (int)Option::get(self::MODULE_ID, self::OPT_MAIN_IBLOCK_SLIDER_ID, '0'));
    }

    public static function mainIblockAdvantagesId(): int
    {
        return max(0, (int)Option::get(self::MODULE_ID, self::OPT_MAIN_IBLOCK_ADVANTAGES_ID, '0'));
    }

    public static function mainIblockAboutId(): int
    {
        return max(0, (int)Option::get(self::MODULE_ID, self::OPT_MAIN_IBLOCK_ABOUT_ID, '0'));
    }

    public static function mainIblockSeoId(): int
    {
        return max(0, (int)Option::get(self::MODULE_ID, self::OPT_MAIN_IBLOCK_SEO_ID, '0'));
    }

    public static function homeSectionsLimit(): int
    {
        $limit = (int)Option::get(
            self::MODULE_ID,
            self::OPT_HOME_SECTIONS_LIMIT,
            (string)self::DEFAULT_HOME_SECTIONS_LIMIT
        );

        return $limit > 0 ? $limit : self::DEFAULT_HOME_SECTIONS_LIMIT;
    }

    public static function homeSectionsUfShowCode(): string
    {
        $code = trim((string)Option::get(
            self::MODULE_ID,
            self::OPT_HOME_SECTIONS_UF_SHOW,
            self::DEFAULT_HOME_SECTIONS_UF_SHOW
        ));

        return $code !== '' ? $code : self::DEFAULT_HOME_SECTIONS_UF_SHOW;
    }

    public static function homeSectionsUfSortCode(): string
    {
        $code = trim((string)Option::get(
            self::MODULE_ID,
            self::OPT_HOME_SECTIONS_UF_SORT,
            self::DEFAULT_HOME_SECTIONS_UF_SORT
        ));

        return $code !== '' ? $code : self::DEFAULT_HOME_SECTIONS_UF_SORT;
    }

    public static function defaultHomeSectionsUfShowCode(): string
    {
        return self::DEFAULT_HOME_SECTIONS_UF_SHOW;
    }

    public static function defaultHomeSectionsUfSortCode(): string
    {
        return self::DEFAULT_HOME_SECTIONS_UF_SORT;
    }

    /**
     * @return array<string, string>
     */
    public static function pagesViewFieldMap(): array
    {
        return self::decodeFieldMapOption(
            self::OPT_PAGES_VIEW_FIELD_MAP,
            [
                'title' => 'NAME',
                'body' => 'DETAIL_TEXT',
                'metaTitle' => 'PROPERTY_META_TITLE',
                'metaDescription' => 'PROPERTY_META_DESCRIPTION',
                'breadcrumbTitle' => 'PROPERTY_BREADCRUMB_TITLE',
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function homeViewFieldMap(): array
    {
        return self::decodeFieldMapOption(
            self::OPT_HOME_VIEW_FIELD_MAP,
            [
                'slider' => [
                    'image' => 'PREVIEW_PICTURE',
                    'link' => 'PROPERTY_LINK',
                    'caption' => 'PROPERTY_CAPTION',
                ],
                'advantages' => [
                    'title' => 'NAME',
                    'text' => 'PROPERTY_TEXT',
                    'icon' => 'PROPERTY_ICON',
                ],
                'about' => [
                    'title' => 'NAME',
                    'body' => 'DETAIL_TEXT',
                    'image' => 'PROPERTY_ABOUT_IMAGE',
                ],
                'seo' => [
                    'body' => 'DETAIL_TEXT',
                ],
            ],
            true
        );
    }

    public static function homeShowSlider(): bool
    {
        return Option::get(self::MODULE_ID, self::OPT_HOME_SHOW_SLIDER, 'Y') === 'Y';
    }

    public static function homeShowAdvantages(): bool
    {
        return Option::get(self::MODULE_ID, self::OPT_HOME_SHOW_ADVANTAGES, 'Y') === 'Y';
    }

    public static function homeShowAbout(): bool
    {
        return Option::get(self::MODULE_ID, self::OPT_HOME_SHOW_ABOUT, 'Y') === 'Y';
    }

    public static function homeShowSeo(): bool
    {
        return Option::get(self::MODULE_ID, self::OPT_HOME_SHOW_SEO, 'Y') === 'Y';
    }

    public static function homeShowCatalog(): bool
    {
        return Option::get(self::MODULE_ID, self::OPT_HOME_SHOW_CATALOG, 'Y') === 'Y';
    }

    /**
     * @param array<string, string> $default
     * @return array<string, mixed>
     */
    private static function decodeFieldMapOption(string $optionName, array $default, bool $nested = false): array
    {
        $raw = (string)Option::get(self::MODULE_ID, $optionName, '');
        if ($raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $default;
        }

        return $nested ? array_replace_recursive($default, $decoded) : array_merge($default, $decoded);
    }
}
