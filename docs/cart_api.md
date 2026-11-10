# API корзины (myapp.catalog)

## Эндпоинты

| Метод | URL | Назначение |
|-------|-----|------------|
| `GET` | `/vue_catalog/api/v1/catalog/cart/summary` | Бейдж «Корзина (N)» в шапке: только `basketCount` / `totals.basketCount`, без позиций и без каталога |
| `GET` | `/vue_catalog/api/v1/catalog/cart` | Страница корзины: полный список позиций, цены, скидки, синхронизация с каталогом |
| `POST` | `.../catalog/cart/add` | Добавление; ответ — полная корзина (для UI и обновления счётчика) |
| `POST` | `.../catalog/cart/update` | Изменение количества; ответ — полная корзина |
| `POST` | `.../catalog/cart/remove` | Удаление позиции; ответ — полная корзина |

Оформление заказа использует `GET /checkout/init` (корзина в теле ответа), не `catalog/cart`.

## Фронт

- [`cart-counter.js`](../js/catalog/cart-counter.js) → `fetchCartCount()` вызывает **summary**.
- [`CartPage.js`](../js/catalog/components/cart/CartPage.js) → полный `GET catalog/cart`.

## Бэкенд

- Лёгкая выборка: [`CartService::getCartSummary()`](../lib/Service/CartService.php) — модуль `sale`, `loadBasket()` + `calculateTotals()`.
- Полная: [`CartService::getCart()`](../lib/Service/CartService.php) — additionally catalog sync, discounts, enrichment per line item.
