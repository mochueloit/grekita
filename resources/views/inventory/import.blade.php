@extends('layouts.app')

@section('title', 'Importar productos nuevos — Grekita')
@section('heading', 'Importar productos nuevos')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4">
            <p class="text-sm text-slate-600">Catálogo completo desde MercadoLibre</p>
            <a
                href="{{ route('inventory.import.stock-price.show') }}"
                class="text-sm font-medium text-slate-900 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-900"
            >
                Actualizar precios y stock
            </a>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-slate-600">
                Sube el inventario exportado de MercadoLibre en <strong>CSV</strong> o <strong>Excel</strong>.
                Crea o actualiza productos, descarga imágenes, genera <strong>products.xml</strong> y sincroniza WordPress (importación #19).
            </p>
            <p class="mt-2 text-sm text-slate-500">
                Dos fases: catálogo e imágenes (Puerto Ordaz), luego stock de Lechería y Caracas.
            </p>

            <div id="import-alert" class="mt-5 hidden rounded-lg border px-4 py-3 text-sm"></div>

            <form id="import-form" action="{{ route('inventory.import.store') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-5">
                @csrf

                <div>
                    <label for="csv_file" class="mb-2 block text-sm font-medium text-slate-800">
                        Archivo de inventario (CSV o Excel)
                    </label>
                    <input
                        type="file"
                        name="csv_file"
                        id="csv_file"
                        accept=".csv,.txt,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        required
                        class="block w-full cursor-pointer rounded-lg border border-slate-300 bg-white text-sm text-slate-700 file:mr-4 file:rounded file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-slate-800"
                    >
                    <p class="mt-2 text-xs text-slate-400">Formatos: .csv, .xlsx, .xls (max. 50 MB).</p>
                </div>

                <button
                    type="submit"
                    id="import-submit"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Importar productos nuevos
                </button>
            </form>

            @include('inventory.partials.import-progress', [
                'progressTitle' => 'Importación en curso',
                'showImageLogPanel' => true,
            ])

            @include('inventory.partials.import-history', ['historyTitle' => 'Importaciones de catálogo recientes'])

            <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
                <p class="font-medium text-slate-800">Reglas — importación completa</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    <li><strong>Puerto Ordaz</strong> define ficha del producto (título, descripciones, imágenes, precios).</li>
                    <li>Lechería y Caracas solo aportan stock por SKU.</li>
                    <li>Las imágenes se encolan y descargan en cola aparte (~3 s entre cada una).</li>
                    <li>Al terminar: XML <code class="rounded bg-white px-1">products.xml</code> + WP All Import #19.</li>
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
            'submittingLabel' => 'Subiendo archivo...',
            'processingLabel' => 'Procesando en segundo plano...',
            'successComplete' => 'Importación y sincronización WordPress completadas.',
            'successPartial' => 'Productos importados. Imágenes y/o WordPress siguen en segundo plano.',
            'successWpActive' => 'Inventario listo. Sincronización WordPress en curso...',
            'showImageLog' => true,
            'activeImportId' => $activeImportId,
        ],
    ])
@endsection
