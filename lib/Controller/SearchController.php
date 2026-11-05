<?php

namespace MyApp\Catalog\Controller;

use Bitrix\Main\Context;
use MyApp\Catalog\Config\Templates;
use MyApp\Catalog\Service\CatalogSearchService;
use MyApp\Catalog\Service\CatalogService;

class SearchController extends BasePageController
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
        $query = trim((string)$request->get('q'));
        $page = max(1, (int)$request->get('page'));
        $limit = CatalogService::normalizeGoodsLimit($request->get('limit'));
        $sort = CatalogService::normalizeGoodsSort($request->get('sort'));

        $searchResult = CatalogSearchService::search($query, $page, $limit, $sort);

        $initialState = [
            'pageTitle' => $query !== '' ? 'Поиск: ' . $query : 'Поиск по каталогу',
            'pageType' => 'search',
            'sectionId' => 0,
            'showBreadcrumbs' => true,
            'contentView' => Templates::viewPath('catalog/search.php'),
            'data_sections' => [],
            'data_section' => [],
            'data_product' => [],
            'data_search' => $searchResult,
            'jsEntry' => 'app-search.js',
        ];

        return $this->renderPage($initialState);
    }
}
