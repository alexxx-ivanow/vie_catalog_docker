<?php

namespace MyApp\Catalog\Install;

use Bitrix\Main\Config\Option;

final class CatalogSiteBootstrapInstaller
{
    private const MODULE_ID = 'myapp.catalog';

    private const OPT_MANAGED = 'root_index_managed';

    private const OPT_BACKUP = 'root_index_backup_path';

    private const ROUTING_MARKER = 'bitrix/routing_index.php';

    public static function ensureRootIndex(?string $documentRoot = null): void
    {
        $documentRoot = $documentRoot ?? (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($documentRoot === '') {
            return;
        }

        $target = $documentRoot . '/index.php';
        $source = dirname(__DIR__, 2) . '/install/files/public/index.php';
        if (!is_file($source)) {
            return;
        }

        $canonical = (string)file_get_contents($source);
        $canonical = self::normalizeNewlines($canonical);

        if (is_file($target)) {
            $current = self::normalizeNewlines((string)file_get_contents($target));
            if (str_contains($current, self::ROUTING_MARKER)) {
                Option::set(self::MODULE_ID, self::OPT_MANAGED, 'Y');

                return;
            }

            $backupName = 'index.php.bak.myapp_catalog.' . date('YmdHis');
            $backupPath = $documentRoot . '/' . $backupName;
            if (@copy($target, $backupPath)) {
                Option::set(self::MODULE_ID, self::OPT_BACKUP, $backupName);
            }
        }

        if (@file_put_contents($target, $canonical) !== false) {
            Option::set(self::MODULE_ID, self::OPT_MANAGED, 'Y');
        }
    }

    public static function tryRestoreRootIndex(?string $documentRoot = null): void
    {
        if (Option::get(self::MODULE_ID, self::OPT_MANAGED, 'N') !== 'Y') {
            return;
        }

        $documentRoot = $documentRoot ?? (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($documentRoot === '') {
            return;
        }

        $source = dirname(__DIR__, 2) . '/install/files/public/index.php';
        $target = $documentRoot . '/index.php';
        if (!is_file($source) || !is_file($target)) {
            return;
        }

        $canonical = self::normalizeNewlines((string)file_get_contents($source));
        $current = self::normalizeNewlines((string)file_get_contents($target));
        if ($current !== $canonical) {
            return;
        }

        $backupName = (string)Option::get(self::MODULE_ID, self::OPT_BACKUP, '');
        $backupPath = $backupName !== '' ? $documentRoot . '/' . $backupName : '';
        if ($backupPath !== '' && is_file($backupPath)) {
            @copy($backupPath, $target);
            @unlink($backupPath);
        }

        Option::set(self::MODULE_ID, self::OPT_MANAGED, '');
        Option::set(self::MODULE_ID, self::OPT_BACKUP, '');
    }

    private static function normalizeNewlines(string $content): string
    {
        return rtrim(str_replace("\r\n", "\n", $content)) . "\n";
    }
}
