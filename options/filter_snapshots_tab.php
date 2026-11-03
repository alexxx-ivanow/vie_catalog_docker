<?php

/**
 * Вкладка «Снапшоты фильтров» на странице настроек модуля.
 *
 * @var array<string, mixed> $filterSnapshotStats
 * @var list<array<string, mixed>> $filterSnapshotErrors
 * @var string $filterSnapshotMessage
 * @var string $filterSnapshotError
 */

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__DIR__ . '/filter_snapshots_tab.php');
$filterSnapshotsLang = dirname(__DIR__) . '/lang/' . LANGUAGE_ID . '/admin/filter_snapshots.php';
if (is_file($filterSnapshotsLang)) {
    Loc::loadLanguageFile($filterSnapshotsLang);
}

if (!isset($filterSnapshotStats, $filterSnapshotErrors)) {
    return;
}

$moduleRoot = dirname(__DIR__);
$stats = $filterSnapshotStats;
$errors = $filterSnapshotErrors;

$cronBody = Loc::getMessage('MYAPP_CATALOG_FILTER_AGENT_CRON_BODY');
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

$statKeys = [
    'sections_total' => 'MYAPP_CATALOG_FILTER_STATS_SECTIONS',
    'snapshots_total' => 'MYAPP_CATALOG_FILTER_STATS_SNAPSHOTS',
    'snapshots_fresh' => 'MYAPP_CATALOG_FILTER_STATS_FRESH',
    'snapshots_stale' => 'MYAPP_CATALOG_FILTER_STATS_STALE',
    'queue_size' => 'MYAPP_CATALOG_FILTER_STATS_QUEUE',
    'errors_total' => 'MYAPP_CATALOG_FILTER_STATS_ERRORS',
    'last_fill_completed_at' => 'MYAPP_CATALOG_FILTER_LAST_FILL',
];
$initialStatValues = [
    'sections_total' => (string)(int)($stats['sections_total'] ?? 0),
    'snapshots_total' => (string)(int)($stats['snapshots_total'] ?? 0),
    'snapshots_fresh' => (string)(int)($stats['snapshots_fresh'] ?? 0),
    'snapshots_stale' => (string)(int)($stats['snapshots_stale'] ?? 0),
    'queue_size' => (string)(int)($stats['queue_size'] ?? 0),
    'errors_total' => (string)(int)($stats['errors_total'] ?? 0),
    'last_fill_completed_at' => $formatDateTime($stats['last_fill_completed_at'] ?? null),
];
?>
<tr class="heading">
    <td colspan="2"><?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_AGENT_CRON_TITLE')) ?></td>
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
    <td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_STATS_HEADING') ?></td>
</tr>
<?php foreach ($statKeys as $statKey => $msgKey): ?>
    <tr data-myapp-stat="<?= htmlspecialcharsbx($statKey) ?>">
        <td width="50%" class="adm-detail-content-cell-l">
            <?= Loc::getMessage($msgKey) ?>
        </td>
        <td width="50%" class="adm-detail-content-cell-r" data-myapp-stat-value>
            <?= htmlspecialcharsbx($initialStatValues[$statKey] ?? '—') ?>
        </td>
    </tr>
<?php endforeach; ?>
<tr>
    <td colspan="2" class="adm-detail-content-cell-l">
        <div class="myapp-filter-job-message" style="display:none;margin-bottom:12px;"></div>
        <div class="myapp-filter-job-progress" style="display:none;margin-bottom:12px;">
            <div class="myapp-filter-job-progress__track">
                <div class="myapp-filter-job-progress__bar" style="width:0%;"></div>
            </div>
            <div class="myapp-filter-job-progress__label">0%</div>
        </div>
        <div class="myapp-filter-job-progress__hint adm-info-message-wrap adm-info-message-yellow" style="display:none;margin-bottom:12px;">
            <div class="adm-info-message">
                <div class="adm-info-message-body"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_JOB_KEEP_TAB') ?></div>
            </div>
        </div>
    </td>
</tr>

<tr class="heading">
    <td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_ACTIONS_HEADING') ?></td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_FILTER_BTN_FILL') ?>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn-save" data-myapp-job-start="fill_all">
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_BTN_FILL')) ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_FILTER_BTN_REBUILD_ALL') ?>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn" data-myapp-job-start="rebuild_all">
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_BTN_REBUILD_ALL')) ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_FILTER_BTN_REBUILD_STALE') ?>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn" data-myapp-job-start="rebuild_stale">
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_BTN_REBUILD_STALE')) ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <label for="myapp_filter_section_id"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_SECTION_ID') ?></label>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <input
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            id="myapp_filter_section_id"
            data-myapp-section-id
            value="0"
            size="10"
            maxlength="10"
        >
        <button type="button" class="adm-btn" data-myapp-job-start="section">
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_BTN_SECTION')) ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_FILTER_BTN_CANCEL') ?>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn" data-myapp-job-cancel style="display:none;">
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_BTN_CANCEL')) ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_FILTER_BTN_CLEAR') ?>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_FILTER_CONFIRM_CLEAR') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button
            type="button"
            class="adm-btn"
            data-myapp-job-clear
            data-confirm="<?= CUtil::JSEscape(Loc::getMessage('MYAPP_CATALOG_FILTER_CONFIRM_CLEAR')) ?>"
        >
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_FILTER_BTN_CLEAR')) ?>
        </button>
    </td>
</tr>

<?php if ($errors !== []): ?>
    <tr class="heading">
        <td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_ERRORS_TITLE') ?></td>
    </tr>
    <tr>
        <td colspan="2">
            <div class="adm-list-table-wrap adm-list-table-with-border">
                <table class="adm-list-table">
                    <thead>
                        <tr class="adm-list-table-header">
                            <td class="adm-list-table-cell adm-list-table-cell-left">
                                <div class="adm-list-table-cell-inner"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_ERR_COL_SECTION') ?></div>
                            </td>
                            <td class="adm-list-table-cell adm-list-table-cell-left">
                                <div class="adm-list-table-cell-inner"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_ERR_COL_UPDATED') ?></div>
                            </td>
                            <td class="adm-list-table-cell adm-list-table-cell-left">
                                <div class="adm-list-table-cell-inner"><?= Loc::getMessage('MYAPP_CATALOG_FILTER_ERR_COL_ERROR') ?></div>
                            </td>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $row): ?>
                            <tr class="adm-list-table-row">
                                <td class="adm-list-table-cell adm-list-table-cell-left">
                                    <div class="adm-list-table-cell-inner"><?= (int)$row['SECTION_ID'] ?></div>
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
