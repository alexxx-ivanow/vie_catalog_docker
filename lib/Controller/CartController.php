<?php

namespace MyApp\Catalog\Controller;

use MyApp\Catalog\Config\Templates;

class CartController extends BasePageController
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
            'pageTitle'   => 'Корзина',
            'pageType'    => 'cart',
            'sectionId'   => 0,
            'contentView' => Templates::viewPath('cart/index.php'),
            'data_sections' => [],
            'data_section'  => [],
            'data_product'  => [],
            'jsEntry'     => 'app-cart.js',
        ];

        return $this->renderPage($initialState);
    }
}
