<?php

namespace MyApp\Catalog\Service;

use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use MyApp\Catalog\Config\Assets;

final class CatalogSkuService
{
    private const MODULE_ID = 'myapp.catalog';

    private const OPT_OFFERS_IBLOCK_ID = 'offers_iblock_id';

    /** @var array<string, mixed>|false|null */
    private static $skuInfoCache = null;

    /**
     * @return array<string, mixed>|null
     */
    public static function getSkuInfo(): ?array
    {
        if (self::$skuInfoCache !== null) {
            return self::$skuInfoCache === false ? null : self::$skuInfoCache;
        }

        if (!Loader::includeModule('catalog')) {
            self::$skuInfoCache = false;

            return null;
        }

        $override = (int)Option::get(self::MODULE_ID, self::OPT_OFFERS_IBLOCK_ID, '0');
        if ($override > 0) {
            $info = \CCatalogSku::GetInfoByOfferIBlock($override);
            if (is_array($info) && !empty($info['PRODUCT_IBLOCK_ID'])) {
                self::$skuInfoCache = $info;

                return $info;
            }
        }

        $info = \CCatalogSku::GetInfoByProductIBlock(Assets::catalogIblockId());
        self::$skuInfoCache = is_array($info) && !empty($info['IBLOCK_ID']) ? $info : false;

        return self::$skuInfoCache === false ? null : self::$skuInfoCache;
    }

    public static function getOffersIblockId(): int
    {
        $info = self::getSkuInfo();

        return $info !== null ? (int)($info['IBLOCK_ID'] ?? 0) : 0;
    }

    public static function getLinkPropertyId(): int
    {
        $info = self::getSkuInfo();

        return $info !== null ? (int)($info['SKU_PROPERTY_ID'] ?? 0) : 0;
    }

    /**
     * @return list<string>
     */
    public static function skuPropertyCodes(): array
    {
        $raw = trim((string)Option::get(self::MODULE_ID, Assets::OPT_SKU_PROPERTY_CODES, ''));
        if ($raw === '') {
            return ['COLOR', 'SIZE'];
        }

        $decoded = json_decode($raw, true);

        return Assets::normalizePropertyCodes(is_array($decoded) ? $decoded : ['COLOR', 'SIZE']);
    }

    public static function isSkuProduct(int $parentId): bool
    {
        $parentId = (int)$parentId;
        if ($parentId <= 0 || self::getOffersIblockId() <= 0) {
            return false;
        }

        if (!Loader::includeModule('catalog')) {
            return false;
        }

        $row = ProductTable::getList([
            'select' => ['TYPE'],
            'filter' => ['=ID' => $parentId],
            'limit' => 1,
        ])->fetch();

        if ($row && (int)($row['TYPE'] ?? 0) === ProductTable::TYPE_SKU) {
            return self::getOfferIdsForProduct($parentId) !== [];
        }

        return self::getOfferIdsForProduct($parentId) !== [];
    }

    public static function resolveParentIdFromOffer(int $offerId): int
    {
        $offerId = (int)$offerId;
        if ($offerId <= 0 || !Loader::includeModule('iblock')) {
            return 0;
        }

        if (Loader::includeModule('catalog')) {
            $info = \CCatalogSku::GetProductInfo($offerId);
            if (is_array($info) && !empty($info['ID'])) {
                return (int)$info['ID'];
            }
        }

        $linkPropId = self::getLinkPropertyId();
        if ($linkPropId <= 0) {
            return 0;
        }

        $res = \CIBlockElement::GetProperty(
            self::getOffersIblockId(),
            $offerId,
            [],
            ['ID' => $linkPropId]
        );
        if ($row = $res->Fetch()) {
            return (int)($row['VALUE'] ?? 0);
        }

        return 0;
    }

    /**
     * @return list<int>
     */
    public static function getOfferIdsForProduct(int $parentId): array
    {
        $parentId = (int)$parentId;
        $offersIblockId = self::getOffersIblockId();
        $linkPropId = self::getLinkPropertyId();
        if ($parentId <= 0 || $offersIblockId <= 0 || $linkPropId <= 0) {
            return [];
        }

        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $ids = [];
        $res = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            [
                'IBLOCK_ID' => $offersIblockId,
                'ACTIVE' => 'Y',
                'PROPERTY_' . $linkPropId => $parentId,
            ],
            false,
            false,
            ['ID']
        );
        while ($row = $res->Fetch()) {
            $ids[] = (int)$row['ID'];
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function loadOfferRows(int $parentId): array
    {
        $offerIds = self::getOfferIdsForProduct($parentId);
        if ($offerIds === []) {
            return [];
        }

        if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
            return [];
        }

        $articleCode = Assets::catalogArticlePropertyCode();
        $skuCodes = self::skuPropertyCodes();
        $priceGroupId = CatalogService::catalogPriceGroupIdPublic();
        $displayCurrency = CatalogService::catalogDisplayCurrency();

        $rows = [];
        $res = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['IBLOCK_ID' => self::getOffersIblockId(), 'ID' => $offerIds, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'NAME', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
        );

        while ($element = $res->GetNextElement()) {
            $fields = $element->GetFields();
            $offerId = (int)$fields['ID'];
            $props = self::propertiesByCode($element->GetProperties());

            $priceRow = PriceTable::getList([
                'select' => ['PRICE', 'CURRENCY'],
                'filter' => [
                    '=PRODUCT_ID' => $offerId,
                    '=CATALOG_GROUP_ID' => $priceGroupId,
                ],
                'limit' => 1,
            ])->fetch();

            $price = 0.0;
            if ($priceRow) {
                $from = trim((string)($priceRow['CURRENCY'] ?? ''));
                if ($from === '') {
                    $from = CatalogService::catalogPriceStorageCurrencyPublic();
                }
                $price = CatalogService::convertCatalogPricePublic(
                    (float)$priceRow['PRICE'],
                    $from,
                    $displayCurrency
                );
            }

            $propMap = [];
            foreach ($skuCodes as $code) {
                $prop = $props[$code] ?? null;
                if (!is_array($prop)) {
                    continue;
                }
                $propType = (string)($prop['PROPERTY_TYPE'] ?? '');
                if ($propType === 'L') {
                    $enumId = (int)($prop['VALUE_ENUM_ID'] ?? 0);
                    if ($enumId <= 0 && is_numeric($prop['VALUE'] ?? '')) {
                        $enumId = (int)$prop['VALUE'];
                    }
                    if ($enumId > 0) {
                        $propMap[$code] = $enumId;
                    }
                    continue;
                }
                $str = trim((string)($prop['VALUE'] ?? ''));
                if ($str !== '') {
                    $propMap[$code] = $str;
                }
            }

            $article = '';
            if (isset($props[$articleCode])) {
                $article = trim((string)($props[$articleCode]['VALUE'] ?? ''));
            }
            if ($article === '' && isset($props['CML2_ARTICLE'])) {
                $article = trim((string)($props['CML2_ARTICLE']['VALUE'] ?? ''));
            }

            $pictureId = (int)($fields['DETAIL_PICTURE'] ?: $fields['PREVIEW_PICTURE']);
            $picture = '';
            if ($pictureId > 0) {
                $resized = ImageResizeService::resize($pictureId, ...ImageResizeService::SIZE_DETAIL);
                $picture = $resized['src'] !== '' ? $resized['src'] : CatalogService::resolveFilePathPublic($pictureId);
            }

            $rows[] = [
                'id' => $offerId,
                'name' => (string)$fields['NAME'],
                'article' => $article,
                'price' => $price,
                'currency' => $displayCurrency,
                'picture' => $picture,
                'available' => $price > 0,
                'props' => $propMap,
            ];
        }

        return $rows;
    }

    /**
     * @return array{tree: array{props: list<array<string, mixed>>}, offers: list<array<string, mixed>>, defaultOfferId: int}
     */
    public static function buildSkuPayload(int $parentId): array
    {
        $offers = self::loadOfferRows($parentId);
        $tree = self::buildTreeFromOffers($offers);
        $defaultOfferId = self::resolveDefaultOfferId($parentId, $offers);

        return [
            'tree' => $tree,
            'offers' => $offers,
            'defaultOfferId' => $defaultOfferId,
        ];
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return array{props: list<array<string, mixed>>}
     */
    public static function buildTreeFromOffers(array $offers): array
    {
        $codes = self::skuPropertyCodes();
        if ($offers === [] || $codes === []) {
            return ['props' => []];
        }

        if (!Loader::includeModule('iblock')) {
            return ['props' => []];
        }

        $offersIblockId = self::getOffersIblockId();
        $propsMeta = [];
        foreach ($codes as $code) {
            $row = PropertyTable::getList([
                'select' => ['ID', 'CODE', 'NAME', 'PROPERTY_TYPE'],
                'filter' => ['=IBLOCK_ID' => $offersIblockId, '=CODE' => $code],
                'limit' => 1,
            ])->fetch();
            if (!$row) {
                continue;
            }
            $propsMeta[$code] = [
                'code' => $code,
                'name' => (string)$row['NAME'],
                'propertyId' => (int)$row['ID'],
                'type' => (string)$row['PROPERTY_TYPE'],
                'values' => [],
            ];
        }

        foreach ($offers as $offer) {
            $offerProps = is_array($offer['props'] ?? null) ? $offer['props'] : [];
            foreach ($propsMeta as $code => &$meta) {
                if (!array_key_exists($code, $offerProps)) {
                    continue;
                }
                $raw = $offerProps[$code];
                $enumKey = is_numeric($raw) ? (int)$raw : null;
                $key = $enumKey !== null ? 'e:' . $enumKey : 's:' . (string)$raw;
                if (!isset($meta['values'][$key])) {
                    $label = self::resolvePropertyValueLabel(
                        $meta['propertyId'],
                        $enumKey ?? (string)$raw,
                        (string)$meta['type']
                    );
                    $meta['values'][$key] = [
                        'id' => $enumKey ?? (string)$raw,
                        'value' => $label,
                    ];
                }
            }
            unset($meta);
        }

        $props = [];
        foreach ($propsMeta as $meta) {
            $values = array_values($meta['values']);
            if ($values === []) {
                continue;
            }
            $props[] = [
                'code' => $meta['code'],
                'name' => $meta['name'],
                'values' => $values,
            ];
        }

        return ['props' => $props];
    }

    /**
     * Оффер по умолчанию: первый в порядке loadOfferRows (SORT ASC, ID ASC) среди доступных.
     *
     * @param list<array<string, mixed>>|null $offers
     */
    public static function resolveDefaultOfferId(int $parentId, ?array $offers = null): int
    {
        $offers ??= self::loadOfferRows($parentId);
        if ($offers === []) {
            return 0;
        }

        foreach ($offers as $offer) {
            if (!empty($offer['available']) && (float)($offer['price'] ?? 0) > 0) {
                return (int)($offer['id'] ?? 0);
            }
        }

        return (int)($offers[0]['id'] ?? 0);
    }

    /**
     * Самый дешёвый доступный оффер (мин. цена, при равенстве — меньший ID).
     * Не используется для дефолта на карточке; оставлено для листинга, акций, A/B и т.п.
     *
     * @param list<array<string, mixed>>|null $offers
     */
    public static function resolveDefaultOfferIdByMinPrice(int $parentId, ?array $offers = null): int
    {
        $offers ??= self::loadOfferRows($parentId);
        if ($offers === []) {
            return 0;
        }

        $available = array_values(array_filter(
            $offers,
            static fn(array $row): bool => !empty($row['available']) && (float)($row['price'] ?? 0) > 0
        ));
        $pool = $available !== [] ? $available : $offers;

        usort($pool, static function (array $a, array $b): int {
            $pa = (float)($a['price'] ?? 0);
            $pb = (float)($b['price'] ?? 0);
            if (abs($pa - $pb) < 0.0001) {
                return ((int)$a['id']) <=> ((int)$b['id']);
            }

            return $pa <=> $pb;
        });

        return (int)($pool[0]['id'] ?? 0);
    }

    public static function resolveActiveOfferId(int $parentId, ?int $uidFromRequest): int
    {
        $offers = self::loadOfferRows($parentId);
        if ($offers === []) {
            return 0;
        }

        $uid = (int)$uidFromRequest;
        if ($uid > 0) {
            foreach ($offers as $offer) {
                if ((int)$offer['id'] === $uid && !empty($offer['available'])) {
                    return $uid;
                }
            }
        }

        return self::resolveDefaultOfferId($parentId, $offers);
    }

    /**
     * @param list<array<string, mixed>> $offers
     * @return array<string, int|string>
     */
    public static function selectedSkuPropsFromOffer(array $offers, int $offerId): array
    {
        foreach ($offers as $offer) {
            if ((int)($offer['id'] ?? 0) !== $offerId) {
                continue;
            }
            $props = $offer['props'] ?? [];

            return is_array($props) ? $props : [];
        }

        return [];
    }

    /**
     * @return array{min: float, max: float}|null
     */
    public static function getOfferPriceRange(int $parentId): ?array
    {
        $offers = self::loadOfferRows($parentId);
        if ($offers === []) {
            return null;
        }

        $prices = array_map(static fn(array $o): float => (float)($o['price'] ?? 0), $offers);
        $prices = array_values(array_filter($prices, static fn(float $p): bool => $p > 0));
        if ($prices === []) {
            return null;
        }

        return ['min' => min($prices), 'max' => max($prices)];
    }

    /**
     * @return list<int>
     */
    public static function getAllOfferIdsForProducts(array $parentIds): array
    {
        $parentIds = array_values(array_filter(array_map('intval', $parentIds)));
        if ($parentIds === [] || self::getOffersIblockId() <= 0) {
            return [];
        }

        $linkPropId = self::getLinkPropertyId();
        if ($linkPropId <= 0) {
            return [];
        }

        $connection = Application::getConnection();
        $idsSql = implode(',', $parentIds);
        $offersIblock = self::getOffersIblockId();
        $res = $connection->query("
            SELECT o.ID
            FROM b_iblock_element o
            INNER JOIN b_iblock_element_property lp
                ON lp.IBLOCK_ELEMENT_ID = o.ID
               AND lp.IBLOCK_PROPERTY_ID = {$linkPropId}
            WHERE o.IBLOCK_ID = {$offersIblock}
              AND o.ACTIVE = 'Y'
              AND lp.VALUE IN ({$idsSql})
        ");

        $offerIds = [];
        while ($row = $res->fetch()) {
            $offerIds[] = (int)$row['ID'];
        }

        return $offerIds;
    }

    /**
     * @param array<int|string, mixed> $properties
     * @return array<string, array<string, mixed>>
     */
    private static function propertiesByCode(array $properties): array
    {
        $byCode = [];
        foreach ($properties as $property) {
            if (!is_array($property)) {
                continue;
            }
            $code = trim((string)($property['CODE'] ?? ''));
            if ($code !== '') {
                $byCode[$code] = $property;
            }
        }

        return $byCode;
    }

    private static function resolvePropertyValueLabel(int $propertyId, int|string $raw, string $type): string
    {
        if ($type === 'L' && is_numeric($raw)) {
            $enum = \CIBlockPropertyEnum::GetByID((int)$raw);
            if (is_array($enum) && !empty($enum['VALUE'])) {
                return (string)$enum['VALUE'];
            }
        }

        return is_string($raw) ? $raw : (string)$raw;
    }
}
