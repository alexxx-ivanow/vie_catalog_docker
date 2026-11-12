# Bitmap Index фильтра каталога (myapp.catalog)

Побитовый индекс для быстрого резолва ID товаров в `GET /catalog/goods`. Vue и публичный API не меняются.

См. также [product_filter_index.md](product_filter_index.md) (денормализованный индекс) и снапшоты фильтра (`/catalog/filters`).

## Движки

Единый переключатель на вкладке **«Индекс фильтрации»** → опция `catalog_filter_engine` (активен ровно один):

| Значение | Поведение |
|----------|-----------|
| `legacy` | SQL по `b_iblock_*` |
| `denormalized` | `ProductFilterQueryService` / таблицы `myapp_catalog_filter_*` |
| `bitmap` | `BitmapFilterService` / таблицы `myapp_catalog_bitmap_*` |

Инкрементальная инвалидация (очередь / remove) выполняется **только для активного движка** (либо пока идёт job построения этого индекса). Неактивный индекс не обновляется при правках товаров.

Фабрика: `CatalogFilterEngineFactory`. Совместимость: пустой `catalog_filter_engine` + старый `use_product_filter_index=Y` → `denormalized`.

Параметр `?article=` при активном bitmap → fallback на `legacy`. Колонки `ARTICLE` в bitmap нет.

## Таблицы

- `myapp_catalog_bitmap_products` — PRODUCT_ID ↔ BITMAP_POSITION, ACTIVE, PRICE, SORT
- `myapp_catalog_bitmap_index` — PROPERTY_ID + VALUE_ID → MEDIUMBLOB маска
- `myapp_catalog_bitmap_value_dict` — VALUE_ID для string/number
- `myapp_catalog_bitmap_membership` — текущие ключи товара для инкрементального clear/set
- `myapp_catalog_bitmap_status` — version, hash, counts, status
- `myapp_catalog_bitmap_queue` / `myapp_catalog_bitmap_job` — очередь и jobs

Разделы: **`PROPERTY_ID = 0` — не ID свойства инфоблока**, а зарезервированный sentinel (`BitmapStorage::SECTION_PROPERTY_ID`); `VALUE_ID = SECTION_ID`. Реальные свойства фильтра имеют `PROPERTY_ID > 0` (например MATERIAL=11).

### Замеры

Как у denormalized/legacy:

```
GET /vue_catalog/api/v1/catalog/goods?section=32&perf=1&skip_cache=1
```

В ответе `_perf`: `meta.filter_engine=bitmap`, `meta.count_mode=bitmap_index`, `bitmap_path=true`, стадии `bitmap_section_or`, `bitmap_props_and_or`, `bitmap_iterate_bits`, при большой выборке — `bitmap_positions_temp`, затем `bitmap_goods_count`, `bitmap_goods_list`, плюс `elapsed_ms` / `sql_time_ms`.

## Логика фильтра

1. OR масок выбранных значений внутри свойства; AND между свойствами.
2. OR масок разделов поддерева; AND с результатом свойств.
3. Цена / сортировка / пагинация — SQL по `bitmap_products`.

**Сопоставление позиций с товарами** (`BitmapFilterService::querySortedPage`):

| `bitmap_popcount` | `bitmap_id_resolve` | Поведение |
|-------------------|---------------------|-----------|
| ≤ 512 | `position_in` | `BITMAP_POSITION IN (...)` |
| > 512 | `position_temp` | `CREATE TEMPORARY TABLE` (MEMORY) + пакетный `INSERT` + `INNER JOIN` на count и list; в perf — `bitmap_positions_temp` |
| без маски | `full_scan_active` | все активные товары индекса |

Порог: `POSITION_IN_THRESHOLD = 512` в коде. Раньше при >5000 позициях шли через `product_id_in`; для больших выборок всегда temp table (один проход по JOIN, без гигантского `IN` и без лишнего маппинга в PHP).

Типы: enum / string (точное) / number (точное); multiple — бит в каждой маске значения.

## Операции

- Построение: вкладка «Bitmap Index» или `tools/bitmap_index_worker.php --batch=20`
- Сравнение: `tools/compare_filter_query.php --section=ID --engine=bitmap`
- Бенч: `tools/bench_filter_engines.php --section=ID`
- Целостность / repair: кнопки в админке → `BitmapIndexValidator`

## Roaring

Не подключён. Baseline — plain binary. Переоценка по `blob_bytes` / memory на 100k+ после замеров `bench_filter_engines.php`.
