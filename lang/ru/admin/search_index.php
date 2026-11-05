<?php

require __DIR__ . '/cron_agent.php';

$MESS['MYAPP_CATALOG_SEARCH_INDEX_STATS_HEADING'] = 'Статистика поискового индекса';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_ACTIONS_HEADING'] = 'Действия';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_PRODUCT_PROPS'] = 'Свойства товара для индекса';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_PRODUCT_PROPS_HINT'] = 'Коды свойств родительского товара (строка, число, список). NAME всегда индексируется.';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_OFFER_PROPS'] = 'Свойства ТП для индекса';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_OFFER_PROPS_HINT'] = 'Значения всех активных офферов сливаются в документ родителя. PREVIEW/DETAIL офферов не индексируются.';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_PREVIEW'] = 'Индексировать PREVIEW_TEXT товара';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_DETAIL'] = 'Индексировать DETAIL_TEXT товара';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_VERSION'] = 'Версия индекса';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_CONFIG_HASH'] = 'Хеш конфигурации (в индексе)';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_CONFIG_EXPECTED'] = 'Ожидаемый хеш конфигурации';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_READY'] = 'Поиск включён';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_DOCUMENTS'] = 'Документов в индексе';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_TOKENS'] = 'Токенов в индексе';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_QUEUE'] = 'В очереди';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_LAST_SYNC'] = 'Последняя полная синхронизация';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_BTN_FULL'] = 'Полное переиндексирование';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_BTN_CLEAR'] = 'Очистить индекс';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_BTN_STOP'] = 'Остановить переиндексирование';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_STEP_BATCH'] = 'Товаров за один HTTP‑запрос (админка)';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_STEP_BATCH_HINT'] = 'От 1 до 120. Сохраняется кнопкой «Сохранить» внизу страницы.';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_CONFIG_STALE'] = 'Настройки поиска изменились — индекс устарел. Поиск на сайте недоступен, пока не выполните полное переиндексирование.';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_CONFIG_STALE_AFTER_SAVE'] = 'Настройки поиска сохранены; индекс помечен как устаревший. Выполните полное переиндексирование на этой вкладке.';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_CONFIRM_CLEAR'] = 'Удалить все данные поискового индекса и очередь?';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_ERRORS_TITLE'] = 'Последние ошибки индексации';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_ERR_COL_PRODUCT'] = 'ID товара';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_ERR_COL_UPDATED'] = 'Обновлено';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_ERR_COL_ERROR'] = 'Ошибка';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_YES'] = 'Да';
$MESS['MYAPP_CATALOG_SEARCH_INDEX_NO'] = 'Нет';
$MESS['MYAPP_CATALOG_SEARCH_CRON_WORKER'] = 'CLI: php #MODULE_ROOT#/tools/catalog_search_index_worker.php --batch=20';
