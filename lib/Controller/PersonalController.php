<?php

namespace MyApp\Catalog\Controller;

use MyApp\Catalog\Config\Templates;
use MyApp\Catalog\Service\AccountService;

class PersonalController extends BasePageController
{
    public function configureActions()
    {
        return [
            'profile' => ['prefilters' => []],
            'orders' => ['prefilters' => []],
            'order' => ['prefilters' => []],
        ];
    }

    public function profileAction()
    {
        if ($this->redirectGuestIfNeeded()) {
            return null;
        }

        return $this->renderPage([
            'pageTitle' => 'Профиль',
            'pageType' => 'personal_profile',
            'sectionId' => 0,
            'contentView' => Templates::viewPath('personal/profile.php'),
            'data_sections' => [],
            'data_section' => [],
            'data_product' => [],
            'data_order' => [],
            'jsEntry' => 'app-personal-profile.js',
        ]);
    }

    public function ordersAction()
    {
        if ($this->redirectGuestIfNeeded()) {
            return null;
        }

        return $this->renderPage([
            'pageTitle' => 'Мои заказы',
            'pageType' => 'personal_orders',
            'sectionId' => 0,
            'contentView' => Templates::viewPath('personal/orders.php'),
            'data_sections' => [],
            'data_section' => [],
            'data_product' => [],
            'data_order' => [],
            'jsEntry' => 'app-personal-orders.js',
        ]);
    }

    public function orderAction(int $id)
    {
        if ($this->redirectGuestIfNeeded()) {
            return null;
        }

        if ($id <= 0) {
            \CHTTP::SetStatus('404 Not Found');
            include \Bitrix\Main\Application::getDocumentRoot() . '/404.php';
            return null;
        }

        return $this->renderPage([
            'pageTitle' => 'Заказ №' . $id,
            'pageType' => 'personal_order',
            'sectionId' => 0,
            'contentView' => Templates::viewPath('personal/order.php'),
            'data_sections' => [],
            'data_section' => [],
            'data_product' => [],
            'data_order' => ['id' => $id],
            'jsEntry' => 'app-personal-order.js',
        ]);
    }

    private function redirectGuestIfNeeded(): bool
    {
        if (AccountService::requireAuthorizedUserId() > 0) {
            return false;
        }

        LocalRedirect('/?auth=1', true);
        return true;
    }
}
