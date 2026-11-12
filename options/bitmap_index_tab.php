<?php

/**
 * Вкладка «Bitmap Index».
 *
 * @var array<string, mixed> $bitmapIndexStatus
 * @var string $optCatalogFilterEngine
 * @var string $optBitmapIndexAdminStepBatch
 * @var string $catalogFilterEngine
 * @var int $bitmapIndexAdminStepBatch
 * @var bool $bitmapIndexConfigChangedOnSave
 * @var string $bitmapJobsAjaxUrl
 */

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;
use MyApp\Catalog\Filter\CatalogFilterEngineFactory;
use MyApp\Catalog\Service\BitmapIndexJobService;

$bitmapLang = dirname(__DIR__) . '/lang/' . LANGUAGE_ID . '/admin/bitmap_index.php';
if (is_file($bitmapLang)) {
    Loc::loadLanguageFile($bitmapLang);
}
$cronLang = dirname(__DIR__) . '/lang/' . LANGUAGE_ID . '/admin/cron_agent.php';
if (is_file($cronLang)) {
    Loc::loadLanguageFile($cronLang);
}

if (!isset(
    $bitmapIndexStatus,
    $optCatalogFilterEngine,
    $optBitmapIndexAdminStepBatch,
    $catalogFilterEngine,
    $bitmapIndexAdminStepBatch,
    $bitmapJobsAjaxUrl
)) {
    return;
}

if (!isset($bitmapIndexConfigChangedOnSave)) {
    $bitmapIndexConfigChangedOnSave = false;
}

$moduleRoot = dirname(__DIR__);
$stats = $bitmapIndexStatus;
$runningJob = $stats['running_job'] ?? null;
$jobIsRunning = is_array($runningJob)
    && (string)($runningJob['STATUS'] ?? '') === BitmapIndexJobService::STATUS_RUNNING;
$jobProcessed = $jobIsRunning ? (int)($runningJob['PROCESSED'] ?? 0) : 0;
$jobTotal = $jobIsRunning ? max(0, (int)($runningJob['TOTAL'] ?? 0)) : 0;
$jobPercent = $jobTotal > 0 ? round(min(100, ($jobProcessed / $jobTotal) * 100), 1) : 0.0;
$jobStopDisabled = $jobIsRunning ? '' : ' disabled';

$cronBody = Loc::getMessage('MYAPP_CATALOG_CRON_AGENT_BODY');
$cronBody = str_replace(
    ['#DOCUMENT_ROOT#', '#MODULE_ROOT#'],
    [(string)$_SERVER['DOCUMENT_ROOT'], $moduleRoot],
    (string)$cronBody
);
$cronBody .= "\n\nphp " . $moduleRoot . '/tools/bitmap_index_worker.php --batch=20';

$formatDateTime = static function ($value): string {
    if ($value instanceof DateTime) {
        return $value->toString();
    }
    if ($value === null || $value === '') {
        return '—';
    }

    return (string)$value;
};

$readyLabel = !empty($stats['index_ready'])
    ? Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_YES')
    : Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_NO');
$showStale = !empty($stats['index_config_stale']) || $bitmapIndexConfigChangedOnSave;
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
    <td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_STATS_HEADING') ?></td>
</tr>
<?php if ($showStale): ?>
<tr>
    <td colspan="2" class="adm-detail-content-cell-l">
        <div class="adm-info-message-wrap adm-info-message-yellow">
            <div class="adm-info-message">
                <div class="adm-info-message-body"><?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_CONFIG_STALE')) ?></div>
            </div>
        </div>
    </td>
</tr>
<?php endif; ?>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_ENGINE') ?>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_ENGINE_HINT') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <strong><?= htmlspecialcharsbx((string)$catalogFilterEngine) ?></strong>
        <?php if ($catalogFilterEngine === CatalogFilterEngineFactory::ENGINE_BITMAP): ?>
            — <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_ENGINE_ACTIVE')) ?>
        <?php else: ?>
            — <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_ENGINE_INACTIVE')) ?>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <?= Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_STEP_BATCH') ?>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_STEP_BATCH_HINT') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <input
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            name="<?= htmlspecialcharsbx($optBitmapIndexAdminStepBatch) ?>"
            data-myapp-bitmap-step-batch
            value="<?= (int)$bitmapIndexAdminStepBatch ?>"
            size="5"
            maxlength="3"
        >
    </td>
</tr>
<?php
$statRows = [
    'index_ready' => $readyLabel,
    'status' => (string)($stats['status'] ?? 'idle'),
    'index_version' => (string)(int)($stats['index_version'] ?? 0),
    'products_indexed' => (string)(int)($stats['products_indexed'] ?? 0),
    'products_total' => (string)(int)($stats['products_total'] ?? 0),
    'masks_count' => (string)(int)($stats['masks_count'] ?? 0),
    'blob_bytes' => (string)(int)($stats['blob_bytes'] ?? 0),
    'max_position' => (string)(int)($stats['max_position'] ?? -1),
    'queue_size' => (string)(int)($stats['queue_size'] ?? 0),
    'last_full_sync_at' => $formatDateTime($stats['last_full_sync_at'] ?? null),
    'config_hash' => (string)($stats['config_hash'] ?? ''),
    'expected_config_hash' => (string)($stats['expected_config_hash'] ?? ''),
];
$statLabels = [
    'index_ready' => 'MYAPP_CATALOG_BITMAP_INDEX_READY',
    'status' => 'MYAPP_CATALOG_BITMAP_INDEX_STATUS',
    'index_version' => 'MYAPP_CATALOG_BITMAP_INDEX_VERSION',
    'products_indexed' => 'MYAPP_CATALOG_BITMAP_INDEX_PRODUCTS',
    'products_total' => 'MYAPP_CATALOG_BITMAP_INDEX_PRODUCTS_TOTAL',
    'masks_count' => 'MYAPP_CATALOG_BITMAP_INDEX_MASKS',
    'blob_bytes' => 'MYAPP_CATALOG_BITMAP_INDEX_BLOB',
    'max_position' => 'MYAPP_CATALOG_BITMAP_INDEX_MAX_POS',
    'queue_size' => 'MYAPP_CATALOG_BITMAP_INDEX_QUEUE',
    'last_full_sync_at' => 'MYAPP_CATALOG_BITMAP_INDEX_LAST_SYNC',
    'config_hash' => 'MYAPP_CATALOG_BITMAP_INDEX_CONFIG_HASH',
    'expected_config_hash' => 'MYAPP_CATALOG_BITMAP_INDEX_CONFIG_EXPECTED',
];
foreach ($statLabels as $key => $msgKey): ?>
<tr data-myapp-bitmap-stat="<?= htmlspecialcharsbx($key) ?>">
    <td width="50%" class="adm-detail-content-cell-l"><?= Loc::getMessage($msgKey) ?></td>
    <td width="50%" class="adm-detail-content-cell-r" data-myapp-bitmap-stat-value>
        <?= htmlspecialcharsbx($statRows[$key] ?? '—') ?>
    </td>
</tr>
<?php endforeach; ?>

<tr>
    <td colspan="2" class="adm-detail-content-cell-l" data-myapp-bitmap-jobs-root>
        <div class="myapp-bitmap-job-message" style="display:none;margin-bottom:12px;"></div>
        <div class="myapp-bitmap-job-progress" style="<?= $jobIsRunning ? '' : 'display:none;' ?>margin-bottom:12px;">
            <div class="myapp-filter-job-progress__track">
                <div class="myapp-bitmap-job-progress__bar myapp-filter-job-progress__bar" style="width:<?= $jobIsRunning ? (float)$jobPercent : 0 ?>%;"></div>
            </div>
            <div class="myapp-bitmap-job-progress__label">
                <?php if ($jobIsRunning): ?>
                    <?= (float)$jobPercent ?>% — <?= $jobProcessed ?> / <?= $jobTotal ?>
                <?php else: ?>
                    0%
                <?php endif; ?>
            </div>
        </div>
        <div style="margin-top:10px;margin-bottom:12px;">
            <button type="button" class="adm-btn myapp-filter-job-stop" data-myapp-bitmap-cancel<?= $jobStopDisabled ?>>
                <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_BTN_STOP')) ?>
            </button>
        </div>
        <div class="adm-info-message-wrap adm-info-message-yellow" style="<?= $jobIsRunning ? '' : 'display:none;' ?>margin-bottom:12px;" data-myapp-bitmap-keep-tab>
            <div class="adm-info-message">
                <div class="adm-info-message-body"><?= Loc::getMessage('MYAPP_CATALOG_BITMAP_JOB_KEEP_TAB') ?></div>
            </div>
        </div>
    </td>
</tr>

<tr class="heading">
    <td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_ACTIONS_HEADING') ?></td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_BTN_FULL') ?></td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn-save" data-myapp-bitmap-start="full">
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_BTN_FULL')) ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_BTN_VALIDATE') ?></td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn" data-myapp-bitmap-validate>
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_BTN_VALIDATE')) ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_BTN_REPAIR') ?></td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn" data-myapp-bitmap-repair>
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_BTN_REPAIR')) ?>
        </button>
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_BTN_CLEAR') ?></td>
    <td width="50%" class="adm-detail-content-cell-r">
        <button type="button" class="adm-btn" data-myapp-bitmap-clear>
            <?= htmlspecialcharsbx(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_BTN_CLEAR')) ?>
        </button>
    </td>
</tr>
<script>
(function () {
    var ajaxUrl = <?= json_encode($bitmapJobsAjaxUrl) ?>;
    var sessid = <?= json_encode(bitrix_sessid()) ?>;
    var root = document.querySelector('[data-myapp-bitmap-jobs-root]');
    if (!root || !ajaxUrl) {
        return;
    }
    var messageEl = root.querySelector('.myapp-bitmap-job-message');
    var progressWrap = root.querySelector('.myapp-bitmap-job-progress');
    var progressBar = root.querySelector('.myapp-bitmap-job-progress__bar');
    var progressLabel = root.querySelector('.myapp-bitmap-job-progress__label');
    var keepTab = document.querySelector('[data-myapp-bitmap-keep-tab]');
    var stepDelayMs = 400;
    var stepBatchMax = <?= (int)\MyApp\Catalog\Config\Assets::MAX_BITMAP_INDEX_ADMIN_STEP_BATCH ?>;
    var ajaxHttpError = <?= json_encode(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_AJAX_HTTP_ERROR')) ?>;
    var polling = false;
    var loopTimer = null;
    var stepInFlight = false;

    function showMessage(text, isError) {
        if (!messageEl) return;
        messageEl.style.display = text ? '' : 'none';
        messageEl.textContent = text || '';
        messageEl.style.color = isError ? '#c00' : '#070';
    }

    function getEffectiveBatch() {
        var batchInput = document.querySelector('[data-myapp-bitmap-step-batch]');
        if (!batchInput) {
            return 20;
        }
        var parsed = parseInt(String(batchInput.value || ''), 10);
        if (Number.isNaN(parsed)) {
            return 20;
        }
        return Math.min(stepBatchMax, Math.max(1, parsed));
    }

    function post(action, extra) {
        var body = new FormData();
        body.append('sessid', sessid);
        body.append('action', action);
        if (extra) {
            Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); });
        }
        if (action === 'step') {
            body.append('batch', String(getEffectiveBatch()));
        }
        return fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                return response.text().then(function (text) {
                    if (!response.ok) {
                        throw new Error(ajaxHttpError + ' (HTTP ' + response.status + ')');
                    }
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error(ajaxHttpError);
                    }
                });
            });
    }

    function applyStats(stats) {
        if (!stats) return;
        Object.keys(stats).forEach(function (key) {
            var cell = document.querySelector('[data-myapp-bitmap-stat="' + key + '"] [data-myapp-bitmap-stat-value]');
            if (!cell) return;
            var val = stats[key];
            if (key === 'index_ready') {
                val = stats.index_ready ? <?= json_encode(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_YES')) ?> : <?= json_encode(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_NO')) ?>;
            }
            cell.textContent = (val === null || val === undefined || val === '') ? '—' : String(val);
        });
    }

    function applyProgress(progress) {
        if (!progress) return;
        var active = !!progress.active;
        if (progressWrap) progressWrap.style.display = active ? '' : 'none';
        if (keepTab) keepTab.style.display = active ? '' : 'none';
        if (progressBar) progressBar.style.width = (progress.percent || 0) + '%';
        if (progressLabel) {
            progressLabel.textContent = active
                ? ((progress.percent || 0) + '% — ' + (progress.processed || 0) + ' / ' + (progress.total || 0))
                : '0%';
        }
        var cancelBtn = document.querySelector('[data-myapp-bitmap-cancel]');
        if (cancelBtn) cancelBtn.disabled = !active;
    }

    function stopLoop() {
        polling = false;
        if (loopTimer !== null) {
            clearTimeout(loopTimer);
            loopTimer = null;
        }
    }

    function scheduleStep() {
        if (!polling || stepInFlight) {
            return;
        }
        loopTimer = setTimeout(runStep, stepDelayMs);
    }

    function runStep() {
        if (!polling || stepInFlight) {
            return;
        }
        loopTimer = null;
        stepInFlight = true;
        post('step')
            .then(function (data) {
                if (!data.ok) {
                    throw new Error(data.error || 'error');
                }
                applyProgress(data.progress);
                applyStats(data.stats);
                var progress = data.progress || {};
                if (progress.active && !progress.finished) {
                    stepInFlight = false;
                    scheduleStep();
                    return;
                }
                stopLoop();
                stepInFlight = false;
                if (progress.finished || progress.status === 'completed') {
                    showMessage(<?= json_encode(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_SAVED')) ?>, false);
                }
            })
            .catch(function (e) {
                stepInFlight = false;
                stopLoop();
                showMessage(e.message || String(e), true);
            });
    }

    function startLoop() {
        if (polling) {
            return;
        }
        polling = true;
        showMessage('', false);
        runStep();
    }

    document.querySelector('[data-myapp-bitmap-start="full"]')?.addEventListener('click', function () {
        stopLoop();
        post('start').then(function (data) {
            if (!data.ok) {
                showMessage(data.error || 'error', true);
                return;
            }
            applyProgress(data.progress);
            applyStats(data.stats);
            startLoop();
        }).catch(function (e) {
            showMessage(e.message || String(e), true);
        });
    });
    document.querySelector('[data-myapp-bitmap-cancel]')?.addEventListener('click', function () {
        stopLoop();
        post('cancel').then(function (data) {
            applyProgress(data.progress);
            applyStats(data.stats);
        }).catch(function (e) {
            showMessage(e.message || String(e), true);
        });
    });
    document.querySelector('[data-myapp-bitmap-clear]')?.addEventListener('click', function () {
        if (!confirm(<?= json_encode(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_CONFIRM_CLEAR')) ?>)) return;
        post('clear').then(function (data) {
            if (!data.ok) {
                showMessage(data.error || 'error', true);
                return;
            }
            applyStats(data.stats);
            showMessage(<?= json_encode(Loc::getMessage('MYAPP_CATALOG_BITMAP_INDEX_SAVED')) ?>, false);
        }).catch(function (e) {
            showMessage(e.message || String(e), true);
        });
    });
    document.querySelector('[data-myapp-bitmap-validate]')?.addEventListener('click', function () {
        post('validate').then(function (data) {
            if (!data.ok) {
                showMessage(data.error || 'error', true);
                return;
            }
            var report = data.report || {};
            var text = report.ok ? 'OK' : (report.issues || []).join('; ');
            showMessage(text || 'OK', !report.ok);
            applyStats(data.stats);
        }).catch(function (e) {
            showMessage(e.message || String(e), true);
        });
    });
    document.querySelector('[data-myapp-bitmap-repair]')?.addEventListener('click', function () {
        post('repair').then(function (data) {
            showMessage(data.message || data.error || '', !data.ok);
            applyProgress(data.progress);
            applyStats(data.stats);
            if (data.ok && data.progress && data.progress.active) {
                startLoop();
            }
        }).catch(function (e) {
            showMessage(e.message || String(e), true);
        });
    });

    if (<?= $jobIsRunning ? 'true' : 'false' ?>) {
        applyProgress({
            active: true,
            percent: <?= (float)$jobPercent ?>,
            processed: <?= (int)$jobProcessed ?>,
            total: <?= (int)$jobTotal ?>
        });
        startLoop();
    }
})();
</script>
