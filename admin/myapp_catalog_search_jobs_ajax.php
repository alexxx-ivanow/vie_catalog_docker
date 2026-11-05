<?php

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$documentRoot = (string)$_SERVER['DOCUMENT_ROOT'];
$candidates = [
    $documentRoot . '/local/modules/myapp.catalog/admin/ajax_search_jobs.php',
    $documentRoot . '/bitrix/modules/myapp.catalog/admin/ajax_search_jobs.php',
];

$ajaxLogic = null;
foreach ($candidates as $path) {
    if (is_file($path)) {
        $ajaxLogic = $path;
        break;
    }
}

if ($ajaxLogic === null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Module ajax handler not found (myapp.catalog/admin/ajax_search_jobs.php)',
    ], JSON_UNESCAPED_UNICODE);
    die();
}

require $ajaxLogic;
