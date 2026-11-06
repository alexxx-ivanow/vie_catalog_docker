# Поиск по каталогу (myapp.catalog)

Поисковый индекс для `/catalog/search/` и API `GET /vue_catalog/api/v1/catalog/search`. **Не смешивается** с [product filter index](product_filter_index.md): отдельные таблицы и логика.

## Движок поиска (опция модуля)

В админке: **модуль → вкладка «Поиск»** — поле **«Движок поиска»** (`catalog_search_backend`).

| Значение | Описание |
|----------|----------|
| **`mysql_lexeme_trigram`** (по умолчанию) | Словарь **лексем** + обратный индекс по **триграммам** на лексеме; подстрока внутри слова; без `LIKE '%…%'` по таблице товаров |
| **`legacy_token`** | Прежняя модель: строки `(PRODUCT_ID, TOKEN)` в `myapp_catalog_search_token`, префиксное совпадение + **fuzzy** (Levenshtein) в `CatalogSearchQueryService` |

Смена движка меняет `searchConfigHash()` → индекс считается **устаревшим** до полной переиндексации (как при смене свойств).

Реализация: [`CatalogSearchBackendFactory.php`](../lib/Search/CatalogSearchBackendFactory.php).

## Таблицы

| Таблица | Назначение |
|---------|------------|
| `myapp_catalog_search_meta` | `INDEX_VERSION`, `CONFIG_HASH`, счётчики, `LAST_FULL_SYNC_AT`, ошибки |
| `myapp_catalog_search_document` | один документ на родительский `PRODUCT_ID` (`NAME_NORM`, `BODY_NORM`, `SORT`, активность) |
| `myapp_catalog_search_lexeme` | уникальная нормализованная **лексема** (`LEXEME_TEXT`, `ID`) |
| `myapp_catalog_search_lexeme_trigram` | триграммы **лексемы** длины ≥ 3 символов: `(LEXEME_ID, TRIGRAM)`, индекс `(TRIGRAM, LEXEME_ID)` |
| `myapp_catalog_search_product_lexeme` | связь товар ↔ лексема с **весом**: `(PRODUCT_ID, LEXEME_ID)`, индекс `(LEXEME_ID, PRODUCT_ID)` |
| `myapp_catalog_search_token` | **только для `legacy_token`**: `(PRODUCT_ID, TOKEN, WEIGHT, SOURCE)` |
| `myapp_catalog_search_queue` | очередь инкрементальной переиндексации |
| `myapp_catalog_search_job` | full rebuild jobs |

Схема и миграции: [`CatalogSearchIndexSchema.php`](../lib/Install/CatalogSearchIndexSchema.php). После выката лексемных таблиц требуется **полная** переиндексация; `truncateIndexData` очищает лексемы, триграммы, связи, документ и (при truncate) токены.

Опционально на `myapp_catalog_search_document` добавляется **FULLTEXT** по `(NAME_NORM, BODY_NORM)` (если СУБД поддерживает); при ошибке DDL короткие запросы обрабатываются только через словарь лексем.

## Настройки индекса (та же вкладка)

- Коды свойств родителя / ТП для поиска.
- `index_product_preview_text`, `index_product_detail_text`.
- **NAME** родителя всегда в индексе; тексты **офферов** в индекс не попадают.
- Готовность: `catalog_search_index_ready=Y` и совпадение `CatalogSearchIndexService::searchConfigHash()` с полем `CONFIG_HASH` в meta.

## Веса (источник → целое число)

Те же уровни, что и раньше; при индексации в движок **лексем** для каждой лексемы сохраняется **максимальный** вес среди вхождений.

| Источник | WEIGHT |
|----------|--------|
| NAME | 10 |
| свойства родителя | 6 |
| NAME/свойства оффера | 5 |
| PREVIEW_TEXT | 3 |
| DETAIL_TEXT | 2 |
| эвристическая «основа» (≥6 букв, −2 символа) | 4 |

В индекс попадают **читаемые** значения свойств (подпись enum, имя элемента/раздела, текст HTML-свойств), не сырые ID.

Нормализация токенов: `[\p{L}\p{N}]+`, минимальная длина токена как в `CatalogSearchTextNormalizer`; короткие буквенно-цифровые последовательности — отдельный проход (артикулы).

## Индексация (`mysql_lexeme_trigram`)

1. Для товара собираются лексемы из полей (как выше), с дедупом по строке и **max весом**.
2. Старые строки `myapp_catalog_search_product_lexeme` для `PRODUCT_ID` удаляются.
3. Для каждой новой лексемы: `INSERT` в `myapp_catalog_search_lexeme`, если ещё нет; для лексем **длины ≥ 3** в `myapp_catalog_search_lexeme_trigram` вставляются триграммы (UTF-8, скользящее окно). Уже существующие лексемы **не** пересоздают триграммы.
4. В `myapp_catalog_search_product_lexeme` вставляется связь `PRODUCT_ID`, `LEXEME_ID`, `WEIGHT` (при конфликте — `GREATEST` веса).
5. Документ в `myapp_catalog_search_document` обновляется как раньше.

Удаление товара: бэкенд удаляет связи лексем и документ; «осиротевшие» строки в `myapp_catalog_search_lexeme` **не** чистятся автоматически (возможна отложенная уборка).

**Legacy:** при `legacy_token` заполняется только `myapp_catalog_search_token` + документ.

## Поиск (`mysql_lexeme_trigram`)

Реализация: [`MysqlLexemeTrigramSearchBackend.php`](../lib/Service/MysqlLexemeTrigramSearchBackend.php).

1. Нормализация запроса — lower, `ё→е`, стоп-слова RU, минимум 2 символа в нормализованной строке.
2. Запрос режется на **токены** (`tokenizeQuery`). Между токенами — **AND**: у товара должна быть подтверждённая лексема на **каждый** токен.
3. **Токен длины ≥ 3:** строятся триграммы запроса; для каждой триграммы считается «редкость» **`COUNT(*)`** по `myapp_catalog_search_lexeme_trigram` (индекс по `TRIGRAM`) — **rare-first**: сначала список `LEXEME_ID` по самой редкой триграмме, затем **пересечение** с остальными триграммами того же токена.
4. **Подтверждение подстроки:** для оставшихся кандидатов-лексем выполняется `LOCATE(нормализованный_токен, LEXEME_TEXT)` только в `myapp_catalog_search_lexeme` — **не** по таблице товаров.
5. **Токен короче 3 символов:** отдельный путь — точное совпадение или **префикс** по `LEXEME_TEXT` в словаре (без строк в таблице триграмм для такой лексемы при индексации).
6. По множествам `LEXEME_ID` на токен собираются `PRODUCT_ID` (через `myapp_catalog_search_product_lexeme` + активный документ), затем **пересечение** по токенам запроса.
7. **Ранжирование:** сумма **максимальных** весов по токенам + бонус за префикс/границу слова в `NAME_NORM` (аналог прежнего `NAME_PREFIX_BONUS`). **Порог по сумме:** `WEIGHT_DETAIL × число_токенов` (минимальный «реалистичный» вклад — совпадения только из детального текста, вес 2 на токен); совпадения из анонса (3) и сильнее проходят.
8. Пагинация — после фильтра по порогу и сортировки по score на стороне PHP (для типичных объёмов выдачи).

`searchMode` в ответе API: **`strict`** при успешном отборе; **`empty`** при коротком/пустом запросе; отсутствие совпадений по токену — режим в духе «строгого нуля» (без fuzzy из legacy).

## Поиск (`legacy_token`)

[`CatalogSearchQueryService`](../lib/Service/CatalogSearchQueryService.php): strict (целый токен / префикс `TOKEN LIKE 'q%'`) и при нуле в strict — **fuzzy** (Levenshtein) для длинных токенов. Подсказки при `total === 0` — как раньше.

## Подсказки (suggest)

[`CatalogSearchSuggestService`](../lib/Service/CatalogSearchSuggestService.php) использует **`suggestCandidateLexemes`** активного бэкенда: для лексемного движка — популярные лексемы по префиксу из словаря; для legacy — прежняя выборка по токенам.

## Jobs и инкремент

- `CatalogSearchIndexJobService` — TYPE_FULL, агент ~90 с, AJAX [`ajax_search_jobs.php`](../admin/ajax_search_jobs.php).
- CLI: `php local/modules/myapp.catalog/tools/catalog_search_index_worker.php --batch=20`.
- При `CatalogService::invalidateByElement(..., true)` — очередь `CatalogSearchIndexQueueService`.
- Удаление — `CatalogSearchIndexService::removeProduct` → бэкенд `removeProductIndex`.

Фасад поиска: [`CatalogSearchService::search()`](../lib/Service/CatalogSearchService.php).

## QA (чеклист)

1. Смена свойств или **движка** в options → hash ≠ meta → индекс **не готов** до full rebuild.
2. Full rebuild → прогресс 100%, `index_ready`, поиск открывается.
3. **Лексемный движок:** подстрока **внутри** русского слова (не только префикс); многословный запрос — **AND** по токенам.
4. Короткий токен (1–2 символа после нормализации) — осмысленное поведение (префикс/совпадение в словаре), без сканирования `b_iblock_element`.
5. Пагинация: вторая страница совпадает с ожиданием при сортировке по score.
6. Удаление товара — нет в выдаче; инкремент после правки — обновлённый текст ищется.
7. Переключение `legacy_token` ↔ `mysql_lexeme_trigram` после full rebuild: ожидаемое различие (fuzzy только в legacy).
8. Модалка в шапке → `/catalog/search/?q=`.
