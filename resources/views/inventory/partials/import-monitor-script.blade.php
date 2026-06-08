<script>
    (function () {
        const cfg = @json($monitorConfig);
        const form = document.getElementById(cfg.formId);
        const submitBtn = document.getElementById(cfg.submitId);
        const alertBox = document.getElementById('import-alert');
            const progressBox = document.getElementById('import-progress');
            const progressFilename = document.getElementById('progress-filename');
            const progressStatus = document.getElementById('progress-status');
            const progressBadge = document.getElementById('progress-badge');
            const progressStats = document.getElementById('progress-stats');
            const progressError = document.getElementById('progress-error');
            const progressBar = document.getElementById('progress-bar');
            const progressCount = document.getElementById('progress-count');
            const progressPercent = document.getElementById('progress-percent');
            const progressStep = document.getElementById('progress-step');
            const progressLog = document.getElementById('progress-log');
            const progressStale = document.getElementById('progress-stale');
            const skippedPanel = document.getElementById('skipped-panel');
            const skippedSummary = document.getElementById('skipped-summary');
            const skippedTableBody = document.getElementById('skipped-table-body');
            const skippedDownload = document.getElementById('skipped-download');
            const imageLogPanel = document.getElementById('image-log-panel');
            const imageLogSummary = document.getElementById('image-log-summary');
            const imageLogContent = document.getElementById('image-log-content');
            const imageLogDownload = document.getElementById('image-log-download');
            const imageLogTruncated = document.getElementById('image-log-truncated');
            const wpLogPanel = document.getElementById('wp-log-panel');
            const wpLogSummary = document.getElementById('wp-log-summary');
            const wpLogContent = document.getElementById('wp-log-content');
            const wpLogDownload = document.getElementById('wp-log-download');
            const wpLogLastMessage = document.getElementById('wp-log-last-message');
            const wpLogHistoryBody = document.getElementById('wp-log-history-body');
            const statusUrlTemplate = @json(route('inventory.import.status', ['import' => '__ID__']));
            const skippedUrlTemplate = @json(route('inventory.import.skipped', ['import' => '__ID__']));
            const imageLogUrlTemplate = @json(route('inventory.import.images.log', ['import' => '__ID__']));
            const wpLogUrlTemplate = @json(route('inventory.import.wp.log', ['import' => '__ID__']));
            const activeImportId = cfg.activeImportId;
            let pollTimer = null;
            let lastLogLength = 0;
            let lastImageLogLength = 0;
            let lastWpLogLength = 0;
            const IMAGE_LOG_DISPLAY_LINES = 80;

            const scrollHeights = {
                compact: 'max-h-36',
                large: 'max-h-80',
            };

            function initCollapsiblePanels() {
                document.querySelectorAll('.collapsible-panel').forEach((panel) => {
                    const toggle = panel.querySelector('.collapsible-panel-toggle');
                    const body = panel.querySelector('.collapsible-panel-body');
                    const scroll = panel.querySelector('.collapsible-panel-scroll');
                    const resizeBtn = panel.querySelector('.collapsible-panel-resize');
                    const chevron = panel.querySelector('.collapsible-panel-chevron');
                    const hint = panel.querySelector('.collapsible-panel-hint');
                    let expanded = panel.dataset.defaultExpanded === 'true';
                    let large = false;

                    function applyState() {
                        body.classList.toggle('hidden', !expanded);
                        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                        chevron?.classList.toggle('-rotate-180', expanded);
                        if (hint) {
                            hint.textContent = expanded ? 'Clic para ocultar' : 'Clic para ver';
                        }
                        if (scroll) {
                            scroll.classList.remove(scrollHeights.compact, scrollHeights.large);
                            scroll.classList.add(large ? scrollHeights.large : scrollHeights.compact);
                        }
                        if (resizeBtn) {
                            resizeBtn.classList.toggle('hidden', !expanded);
                            resizeBtn.textContent = large ? 'Reducir' : 'Ampliar';
                        }
                    }

                    toggle?.addEventListener('click', () => {
                        expanded = !expanded;
                        applyState();
                        if (expanded && panel.id === 'image-log-panel' && imageLogLinesCache.length > 0) {
                            renderImageLogLines(imageLogLinesCache);
                        }
                        if (expanded && panel.id === 'progress-log-panel' && progressLogCache.length > 0) {
                            renderProgressLogLines(progressLogCache);
                        }
                        if (expanded && panel.id === 'wp-log-panel' && wpLogLinesCache.length > 0) {
                            renderWpLogLines(wpLogLinesCache);
                        }
                    });

                    resizeBtn?.addEventListener('click', (event) => {
                        event.stopPropagation();
                        large = !large;
                        applyState();
                    });

                    applyState();
                });
            }

            function isPanelExpanded(panelId) {
                const panel = document.getElementById(panelId);
                const body = panel?.querySelector('.collapsible-panel-body');
                return body !== null && !body.classList.contains('hidden');
            }

            function isImageLogExpanded() {
                return isPanelExpanded('image-log-panel');
            }

            function isProgressLogExpanded() {
                return isPanelExpanded('progress-log-panel');
            }

            let imageLogLinesCache = [];
            let wpLogLinesCache = [];
            let progressLogCache = [];

            function wpLogUrl(id) {
                return wpLogUrlTemplate.replace('__ID__', id);
            }

            function renderWpLogLines(lines) {
                wpLogContent.innerHTML = lines.map(line => {
                    const isError = line.includes('[ERROR]') || line.includes('[WARN]');
                    const isOk = line.includes('FIN ΓÇö') || line.includes('API status 200');
                    const color = isError ? 'text-amber-300' : (isOk ? 'text-emerald-400' : 'text-violet-300');
                    return `<div class="${color}">${line}</div>`;
                }).join('');
                wpLogContent.scrollTop = wpLogContent.scrollHeight;
            }

            async function loadWpSyncLog(importData) {
                const wpSync = importData.wp_sync ?? {};
                const showPanel = wpSync.enabled || importData.wp_sync_log_download_url || importData.status === 'completed';

                if (!showPanel) {
                    wpLogPanel.classList.add('hidden');
                    return;
                }

                wpLogPanel.classList.remove('hidden');

                if (importData.wp_sync_log_download_url) {
                    wpLogDownload.href = importData.wp_sync_log_download_url;
                    wpLogDownload.classList.remove('hidden');
                } else {
                    wpLogDownload.classList.add('hidden');
                }

                const phaseLabel = {
                    waiting: 'En espera',
                    waiting_images: 'Esperando im├ígenes',
                    exporting_xml: 'Generando XML',
                    triggering: 'Activando importaci├│n',
                    processing: 'Procesando en WordPress',
                    completed: 'Completado',
                    idle: 'Inactivo',
                };

                const phase = phaseLabel[wpSync.phase] ?? (wpSync.phase || 'Pendiente');
                const statusSuffix = wpSync.finished
                    ? ' ΓÇö terminado'
                    : (wpSync.phase && wpSync.phase !== 'idle' ? ' ΓÇö en curso' : '');

                if (wpSync.last_message) {
                    wpLogLastMessage.textContent = wpSync.last_message;
                    wpLogLastMessage.classList.remove('hidden');
                } else {
                    wpLogLastMessage.classList.add('hidden');
                }

                wpLogSummary.textContent = wpSync.last_api_status != null
                    ? `${phase} ┬╖ API ${wpSync.last_api_status}${statusSuffix}`
                    : `${phase}${statusSuffix}`;

                try {
                    const response = await fetch(wpLogUrl(importData.id), {
                        headers: { 'Accept': 'application/json' },
                    });

                    if (!response.ok) {
                        wpLogContent.innerHTML = '<p class="text-slate-500">A├║n no hay actividad de WordPress.</p>';
                        return;
                    }

                    const payload = await response.json();

                    if (payload.history && payload.history.length > 0) {
                        const rows = payload.history.slice(-40);
                        wpLogHistoryBody.innerHTML = rows.map(row => `
                            <tr>
                                <td class="px-3 py-2 text-slate-500">${row.at ?? 'ΓÇö'}</td>
                                <td class="px-3 py-2 font-medium text-violet-900">${row.action ?? 'ΓÇö'}${row.attempt ? ' #' + row.attempt : ''}</td>
                                <td class="px-3 py-2 ${row.api_status === 200 ? 'text-emerald-700' : 'text-amber-700'}">${row.api_status ?? 'ΓÇö'}</td>
                                <td class="px-3 py-2 text-slate-800">${row.message ?? 'ΓÇö'}</td>
                            </tr>
                        `).join('');
                    }

                    if (payload.state?.last_message) {
                        wpLogLastMessage.textContent = payload.state.last_message;
                        wpLogLastMessage.classList.remove('hidden');
                    }

                    if (!payload.lines || payload.lines.length === 0) {
                        wpLogLinesCache = [];
                        if (isPanelExpanded('wp-log-panel')) {
                            wpLogContent.innerHTML = '<p class="text-slate-500">Esperando pipeline WordPressΓÇª</p>';
                        }
                        lastWpLogLength = 0;
                        return;
                    }

                    if (payload.lines.length === lastWpLogLength) {
                        return;
                    }

                    lastWpLogLength = payload.lines.length;
                    wpLogLinesCache = payload.lines;

                    if (isPanelExpanded('wp-log-panel')) {
                        renderWpLogLines(wpLogLinesCache);
                    }
                } catch (error) {
                    wpLogContent.innerHTML = '<p class="text-slate-500">No se pudo cargar el log de WordPress.</p>';
                }
            }

            function isWpSyncActive(importData) {
                const wpSync = importData.wp_sync ?? {};
                return wpSync.enabled === true && wpSync.finished !== true
                    && ['waiting', 'waiting_images', 'exporting_xml', 'triggering', 'processing'].includes(wpSync.phase);
            }

            function renderImageLogLines(lines) {
                const displayLines = lines.slice(-IMAGE_LOG_DISPLAY_LINES);
                const truncated = lines.length > displayLines.length;

                if (imageLogTruncated) {
                    imageLogTruncated.classList.toggle('hidden', !truncated);
                    imageLogTruncated.textContent = truncated
                        ? `Mostrando las ├║ltimas ${displayLines.length} de ${lines.length} l├¡neas. Descarga el .log para ver todo.`
                        : '';
                }

                imageLogContent.innerHTML = displayLines.map(line => {
                    const isError = line.includes('[ERROR]') || line.includes('FALLO');
                    const isOk = line.includes('OK ΓÇö') || line.includes('OK -');
                    const color = isError ? 'text-red-400' : (isOk ? 'text-emerald-400' : 'text-sky-300');
                    return `<div class="${color}">${line}</div>`;
                }).join('');
                imageLogContent.scrollTop = imageLogContent.scrollHeight;
            }

            function skippedUrl(id) {
                return skippedUrlTemplate.replace('__ID__', id);
            }

            function imageLogUrl(id) {
                return imageLogUrlTemplate.replace('__ID__', id);
            }

            async function loadImageLog(importData) {
                if (!cfg.showImageLog || !imageLogPanel) {
                    imageLogPanel?.classList.add('hidden');
                    return;
                }
                const hasImages = (importData.stats?.images_queued ?? importData.partial_stats?.images_queued ?? 0) > 0
                    || (importData.image_downloads?.total ?? 0) > 0
                    || importData.image_log_download_url;

                if (!hasImages && importData.status !== 'completed') {
                    imageLogPanel.classList.add('hidden');
                    return;
                }

                imageLogPanel.classList.remove('hidden');

                if (importData.image_log_download_url) {
                    imageLogDownload.href = importData.image_log_download_url;
                    imageLogDownload.classList.remove('hidden');
                } else {
                    imageLogDownload.classList.add('hidden');
                }

                try {
                    const response = await fetch(imageLogUrl(importData.id), {
                        headers: { 'Accept': 'application/json' },
                    });

                    if (!response.ok) {
                        imageLogContent.innerHTML = '<p class="text-slate-500">A├║n no hay entradas en el log de im├ígenes.</p>';
                        return;
                    }

                    const payload = await response.json();
                    const stats = payload.stats ?? {};
                    const pending = (stats.pending ?? 0) + (stats.downloading ?? 0);
                    const finishedLabel = stats.finished ? ' ΓÇö terminado' : ' ΓÇö en progreso';

                    imageLogSummary.textContent = stats.total > 0
                        ? `${stats.completed ?? 0} descargadas, ${pending} pendientes, ${stats.failed ?? 0} fallidas${finishedLabel}`
                        : 'Sin im├ígenes encoladas en esta importaci├│n.';

                    if (!payload.lines || payload.lines.length === 0) {
                        imageLogLinesCache = [];
                        if (isImageLogExpanded()) {
                            imageLogContent.innerHTML = '<p class="text-slate-500">Esperando actividad de descargaΓÇª</p>';
                        }
                        lastImageLogLength = 0;
                        return;
                    }

                    if (payload.lines.length === lastImageLogLength) {
                        return;
                    }

                    lastImageLogLength = payload.lines.length;
                    imageLogLinesCache = payload.lines;

                    if (isImageLogExpanded()) {
                        renderImageLogLines(imageLogLinesCache);
                    }
                } catch (error) {
                    if (isImageLogExpanded()) {
                        imageLogContent.innerHTML = '<p class="text-slate-500">No se pudo cargar el log de im├ígenes.</p>';
                    }
                }
            }

            async function loadSkippedRows(importData) {
                if (!importData.skipped_count || importData.skipped_count <= 0) {
                    skippedPanel.classList.add('hidden');
                    return;
                }

                skippedPanel.classList.remove('hidden');
                skippedSummary.textContent = `${importData.skipped_count} fila(s) no se importaron. Revisa el motivo, corrige el CSV/Excel y vuelve a subir.`
                    + (importData.skipped_csv_saved ? ' El CSV qued├│ guardado en el servidor.' : '');
                if (importData.skipped_download_url) {
                    skippedDownload.href = importData.skipped_download_url;
                }

                try {
                    const response = await fetch(skippedUrl(importData.id), {
                        headers: { 'Accept': 'application/json' },
                    });

                    if (!response.ok) {
                        return;
                    }

                    const payload = await response.json();
                    skippedTableBody.innerHTML = payload.rows.map(row => `
                        <tr>
                            <td class="px-3 py-2 text-slate-600">${row.row ?? 'ΓÇö'}</td>
                            <td class="px-3 py-2 font-medium text-slate-800">${row.sku ?? 'ΓÇö'}</td>
                            <td class="px-3 py-2 text-slate-600">${row.title ?? 'ΓÇö'}</td>
                            <td class="px-3 py-2 text-slate-700">${row.reason ?? row.reason_code ?? 'ΓÇö'}</td>
                        </tr>
                    `).join('');
                } catch (error) {
                    skippedTableBody.innerHTML = '<tr><td colspan="4" class="px-3 py-2 text-slate-500">No se pudo cargar el detalle.</td></tr>';
                }
            }

            function renderStatsBlock(stats, importData) {
                if (!stats) {
                    return '';
                }

                if (importData?.import_mode === 'stock_price_xml') {
                    return `
                        <p><strong>${stats.processed ?? 0}</strong> filas ┬╖
                        <strong>${stats.updated ?? 0}</strong> productos ┬╖
                        <strong>${stats.prices_updated ?? 0}</strong> precios ┬╖
                        <strong>${stats.skipped ?? 0}</strong> omitidas</p>
                        <p class="mt-1">Stock fase 2: ${stats.stock_applied ?? 0} aplicado ┬╖ ${stats.stock_skipped ?? 0} sin producto</p>
                    `;
                }

                return `
                    <p><strong>${stats.processed ?? 0}</strong> filas procesadas ┬╖
                    <strong>${stats.created ?? 0}</strong> creados ┬╖
                    <strong>${stats.updated ?? 0}</strong> actualizados ┬╖
                    <strong>${stats.skipped ?? 0}</strong> omitidas</p>
                    <p class="mt-1">Fase 2 stock: ${stats.stock_applied ?? 0} aplicado ┬╖ ${stats.stock_skipped ?? 0} sin producto</p>
                    <p class="mt-1">Atributos: ${stats.attributes_synced ?? 0} ┬╖ Im├ígenes en cola: ${stats.images_queued ?? 0}</p>
                `;
            }

            function renderProgressLogLines(entries) {
                const displayEntries = entries.slice(-80);
                progressLog.innerHTML = displayEntries.map(entry => (
                    `<div><span class="text-slate-500">[${entry.at}]</span> ${entry.message}</div>`
                )).join('');
                progressLog.scrollTop = progressLog.scrollHeight;
            }

            function renderLog(entries) {
                progressLogCache = entries || [];

                if (progressLogCache.length === 0) {
                    if (isProgressLogExpanded()) {
                        progressLog.innerHTML = '<p class="text-slate-500">Esperando actividadΓÇª</p>';
                    }
                    lastLogLength = 0;
                    return;
                }

                if (progressLogCache.length === lastLogLength) {
                    return;
                }

                lastLogLength = progressLogCache.length;

                if (isProgressLogExpanded()) {
                    renderProgressLogLines(progressLogCache);
                }
            }

            function renderImport(importData) {
                progressBox.classList.remove('hidden');
                progressFilename.textContent = importData.original_filename
                    + (importData.import_mode_label ? ' ┬╖ ' + importData.import_mode_label : '');
                progressStatus.textContent = importData.status_label;
                progressBadge.textContent = importData.status_label;
                const phaseLabel = importData.import_phase_label || '';
                progressStep.textContent = phaseLabel
                    ? `${phaseLabel}${importData.current_step ? ' ┬╖ ' + importData.current_step : ''}`
                    : (importData.current_step ? `Paso: ${importData.current_step}` : '');

                const total = importData.total_rows ?? 0;
                const done = importData.processed_rows ?? 0;
                const percent = importData.progress_percent ?? (total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0);

                progressCount.textContent = total > 0 ? `${done} / ${total} filas` : `${done} filas procesadas`;
                progressPercent.textContent = `${percent}%`;
                progressBar.style.width = `${percent}%`;

                progressStale.classList.add('hidden');
                if (importData.worker_hint) {
                    progressStale.textContent = importData.worker_hint;
                    progressStale.classList.remove('hidden');
                } else if (importData.is_stale && !importData.is_finished) {
                    progressStale.textContent = 'Sin actividad reciente. El worker deber├¡a retomar en breve.';
                    progressStale.classList.remove('hidden');
                }

                if (importData.queued_jobs > 0 && !importData.is_finished) {
                    progressStep.textContent = (importData.current_step ? `Paso: ${importData.current_step} ┬╖ ` : '')
                        + `${importData.queued_jobs} lote(s) en cola`;
                }

                if (importData.image_downloads) {
                    const img = importData.image_downloads;
                    const imgPending = (img.pending ?? 0) + (img.downloading ?? 0);
                    if (img.total > 0 || imgPending > 0 || img.failed > 0) {
                        const finished = img.finished ? ' (im├ígenes terminadas)' : '';
                        progressStep.textContent = (progressStep.textContent ? progressStep.textContent + ' ┬╖ ' : '')
                            + `Im├ígenes: ${img.completed ?? 0}/${img.total ?? 0} listas` + (imgPending ? `, ${imgPending} pendientes` : '') + (img.failed ? `, ${img.failed} fallidas` : '') + finished;
                    }
                }

                if (importData.wp_sync) {
                    const wp = importData.wp_sync;
                    if (wp.enabled && (wp.phase === 'processing' || wp.phase === 'triggering')) {
                        progressStep.textContent = (progressStep.textContent ? progressStep.textContent + ' ┬╖ ' : '')
                            + `WordPress: ${wp.phase} (API ${wp.last_api_status ?? 'ΓÇª'})`;
                    }
                }

                renderLog(importData.log_entries);
                loadImageLog(importData);
                loadWpSyncLog(importData);

                progressError.classList.add('hidden');
                progressStats.classList.add('hidden');

                const liveStats = importData.partial_stats || importData.stats;
                if (liveStats && !importData.is_finished) {
                    progressStats.innerHTML = renderStatsBlock(liveStats, importData);
                    progressStats.classList.remove('hidden');
                }

                if ((importData.skipped_count ?? 0) > 0) {
                    loadSkippedRows(importData);
                }

                if (importData.status === 'failed') {
                    progressError.textContent = importData.error_message || 'Error desconocido durante el procesamiento.';
                    progressError.classList.remove('hidden');
                    submitBtn.disabled = false;
                    submitBtn.textContent = cfg.submitLabel;
                    clearInterval(pollTimer);
                    return;
                }

                if (importData.status === 'completed' && importData.stats) {
                    progressStats.innerHTML = renderStatsBlock(importData.stats, importData);
                    progressStats.classList.remove('hidden');
                    progressBar.style.width = '100%';
                    progressPercent.textContent = '100%';
                    loadSkippedRows(importData);
                    loadImageLog(importData);
                    submitBtn.disabled = false;
                    submitBtn.textContent = cfg.submitLabel;

                    const isQuick = cfg.importMode === 'stock_price_xml';
                    const imgPending = isQuick ? 0 : ((importData.image_downloads?.pending ?? 0) + (importData.image_downloads?.downloading ?? 0));
                    const wpActive = isWpSyncActive(importData);

                    if (imgPending === 0 && !wpActive) {
                        clearInterval(pollTimer);
                        showAlert(cfg.successComplete, 'success');
                    } else if (imgPending > 0) {
                        showAlert(cfg.successPartial, 'success');
                    } else if (wpActive) {
                        showAlert(cfg.successWpActive, 'success');
                    }
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.textContent = cfg.processingLabel;
            }

            async function submitImportForm(targetForm, button, defaultLabel, uploadingLabel) {
                const fileInput = targetForm.querySelector('input[type="file"]');
                if (!fileInput?.files?.length) {
                    showAlert('Selecciona un archivo primero.', 'error');
                    return;
                }

                button.disabled = true;
                button.textContent = uploadingLabel;
                if (submitBtn) submitBtn.disabled = true;
                alertBox.classList.add('hidden');

                const formData = new FormData(targetForm);

                try {
                    const response = await fetch(targetForm.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        const validationError = payload.errors?.csv_file?.[0];
                        throw new Error(validationError || payload.message || 'Error al subir el archivo.');
                    }

                    progressBox.classList.remove('hidden');
                    renderImport(payload.import);
                    startPolling(payload.import.id);
                    targetForm.reset();
                    showAlert(payload.message || 'Archivo encolado.', 'success');
                } catch (error) {
                    showAlert(error.message, 'error');
                    button.disabled = false;
                    button.textContent = defaultLabel;
                    if (submitBtn) submitBtn.disabled = false;
                }
            }

            if (form && submitBtn) {
                form.addEventListener('submit', async function (event) {
                    event.preventDefault();
                    await submitImportForm(form, submitBtn, cfg.submitLabel, cfg.submittingLabel);
                });
            }

            async function pollImport(id) {
                try {
                    const response = await fetch(statusUrl(id), {
                        headers: { 'Accept': 'application/json' },
                    });

                    if (!response.ok) {
                        throw new Error('No se pudo consultar el estado.');
                    }

                    const payload = await response.json();
                    renderImport(payload.import);

                    const isQuick = cfg.importMode === 'stock_price_xml';
                    const img = payload.import.image_downloads ?? {};
                    const imgPending = isQuick ? 0 : ((img.pending ?? 0) + (img.downloading ?? 0));

                    if (payload.import.is_finished && imgPending === 0 && !isWpSyncActive(payload.import)) {
                        clearInterval(pollTimer);
                    }
                } catch (error) {
                    clearInterval(pollTimer);
                    showAlert(error.message, 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = cfg.submitLabel;
                }
            }

            function showAlert(message, type) {
                alertBox.textContent = message;
                alertBox.className = 'mt-6 rounded-lg border px-4 py-3 text-sm ' + (
                    type === 'error'
                        ? 'border-red-200 bg-red-50 text-red-800'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-800'
                );
                alertBox.classList.remove('hidden');
            }

            function statusUrl(id) {
                return statusUrlTemplate.replace('__ID__', id);
            }

            function startPolling(id) {
                clearInterval(pollTimer);
                lastLogLength = 0;
                lastImageLogLength = 0;
                pollImport(id);
                pollTimer = setInterval(() => pollImport(id), 2000);
            }

            if (activeImportId) {
                startPolling(activeImportId);
            }

        initCollapsiblePanels();
    })();
</script>
