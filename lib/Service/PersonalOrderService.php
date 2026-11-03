<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Loader;
use Bitrix\Sale\Internals\OrderTable;
use Bitrix\Sale\Internals\StatusLangTable;
use Bitrix\Sale\Order;
use MyApp\Catalog\Config\Assets;

final class PersonalOrderService
{
    public static function listOrders(int $userId, int $page, int $limit): array
    {
        self::ensureModules();
        if ($userId <= 0) {
            return [
                'items' => [],
                'pagination' => self::emptyPagination($page, $limit),
            ];
        }

        $filter = ['=USER_ID' => $userId];
        $total = (int)OrderTable::getCount($filter);
        $pages = $limit > 0 ? (int)max(1, ceil($total / $limit)) : 1;
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $limit;

        $statusNames = self::loadStatusNames();
        $items = [];

        $rows = OrderTable::getList([
            'select' => ['ID', 'ACCOUNT_NUMBER', 'DATE_INSERT', 'STATUS_ID', 'PRICE', 'CURRENCY', 'PAYED'],
            'filter' => $filter,
            'order' => ['ID' => 'DESC'],
            'limit' => $limit,
            'offset' => $offset,
        ]);

        while ($row = $rows->fetch()) {
            $statusId = (string)($row['STATUS_ID'] ?? '');
            $dateFormatted = self::formatDate($row['DATE_INSERT']);
            $isPaid = ($row['PAYED'] ?? 'N') === 'Y';

            $items[] = [
                'id' => (int)$row['ID'],
                'accountNumber' => (string)($row['ACCOUNT_NUMBER'] ?? $row['ID']),
                'dateFormatted' => $dateFormatted,
                'statusId' => $statusId,
                'statusName' => $statusNames[$statusId] ?? $statusId,
                'price' => (float)($row['PRICE'] ?? 0),
                'currency' => (string)($row['CURRENCY'] ?? ''),
                'isPaid' => $isPaid,
                'paidLabel' => $isPaid ? 'Оплачено' : 'Не оплачено',
                'url' => '/personal/orders/' . (int)$row['ID'] . '/',
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pages' => $pages,
                'limit' => $limit,
                'total' => $total,
            ],
        ];
    }

    public static function getOrderDetail(int $orderId, int $userId): ?array
    {
        self::ensureModules();
        if ($orderId <= 0) {
            return null;
        }

        $order = Order::load($orderId);
        if (!$order) {
            return null;
        }

        if (!CheckoutService::canViewOrder($order)) {
            return null;
        }

        if ($userId > 0 && (int)$order->getUserId() !== $userId) {
            return null;
        }

        $statusId = (string)$order->getField('STATUS_ID');
        $statusNames = self::loadStatusNames();

        $deliveryName = '';
        $shipmentCollection = $order->getShipmentCollection();
        foreach ($shipmentCollection as $shipment) {
            if ($shipment->isSystem()) {
                continue;
            }
            $deliveryName = (string)$shipment->getDeliveryName();
            if ($deliveryName === '' && $shipment->getDeliveryId() > 0) {
                $deliveryName = 'Доставка #' . (int)$shipment->getDeliveryId();
            }
            break;
        }

        $paymentName = '';
        foreach ($order->getPaymentCollection() as $payment) {
            $paymentName = (string)$payment->getPaymentSystemName();
            if ($paymentName === '') {
                $paymentName = 'Оплата #' . (int)$payment->getPaymentSystemId();
            }
            break;
        }

        $properties = [];
        foreach ($order->getPropertyCollection() as $property) {
            $value = $property->getValue();
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $name = (string)$property->getField('NAME');
            $code = (string)$property->getField('CODE');
            $properties[] = [
                'code' => $code,
                'name' => $name !== '' ? $name : $code,
                'value' => $value,
            ];
        }

        $items = [];
        $productIds = [];
        foreach ($order->getBasket() as $basketItem) {
            $productIds[] = (int)$basketItem->getProductId();
        }
        $products = [];
        foreach (array_unique($productIds) as $productId) {
            $product = CatalogService::getProduct($productId);
            if ($product) {
                $products[$productId] = $product;
            }
        }

        foreach ($order->getBasket() as $basketItem) {
            $productId = (int)$basketItem->getProductId();
            $product = $products[$productId] ?? null;
            $quantity = (float)$basketItem->getQuantity();
            $price = (float)$basketItem->getPrice();
            $sum = round($price * $quantity, 2);
            $name = $product['NAME'] ?? (string)$basketItem->getField('NAME');
            $preview = trim((string)($product['DETAIL_PICTURE'] ?? ''));
            if ($preview === '') {
                $preview = Assets::NO_PHOTO_IMAGE;
            }

            $items[] = [
                'productId' => $productId,
                'name' => $name,
                'preview' => $preview,
                'quantity' => $quantity,
                'price' => $price,
                'sum' => $sum,
                'url' => '/catalog/element/' . $productId,
            ];
        }

        $isPaid = $order->isPaid();
        $dateInsert = $order->getField('DATE_INSERT');

        return [
            'id' => (int)$order->getId(),
            'accountNumber' => (string)$order->getField('ACCOUNT_NUMBER'),
            'dateFormatted' => self::formatDate($dateInsert),
            'statusId' => $statusId,
            'statusName' => $statusNames[$statusId] ?? $statusId,
            'price' => (float)$order->getPrice(),
            'currency' => (string)$order->getCurrency(),
            'isPaid' => $isPaid,
            'paidLabel' => $isPaid ? 'Оплачено' : 'Не оплачено',
            'deliveryName' => $deliveryName,
            'paymentName' => $paymentName,
            'properties' => $properties,
            'items' => $items,
        ];
    }

    private static function ensureModules(): void
    {
        Loader::includeModule('sale');
        Loader::includeModule('catalog');
        Loader::includeModule('iblock');
    }

    /**
     * @return array<string, string>
     */
    private static function loadStatusNames(): array
    {
        $lang = defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru';
        $result = [];
        $rows = StatusLangTable::getList([
            'select' => ['STATUS_ID', 'NAME'],
            'filter' => ['=LID' => $lang],
        ]);
        while ($row = $rows->fetch()) {
            $result[(string)$row['STATUS_ID']] = (string)$row['NAME'];
        }

        return $result;
    }

    private static function formatDate(mixed $dateInsert): string
    {
        if ($dateInsert instanceof \Bitrix\Main\Type\DateTime) {
            return $dateInsert->format('d.m.Y H:i');
        }
        if (is_string($dateInsert) && $dateInsert !== '') {
            return $dateInsert;
        }

        return '';
    }

    private static function emptyPagination(int $page, int $limit): array
    {
        return [
            'page' => $page,
            'pages' => 1,
            'limit' => $limit,
            'total' => 0,
        ];
    }
}
