<?php

namespace MyApp\Catalog\Controller;

use Bitrix\Main\Application;
use MyApp\Catalog\Config\Templates;
use MyApp\Catalog\Service\ContentPageService;

class ContentPageController extends BasePageController
{
    public function configureActions()
    {
        return [
            'index' => [
                'prefilters' => [],
            ],
        ];
    }

    public function indexAction(string $code)
    {
        $page = ContentPageService::getActiveByCode($code);
        if ($page === null) {
            \CHTTP::SetStatus('404 Not Found');
            include Application::getDocumentRoot() . '/404.php';

            return null;
        }

        $view = is_array($page['view'] ?? null) ? $page['view'] : [];
        $title = (string)($view['title'] ?? 'Страница');
        $metaTitle = trim((string)($page['metaTitle'] ?? ''));
        if ($metaTitle !== '') {
            $title = $metaTitle;
        }

        $breadcrumbTitle = trim((string)($view['breadcrumbTitle'] ?? ''));
        if ($breadcrumbTitle === '') {
            $breadcrumbTitle = (string)($view['title'] ?? $title);
        }

        $initialState = [
            'pageTitle' => $title,
            'pageType' => 'content_page',
            'contentView' => Templates::viewPath('pages/show.php'),
            'data_sections' => [],
            'data_section' => [],
            'data_product' => [],
            'data_page' => $page,
            'contentPageTitle' => $breadcrumbTitle,
            'metaDescription' => (string)($page['metaDescription'] ?? ''),
            'jsEntry' => '',
        ];

        return $this->renderPage($initialState);
    }
}
