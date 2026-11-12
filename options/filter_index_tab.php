<?php

/**
 * Вкладка «Индекс фильтрации» на странице настроек модуля.
 *
 * @var array<string, mixed> $filterIndexStatus
 * @var list<array<string, mixed>> $filterIndexErrors
 * @var string $filterIndexMessage
 * @var string $filterIndexError
 * @var string $optCatalogFilterEngine
 * @var string $optProductFilterIndexAdminStepBatch
 * @var string $catalogFilterEngine
 * @var int $productFilterIndexAdminStepBatch
 * @var bool $filterIndexConfigChangedOnSave
 */

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Filter\CatalogFilterEngineFactory;
use MyApp\Catalog\Service\ProductFilterIndexJobService;

$filterIndexLang = dirname(__DIR__) . '/lang/' . LANGUAGE_ID . '/admin/filter_index.php';
if (is_file($filterIndexLang)) {
    Loc::loadLanguageFile($filterIndexLang);
}
$cronLang = dirname(__DIR__) . '/lang/' . LANGUAGE_ID . '/admin/cron_agent.php';
if (is_file($cronLang)) {
    Loc::loadLanguageFile($cronLang);
}

if (!isset(
    $filterIndexStatus,
    $filterIndexErrors,
    $optCatalogFilterEngine,
    $optProductFilterIndexAdminStepBatch,
    $catalogFilterEngine,
    $productFilterIndexAdminStepBatch
)) {
    return;
}

if (!isset($filterIndexConfigChangedOnSave)) {
    $filterIndexConfigChangedOnSave = false;
}

$moduleRoot = dirname(__DIR__);
$stats = $filterIndexStatus;
$errors = $filterIndexErrors;
$runningJob = $stats['running_job'] ?? null;
$indexJobIsRunning = is_array($runningJob)
    && (string)($runningJob['STATUS'] ?? '') === ProductFilterIndexJobService::STATUS_RUNNING;
$indexJobProcessed = $indexJobIsRunning ? (int)($runningJob['PROCESSED'] ?? 0) : 0;
$indexJobTotal = $indexJobIsRunning ? max(0, (int)($runningJob['TOTAL'] ?? 0)) : 0;
$indexJobPercent = $indexJobTotal > 0
    ? round(min(100, ($indexJobProcessed / $indexJobTotal) * 100), 1)
    : 0.0;
$indexJobStopDisabled = $indexJobIsRunning ? '' : ' disabled';

$cronBody = Loc::getMessage('MYAPP_CATALOG_CRON_AGENT_BODY');
if ($cronBody === '') {
    $cronBody = Loc::getMessage('MYAPP_CATALOG_FILTER_AGENT_CRON_BODY');
}
$cronBody = str_replace(
    ['#DOCUMENT_ROOT#', '#MODULE_ROOT#'],
    [(string)$_SERVER['DOCUMENT_ROOT'], $moduleRoot],
    $cronBody
);

$formatDateTime = static function ($value): string {
    if ($value instanceof DateTime) {
        return $value->toString();
    }
    if ($value === null || $value === '') {
        return '—';
    }

    return (string)$value;
};

$readyLabel = ($stats['index_ready'] ?? false)
    ? Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_YES')
    : Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_NO');
$partialSectionId = (int)($stats['partial_section_id'] ?? 0);
$partialIndexLabel = $partialSectionId > 0
    ? str_replace('#ID#', (string)$partialSectionId, Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_PARTIAL_WARNING'))
    : '—';
$indexConfigStale = !empty($stats['index_config_stale']);
$showIndexConfigStaleBanner = ($indexConfigStale || $filterIndexConfigChangedOnSave) && !$indexJobIsRunning;
$indexConfigStaleMessage = $filterIndexConfigChangedOnSave
    ? Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_CONFIG_STALE_AFTER_SAVE')
    : Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_CONFIG_STALE');
?>
<tr class="heading">
    <td colspan="2"><?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_CRON_AGENT_TITLE')) ?></td>
</tr>
<tr>
    <td colspan="2" class="adm-detail-content-cell-l">
        <div class="adm-info-message-wrap adm-info-message-yellow">
            <div class="adm-info-message">
                <div class="adm-info-message-body"><?= nl2br(htmlspecialcharsbx($cronBody)) ?></div>
            </div>
        </div>
    </td>
</tr>

<tr class="heading">
    <td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_STATS_HEADING') ?></td>
</tr>
<?php if ($showIndexConfigStaleBanner): ?>
<tr data-myapp-index-config-stale-wrap>
    <td colspan="2" class="adm-detail-content-cell-l">
        <div class="adm-info-message-wrap adm-info-message-yellow">
            <div class="adm-info-message">
                <div class="adm-info-message-body"><?= nl2br(htmlspecialcharsbx($indexConfigStaleMessage)) ?></div>
            </div>
        </div>
    </td>
</tr>
<?php endif; ?>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_ENGINE') ?>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_ENGINE_HINT') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <select name="<?= htmlspecialcharsbx($optCatalogFilterEngine) ?>" id="MYAPP_CATALOG_FILTER_ENGINE">
            <option value="<?= CatalogFilterEngineFactory::ENGINE_LEGACY ?>" <?= $catalogFilterEngine === CatalogFilterEngineFactory::ENGINE_LEGACY ? 'selected' : '' ?>>
                <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_ENGINE_LEGACY')) ?>
            </option>
            <option value="<?= CatalogFilterEngineFactory::ENGINE_DENORMALIZED ?>" <?= $catalogFilterEngine === CatalogFilterEngineFactory::ENGINE_DENORMALIZED ? 'selected' : '' ?>>
                <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_ENGINE_DENORMALIZED')) ?>
            </option>
            <option value="<?= CatalogFilterEngineFactory::ENGINE_BITMAP ?>" <?= $catalogFilterEngine === CatalogFilterEngineFactory::ENGINE_BITMAP ? 'selected' : '' ?>>
                <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_ENGINE_BITMAP')) ?>
            </option>
        </select>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_STEP_BATCH') ?>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_STEP_BATCH_HINT') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <input
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            id="MYAPP_CATALOG_FILTER_INDEX_STEP_BATCH"
            name="<?= htmlspecialcharsbx($optProductFilterIndexAdminStepBatch) ?>"
            data-myapp-index-step-batch
            value="<?= (int)$productFilterIndexAdminStepBatch ?>"
            size="5"
            maxlength="3"
        >
    </td>
</tr>
<?php
$statKeys = [
    'index_ready' => 'MYAPP_CATALOG_FILTER_INDEX_READY',
    'index_version' => 'MYAPP_CATALOG_FILTER_INDEX_VERSION',
    'config_hash' => 'MYAPP_CATALOG_FILTER_INDEX_CONFIG_HASH',
    'expected_config_hash' => 'MYAPP_CATALOG_FILTER_INDEX_CONFIG_EXPECTED',
    'products_count' => 'MYAPP_CATALOG_FILTER_INDEX_PRODUCTS',
    'values_count' => 'MYAPP_CATALOG_FILTER_INDEX_VALUES',
    'indexed_properties_count' => 'MYAPP_CATALOG_FILTER_INDEX_PROPERTIES',
    'queue_size' => 'MYAPP_CATALOG_FILTER_INDEX_QUEUE',
    'last_full_sync_at' => 'MYAPP_CATALOG_FILTER_INDEX_LAST_SYNC',
    'partial_section_id' => 'MYAPP_CATALOG_FILTER_INDEX_PARTIAL',
];
$initialStatValues = [
    'index_ready' => $readyLabel,
    'index_version' => (string)(int)($stats['index_version'] ?? 0),
    'config_hash' => (string)($stats['config_hash'] ?? ''),
    'expected_config_hash' => (string)($stats['expected_config_hash'] ?? ''),
    'products_count' => (string)(int)($stats['products_count'] ?? 0),
    'values_count' => (string)(int)($stats['values_count'] ?? 0),
    'indexed_properties_count' => (string)(int)($stats['indexed_properties_count'] ?? 0),
    'queue_size' => (string)(int)($stats['queue_size'] ?? 0),
    'last_full_sync_at' => $formatDateTime($stats['last_full_sync_at'] ?? null),
    'partial_section_id' => $partialIndexLabel,
];
foreach ($statKeys as $statKey => $msgKey): ?>
    <tr data-myapp-stat="<?= htmlspecialcharsbx($statKey) ?>">
        <td width="50%" class="adm-detail-content-cell-l"><?= Loc::getMessage($msgKey) ?></td>
        <td width="50%" class="adm-detail-content-cell-r" data-myapp-stat-value>
            <?= htmlspecialcharsbx($initialStatValues[$statKey] ?? '—') ?>
        </td>
    </tr>
<?php endforeach; ?>
<?php if ($partialSectionId > 0): ?>
<tr>
    <td colspan="2" class="adm-detail-content-cell-l">
        <div class="adm-info-message-wrap adm-info-message-yellow">
            <div class="adm-info-message">
                <div class="adm-info-message-body"><?= htmlspecialcharsbx($partialIndexLabel) ?></div>
            </div>
        </div>
    </td>
</tr>
<?php endif; ?>
<tr>
    <td colspan="2" class="adm-detail-content-cell-l" data-myapp-jobs-root="index">
        <div class="myapp-filter-job-message" style="display:none;margin-bottom:12px;"></div>
        <div class="myapp-filter-job-progress" style="<?= $indexJobIsRunning ? '' : 'display:none;' ?>margin-bottom:12px;">
            <div class="myapp-filter-job-progress__track">
                <div class="myapp-filter-job-progress__bar" style="width:<?= $indexJobIsRunning ? (float)$indexJobPercent : 0 ?>%;"></div>
            </div>
            <div class="myapp-filter-job-progress__label">
                <?php if ($indexJobIsRunning): ?>
                    <?= (float)$indexJobPercent ?>% — <?= $indexJobProcessed ?> / <?= $indexJobTotal ?>
                    (<?= htmlspecialcharsbx((string)($runningJob['TYPE'] ?? '')) ?>)
                <?php else: ?>
                    0%
                <?php endif; ?>
            </div>
        </div>
        <div style="margin-top:10px;margin-bottom:12px;">
            <button type="button" class="adm-btn myapp-filter-job-stop" data-myapp-job-cancel<?= $indexJobStopDisabled ?>>
                <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_BTN_STOP')) ?>
            </button>
        </div>
        <div class="myapp-filter-job-progress__hint adm-info-message-wrap adm-info-message-yellow" style="<?= $indexJobIsRunning ? '' : 'display:none;' ?>margin-bottom:12px;">
            <div class="adm-info-message">
                <div class="adm-info-message-body"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_JOB_KEEP_TAB') ?></div>
            </div>
        </div>
    </td>
</tr>

<tr class="heading">
    <td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_ACTIONS_HEADING') ?></td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_BTN_FULL') ?></td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn-save" data-myapp-job-start="full">
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_BTN_FULL')) ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <label for="myapp_filter_index_section_id"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_SECTION_ID') ?></label>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_SECTION_HINT') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <input
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            id="myapp_filter_index_section_id"
            data-myapp-section-id
            value="0"
            size="10"
            maxlength="10"
        >
        <button type="button" class="adm-btn" data-myapp-job-start="section">
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_BTN_SECTION')) ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_BTN_CLEAR') ?>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_CONFIRM_CLEAR') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button
            type="button"
            class="adm-btn"
            data-myapp-job-clear
            data-confirm="<?= CUtil::JSEscape(Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_CONFIRM_CLEAR')) ?>"
        >
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_BTN_CLEAR')) ?>
        </button>
    </td>
</tr>

<?php if ($errors !== []): ?>
    <tr class="heading">
        <td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_ERRORS_TITLE') ?></td>
    </tr>
    <tr>
        <td colspan="2">
            <div class="adm-list-table-wrap adm-list-table-with-border">
                <table class="adm-list-table">
                    <thead>
                        <tr class="adm-list-table-header">
                            <td class="adm-list-table-cell adm-list-table-cell-left">
                                <div class="adm-list-table-cell-inner"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_ERR_COL_PRODUCT') ?></div>
                            </td>
                            <td class="adm-list-table-cell adm-list-table-cell-left">
                                <div class="adm-list-table-cell-inner"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_ERR_COL_UPDATED') ?></div>
                            </td>
                            <td class="adm-list-table-cell adm-list-table-cell-left">
                                <div class="adm-list-table-cell-inner"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_ERR_COL_ERROR') ?></div>
                            </td>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $row): ?>
                            <tr class="adm-list-table-row">
                                <td class="adm-list-table-cell adm-list-table-cell-left">
                                    <div class="adm-list-table-cell-inner"><?= (int)$row['PRODUCT_ID'] ?></div>
                                </td>
                                <td class="adm-list-table-cell adm-list-table-cell-left">
                                    <div class="adm-list-table-cell-inner"><?= htmlspecialcharsbx($formatDateTime($row['UPDATED_AT'] ?? null)) ?></div>
                                </td>
                                <td class="adm-list-table-cell adm-list-table-cell-left">
                                    <div class="adm-list-table-cell-inner"><?= htmlspecialcharsbx((string)($row['LAST_ERROR'] ?? '')) ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </td>
    </tr>
<?php endif; ?>
