<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Service\CatalogService;
use MyApp\Catalog\Service\FilterRebuildJobService;
use MyApp\Catalog\Service\ProductFilterIndexService;
use MyApp\Catalog\Service\ProductFilterIndexJobService;

/**
 * @global CUser $USER
 * @global CMain $APPLICATION
 * @var string $mid
 */

if (!$USER->IsAdmin()) {
    return;
}

if (!Loader::includeModule('myapp.catalog')) {
    return;
}

Loc::loadMessages(__FILE__);

$moduleId = 'myapp.catalog';
$optUseBitrixVue = 'use_bitrix_vue';
$optCatalogIblockId = 'catalog_iblock_id';
$optCatalogCacheTtl = Assets::OPT_CATALOG_CACHE_TTL;
$optAutoLoginAfterGuestOrder = 'auto_login_after_guest_order';
$optShowCatalogFilter = Assets::OPT_SHOW_CATALOG_FILTER;
$optCatalogPriceTypeCode = Assets::OPT_CATALOG_PRICE_TYPE_CODE;
$optFilterPropertyCodes = Assets::OPT_FILTER_PROPERTY_CODES;
$optCardPropertyCodes = Assets::OPT_CARD_PROPERTY_CODES;
$optCatalogArticlePropertyCode = Assets::OPT_CATALOG_ARTICLE_PROPERTY_CODE;
$optUseProductFilterIndex = Assets::OPT_USE_PRODUCT_FILTER_INDEX;
$optProductFilterIndexAdminStepBatch = Assets::OPT_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH;
$defaultCatalogIblockId = 2;
$filterIndexConfigChangedOnSave = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $filtersConfigHashBefore = CatalogService::filtersConfigHash();
    $indexConfigHashBefore = ProductFilterIndexService::indexConfigHash();

    Option::set($moduleId, $optUseBitrixVue, isset($_POST[$optUseBitrixVue]) ? 'Y' : 'N');
    Option::set(
        $moduleId,
        $optAutoLoginAfterGuestOrder,
        isset($_POST[$optAutoLoginAfterGuestOrder]) ? 'Y' : 'N'
    );
    Option::set($moduleId, $optShowCatalogFilter, isset($_POST[$optShowCatalogFilter]) ? 'Y' : 'N');

    $postedPriceCode = trim((string)($_POST[$optCatalogPriceTypeCode] ?? ''));
    if ($postedPriceCode === '' || !preg_match('/^[A-Za-z0-9_]+$/', $postedPriceCode)) {
        $postedPriceCode = Assets::DEFAULT_CATALOG_PRICE_TYPE_CODE;
    }
    Option::set($moduleId, $optCatalogPriceTypeCode, $postedPriceCode);

    $postedCodes = $_POST[$optFilterPropertyCodes] ?? [];
    $propertyCodes = Assets::normalizePropertyCodes(is_array($postedCodes) ? $postedCodes : []);
    Option::set(
        $moduleId,
        $optFilterPropertyCodes,
        (string)json_encode($propertyCodes, JSON_UNESCAPED_UNICODE)
    );

    $postedCardCodes = $_POST[$optCardPropertyCodes] ?? [];
    $cardPropertyCodes = Assets::normalizePropertyCodes(is_array($postedCardCodes) ? $postedCardCodes : []);
    Option::set(
        $moduleId,
        $optCardPropertyCodes,
        (string)json_encode($cardPropertyCodes, JSON_UNESCAPED_UNICODE)
    );

    $postedArticleCode = trim((string)($_POST[$optCatalogArticlePropertyCode] ?? ''));
    if ($postedArticleCode === '' || !preg_match('/^[A-Za-z0-9_]+$/', $postedArticleCode)) {
        $postedArticleCode = Assets::DEFAULT_ARTICLE_PROPERTY_CODE;
    }
    Option::set($moduleId, $optCatalogArticlePropertyCode, $postedArticleCode);

    $postedIblockId = (int)($_POST[$optCatalogIblockId] ?? 0);
    if ($postedIblockId > 0) {
        Option::set($moduleId, $optCatalogIblockId, (string)$postedIblockId);
    }

    $postedCacheTtl = (int)($_POST[$optCatalogCacheTtl] ?? 0);
    if ($postedCacheTtl <= 0) {
        $postedCacheTtl = Assets::DEFAULT_CATALOG_CACHE_TTL;
    }
    Option::set($moduleId, $optCatalogCacheTtl, (string)$postedCacheTtl);

    if (array_key_exists($optUseProductFilterIndex, $_POST)) {
        Option::set(
            $moduleId,
            $optUseProductFilterIndex,
            isset($_POST[$optUseProductFilterIndex]) ? 'Y' : 'N'
        );
    }

    if (array_key_exists($optProductFilterIndexAdminStepBatch, $_POST)) {
        $postedStepBatch = (int)($_POST[$optProductFilterIndexAdminStepBatch] ?? 0);
        if ($postedStepBatch <= 0) {
            $postedStepBatch = Assets::DEFAULT_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH;
        }
        $postedStepBatch = min(Assets::MAX_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH, max(1, $postedStepBatch));
        Option::set($moduleId, $optProductFilterIndexAdminStepBatch, (string)$postedStepBatch);
    }

    CatalogService::clearCatalogDataCache();
    if (CatalogService::filtersConfigHash() !== $filtersConfigHashBefore) {
        CatalogService::markFilterSnapshotsForRebuild();
    }
    $filterIndexConfigChangedOnSave = $indexConfigHashBefore !== ProductFilterIndexService::indexConfigHash();
}

$filterSnapshotStats = FilterRebuildJobService::getStatistics();
$filterSnapshotErrors = FilterRebuildJobService::getRecentErrors(15);
$filterIndexStatus = ProductFilterIndexService::getIndexStatus();
$filterIndexErrors = ProductFilterIndexService::getRecentProductErrors(15);
$filterIndexInitialProgress = ProductFilterIndexJobService::getProgressPayload();

$useBitrixVue = Option::get($moduleId, $optUseBitrixVue, 'N') === 'Y';
$autoLoginAfterGuestOrder = Option::get($moduleId, $optAutoLoginAfterGuestOrder, 'N') === 'Y';
$showCatalogFilter = Assets::showCatalogFilter();
$catalogPriceTypeCode = Assets::catalogPriceTypeCode();
$filterPropertyCodes = Assets::filterPropertyCodes();
$cardPropertyCodes = Assets::cardPropertyCodes();
$catalogArticlePropertyCode = Assets::catalogArticlePropertyCode();
$catalogCacheTtl = Assets::catalogCacheTtl();
$catalogIblockId = (int)Option::get($moduleId, $optCatalogIblockId, (string)$defaultCatalogIblockId);
if ($catalogIblockId <= 0) {
    $catalogIblockId = $defaultCatalogIblockId;
}
$useProductFilterIndex = Option::get($moduleId, $optUseProductFilterIndex, 'N') === 'Y';
$productFilterIndexAdminStepBatch = Assets::productFilterIndexAdminStepBatch();

$filterJobsAjaxUrl = '/bitrix/admin/myapp_catalog_filter_jobs_ajax.php';
if (!is_file($_SERVER['DOCUMENT_ROOT'] . $filterJobsAjaxUrl)) {
    $filterJobsAjaxUrl = '/local/modules/myapp.catalog/admin/myapp_catalog_filter_jobs_ajax.php';
}

$aTabs = [
    [
        'DIV' => 'edit1',
        'TAB' => Loc::getMessage('MYAPP_CATALOG_OPT_TAB'),
        'TITLE' => Loc::getMessage('MYAPP_CATALOG_OPT_TAB_TITLE'),
    ],
    [
        'DIV' => 'edit2',
        'TAB' => Loc::getMessage('MYAPP_CATALOG_OPT_TAB_SNAPSHOTS'),
        'TITLE' => Loc::getMessage('MYAPP_CATALOG_OPT_TAB_SNAPSHOTS_TITLE'),
    ],
    [
        'DIV' => 'edit3',
        'TAB' => Loc::getMessage('MYAPP_CATALOG_OPT_TAB_INDEX'),
        'TITLE' => Loc::getMessage('MYAPP_CATALOG_OPT_TAB_INDEX_TITLE'),
    ],
];

$tabControl = new CAdminTabControl('tabControl', $aTabs);
if ((string)($_REQUEST['tabControl_active_tab'] ?? '') === 'edit3') {
    $tabControl->selectedTab = 'edit3';
} elseif (
    !empty($_POST['myapp_filter_snapshot_action'])
    || (string)($_REQUEST['tabControl_active_tab'] ?? '') === 'edit2'
) {
    $tabControl->selectedTab = 'edit2';
}
$tabControl->Begin();
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= htmlspecialcharsbx($mid) ?>&lang=<?= LANGUAGE_ID ?>">
    <?php
    echo bitrix_sessid_post();
    $tabControl->BeginNextTab();
    ?>
    <tr>
        <td width="50%" class="adm-detail-content-cell-l">
            <label for="MYAPP_CATALOG_USE_BITRIX_VUE">
                <?= Loc::getMessage('MYAPP_CATALOG_OPT_USE_BITRIX_VUE') ?>
            </label>
            <br>
            <small><?= Loc::getMessage('MYAPP_CATALOG_OPT_USE_BITRIX_VUE_HINT') ?></small>
        </td>
        <td width="50%" class="adm-detail-content-cell-r">
            <input
                type="checkbox"
                class="adm-designed-checkbox"
                id="MYAPP_CATALOG_USE_BITRIX_VUE"
                name="<?= htmlspecialcharsbx($optUseBitrixVue) ?>"
                value="Y"
                <?= $useBitrixVue ? 'checked' : '' ?>
            >
            <label class="adm-designed-checkbox-label" for="MYAPP_CATALOG_USE_BITRIX_VUE"></label>
        </td>
    </tr>
    <tr class="heading"><td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_OPT_ORDER_SECTION') ?></td></tr>
    <tr>
        <td width="50%" class="adm-detail-content-cell-l">
            <label for="MYAPP_CATALOG_AUTO_LOGIN_GUEST">
                <?= Loc::getMessage('MYAPP_CATALOG_OPT_AUTO_LOGIN_GUEST') ?>
            </label>
            <br>
            <small><?= Loc::getMessage('MYAPP_CATALOG_OPT_AUTO_LOGIN_GUEST_HINT') ?></small>
        </td>
        <td width="50%" class="adm-detail-content-cell-r">
            <input
                type="checkbox"
                class="adm-designed-checkbox"
                id="MYAPP_CATALOG_AUTO_LOGIN_GUEST"
                name="<?= htmlspecialcharsbx($optAutoLoginAfterGuestOrder) ?>"
                value="Y"
                <?= $autoLoginAfterGuestOrder ? 'checked' : '' ?>
            >
            <label class="adm-designed-checkbox-label" for="MYAPP_CATALOG_AUTO_LOGIN_GUEST"></label>
        </td>
    </tr>
    <tr class="heading"><td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_OPT_CARD_SECTION') ?></td></tr>
    <tr>
        <td width="50%" class="adm-detail-content-cell-l">
            <label for="MYAPP_CATALOG_ARTICLE_PROPERTY_CODE">
                <?= Loc::getMessage('MYAPP_CATALOG_OPT_ARTICLE_PROPERTY_CODE') ?>
            </label>
            <br>
            <small><?= Loc::getMessage('MYAPP_CATALOG_OPT_ARTICLE_PROPERTY_CODE_HINT') ?></small>
        </td>
        <td width="50%" class="adm-detail-content-cell-r">
            <input
                type="text"
                id="MYAPP_CATALOG_ARTICLE_PROPERTY_CODE"
                name="<?= htmlspecialcharsbx($optCatalogArticlePropertyCode) ?>"
                value="<?= htmlspecialcharsbx($catalogArticlePropertyCode) ?>"
                maxlength="50"
            >
        </td>
    </tr>
    <tr>
        <td width="50%" class="adm-detail-content-cell-l" style="vertical-align: top;">
            <?= Loc::getMessage('MYAPP_CATALOG_OPT_CARD_PROPS') ?>
            <br>
            <small><?= Loc::getMessage('MYAPP_CATALOG_OPT_CARD_PROPS_HINT') ?></small>
        </td>
        <td width="50%" class="adm-detail-content-cell-r">
            <div id="myapp-catalog-card-props">
                <?php foreach ($cardPropertyCodes as $propertyCode): ?>
                    <div class="myapp-catalog-prop-row" style="margin-bottom: 8px;">
                        <input
                            type="text"
                            name="<?= htmlspecialcharsbx($optCardPropertyCodes) ?>[]"
                            value="<?= htmlspecialcharsbx($propertyCode) ?>"
                            maxlength="50"
                        >
                        <button type="button" class="adm-btn myapp-catalog-prop-remove">
                            <?= Loc::getMessage('MYAPP_CATALOG_OPT_CARD_PROPS_REMOVE') ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="adm-btn" id="myapp-catalog-card-prop-add">
                <?= Loc::getMessage('MYAPP_CATALOG_OPT_CARD_PROPS_ADD') ?>
            </button>
        </td>
    </tr>
    <tr class="heading"><td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_OPT_FILTER_SECTION') ?></td></tr>
    <tr>
        <td width="50%" class="adm-detail-content-cell-l">
            <label for="MYAPP_CATALOG_SHOW_FILTER">
                <?= Loc::getMessage('MYAPP_CATALOG_OPT_SHOW_FILTER') ?>
            </label>
            <br>
            <small><?= Loc::getMessage('MYAPP_CATALOG_OPT_SHOW_FILTER_HINT') ?></small>
        </td>
        <td width="50%" class="adm-detail-content-cell-r">
            <input
                type="checkbox"
                class="adm-designed-checkbox"
                id="MYAPP_CATALOG_SHOW_FILTER"
                name="<?= htmlspecialcharsbx($optShowCatalogFilter) ?>"
                value="Y"
                <?= $showCatalogFilter ? 'checked' : '' ?>
            >
            <label class="adm-designed-checkbox-label" for="MYAPP_CATALOG_SHOW_FILTER"></label>
        </td>
    </tr>
    <tr>
        <td width="50%" class="adm-detail-content-cell-l">
            <label for="MYAPP_CATALOG_PRICE_TYPE_CODE">
                <?= Loc::getMessage('MYAPP_CATALOG_OPT_PRICE_TYPE_CODE') ?>
            </label>
            <br>
            <small><?= Loc::getMessage('MYAPP_CATALOG_OPT_PRICE_TYPE_CODE_HINT') ?></small>
        </td>
        <td width="50%" class="adm-detail-content-cell-r">
            <input
                type="text"
                id="MYAPP_CATALOG_PRICE_TYPE_CODE"
                name="<?= htmlspecialcharsbx($optCatalogPriceTypeCode) ?>"
                value="<?= htmlspecialcharsbx($catalogPriceTypeCode) ?>"
                maxlength="32"
            >
        </td>
    </tr>
    <tr>
        <td width="50%" class="adm-detail-content-cell-l" style="vertical-align: top;">
            <?= Loc::getMessage('MYAPP_CATALOG_OPT_FILTER_PROPS') ?>
            <br>
            <small><?= Loc::getMessage('MYAPP_CATALOG_OPT_FILTER_PROPS_HINT') ?></small>
        </td>
        <td width="50%" class="adm-detail-content-cell-r">
            <div id="myapp-catalog-filter-props">
                <?php foreach ($filterPropertyCodes as $propertyCode): ?>
                    <div class="myapp-catalog-prop-row" style="margin-bottom: 8px;">
                        <input
                            type="text"
                            name="<?= htmlspecialcharsbx($optFilterPropertyCodes) ?>[]"
                            value="<?= htmlspecialcharsbx($propertyCode) ?>"
                            maxlength="50"
                        >
                        <button type="button" class="adm-btn myapp-catalog-prop-remove">
                            <?= Loc::getMessage('MYAPP_CATALOG_OPT_FILTER_PROPS_REMOVE') ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="adm-btn" id="myapp-catalog-filter-prop-add">
                <?= Loc::getMessage('MYAPP_CATALOG_OPT_FILTER_PROPS_ADD') ?>
            </button>
        </td>
    </tr>
    <tr class="heading"><td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_OPT_CATALOG_SECTION') ?></td></tr>
    <tr>
        <td width="50%" class="adm-detail-content-cell-l">
            <label for="MYAPP_CATALOG_IBLOCK_ID">
                <?= Loc::getMessage('MYAPP_CATALOG_OPT_IBLOCK_ID') ?>
            </label>
        </td>
        <td width="50%" class="adm-detail-content-cell-r">
            <input
                type="text"
                inputmode="numeric"
                pattern="[0-9]*"
                id="MYAPP_CATALOG_IBLOCK_ID"
                name="<?= htmlspecialcharsbx($optCatalogIblockId) ?>"
                value="<?= (int)$catalogIblockId ?>"
                maxlength="10"
            >
        </td>
    </tr>
    <tr>
        <td width="50%" class="adm-detail-content-cell-l">
            <label for="MYAPP_CATALOG_CACHE_TTL">
                <?= Loc::getMessage('MYAPP_CATALOG_OPT_CACHE_TTL') ?>
            </label>
            <br>
            <small><?= Loc::getMessage('MYAPP_CATALOG_OPT_CACHE_TTL_HINT') ?></small>
        </td>
        <td width="50%" class="adm-detail-content-cell-r">
            <input
                type="text"
                inputmode="numeric"
                pattern="[0-9]*"
                id="MYAPP_CATALOG_CACHE_TTL"
                name="<?= htmlspecialcharsbx($optCatalogCacheTtl) ?>"
                value="<?= (int)$catalogCacheTtl ?>"
                maxlength="10"
            >
        </td>
    </tr>
    <?php
    $tabControl->BeginNextTab();
    include __DIR__ . '/options/filter_snapshots_tab.php';
    $tabControl->BeginNextTab();
    include __DIR__ . '/options/filter_index_tab.php';
    $tabControl->Buttons();
    ?>
    <input type="submit" class="adm-btn-save" name="save" value="<?= Loc::getMessage('MAIN_SAVE') ?>">
    <?php
    $tabControl->End();
    ?>
</form>
<style>
.adm-workarea .adm-detail-content-table button[data-myapp-job-start],
.adm-workarea .adm-detail-content-table button[data-myapp-job-cancel],
.adm-workarea .adm-detail-content-table button[data-myapp-job-clear] {
    box-sizing: border-box;
    display: inline-block;
    height: 29px;
    min-height: 29px;
    margin: 0;
    padding: 0 15px;
    border-radius: 4px;
    font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
    font-size: 13px;
    font-weight: normal;
    line-height: 27px;
    vertical-align: middle;
    cursor: pointer;
    text-shadow: none;
    -webkit-appearance: none;
    appearance: none;
}
.adm-workarea .adm-detail-content-table button[data-myapp-job-start].adm-btn,
.adm-workarea .adm-detail-content-table button[data-myapp-job-cancel].adm-btn,
.adm-workarea .adm-detail-content-table button[data-myapp-job-clear].adm-btn {
    border: 1px solid #a9a9a9;
    background: #fff;
    background: linear-gradient(to bottom, #fff 0%, #eaeaea 100%);
    color: #000;
}
.adm-workarea .adm-detail-content-table button[data-myapp-job-start].adm-btn-save {
    border: 1px solid #97c004;
    background: #86b814;
    background: linear-gradient(to bottom, #b4e034 0%, #85af27 100%);
    color: #fff;
    font-weight: bold;
    text-shadow: 0 1px 0 rgba(0, 0, 0, 0.15);
}
.adm-workarea .adm-detail-content-table button[data-myapp-job-start].adm-btn:hover,
.adm-workarea .adm-detail-content-table button[data-myapp-job-cancel].adm-btn:hover,
.adm-workarea .adm-detail-content-table button[data-myapp-job-clear].adm-btn:hover {
    background: #f5f5f5;
    background: linear-gradient(to bottom, #f5f5f5 0%, #dcdcdc 100%);
}
.adm-workarea .adm-detail-content-table button[data-myapp-job-start].adm-btn-save:hover {
    background: #9fd022;
    background: linear-gradient(to bottom, #c5eb45 0%, #93bf2b 100%);
}
.myapp-filter-job-progress__track {
    height: 22px;
    border-radius: 11px;
    background: #e5e9ed;
    border: 1px solid #c6cdd3;
    overflow: hidden;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.06);
}
.myapp-filter-job-progress__bar {
    height: 100%;
    min-width: 0;
    border-radius: 10px;
    background: linear-gradient(to bottom, #b4e034 0%, #85af27 100%);
    transition: width 0.35s ease;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.25);
}
.adm-workarea .adm-detail-content-table button.myapp-filter-job-stop:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}
.adm-workarea .adm-detail-content-table button.myapp-filter-job-stop:not(:disabled) {
    border-color: #c40000;
    color: #a80000;
}
.myapp-filter-job-progress__label {
    margin-top: 8px;
    font-size: 13px;
    color: #333;
}
</style>
<script src="/local/modules/myapp.catalog/js/admin/filter_jobs_ui.js"></script>
<script>
(function () {
    var removeLabel = <?= json_encode(Loc::getMessage('MYAPP_CATALOG_OPT_FILTER_PROPS_REMOVE')) ?>;

    function initPropertyCodeList(listId, addButtonId, fieldName) {
        var list = document.getElementById(listId);
        var addButton = document.getElementById(addButtonId);
        if (!list || !addButton) {
            return;
        }

        function bindRemove(button) {
            button.addEventListener('click', function () {
                var row = button.closest('.myapp-catalog-prop-row');
                if (row) {
                    row.remove();
                }
            });
        }

        list.querySelectorAll('.myapp-catalog-prop-remove').forEach(bindRemove);

        addButton.addEventListener('click', function () {
            var row = document.createElement('div');
            row.className = 'myapp-catalog-prop-row';
            row.style.marginBottom = '8px';

            var input = document.createElement('input');
            input.type = 'text';
            input.name = fieldName + '[]';
            input.maxLength = 50;

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'adm-btn myapp-catalog-prop-remove';
            button.textContent = removeLabel;
            bindRemove(button);

            row.appendChild(input);
            row.appendChild(document.createTextNode(' '));
            row.appendChild(button);
            list.appendChild(row);
            input.focus();
        });
    }

    initPropertyCodeList(
        'myapp-catalog-card-props',
        'myapp-catalog-card-prop-add',
        <?= json_encode($optCardPropertyCodes) ?>
    );
    initPropertyCodeList(
        'myapp-catalog-filter-props',
        'myapp-catalog-filter-prop-add',
        <?= json_encode($optFilterPropertyCodes) ?>
    );
})();

<?php
$filterJobsLabels = [
    'yes' => Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_YES'),
    'no' => Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_NO'),
    'done' => Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_SAVED'),
    'cancelled' => Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_BTN_CANCEL'),
    'failed' => Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_ERR'),
    'error' => Loc::getMessage('MYAPP_CATALOG_FILTER_ERR'),
    'confirmClear' => Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_CONFIRM_CLEAR'),
    'cleared' => Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_SAVED'),
    'currentProduct' => Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_JOB_PRODUCT'),
    'currentSection' => Loc::getMessage('MYAPP_CATALOG_FILTER_JOB_SECTION'),
    'partialIndex' => Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_PARTIAL_WARNING'),
    'sectionIdRequired' => Loc::getMessage('MYAPP_CATALOG_FILTER_INDEX_SECTION_ID_REQUIRED'),
];
?>
MyappCatalogFilterJobs.init([
    {
        domain: 'snapshots',
        tabId: 'edit2',
        ajaxUrl: <?= json_encode($filterJobsAjaxUrl) ?>,
        sessid: <?= json_encode(bitrix_sessid()) ?>,
        batchSize: 20,
        labels: <?= json_encode($filterJobsLabels, JSON_UNESCAPED_UNICODE) ?>
    },
    {
        domain: 'index',
        tabId: 'edit3',
        ajaxUrl: <?= json_encode($filterJobsAjaxUrl) ?>,
        sessid: <?= json_encode(bitrix_sessid()) ?>,
        batchSize: <?= (int)$productFilterIndexAdminStepBatch ?>,
        cancelAlwaysVisible: true,
        stepBatchInputSelector: '[data-myapp-index-step-batch]',
        stepBatchMax: <?= (int)Assets::MAX_PRODUCT_FILTER_INDEX_ADMIN_STEP_BATCH ?>,
        initialProgress: <?= json_encode($filterIndexInitialProgress, JSON_UNESCAPED_UNICODE) ?>,
        labels: <?= json_encode($filterJobsLabels, JSON_UNESCAPED_UNICODE) ?>
    }
]);
</script>
