<?php

namespace MyApp\Catalog\Service;

use Bitrix\Iblock\Model\Section;
use Bitrix\Main\Loader;
use MyApp\Catalog\Config\Assets;

final class HomeService
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function getRootSections(): array
    {
        return CatalogService::getSections();
    }

    /**
     * Корневые разделы каталога для главной (UF + лимит).
     *
     * @return list<array<string, mixed>>
     */
    public static function getHomeRootSections(?int $limit = null): array
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $iblockId = Assets::catalogIblockId();
        if ($iblockId <= 0) {
            return [];
        }

        $limit = $limit ?? Assets::homeSectionsLimit();
        $ufShow = Assets::homeSectionsUfShowCode();
        $ufSort = Assets::homeSectionsUfSortCode();
        $hasUfShow = self::sectionUserFieldExists($iblockId, $ufShow);
        $hasUfSort = self::sectionUserFieldExists($iblockId, $ufSort);

        if (!$hasUfShow) {
            return [];
        }

        $sectionEntity = Section::compileEntityByIblock($iblockId);
        if ($sectionEntity === null) {
            return [];
        }

        $select = ['ID', 'NAME', 'CODE', 'PICTURE', 'DESCRIPTION', 'SORT'];
        $select[] = $ufShow;
        if ($hasUfSort && $ufSort !== $ufShow) {
            $select[] = $ufSort;
        }

        $filter = [
            '=ACTIVE' => 'Y',
            '=DEPTH_LEVEL' => 1,
            '=' . $ufShow => 1,
        ];

        $order = ['SORT' => 'ASC', 'NAME' => 'ASC'];
        if ($hasUfSort) {
            $order = [$ufSort => 'ASC', 'SORT' => 'ASC', 'NAME' => 'ASC'];
        }

        $sections = [];
        try {
            $result = $sectionEntity::getList([
                'filter' => $filter,
                'select' => $select,
                'order' => $order,
                'limit' => $limit,
            ]);
            while ($row = $result->fetch()) {
                $sections[] = self::mapSectionRow($row);
            }
        } catch (\Throwable) {
            return [];
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapSectionRow(array $row): array
    {
        return [
            'ID' => (int)$row['ID'],
            'NAME' => (string)$row['NAME'],
            'CODE' => (string)($row['CODE'] ?? ''),
            'DESCRIPTION' => (string)($row['DESCRIPTION'] ?? ''),
            'PICTURE' => IblockContentReader::fileSrc((int)($row['PICTURE'] ?? 0)),
            'URL' => '/catalog/section/' . (int)$row['ID'],
        ];
    }

    private static function sectionUserFieldExists(int $iblockId, string $fieldName): bool
    {
        $fieldName = trim($fieldName);
        if ($iblockId <= 0 || $fieldName === '') {
            return false;
        }

        if (!class_exists(\CUserTypeEntity::class)) {
            return false;
        }

        return (bool)\CUserTypeEntity::GetList([], [
            'ENTITY_ID' => 'IBLOCK_' . $iblockId . '_SECTION',
            'FIELD_NAME' => $fieldName,
        ])->Fetch();
    }
}
