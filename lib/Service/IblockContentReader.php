<?php

namespace MyApp\Catalog\Service;

use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;
use MyApp\Catalog\Config\Assets;

final class IblockContentReader
{
    /**
     * @param array<string, mixed> $filter
     * @param list<string> $select
     * @return list<array<string, mixed>>
     */
    public static function getActiveElements(
        int $iblockId,
        array $filter = [],
        array $select = ['ID', 'NAME', 'CODE', 'SORT', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'PREVIEW_PICTURE', 'DETAIL_PICTURE'],
        string $order = 'ASC',
        ?int $limit = null
    ): array {
        if ($iblockId <= 0 || !Loader::includeModule('iblock')) {
            return [];
        }

        $params = [
            'filter' => array_merge([
                '=IBLOCK_ID' => $iblockId,
                '=ACTIVE' => 'Y',
            ], $filter),
            'select' => $select,
            'order' => ['SORT' => $order, 'ID' => 'ASC'],
        ];
        if ($limit !== null && $limit > 0) {
            $params['limit'] = $limit;
        }

        $rows = [];
        $result = ElementTable::getList($params);
        while ($row = $result->fetch()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getActiveElementByCode(int $iblockId, string $code): ?array
    {
        $code = trim($code);
        if ($iblockId <= 0 || $code === '') {
            return null;
        }

        $row = ElementTable::getList([
            'filter' => [
                '=IBLOCK_ID' => $iblockId,
                '=ACTIVE' => 'Y',
                '=CODE' => $code,
            ],
            'select' => ['ID', 'NAME', 'CODE', 'DETAIL_TEXT', 'PREVIEW_TEXT', 'PREVIEW_PICTURE'],
            'limit' => 1,
        ])->fetch();

        return $row ?: null;
    }

    /**
     * @param list<int> $elementIds
     * @param list<string> $propertyCodes
     * @return array<int, array<string, string>>
     */
    public static function loadPropertiesByElementIds(int $iblockId, array $elementIds, array $propertyCodes): array
    {
        $elementIds = array_values(array_filter(array_map('intval', $elementIds), static fn(int $id): bool => $id > 0));
        $propertyCodes = Assets::normalizePropertyCodes($propertyCodes);
        if ($iblockId <= 0 || $elementIds === [] || $propertyCodes === [] || !Loader::includeModule('iblock')) {
            return [];
        }

        $propertyIdByCode = [];
        $propRes = PropertyTable::getList([
            'filter' => ['=IBLOCK_ID' => $iblockId, '@CODE' => $propertyCodes],
            'select' => ['ID', 'CODE'],
        ]);
        while ($prop = $propRes->fetch()) {
            $propertyIdByCode[(int)$prop['ID']] = (string)$prop['CODE'];
        }
        if ($propertyIdByCode === []) {
            return [];
        }

        $result = [];
        $valueRes = ElementPropertyTable::getList([
            'filter' => [
                '@IBLOCK_ELEMENT_ID' => $elementIds,
                '@IBLOCK_PROPERTY_ID' => array_keys($propertyIdByCode),
            ],
            'select' => ['IBLOCK_ELEMENT_ID', 'IBLOCK_PROPERTY_ID', 'VALUE'],
        ]);
        while ($val = $valueRes->fetch()) {
            $elId = (int)$val['IBLOCK_ELEMENT_ID'];
            $propId = (int)$val['IBLOCK_PROPERTY_ID'];
            $code = $propertyIdByCode[$propId] ?? '';
            if ($code === '') {
                continue;
            }
            $result[$elId][$code] = (string)$val['VALUE'];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<string, string> $properties
     * @param array<string, string> $fieldMap key => descriptor NAME|DETAIL_TEXT|PROPERTY_CODE
     * @return array<string, string>
     */
    public static function mapToView(array $element, array $properties, array $fieldMap): array
    {
        $view = [];
        foreach ($fieldMap as $viewKey => $descriptor) {
            $descriptor = trim((string)$descriptor);
            if ($descriptor === '') {
                continue;
            }
            if (str_starts_with($descriptor, 'PROPERTY_')) {
                $propCode = substr($descriptor, 9);
                $view[$viewKey] = (string)($properties[$propCode] ?? '');
                continue;
            }
            $view[$viewKey] = (string)($element[$descriptor] ?? '');
        }

        return $view;
    }

    public static function fileSrc(mixed $fileId): string
    {
        $id = (int)$fileId;
        if ($id <= 0) {
            return Assets::NO_PHOTO_IMAGE;
        }

        $path = (string)\CFile::GetPath($id);

        return $path !== '' ? $path : Assets::NO_PHOTO_IMAGE;
    }

    /**
     * @param list<string> $propertyCodes
     */
    public static function collectPropertyCodesFromMap(array $fieldMap): array
    {
        $codes = [];
        foreach ($fieldMap as $descriptor) {
            if (!is_string($descriptor)) {
                continue;
            }
            if (str_starts_with($descriptor, 'PROPERTY_')) {
                $codes[] = substr($descriptor, 9);
            }
        }

        return Assets::normalizePropertyCodes($codes);
    }
}
