<?php

namespace MyApp\Catalog\Controller;

use Bitrix\Main\Application;
use MyApp\Catalog\Config\Templates;
use MyApp\Catalog\Service\CatalogService;

class ProductController extends BasePageController
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
        $product = CatalogService::getProduct($id);
        if (!$product) {
            \CHTTP::SetStatus('404 Not Found');
            include Application::getDocumentRoot() . '/404.php';
            return null;
        }

        $initialState = [
            'pageTitle'   => 'Товар - ' . $product['NAME'],
            'pageType' => 'product',
            'sectionId' => (int)($product['SECTION_ID'] ?? 0),
            'contentView' => Templates::viewPath('catalog/product.php'),
            'data_sections'    => [],
            'data_section'    => [],
            'data_product'    => [
                'ID' => (int)$product['ID'],
            ],
            'jsEntry' => 'app-product.js',
        ];

        return $this->renderPage($initialState);
    }
}
