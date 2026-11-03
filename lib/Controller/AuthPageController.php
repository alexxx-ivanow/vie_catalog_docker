<?php

namespace MyApp\Catalog\Controller;

use Bitrix\Main\HttpResponse;
use MyApp\Catalog\Config\Templates;
use MyApp\Catalog\Service\AccountService;

class AuthPageController extends BasePageController
{
    public function configureActions()
    {
        return [
            'forgot' => ['prefilters' => []],
            'register' => ['prefilters' => []],
            'bitrixAuth' => ['prefilters' => []],
            'confirmRegistration' => ['prefilters' => []],
            'legacyAuthIndex' => ['prefilters' => []],
            'changePassword' => ['prefilters' => []],
        ];
    }

    public function forgotAction()
    {
        return $this->renderPage([
            'pageTitle' => 'Восстановление пароля',
            'pageType' => 'auth_forgot',
            'sectionId' => 0,
            'contentView' => Templates::viewPath('auth/forgot.php'),
            'data_sections' => [],
            'data_section' => [],
            'data_product' => [],
            'data_order' => [],
            'jsEntry' => 'app-auth-forgot.js',
        ]);
    }

    public function registerAction()
    {
        return $this->renderPage([
            'pageTitle' => 'Регистрация',
            'pageType' => 'auth_register',
            'sectionId' => 0,
            'contentView' => Templates::viewPath('auth/register.php'),
            'data_sections' => [],
            'data_section' => [],
            'data_product' => [],
            'data_order' => [],
            'jsEntry' => 'app-auth-register.js',
        ]);
    }

    public function bitrixAuthAction()
    {
        return $this->processLegacyAuthRedirect('/auth/register/');
    }

    public function legacyAuthIndexAction()
    {
        return $this->processLegacyAuthRedirect('/auth/register/');
    }

    public function confirmRegistrationAction(string $code)
    {
        $result = AccountService::confirmRegistrationByCode($code);

        return $this->renderPage([
            'pageTitle' => 'Подтверждение регистрации',
            'pageType' => 'auth_confirm',
            'showBreadcrumbs' => false,
            'sectionId' => 0,
            'contentView' => Templates::viewPath('auth/confirm.php'),
            'data_sections' => [],
            'data_section' => [],
            'data_product' => [],
            'data_order' => [],
            'data_auth_confirm' => $result,
        ]);
    }

    public function changePasswordAction(string $login, string $checkword)
    {
        $login = rawurldecode($login);
        $state = AccountService::validatePasswordResetLink($login, $checkword);

        $page = [
            'pageTitle' => 'Новый пароль',
            'pageType' => 'auth_change_password',
            'showBreadcrumbs' => false,
            'sectionId' => 0,
            'contentView' => Templates::viewPath('auth/change_password.php'),
            'data_sections' => [],
            'data_section' => [],
            'data_product' => [],
            'data_order' => [],
            'data_auth_change_password' => $state,
        ];

        if (!empty($state['valid'])) {
            $page['jsEntry'] = 'app-auth-change-password.js';
        }

        return $this->renderPage($page);
    }

    private function processLegacyAuthRedirect(string $fallbackUrl)
    {
        $result = AccountService::handleAuthQueryParams();
        if (empty($result['handled'])) {
            LocalRedirect($fallbackUrl, true);
            return null;
        }

        if (!empty($result['redirect'])) {
            LocalRedirect((string)$result['redirect'], true);
            return null;
        }

        $message = (string)($result['message'] ?? $result['error'] ?? '');
        $response = new HttpResponse();
        $response->appendContent(
            '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>Авторизация</title>'
            . '<link rel="stylesheet" href="/local/templates/assets/css/style.css"></head><body class="site-body">'
            . '<main class="site-main"><div class="container"><p class="personal-message">'
            . htmlspecialcharsbx($message)
            . '</p><p><a href="/">На главную</a></p></div></main></body></html>'
        );

        return $response;
    }
}
