<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

global $APPLICATION;
$APPLICATION->ShowPanel = false;

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/routing_index.php';
