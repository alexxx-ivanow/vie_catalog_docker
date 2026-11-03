<?php

require __DIR__ . '/cron_agent.php';

$MESS['MYAPP_CATALOG_FILTER_INDEX_STATS_HEADING'] = 'Статистика индекса';
$MESS['MYAPP_CATALOG_FILTER_INDEX_ACTIONS_HEADING'] = 'Действия';
$MESS['MYAPP_CATALOG_FILTER_INDEX_USE'] = 'Использовать индекс для выборки товаров';
$MESS['MYAPP_CATALOG_FILTER_INDEX_USE_HINT'] = 'Если включено и индекс готов — AJAX catalog/goods использует денормализованные таблицы вместо JOIN по свойствам Bitrix.';
$MESS['MYAPP_CATALOG_FILTER_INDEX_VERSION'] = 'Версия индекса';
$MESS['MYAPP_CATALOG_FILTER_INDEX_CONFIG_HASH'] = 'Хеш конфигурации (в индексе)';
$MESS['MYAPP_CATALOG_FILTER_INDEX_CONFIG_EXPECTED'] = 'Ожидаемый хеш конфигурации';
$MESS['MYAPP_CATALOG_FILTER_INDEX_READY'] = 'Индекс готов';
$MESS['MYAPP_CATALOG_FILTER_INDEX_PRODUCTS'] = 'Товаров в индексе';
$MESS['MYAPP_CATALOG_FILTER_INDEX_VALUES'] = 'Значений свойств';
$MESS['MYAPP_CATALOG_FILTER_INDEX_PROPERTIES'] = 'Свойств в настройках фильтра';
$MESS['MYAPP_CATALOG_FILTER_INDEX_QUEUE'] = 'В очереди';
$MESS['MYAPP_CATALOG_FILTER_INDEX_LAST_SYNC'] = 'Последняя полная синхронизация';
$MESS['MYAPP_CATALOG_FILTER_INDEX_BTN_FULL'] = 'Полное построение индекса';
// Кнопка sync убрана из UI; строка оставлена для совместимости lang.
$MESS['MYAPP_CATALOG_FILTER_INDEX_BTN_SYNC'] = 'Синхронизация с настройками';
$MESS['MYAPP_CATALOG_FILTER_INDEX_CONFIG_STALE'] = 'Настройки индекса (инфоблок, свойства фильтра, артикул) не совпадают с построенным индексом. API не использует индекс, пока он не пересобран. Выполните «Полное построение индекса» и оставьте эту вкладку открытой до 100% (на большом каталоге — порядка 20–30 минут). При необходимости сначала «Очистить индекс».';
$MESS['MYAPP_CATALOG_FILTER_INDEX_CONFIG_STALE_AFTER_SAVE'] = 'Настройки индекса сохранены; индекс устарел и не используется в API. Выполните «Полное построение индекса» на этой вкладке и оставьте её открытой до 100% (на большом каталоге — порядка 20–30 минут).';
$MESS['MYAPP_CATALOG_FILTER_INDEX_BTN_CLEAR'] = 'Очистить индекс';
$MESS['MYAPP_CATALOG_FILTER_INDEX_BTN_CANCEL'] = 'Остановить задачу';
$MESS['MYAPP_CATALOG_FILTER_INDEX_BTN_STOP'] = 'Остановить построение индекса';
$MESS['MYAPP_CATALOG_FILTER_INDEX_STEP_BATCH'] = 'Товаров за один HTTP‑запрос (админка)';
$MESS['MYAPP_CATALOG_FILTER_INDEX_STEP_BATCH_HINT'] = 'Сколько товаров обрабатывается за один AJAX‑шаг при открытой вкладке. От 1 до 120. По умолчанию 30. Сохраняется кнопкой «Сохранить» внизу страницы; при построении можно менять значение без сохранения.';
$MESS['MYAPP_CATALOG_FILTER_INDEX_JOB_RUNNING'] = 'Выполняется задача';
$MESS['MYAPP_CATALOG_FILTER_INDEX_JOB_PROGRESS'] = 'Прогресс';
$MESS['MYAPP_CATALOG_FILTER_INDEX_JOB_PRODUCT'] = 'текущий товар';
$MESS['MYAPP_CATALOG_FILTER_INDEX_ERRORS_TITLE'] = 'Последние ошибки индексации';
$MESS['MYAPP_CATALOG_FILTER_INDEX_ERR_COL_PRODUCT'] = 'ID товара';
$MESS['MYAPP_CATALOG_FILTER_INDEX_ERR_COL_UPDATED'] = 'Обновлено';
$MESS['MYAPP_CATALOG_FILTER_INDEX_ERR_COL_ERROR'] = 'Ошибка';
$MESS['MYAPP_CATALOG_FILTER_INDEX_CONFIRM_CLEAR'] = 'Удалить все данные индекса и очередь?';
$MESS['MYAPP_CATALOG_FILTER_INDEX_SAVED'] = 'Действие выполнено';
$MESS['MYAPP_CATALOG_FILTER_INDEX_ERR'] = 'Ошибка';
$MESS['MYAPP_CATALOG_FILTER_JOB_KEEP_TAB'] = 'Идёт фоновая задача. Можно оставить вкладку открытой — прогресс обновляется автоматически. На продакшене очередь также обрабатывает cron.';
$MESS['MYAPP_CATALOG_FILTER_INDEX_YES'] = 'Да';
$MESS['MYAPP_CATALOG_FILTER_INDEX_NO'] = 'Нет';
$MESS['MYAPP_CATALOG_FILTER_INDEX_SECTION_ID'] = 'ID раздела каталога';
$MESS['MYAPP_CATALOG_FILTER_INDEX_BTN_SECTION'] = 'Построить индекс для раздела';
$MESS['MYAPP_CATALOG_FILTER_INDEX_SECTION_HINT'] = 'Индексируются активные товары в выбранном разделе и всех вложенных (поддерево). Для чистого теста без «хвостов» от прошлого full сначала очистите индекс или выполните полное построение. После успеха индекс помечается готовым с предупреждением о частичном покрытии — перед продакшеном нужно полное построение.';
$MESS['MYAPP_CATALOG_FILTER_INDEX_PARTIAL'] = 'Частичный индекс (раздел)';
$MESS['MYAPP_CATALOG_FILTER_INDEX_PARTIAL_WARNING'] = 'Частичный индекс: построено для раздела #ID# и вложенных. Перед продакшеном выполните полное построение.';
$MESS['MYAPP_CATALOG_FILTER_INDEX_SECTION_ID_REQUIRED'] = 'Укажите ID раздела больше 0';
