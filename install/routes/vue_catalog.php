<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Routing\RoutingConfigurator;
use MyApp\Catalog\Routing\WebRoutes;

return static function (RoutingConfigurator $routes) {
    if (!Loader::includeModule('myapp.catalog')) {
        $include = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/myapp.catalog/include.php';
        if (is_file($include)) {
            require_once $include;
        }
    }

    WebRoutes::configure($routes);
};
