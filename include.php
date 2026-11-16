<?php

use Bitrix\Main\Loader;

Loader::registerNamespace('MyApp\\Catalog', __DIR__ . '/lib');

\MyApp\Catalog\Event\CatalogCacheHandler::ensurePersistentHandlers();
\MyApp\Catalog\Install\FilterAggregateSchema::ensureRebuildInfrastructure();
\MyApp\Catalog\Install\ProductFilterIndexSchema::ensureInfrastructure();
\MyApp\Catalog\Install\BitmapIndexSchema::ensureInfrastructure();
\MyApp\Catalog\Install\CatalogSearchIndexSchema::ensureInfrastructure();
\MyApp\Catalog\Install\CatalogContentIblockInstaller::ensureInfrastructure();
\MyApp\Catalog\Service\FilterRebuildJobService::ensureAgentRegistered();
\MyApp\Catalog\Service\ProductFilterIndexJobService::ensureAgentRegistered();
\MyApp\Catalog\Service\BitmapIndexJobService::ensureAgentRegistered();
\MyApp\Catalog\Service\CatalogSearchIndexJobService::ensureAgentRegistered();