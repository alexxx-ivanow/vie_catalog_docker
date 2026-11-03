<?php

namespace MyApp\Catalog\Controller;

use Bitrix\Main\Context;
use MyApp\Catalog\Config\Templates;
use MyApp\Catalog\Service\CheckoutService;

class OrderController extends BasePageController
{
    public function configureActions()
    {
        return [
            'index' => [
                'prefilters' => [],
            ],
        ];
    }

    public function indexAction()
    {
        $request = Context::getCurrent()->getRequest();
        $orderId = (int)$request->get('order_id');

        if ($orderId > 0) {
            $orderInfo = CheckoutService::getOrderSuccessInfo($orderId);
            if (!$orderInfo) {
                \CHTTP::SetStatus('404 Not Found');
                include \Bitrix\Main\Application::getDocumentRoot() . '/404.php';
                return null;
            }

            return $this->renderPage([
                'pageTitle' => 'Заказ оформлен',
                'pageType' => 'order_success',
                'sectionId' => 0,
                'contentView' => Templates::viewPath('order/index.php'),
                'data_sections' => [],
                'data_section' => [],
                'data_product' => [],
                'data_order' => $orderInfo,
                'jsEntry' => 'app-order-success.js',
            ]);
        }

        return $this->renderPage([
            'pageTitle' => 'Оформление заказа',
            'pageType' => 'checkout',
            'sectionId' => 0,
            'contentView' => Templates::viewPath('order/index.php'),
            'data_sections' => [],
            'data_section' => [],
            'data_product' => [],
            'jsEntry' => 'app-checkout.js',
        ]);
    }
}
