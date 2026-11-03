<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

$lang = isset($_REQUEST['lang']) ? (string)$_REQUEST['lang'] : LANGUAGE_ID;
LocalRedirect('/bitrix/admin/settings.php?mid=myapp.catalog&lang=' . urlencode($lang) . '&tabControl_active_tab=edit2');
