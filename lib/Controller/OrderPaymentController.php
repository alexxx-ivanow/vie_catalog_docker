<?php

namespace MyApp\Catalog\Controller;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\HttpResponse;
use Bitrix\Sale\Order;
use MyApp\Catalog\Service\CheckoutService;

class OrderPaymentController extends Controller
{
    public function configureActions()
    {
        return [
            'pay' => ['prefilters' => []],
            'legacy' => ['prefilters' => []],
        ];
    }

    public function payAction(int $id)
    {
        return $this->processPayment($id);
    }

    public function legacyAction()
    {
        $request = Context::getCurrent()->getRequest();
        $orderId = (int)$request->get('ORDER_ID');
        if ($orderId <= 0) {
            $this->notFound();

            return null;
        }

        LocalRedirect(CheckoutService::paymentUrlForOrder($orderId), true, '301 Moved Permanently');

        return null;
    }

    private function processPayment(int $orderId)
    {
        if ($orderId <= 0) {
            $this->notFound();

            return null;
        }

        CheckoutService::ensureModules();

        $order = Order::load($orderId);
        if (!$order || !CheckoutService::canViewOrder($order)) {
            $this->notFound();

            return null;
        }

        if ($order->isPaid()) {
            LocalRedirect('/order/?order_id=' . $orderId, true);

            return null;
        }

        $init = CheckoutService::initiateUnpaidOnlinePayment($order);
        $redirectUrl = (string)($init['redirectUrl'] ?? '');
        if ($redirectUrl !== '') {
            LocalRedirect($redirectUrl, true);

            return null;
        }

        $template = (string)($init['template'] ?? '');
        if ($template !== '') {
            $html = '<!DOCTYPE html>'
                . '<html lang="ru"><head>'
                . '<meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>Оплата заказа</title>'
                . '</head><body>'
                . $template
                . '</body></html>';

            $response = new HttpResponse();
            $response->addHeader('Content-Type', 'text/html; charset=UTF-8');

            return $response->setContent($html);
        }

        LocalRedirect('/order/?order_id=' . $orderId . '&payment_error=1', true);

        return null;
    }

    private function notFound(): void
    {
        \CHTTP::SetStatus('404 Not Found');
        include \Bitrix\Main\Application::getDocumentRoot() . '/404.php';
    }
}
