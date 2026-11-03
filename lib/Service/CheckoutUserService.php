<?php

namespace MyApp\Catalog\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;
use Bitrix\Main\UserTable;
use Bitrix\Sale\Order;

final class CheckoutUserService
{
    private const EMAIL_CODES = ['EMAIL', 'E_MAIL', 'MAIL', 'E-MAIL'];
    private const PHONE_CODES = ['PHONE', 'TEL', 'PHONE_NUMBER', 'MOBILE'];
    private const NAME_CODES = ['FIO', 'NAME', 'CONTACT_PERSON', 'FULL_NAME', 'USER_NAME'];

    /**
     * @return array{userId: int, isNew: bool, error: string}
     */
    public static function resolveUserIdForOrder(array $selection, bool $strict = false): array
    {
        global $USER;

        if (is_object($USER) && $USER->IsAuthorized()) {
            return [
                'userId' => (int)$USER->GetID(),
                'isNew' => false,
                'error' => '',
            ];
        }

        Loader::includeModule('main');

        $contact = self::extractContactFromSelection($selection);
        $email = $contact['email'];

        if ($email === '') {
            if ($strict) {
                return [
                    'userId' => 0,
                    'isNew' => false,
                    'error' => 'Укажите e-mail в данных получателя',
                ];
            }

            return [
                'userId' => 0,
                'isNew' => false,
                'error' => '',
            ];
        }

        $existingId = self::findUserIdByEmail($email);
        if ($existingId > 0) {
            self::updateUserProfileIfEmpty($existingId, $contact);

            return [
                'userId' => $existingId,
                'isNew' => false,
                'error' => '',
            ];
        }

        $created = self::registerUser($email, $contact);
        if ($created['userId'] <= 0) {
            return [
                'userId' => 0,
                'isNew' => false,
                'error' => $created['error'] !== ''
                    ? $created['error']
                    : 'Не удалось зарегистрировать пользователя',
            ];
        }

        return [
            'userId' => $created['userId'],
            'isNew' => true,
            'error' => '',
        ];
    }

    /**
     * @return array{email: string, phone: string, name: string}
     */
    public static function extractContactFromSelection(array $selection): array
    {
        $values = is_array($selection['properties'] ?? null) ? $selection['properties'] : [];

        $email = self::normalizeEmail(self::pickValue($values, self::EMAIL_CODES));
        if ($email === '') {
            $email = self::findEmailInValues($values);
        }

        return [
            'email' => $email,
            'phone' => self::pickValue($values, self::PHONE_CODES),
            'name' => self::pickValue($values, self::NAME_CODES),
        ];
    }

    private static function findEmailInValues(array $values): string
    {
        foreach ($values as $value) {
            $stringValue = trim((string)$value);
            if ($stringValue === '') {
                continue;
            }
            if (filter_var($stringValue, FILTER_VALIDATE_EMAIL)) {
                return self::normalizeEmail($stringValue);
            }
        }

        return '';
    }

    /**
     * @return array{email: string, phone: string, name: string}
     */
    public static function extractContactFromOrder(Order $order): array
    {
        $email = '';
        $phone = '';
        $name = '';

        foreach ($order->getPropertyCollection() as $propertyValue) {
            if ($propertyValue->isUtil()) {
                continue;
            }

            $prop = $propertyValue->getProperty();
            if (!is_array($prop)) {
                continue;
            }

            $code = strtoupper((string)($prop['CODE'] ?? ''));
            $value = trim((string)$propertyValue->getValue());
            if ($value === '') {
                continue;
            }

            if ($email === '' && self::isEmailProperty($prop, $code)) {
                $email = self::normalizeEmail($value);
            }
            if ($phone === '' && self::isPhoneProperty($prop, $code)) {
                $phone = $value;
            }
            if ($name === '' && self::isNameProperty($code)) {
                $name = $value;
            }
        }

        return [
            'email' => $email,
            'phone' => $phone,
            'name' => $name,
        ];
    }

    public static function grantOrderViewAccess(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        if (!isset($_SESSION['MYAPP_CATALOG_ORDER_VIEW']) || !is_array($_SESSION['MYAPP_CATALOG_ORDER_VIEW'])) {
            $_SESSION['MYAPP_CATALOG_ORDER_VIEW'] = [];
        }

        $_SESSION['MYAPP_CATALOG_ORDER_VIEW'][$orderId] = true;
    }

    public static function hasOrderViewAccess(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        return !empty($_SESSION['MYAPP_CATALOG_ORDER_VIEW'][$orderId]);
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

    /**
     * @param array{email: string, phone: string, name: string} $contact
     * @return array{userId: int, error: string}
     */
    private static function registerUser(string $email, array $contact): array
    {
        $password = Random::getString(10);
        $siteId = Context::getCurrent()->getSite() ?: SITE_ID;
        $fields = [
            'LOGIN' => $email,
            'EMAIL' => $email,
            'NAME' => $contact['name'],
            'PERSONAL_PHONE' => $contact['phone'],
            'PASSWORD' => $password,
            'CONFIRM_PASSWORD' => $password,
            'ACTIVE' => 'Y',
            'GROUP_ID' => self::resolveRegistrationGroupIds(),
        ];

        $user = new \CUser();
        $userId = (int)$user->Add($fields);
        if ($userId <= 0) {
            return [
                'userId' => 0,
                'error' => (string)$user->LAST_ERROR,
            ];
        }

        self::sendNewUserNotification($userId, $email, $contact['name'], $password, $siteId);

        return [
            'userId' => $userId,
            'error' => '',
        ];
    }

    private static function sendNewUserNotification(
        int $userId,
        string $email,
        string $name,
        string $password,
        string $siteId
    ): void {
        $eventFields = [
            'USER_ID' => $userId,
            'EMAIL' => $email,
            'LOGIN' => $email,
            'NAME' => $name,
            'PASSWORD' => $password,
            'SITE_NAME' => (string)(\COption::GetOptionString('main', 'site_name', '')),
        ];

        if (\CEvent::Send('NEW_USER', $siteId, $eventFields)) {
            return;
        }

        $user = new \CUser();
        $user->SendUserInfo($userId, $siteId, 'Вы зарегистрированы при оформлении заказа', true);
    }

    /**
     * @param array{email: string, phone: string, name: string} $contact
     */
    private static function updateUserProfileIfEmpty(int $userId, array $contact): void
    {
        $row = UserTable::getById($userId)->fetch();
        if (!$row) {
            return;
        }

        $update = [];
        if ($contact['name'] !== '' && trim((string)($row['NAME'] ?? '')) === '') {
            $update['NAME'] = $contact['name'];
        }
        if ($contact['phone'] !== '' && trim((string)($row['PERSONAL_PHONE'] ?? '')) === '') {
            $update['PERSONAL_PHONE'] = $contact['phone'];
        }

        if (empty($update)) {
            return;
        }

        $user = new \CUser();
        $user->Update($userId, $update);
    }

    /**
     * Группы из настройки главного модуля «При регистрации добавлять в группу»
     * (как у CUser::Register).
     *
     * @return list<int>
     */
    public static function resolveRegistrationGroupIds(): array
    {
        $siteId = (string)Context::getCurrent()->getSite();
        $raw = (string)Option::get('main', 'new_user_registration_def_group', '', $siteId);
        if ($raw === '') {
            return [];
        }

        $groupIds = [];
        foreach (explode(',', $raw) as $part) {
            $groupId = (int)trim($part);
            if ($groupId > 0) {
                $groupIds[] = $groupId;
            }
        }

        return array_values(array_unique($groupIds));
    }

    private static function pickValue(array $values, array $codes): string
    {
        foreach ($codes as $code) {
            foreach ([$code, strtolower($code), strtoupper($code)] as $key) {
                if (!array_key_exists($key, $values)) {
                    continue;
                }
                $stringValue = trim((string)$values[$key]);
                if ($stringValue !== '') {
                    return $stringValue;
                }
            }
        }

        foreach ($values as $key => $value) {
            $keyUpper = strtoupper((string)$key);
            foreach ($codes as $code) {
                if ($keyUpper === strtoupper($code)) {
                    $stringValue = trim((string)$value);
                    if ($stringValue !== '') {
                        return $stringValue;
                    }
                }
            }
        }

        return '';
    }

    private static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function isEmailProperty(array $prop, string $code): bool
    {
        if (($prop['IS_EMAIL'] ?? '') === 'Y') {
            return true;
        }

        return in_array($code, self::EMAIL_CODES, true);
    }

    private static function isPhoneProperty(array $prop, string $code): bool
    {
        if (($prop['IS_PHONE'] ?? '') === 'Y') {
            return true;
        }

        return in_array($code, self::PHONE_CODES, true);
    }

    private static function isNameProperty(string $code): bool
    {
        return in_array($code, self::NAME_CODES, true);
    }
}
