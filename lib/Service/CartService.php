<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use MyApp\Catalog\Config\Assets;
use Bitrix\Sale\Basket;
use Bitrix\Sale\BasketBase;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Discount;
use Bitrix\Sale\DiscountCouponsManager;
use Bitrix\Sale\Fuser;

class CartService
{
    public static function addProduct(int $productId, int $quantity = 1, int $offerId = 0): array
    {
        Loader::includeModule('sale');
        Loader::includeModule('catalog');

        $catalogProductId = $offerId > 0 ? $offerId : $productId;
        if ($catalogProductId <= 0) {
            return ['error' => 'Некорректный товар'];
        }

        if ($offerId <= 0 && $productId > 0 && CatalogSkuService::isSkuProduct($productId)) {
            return ['error' => 'Выберите вариант товара', 'success' => false];
        }

        if ($offerId > 0) {
            $parentFromOffer = CatalogSkuService::resolveParentIdFromOffer($offerId);
            if ($productId > 0 && $parentFromOffer > 0 && $parentFromOffer !== $productId) {
                return ['error' => 'Вариант не относится к этому товару', 'success' => false];
            }
            if ($parentFromOffer <= 0) {
                return ['error' => 'Торговое предложение не найдено', 'success' => false];
            }
            $productId = $parentFromOffer;
        } elseif ($productId > 0) {
            $catalogProductId = $productId;
        }

        $context = Context::getCurrent();
        $siteId  = $context->getSite();

        $fuserId = Fuser::getId();
        $basket  = Basket::loadItemsForFUser($fuserId, $siteId);

        $item = $basket->getExistsItem('catalog', $catalogProductId);

        if ($item) {
            $setResult = $item->setField('QUANTITY', $item->getQuantity() + $quantity);
            if (!$setResult->isSuccess()) {
                return [
                    'error' => implode('; ', $setResult->getErrorMessages()),
                ];
            }
        } else {
            $item = $basket->createItem('catalog', $catalogProductId);
            $setResult = $item->setFields([
                'QUANTITY' => $quantity,
                'CURRENCY' => CatalogService::catalogDisplayCurrency(),
                'LID' => $siteId,
                'PRODUCT_PROVIDER_CLASS' => 'CCatalogProductProvider',
            ]);
            if (!$setResult->isSuccess()) {
                return [
                    'error' => implode('; ', $setResult->getErrorMessages()),
                ];
            }
        }

        self::alignBasketItemWithCatalog($item, CatalogService::getProductForCatalogItem($catalogProductId));

        $res = $basket->save();

        if (!$res->isSuccess()) {
            return [
                'error' => implode('; ', $res->getErrorMessages()),
            ];
        }

        $cart = self::getCart();
        $cart['basketCount'] = $cart['totals']['basketCount'];
        $cart['basketSum'] = $cart['totals']['total'];

        return $cart;
    }

    public static function getCart(): array
    {
        Loader::includeModule('sale');
        Loader::includeModule('catalog');

        $basket = self::loadBasket();
        self::syncBasketPricesFromCatalog($basket);
        self::applyDiscounts($basket);

        $displayCurrency = CatalogService::catalogDisplayCurrency();
        $items = [];
        $productIds = [];

        foreach ($basket as $basketItem) {
            if (!self::isBuyableItem($basketItem)) {
                continue;
            }
            $productIds[] = (int)$basketItem->getProductId();
        }

        $products = [];
        foreach (array_unique($productIds) as $productId) {
            $product = CatalogService::getProductForCatalogItem($productId);
            if ($product) {
                $products[$productId] = $product;
            }
        }

        foreach ($basket as $basketItem) {
            if (!self::isBuyableItem($basketItem)) {
                continue;
            }

            $productId = (int)$basketItem->getProductId();
            $product = $products[$productId] ?? null;
            $quantity = (float)$basketItem->getQuantity();
            $parentId = (int)($product['ID'] ?? $productId);
            $detailUid = (int)($product['OFFER_ID'] ?? $productId);
            $url = '/catalog/element/' . $parentId;
            if ($detailUid > 0 && $detailUid !== $parentId) {
                $url .= '?uid=' . $detailUid;
            }

            $price = (float)$basketItem->getPrice();
            $basePrice = (float)$basketItem->getBasePrice();
            if ($basePrice <= 0) {
                $basePrice = $price;
            }

            $discountPerUnit = max(0, round($basePrice - $price, 2));
            $discountSum = round($discountPerUnit * $quantity, 2);
            $sum = round($price * $quantity, 2);
            $sumBase = round($basePrice * $quantity, 2);

            $name = $product['NAME'] ?? (string)$basketItem->getField('NAME');
            $preview = trim((string)($product['DETAIL_PICTURE'] ?? ''));
            if ($preview === '') {
                $preview = Assets::NO_PHOTO_IMAGE;
            }
            $article = $product['ARTICLE'] ?? '';

            $items[] = [
                'id' => (int)$basketItem->getId(),
                'productId' => $productId,
                'name' => $name,
                'preview' => $preview,
                'url' => $url,
                'article' => $article,
                'basePrice' => $basePrice,
                'price' => $price,
                'discount' => $discountPerUnit,
                'discountSum' => $discountSum,
                'quantity' => $quantity,
                'sum' => $sum,
                'sumBase' => $sumBase,
                'currency' => (string)($product['CURRENCY'] ?? $displayCurrency),
            ];
        }

        return [
            'success' => true,
            'items' => $items,
            'totals' => array_merge(
                self::calculateTotals($basket),
                ['currency' => $displayCurrency]
            ),
        ];
    }

    public static function updateQuantity(int $basketItemId, int $quantity): array
    {
        Loader::includeModule('sale');
        Loader::includeModule('catalog');

        if ($quantity < 1) {
            return self::removeItem($basketItemId);
        }

        $basket = self::loadBasket();
        $item = self::findBasketItem($basket, $basketItemId);

        if (!$item) {
            return [
                'success' => false,
                'error' => 'Товар в корзине не найден',
                'items' => [],
                'totals' => self::emptyTotals(),
            ];
        }

        $setResult = $item->setField('QUANTITY', $quantity);
        if (!$setResult->isSuccess()) {
            return [
                'success' => false,
                'error' => implode('; ', $setResult->getErrorMessages()),
                'items' => [],
                'totals' => self::emptyTotals(),
            ];
        }

        $res = $basket->save();

        if (!$res->isSuccess()) {
            return [
                'success' => false,
                'error' => implode('; ', $res->getErrorMessages()),
                'items' => [],
                'totals' => self::emptyTotals(),
            ];
        }

        return self::getCart();
    }

    public static function removeItem(int $basketItemId): array
    {
        Loader::includeModule('sale');
        Loader::includeModule('catalog');

        $basket = self::loadBasket();
        $item = self::findBasketItem($basket, $basketItemId);

        if (!$item) {
            return [
                'success' => false,
                'error' => 'Товар в корзине не найден',
                'items' => [],
                'totals' => self::emptyTotals(),
            ];
        }

        $deleteResult = $item->delete();
        if (!$deleteResult->isSuccess()) {
            return [
                'success' => false,
                'error' => implode('; ', $deleteResult->getErrorMessages()),
                'items' => [],
                'totals' => self::emptyTotals(),
            ];
        }

        $res = $basket->save();

        if (!$res->isSuccess()) {
            return [
                'success' => false,
                'error' => implode('; ', $res->getErrorMessages()),
                'items' => [],
                'totals' => self::emptyTotals(),
            ];
        }

        return self::getCart();
    }

    private static function syncBasketPricesFromCatalog(BasketBase $basket): void
    {
        if ($basket->isEmpty()) {
            return;
        }

        $productIds = [];
        foreach ($basket as $basketItem) {
            if (!self::isBuyableItem($basketItem)) {
                continue;
            }
            $productIds[] = (int)$basketItem->getProductId();
        }

        if (empty($productIds)) {
            return;
        }

        $products = [];
        foreach (array_unique($productIds) as $productId) {
            $product = CatalogService::getProductForCatalogItem($productId);
            if ($product) {
                $products[$productId] = $product;
            }
        }

        $changed = false;
        foreach ($basket as $basketItem) {
            if (!self::isBuyableItem($basketItem)) {
                continue;
            }
            $productId = (int)$basketItem->getProductId();
            if (self::alignBasketItemWithCatalog($basketItem, $products[$productId] ?? null)) {
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        $basket->save();
    }

    /**
     * @param array<string, mixed>|null $product
     */
    private static function alignBasketItemWithCatalog(BasketItem $item, ?array $product): bool
    {
        if ($product === null) {
            return false;
        }

        $catalogPrice = (float)($product['PRICE'] ?? 0);
        if ($catalogPrice <= 0) {
            return false;
        }

        $currency = trim((string)($product['CURRENCY'] ?? ''));
        if ($currency === '') {
            $currency = CatalogService::catalogDisplayCurrency();
        }

        $currentPrice = (float)$item->getPrice();
        $currentBasePrice = (float)$item->getBasePrice();
        $currentCurrency = strtoupper(trim((string)$item->getField('CURRENCY')));
        $currency = strtoupper($currency);

        $priceMatches = abs($currentPrice - $catalogPrice) < 0.009
            && abs($currentBasePrice - $catalogPrice) < 0.009;
        if ($priceMatches && $currentCurrency === $currency && $item->getField('CUSTOM_PRICE') === 'Y') {
            return false;
        }

        $item->setField('CUSTOM_PRICE', 'Y');
        $item->setField('CURRENCY', $currency);
        $item->setField('BASE_PRICE', $catalogPrice);
        $item->setField('PRICE', $catalogPrice);
        $item->setField('DISCOUNT_PRICE', 0);

        return true;
    }

    private static function loadBasket(): BasketBase
    {
        $siteId = Context::getCurrent()->getSite();
        $fuserId = Fuser::getId();

        return Basket::loadItemsForFUser($fuserId, $siteId);
    }

    private static function findBasketItem(BasketBase $basket, int $basketItemId): ?BasketItem
    {
        if ($basketItemId <= 0) {
            return null;
        }

        $item = $basket->getItemById($basketItemId);
        if ($item instanceof BasketItem) {
            return $item;
        }

        foreach ($basket as $basketItem) {
            if ((int)$basketItem->getId() === $basketItemId) {
                return $basketItem;
            }
        }

        return null;
    }

    private static function isBuyableItem(BasketItem $basketItem): bool
    {
        return $basketItem->getField('DELAY') !== 'Y' && $basketItem->getField('CAN_BUY') !== 'N';
    }

    private static function applyDiscounts(BasketBase $basket): void
    {
        if ($basket->isEmpty()) {
            return;
        }

        try {
            DiscountCouponsManager::init();

            $context = new Discount\Context\Fuser(Fuser::getId());
            $discounts = Discount::buildFromBasket($basket, $context);

            if (!$discounts) {
                return;
            }

            $result = $discounts->calculate();
            if (!$result->isSuccess()) {
                return;
            }

            $showPrices = $discounts->getShowPrices();
            if (empty($showPrices['BASKET']) || !is_array($showPrices['BASKET'])) {
                return;
            }

            foreach ($showPrices['BASKET'] as $basketCode => $priceData) {
                if (!is_array($priceData)) {
                    continue;
                }

                $basketItem = $basket->getItemByBasketCode($basketCode);
                if (!$basketItem) {
                    continue;
                }

                if (isset($priceData['SHOW_BASE_PRICE'])) {
                    $basketItem->setFieldNoDemand('BASE_PRICE', $priceData['SHOW_BASE_PRICE']);
                }
                if (isset($priceData['SHOW_PRICE'])) {
                    $basketItem->setFieldNoDemand('PRICE', $priceData['SHOW_PRICE']);
                }
                if (isset($priceData['SHOW_DISCOUNT'])) {
                    $basketItem->setFieldNoDemand('DISCOUNT_PRICE', $priceData['SHOW_DISCOUNT']);
                }
            }
        } catch (\Throwable $e) {
            // Не блокируем выдачу корзины, если движок скидок недоступен.
        }
    }

    private static function calculateTotals(BasketBase $basket): array
    {
        $itemsSum = 0.0;
        $total = 0.0;
        $basketCount = 0.0;

        foreach ($basket as $basketItem) {
            if (!self::isBuyableItem($basketItem)) {
                continue;
            }

            $quantity = (float)$basketItem->getQuantity();
            $basePrice = (float)$basketItem->getBasePrice();
            if ($basePrice <= 0) {
                $basePrice = (float)$basketItem->getPrice();
            }

            $itemsSum += $basePrice * $quantity;
            $total += (float)$basketItem->getPrice() * $quantity;
            $basketCount += $quantity;
        }

        $itemsSum = round($itemsSum, 2);
        $total = round($total, 2);
        $discount = round(max(0, $itemsSum - $total), 2);

        return [
            'itemsSum' => $itemsSum,
            'discount' => $discount,
            'total' => $total,
            'basketCount' => $basketCount,
        ];
    }

    private static function emptyTotals(): array
    {
        return [
            'itemsSum' => 0,
            'discount' => 0,
            'total' => 0,
            'basketCount' => 0,
        ];
    }
}
