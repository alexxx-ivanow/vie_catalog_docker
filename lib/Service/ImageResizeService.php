<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\File\Image;
use Bitrix\Main\File\Image\Rectangle;
use Bitrix\Main\FileTable;

/**
 * D7-ресайз изображений с дисковым кешем (аналог CFile::ResizeImageGet).
 *
 * Первый вызов генерирует файл в /upload/resize_cache/myapp/..., дальнейшие
 * вызовы с теми же параметрами возвращают готовый путь (file_exists + memo),
 * без запуска GD. Смена размеров/качества/формата ведёт в отдельную папку.
 *
 * Кеш само-инвалидируется при замене файла: в ключ входит TIMESTAMP_X из b_file.
 * Очистка вручную: rm -rf upload/resize_cache/myapp/
 */
final class ImageResizeService
{
    /** Листинг каталога/поиск: рендер 200×200 @2x, квадрат-кроп. */
    public const SIZE_LIST = [400, 400, Image::RESIZE_EXACT];

    /** Детальная карточка: рендер 400×400 @2x. */
    public const SIZE_DETAIL = [800, 800, Image::RESIZE_EXACT];

    /** Миниатюра корзины. */
    public const SIZE_THUMB_CART = [96, 96, Image::RESIZE_EXACT];

    /** Миниатюры чекаута и заказов ЛК (рендер 72 @2x). */
    public const SIZE_THUMB_SMALL = [96, 96, Image::RESIZE_EXACT];

    /** Картинка раздела. */
    public const SIZE_SECTION = [400, 400, Image::RESIZE_EXACT];

    /** Слайдер главной. */
    public const SIZE_HOME_SLIDER = [1920, 700, Image::RESIZE_EXACT];

    /** Иконки преимуществ на главной (рендер 48 @2x). */
    public const SIZE_HOME_ICON = [96, 96, Image::RESIZE_EXACT];

    /** Блок «О магазине» на главной. */
    public const SIZE_HOME_ABOUT = [1120, 800, Image::RESIZE_EXACT];

    private const RESIZE_TYPES = [
        Image::RESIZE_PROPORTIONAL_ALT,
        Image::RESIZE_PROPORTIONAL,
        Image::RESIZE_EXACT,
    ];

    private const OUTPUT_TYPES = [
        'jpg' => ['format' => Image::FORMAT_JPEG, 'ext' => 'jpg'],
        'png' => ['format' => Image::FORMAT_PNG, 'ext' => 'png'],
        'webp' => ['format' => Image::FORMAT_WEBP, 'ext' => 'webp'],
    ];

    private const CACHE_DIR = 'resize_cache/myapp';

    /** @var array<string, array{src: string, width: int, height: int}> */
    private static array $memo = [];

    private static int $errorLogCount = 0;

    /**
     * Ресайз файла b_file с кешем на диске.
     *
     * @param int|null $fileId ID файла из b_file
     * @param int $width целевая ширина
     * @param int $height целевая высота
     * @param int $resizeType Image::RESIZE_* (совместимо с BX_RESIZE_IMAGE_*)
     * @param int|null $quality 1..100; null — опция main image_resize_quality
     * @param string $outputType 'jpg' | 'png' | 'webp'
     * @return array{src: string, width: int, height: int} src='' если файла нет,
     *         оригинал — если ресайз не требуется/невозможен
     */
    public static function resize(
        ?int $fileId,
        int $width,
        int $height,
        int $resizeType = Image::RESIZE_PROPORTIONAL,
        ?int $quality = null,
        string $outputType = 'webp'
    ): array {
        $fileId = (int)$fileId;
        if ($fileId <= 0) {
            return ['src' => '', 'width' => 0, 'height' => 0];
        }

        $memoKey = $fileId . '|' . $width . '|' . $height . '|' . $resizeType . '|' . ($quality ?? 'def') . '|' . $outputType;
        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }

        $row = FileTable::getList([
            'select' => ['ID', 'SUBDIR', 'FILE_NAME', 'TIMESTAMP_X'],
            'filter' => ['=ID' => $fileId],
            'limit' => 1,
        ])->fetch();

        $result = $row
            ? self::resizeByRow(is_array($row) ? $row : [], $width, $height, $resizeType, $quality, $outputType)
            : ['src' => '', 'width' => 0, 'height' => 0];

        return self::$memo[$memoKey] = $result;
    }

    /**
     * Батч-ресайз: один запрос b_file на список ID.
     *
     * @param list<int> $fileIds
     * @return array<int, array{src: string, width: int, height: int}>
     */
    public static function resizeMany(
        array $fileIds,
        int $width,
        int $height,
        int $resizeType = Image::RESIZE_PROPORTIONAL,
        ?int $quality = null,
        string $outputType = 'webp'
    ): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fileIds))));
        if ($ids === []) {
            return [];
        }

        $rows = [];
        $res = FileTable::getList([
            'select' => ['ID', 'SUBDIR', 'FILE_NAME', 'TIMESTAMP_X'],
            'filter' => ['@ID' => $ids],
        ]);
        while ($row = $res->fetch()) {
            $rows[(int)$row['ID']] = $row;
        }

        $result = [];
        foreach ($ids as $id) {
            $memoKey = $id . '|' . $width . '|' . $height . '|' . $resizeType . '|' . ($quality ?? 'def') . '|' . $outputType;
            if (isset(self::$memo[$memoKey])) {
                $result[$id] = self::$memo[$memoKey];
                continue;
            }
            $result[$id] = isset($rows[$id])
                ? self::resizeByRow($rows[$id], $width, $height, $resizeType, $quality, $outputType)
                : ['src' => '', 'width' => 0, 'height' => 0];
            self::$memo[$memoKey] = $result[$id];
        }

        return $result;
    }

    /**
     * @param array{ID: mixed, SUBDIR: mixed, FILE_NAME: mixed, TIMESTAMP_X: mixed} $row
     * @return array{src: string, width: int, height: int}
     */
    private static function resizeByRow(
        array $row,
        int $width,
        int $height,
        int $resizeType,
        ?int $quality,
        string $outputType
    ): array {
        $width = max(1, $width);
        $height = max(1, $height);
        if (!in_array($resizeType, self::RESIZE_TYPES, true)) {
            $resizeType = Image::RESIZE_PROPORTIONAL;
        }
        if (!isset(self::OUTPUT_TYPES[$outputType])) {
            $outputType = 'webp';
        }

        $quality = $quality ?? (int)Option::get('main', 'image_resize_quality', 95);
        if ($quality <= 0 || $quality > 100) {
            $quality = 95;
        }

        $fileId = (int)$row['ID'];
        $subDir = trim((string)$row['SUBDIR'], '/ ');
        $fileName = (string)$row['FILE_NAME'];
        if ($fileId <= 0 || $subDir === '' || $fileName === '') {
            return ['src' => '', 'width' => 0, 'height' => 0];
        }

        $uploadDir = trim((string)Option::get('main', 'upload_dir', 'upload'), '/ ');
        $srcAbs = self::documentRoot() . '/' . $uploadDir . '/' . $subDir . '/' . $fileName;
        if (!is_file($srcAbs)) {
            return ['src' => '', 'width' => 0, 'height' => 0];
        }

        try {
            $image = new Image($srcAbs);
            $info = $image->getInfo();
            if ($info === null || !$info->isSupported()) {
                // SVG и прочие нераспознанные форматы — наружу как есть.
                return self::originalResult('/' . $uploadDir . '/' . $subDir . '/' . $fileName, $width, $height);
            }

            $srcFormat = (int)$info->getFormat();
            if ($srcFormat === Image::FORMAT_GIF) {
                // GD теряет анимацию GIF — отдавать оригинал.
                return self::originalResult('/' . $uploadDir . '/' . $subDir . '/' . $fileName, $width, $height);
            }

            $outputType = self::resolveOutputType($outputType, $srcFormat);
            if ($outputType === null) {
                // Запрошен webp, но движок его не умеет, а исходник — webp.
                return self::originalResult('/' . $uploadDir . '/' . $subDir . '/' . $fileName, $width, $height);
            }

            $srcWidth = (int)$info->getWidth();
            $srcHeight = (int)$info->getHeight();

            // Апскейл не делаем: целевые размеры >= исходных — вернуть оригинал (семантика ядра).
            if ($width >= $srcWidth && $height >= $srcHeight) {
                return self::originalResult('/' . $uploadDir . '/' . $subDir . '/' . $fileName, $width, $height);
            }

            if (!$image->load()) {
                return self::originalResult('/' . $uploadDir . '/' . $subDir . '/' . $fileName, $width, $height);
            }

            $hash = substr(md5($fileId . '|' . $subDir . '|' . $fileName . '|' . (string)$row['TIMESTAMP_X']), 0, 8);
            $output = self::OUTPUT_TYPES[$outputType];
            $baseName = pathinfo($fileName, PATHINFO_FILENAME);
            $dirRel = '/' . $uploadDir . '/' . self::CACHE_DIR . '/' . $subDir
                . '/' . $width . 'x' . $height . '_' . $resizeType . '_q' . $quality . '_' . $outputType . '_' . $hash;
            $dstAbs = self::documentRoot() . $dirRel . '/' . $baseName . '.' . $output['ext'];

            if (is_file($dstAbs)) {
                return self::cachedResult($dstAbs, $dirRel . '/' . $baseName . '.' . $output['ext'], $width, $height);
            }

            $sourceRect = new Rectangle($srcWidth, $srcHeight);
            $destRect = new Rectangle($width, $height);
            if (!$sourceRect->resize($destRect, $resizeType)) {
                return self::originalResult('/' . $uploadDir . '/' . $subDir . '/' . $fileName, $width, $height);
            }

            CheckDirPath($dstAbs);
            $image->resize($sourceRect, $destRect);
            $image->saveAs($dstAbs, $quality, $output['format']);

            if (!is_file($dstAbs)) {
                return self::originalResult('/' . $uploadDir . '/' . $subDir . '/' . $fileName, $width, $height);
            }

            return self::cachedResult($dstAbs, $dirRel . '/' . $baseName . '.' . $output['ext'], $width, $height);
        } catch (\Throwable $e) {
            if (self::$errorLogCount < 3) {
                self::$errorLogCount++;
                Debug::writeToFile(
                    ['fileId' => $fileId, 'error' => $e->getMessage()],
                    'ImageResizeService: ' . date('Y-m-d H:i:s')
                );
            }

            return self::originalResult('/' . $uploadDir . '/' . $subDir . '/' . $fileName, $width, $height);
        }
    }

    /**
     * GD без webp: webp → формат источника (png/jpg), исходник webp — оригинал.
     */
    private static function resolveOutputType(string $outputType, int $sourceFormat): ?string
    {
        if ($outputType !== 'webp') {
            return $outputType;
        }

        if (self::isWebpSupported()) {
            return 'webp';
        }

        if ($sourceFormat === Image::FORMAT_WEBP) {
            return null;
        }

        return $sourceFormat === Image::FORMAT_PNG ? 'png' : 'jpg';
    }

    private static function isWebpSupported(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromwebp');
    }

    /**
     * @return array{src: string, width: int, height: int}
     */
    private static function cachedResult(string $absPath, string $srcUrl, int $fallbackWidth, int $fallbackHeight): array
    {
        $dims = @getimagesize($absPath);
        if (is_array($dims)) {
            return ['src' => $srcUrl, 'width' => (int)$dims[0], 'height' => (int)$dims[1]];
        }

        return ['src' => $srcUrl, 'width' => $fallbackWidth, 'height' => $fallbackHeight];
    }

    /**
     * Ресайз не потребовался — вернуть оригинальные размеры файла, если читаются.
     *
     * @return array{src: string, width: int, height: int}
     */
    private static function originalResult(string $srcUrl, int $fallbackWidth, int $fallbackHeight): array
    {
        $dims = @getimagesize(self::documentRoot() . $srcUrl);
        if (is_array($dims)) {
            return ['src' => $srcUrl, 'width' => (int)$dims[0], 'height' => (int)$dims[1]];
        }

        return ['src' => $srcUrl, 'width' => $fallbackWidth, 'height' => $fallbackHeight];
    }

    private static function documentRoot(): string
    {
        return rtrim((string)Application::getDocumentRoot(), '/\\');
    }
}
