<?php

namespace MyApp\Catalog\Controller;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\Response\Json;
use MyApp\Catalog\Service\CheckoutService;

class CheckoutApiController
{
    private function request()
    {
        return Context::getCurrent()->getRequest();
    }

    public function init(): Json
    {
        try {
            $data = CheckoutService::init();
            $status = !empty($data['success']) ? 200 : 400;
            return $this->jsonResponse($data, $status);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function locations(): Json
    {
        $query = (string)$this->request()->get('q');
        $limit = (int)$this->request()->get('limit');

        try {
            return $this->jsonResponse(CheckoutService::searchLocations($query, $limit ?: 10));
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function calculate(): Json
    {
        $body = $this->jsonBody();

        if (!$this->isValidSessid($body['sessid'] ?? null)) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Invalid sessid',
            ], 400);
        }

        try {
            $data = CheckoutService::calculate($body);
            $status = !empty($data['success']) ? 200 : 400;
            return $this->jsonResponse($data, $status);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    public function submit(): Json
    {
        $body = $this->jsonBody();

        if (!$this->isValidSessid($body['sessid'] ?? null)) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Invalid sessid',
            ], 400);
        }

        try {
            $data = CheckoutService::submit($body);
            $status = !empty($data['success']) ? 200 : 400;
            return $this->jsonResponse($data, $status);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    private function jsonBody(): array
    {
        $request = $this->request();
        $raw = method_exists($request, 'getInput') ? (string)$request->getInput() : '';
        if ($raw === '') {
            $raw = (string)file_get_contents('php://input');
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            $body = [];
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
        return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
    }
}
