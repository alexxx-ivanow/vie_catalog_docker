# Аудит модуля myapp.catalog: безопасность API и производительность фильтра/поиска

Дата: 2026-11-08 · Объём: `lib/Controller`, `lib/Service` (поиск, фильтр, корзина, checkout, аккаунт), `admin/*.php`, `install/templates/main/views`, схемы БД (`lib/Install`, `install/db/mysql`), `tools`.

Методика: ручной разбор кода — все входы API (`$_GET`/JSON body/роут-параметры) прослежены до SQL и вывода; SQL-построение проверено на экранирование; подсистемы фильтра и поиска сверены с их документацией (`product_filter_index.md`, `catalog_search.md`), чтобы отделить уже известные решения от новых находок.

---

## Резюме

| # | Область | Находка | Критичность |
|---|---------|---------|-------------|
| S1 | Безопасность | Отражённая XSS через JSON-встраивание пользовательского `q` в `<script>` страницы поиска | **Высокая** |
| S2 | Безопасность | Гостевой checkout привязывает корзину (fuser) к существующему пользователю по e-mail без проверки пароля | Средняя |
| S3 | Безопасность | `errorResponse` отдаёт наружу текст исключения (`$e->getMessage()`) во всех API-контроллерах | Средняя |
| S4 | Безопасность | `checkout/calculate` — тяжёлый расчёт скидок без CSRF-токена | Средняя |
| S5 | Безопасность | Нет троттлинга/captcha на login/register/forgot-password | Средняя |
| S6 | Безопасность | LIKE-маски `%`/`_` не экранируются в фильтре по артикулу и legacy-поиске | Низкая |
| S7 | Безопасность | Поиск без ограничения на число/длину токенов → DoS-вектор на SQL | Низкая/Средняя |
| S8 | Безопасность | Legacy-работа с checkword (сравнение plain/md5), поиск пользователя по значению CHECKWORD, отладочный лог паролей (`TEMP_PASSWORD_RESET_DEBUG`) | Низкая |
| P1 | Производительность | Индексация: построчные INSERT без батчей и транзакций, повторные запросы свойств на каждый товар | Высокий эффект |
| P2 | Производительность | Поиск (lexeme_trigram): по-триграммные COUNT-запросы и коррелированные подзапросы MAX(WEIGHT) | Высокий эффект |
| P3 | Производительность | count+list — двойной проход по одним JOIN (window function) | Средний эффект |
| P4 | Производительность | Отсутствует индекс `(PROPERTY_ID, VALUE_STR, PRODUCT_ID)`; сортировка по name через join `b_iblock_element` | Средний эффект |
| P5 | Производительность | Очередь job: 2 ORM-операции прогресса + построчное удаление на каждый товар | Низкий/средний эффект |

SQL-инъекций в пользовательских входах **не обнаружено**: все параметры приводятся к int/float либо проходят `SqlHelper::forSql()`; sort/limit/page нормализуются белыми списками и clamp'ами.

---

# Часть 1. Безопасность API-запросов

## 1.1. Что уже сделано правильно

- **CSRF**: все мутирующие эндпоинты (`cart/add|update|remove`, `checkout/submit`, все `account/*` POST) проверяют sessid (`hash_equals(bitrix_sessid())` + fallback `check_bitrix_sessid()`) — `ApiController::isValidSessid()` (lib/Controller/ApiController.php:268), аналогично в `CheckoutApiController`, `AccountApiController`.
- **Админка**: `admin/ajax_filter_jobs.php`, `admin/ajax_search_jobs.php` — `IsAdmin()` + `POST` + `check_bitrix_sessid()` + белый список `action`/`type`, clamp `batch`. `options.php` — проверка `$USER->IsAdmin()`.
- **SQL**: везде `(int)`-каст, `array_map('intval')`, `SqlHelper::forSql()` (например, `ProductFilterQueryService::buildFilterBaseSql` — lib/Service/ProductFilterQueryService.php:150-197; legacy-путь `CatalogService::getGoods` — lib/Service/CatalogService.php:425-492). Сортировка — whitelist (`normalizeGoodsSort`, lib/Service/CatalogService.php:729), limit — clamp 1..48 (`normalizeGoodsLimit`), page — clamp к числу страниц в обоих путях.
- **AuthZ заказов**: `AccountApiController::order()` требует авторизации и передаёт `$userId`; `PersonalOrderService::getOrderDetail()` (lib/Service/PersonalOrderService.php:72-90) проверяет владельца; `CheckoutService::canViewOrder()` — тройная проверка (сессионная метка после submit / совпадение пользователя / совпадение fuser).
- **Корзина**: все операции — через `Basket::loadItemsForFUser()` текущего fuser, `getItemById` ищет только внутри своей корзины (lib/Service/CartService.php:349-375) — IDOR по basketItemId отсутствует.
- **Вывод**: `<title>` экранирован (`htmlspecialcharsbx`, layouts/main.php:35), сообщения auth-страниц экранированы (AuthPageController:126), приватные ответы помечены `Cache-Control: no-store` (AccountApiController:221-225), читающие GET-эндпоинты закрывают session lock (`releaseSessionLock`).
- **Пароли**: смена в профиле требует текущий пароль (`AccountService::updateProfile`, :150-161); register/forgot идут через ядро (`CUser::Register/SendPassword/ChangePassword`), сравнение checkword — `hash_equals`/`Password::equals`.

## 1.2. Находки

### S1. Отражённая XSS на страницах, где JSON встраивается в `<script>` — Высокая

`install/templates/main/views/layouts/main.php:109-143` — все `__PAGE_DATA__` поля выводятся через `json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`. Комбинация флагов **не экранирует `<` и `/`**, поэтому значение, содержащее `</script>`, физически закрывает тег script в HTML.

Достижимый источник: страница `/catalog/search/?q=...` — `SearchController::indexAction()` (lib/Controller/SearchController.php:21-44) кладёт **сырой** (trim, без нормализации) запрос в `data_search['query']` (`CatalogSearchService::search()`, lib/Service/CatalogSearchService.php:83 — `'query' => $query`), а layout выводит его в `search: <?= json_encode($data_search, ...) ?>`.

Вектор: `GET /catalog/search/?q=</script><script>alert(document.cookie)</script>` → исполняемый JS в контексте origin (кража sessid/сессии).

Аналогичные поля-источники в том же выводе: `content_title` (breadcrumbs), `data_order`, `authChangePassword` — закрывать нужно системно, в одном месте.

**Исправление** (одно место — layout): добавить флаги `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` ко всем `json_encode` в `layouts/main.php`, либо глобальный локальный хелпер. Проверить ещё `install/templates/main/views/auth/change_password.php` и `confirm.php` на локальные `json_encode`.

### S2. Гостевой checkout привязывает fuser к существующему аккаунту без проверки пароля — Средняя

`CheckoutService::submit()` (lib/Service/CheckoutService.php:170-188): для гостя `CheckoutUserService::resolveUserIdForOrder()` находит пользователя **по e-mail** (`findUserIdByEmail`, lib/Service/CheckoutUserService.php:182-191) и возвращает его ID без какого-либо подтверждения владения ящиком; далее `bindFuserToUser($orderUserId)` (lib/Service/CheckoutService.php:1497-1509) **постоянно** привязывает fuser текущей гостевой сессии к чужому аккаунту (`CSaleUser::Update(..., ['USER_ID' => $userId])`).

Последствия:
- заказ оформляется от имени другого пользователя (появляется в его ЛК, лояльность/скидки/история);
- поведение ответа различается (auto-login только для нового пользователя — CheckoutService.php:244-249) → **перечисление зарегистрированных e-mail** через checkout.

Auto-login `$USER->Authorize()` выполняется только для **нового** пользователя — это корректно. Проблема — ветка «существующий e-mail».

**Исправление**: для существующего e-mail — требовать пароль, либо создавать заказ под гостевым пользователем/не подтверждённым аккаунтом с письмом «подтвердите, что это вы», либо слать ссылку-подтверждение вместо немедленной привязки.

### S3. Наружу отдаётся текст исключений — Средняя

`ApiController::errorResponse()` (lib/Controller/ApiController.php:287-294) — комментарий сам признаёт проблему, но закомментированный «безопасный» вариант: наружу уходит `$e->getMessage()` (пути, фрагменты SQL, ошибки БД). То же в `CheckoutApiController:114-117` и `AccountApiController:159,178` (обёрнуто в 500). Для гостевого трафика это information disclosure и подсказки для эксплуатации.

**Исправление**: клиенту — общий текст + код ошибки; детали — в `\Bitrix\Main\Diag\Debug::writeToFile`/журнал. Оставить детальные сообщения только для ожидаемых бизнес-ошибок (валидация), где текст формируется кодом, а не исключением.

### S4. `checkout/calculate` без sessid — Средняя

`CheckoutApiController::calculate()` (lib/Controller/CheckoutApiController.php:39-50) принимает произвольный body и запускает `CheckoutService::calculate()` → `CartService::getCart()` → полный расчёт корзины + движок скидок Sale + расчёт доставок. Это самый тяжёлый публичный эндпоинт, при этом единственный мутирующий (по сути) без CSRF. Анонимный цикл запросов — нагрузка на CPU/БД.

**Исправление**: добавить sessid (как у `submit`); для GET-эффектов сессии — использовать существующий механизм `X-Bitrix-Csrf-Token`.

### S5. Нет троттлинга на аутентификационных эндпоинтах — Средняя

`account/login`, `account/register`, `account/forgot-password`, `auth/change_password` не имеют ограничений частоты на уровне модуля. Подбор пароля частично покрывает встроенная защита Битрикса (если включена), но register (массовое создание аккаунтов) и forgot (mail-bombing на произвольный e-mail) — нет.

**Исправление**: минимально — счётчик в кешe по IP+логину (например, `Bitrix\Main\Data\Cache` или `MaxMind`-подобный лимитер) с блоком на N минут; опционально captcha на register/forgot.

### S6. LIKE-маски не экранируются — Низкая

Артикул: `LIKE '%{$escaped}%'` (lib/Service/ProductFilterQueryService.php:157 и lib/Service/CatalogService.php:431); legacy-поиск/подсказки: `TOKEN LIKE '{$prefix}%'` (CatalogSearchQueryService.php:253, MysqlLexemeTrigramSearchBackend.php:343). `forSql()` не трогает `%` и `_` → пользователь может подставить маски (`article=%`) и получить полное сканирование/нерелевантный отбор. Инъекцией это не является.

**Исправление**: локальный хелпер `escapeLike($s) = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $s)` перед `forSql`.

### S7. Поиск без ограничения числа токенов — Низкая/Средняя

`CatalogSearchTextNormalizer::tokenizeQuery` не ограничивает количество токенов (длина токена обрезана до 64, lib/Service/CatalogSearchTextNormalizer.php:88-95). В `MysqlLexemeTrigramSearchBackend::searchProductIds` **каждый** токен порождает: per-триграммные `COUNT(*)`, выборки пересечений (lib/Service/MysqlLexemeTrigramSearchBackend.php:409-455), подзапрос `IN (SELECT ...)` + коррелированный `MAX(WEIGHT)` + `LOCATE`-условие в финальном SQL (строки 127-176). Запрос `q` с 300 токенов → сотни SQL-запросов и SQL-текст на сотни КБ. В legacy-бэкенде то же: per-token запросы + `PRODUCT_ID IN (все найденные id)` (CatalogSearchQueryService.php:303-335).

**Исправление**: cap — не более 6-8 токенов (лишние отбрасывать), плюс ограничить длину `q` (например, 200 символов) на контроллере. Это же снижает SQL-нагрузку легитимных «длинных» запросов.

### S8. Гигиена работы с checkword — Низкая

- `AccountService::checkwordCompareDetails()` (lib/Service/AccountService.php:727-746) принимает **plain- и md5-сравнение** checkword из БД (legacy-миграция). Если в БД остались старые записи — модель слабее, чем у ядра.
- `fetchUserRowFromDbByCheckword()` (lib/Service/AccountService.php:654-668) ищет пользователя прямым сравнением `CHECKWORD = '...'` в SQL (экранирование есть, инъекции нет; константное время не соблюдается — минор).
- `logPasswordResetDebug()` (lib/Service/AccountService.php:762-789) пишет checkword, его md5 и префикс хеша в файл `logs/password-reset-debug.log`. Включатель `TEMP_PASSWORD_RESET_DEBUG = false` — сейчас выключен; файл с такими данными в каталоге модуля лучше удалить вместе с методом после окончания диагностики.
- Checkword передаётся в URL (`/auth/change_password/{login}/{checkword}`) — попадает в access-логи/Referer. Стандартный паттерн Битрикса, но стоит убедиться, что admin-логи не отдаются публично, и что `checkword` имеет ограниченный срок жизни (это делает ядро `CUser::ChangePassword`).

## 1.3. Прочее зафиксированное

- `cartAdd` возвращает `'data' => $body` (lib/Controller/ApiController.php:162) — эхо запроса наружу; лучше убрать.
- `sendForgotPassword` пробрасывает наружу сообщения ядра `SendPassword` (lib/Service/AccountService.php:314-320) — может раскрывать существование/несуществование аккаунта; в ответе лучше всегда общий текст «если аккаунт существует, письмо отправлено».
- HTML-поля контента выводятся без экранирования по дизайну (admin-контент): `views/pages/show.php:14` (`$body`), `views/home/index.php:147,178` (`about.body`, `seo.body`). Риск — только при недоверенном контент-редакторе; при необходимости — санитайзер на этапе сохранения контента.
- Платёжный шаблон (`OrderPaymentController::processPayment`, :72-87) вставляется в страницу как есть — источник — обработчик платёжной системы Битрикса (доверенный), изменений не требует.

---

# Часть 2. Производительность сервисов фильтра и поиска

## 2.1. Что уже реализовано хорошо

- Кеш total count на index path (`ProductFilterQueryService::queryProductIds`, :62-94) и кеш total поиска с epoch-инвалидацией (`CatalogSearchTotalCacheService` + `CatalogSearchService::search`, :49-59) — пагинация не пересчитывает COUNT.
- Rare-first перебор триграмм с подтверждением `LOCATE` только по словарю лексем, а не по таблице товаров (`MysqlLexemeTrigramSearchBackend`).
- Тегированный кеш списков/фильтров/карточек (`commitTaggedDataCache`, теги раздела/элемента/инфоблока) + точечная инвалидация по событиям (`CatalogCacheHandler`): события фильтруются по инфоблоку и свойствам (`touchesFilterProperties`), цены не трогают снапшоты целиком.
- Lazy-очередь job с порционным `fillQueueFromCursor` (до 2000 за шаг) и батчевым `enqueueManyBulk` (`INSERT IGNORE` чанками по 500).
- `releaseSessionLock()` на читающих эндпоинтах — параллельные fetch не блокируются PHP-сессией.
- Кеш карточек `loadProductBaseCached`, батч-загрузка картинок/цен списком, perf-режим (`?perf=1`) для замеров.

## 2.2. Находки по подсистемам

### A. Выдача товаров (index path, `/catalog/goods`)

| # | Что | Где | Эффект |
|---|-----|-----|--------|
| A1 | count и list — два полных прохода с одним `buildFilterBaseSql` (`COUNT(DISTINCT)` + `SELECT DISTINCT ... LIMIT`) | ProductFilterQueryService.php:56-115 | На cache miss count ≈ 50% SQL-времени (задокументировано в product_filter_index.md §9). Один SQL с `COUNT(*) OVER() AS total_cnt` (MySQL 8) убирает второй проход; для MySQL 5.7 — считать count в кеш только для page 1 и переиспользовать при листании (частично уже сделано). |
| A2 | Нет индекса `(PROPERTY_ID, VALUE_STR, PRODUCT_ID)` на `myapp_catalog_filter_value` — строковые свойства и поиск по артикулу через `LIKE '%…%'` идут сканом | lib/Install/ProductFilterIndexSchema.php:71-82 (создание таблицы) | Добавить индекс + экранирование масок (S6). Для артикула лучше точное совпадение/префикс вместо подстроки. |
| A3 | Сортировка `name` — INNER JOIN `b_iblock_element e_sort` на каждый запрос списка | ProductFilterQueryService.php:248 | Денормализовать `NAME` (или `NAME_NORM`) в `filter_product` и убрать join; индекс `(ACTIVE, IBLOCK_ID, SORT)` / `(ACTIVE, IBLOCK_ID, PRICE)` под сортировки по умолчанию/цене. |
| A4 | `queryProductIds` на каждый вызов делает `ProductFilterIndexSchema::ensureInfrastructure()` (Option-проверки) | ProductFilterQueryService.php:24; то же в `rebuildProduct`, `removeProduct`, queue/job-сервисах | Дешёвая, но повторяющаяся работа: один `static $checked` флаг в процессе (по образцу `filterPropertyDefinitions()`, CatalogService.php:2114) снимает всю накладную на горячем пути. |
| A5 | `getFilters()` при stale-снапшоте возвращает устаревший payload и планирует rebuild — ок, но при `skip_cache` каждый запрос делает `read()` + 2 Option-проверки (`ensureRebuildInfrastructure`) | CatalogService.php:1394-1460, FilterAggregateService.php:15-35 | Микро: static-флаг после первой проверки инфраструктуры в рамках процесса. |

### B. Снапшоты фильтра (`/catalog/filters`)

| # | Что | Где | Эффект |
|---|-----|-----|--------|
| B1 | `buildFiltersPayload` — отдельный тяжёлый `SELECT DISTINCT` по scope на каждое свойство (для SKU-свойств — ещё и с двойным JOIN через офферы) | CatalogService.php:1168-1241 | Когда product filter index готов, снапшоты можно собирать из `myapp_catalog_filter_value` (уже денормализовано: 1 запрос с `GROUP BY PROPERTY_ID, VALUE_ENUM`) вместо JOIN по `b_iblock_element_property`. Первичная сборка ускоряется в разы. |
| B2 | `markStaleForSectionPath` + `enqueue` на каждый раздел пути — построчные `UPDATE`/`INSERT` ORM | FilterAggregateService.php:77-103 | При массовом импорте (тысячи событий элементов) — заменить на `UPDATE ... WHERE SECTION_ID IN (...)` и батч-очередь. |

### C. Индексация фильтра (product filter index)

| # | Что | Где | Эффект |
|---|-----|-----|--------|
| C1 | `rebuildProduct` — ~15+ запросов на товар, все построчные: `removeProduct` (3 DELETE + SELECT + DELETE ORM), SELECT элемента, ORM `add()` на каждую секцию (`FilterIndexProductSectionTable::add` в цикле), ORM `add()` на каждое значение свойства | ProductFilterIndexService.php:187-245, 376-395, 610-697 | Батч: multi-row `INSERT` значений/секций + транзакция на товар (атомарность заодно). Ожидаемо 3-5× ускорение full build. |
| C2 | `indexMergedOfferPropertyValues` — запрос `PropertyTable::getList` **на каждый код и на каждый товар** | ProductFilterIndexService.php:716-729 | Кэш в `static`/per-job map: карта «код → ID/тип» свойств офферного инфоблока не меняется в рамках job. |
| C3 | `resolveIndexedPrice` — SELECT цен + `CCurrencyRates::ConvertCurrency` **на каждый товар** (и на каждую строку цены) | ProductFilterIndexService.php:319-374, CatalogService.php:2088-2107 | Для SKU — один запрос `SELECT MIN(PRICE) ...` по офферам; курсы загрузить один раз в map на job (`CCurrencyRates` всё равно ходит в БД при каждом вызове при смене дня). |
| C4 | `incrementJobProgress` — `getByPrimary` + `update` (2 ORM-операции) **на каждый товар** | ProductFilterIndexJobService.php:460-471 | Одно `UPDATE ... SET PROCESSED = PROCESSED + 1, ERROR_COUNT = ERROR_COUNT + ?` (SqlHelper::incrementCounter). |
| C5 | Очередь: `dequeueBatch` SELECT + `remove` по одному ID | ProductFilterIndexQueueService.php:85-108 | `DELETE ... WHERE PRODUCT_ID IN (...)` одним запросом после выборки. |
| C6 | Нет транзакции вокруг `rebuildProduct` — сбой посередине оставляет половину строк | ProductFilterIndexService.php:187-245 | `$connection->startTransaction()` / `commit` на товар; при исключении — rollback + LAST_ERROR. |

### D. Поиск — обработка запроса

| # | Что | Где | Эффект |
|---|-----|-----|--------|
| D1 | `resolveLexemesByTrigrams`: `COUNT(*)` отдельным запросом на каждую триграмму токена + пересечение чанками по 400 в PHP (много round-trip) | MysqlLexemeTrigramSearchBackend.php:409-455 | Один запрос `SELECT TRIGRAM, COUNT(*) FROM ... WHERE TRIGRAM IN (...) GROUP BY TRIGRAM`; пересечение — одним SQL (`JOIN` по LEXEME_ID) или `GROUP BY LEXEME_ID HAVING COUNT(DISTINCT TRIGRAM) = N`. |
| D2 | Финальный SQL — 3 уровня вложенных derived-таблиц с коррелированными `MAX(WEIGHT)` на каждый токен; ранжирование файлсортом всего множества совпадений | MysqlLexemeTrigramSearchBackend.php:124-231 | Вариант: одна материализация `JOIN product_lexeme GROUP BY PRODUCT_ID` с `MAX(CASE WHEN LEXEME_ID IN (token_i) THEN WEIGHT END)`; threshold через `HAVING`. Либо двухфазно: IDs по пересечению, взвешенный score только для страницы 1 + кеш. |
| D3 | `COUNT(*)` поверх `rankedSubquery` повторяет весь пайплайн (задокументировано) | MysqlLexemeTrigramSearchBackend.php:199-206 | Закрывается кешем total (уже есть) + P3 (window function): считать `COUNT(*) OVER()` в list-запросе первой страницы. |
| D4 | Бонус за NAME считается в SQL (CASE по LOCATE) и **повторно** в PHP для scores | MysqlLexemeTrigramSearchBackend.php:164-169, 239-253 | Мелочь: дублирование логики, оставить один источник. |
| D5 | Legacy fuzzy — до 5000 кандидатов и `levenshtein()` в PHP на каждый токен; `scoreProducts` — `PRODUCT_ID IN (все совпавшие id)` | CatalogSearchQueryService.php:267-296, 303-335 | Ограничить размер intersection (например, top 1000 по префикс-весу) до score-фазы; чанковать `IN`. Закрывается капом токенов (S7). |
| D6 | Suggest — `GROUP BY` + `COUNT(DISTINCT)` по префиксу на каждый запрос при total=0 | MysqlLexemeTrigramSearchBackend.php:350-359 | Кеш по нормализованному префиксу (TTL 60-300 c) — запросы «почти совпадают» между пользователями. |

### E. Поиск — индексация

| # | Что | Где | Эффект |
|---|-----|-----|--------|
| E1 | `indexProductLexemes`: на каждую лексему SELECT существования + возможный INSERT + **INSERT IGNORE на каждую триграмму построчно** + INSERT связи | MysqlLexemeTrigramSearchBackend.php:38-86 | Батч: получить существующие лексемы одним `IN (...)`, недостающие — multi-row INSERT; триграммы всех новых лексем — один-два multi-row `INSERT IGNORE`; всё в транзакции. Ожидаемо 5-10× ускорение full rebuild. |
| E2 | Словарь лексем — SELECT per lexeme без in-process кеша | там же :61-67 | Static map «текст → ID» на время job. |
| E3 | Legacy-бэкенд: `SearchIndexTokenTable::add` построчно | LegacyTokenCatalogSearchBackend.php:29-43 | То же — multi-row INSERT (PK `(PRODUCT_ID, TOKEN)` защищает от дублей). |

### F. Инфраструктура (очереди, кеш, агенты)

| # | Что | Где | Эффект |
|---|-----|-----|--------|
| F1 | Агент ~90 c + AJAX-шаги + CLI-воркер — на больших каталогах агент отстаёт | tools/*.php, ProductFilterIndexJobService | На проде запускать CLI-воркеры cron'ом/супервизором (воркеры уже умеют `--batch`), агент оставить как fallback. |
| F2 | `CartService::getCart` — `getProductForCatalogItem` вызывается дважды для каждого товара (sync-проход и проход сборки items) | CartService.php:266-308, 104-117 | Собрать `$products` один раз до обоих проходов; `resolveParentIdFromOffer` на каждый товар — закешировать в static map. |
| F3 | `PersonalOrderService::getOrderDetail` — `CatalogService::getProduct` в цикле по позициям заказа (N+1) | PersonalOrderService.php:138-147 | Батч по уникальным `productId` (как в `getCart`). |
| F4 | `FilterAggregateSchema::ensure*` на каждый read/write — 3-5 Option::get | FilterAggregateSchema.php:44-99 | Static-флаг процесса (см. A4). |

## 2.3. Приоритеты

**Quick wins (1-2 дня, низкий риск):**
1. S1 — флаги `JSON_HEX_*` в `layouts/main.php` (устраняет XSS).
2. S3 — общий текст ошибки вместо `$e->getMessage()`.
3. S4 — sessid на `checkout/calculate`.
4. S7 + S6 — кап токенов/длины `q`, экранирование LIKE-масок.
5. A2 — индекс `(PROPERTY_ID, VALUE_STR, PRODUCT_ID)`.
6. C2/C4/C5, E2 — static-кеш карты свойств, инкремент одним UPDATE, батч-удаление очереди.

**Средний срок:**
7. C1/C3/E1/E3 — батч-INSERT и транзакции в индексации фильтра и поиска (самый большой выигрыш на full rebuild).
8. D1 — слияние per-триграммных запросов в GROUP BY-запросы.
9. A1/D3 — window function для count (если MySQL ≥ 8).
10. B1 — сборка снапшотов из `myapp_catalog_filter_value` при готовом индексе.

**Стратегические:**
11. S2 — пересмотр гостевого checkout (подтверждение владения e-mail).
12. S5 — общий троттлинг-слой для аутентификационных эндпоинтов.
13. A3 — денормализация NAME в `filter_product` под сортировку.

---

# Приложение: чек-лист проверки после внедрения

Безопасность:
1. `GET /catalog/search/?q=</script><b>x</b>` — в HTML нет `</script>` из пользовательского ввода (проверить `<title>` и `__PAGE_DATA__`).
2. 500-ответ любого API не содержит текста исключения (создать искусственную ошибку в тестовой БД).
3. `POST /vue_catalog/api/v1/checkout/calculate` без sessid → 400.
4. Гостевой заказ с e-mail существующего пользователя — не привязывает fuser к чужому аккаунту.
5. 20 rapid-запросов `account/register` с одного IP — срабатывает лимитер.
6. `article=%` и `article=a_b` — фильтр ведёт себя как литеральный поиск.

Производительность (замерять с `?perf=1&skip_cache=1`):
7. Full build фильтр-индекса на текущем каталоге — время до/после батчинга (ориентир ×3-5).
8. Full build поискового индекса — время до/после (ориентир ×5-10 на лексемном движке).
9. `/catalog/goods` с фильтрами: `index_goods_count` исчезает из slowest при MySQL ≥ 8 (window function) или падает по count-кешу.
10. Поиск: запрос из 10+ слов не порождает десятков SQL (кап токенов), `slowest` стабилен.
