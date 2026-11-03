<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Password;
use Bitrix\Main\UserTable;

final class AccountService
{
    /** Временная отладка ссылки смены пароля — удалить после диагностики */
    private const TEMP_PASSWORD_RESET_DEBUG = false;

    public static function getSessionUser(): array
    {
        global $USER;

        if (!is_object($USER) || !$USER->IsAuthorized()) {
            return self::guestSessionUser();
        }

        $userId = (int)$USER->GetID();
        if ($userId <= 0) {
            return self::guestSessionUser();
        }

        $profile = self::getProfileForUserId($userId);
        if ($profile === null) {
            return [
                'authorized' => true,
                'id' => (int)$USER->GetID(),
                'name' => (string)$USER->GetFirstName(),
                'lastName' => (string)$USER->GetLastName(),
                'email' => (string)$USER->GetEmail(),
                'login' => (string)$USER->GetLogin(),
            ];
        }

        return array_merge(['authorized' => true], $profile);
    }

    public static function requireAuthorizedUserId(): int
    {
        global $USER;

        if (!is_object($USER) || !$USER->IsAuthorized()) {
            return 0;
        }

        return (int)$USER->GetID();
    }

    /**
     * @return array{success: bool, error?: string, user?: array}
     */
    public static function login(string $login, string $password, bool $remember = true): array
    {
        Loader::includeModule('main');
        global $USER;

        $login = trim($login);
        $password = (string)$password;
        if ($login === '' || $password === '') {
            return ['success' => false, 'error' => 'Укажите логин и пароль'];
        }

        $result = $USER->Login($login, $password, $remember ? 'Y' : 'N');
        if ($result === true) {
            return [
                'success' => true,
                'user' => self::getSessionUser(),
            ];
        }

        $error = is_object($USER) ? (string)$USER->LAST_ERROR : '';
        if ($error === '') {
            $error = 'Неверный логин или пароль';
        }

        return ['success' => false, 'error' => $error];
    }

    public static function logout(): void
    {
        global $USER;
        if (is_object($USER)) {
            $USER->Logout();
        }
    }

    /**
     * @return array|null
     */
    public static function getProfile(): ?array
    {
        $userId = self::requireAuthorizedUserId();
        if ($userId <= 0) {
            return null;
        }

        return self::getProfileForUserId($userId);
    }

    /**
     * @param array{name?: string, lastName?: string, email?: string, password?: string, newPassword?: string} $fields
     * @return array{success: bool, error?: string, profile?: array, emailChangePending?: bool, message?: string}
     */
    public static function updateProfile(array $fields): array
    {
        Loader::includeModule('main');
        $userId = self::requireAuthorizedUserId();
        if ($userId <= 0) {
            return ['success' => false, 'error' => 'Требуется авторизация'];
        }

        $row = UserTable::getById($userId)->fetch();
        if (!$row) {
            return ['success' => false, 'error' => 'Пользователь не найден'];
        }

        $update = [];
        $name = trim((string)($fields['name'] ?? ''));
        $lastName = trim((string)($fields['lastName'] ?? ''));
        if ($name !== '') {
            $update['NAME'] = $name;
        }
        if ($lastName !== '') {
            $update['LAST_NAME'] = $lastName;
        }

        $newEmail = trim((string)($fields['email'] ?? ''));
        $currentEmail = trim((string)($row['EMAIL'] ?? ''));
        $emailChangePending = false;
        $message = '';

        if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Некорректный e-mail'];
        }

        if ($newEmail !== '' && strcasecmp($newEmail, $currentEmail) !== 0) {
            $update['EMAIL'] = $newEmail;
            if (strcasecmp((string)($row['LOGIN'] ?? ''), $currentEmail) === 0) {
                $update['LOGIN'] = $newEmail;
            }
        }

        $currentPassword = (string)($fields['password'] ?? '');
        $newPassword = (string)($fields['newPassword'] ?? '');
        if ($newPassword !== '') {
            if ($currentPassword === '') {
                return ['success' => false, 'error' => 'Укажите текущий пароль'];
            }
            if (!self::verifyUserPassword($userId, $currentPassword)) {
                return ['success' => false, 'error' => 'Неверный текущий пароль'];
            }
            $update['PASSWORD'] = $newPassword;
            $update['CONFIRM_PASSWORD'] = $newPassword;
        }

        if (empty($update)) {
            $profile = self::getProfileForUserId($userId);
            return [
                'success' => true,
                'profile' => $profile,
            ];
        }

        $user = new \CUser();
        if (!$user->Update($userId, $update)) {
            return ['success' => false, 'error' => (string)$user->LAST_ERROR];
        }

        if (isset($update['EMAIL']) && self::isEmailConfirmationRequired()) {
            $emailChangePending = true;
            $message = 'На новый адрес отправлено письмо для подтверждения e-mail.';
        }

        $profile = self::getProfileForUserId($userId);
        if ($emailChangePending && $profile !== null) {
            $profile['email'] = $currentEmail;
        }

        return [
            'success' => true,
            'profile' => $profile,
            'emailChangePending' => $emailChangePending,
            'message' => $message,
        ];
    }

    /**
     * @return array{success: bool, error?: string, needsEmailConfirm?: bool, message?: string}
     */
    public static function register(
        string $name,
        string $lastName,
        string $email,
        string $phone,
        string $password,
        string $confirmPassword
    ): array {
        Loader::includeModule('main');
        global $USER;

        $name = trim($name);
        $lastName = trim($lastName);
        $email = trim($email);
        $phone = self::normalizePhone($phone);
        $password = (string)$password;
        $confirmPassword = (string)$confirmPassword;

        if ($name === '' || $lastName === '' || $email === '' || $phone === '') {
            return ['success' => false, 'error' => 'Заполните все обязательные поля'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Некорректный e-mail'];
        }
        if ($password === '' || $confirmPassword === '') {
            return ['success' => false, 'error' => 'Укажите пароль'];
        }
        if ($password !== $confirmPassword) {
            return ['success' => false, 'error' => 'Пароли не совпадают'];
        }
        if (strlen($phone) < 11) {
            return ['success' => false, 'error' => 'Укажите корректный телефон'];
        }

        $siteId = Context::getCurrent()->getSite() ?: (defined('SITE_ID') ? SITE_ID : 's1');

        $registerResult = $USER->Register(
            $email,
            $name,
            $lastName,
            $password,
            $confirmPassword,
            $email,
            $siteId
        );

        if ($registerResult === false) {
            return ['success' => false, 'error' => 'Ошибка регистрации'];
        }
        if (is_array($registerResult) && ($registerResult['TYPE'] ?? '') === 'ERROR') {
            $error = (string)($registerResult['MESSAGE'] ?? 'Ошибка регистрации');
            return ['success' => false, 'error' => $error];
        }
        if (is_array($registerResult) && ($registerResult['TYPE'] ?? '') !== 'OK' && ($registerResult['TYPE'] ?? '') !== '') {
            $error = (string)($registerResult['MESSAGE'] ?? 'Ошибка регистрации');
            if ($error !== '') {
                return ['success' => false, 'error' => $error];
            }
        }

        $userId = is_object($USER) && $USER->IsAuthorized() ? (int)$USER->GetID() : 0;
        if ($userId <= 0) {
            $userId = self::findUserIdByEmail($email);
        }
        if ($userId > 0) {
            $groupIds = CheckoutUserService::resolveRegistrationGroupIds();
            $update = ['PERSONAL_PHONE' => $phone];
            if (!empty($groupIds)) {
                $update['GROUP_ID'] = $groupIds;
            }
            $user = new \CUser();
            $user->Update($userId, $update);
        }

        $needsConfirm = self::isRegistrationEmailConfirmationRequired();
        if (is_object($USER) && $USER->IsAuthorized()) {
            $USER->Logout();
        }

        if ($needsConfirm) {
            return [
                'success' => true,
                'needsEmailConfirm' => true,
                'message' => 'На указанный e-mail отправлено письмо. Подтвердите регистрацию по ссылке из письма.',
            ];
        }

        return [
            'success' => true,
            'needsEmailConfirm' => false,
            'message' => 'Регистрация завершена. Вы можете войти.',
        ];
    }

    /**
     * @return array{success: bool, error?: string, message?: string}
     */
    public static function sendForgotPassword(string $email): array
    {
        Loader::includeModule('main');
        global $USER;

        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Укажите корректный e-mail'];
        }

        $login = $email;
        $row = UserTable::getList([
            'select' => ['LOGIN', 'EMAIL'],
            'filter' => ['=EMAIL' => $email],
            'limit' => 1,
        ])->fetch();
        if ($row) {
            $login = (string)($row['LOGIN'] ?: $row['EMAIL']);
        }

        $result = $USER->SendPassword($login, $email);
        if (is_array($result) && ($result['TYPE'] ?? '') === 'ERROR') {
            return [
                'success' => false,
                'error' => (string)($result['MESSAGE'] ?? 'Не удалось отправить письмо'),
            ];
        }

        return [
            'success' => true,
            'message' => 'Инструкции по восстановлению пароля отправлены на e-mail.',
        ];
    }

    public static function buildConfirmRegistrationPath(string $code): string
    {
        $code = trim($code);
        return '/auth/confirm_registration/' . rawurlencode($code);
    }

    public static function resolveUserIdByConfirmCode(string $code): int
    {
        $code = trim($code);
        if ($code === '') {
            return 0;
        }

        $row = UserTable::getList([
            'select' => ['ID'],
            'filter' => ['=CONFIRM_CODE' => $code],
            'limit' => 1,
        ])->fetch();

        return $row ? (int)$row['ID'] : 0;
    }

    /**
     * @return array{success: bool, message?: string, error?: string}
     */
    public static function confirmRegistration(int $userId, string $code): array
    {
        Loader::includeModule('main');
        global $USER;

        if ($userId <= 0 || trim($code) === '') {
            return [
                'success' => false,
                'error' => 'Неверная или устаревшая ссылка',
            ];
        }

        $code = trim($code);

        if (is_object($USER) && method_exists($USER, 'ConfirmRegistration')) {
            $result = $USER->ConfirmRegistration($userId, $code);
            if ($result === true || (is_array($result) && ($result['TYPE'] ?? '') === 'OK')) {
                return [
                    'success' => true,
                    'message' => 'Регистрация подтверждена. Вы можете войти в личный кабинет.',
                ];
            }

            $error = is_array($result) ? (string)($result['MESSAGE'] ?? '') : '';

            return [
                'success' => false,
                'error' => $error !== '' ? $error : 'Не удалось подтвердить регистрацию',
            ];
        }

        return self::confirmRegistrationByUserFields($userId, $code);
    }

    /**
     * Подтверждение регистрации для сборок, где у CUser нет ConfirmRegistration().
     *
     * @return array{success: bool, message?: string, error?: string}
     */
    private static function confirmRegistrationByUserFields(int $userId, string $code): array
    {
        $row = UserTable::getById($userId)->fetch();
        if (!$row) {
            return [
                'success' => false,
                'error' => 'Неверная или устаревшая ссылка',
            ];
        }

        $isActive = ($row['ACTIVE'] ?? 'N') === 'Y';
        $storedCode = (string)($row['CONFIRM_CODE'] ?? '');

        if ($isActive && $storedCode === '') {
            return [
                'success' => true,
                'message' => 'Регистрация уже была подтверждена. Вы можете войти в личный кабинет.',
            ];
        }

        if ($storedCode === '' || !hash_equals($storedCode, $code)) {
            return [
                'success' => false,
                'error' => 'Неверная или устаревшая ссылка',
            ];
        }

        $user = new \CUser();
        if (!$user->Update($userId, [
            'ACTIVE' => 'Y',
            'CONFIRM_CODE' => '',
        ])) {
            return [
                'success' => false,
                'error' => (string)$user->LAST_ERROR ?: 'Не удалось подтвердить регистрацию',
            ];
        }

        return [
            'success' => true,
            'message' => 'Регистрация подтверждена. Вы можете войти в личный кабинет.',
        ];
    }

    /**
     * @return array{success: bool, message?: string, error?: string}
     */
    public static function confirmRegistrationByCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return [
                'success' => false,
                'error' => 'Неверная или устаревшая ссылка',
            ];
        }

        $userId = self::resolveUserIdByConfirmCode($code);
        if ($userId <= 0) {
            return [
                'success' => false,
                'error' => 'Неверная или устаревшая ссылка',
            ];
        }

        return self::confirmRegistration($userId, $code);
    }

    public static function buildChangePasswordPath(string $login, string $checkword): string
    {
        $login = trim($login);
        $checkword = trim($checkword);

        return '/auth/change_password/'
            . rawurlencode($login)
            . '/'
            . rawurlencode($checkword);
    }

    /**
     * @return array{ID: int, LOGIN: string, EMAIL: string, CHECKWORD: string}|null
     */
    public static function resolveUserByLoginOrEmail(string $loginOrEmail): ?array
    {
        $loginOrEmail = trim($loginOrEmail);
        if ($loginOrEmail === '') {
            return null;
        }

        $candidates = array_unique(array_filter([
            $loginOrEmail,
            rawurldecode($loginOrEmail),
            urldecode($loginOrEmail),
        ]));

        foreach ($candidates as $candidate) {
            $row = UserTable::getList([
                'select' => ['ID', 'LOGIN', 'EMAIL'],
                'filter' => [
                    'LOGIC' => 'OR',
                    ['=LOGIN' => $candidate],
                    ['=EMAIL' => $candidate],
                ],
                'limit' => 1,
            ])->fetch();

            if ($row) {
                return self::fetchUserAuthFieldsById((int)$row['ID'], (string)($row['LOGIN'] ?? ''));
            }

            $dbRow = self::fetchUserRowFromDbByLoginOrEmail($candidate);
            if ($dbRow !== null) {
                return self::normalizeUserAuthRow($dbRow);
            }
        }

        return null;
    }

    /**
     * CHECKWORD недоступен в D7 UserTable на части сборок — читаем через CUser::GetList.
     *
     * @return array{ID: int, LOGIN: string, EMAIL: string, CHECKWORD: string}|null
     */
    private static function fetchUserAuthFieldsById(int $userId, string $fallbackLogin = ''): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $dbRow = self::fetchUserRowFromDbById($userId);
        if ($dbRow !== null) {
            return self::normalizeUserAuthRow($dbRow, $fallbackLogin);
        }

        $legacy = self::fetchLegacyUserRow(['ID' => $userId]);
        if (!$legacy) {
            return null;
        }

        return self::normalizeUserAuthRow($legacy, $fallbackLogin);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ID: int, LOGIN: string, EMAIL: string, CHECKWORD: string}
     */
    private static function normalizeUserAuthRow(array $row, string $fallbackLogin = ''): array
    {
        $userId = (int)($row['ID'] ?? 0);
        $login = (string)($row['LOGIN'] ?? $fallbackLogin);

        return [
            'ID' => $userId,
            'LOGIN' => $login,
            'EMAIL' => (string)($row['EMAIL'] ?? ''),
            'CHECKWORD' => (string)($row['CHECKWORD'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchUserRowFromDbById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $connection = Application::getConnection();
        $sql = 'SELECT ID, LOGIN, EMAIL, CHECKWORD FROM b_user WHERE ID = ' . $userId;
        $row = $connection->query($sql)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchUserRowFromDbByLoginOrEmail(string $loginOrEmail): ?array
    {
        $loginOrEmail = trim($loginOrEmail);
        if ($loginOrEmail === '') {
            return null;
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $escaped = $helper->forSql($loginOrEmail);
        $sql = "SELECT ID, LOGIN, EMAIL, CHECKWORD FROM b_user WHERE LOGIN = '{$escaped}' OR EMAIL = '{$escaped}' LIMIT 1";
        $row = $connection->query($sql)->fetch();

        if (is_array($row)) {
            return $row;
        }

        $lower = $helper->forSql(mb_strtolower($loginOrEmail));
        $sql = "SELECT ID, LOGIN, EMAIL, CHECKWORD FROM b_user WHERE LOWER(LOGIN) = '{$lower}' OR LOWER(EMAIL) = '{$lower}' LIMIT 1";

        return $connection->query($sql)->fetch() ?: null;
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>|null
     */
    private static function fetchLegacyUserRow(array $filter): ?array
    {
        $rs = \CUser::GetList(
            'ID',
            'ASC',
            $filter,
            [
                'FIELDS' => ['ID', 'LOGIN', 'EMAIL', 'CHECKWORD'],
                'NAV_PARAMS' => ['nTopCount' => 1],
            ]
        );

        if (!is_object($rs)) {
            return null;
        }

        $row = $rs->Fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array{ID: int, LOGIN: string, EMAIL: string, CHECKWORD: string}|null
     */
    private static function findUserByCheckword(string $checkword): ?array
    {
        $checkword = trim($checkword);
        if ($checkword === '') {
            return null;
        }

        $row = self::fetchUserRowFromDbByCheckword($checkword);
        if ($row !== null) {
            return self::normalizeUserAuthRow($row);
        }

        foreach ([$checkword, md5($checkword)] as $candidate) {
            $legacy = self::fetchLegacyUserRow(['CHECKWORD' => $candidate]);
            if (!$legacy) {
                continue;
            }

            $userId = (int)($legacy['ID'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            return self::fetchUserAuthFieldsById($userId, (string)($legacy['LOGIN'] ?? ''));
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fetchUserRowFromDbByCheckword(string $checkword): ?array
    {
        $checkword = trim($checkword);
        if ($checkword === '') {
            return null;
        }

        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();
        $plain = $helper->forSql($checkword);
        $hashed = $helper->forSql(md5($checkword));
        $sql = "SELECT ID, LOGIN, EMAIL, CHECKWORD FROM b_user WHERE CHECKWORD <> '' AND (CHECKWORD = '{$plain}' OR CHECKWORD = '{$hashed}') LIMIT 1";

        return $connection->query($sql)->fetch() ?: null;
    }

    private static function loginOrEmailMatchesUser(string $loginOrEmail, array $user): bool
    {
        $needle = trim($loginOrEmail);
        if ($needle === '') {
            return false;
        }

        $login = (string)($user['LOGIN'] ?? '');
        $email = (string)($user['EMAIL'] ?? '');

        if ($login !== '' && strcasecmp($login, $needle) === 0) {
            return true;
        }

        if ($email !== '' && strcasecmp($email, $needle) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Сверка как в CUser::ChangePassword: Password::equals (crypt), плюс legacy plain/md5/salt.
     */
    private static function isCheckwordMatch(string $stored, string $checkword): bool
    {
        $details = self::checkwordCompareDetails($stored, $checkword);

        return self::checkwordMatchesFromDetails($details);
    }

    /**
     * @param array<string, mixed> $details
     */
    private static function checkwordMatchesFromDetails(array $details): bool
    {
        return !empty($details['match_bitrix'])
            || !empty($details['match_plain'])
            || !empty($details['match_md5'])
            || !empty($details['match_legacy_salt_md5']);
    }

    /**
     * @return array{
     *   stored_len: int,
     *   url_len: int,
     *   match_bitrix: bool,
     *   match_plain: bool,
     *   match_md5: bool,
     *   match_legacy_salt_md5: bool,
     *   stored_empty: bool,
     *   url_empty: bool,
     *   stored_prefix: string,
     *   url_checkword: string,
     *   url_checkword_md5: string
     * }
     */
    private static function checkwordCompareDetails(string $stored, string $checkword): array
    {
        $checkword = trim($checkword);
        $stored = (string)$stored;
        $nonEmpty = $stored !== '' && $checkword !== '';

        return [
            'stored_len' => strlen($stored),
            'url_len' => strlen($checkword),
            'stored_empty' => $stored === '',
            'url_empty' => $checkword === '',
            'match_bitrix' => $nonEmpty && Password::equals($stored, $checkword),
            'match_plain' => $nonEmpty && hash_equals($stored, $checkword),
            'match_md5' => $nonEmpty && hash_equals($stored, md5($checkword)),
            'match_legacy_salt_md5' => $nonEmpty && self::isLegacySaltMd5CheckwordMatch($stored, $checkword),
            'stored_prefix' => substr($stored, 0, 12),
            'url_checkword' => $checkword,
            'url_checkword_md5' => md5($checkword),
        ];
    }

    private static function isLegacySaltMd5CheckwordMatch(string $stored, string $checkword): bool
    {
        if ($stored === '' || $checkword === '' || strpos($stored, '$') === 0 || strlen($stored) < 9) {
            return false;
        }

        $salt = substr($stored, 0, 8);

        return hash_equals($stored, $salt . md5($salt . $checkword));
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function logPasswordResetDebug(string $stage, array $context = []): void
    {
        if (!self::TEMP_PASSWORD_RESET_DEBUG) {
            return;
        }

        $payload = array_merge([
            'ts' => date('c'),
            'stage' => $stage,
        ], $context);

        $documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($documentRoot === '') {
            return;
        }

        $dir = $documentRoot . '/local/modules/myapp.catalog/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $path = $dir . '/password-reset-debug.log';
        @file_put_contents(
            $path,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    private static function isCheckwordValidForUser(int $userId, string $checkword): bool
    {
        if ($userId <= 0) {
            self::logPasswordResetDebug('checkword_user_invalid_id', ['userId' => $userId]);

            return false;
        }

        $row = self::fetchUserRowFromDbById($userId);
        if ($row === null) {
            $user = self::fetchUserAuthFieldsById($userId);
            if ($user === null) {
                self::logPasswordResetDebug('checkword_user_not_found', ['userId' => $userId]);

                return false;
            }

            $stored = (string)($user['CHECKWORD'] ?? '');
            $details = self::checkwordCompareDetails($stored, $checkword);
            self::logPasswordResetDebug('checkword_compare_legacy', [
                'userId' => $userId,
                'source' => 'fetchUserAuthFieldsById',
                'compare' => $details,
            ]);

            return self::checkwordMatchesFromDetails($details);
        }

        $stored = (string)($row['CHECKWORD'] ?? '');
        $details = self::checkwordCompareDetails($stored, $checkword);
        self::logPasswordResetDebug('checkword_compare_db', [
            'userId' => $userId,
            'login' => (string)($row['LOGIN'] ?? ''),
            'email' => (string)($row['EMAIL'] ?? ''),
            'source' => 'fetchUserRowFromDbById',
            'compare' => $details,
        ]);

        return self::checkwordMatchesFromDetails($details);
    }

    /**
     * @return array{valid: bool, error?: string, login?: string, checkword?: string}
     */
    public static function validatePasswordResetLink(string $loginOrEmail, string $checkword): array
    {
        $checkword = trim($checkword);
        $loginOrEmail = trim($loginOrEmail);

        self::logPasswordResetDebug('validate_start', [
            'loginOrEmail' => $loginOrEmail,
            'checkword_len' => strlen($checkword),
            'checkword' => $checkword,
        ]);

        if ($checkword === '' || $loginOrEmail === '') {
            self::logPasswordResetDebug('validate_fail', ['reason' => 'empty_login_or_checkword']);

            return [
                'valid' => false,
                'error' => 'Неверная или устаревшая ссылка',
            ];
        }

        $user = self::resolveUserByLoginOrEmail($loginOrEmail);
        if ($user !== null) {
            self::logPasswordResetDebug('validate_user_resolved', [
                'userId' => (int)$user['ID'],
                'login' => (string)($user['LOGIN'] ?? ''),
                'email' => (string)($user['EMAIL'] ?? ''),
                'resolved_checkword_len' => strlen((string)($user['CHECKWORD'] ?? '')),
            ]);

            if (!self::loginOrEmailMatchesUser($loginOrEmail, $user)) {
                self::logPasswordResetDebug('validate_fail', ['reason' => 'login_email_mismatch']);

                return [
                    'valid' => false,
                    'error' => 'Неверная или устаревшая ссылка',
                ];
            }

            if (!self::isCheckwordValidForUser((int)$user['ID'], $checkword)) {
                self::logPasswordResetDebug('validate_fail', ['reason' => 'checkword_mismatch_for_user']);

                return [
                    'valid' => false,
                    'error' => 'Неверная или устаревшая ссылка',
                ];
            }

            $result = self::validPasswordResetState($user, $checkword);
            self::logPasswordResetDebug('validate_ok', ['path' => 'by_login_or_email', 'login' => $result['login'] ?? '']);

            return $result;
        }

        self::logPasswordResetDebug('validate_user_not_found_by_login', ['loginOrEmail' => $loginOrEmail]);

        $userByCheckword = self::findUserByCheckword($checkword);
        if ($userByCheckword === null) {
            self::logPasswordResetDebug('validate_fail', ['reason' => 'user_not_found_by_checkword']);

            return [
                'valid' => false,
                'error' => 'Неверная или устаревшая ссылка',
            ];
        }

        self::logPasswordResetDebug('validate_user_by_checkword', [
            'userId' => (int)$userByCheckword['ID'],
            'login' => (string)($userByCheckword['LOGIN'] ?? ''),
            'email' => (string)($userByCheckword['EMAIL'] ?? ''),
        ]);

        if (!self::loginOrEmailMatchesUser($loginOrEmail, $userByCheckword)) {
            self::logPasswordResetDebug('validate_fail', ['reason' => 'login_email_mismatch_for_checkword_user']);

            return [
                'valid' => false,
                'error' => 'Неверная или устаревшая ссылка',
            ];
        }

        if (!self::isCheckwordValidForUser((int)$userByCheckword['ID'], $checkword)) {
            self::logPasswordResetDebug('validate_fail', ['reason' => 'checkword_mismatch_after_find_by_checkword']);

            return [
                'valid' => false,
                'error' => 'Неверная или устаревшая ссылка',
            ];
        }

        $result = self::validPasswordResetState($userByCheckword, $checkword);
        self::logPasswordResetDebug('validate_ok', ['path' => 'by_checkword', 'login' => $result['login'] ?? '']);

        return $result;
    }

    /**
     * @param array{ID: int, LOGIN: string, EMAIL?: string, CHECKWORD: string} $user
     * @return array{valid: bool, error?: string, login?: string, checkword?: string}
     */
    private static function validPasswordResetState(array $user, string $checkword): array
    {
        $login = (string)($user['LOGIN'] ?? '');
        if ($login === '') {
            return [
                'valid' => false,
                'error' => 'Неверная или устаревшая ссылка',
            ];
        }

        return [
            'valid' => true,
            'login' => $login,
            'checkword' => $checkword,
        ];
    }

    /**
     * @return array{success: bool, message?: string, error?: string, redirect?: string}
     */
    public static function changePasswordWithCheckword(
        string $login,
        string $checkword,
        string $password,
        string $confirmPassword
    ): array {
        Loader::includeModule('main');
        global $USER;

        $login = trim($login);
        $checkword = trim($checkword);
        $password = (string)$password;
        $confirmPassword = (string)$confirmPassword;

        if ($login === '' || $checkword === '') {
            return ['success' => false, 'error' => 'Неверная или устаревшая ссылка'];
        }
        if ($password === '' || $confirmPassword === '') {
            return ['success' => false, 'error' => 'Укажите новый пароль'];
        }
        if ($password !== $confirmPassword) {
            return ['success' => false, 'error' => 'Пароли не совпадают'];
        }

        $linkCheck = self::validatePasswordResetLink($login, $checkword);
        if (empty($linkCheck['valid'])) {
            return [
                'success' => false,
                'error' => (string)($linkCheck['error'] ?? 'Неверная или устаревшая ссылка'),
            ];
        }

        $canonicalLogin = $login;
        $resolved = self::resolveUserByLoginOrEmail($login);
        if ($resolved !== null && (string)($resolved['LOGIN'] ?? '') !== '') {
            $canonicalLogin = (string)$resolved['LOGIN'];
        } else {
            $byCheckword = self::findUserByCheckword($checkword);
            if ($byCheckword !== null && (string)($byCheckword['LOGIN'] ?? '') !== '') {
                $canonicalLogin = (string)$byCheckword['LOGIN'];
            }
        }

        if (is_object($USER) && method_exists($USER, 'ChangePassword')) {
            $resolved = self::resolveUserByLoginOrEmail($login);
            $loginsToTry = array_unique(array_filter([
                $canonicalLogin,
                $login,
                $resolved !== null ? (string)($resolved['LOGIN'] ?? '') : '',
            ]));

            $error = '';
            foreach ($loginsToTry as $loginAttempt) {
                $result = $USER->ChangePassword($loginAttempt, $checkword, $password, $confirmPassword);
                if ($result === true || (is_array($result) && ($result['TYPE'] ?? '') === 'OK')) {
                    return [
                        'success' => true,
                        'message' => 'Пароль успешно изменён. Вы можете войти в личный кабинет.',
                        'redirect' => '/?auth=1',
                    ];
                }
                if (is_array($result)) {
                    $error = (string)($result['MESSAGE'] ?? '');
                }
            }

            return [
                'success' => false,
                'error' => $error !== '' ? $error : 'Не удалось изменить пароль',
            ];
        }

        return self::changePasswordByUserFields($canonicalLogin, $checkword, $password);
    }

    /**
     * @return array{success: bool, message?: string, error?: string, redirect?: string}
     */
    private static function changePasswordByUserFields(string $login, string $checkword, string $password): array
    {
        $user = self::resolveUserByLoginOrEmail($login);
        if ($user === null) {
            return ['success' => false, 'error' => 'Неверная или устаревшая ссылка'];
        }

        $stored = (string)($user['CHECKWORD'] ?? '');
        if (!self::isCheckwordMatch($stored, $checkword)) {
            return ['success' => false, 'error' => 'Неверная или устаревшая ссылка'];
        }

        $userId = (int)$user['ID'];
        $cUser = new \CUser();
        if (!$cUser->Update($userId, [
            'PASSWORD' => $password,
            'CONFIRM_PASSWORD' => $password,
            'CHECKWORD' => '',
        ])) {
            return [
                'success' => false,
                'error' => (string)$cUser->LAST_ERROR ?: 'Не удалось изменить пароль',
            ];
        }

        return [
            'success' => true,
            'message' => 'Пароль успешно изменён. Вы можете войти в личный кабинет.',
            'redirect' => '/?auth=1',
        ];
    }

    /**
     * @return array{handled: bool, redirect?: string, message?: string, error?: string}
     */
    public static function handleAuthQueryParams(): array
    {
        Loader::includeModule('main');
        $request = Context::getCurrent()->getRequest();

        if ($request->get('confirm_registration') === 'yes') {
            $code = trim((string)$request->get('confirm_code'));
            if ($code !== '') {
                return [
                    'handled' => true,
                    'redirect' => self::buildConfirmRegistrationPath($code),
                ];
            }
        }

        if ($request->get('change_password') === 'yes') {
            $login = trim((string)$request->get('USER_LOGIN'));
            $checkword = trim((string)$request->get('USER_CHECKWORD'));
            if ($login !== '' && $checkword !== '') {
                return [
                    'handled' => true,
                    'redirect' => self::buildChangePasswordPath($login, $checkword),
                ];
            }
        }

        return ['handled' => false];
    }

    private static function getProfileForUserId(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $row = UserTable::getById($userId)->fetch();
        if (!$row) {
            return null;
        }

        return [
            'id' => $userId,
            'name' => (string)($row['NAME'] ?? ''),
            'lastName' => (string)($row['LAST_NAME'] ?? ''),
            'email' => (string)($row['EMAIL'] ?? ''),
            'login' => (string)($row['LOGIN'] ?? ''),
        ];
    }

    private static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }

        return $digits;
    }

    private static function isRegistrationEmailConfirmationRequired(): bool
    {
        $siteId = (string)Context::getCurrent()->getSite();
        return Option::get('main', 'new_user_registration_email_confirmation', 'N', $siteId) === 'Y';
    }

    private static function guestSessionUser(): array
    {
        return [
            'authorized' => false,
            'id' => 0,
            'name' => '',
            'lastName' => '',
            'email' => '',
            'login' => '',
        ];
    }

    private static function verifyUserPassword(int $userId, string $password): bool
    {
        if ($userId <= 0 || $password === '') {
            return false;
        }

        $row = UserTable::getById($userId)->fetch();
        if (!$row || empty($row['PASSWORD'])) {
            return false;
        }

        return Password::equals((string)$row['PASSWORD'], $password);
    }

    private static function findUserIdByEmail(string $email): int
    {
        $row = UserTable::getList([
            'select' => ['ID'],
            'filter' => ['=EMAIL' => $email],
            'limit' => 1,
        ])->fetch();

        return $row ? (int)$row['ID'] : 0;
    }

    private static function isEmailConfirmationRequired(): bool
    {
        $siteId = (string)Context::getCurrent()->getSite();
        if (Option::get('main', 'email_confirmation', 'N', $siteId) === 'Y') {
            return true;
        }

        return Option::get('main', 'new_user_email_confirmation', 'N', $siteId) === 'Y';
    }
}
