<?php

namespace MyApp\Catalog\Controller;

use MyApp\Catalog\Config\Templates;
use MyApp\Catalog\Service\HomeService;

class HomeController extends BasePageController
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
        $initialState = [
            'pageTitle' => 'Интернет-магазин',
            'pageType' => 'home',
            'showBreadcrumbs' => false,
            'contentView' => Templates::viewPath('home/index.php'),
            'data_sections' => HomeService::getRootSections(),
            'data_section' => [],
            'data_product' => [],
            'jsEntry' => 'app-home.js',
        ];

        return $this->renderPage($initialState);
    }
}
