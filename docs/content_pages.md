# Контентные страницы (`/pages/{code}`)

## Настройка

1. При установке модуля создаётся тип инфоблока `myapp_catalog_pages` и ИБ **«Служебные страницы»** (`myapp_site_pages`).
2. ID инфоблока — вкладка **Контентные страницы** в настройках модуля (`pages_iblock_id`).
3. **Маппинг полей** (`pages_view_field_map`, JSON) задаёт, какие поля/свойства попадают во view.

## Элемент инфоблока

- **Символьный код** (`CODE`) — часть URL: `/pages/{code}/` (роут без завершающего слэша тоже работает).
- **NAME** — заголовок H1 (по умолчанию).
- **DETAIL_TEXT** — основной HTML-контент.
- Свойства: `META_TITLE`, `META_DESCRIPTION`, `BREADCRUMB_TITLE`.

Зарезервированные коды (404): `catalog`, `cart`, `order`, `auth`, `personal`, `pages`, `vue_catalog`.

## Кеш

Выборка по `CODE` кешируется в `/myapp_catalog/content_page/` с TTL `catalog_cache_ttl`. Сброс при изменении элементов ИБ страниц.
