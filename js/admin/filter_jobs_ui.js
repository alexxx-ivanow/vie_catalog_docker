(function () {
    'use strict';

    /**
     * @param {Record<string, string>} config
     */
    function createController(config) {
        var domain = config.domain;

        var ajaxUrl = config.ajaxUrl;
        var sessid = config.sessid;
        var batchSize = config.batchSize || 20;
        var stepDelayMs = config.stepDelayMs || 320;
        var cancelAlwaysVisible = !!config.cancelAlwaysVisible;
        var stepBatchInputSelector = config.stepBatchInputSelector || '';
        var stepBatchMax = config.stepBatchMax || 50;
        var initialProgress = config.initialProgress || null;
        var labels = config.labels || {};

        function resolveScope() {
            var byTabId = config.tabId ? document.getElementById(config.tabId) : null;
            if (byTabId && byTabId.querySelector('[data-myapp-job-cancel], [data-myapp-job-start], .myapp-filter-job-progress')) {
                return byTabId;
            }
            var anchor = document.querySelector('[data-myapp-jobs-root="' + domain + '"]');
            if (anchor) {
                var table = anchor.closest('table');
                if (table) {
                    return table;
                }
            }
            var domainRow = document.querySelector('[data-myapp-jobs-domain="' + domain + '"]');
            if (domainRow) {
                var tableFromRow = domainRow.closest('table');
                if (tableFromRow) {
                    return tableFromRow;
                }
            }
            if (byTabId) {
                return byTabId;
            }
            return document.body;
        }

        var tab = resolveScope();
        if (!tab || tab === document.body) {
            return null;
        }

        var progressWrap = tab.querySelector('.myapp-filter-job-progress');
        var progressBar = tab.querySelector('.myapp-filter-job-progress__bar');
        var progressLabel = tab.querySelector('.myapp-filter-job-progress__label');
        var progressHint = tab.querySelector('.myapp-filter-job-progress__hint');
        var messageBox = tab.querySelector('.myapp-filter-job-message');

        var polling = false;
        var loopTimer = null;

        function showMessage(text, isError) {
            if (!messageBox) {
                return;
            }
            if (!text) {
                messageBox.innerHTML = '';
                messageBox.style.display = 'none';
                return;
            }
            messageBox.style.display = '';
            messageBox.innerHTML =
                '<div class="adm-info-message-wrap adm-info-message-' + (isError ? 'red' : 'green') + '">' +
                '<div class="adm-info-message"><div class="adm-info-message-body">' +
                escapeHtml(text) +
                '</div></div></div>';
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function post(action, extra) {
            var body = new URLSearchParams();
            body.set('sessid', sessid);
            body.set('domain', domain);
            body.set('action', action);
            if (extra) {
                Object.keys(extra).forEach(function (key) {
                    body.set(key, extra[key]);
                });
            }

            return fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body: body.toString(),
            }).then(function (response) {
                return response.text().then(function (text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (parseError) {
                        throw new Error(labels.error || 'Invalid server response');
                    }
                    return data;
                });
            });
        }

        function formatProgressLine(progress) {
            var percent = progress.percent != null ? progress.percent : 0;
            var processed = progress.processed || 0;
            var total = progress.total || 0;
            var type = progress.type || '';
            var currentId = domain === 'index'
                ? (progress.current_product_id || 0)
                : (progress.current_section_id || 0);
            var currentLabel = domain === 'index'
                ? (labels.currentProduct || 'товар')
                : (labels.currentSection || 'раздел');

            var line = percent + '% — ' + processed + ' / ' + total;
            if (type) {
                line += ' (' + type + ')';
            }
            if (currentId > 0) {
                line += ', ' + currentLabel + ' ' + currentId;
            }
            return line;
        }

        function setProgressVisible(visible) {
            if (!progressWrap) {
                return;
            }
            progressWrap.style.display = visible ? '' : 'none';
            if (progressHint) {
                progressHint.style.display = visible ? '' : 'none';
            }
        }

        function getEffectiveBatchSize() {
            if (stepBatchInputSelector) {
                var input = tab.querySelector(stepBatchInputSelector);
                if (input) {
                    var parsed = parseInt(String(input.value || ''), 10);
                    if (!Number.isNaN(parsed)) {
                        return Math.min(stepBatchMax, Math.max(1, parsed));
                    }
                }
            }

            return batchSize;
        }

        function setCancelButtonsState(active) {
            tab.querySelectorAll('[data-myapp-job-cancel]').forEach(function (btn) {
                if (cancelAlwaysVisible) {
                    btn.disabled = !active;
                    btn.style.display = '';
                    return;
                }
                btn.style.display = active ? '' : 'none';
                btn.disabled = false;
            });
        }

        function updateProgress(progress) {
            if (!progress) {
                return;
            }
            var percent = Math.max(0, Math.min(100, Number(progress.percent) || 0));
            if (progressBar) {
                progressBar.style.width = percent + '%';
            }
            if (progressLabel) {
                progressLabel.textContent = formatProgressLine(progress);
            }
            var active = !!progress.active;
            setProgressVisible(active || (!progress.finished && percent > 0 && percent < 100));
            setCancelButtonsState(active);
            if (domain === 'index' && active) {
                updateIndexConfigStaleBanner(null, progress);
            }
        }

        function formatStatValue(key, stats) {
            if (!stats) {
                return '—';
            }
            if (key === 'index_ready') {
                return stats.index_ready ? (labels.yes || 'Да') : (labels.no || 'Нет');
            }
            if (key === 'partial_section_id') {
                var sectionId = parseInt(stats.partial_section_id, 10) || 0;
                if (sectionId <= 0) {
                    return '—';
                }
                var tpl = labels.partialIndex || 'Частичный индекс: раздел #ID#';
                return tpl.replace('#ID#', String(sectionId));
            }
            var val = stats[key];
            if (val === null || val === undefined || val === '') {
                return '—';
            }
            return String(val);
        }

        function updateStats(stats) {
            if (!stats) {
                return;
            }
            tab.querySelectorAll('[data-myapp-stat]').forEach(function (row) {
                var key = row.getAttribute('data-myapp-stat');
                var cell = row.querySelector('[data-myapp-stat-value]');
                if (!key || !cell) {
                    return;
                }
                cell.textContent = formatStatValue(key, stats);
            });
            if (domain === 'index') {
                updateIndexConfigStaleBanner(stats, null);
            }
        }

        function updateIndexConfigStaleBanner(stats, progress) {
            if (domain !== 'index') {
                return;
            }
            var wrap = tab.querySelector('[data-myapp-index-config-stale-wrap]');
            if (!wrap) {
                return;
            }
            var jobActive = progress ? !!progress.active : false;
            if (jobActive) {
                wrap.style.display = 'none';
                return;
            }
            var stale = stats && stats.index_config_stale;
            wrap.style.display = stale ? '' : 'none';
        }

        function applyResponse(data) {
            if (!data.ok) {
                throw new Error(data.error || labels.error || 'Error');
            }
            if (data.progress) {
                updateProgress(data.progress);
            }
            if (data.stats) {
                updateStats(data.stats);
            }
            if (domain === 'index' && data.stats) {
                updateIndexConfigStaleBanner(data.stats, data.progress || null);
            }
            return data;
        }

        function stopLoop() {
            polling = false;
            if (loopTimer !== null) {
                clearTimeout(loopTimer);
                loopTimer = null;
            }
        }

        function scheduleStep() {
            if (!polling) {
                return;
            }
            loopTimer = setTimeout(runStep, stepDelayMs);
        }

        function runStep() {
            if (!polling) {
                return;
            }
            post('step', { batch: String(getEffectiveBatchSize()) })
                .then(applyResponse)
                .then(function (data) {
                    var progress = data.progress || {};
                    if (progress.active) {
                        scheduleStep();
                        return;
                    }
                    stopLoop();
                    if (progress.status === 'completed') {
                        showMessage(labels.done || 'Готово', false);
                    } else if (progress.status === 'cancelled') {
                        showMessage(labels.cancelled || 'Отменено', false);
                    } else if (progress.status === 'failed') {
                        showMessage(progress.last_error || labels.failed || 'Ошибка', true);
                    }
                })
                .catch(function (err) {
                    stopLoop();
                    showMessage(err.message, true);
                });
        }

        function startLoop() {
            if (polling) {
                return;
            }
            polling = true;
            showMessage('', false);
            setProgressVisible(true);
            runStep();
        }

        function startJob(type, extra) {
            var payload = { type: type };
            if (extra) {
                Object.assign(payload, extra);
            }
            stopLoop();
            post('start', payload)
                .then(applyResponse)
                .then(function (data) {
                    var progress = data.progress || {};
                    if (progress.active || (progress.total > 0 && !progress.finished)) {
                        startLoop();
                    } else if (progress.finished) {
                        showMessage(labels.done || 'Готово', false);
                    }
                })
                .catch(function (err) {
                    showMessage(err.message, true);
                });
        }

        function bindEvents() {
            tab.querySelectorAll('[data-myapp-job-start]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var type = btn.getAttribute('data-myapp-job-start');
                    if (!type) {
                        return;
                    }
                    var extra = {};
                    if (type === 'section') {
                        var input = tab.querySelector('[data-myapp-section-id]');
                        var sectionId = input ? parseInt(input.value, 10) || 0 : 0;
                        if (sectionId <= 0) {
                            showMessage(labels.sectionIdRequired || 'Укажите ID раздела больше 0', true);
                            return;
                        }
                        extra.section_id = String(sectionId);
                    }
                    startJob(type, extra);
                });
            });

            tab.querySelectorAll('[data-myapp-job-cancel]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    stopLoop();
                    post('cancel', {})
                        .then(applyResponse)
                        .then(function () {
                            showMessage(labels.cancelled || 'Отменено', false);
                        })
                        .catch(function (err) {
                            showMessage(err.message, true);
                        });
                });
            });

            tab.querySelectorAll('[data-myapp-job-clear]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var confirmText = btn.getAttribute('data-confirm') || labels.confirmClear || 'Удалить?';
                    if (!window.confirm(confirmText)) {
                        return;
                    }
                    stopLoop();
                    post('clear', {})
                        .then(applyResponse)
                        .then(function () {
                            showMessage(labels.cleared || 'Очищено', false);
                        })
                        .catch(function (err) {
                            showMessage(err.message, true);
                        });
                });
            });
        }

        function resumeIfActive(progress) {
            if (!progress || !progress.active) {
                return;
            }
            setCancelButtonsState(true);
            setProgressVisible(true);
            startLoop();
        }

        function refreshStatus() {
            return post('status', {})
                .then(applyResponse)
                .then(function (data) {
                    if (data.progress && data.progress.active) {
                        resumeIfActive(data.progress);
                    } else if (initialProgress && initialProgress.active) {
                        setCancelButtonsState(false);
                    }
                })
                .catch(function (err) {
                    if (initialProgress && initialProgress.active) {
                        updateProgress(initialProgress);
                        setCancelButtonsState(true);
                        setProgressVisible(true);
                        resumeIfActive(initialProgress);
                    }
                    showMessage(err.message || labels.error || 'Error', true);
                });
        }

        bindEvents();
        if (initialProgress && initialProgress.active) {
            updateProgress(initialProgress);
            setCancelButtonsState(true);
            setProgressVisible(true);
            startLoop();
        } else if (cancelAlwaysVisible) {
            setCancelButtonsState(false);
        }
        refreshStatus();

        if (config.tabId) {
            var tabHeader = document.getElementById('tab_cont_' + config.tabId);
            if (tabHeader) {
                tabHeader.addEventListener('click', function () {
                    window.setTimeout(refreshStatus, 50);
                });
            }
        }

        return { refreshStatus: refreshStatus };
    }

    window.MyappCatalogFilterJobs = {
        init: function (items) {
            (items || []).forEach(function (config) {
                createController(config);
            });
        },
    };
})();
