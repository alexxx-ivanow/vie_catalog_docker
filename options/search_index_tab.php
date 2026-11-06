<?php

/**
 * @var array<string, mixed> $searchIndexStatus
 * @var list<array<string, mixed>> $searchIndexErrors
 * @var string $optSearchProductPropertyCodes
 * @var string $optSearchOfferPropertyCodes
 * @var string $optIndexProductPreviewText
 * @var string $optIndexProductDetailText
 * @var string $optSearchIndexAdminStepBatch
 * @var list<string> $searchProductPropertyCodes
 * @var list<string> $searchOfferPropertyCodes
 * @var bool $indexProductPreviewText
 * @var bool $indexProductDetailText
 * @var int $searchIndexAdminStepBatch
 * @var bool $searchIndexConfigChangedOnSave
 * @var string $optCatalogSearchBackend
 * @var string $catalogSearchBackend
 */

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Service\CatalogSearchIndexJobService;

$searchLang = dirname(__DIR__) . '/lang/' . LANGUAGE_ID . '/admin/search_index.php';
if (is_file($searchLang)) {
    Loc::loadLanguageFile($searchLang);
}

if (!isset($searchIndexStatus, $searchIndexErrors, $searchProductPropertyCodes, $searchOfferPropertyCodes)) {
    return;
}

if (!isset($optCatalogSearchBackend, $catalogSearchBackend)) {
    return;
}

if (!isset($searchIndexConfigChangedOnSave)) {
    $searchIndexConfigChangedOnSave = false;
}

$moduleRoot = dirname(__DIR__);
$stats = $searchIndexStatus;
$errors = $searchIndexErrors;
$runningJob = $stats['running_job'] ?? null;
$indexJobIsRunning = is_array($runningJob)
    && (string)($runningJob['STATUS'] ?? '') === CatalogSearchIndexJobService::STATUS_RUNNING;
$indexJobProcessed = $indexJobIsRunning ? (int)($runningJob['PROCESSED'] ?? 0) : 0;
$indexJobTotal = $indexJobIsRunning ? max(0, (int)($runningJob['TOTAL'] ?? 0)) : 0;
$indexJobPercent = $indexJobTotal > 0
    ? round(min(100, ($indexJobProcessed / $indexJobTotal) * 100), 1)
    : 0.0;
$indexJobStopDisabled = $indexJobIsRunning ? '' : ' disabled';

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
    ? Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_YES')
    : Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_NO');
$indexConfigStale = !empty($stats['index_config_stale']);
$showIndexConfigStaleBanner = ($indexConfigStale || $searchIndexConfigChangedOnSave) && !$indexJobIsRunning;
$indexConfigStaleMessage = $searchIndexConfigChangedOnSave
    ? Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_CONFIG_STALE_AFTER_SAVE')
    : Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_CONFIG_STALE');

$cronWorker = str_replace('#MODULE_ROOT#', $moduleRoot, Loc::getMessage('MYAPP_CATALOG_SEARCH_CRON_WORKER'));
?>
<tr class="heading">
    <td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_STATS_HEADING') ?></td>
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
    <td width="50%" class="adm-detail-content-cell-l" style="vertical-align: top;">
        <?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_PRODUCT_PROPS') ?>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_PRODUCT_PROPS_HINT') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <div id="myapp-search-product-props">
            <?php foreach ($searchProductPropertyCodes as $propertyCode): ?>
                <div class="myapp-catalog-prop-row" style="margin-bottom: 8px;">
                    <input
                        type="text"
                        name="<?= htmlspecialcharsbx($optSearchProductPropertyCodes) ?>[]"
                        value="<?= htmlspecialcharsbx($propertyCode) ?>"
                        maxlength="50"
                    >
                    <button type="button" class="adm-btn myapp-catalog-prop-remove">
                        <?= Loc::getMessage('MYAPP_CATALOG_OPT_FILTER_PROPS_REMOVE') ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="adm-btn" id="myapp-search-product-prop-add">
            <?= Loc::getMessage('MYAPP_CATALOG_OPT_FILTER_PROPS_ADD') ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l" style="vertical-align: top;">
        <?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_OFFER_PROPS') ?>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_OFFER_PROPS_HINT') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <div id="myapp-search-offer-props">
            <?php foreach ($searchOfferPropertyCodes as $propertyCode): ?>
                <div class="myapp-catalog-prop-row" style="margin-bottom: 8px;">
                    <input
                        type="text"
                        name="<?= htmlspecialcharsbx($optSearchOfferPropertyCodes) ?>[]"
                        value="<?= htmlspecialcharsbx($propertyCode) ?>"
                        maxlength="50"
                    >
                    <button type="button" class="adm-btn myapp-catalog-prop-remove">
                        <?= Loc::getMessage('MYAPP_CATALOG_OPT_FILTER_PROPS_REMOVE') ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="adm-btn" id="myapp-search-offer-prop-add">
            <?= Loc::getMessage('MYAPP_CATALOG_OPT_FILTER_PROPS_ADD') ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_SEARCH_BACKEND') ?>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_BACKEND_HINT') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <select name="<?= htmlspecialcharsbx($optCatalogSearchBackend) ?>" id="MYAPP_CATALOG_SEARCH_BACKEND">
            <option
                value="mysql_lexeme_trigram"
                <?= $catalogSearchBackend === 'mysql_lexeme_trigram' ? 'selected' : '' ?>
            ><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_BACKEND_MYSQL_TRIGRAM') ?></option>
            <option
                value="legacy_token"
                <?= $catalogSearchBackend === 'legacy_token' ? 'selected' : '' ?>
            ><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_BACKEND_LEGACY') ?></option>
        </select>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_PREVIEW') ?>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <input
            type="checkbox"
            class="adm-designed-checkbox"
            id="MYAPP_CATALOG_SEARCH_INDEX_PREVIEW"
            name="<?= htmlspecialcharsbx($optIndexProductPreviewText) ?>"
            value="Y"
            <?= $indexProductPreviewText ? 'checked' : '' ?>
        >
        <label class="adm-designed-checkbox-label" for="MYAPP_CATALOG_SEARCH_INDEX_PREVIEW"></label>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_DETAIL') ?>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <input
            type="checkbox"
            class="adm-designed-checkbox"
            id="MYAPP_CATALOG_SEARCH_INDEX_DETAIL"
            name="<?= htmlspecialcharsbx($optIndexProductDetailText) ?>"
            value="Y"
            <?= $indexProductDetailText ? 'checked' : '' ?>
        >
        <label class="adm-designed-checkbox-label" for="MYAPP_CATALOG_SEARCH_INDEX_DETAIL"></label>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_STEP_BATCH') ?>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_STEP_BATCH_HINT') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <input
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            data-myapp-search-step-batch
            name="<?= htmlspecialcharsbx($optSearchIndexAdminStepBatch) ?>"
            value="<?= (int)$searchIndexAdminStepBatch ?>"
            size="5"
            maxlength="3"
        >
    </td>
</tr>
<?php
$statKeys = [
    'index_ready' => 'MYAPP_CATALOG_SEARCH_INDEX_READY',
    'search_backend' => 'MYAPP_CATALOG_SEARCH_BACKEND_STAT',
    'index_version' => 'MYAPP_CATALOG_SEARCH_INDEX_VERSION',
    'config_hash' => 'MYAPP_CATALOG_SEARCH_INDEX_CONFIG_HASH',
    'expected_config_hash' => 'MYAPP_CATALOG_SEARCH_INDEX_CONFIG_EXPECTED',
    'documents_count' => 'MYAPP_CATALOG_SEARCH_INDEX_DOCUMENTS',
    'tokens_count' => 'MYAPP_CATALOG_SEARCH_INDEX_TOKENS',
    'queue_size' => 'MYAPP_CATALOG_SEARCH_INDEX_QUEUE',
    'last_full_sync_at' => 'MYAPP_CATALOG_SEARCH_INDEX_LAST_SYNC',
];
$initialStatValues = [
    'index_ready' => $readyLabel,
    'search_backend' => (string)($stats['search_backend'] ?? ''),
    'index_version' => (string)(int)($stats['index_version'] ?? 0),
    'config_hash' => (string)($stats['config_hash'] ?? ''),
    'expected_config_hash' => (string)($stats['expected_config_hash'] ?? ''),
    'documents_count' => (string)(int)($stats['documents_count'] ?? 0),
    'tokens_count' => (string)(int)($stats['tokens_count'] ?? 0),
    'queue_size' => (string)(int)($stats['queue_size'] ?? 0),
    'last_full_sync_at' => $formatDateTime($stats['last_full_sync_at'] ?? null),
];
foreach ($statKeys as $statKey => $msgKey): ?>
    <tr data-myapp-stat="<?= htmlspecialcharsbx($statKey) ?>">
        <td width="50%" class="adm-detail-content-cell-l"><?= Loc::getMessage($msgKey) ?></td>
        <td width="50%" class="adm-detail-content-cell-r" data-myapp-stat-value>
            <?= htmlspecialcharsbx($initialStatValues[$statKey] ?? '—') ?>
        </td>
    </tr>
<?php endforeach; ?>
<tr>
    <td colspan="2" class="adm-detail-content-cell-l" data-myapp-jobs-root="search">
        <div class="myapp-filter-job-message" style="display:none;margin-bottom:12px;"></div>
        <div class="myapp-filter-job-progress" style="<?= $indexJobIsRunning ? '' : 'display:none;' ?>margin-bottom:12px;">
            <div class="myapp-filter-job-progress__track">
                <div class="myapp-filter-job-progress__bar" style="width:<?= $indexJobIsRunning ? (float)$indexJobPercent : 0 ?>%;"></div>
            </div>
            <div class="myapp-filter-job-progress__label">
                <?php if ($indexJobIsRunning): ?>
                    <?= (float)$indexJobPercent ?>% — <?= $indexJobProcessed ?> / <?= $indexJobTotal ?>
                <?php else: ?>
                    0%
                <?php endif; ?>
            </div>
        </div>
        <div style="margin-top:10px;margin-bottom:12px;">
            <button type="button" class="adm-btn myapp-filter-job-stop" data-myapp-job-cancel<?= $indexJobStopDisabled ?>>
                <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_BTN_STOP')) ?>
            </button>
        </div>
        <p><small><?= htmlspecialcharsbx($cronWorker) ?></small></p>
    </td>
</tr>
<tr class="heading">
    <td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_ACTIONS_HEADING') ?></td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_BTN_FULL') ?></td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn-save" data-myapp-job-start="full">
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_BTN_FULL')) ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_BTN_CLEAR') ?></td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button
            type="button"
            class="adm-btn"
            data-myapp-job-clear
            data-confirm="<?= CUtil::JSEscape(Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_CONFIRM_CLEAR')) ?>"
        >
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_BTN_CLEAR')) ?>
        </button>
    </td>
</tr>
<?php if ($errors !== []): ?>
    <tr class="heading">
        <td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_ERRORS_TITLE') ?></td>
    </tr>
    <tr>
        <td colspan="2">
            <div class="adm-list-table-wrap adm-list-table-with-border">
                <table class="adm-list-table">
                    <thead>
                        <tr class="adm-list-table-header">
                            <td class="adm-list-table-cell adm-list-table-cell-left">
                                <div class="adm-list-table-cell-inner"><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_ERR_COL_PRODUCT') ?></div>
                            </td>
                            <td class="adm-list-table-cell adm-list-table-cell-left">
                                <div class="adm-list-table-cell-inner"><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_ERR_COL_UPDATED') ?></div>
                            </td>
                            <td class="adm-list-table-cell adm-list-table-cell-left">
                                <div class="adm-list-table-cell-inner"><?= Loc::getMessage('MYAPP_CATALOG_SEARCH_INDEX_ERR_COL_ERROR') ?></div>
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
