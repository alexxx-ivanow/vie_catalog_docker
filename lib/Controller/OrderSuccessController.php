<?php

namespace MyApp\Catalog\Controller;

use Bitrix\Main\Context;

class OrderSuccessController extends BasePageController
{
    public function configureActions()
    {
        return [
            'index' => [
                'prefilters' => [],
            ],
        ];
    }

    public function indexAction(int $id)
    {
        if ($id <= 0) {
            \CHTTP::SetStatus('404 Not Found');
            include \Bitrix\Main\Application::getDocumentRoot() . '/404.php';
            return null;
        }

        LocalRedirect('/order/?order_id=' . $id, true, '301 Moved Permanently');
        return null;
    }
}
