<?php

namespace MyApp\Catalog\Controller;

use Bitrix\Main\Application;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Page\Asset;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Config\Templates;

abstract class BasePageController extends Controller
{
    protected function renderPage(array $initialState): HttpResponse
    {
        $useBitrixVue = Assets::prepareVueForPage();

        $initialState['jsBase'] = Assets::JS_CATALOG_BASE;
        $initialState['apiBase'] = Assets::API_V1_BASE . '/';
        $initialState['vueModuleUrl'] = Assets::vueModuleUrl();
        $initialState['useBitrixVue'] = $useBitrixVue;
        $initialState['bitrixAssetJs'] = '';
        $initialState['bitrixAssetStrings'] = '';

        if ($useBitrixVue) {
            // Layout без ShowHead — скрипты ui.vue3 нужно вставить вручную.
            $asset = Asset::getInstance();
            $asset->addString(
                '<script>BX.message(' . \CUtil::PhpToJSObject([
                    'SERVER_TIME' => time(),
                    'SERVER_TZ_OFFSET' => (int)date('Z'),
                ]) . ');</script>'
            );
            $initialState['bitrixAssetStrings'] = (string)$asset->getStrings();
            $initialState['bitrixAssetJs'] = (string)$asset->getJs();
        }

        // Не используем Engine renderView: при withSiteTemplate=false ядро всё равно
        // вызывает ShowAjaxHead() и может подмешать Bitrix JS/CSS в ответ.
        $viewFile = Application::getDocumentRoot() . Templates::viewPath('layouts/main.php');
        if (!is_file($viewFile)) {
            throw new \RuntimeException('Layout not found: ' . $viewFile);
        }

        extract($initialState, EXTR_SKIP);

        ob_start();
        include $viewFile;
        $html = (string)ob_get_clean();

        $response = new HttpResponse();
        return $response->appendContent($html);
    }
}
