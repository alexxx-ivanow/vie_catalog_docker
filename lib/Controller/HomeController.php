<?php

namespace MyApp\Catalog\Controller;

use MyApp\Catalog\Config\Templates;
use MyApp\Catalog\Service\HomeMainContentService;

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
        $home = HomeMainContentService::buildHomePayload();
        $sections = is_array($home['sections'] ?? null) ? $home['sections'] : [];

        $initialState = [
            'pageTitle' => 'Интернет-магазин',
            'pageType' => 'home',
            'showBreadcrumbs' => false,
            'contentView' => Templates::viewPath('home/index.php'),
            'data_sections' => $sections,
            'data_section' => [],
            'data_product' => [],
            'data_home' => $home,
        ];

        return $this->renderPage($initialState);
    }
}
