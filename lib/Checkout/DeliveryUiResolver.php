<?php

namespace MyApp\Catalog\Checkout;

final class DeliveryUiResolver
{
    /**
     * @param object|null $deliveryService Bitrix\Sale\Delivery\Services\Base
     */
    public static function resolve(?object $deliveryService, array $calculationData = []): array
    {
        if (!$deliveryService) {
            return self::none();
        }

        $className = get_class($deliveryService);
        $name = (string)$deliveryService->getName();
        $lower = mb_strtolower($className . ' ' . $name);

        if (str_contains($lower, 'cdek') || str_contains($lower, 'sdek') || str_contains($lower, 'сдэк')) {
            return [
                'type' => 'button',
                'component' => 'CheckoutDeliveryCdekPvz',
                'label' => 'Выбрать пункт выдачи',
                'initParams' => [
                    'deliveryId' => (int)$deliveryService->getId(),
                    'calculation' => $calculationData,
                ],
            ];
        }

        if (!empty($calculationData['EXTRA_SERVICES']) && is_array($calculationData['EXTRA_SERVICES'])) {
            return [
                'type' => 'widget',
                'component' => 'CheckoutDeliveryExtra',
                'label' => 'Дополнительные параметры доставки',
                'initParams' => [
                    'deliveryId' => (int)$deliveryService->getId(),
                    'extraServices' => $calculationData['EXTRA_SERVICES'],
                ],
            ];
        }

        return self::none();
    }

    private static function none(): array
    {
        return [
            'type' => 'none',
            'component' => null,
            'label' => '',
            'initParams' => [],
        ];
    }
}
