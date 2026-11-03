<?php

use Bitrix\Main\Loader;

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../../..');
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!Loader::includeModule('myapp.catalog')) {
    fwrite(STDERR, "Module myapp.catalog not loaded\n");
    exit(1);
}

$batch = 10;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--batch=')) {
        $batch = max(1, (int)substr($arg, 8));
    }
}

$processed = \MyApp\Catalog\Service\FilterRebuildJobService::runStep($batch);
echo "Processed sections: {$processed}\n";
