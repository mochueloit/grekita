@extends('layouts.app')

@section('title', 'Importar variaciones — Grekita')
@section('heading', 'Importar variaciones')

@section('content')
    <div class="mx-auto max-w-3xl">

        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-slate-600">
                Sube el Excel exportado de MercadoLibre con las variaciones.
                Convierte productos simples a variables en WooCommerce y asigna stock por sede.
            </p>
            <p class="mt-2 text-sm text-slate-500">
                Solo se procesan variaciones con SKU en formato <strong>SKUPadre + Letra</strong> (ej: <code class="rounded bg-slate-100 px-1">2295A</code>).
                Los demás quedan en el reporte de errores para corrección manual.
            </p>

            <div id="import-alert" class="mt-5 hidden rounded-lg border px-4 py-3 text-sm"></div>

            <form id="variation-form" action="{{ route('variations.import.store') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-5">
                @csrf

                <div>
                    <label for="excel_file" class="mb-2 block text-sm font-medium text-slate-800">
                        Archivo Excel de variaciones (.xlsx)
                    </label>
                    <input
                        type="file"
                        name="excel_file"
                        id="excel_file"
                        accept=".xlsx,.xls"
                        required
                        class="block w-full cursor-pointer rounded-lg border border-slate-300 bg-white text-sm text-slate-700 file:mr-4 file:rounded file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-slate-800"
                    >
                    <p class="mt-2 text-xs text-slate-400">Formatos: .xlsx, .xls (max. 50 MB).</p>
                </div>

                <button
                    type="submit"
                    id="variation-submit"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Importar variaciones
                </button>
            </form>

            {{-- Panel de progreso (igual al de los otros módulos) --}}
            <div id="import-progress" class="mt-6 hidden rounded-lg border border-slate-200 bg-slate-50 p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Proceso en curso</p>
                        <p id="progress-filename" class="mt-1 text-sm font-medium text-slate-900"></p>
                        <p id="progress-status" class="mt-2 text-sm text-slate-700"></p>
                    </div>
                    <span id="progress-badge" class="shrink-0 rounded-full border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-700"></span>
                </div>

                <div class="mt-4">
                    <div class="mb-1 flex items-center justify-between text-xs text-slate-600">
                        <span id="progress-count">0 / 0 filas</span>
                        <span id="progress-percent">0%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full border border-slate-200 bg-white">
                        <div id="progress-bar" class="h-full rounded-full bg-slate-900 transition-all duration-500" style="width: 0%"></div>
                    </div>
                </div>

                <div id="progress-stats" class="mt-4 hidden rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-700"></div>

                {{-- Log en vivo --}}
                <div id="progress-log-panel" class="collapsible-panel mt-4 rounded-lg border border-slate-200 bg-white" data-default-expanded="true">
                    <button type="button" class="collapsible-panel-toggle flex w-full items-center justify-between gap-3 px-4 py-3 text-left" aria-expanded="true">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Log en vivo</span>
                        <span class="flex shrink-0 items-center gap-2 text-xs text-slate-400">
                            <span class="collapsible-panel-hint hidden sm:inline">Clic para ocultar</span>
                            <svg class="collapsible-panel-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </span>
                    </button>
                    <div class="collapsible-panel-body border-t border-slate-100 px-4 pb-4">
                        <div class="mb-2 flex justify-end">
                            <button type="button" class="collapsible-panel-resize rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-600 hover:bg-slate-100">Ampliar</button>
                        </div>
                        <div id="progress-log" class="collapsible-panel-scroll max-h-36 overflow-y-auto rounded-lg bg-slate-900 p-3 font-mono text-xs leading-relaxed text-slate-300"></div>
                    </div>
                </div>

                <p id="progress-error" class="mt-3 hidden text-sm font-medium text-slate-900"></p>

                <div id="log-download-panel" class="mt-4 hidden">
                    <a id="log-download-link" href="#" class="inline-flex rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
                        Descargar log completo (.log)
                    </a>
                </div>

                <div id="error-report-panel" class="mt-4 hidden rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <p class="text-sm font-medium text-slate-800">Reporte de errores de validacion disponible.</p>
                    <p id="error-report-summary" class="mt-1 text-sm text-slate-600"></p>
                    <a id="error-report-download" href="#" class="mt-3 inline-flex rounded border border-slate-900 bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">
                        Descargar CSV de errores
                    </a>
                </div>
            </div>
        </div>

        {{-- Sync WooCommerce --}}
        <div class="mt-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-slate-800">Sincronizar con WooCommerce</p>
                    <p class="mt-1 text-xs text-slate-500">
                        Toma todas las variaciones guardadas localmente (pendientes) y las crea o actualiza en WordPress.
                    </p>
                </div>
                <button
                    id="sync-btn"
                    type="button"
                    class="inline-flex items-center justify-center rounded-lg border border-emerald-700 bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Sincronizar con WooCommerce
                </button>
            </div>

            <div id="sync-alert" class="mt-4 hidden rounded-lg border px-4 py-3 text-sm"></div>

            <div id="sync-progress" class="mt-4 hidden rounded-lg border border-slate-200 bg-slate-50 p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Sync en curso</p>
                        <p id="sync-status" class="mt-1 text-sm text-slate-700"></p>
                    </div>
                    <span id="sync-badge" class="shrink-0 rounded-full border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-700"></span>
                </div>

                <div id="sync-log-panel" class="collapsible-panel mt-4 rounded-lg border border-slate-200 bg-white" data-default-expanded="true">
                    <button type="button" class="collapsible-panel-toggle flex w-full items-center justify-between gap-3 px-4 py-3 text-left" aria-expanded="true">
                        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Log sync WooCommerce</span>
                        <span class="flex shrink-0 items-center gap-2 text-xs text-slate-400">
                            <span class="collapsible-panel-hint hidden sm:inline">Clic para ocultar</span>
                            <svg class="collapsible-panel-chevron h-4 w-4 text-slate-400 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </span>
                    </button>
                    <div class="collapsible-panel-body border-t border-slate-100 px-4 pb-4">
                        <div class="mb-2 flex justify-end">
                            <button type="button" class="collapsible-panel-resize rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-600 hover:bg-slate-100">Ampliar</button>
                        </div>
                        <div id="sync-log" class="collapsible-panel-scroll max-h-60 overflow-y-auto rounded-lg bg-slate-900 p-3 font-mono text-xs leading-relaxed text-slate-300"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Historial --}}
        @if ($imports->isNotEmpty())
            <div class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h2 class="text-sm font-semibold text-slate-800">Importaciones recientes</h2>
                </div>
                <ul class="divide-y divide-slate-100">
                    @foreach ($imports as $import)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-6 py-4 text-sm">
                            <div>
                                <span class="font-medium text-slate-800">#{{ $import->id }}</span>
                                <span class="ml-2 text-slate-500">{{ $import->original_filename }}</span>
                                <span class="ml-2 text-xs text-slate-400">{{ $import->created_at->format('d/m/Y H:i') }}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span @class([
                                    'inline-block rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-yellow-100 text-yellow-800' => in_array($import->status, ['pending', 'validating', 'processing']),
                                    'bg-green-100 text-green-800'  => $import->status === 'completed',
                                    'bg-red-100 text-red-800'      => $import->status === 'failed',
                                ])>
                                    {{ $import->statusLabel() }}
                                </span>
                                <span class="text-xs text-slate-500">
                                    {{ $import->successful_products }} ok
                                    &nbsp;{{ $import->failed_products }} fallidos
                                    &nbsp;{{ $import->skipped_products }} omitidos
                                </span>
                                @php($logPath = storage_path("logs/imports/import_{$import->id}.log"))
                                @if (file_exists($logPath))
                                    <a
                                        href="{{ route('variations.import.log', $import) }}"
                                        class="text-xs font-medium text-slate-600 underline decoration-slate-300 underline-offset-2 hover:text-slate-900"
                                    >
                                        Descargar log
                                    </a>
                                @endif
                                @if (isset(($import->checkpoint ?? [])['error_report']))
                                    <a
                                        href="{{ route('variations.import.report', $import) }}"
                                        class="text-xs font-medium text-slate-600 underline decoration-slate-300 underline-offset-2 hover:text-slate-900"
                                    >
                                        Descargar errores
                                    </a>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <script>
    (function () {
        const form        = document.getElementById('variation-form');
        const submitBtn   = document.getElementById('variation-submit');
        const alertBox    = document.getElementById('import-alert');
        const progressBox = document.getElementById('import-progress');
        const progressFilename = document.getElementById('progress-filename');
        const progressStatus   = document.getElementById('progress-status');
        const progressBadge    = document.getElementById('progress-badge');
        const progressStats    = document.getElementById('progress-stats');
        const progressError    = document.getElementById('progress-error');
        const progressBar      = document.getElementById('progress-bar');
        const progressCount    = document.getElementById('progress-count');
        const progressPercent  = document.getElementById('progress-percent');
        const progressLog      = document.getElementById('progress-log');
        const errorReportPanel    = document.getElementById('error-report-panel');
        const errorReportSummary  = document.getElementById('error-report-summary');
        const errorReportDownload = document.getElementById('error-report-download');
        const logDownloadPanel    = document.getElementById('log-download-panel');
        const logDownloadLink     = document.getElementById('log-download-link');

        const statusUrlTemplate = @json(route('variations.import.status', ['import' => '__ID__']));

        let pollTimer     = null;
        let lastLogLength = '';
        let logCache      = [];

        // ---- Collapsible panels ----
        document.querySelectorAll('.collapsible-panel').forEach((panel) => {
            const toggle    = panel.querySelector('.collapsible-panel-toggle');
            const body      = panel.querySelector('.collapsible-panel-body');
            const scroll    = panel.querySelector('.collapsible-panel-scroll');
            const resizeBtn = panel.querySelector('.collapsible-panel-resize');
            const chevron   = panel.querySelector('.collapsible-panel-chevron');
            const hint      = panel.querySelector('.collapsible-panel-hint');
            let expanded    = panel.dataset.defaultExpanded === 'true';
            let large       = false;

            function applyState() {
                body.classList.toggle('hidden', !expanded);
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                chevron?.classList.toggle('-rotate-180', expanded);
                if (hint) hint.textContent = expanded ? 'Clic para ocultar' : 'Clic para ver';
                if (scroll) {
                    scroll.classList.remove('max-h-36', 'max-h-80');
                    scroll.classList.add(large ? 'max-h-80' : 'max-h-36');
                }
                if (resizeBtn) {
                    resizeBtn.classList.toggle('hidden', !expanded);
                    resizeBtn.textContent = large ? 'Reducir' : 'Ampliar';
                }
            }

            toggle?.addEventListener('click', () => { expanded = !expanded; applyState(); });
            resizeBtn?.addEventListener('click', (e) => { e.stopPropagation(); large = !large; applyState(); });
            applyState();
        });

        // ---- Log rendering ----
        function renderLog(entries) {
            logCache = entries || [];
            if (logCache.length === 0) return;
            const lastKey = logCache.length + '|' + (logCache[logCache.length - 1]?.at ?? '') + '|' + (logCache[logCache.length - 1]?.message ?? '');
            if (lastKey === lastLogLength) return;
            lastLogLength = lastKey;

            progressLog.innerHTML = logCache.map(e =>
                `<div><span class="text-slate-500">[${e.at}]</span> ${e.message}</div>`
            ).join('');
            progressLog.scrollTop = progressLog.scrollHeight;
        }

        // ---- Render import state ----
        function renderImport(data) {
            progressBox.classList.remove('hidden');
            progressFilename.textContent = data.original_filename || '';
            progressStatus.textContent   = data.status_label;
            progressBadge.textContent    = data.status_label;

            const total   = data.total_rows ?? 0;
            const done    = data.processed_rows ?? 0;
            const percent = data.progress_percent ?? (total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0);

            progressCount.textContent   = total > 0 ? `${done} / ${total} filas` : `${done} filas procesadas`;
            progressPercent.textContent = `${percent}%`;
            progressBar.style.width     = `${percent}%`;

            renderLog(data.log_entries);

            progressError.classList.add('hidden');
            progressStats.classList.add('hidden');
            errorReportPanel.classList.add('hidden');
            logDownloadPanel.classList.add('hidden');

            if (data.log_download_url) {
                logDownloadLink.href = data.log_download_url;
                logDownloadPanel.classList.remove('hidden');
            }

            if (data.status === 'failed') {
                progressError.textContent = data.error_message || 'Error desconocido.';
                progressError.classList.remove('hidden');
                resetButton();
                clearInterval(pollTimer);
                return;
            }

            if (data.is_finished) {
                progressBar.style.width     = '100%';
                progressPercent.textContent = '100%';

                progressStats.innerHTML = `
                    <p>
                        <strong>${data.successful_products ?? 0}</strong> productos convertidos
                        &nbsp;&nbsp;
                        <strong>${data.failed_products ?? 0}</strong> fallidos
                        &nbsp;&nbsp;
                        <strong>${data.skipped_products ?? 0}</strong> errores de validacion
                    </p>`;
                progressStats.classList.remove('hidden');

                if (data.error_report_url) {
                    errorReportSummary.textContent = `${data.skipped_products ?? 0} filas con errores de validacion. Descarga el CSV para ver el detalle.`;
                    errorReportDownload.href = data.error_report_url;
                    errorReportPanel.classList.remove('hidden');
                }

                resetButton();
                clearInterval(pollTimer);
                showAlert('Importacion local completada. Iniciando sync con WooCommerce...', 'success');
                setTimeout(() => window.triggerSync?.(), 1000);
                return;
            }

            submitBtn.disabled    = true;
            submitBtn.textContent = 'Procesando en segundo plano...';
        }

        // ---- Form submit ----
        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            const fileInput = form.querySelector('input[type="file"]');
            if (!fileInput?.files?.length) {
                showAlert('Selecciona un archivo primero.', 'error');
                return;
            }

            submitBtn.disabled    = true;
            submitBtn.textContent = 'Subiendo archivo...';
            alertBox.classList.add('hidden');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                const payload = await response.json().catch(() => ({}));

                if (!response.ok) {
                    const err = payload.errors?.excel_file?.[0] || payload.message || 'Error al subir el archivo.';
                    throw new Error(err);
                }

                form.reset();
                lastLogLength = '';
                renderImport(payload.import);
                startPolling(payload.import.id);
                showAlert(payload.message || 'Archivo encolado.', 'success');

            } catch (err) {
                showAlert(err.message, 'error');
                resetButton();
            }
        });

        // ---- Polling ----
        async function poll(id) {
            try {
                const response = await fetch(statusUrlTemplate.replace('__ID__', id), {
                    headers: { 'Accept': 'application/json' },
                });
                if (!response.ok) throw new Error('Error consultando estado.');
                const payload = await response.json();
                renderImport(payload.import);
                if (payload.import.is_finished) clearInterval(pollTimer);
            } catch (err) {
                clearInterval(pollTimer);
                showAlert(err.message, 'error');
                resetButton();
            }
        }

        function startPolling(id) {
            clearInterval(pollTimer);
            lastLogLength = 0;
            poll(id);
            pollTimer = setInterval(() => poll(id), 2000);
        }

        function resetButton() {
            submitBtn.disabled    = false;
            submitBtn.textContent = 'Importar variaciones';
        }

        function showAlert(message, type) {
            alertBox.textContent  = message;
            alertBox.className    = 'mt-5 rounded-lg border px-4 py-3 text-sm ' + (
                type === 'error'
                    ? 'border-slate-400 bg-slate-100 text-slate-900'
                    : 'border-slate-300 bg-white text-slate-800'
            );
            alertBox.classList.remove('hidden');
        }

        // Si hay una importación activa al cargar, iniciar polling
        const activeImportId = @json($activeImportId);
        if (activeImportId) {
            startPolling(activeImportId);
        }
    })();

    // ---- Sync WooCommerce ----
    (function () {
        const syncBtn      = document.getElementById('sync-btn');
        const syncAlert    = document.getElementById('sync-alert');
        const syncProgress = document.getElementById('sync-progress');
        const syncStatus   = document.getElementById('sync-status');
        const syncBadge    = document.getElementById('sync-badge');
        const syncLog      = document.getElementById('sync-log');

        const statusUrlTemplate = @json(route('variations.import.status', ['import' => '__ID__']));
        const syncUrl           = @json(route('variations.sync'));

        let syncPollTimer    = null;
        let syncLastLogLen   = '';

        // Reusar la lógica de collapsible para el panel de sync
        document.querySelectorAll('#sync-log-panel').forEach((panel) => {
            const toggle    = panel.querySelector('.collapsible-panel-toggle');
            const body      = panel.querySelector('.collapsible-panel-body');
            const scroll    = panel.querySelector('.collapsible-panel-scroll');
            const resizeBtn = panel.querySelector('.collapsible-panel-resize');
            const chevron   = panel.querySelector('.collapsible-panel-chevron');
            const hint      = panel.querySelector('.collapsible-panel-hint');
            let expanded = true;
            let large    = false;

            function applyState() {
                body.classList.toggle('hidden', !expanded);
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                chevron?.classList.toggle('-rotate-180', expanded);
                if (hint) hint.textContent = expanded ? 'Clic para ocultar' : 'Clic para ver';
                if (scroll) {
                    scroll.classList.remove('max-h-60', 'max-h-[32rem]');
                    scroll.classList.add(large ? 'max-h-[32rem]' : 'max-h-60');
                }
                if (resizeBtn) {
                    resizeBtn.classList.toggle('hidden', !expanded);
                    resizeBtn.textContent = large ? 'Reducir' : 'Ampliar';
                }
            }
            toggle?.addEventListener('click', () => { expanded = !expanded; applyState(); });
            resizeBtn?.addEventListener('click', (e) => { e.stopPropagation(); large = !large; applyState(); });
            applyState();
        });

        function renderSyncLog(entries) {
            if (!entries || entries.length === 0) return;
            const lastKey = entries.length + '|' + (entries[entries.length - 1]?.at ?? '') + '|' + (entries[entries.length - 1]?.message ?? '');
            if (lastKey === syncLastLogLen) return;
            syncLastLogLen = lastKey;
            syncLog.innerHTML = entries.map(e =>
                `<div><span class="text-slate-500">[${e.at}]</span> ${e.message}</div>`
            ).join('');
            syncLog.scrollTop = syncLog.scrollHeight;
        }

        async function pollSync(id) {
            try {
                const res = await fetch(statusUrlTemplate.replace('__ID__', id), {
                    headers: { 'Accept': 'application/json' },
                });
                if (!res.ok) throw new Error('Error consultando estado sync.');
                const payload = await res.json();
                const imp = payload.import;

                syncStatus.textContent = imp.status_label;
                syncBadge.textContent  = imp.status_label;
                renderSyncLog(imp.log_entries);

                if (imp.is_finished || imp.status === 'failed') {
                    clearInterval(syncPollTimer);
                    syncBtn.disabled    = false;
                    syncBtn.textContent = 'Sincronizar con WooCommerce';
                    showSyncAlert(
                        imp.status === 'failed'
                            ? 'Sync terminó con errores. Revisa el log.'
                            : 'Sync completado.',
                        imp.status === 'failed' ? 'error' : 'success'
                    );
                }
            } catch (err) {
                clearInterval(syncPollTimer);
                showSyncAlert(err.message, 'error');
                syncBtn.disabled    = false;
                syncBtn.textContent = 'Sincronizar con WooCommerce';
            }
        }

        async function triggerSync() {
            syncBtn.disabled    = true;
            syncBtn.textContent = 'Encolando sync...';
            syncAlert.classList.add('hidden');

            try {
                const res = await fetch(syncUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                });
                const payload = await res.json().catch(() => ({}));

                if (!res.ok) {
                    throw new Error(payload.message || 'Error al iniciar sync.');
                }

                syncProgress.classList.remove('hidden');
                syncLastLogLen = '';
                syncStatus.textContent = payload.import.status_label;
                syncBadge.textContent  = payload.import.status_label;
                syncBtn.textContent    = 'Sincronizando...';

                showSyncAlert(payload.message, 'success');

                clearInterval(syncPollTimer);
                syncPollTimer = setInterval(() => pollSync(payload.import.id), 2000);

            } catch (err) {
                showSyncAlert(err.message, 'error');
                syncBtn.disabled    = false;
                syncBtn.textContent = 'Sincronizar con WooCommerce';
            }
        }

        // Botón manual para re-sync
        syncBtn.addEventListener('click', () => triggerSync());

        // Exponer para que el import auto-dispare el sync al terminar
        window.triggerSync = triggerSync;

        function showSyncAlert(message, type) {
            syncAlert.textContent = message;
            syncAlert.className   = 'mt-4 rounded-lg border px-4 py-3 text-sm ' + (
                type === 'error'
                    ? 'border-slate-400 bg-slate-100 text-slate-900'
                    : 'border-slate-300 bg-white text-slate-800'
            );
            syncAlert.classList.remove('hidden');
        }
    })();
    </script>
@endsection
