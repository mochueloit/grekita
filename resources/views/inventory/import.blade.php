@extends('layouts.app')

@section('title', 'Panel de Carga — Grekita Inventario')
@section('heading', 'Panel de carga de inventario')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <p class="text-sm text-slate-500">
                Sube el inventario exportado de MercadoLibre en <strong>CSV</strong> o <strong>Excel</strong> (.xlsx / .xls).
                El archivo se guarda de inmediato. La importación corre en <strong>dos fases</strong>: primero catálogo e imágenes (solo Puerto Ordaz), luego una segunda lectura del mismo archivo para stock de Lechería y Caracas.
            </p>

            <div id="import-alert" class="mt-6 hidden rounded-lg border px-4 py-3 text-sm"></div>

            <form id="import-form" action="{{ route('inventory.import.store') }}" method="POST" enctype="multipart/form-data" class="mt-8 space-y-6">
                @csrf

                <div>
                    <label for="csv_file" class="mb-2 block text-sm font-medium text-slate-700">
                        Archivo de inventario (CSV o Excel)
                    </label>
                    <input
                        type="file"
                        name="csv_file"
                        id="csv_file"
                        accept=".csv,.txt,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        required
                        class="block w-full cursor-pointer rounded-lg border border-slate-300 bg-slate-50 text-sm text-slate-700 file:mr-4 file:rounded-md file:border-0 file:bg-indigo-600 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-indigo-700"
                    >
                    <p class="mt-2 text-xs text-slate-400">Formatos: .csv, .xlsx, .xls (máx. 50 MB). Se usa la primera hoja del Excel.</p>
                </div>

                <button
                    type="submit"
                    id="import-submit"
                    class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Subir archivo
                </button>
            </form>

            <div id="import-progress" class="mt-8 hidden rounded-xl border border-indigo-200 bg-indigo-50 p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Importación en curso</p>
                        <p id="progress-filename" class="mt-1 truncate text-sm font-medium text-slate-800"></p>
                        <p id="progress-status" class="mt-2 text-sm text-indigo-900"></p>
                        <p id="progress-step" class="mt-1 text-xs text-indigo-700"></p>
                    </div>
                    <span id="progress-badge" class="shrink-0 rounded-full bg-white px-3 py-1 text-xs font-semibold text-indigo-700 ring-1 ring-indigo-200"></span>
                </div>

                <div class="mt-4">
                    <div class="mb-1 flex items-center justify-between text-xs text-indigo-800">
                        <span id="progress-count">0 / 0 filas</span>
                        <span id="progress-percent">0%</span>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-white ring-1 ring-indigo-200">
                        <div id="progress-bar" class="h-full rounded-full bg-indigo-600 transition-all duration-500" style="width: 0%"></div>
                    </div>
                </div>

                <p id="progress-stale" class="mt-3 hidden text-xs font-medium text-amber-800"></p>

                <div id="progress-stats" class="mt-4 hidden rounded-lg bg-white p-4 text-sm text-slate-700"></div>

                <div id="progress-log-panel" class="collapsible-panel mt-4 rounded-lg border border-indigo-200 bg-white" data-default-expanded="true">
                    <button type="button" class="collapsible-panel-toggle flex w-full items-center justify-between gap-3 px-4 py-3 text-left" aria-expanded="true">
                        <span class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Log en vivo</span>
                        <span class="flex shrink-0 items-center gap-2 text-xs text-slate-500">
                            <span class="collapsible-panel-hint hidden sm:inline">Clic para ocultar</span>
                            <svg class="collapsible-panel-chevron h-4 w-4 text-indigo-500 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </span>
                    </button>
                    <div class="collapsible-panel-body border-t border-indigo-100 px-4 pb-4">
                        <div class="mb-2 flex justify-end">
                            <button type="button" class="collapsible-panel-resize rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-600 hover:bg-slate-100">Ampliar</button>
                        </div>
                        <div id="progress-log" class="collapsible-panel-scroll max-h-36 overflow-y-auto rounded-lg bg-slate-900 p-3 font-mono text-xs leading-relaxed text-emerald-400"></div>
                    </div>
                </div>

                <p id="progress-error" class="mt-3 hidden text-sm text-red-700"></p>

                <div id="skipped-panel" class="collapsible-panel mt-6 hidden rounded-xl border border-amber-200 bg-amber-50" data-default-expanded="false">
                    <button type="button" class="collapsible-panel-toggle flex w-full items-center justify-between gap-3 px-5 py-4 text-left" aria-expanded="false">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Filas omitidas</p>
                            <p id="skipped-summary" class="mt-1 text-sm text-amber-900"></p>
                        </div>
                        <span class="flex shrink-0 items-center gap-2">
                            <a
                                id="skipped-download"
                                href="#"
                                class="inline-flex rounded-lg bg-amber-700 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-800"
                                onclick="event.stopPropagation()"
                            >
                                Descargar CSV
                            </a>
                            <svg class="collapsible-panel-chevron h-4 w-4 text-amber-600 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </span>
                    </button>
                    <div class="collapsible-panel-body hidden border-t border-amber-200 px-5 pb-5">
                        <div class="mb-2 flex justify-end">
                            <button type="button" class="collapsible-panel-resize rounded border border-amber-200 bg-white px-2 py-1 text-xs text-amber-800 hover:bg-amber-100">Ampliar</button>
                        </div>
                        <div class="collapsible-panel-scroll max-h-40 overflow-auto rounded-lg border border-amber-200 bg-white">
                            <table class="min-w-full text-xs">
                                <thead class="sticky top-0 bg-slate-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Fila</th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600">SKU</th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Título</th>
                                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Motivo</th>
                                    </tr>
                                </thead>
                                <tbody id="skipped-table-body" class="divide-y divide-slate-100"></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="wp-log-panel" class="collapsible-panel mt-6 hidden rounded-xl border border-violet-200 bg-violet-50" data-default-expanded="false">
                    <button type="button" class="collapsible-panel-toggle flex w-full items-center justify-between gap-3 px-5 py-4 text-left" aria-expanded="false">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-violet-700">Sincronización WordPress (WP All Import)</p>
                            <p id="wp-log-summary" class="mt-1 truncate text-sm text-violet-900"></p>
                        </div>
                        <span class="flex shrink-0 items-center gap-2">
                            <a
                                id="wp-log-download"
                                href="#"
                                class="hidden inline-flex rounded-lg bg-violet-700 px-3 py-2 text-xs font-semibold text-white hover:bg-violet-800"
                                onclick="event.stopPropagation()"
                            >
                                Descargar .log
                            </a>
                            <svg class="collapsible-panel-chevron h-4 w-4 text-violet-500 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </span>
                    </button>
                    <div class="collapsible-panel-body hidden border-t border-violet-200 px-5 pb-5">
                        <div class="mb-2 flex justify-end">
                            <button type="button" class="collapsible-panel-resize rounded border border-violet-200 bg-white px-2 py-1 text-xs text-violet-800 hover:bg-violet-100">Ampliar</button>
                        </div>
                        <div id="wp-log-last-message" class="mb-3 hidden rounded-lg border border-violet-200 bg-white px-3 py-2 text-sm text-violet-900"></div>
                        <div class="mb-3 overflow-hidden rounded-lg border border-violet-200 bg-white">
                            <table class="min-w-full text-xs">
                                <thead class="bg-violet-50">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-semibold text-violet-800">Hora</th>
                                        <th class="px-3 py-2 text-left font-semibold text-violet-800">Acción</th>
                                        <th class="px-3 py-2 text-left font-semibold text-violet-800">Status</th>
                                        <th class="px-3 py-2 text-left font-semibold text-violet-800">Mensaje WP</th>
                                    </tr>
                                </thead>
                                <tbody id="wp-log-history-body" class="divide-y divide-violet-100">
                                    <tr><td colspan="4" class="px-3 py-2 text-slate-500">Sin respuestas aún.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div id="wp-log-content" class="collapsible-panel-scroll max-h-36 overflow-y-auto rounded-lg bg-slate-900 p-3 font-mono text-xs leading-relaxed text-violet-300">
                            <p class="text-slate-500">Cargando log WordPress…</p>
                        </div>
                    </div>
                </div>

                <div id="image-log-panel" class="collapsible-panel mt-6 hidden rounded-xl border border-slate-200 bg-slate-50" data-default-expanded="false">
                    <button type="button" class="collapsible-panel-toggle flex w-full items-center justify-between gap-3 px-5 py-4 text-left" aria-expanded="false">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Log de descarga de imágenes</p>
                            <p id="image-log-summary" class="mt-1 truncate text-sm text-slate-800"></p>
                        </div>
                        <span class="flex shrink-0 items-center gap-2">
                            <a
                                id="image-log-download"
                                href="#"
                                class="hidden inline-flex rounded-lg bg-slate-700 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                                onclick="event.stopPropagation()"
                            >
                                Descargar .log
                            </a>
                            <svg class="collapsible-panel-chevron h-4 w-4 text-slate-500 transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                        </span>
                    </button>
                    <div class="collapsible-panel-body hidden border-t border-slate-200 px-5 pb-5">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <p id="image-log-truncated" class="hidden text-xs text-slate-500"></p>
                            <button type="button" class="collapsible-panel-resize ml-auto rounded border border-slate-200 bg-white px-2 py-1 text-xs text-slate-600 hover:bg-slate-100">Ampliar</button>
                        </div>
                        <div id="image-log-content" class="collapsible-panel-scroll max-h-36 overflow-y-auto rounded-lg bg-slate-900 p-3 font-mono text-xs leading-relaxed text-sky-300">
                            <p class="text-slate-500">Cargando log…</p>
                        </div>
                    </div>
                </div>
            </div>

            @if ($imports->isNotEmpty())
                <div class="mt-8">
                    <p class="text-sm font-semibold text-slate-700">Importaciones recientes</p>
                    <div class="mt-3 overflow-hidden rounded-xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-600">Archivo</th>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-600">Estado</th>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-600">Fecha</th>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-600">Archivos</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($imports as $import)
                                    <tr>
                                        <td class="px-4 py-3 text-slate-700">{{ $import->original_filename }}</td>
                                        <td class="px-4 py-3">
                                            <span @class([
                                                'rounded-full px-2.5 py-1 text-xs font-medium',
                                                'bg-amber-100 text-amber-800' => in_array($import->status, ['pending', 'processing']),
                                                'bg-emerald-100 text-emerald-800' => $import->status === 'completed',
                                                'bg-red-100 text-red-800' => $import->status === 'failed',
                                            ])>{{ $import->statusLabel() }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-500">{{ $import->created_at?->format('d/m/Y H:i') }}</td>
                                        <td class="px-4 py-3 text-xs">
                                            <a href="{{ route('inventory.import.show', ['import' => $import->id]) }}" class="font-medium text-indigo-600 hover:text-indigo-800">Ver</a>
                                            @if ($import->image_download_log_path)
                                                · <a href="{{ route('inventory.import.images.log.download', $import) }}" class="text-slate-600 hover:text-slate-900">Log imágenes</a>
                                            @endif
                                            @if ($import->wp_sync_log_path)
                                                · <a href="{{ route('inventory.import.wp.log.download', $import) }}" class="text-violet-700 hover:text-violet-900">Log WordPress</a>
                                            @endif
                                            @if ($import->skipped_rows_csv_path)
                                                · <a href="{{ route('inventory.import.skipped.download', $import) }}" class="text-amber-700 hover:text-amber-900">CSV omitidas</a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="mt-8 rounded-lg bg-slate-50 p-4 text-xs text-slate-500">
                <p class="font-medium text-slate-700">Reglas de procesamiento</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    <li>La subida es inmediata; el procesamiento corre en <strong>lotes encadenados</strong> (cola Laravel).</li>
                    <li>Al subir un archivo se intenta iniciar el worker automáticamente en segundo plano.</li>
                    <li>Alternativa manual: <code class="rounded bg-slate-200 px-1">php artisan queue:work --stop-when-empty</code></li>
                    <li><strong>Sede Puerto Ordaz</strong> define título, descripción, marca y atributos del producto padre.</li>
                    <li>Lechería y Caracas solo aportan stock por SKU; no sobrescriben la ficha del producto.</li>
                    <li>Las imágenes se <strong>encolan</strong> al importar y se descargan despacio en cola aparte (~3 s entre cada una).</li>
                    <li>Las filas omitidas se guardan en CSV descargable junto a cada importación procesada.</li>
                    <li>La descarga de imágenes genera un <strong>.log</strong> persistente consultable desde importaciones completadas.</li>
                    <li>Al terminar inventario + imágenes + XML se ejecuta <strong>WP All Import</strong> (trigger una vez, processing cada 3 min mientras API status=200).</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('import-form');
            const submitBtn = document.getElementById('import-submit');
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
            const activeImportId = @json($activeImportId);
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
                    const isOk = line.includes('FIN —') || line.includes('API status 200');
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
                    waiting_images: 'Esperando imágenes',
                    exporting_xml: 'Generando XML',
                    triggering: 'Activando importación',
                    processing: 'Procesando en WordPress',
                    completed: 'Completado',
                    idle: 'Inactivo',
                };

                const phase = phaseLabel[wpSync.phase] ?? (wpSync.phase || 'Pendiente');
                const statusSuffix = wpSync.finished
                    ? ' — terminado'
                    : (wpSync.phase && wpSync.phase !== 'idle' ? ' — en curso' : '');

                if (wpSync.last_message) {
                    wpLogLastMessage.textContent = wpSync.last_message;
                    wpLogLastMessage.classList.remove('hidden');
                } else {
                    wpLogLastMessage.classList.add('hidden');
                }

                wpLogSummary.textContent = wpSync.last_api_status != null
                    ? `${phase} · API ${wpSync.last_api_status}${statusSuffix}`
                    : `${phase}${statusSuffix}`;

                try {
                    const response = await fetch(wpLogUrl(importData.id), {
                        headers: { 'Accept': 'application/json' },
                    });

                    if (!response.ok) {
                        wpLogContent.innerHTML = '<p class="text-slate-500">Aún no hay actividad de WordPress.</p>';
                        return;
                    }

                    const payload = await response.json();

                    if (payload.history && payload.history.length > 0) {
                        const rows = payload.history.slice(-40);
                        wpLogHistoryBody.innerHTML = rows.map(row => `
                            <tr>
                                <td class="px-3 py-2 text-slate-500">${row.at ?? '—'}</td>
                                <td class="px-3 py-2 font-medium text-violet-900">${row.action ?? '—'}${row.attempt ? ' #' + row.attempt : ''}</td>
                                <td class="px-3 py-2 ${row.api_status === 200 ? 'text-emerald-700' : 'text-amber-700'}">${row.api_status ?? '—'}</td>
                                <td class="px-3 py-2 text-slate-800">${row.message ?? '—'}</td>
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
                            wpLogContent.innerHTML = '<p class="text-slate-500">Esperando pipeline WordPress…</p>';
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
                        ? `Mostrando las últimas ${displayLines.length} de ${lines.length} líneas. Descarga el .log para ver todo.`
                        : '';
                }

                imageLogContent.innerHTML = displayLines.map(line => {
                    const isError = line.includes('[ERROR]') || line.includes('FALLO');
                    const isOk = line.includes('OK —') || line.includes('OK -');
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
                        imageLogContent.innerHTML = '<p class="text-slate-500">Aún no hay entradas en el log de imágenes.</p>';
                        return;
                    }

                    const payload = await response.json();
                    const stats = payload.stats ?? {};
                    const pending = (stats.pending ?? 0) + (stats.downloading ?? 0);
                    const finishedLabel = stats.finished ? ' — terminado' : ' — en progreso';

                    imageLogSummary.textContent = stats.total > 0
                        ? `${stats.completed ?? 0} descargadas, ${pending} pendientes, ${stats.failed ?? 0} fallidas${finishedLabel}`
                        : 'Sin imágenes encoladas en esta importación.';

                    if (!payload.lines || payload.lines.length === 0) {
                        imageLogLinesCache = [];
                        if (isImageLogExpanded()) {
                            imageLogContent.innerHTML = '<p class="text-slate-500">Esperando actividad de descarga…</p>';
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
                        imageLogContent.innerHTML = '<p class="text-slate-500">No se pudo cargar el log de imágenes.</p>';
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
                    + (importData.skipped_csv_saved ? ' El CSV quedó guardado en el servidor.' : '');
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
                            <td class="px-3 py-2 text-slate-600">${row.row ?? '—'}</td>
                            <td class="px-3 py-2 font-medium text-slate-800">${row.sku ?? '—'}</td>
                            <td class="px-3 py-2 text-slate-600">${row.title ?? '—'}</td>
                            <td class="px-3 py-2 text-slate-700">${row.reason ?? row.reason_code ?? '—'}</td>
                        </tr>
                    `).join('');
                } catch (error) {
                    skippedTableBody.innerHTML = '<tr><td colspan="4" class="px-3 py-2 text-slate-500">No se pudo cargar el detalle.</td></tr>';
                }
            }

            function renderStatsBlock(stats) {
                if (!stats) {
                    return '';
                }

                return `
                    <p><strong>${stats.processed ?? 0}</strong> filas procesadas ·
                    <strong>${stats.created ?? 0}</strong> creados ·
                    <strong>${stats.updated ?? 0}</strong> actualizados ·
                    <strong>${stats.skipped ?? 0}</strong> omitidas</p>
                    <p class="mt-1">Fase 2 stock: ${stats.stock_applied ?? 0} aplicado · ${stats.stock_skipped ?? 0} sin producto</p>
                    <p class="mt-1">Atributos: ${stats.attributes_synced ?? 0} · Imágenes en cola: ${stats.images_queued ?? 0}</p>
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
                        progressLog.innerHTML = '<p class="text-slate-500">Esperando actividad…</p>';
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
                progressFilename.textContent = importData.original_filename;
                progressStatus.textContent = importData.status_label;
                progressBadge.textContent = importData.status_label;
                const phaseLabel = importData.import_phase_label || '';
                progressStep.textContent = phaseLabel
                    ? `${phaseLabel}${importData.current_step ? ' · ' + importData.current_step : ''}`
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
                    progressStale.textContent = 'Sin actividad reciente. El worker debería retomar en breve.';
                    progressStale.classList.remove('hidden');
                }

                if (importData.queued_jobs > 0 && !importData.is_finished) {
                    progressStep.textContent = (importData.current_step ? `Paso: ${importData.current_step} · ` : '')
                        + `${importData.queued_jobs} lote(s) en cola`;
                }

                if (importData.image_downloads) {
                    const img = importData.image_downloads;
                    const imgPending = (img.pending ?? 0) + (img.downloading ?? 0);
                    if (img.total > 0 || imgPending > 0 || img.failed > 0) {
                        const finished = img.finished ? ' (imágenes terminadas)' : '';
                        progressStep.textContent = (progressStep.textContent ? progressStep.textContent + ' · ' : '')
                            + `Imágenes: ${img.completed ?? 0}/${img.total ?? 0} listas` + (imgPending ? `, ${imgPending} pendientes` : '') + (img.failed ? `, ${img.failed} fallidas` : '') + finished;
                    }
                }

                if (importData.wp_sync) {
                    const wp = importData.wp_sync;
                    if (wp.enabled && (wp.phase === 'processing' || wp.phase === 'triggering')) {
                        progressStep.textContent = (progressStep.textContent ? progressStep.textContent + ' · ' : '')
                            + `WordPress: ${wp.phase} (API ${wp.last_api_status ?? '…'})`;
                    }
                }

                renderLog(importData.log_entries);
                loadImageLog(importData);
                loadWpSyncLog(importData);

                progressError.classList.add('hidden');
                progressStats.classList.add('hidden');

                const liveStats = importData.partial_stats || importData.stats;
                if (liveStats && !importData.is_finished) {
                    progressStats.innerHTML = renderStatsBlock(liveStats);
                    progressStats.classList.remove('hidden');
                }

                if ((importData.skipped_count ?? 0) > 0) {
                    loadSkippedRows(importData);
                }

                if (importData.status === 'failed') {
                    progressError.textContent = importData.error_message || 'Error desconocido durante el procesamiento.';
                    progressError.classList.remove('hidden');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Subir archivo';
                    clearInterval(pollTimer);
                    return;
                }

                if (importData.status === 'completed' && importData.stats) {
                    progressStats.innerHTML = renderStatsBlock(importData.stats);
                    progressStats.classList.remove('hidden');
                    progressBar.style.width = '100%';
                    progressPercent.textContent = '100%';
                    loadSkippedRows(importData);
                    loadImageLog(importData);
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Subir archivo';

                    const imgPending = ((importData.image_downloads?.pending ?? 0) + (importData.image_downloads?.downloading ?? 0));
                    const wpActive = isWpSyncActive(importData);

                    if (imgPending === 0 && !wpActive) {
                        clearInterval(pollTimer);
                        showAlert('Importación y sincronización WordPress completadas.', 'success');
                    } else if (imgPending > 0) {
                        showAlert('Productos importados. Imágenes y/o WordPress siguen en segundo plano.', 'success');
                    } else if (wpActive) {
                        showAlert('Inventario listo. Sincronización WordPress en curso…', 'success');
                    }
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.textContent = 'Procesando en segundo plano…';
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

                    const img = payload.import.image_downloads ?? {};
                    const imgPending = (img.pending ?? 0) + (img.downloading ?? 0);

                    if (payload.import.is_finished && imgPending === 0 && !isWpSyncActive(payload.import)) {
                        clearInterval(pollTimer);
                    }
                } catch (error) {
                    clearInterval(pollTimer);
                    showAlert(error.message, 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Subir archivo';
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

            form.addEventListener('submit', async function (event) {
                event.preventDefault();

                const fileInput = document.getElementById('csv_file');
                if (!fileInput.files.length) {
                    showAlert('Selecciona un archivo primero.', 'error');
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.textContent = 'Subiendo archivo…';
                alertBox.classList.add('hidden');

                const formData = new FormData(form);

                try {
                    const response = await fetch(form.action, {
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

                    showAlert(payload.message, 'success');
                    renderImport(payload.import);
                    startPolling(payload.import.id);
                    form.reset();
                } catch (error) {
                    showAlert(error.message, 'error');
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Subir archivo';
                }
            });

            if (activeImportId) {
                startPolling(activeImportId);
            }

            initCollapsiblePanels();
        })();
    </script>
@endsection
