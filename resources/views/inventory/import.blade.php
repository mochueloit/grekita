@extends('layouts.app')

@section('title', 'Importar productos nuevos — Grekita')
@section('heading', 'Importar productos nuevos')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-500">Catálogo completo desde MercadoLibre</p>
            <a
                href="{{ route('inventory.import.stock-price.show') }}"
                class="inline-flex items-center rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-800 transition hover:bg-emerald-100"
            >
                Actualizar precios y stock →
            </a>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <p class="text-sm text-slate-500">
                Sube el inventario exportado de MercadoLibre en <strong>CSV</strong> o <strong>Excel</strong>.
                Crea o actualiza productos, descarga imágenes, genera <strong>products.xml</strong> y sincroniza WordPress (importación #19).
            </p>
            <p class="mt-2 text-sm text-slate-500">
                Dos fases: catálogo e imágenes (Puerto Ordaz), luego stock de Lechería y Caracas.
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
                    <p class="mt-2 text-xs text-slate-400">Formatos: .csv, .xlsx, .xls (máx. 50 MB).</p>
                </div>

                <button
                    type="submit"
                    id="import-submit"
                    class="inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Importar productos nuevos
                </button>
            </form>

            @include('inventory.partials.import-progress', [
                'theme' => 'indigo',
                'progressTitle' => 'Importación en curso',
                'showImageLogPanel' => true,
            ])

            @include('inventory.partials.import-history', ['historyTitle' => 'Importaciones de catálogo recientes'])

            <div class="mt-8 rounded-lg bg-slate-50 p-4 text-xs text-slate-500">
                <p class="font-medium text-slate-700">Reglas — importación completa</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    <li><strong>Puerto Ordaz</strong> define ficha del producto (título, descripciones, imágenes, precios).</li>
                    <li>Lechería y Caracas solo aportan stock por SKU.</li>
                    <li>Las imágenes se encolan y descargan en cola aparte (~3 s entre cada una).</li>
                    <li>Al terminar: XML <code class="rounded bg-slate-200 px-1">products.xml</code> + WP All Import #19.</li>
                </ul>
            </div>
        </div>
    </div>

    @include('inventory.partials.import-monitor-script', [
        'monitorConfig' => [
            'importMode' => 'full',
            'formId' => 'import-form',
            'submitId' => 'import-submit',
            'submitLabel' => 'Importar productos nuevos',
            'submittingLabel' => 'Subiendo archivo…',
            'processingLabel' => 'Procesando en segundo plano…',
            'successComplete' => 'Importación y sincronización WordPress completadas.',
            'successPartial' => 'Productos importados. Imágenes y/o WordPress siguen en segundo plano.',
            'successWpActive' => 'Inventario listo. Sincronización WordPress en curso…',
            'showImageLog' => true,
            'activeImportId' => $activeImportId,
        ],
    ])
@endsection
