<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Data\Cache;
use MyApp\Catalog\Config\Assets;

final class HomeMainContentService
{
    private const CACHE_DIR = '/myapp_catalog/home/';

    /**
     * @return array<string, mixed>
     */
    public static function buildHomePayload(): array
    {
        $cache = Cache::createInstance();
        $cacheId = self::cacheKey();
        $ttl = Assets::catalogCacheTtl();
        if ($cache->initCache($ttl, $cacheId, self::CACHE_DIR)) {
            $vars = $cache->getVars();

            return is_array($vars) ? $vars : self::buildUncached();
        }

        if ($cache->startDataCache()) {
            $payload = self::buildUncached();
            $cache->endDataCache($payload);

            return $payload;
        }

        return self::buildUncached();
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildUncached(): array
    {
        $map = Assets::homeViewFieldMap();
        $homeSections = Assets::homeShowCatalog() ? HomeService::getHomeRootSections() : [];
        $showCatalog = Assets::homeShowCatalog() && $homeSections !== [];

        return [
            'show' => [
                'slider' => Assets::homeShowSlider(),
                'advantages' => Assets::homeShowAdvantages(),
                'about' => Assets::homeShowAbout(),
                'seo' => Assets::homeShowSeo(),
                'catalog' => $showCatalog,
            ],
            'slider' => Assets::homeShowSlider() ? self::loadSlider($map['slider'] ?? []) : [],
            'advantages' => Assets::homeShowAdvantages() ? self::loadAdvantages($map['advantages'] ?? []) : [],
            'about' => Assets::homeShowAbout() ? self::loadSingleton(Assets::mainIblockAboutId(), $map['about'] ?? []) : null,
            'seo' => Assets::homeShowSeo() ? self::loadSingleton(Assets::mainIblockSeoId(), $map['seo'] ?? []) : null,
            'sections' => $homeSections,
        ];
    }

    /**
     * @param array<string, string> $map
     * @return list<array<string, mixed>>
     */
    private static function loadSlider(array $map): array
    {
        $iblockId = Assets::mainIblockSliderId();
        if ($iblockId <= 0) {
            return [];
        }

        $elements = IblockContentReader::getActiveElements($iblockId);
        $propCodes = IblockContentReader::collectPropertyCodesFromMap($map);
        $props = IblockContentReader::loadPropertiesByElementIds(
            $iblockId,
            array_map(static fn(array $row): int => (int)$row['ID'], $elements),
            $propCodes
        );

        $imageKey = (string)($map['image'] ?? 'PREVIEW_PICTURE');
        $slides = [];
        foreach ($elements as $element) {
            $elId = (int)$element['ID'];
            $view = IblockContentReader::mapToView($element, $props[$elId] ?? [], $map);
            $fileId = str_starts_with($imageKey, 'PROPERTY_')
                ? (int)($props[$elId][substr($imageKey, 9)] ?? 0)
                : (int)($element[$imageKey] ?? 0);

            $slides[] = [
                'image' => IblockContentReader::fileSrc($fileId),
                'link' => $view['link'] ?? '',
                'caption' => $view['caption'] ?? '',
                'alt' => (string)($element['NAME'] ?? ''),
            ];
        }

        return $slides;
    }

    /**
     * @param array<string, string> $map
     * @return list<array<string, mixed>>
     */
    private static function loadAdvantages(array $map): array
    {
        $iblockId = Assets::mainIblockAdvantagesId();
        if ($iblockId <= 0) {
            return [];
        }

        $elements = IblockContentReader::getActiveElements($iblockId);
        $propCodes = IblockContentReader::collectPropertyCodesFromMap($map);
        $props = IblockContentReader::loadPropertiesByElementIds(
            $iblockId,
            array_map(static fn(array $row): int => (int)$row['ID'], $elements),
            $propCodes
        );

        $items = [];
        foreach ($elements as $element) {
            $elId = (int)$element['ID'];
            $view = IblockContentReader::mapToView($element, $props[$elId] ?? [], $map);
            $iconCode = str_starts_with((string)($map['icon'] ?? ''), 'PROPERTY_')
                ? substr((string)$map['icon'], 9)
                : 'ICON';
            $iconId = (int)($props[$elId][$iconCode] ?? 0);
            $text = $view['text'] ?? '';
            if ($text === '' && !empty($element['PREVIEW_TEXT'])) {
                $text = (string)$element['PREVIEW_TEXT'];
            }

            $items[] = [
                'title' => $view['title'] ?? (string)($element['NAME'] ?? ''),
                'text' => $text,
                'icon' => IblockContentReader::fileSrc($iconId),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, string> $map
     * @return array<string, mixed>|null
     */
    private static function loadSingleton(int $iblockId, array $map): ?array
    {
        if ($iblockId <= 0) {
            return null;
        }

        $elements = IblockContentReader::getActiveElements($iblockId, [], ['ID', 'NAME', 'DETAIL_TEXT', 'PREVIEW_PICTURE'], 'ASC', 1);
        $element = $elements[0] ?? null;
        if ($element === null) {
            return null;
        }

        $propCodes = IblockContentReader::collectPropertyCodesFromMap($map);
        $props = IblockContentReader::loadPropertiesByElementIds($iblockId, [(int)$element['ID']], $propCodes);
        $view = IblockContentReader::mapToView($element, $props[(int)$element['ID']] ?? [], $map);

        $imageKey = (string)($map['image'] ?? '');
        $fileId = 0;
        if ($imageKey !== '') {
            $fileId = str_starts_with($imageKey, 'PROPERTY_')
                ? (int)($props[(int)$element['ID']][substr($imageKey, 9)] ?? 0)
                : (int)($element[$imageKey] ?? 0);
        }

        return [
            'title' => $view['title'] ?? (string)($element['NAME'] ?? ''),
            'body' => $view['body'] ?? (string)($element['DETAIL_TEXT'] ?? ''),
            'image' => $fileId > 0 ? IblockContentReader::fileSrc($fileId) : '',
        ];
    }

    public static function clearCache(): void
    {
        $cache = Cache::createInstance();
        $cache->cleanDir(self::CACHE_DIR);
    }

    private static function cacheKey(): string
    {
        return md5(
            Assets::mainIblockSliderId()
            . '|' . Assets::mainIblockAdvantagesId()
            . '|' . Assets::mainIblockAboutId()
            . '|' . Assets::mainIblockSeoId()
            . '|' . Assets::catalogIblockId()
            . '|' . Assets::homeSectionsUfShowCode()
            . '|' . Assets::homeSectionsLimit()
            . '|' . json_encode(Assets::homeViewFieldMap(), JSON_UNESCAPED_UNICODE)
            . '|' . (Assets::homeShowSlider() ? '1' : '0')
            . (Assets::homeShowAdvantages() ? '1' : '0')
            . (Assets::homeShowAbout() ? '1' : '0')
            . (Assets::homeShowSeo() ? '1' : '0')
            . (Assets::homeShowCatalog() ? '1' : '0')
        );
    }
}
