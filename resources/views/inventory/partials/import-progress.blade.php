@php
    $theme = $theme ?? 'indigo';
    $isEmerald = $theme === 'emerald';
    $progressBorder = $isEmerald ? 'border-emerald-200' : 'border-indigo-200';
    $progressBg = $isEmerald ? 'bg-emerald-50' : 'bg-indigo-50';
    $progressLabel = $isEmerald ? 'text-emerald-600' : 'text-indigo-600';
    $progressText = $isEmerald ? 'text-emerald-900' : 'text-indigo-900';
    $progressStep = $isEmerald ? 'text-emerald-700' : 'text-indigo-700';
    $progressBadge = $isEmerald ? 'text-emerald-700 ring-emerald-200' : 'text-indigo-700 ring-indigo-200';
    $progressCount = $isEmerald ? 'text-emerald-800' : 'text-indigo-800';
    $progressBarTrack = $isEmerald ? 'ring-emerald-200' : 'ring-indigo-200';
    $progressBarFill = $isEmerald ? 'bg-emerald-600' : 'bg-indigo-600';
    $logPanelBorder = $isEmerald ? 'border-emerald-200' : 'border-indigo-200';
    $logLabel = $isEmerald ? 'text-emerald-600' : 'text-indigo-600';
    $logChevron = $isEmerald ? 'text-emerald-500' : 'text-indigo-500';
    $logBodyBorder = $isEmerald ? 'border-emerald-100' : 'border-indigo-100';
    $progressTitle = $progressTitle ?? 'Proceso en curso';
@endphp

<div id="import-progress" class="mt-8 hidden rounded-xl border {{ $progressBorder }} {{ $progressBg }} p-5">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0 flex-1">
            <p class="text-xs font-semibold uppercase tracking-wide {{ $progressLabel }}">{{ $progressTitle }}</p>
            <p id="progress-filename" class="mt-1 truncate text-sm font-medium text-slate-800"></p>
            <p id="progress-status" class="mt-2 text-sm {{ $progressText }}"></p>
            <p id="progress-step" class="mt-1 text-xs {{ $progressStep }}"></p>
        </div>
        <span id="progress-badge" class="shrink-0 rounded-full bg-white px-3 py-1 text-xs font-semibold {{ $progressBadge }} ring-1"></span>
    </div>

    <div class="mt-4">
        <div class="mb-1 flex items-center justify-between text-xs {{ $progressCount }}">
            <span id="progress-count">0 / 0 filas</span>
            <span id="progress-percent">0%</span>
        </div>
        <div class="h-2.5 overflow-hidden rounded-full bg-white ring-1 {{ $progressBarTrack }}">
            <div id="progress-bar" class="h-full rounded-full {{ $progressBarFill }} transition-all duration-500" style="width: 0%"></div>
        </div>
    </div>

    <p id="progress-stale" class="mt-3 hidden text-xs font-medium text-amber-800"></p>
    <div id="progress-stats" class="mt-4 hidden rounded-lg bg-white p-4 text-sm text-slate-700"></div>

    <div id="progress-log-panel" class="collapsible-panel mt-4 rounded-lg border {{ $logPanelBorder }} bg-white" data-default-expanded="true">
        <button type="button" class="collapsible-panel-toggle flex w-full items-center justify-between gap-3 px-4 py-3 text-left" aria-expanded="true">
            <span class="text-xs font-semibold uppercase tracking-wide {{ $logLabel }}">Log en vivo</span>
            <span class="flex shrink-0 items-center gap-2 text-xs text-slate-500">
                <span class="collapsible-panel-hint hidden sm:inline">Clic para ocultar</span>
                <svg class="collapsible-panel-chevron h-4 w-4 {{ $logChevron }} transition-transform" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
            </span>
        </button>
        <div class="collapsible-panel-body border-t {{ $logBodyBorder }} px-4 pb-4">
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
                <a id="skipped-download" href="#" class="inline-flex rounded-lg bg-amber-700 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-800" onclick="event.stopPropagation()">Descargar CSV</a>
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
                <a id="wp-log-download" href="#" class="hidden inline-flex rounded-lg bg-violet-700 px-3 py-2 text-xs font-semibold text-white hover:bg-violet-800" onclick="event.stopPropagation()">Descargar .log</a>
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

    @if ($showImageLogPanel ?? true)
        <div id="image-log-panel" class="collapsible-panel mt-6 hidden rounded-xl border border-slate-200 bg-slate-50" data-default-expanded="false">
            <button type="button" class="collapsible-panel-toggle flex w-full items-center justify-between gap-3 px-5 py-4 text-left" aria-expanded="false">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-600">Log de descarga de imágenes</p>
                    <p id="image-log-summary" class="mt-1 truncate text-sm text-slate-800"></p>
                </div>
                <span class="flex shrink-0 items-center gap-2">
                    <a id="image-log-download" href="#" class="hidden inline-flex rounded-lg bg-slate-700 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800" onclick="event.stopPropagation()">Descargar .log</a>
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
    @endif
</div>
