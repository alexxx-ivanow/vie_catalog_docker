<?php

namespace MyApp\Catalog\Controller;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\Response\Json;
use MyApp\Catalog\Service\AccountService;
use MyApp\Catalog\Service\PersonalOrderService;

class AccountApiController
{
    private function request()
    {
        return Context::getCurrent()->getRequest();
    }

    private function releaseSessionLock(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function session(): Json
    {
        $user = AccountService::getSessionUser();
        $this->releaseSessionLock();

        return $this->jsonResponse([
            'success' => true,
            'user' => $user,
        ], 200, true);
    }

    public function login(): Json
    {
        $body = $this->jsonBody();
        if (!$this->isValidSessid($body['sessid'] ?? null)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid sessid'], 400);
        }

        $result = AccountService::login(
            (string)($body['login'] ?? ''),
            (string)($body['password'] ?? ''),
            !empty($body['remember'])
        );

        return $this->jsonResponse($result, !empty($result['success']) ? 200 : 401, true);
    }

    public function logout(): Json
    {
        $body = $this->jsonBody();
        if (!$this->isValidSessid($body['sessid'] ?? null)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid sessid'], 400, true);
        }

        AccountService::logout();

        return $this->jsonResponse(['success' => true, 'user' => AccountService::getSessionUser()], 200, true);
    }

    public function profileGet(): Json
    {
        $profile = AccountService::getProfile();
        $this->releaseSessionLock();
        if ($profile === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'Требуется авторизация'], 401, true);
        }

        return $this->jsonResponse(['success' => true, 'profile' => $profile], 200, true);
    }

    public function profilePost(): Json
    {
        $body = $this->jsonBody();
        if (!$this->isValidSessid($body['sessid'] ?? null)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid sessid'], 400);
        }

        $result = AccountService::updateProfile([
            'name' => (string)($body['name'] ?? ''),
            'lastName' => (string)($body['lastName'] ?? ''),
            'email' => (string)($body['email'] ?? ''),
            'password' => (string)($body['password'] ?? ''),
            'newPassword' => (string)($body['newPassword'] ?? ''),
        ]);

        $status = !empty($result['success']) ? 200 : 422;
        return $this->jsonResponse($result, $status);
    }

    public function register(): Json
    {
        $body = $this->jsonBody();
        if (!$this->isValidSessid($body['sessid'] ?? null)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid sessid'], 400);
        }

        $result = AccountService::register(
            (string)($body['name'] ?? ''),
            (string)($body['lastName'] ?? ''),
            (string)($body['email'] ?? ''),
            (string)($body['phone'] ?? ''),
            (string)($body['password'] ?? ''),
            (string)($body['confirmPassword'] ?? ''),
        );

        return $this->jsonResponse($result, !empty($result['success']) ? 200 : 422);
    }

    public function forgotPassword(): Json
    {
        $body = $this->jsonBody();
        if (!$this->isValidSessid($body['sessid'] ?? null)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid sessid'], 400);
        }

        $result = AccountService::sendForgotPassword((string)($body['email'] ?? ''));
        return $this->jsonResponse($result, !empty($result['success']) ? 200 : 422);
    }

    public function changePassword(): Json
    {
        $body = $this->jsonBody();
        if (!$this->isValidSessid($body['sessid'] ?? null)) {
            return $this->jsonResponse(['success' => false, 'error' => 'Invalid sessid'], 400, true);
        }

        $result = AccountService::changePasswordWithCheckword(
            (string)($body['login'] ?? ''),
            (string)($body['checkword'] ?? ''),
            (string)($body['password'] ?? ''),
            (string)($body['confirmPassword'] ?? ''),
        );

        return $this->jsonResponse($result, !empty($result['success']) ? 200 : 422, true);
    }

    public function orders(): Json
    {
        $userId = AccountService::requireAuthorizedUserId();
        $this->releaseSessionLock();
        if ($userId <= 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Требуется авторизация'], 401, true);
        }

        $page = max(1, (int)$this->request()->get('page'));
        $limit = (int)$this->request()->get('limit');
        if ($limit <= 0) {
            $limit = 10;
        }
        $limit = min(50, max(1, $limit));

        try {
            $data = PersonalOrderService::listOrders($userId, $page, $limit);
            return $this->jsonResponse(['success' => true] + $data, 200, true);
        } catch (\Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500, true);
        }
    }

    public function order(int $id): Json
    {
        $userId = AccountService::requireAuthorizedUserId();
        $this->releaseSessionLock();
        if ($userId <= 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'Требуется авторизация'], 401, true);
        }

        try {
            $order = PersonalOrderService::getOrderDetail($id, $userId);
            if ($order === null) {
                return $this->jsonResponse(['success' => false, 'error' => 'Заказ не найден'], 404, true);
            }
            return $this->jsonResponse(['success' => true, 'order' => $order], 200, true);
        } catch (\Throwable $e) {
            return $this->jsonResponse(['success' => false, 'error' => $e->getMessage()], 500, true);
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

    private function jsonResponse(array $data, int $status = 200, bool $privateNoCache = false): Json
    {
        $data['sessid'] = bitrix_sessid();
        $response = new Json($data);
        if ($status !== 200) {
            $response->setStatus($status);
        }
        if ($privateNoCache) {
            $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->addHeader('Pragma', 'no-cache');
            $response->addHeader('Expires', '0');
        }

        return $response;
    }
}
