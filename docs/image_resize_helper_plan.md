# План: D7-хелпер ресайза изображений с кешем и outputType (аналог CFile::ResizeImageGet)

Утверждён: 2026-11-08

## Context

Модуль `myapp.catalog` отдаёт все картинки оригиналами из `/upload` (`FileTable` → `getFilePath`/`getFilePaths`/`resolveFilePathPublic`, `IblockContentReader::fileSrc`) — ресайза нет нигде. Vue-шаблоны рисуют их в маленьких боксах (200×200 листинг, 96×96 корзина), слайдер главной грузит оригинал под 1920×700. Нужно: D7-хелпер (ширина/высота/resizeType как у ResizeImageGet/quality/**outputType: jpg|png|webp**), файлы кеша создаются один раз и дальше отдаются без вызова хелпера, плюс полная интеграция в точки выдачи. Формат по умолчанию — **webp**.

Факты: ядро 26.150.0, `\Bitrix\Main\File\Image` (`site/bitrix/modules/main/lib/File/Image.php`) умеет `load()` → `resize(Image\Rectangle)` → `saveAs($path, $quality, $format)` с `FORMAT_WEBP`; режимы `RESIZE_PROPORTIONAL_ALT=0 / PROPORTIONAL=1 / EXACT=2` идентичны `BX_RESIZE_IMAGE_*`. GD в образе собран без webp — пересборка обязательна. nginx уже отдаёт `.webp` статикой (`expires 7d`) — правки nginx не нужны. Паттерн кеша ядра: `/upload/resize_cache/{SUBDIR}/{w}_{h}_{mode}{md5}/{FILE_NAME}`; при целевых размерах ≥ исходных в proportional ядро возвращает оригинал.

## Шаг 1. Docker: webp в GD

`Dockerfile`: в apt-блок добавляется `libwebp-dev`; `docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp`.
Пересборка: `docker compose build php && docker compose up -d php`; проверка `docker exec shop_php php -r 'var_dump(function_exists("imagewebp"));'` → `bool(true)`.

## Шаг 2. Хелпер `ImageResizeService`

`lib/Service/ImageResizeService.php`, namespace `MyApp\Catalog\Service`; autoload-запись в `include.php`.

### API

```php
resize(?int $fileId, int $width, int $height,
       int $resizeType = Image::RESIZE_PROPORTIONAL,   // совместимо с BX_RESIZE_IMAGE_*
       ?int $quality = null,                            // null → опция main image_resize_quality
       string $outputType = 'webp'): array              // 'jpg' | 'png' | 'webp'
// => ['src' => string, 'width' => int, 'height' => int]; оригинал при fallback

resizeMany(array $fileIds, ...те же параметры): array  // map fileId => result, FileTable одним IN-запросом
```

Пресеты — public-константы: `SIZE_LIST` 400×400 EXACT (рендер 200@2x), `SIZE_DETAIL` 800×800 EXACT (400@2x), `SIZE_THUMB_CART` 96×96 EXACT, `SIZE_THUMB_SMALL` 96×96 EXACT (чекаут/заказы 72@2x), `SIZE_SECTION` 400×400 EXACT, `SIZE_HOME_SLIDER` 1920×700 EXACT, `SIZE_HOME_ICON` 96×96 EXACT, `SIZE_HOME_ABOUT` 1120×800 EXACT.

### Алгоритм

1. Валидация входа (fileId/width/height > 0; resizeType ∈ {0,1,2}; outputType ∈ {jpg,png,webp}).
2. Static per-request memo `[$fileId|$params => result]` — повторные вызовы без file_exists.
3. Ряд `FileTable` (`SUBDIR, FILE_NAME, TIMESTAMP_X`); нет ряда → `['src' => '', ...]`.
4. **Кеш-путь**: `/upload/resize_cache/myapp/{SUBDIR}/{w}x{h}_{mode}_q{quality}_{outputType}_{hash8}/{BASE}.{ext}`,
   `hash8 = substr(md5("{ID}|{SUBDIR}|{FILE_NAME}|{TIMESTAMP_X}"), 0, 8)` — само-инвалидация при замене файла;
   отдельная подпапка `myapp/` исключает коллизии с файлами штатного `ResizeImageGet`; `BASE` — имя исходника без расширения.
   `file_exists` → вернуть путь сразу (+ размеры getimagesize).
5. Генерация при промахе: `new \Bitrix\Main\File\Image($srcPath)` → `load()` → `getInfo()`:
   - источник не поддерживается (SVG и т.п., `Info::isSupported()`) или `FORMAT_GIF` (Gd теряет анимацию) → вернуть оригинал;
   - proportional/alt и целевые размеры ≥ исходных → вернуть оригинал (семантика ядра);
   - геометрия `Image\Rectangle` — по образцу `CFile::ResizeImageFile` (`main/classes/general/file.php`; EXACT = центр-кроп);
   - `CheckDirPath($dstDir)` → `$image->resize($sourceRect, $destRect)` → `$image->saveAs($dstPath, $quality, $formatConst)`
     (webp→`FORMAT_WEBP`, jpg→`FORMAT_JPEG`, png→`FORMAT_PNG`);
   - размеры ответа: `getimagesize($dstPath)`.
6. **Деградация**: webp запрошен, но GD без webp (`!function_exists('imagewebp') || !imagecreatefromwebp`) → источник webp —
   вернуть оригинал, иначе ресайз в формате источника (png сохраняет альфу). Любой Throwable → вернуть оригинал +
   один `Debug::writeToFile` (без спама).
7. Смена размера/качества/формата меняет имя папки — конфликтов нет; очистка вручную `upload/resize_cache/myapp/`.

Не трогать: `NO_PHOTO_IMAGE` (статика), файловые свойства карточки (рендерятся ссылками на оригинал), nginx, JS-компоненты (поля JSON прежние).

## Шаг 3. Интеграция в точки выдачи

| Точка | Файл:строка | Правка |
|---|---|---|
| Листинг | `CatalogService.php:662,680-690` (`getGoods`, pictureMap) | `resizeMany(IDs, SIZE_LIST)` вместо `getFilePaths` |
| Поиск/индекс-выдача | `CatalogService.php:2682` (`loadGoodsDetailsByIds`) | то же (SIZE_LIST) |
| Карточка товара | `CatalogService.php:191-195` (`loadProductBaseCached`) | `DETAIL_PICTURE` → SIZE_DETAIL; добавить `DETAIL_PICTURE_THUMB` (SIZE_THUMB_CART); суффикс `_img1` в cacheKey (:153) для сброса старых payload |
| Фото оффера (SKU) | `CatalogSkuService.php:261` | хелпер SIZE_DETAIL |
| Разделы каталога | `CatalogService.php:52` (`getSections`) | собрать ID → один `resizeMany` SIZE_SECTION (уходит N+1) |
| Корзина | `CartService.php:146-156` | `preview` = `DETAIL_PICTURE_THUMB` (fallback DETAIL_PICTURE) |
| Заказы ЛК | `PersonalOrderService.php:156-164` | то же поле |
| Checkout | `CheckoutService` | без правок (reuses `CartService::getCart`) |
| Главная: разделы | `HomeService.php:96` | SIZE_HOME_SECTION |
| Главная: слайдер/иконки/о магазине | `HomeMainContentService.php:90,135,173` | SIZE_HOME_SLIDER / SIZE_HOME_ICON / SIZE_HOME_ABOUT |

Инвалидация: тегированные кеши модуля сбрасываются при изменении элементов (`CatalogCacheHandler`); resize-кеш само-инвалидируется хешем TIMESTAMP_X.

## Шаг 4. Проверка (end-to-end)

1. Пересборка PHP (шаг 1) → `imagewebp` = true.
2. `curl 'http://shop.localhost/vue_catalog/api/v1/catalog/goods?section=37&skip_cache=1'` → `items[].DETAIL_PICTURE` = `/upload/resize_cache/myapp/...*.webp`; файл существует в контейнере; `curl -I <src>` → 200, `Content-Type: image/webp`.
3. Повторные вызовы — те же пути, файлы не перегенерируются (mtime стабилен).
4. Браузер: раздел, карточка товара (SKU-подмена оффера), корзина, `/order/`, главная — визуально + Network: webp ≪ оригинал.
5. Фолбэки: `outputType='jpg'` → кеш `.jpg`; SVG/GIF и файл ≥ целевого → оригинал; PNG с прозрачностью → webp с альфой.
6. Инвалидация: заменить картинку в админке → новый hash8-каталог, каталог-кеш сбрасывается событием.
7. Регресс: поиск, ЛК заказов, checkout-пересчёт — поля JSON прежние, вёрстка не поехала.
