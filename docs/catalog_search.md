# Поиск по каталогу (myapp.catalog)

Отдельный **токенный индекс** для `/catalog/search/` и API `GET /vue_catalog/api/v1/catalog/search`. Не смешивается с [product filter index](product_filter_index.md).

## Таблицы

| Таблица | Назначение |
|---------|------------|
| `myapp_catalog_search_meta` | версия, `CONFIG_HASH`, счётчики, `LAST_FULL_SYNC_AT` |
| `myapp_catalog_search_document` | один документ на родительский `PRODUCT_ID` (`NAME_NORM`, `BODY_NORM`, `SORT`) |
| `myapp_catalog_search_token` | `(PRODUCT_ID, TOKEN)` + `WEIGHT`, `SOURCE` |
| `myapp_catalog_search_queue` | очередь инкрементальной переиндексации |
| `myapp_catalog_search_job` | full rebuild jobs |

Схема: [`CatalogSearchIndexSchema.php`](../lib/Install/CatalogSearchIndexSchema.php).

## Настройки (админка → модуль → вкладка «Поиск»)

- Свойства товара / ТП (коды).
- `index_product_preview_text`, `index_product_detail_text`.
- **NAME** родителя всегда в индексе; тексты **офферов** не индексируются.
- Готовность: опция `catalog_search_index_ready=Y` и совпадение `searchConfigHash()` с meta.

## Веса токенов

| Источник | WEIGHT |
|----------|--------|
| NAME | 10 |
| свойства родителя | 6 |
| NAME/свойства оффера | 5 |
| PREVIEW_TEXT | 3 |
| DETAIL_TEXT | 2 |
| эвристическая «основа» (≥6 букв, −2 символа) | 4 |

В индекс попадают **читаемые** значения свойств (подпись enum, имя элемента/раздела, текст HTML-свойств), не ID из `b_iblock_element_property`.

Токены: `[\p{L}\p{N}]+`, min 3; alphanumeric длиной ≥2 — отдельный pass (артикулы).

## Поиск (runtime)

1. **Нормализация** — lower, `ё→е`, стоп-слова RU, min запрос 2 символа.
2. **Фаза A (strict)** — AND по токенам, `TOKEN = t OR TOKEN LIKE t%`, score = SUM(WEIGHT) + бонус +5 за prefix в `NAME_NORM`, порог **5** / 10 (один токен — достаточно веса свойства ТП).
3. **Фаза B (fuzzy)** — только если strict дал **0** результатов и есть токены ≥4 символов (не чисто цифровые): Levenshtein ≤1 с prefilter по первым 2 символам; ответ `searchMode: fuzzy`. Точные strict-совпадения fuzzy не заменяет.
4. **Подсказки** — при `total === 0` на странице поиска (`suggestions` в JSON); для числовых токенов опечатки не подставляются.

Сервисы: `CatalogSearchQueryService`, `CatalogSearchSuggestService`, фасад `CatalogSearchService::search()`.

## Jobs

- `CatalogSearchIndexJobService` — TYPE_FULL, agent ~90 с, админ AJAX [`ajax_search_jobs.php`](../admin/ajax_search_jobs.php).
- CLI: `php local/modules/myapp.catalog/tools/catalog_search_index_worker.php --batch=20`.

## Инкремент

При `CatalogService::invalidateByElement(..., true)` — `CatalogSearchIndexQueueService::enqueue`. Удаление товара — `CatalogSearchIndexService::removeProduct`.

## QA (кратко)

1. Смена свойств в options → hash меняется → `index_ready=N` до full rebuild.
2. Full rebuild → progress 100%, поиск работает.
3. NAME (регистр, ё, prefix), свойства родителя/оффера, preview/detail родителя.
4. Модалка в шапке → `/catalog/search/?q=`.
5. Опечатка → fuzzy или блок «Возможно, вы имели в виду».
