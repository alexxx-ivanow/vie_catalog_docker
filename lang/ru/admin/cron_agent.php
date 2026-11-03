<?php

$MESS['MYAPP_CATALOG_CRON_AGENT_TITLE'] = 'Агенты Bitrix и cron';
$MESS['MYAPP_CATALOG_CRON_AGENT_BODY'] = 'Фоновые задачи модуля myapp.catalog обрабатываются агентами (регистрируются при установке/обновлении). На продакшене включите выполнение агентов через cron в настройках продукта и настройте системный cron, например каждую минуту: php #DOCUMENT_ROOT#/bitrix/php_interface/cron_events.php. Без cron при малом трафике очередь может не обрабатываться. Проверьте записи агентов в списке агентов. Дополнительно (ручной запуск очереди): php #MODULE_ROOT#/tools/filter_rebuild_worker.php --batch=20 и php #MODULE_ROOT#/tools/product_filter_index_worker.php --batch=20';
