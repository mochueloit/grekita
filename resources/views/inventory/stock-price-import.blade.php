@extends('layouts.app')

@section('title', 'Actualizar precios y stock — Grekita')
@section('heading', 'Actualizar precios y stock de productos')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4">
            <p class="text-sm text-slate-600">Solo productos que ya existen en catálogo</p>
            <a
                href="{{ route('inventory.import.show') }}"
                class="text-sm font-medium text-slate-900 underline decoration-slate-300 underline-offset-2 hover:decoration-slate-900"
            >
                Importar productos nuevos
            </a>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-slate-600">
                Mismo CSV/Excel de MercadoLibre. Actualiza <strong>precios</strong> (Puerto Ordaz) y <strong>stock por sede</strong> (Cantidad).
                Genera <strong>stock-price-update.xml</strong> y dispara WordPress importación #20.
            </p>
            <p class="mt-2 text-sm text-slate-500">
                Sin imágenes ni creación de productos — proceso más rápido.
            </p>

            <div id="import-alert" class="mt-5 hidden rounded-lg border px-4 py-3 text-sm"></div>

            <form id="stock-price-form" action="{{ route('inventory.import.stock-price.store') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-5">
                @csrf

                <div>
                    <label for="csv_file" class="mb-2 block text-sm font-medium text-slate-800">
                        Archivo CSV / Excel (stock y precios)
                    </label>
                    <input
                        type="file"
                        name="csv_file"
                        id="csv_file"
                        accept=".csv,.txt,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        required
                        class="block w-full cursor-pointer rounded-lg border border-slate-300 bg-white text-sm text-slate-700 file:mr-4 file:rounded file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-slate-800"
                    >
                    <p class="mt-2 text-xs text-slate-400">SKU debe existir en catálogo. Filas desconocidas se omiten.</p>
                </div>

                <button
                    type="submit"
                    id="stock-price-submit"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Actualizar precios y stock de productos
                </button>
            </form>

            @include('inventory.partials.import-progress', [
                'progressTitle' => 'Actualización en curso',
                'showImageLogPanel' => false,
            ])

            @include('inventory.partials.import-history', ['historyTitle' => 'Actualizaciones recientes'])

            <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
                <p class="font-medium text-slate-800">Reglas — actualización rápida</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    <li>Solo actualiza productos existentes (SKU en base de datos).</li>
                    <li>Fase 1: precio + stock Puerto Ordaz. Fase 2: stock Lechería y Caracas.</li>
                    <li>XML separado: <code class="rounded bg-white px-1">stock-price-update.xml</code> (no pisa products.xml).</li>
                    <li>WordPress: WP All Import #20 (solo actualizar por SKU).</li>
                </ul>
            </div>
        </div>
    </div>

    @include('inventory.partials.import-monitor-script', [
        'monitorConfig' => [
            'importMode' => 'stock_price_xml',
            'formId' => 'stock-price-form',
            'submitId' => 'stock-price-submit',
            'submitLabel' => 'Actualizar precios y stock de productos',
            'submittingLabel' => 'Subiendo archivo...',
            'processingLabel' => 'Procesando en segundo plano...',
            'successComplete' => 'Precios y stock actualizados. XML y WordPress completados.',
            'successPartial' => 'Actualización en curso. WordPress sigue procesando...',
            'successWpActive' => 'Stock/precio listos. Sincronización WordPress en curso...',
            'showImageLog' => false,
            'activeImportId' => $activeImportId,
        ],
    ])
@endsection
