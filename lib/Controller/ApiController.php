<?php

namespace MyApp\Catalog\Controller;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\Response\Json;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Service\CartService;
use MyApp\Catalog\Service\CatalogService;

class ApiController
{
    private function request()
    {
        return Context::getCurrent()->getRequest();
    }

    /**
     * Сессия Bitrix держит блокировку до конца запроса, из-за чего параллельные
     * fetch одной страницы выполняются по очереди. Читающим эндпоинтам сессия не нужна.
     */
    private function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function sections(): Json
    {
        $this->releaseSessionLock();

        try {
            return new Json(CatalogService::getSections());
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function filters(): Json
    {
        $this->releaseSessionLock();
        $sectionId = $this->request()->get('section') ? (int)$this->request()->get('section') : null;

        try {
            return new Json(CatalogService::getFilters((int)$sectionId));
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function goods(): Json
    {
        $this->releaseSessionLock();
        $request = $this->request();
        $showFilter = Assets::showCatalogFilter();
        $properties = $showFilter
            ? CatalogService::parseRequestProperties($request->get('props'))
            : [];

        $filter = [
            'article' => (string)($request->get('article') ?? ''),
            'price_from' => $showFilter && $request->get('price_from') !== null && $request->get('price_from') !== ''
                ? (float)$request->get('price_from')
                : null,
            'price_to' => $showFilter && $request->get('price_to') !== null && $request->get('price_to') !== ''
                ? (float)$request->get('price_to')
                : null,
            'properties' => $properties,
            'sort' => CatalogService::normalizeGoodsSort($request->get('sort')),
        ];

        $page  = max(1, (int)$request->get('page') ?: 1);
        $limit = CatalogService::normalizeGoodsLimit($request->get('limit'));
        $sectionId = max(0, (int)$request->get('section'));

        try {
            return new Json(CatalogService::getGoods($filter, $page, $limit, $sectionId));
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function good(int $id): Json
    {
        $this->releaseSessionLock();

        try {
            $uid = $this->request()->get('uid');
            $uidInt = $uid !== null && $uid !== '' ? (int)$uid : null;
            $product = CatalogService::getProduct($id, $uidInt);
            if (!$product) {
                return $this->jsonResponse(['error' => 'Товар не найден'], 404);
            }
            return $this->jsonResponse($product);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function breadcrumbs(): Json
    {
        $this->releaseSessionLock();
        $request = $this->request();
        $pageType = (string)($request->get('pageType') ?? 'catalog');
        $sectionId = max(0, (int)$request->get('section'));
        $productId = max(0, (int)$request->get('product'));

        try {
            $items = CatalogService::getBreadcrumbs($pageType, $sectionId, $productId);
            return $this->jsonResponse(['items' => $items]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function cart(): Json
    {
        try {
            return $this->jsonResponse(CartService::getCart());
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function cartAdd(): Json
    {
        $body = $this->jsonBody();

        if (!$this->isValidSessid($body['sessid'] ?? null)) {
            return $this->jsonResponse([
                'error' => 'Invalid sessid',
                'success' => false,
            ], 400);
        }

        $productId = (int)($body['productId'] ?? 0);
        $offerId = (int)($body['offerId'] ?? 0);
        $quantity  = max(1, (int)($body['quantity'] ?: 1));

        if ($offerId <= 0 && $productId <= 0) {
            return $this->jsonResponse([
                'error' => 'Некорректный productId или offerId',
                'data' => $body,
            ], 400);
        }

        try {
            $result = CartService::addProduct($productId, $quantity, $offerId);
            $status = !empty($result['error']) ? 400 : 200;
            return $this->jsonResponse($result, $status);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function cartUpdate(): Json
    {
        $body = $this->jsonBody();

        if (!$this->isValidSessid($body['sessid'] ?? null)) {
            return $this->jsonResponse([
                'error' => 'Invalid sessid',
                'success' => false,
            ], 400);
        }

        $basketItemId = (int)($body['basketItemId'] ?? $body['id'] ?? 0);
        $quantity = (int)($body['quantity'] ?? 0);

        if ($basketItemId <= 0) {
            return $this->jsonResponse([
                'error' => 'Некорректный basketItemId',
                'success' => false,
            ], 400);
        }

        try {
            $result = CartService::updateQuantity($basketItemId, $quantity);
            $status = empty($result['success']) ? 400 : 200;
            return $this->jsonResponse($result, $status);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function cartRemove(): Json
    {
        $body = $this->jsonBody();

        if (!$this->isValidSessid($body['sessid'] ?? null)) {
            return $this->jsonResponse([
                'error' => 'Invalid sessid',
                'success' => false,
            ], 400);
        }

        $basketItemId = (int)($body['basketItemId'] ?? $body['id'] ?? 0);

        if ($basketItemId <= 0) {
            return $this->jsonResponse([
                'error' => 'Некорректный basketItemId',
                'success' => false,
            ], 400);
        }

        try {
            $result = CartService::removeItem($basketItemId);
            $status = empty($result['success']) ? 400 : 200;
            return $this->jsonResponse($result, $status);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    private function jsonBody(): array
    {
        $request = $this->request();
        $raw = '';

        if (method_exists($request, 'getInput')) {
            $raw = (string)$request->getInput();
        }

        if ($raw === '') {
            $raw = (string)file_get_contents('php://input');
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = [];
        }

        foreach (['productId', 'basketItemId', 'id', 'quantity', 'sessid'] as $key) {
            if (!array_key_exists($key, $body) && $request->get($key) !== null) {
                $body[$key] = $request->get($key);
            }
        }

        if (empty($body['sessid'])) {
            $headerSessid = (string)$request->getHeader('X-Bitrix-Csrf-Token');
            if ($headerSessid !== '') {
                $body['sessid'] = $headerSessid;
            }
        }

        return $body;
    }

    private function isValidSessid(mixed $sessid): bool
    {
        if (is_string($sessid) && $sessid !== '' && hash_equals((string)bitrix_sessid(), $sessid)) {
            return true;
        }

        return check_bitrix_sessid();
    }

    private function jsonResponse(array $data, int $status = 200): Json
    {
        $response = new Json($data);
        if ($status !== 200) {
            $response->setStatus($status);
        }

        return $response;
    }

    private function errorResponse(\Throwable $e): Json
    {
        // В проде — не отдавать $e->getMessage() наружу без надобности,
        // логировать через \Bitrix\Main\Diag\Debug::writeToFile или монитор ошибок.

        //return $this->jsonResponse(['error' => 'Внутренняя ошибка сервера'], 500);
        return $this->jsonResponse(['error' => $e->getMessage()], 500);
    }
}
