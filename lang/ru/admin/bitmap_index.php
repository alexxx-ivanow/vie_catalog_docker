<?php

require __DIR__ . '/cron_agent.php';

$MESS['MYAPP_CATALOG_BITMAP_INDEX_STATS_HEADING'] = 'Статистика Bitmap Index';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_ACTIONS_HEADING'] = 'Действия';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_ENGINE'] = 'Активный движок фильтрации';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_ENGINE_HINT'] = 'Выбор только на вкладке «Индекс фильтрации». Здесь показано текущее значение. Инкрементальные обновления bitmap выполняются только если активен bitmap (или идёт job построения).';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_ENGINE_ACTIVE'] = 'активен; инвалидация обновляет bitmap';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_ENGINE_INACTIVE'] = 'не активен; смена товара не пишет в bitmap (кроме ручного построения)';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_ENGINE_LEGACY'] = 'legacy (JOIN Bitrix)';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_ENGINE_DENORMALIZED'] = 'denormalized (таблицы filter_*)';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_ENGINE_BITMAP'] = 'bitmap (побитовый индекс)';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_STEP_BATCH'] = 'Товаров за один HTTP‑запрос (админка)';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_STEP_BATCH_HINT'] = 'Сколько товаров обрабатывается за один AJAX‑шаг при открытой вкладке. От 1 до 120. По умолчанию 20. Сохраняется кнопкой «Сохранить» внизу страницы; при построении можно менять значение без сохранения.';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_READY'] = 'Bitmap индекс готов';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_VERSION'] = 'Версия индекса';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_STATUS'] = 'Статус';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_PRODUCTS'] = 'Проиндексировано товаров';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_PRODUCTS_TOTAL'] = 'Активных товаров в каталоге';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_MASKS'] = 'Масок (значений)';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_BLOB'] = 'Объём масок, байт';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_MAX_POS'] = 'Макс. BITMAP_POSITION';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_QUEUE'] = 'В очереди';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_LAST_SYNC'] = 'Последняя полная индексация';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_CONFIG_HASH'] = 'Хеш конфигурации (в индексе)';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_CONFIG_EXPECTED'] = 'Ожидаемый хеш конфигурации';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_BTN_FULL'] = 'Полное построение Bitmap Index';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_BTN_CLEAR'] = 'Очистить Bitmap Index';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_BTN_STOP'] = 'Остановить построение';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_BTN_VALIDATE'] = 'Проверить целостность';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_BTN_REPAIR'] = 'Восстановить (full rebuild)';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_CONFIRM_CLEAR'] = 'Удалить все данные bitmap-индекса и очередь?';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_CONFIG_STALE'] = 'Конфигурация фильтра изменилась. Bitmap Index не используется, пока не выполнено полное построение.';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_YES'] = 'Да';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_NO'] = 'Нет';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_SAVED'] = 'Действие выполнено';
$MESS['MYAPP_CATALOG_BITMAP_INDEX_ERR'] = 'Ошибка';
$MESS['MYAPP_CATALOG_BITMAP_JOB_KEEP_TAB'] = 'Идёт построение Bitmap Index. Оставьте вкладку открытой или запустите CLI-воркер.';
