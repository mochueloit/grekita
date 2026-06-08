@extends('layouts.app')

@section('title', 'Actualizar precios y stock — Grekita')
@section('heading', 'Actualizar precios y stock de productos')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-slate-500">Solo productos que ya existen en catálogo</p>
            <a
                href="{{ route('inventory.import.show') }}"
                class="inline-flex items-center rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-800 transition hover:bg-indigo-100"
            >
                ← Importar productos nuevos
            </a>
        </div>

        <div class="rounded-2xl border border-emerald-200 bg-white p-8 shadow-sm ring-1 ring-emerald-100">
            <p class="text-sm text-slate-600">
                Mismo CSV/Excel de MercadoLibre. Actualiza <strong>precios</strong> (Puerto Ordaz) y <strong>stock por sede</strong> (Cantidad).
                Genera <strong>stock-price-update.xml</strong> y dispara WordPress importación #20.
            </p>
            <p class="mt-2 text-sm text-emerald-800">
                Sin imágenes ni creación de productos — proceso mucho más rápido.
            </p>

            <div id="import-alert" class="mt-6 hidden rounded-lg border px-4 py-3 text-sm"></div>

            <form id="stock-price-form" action="{{ route('inventory.import.stock-price.store') }}" method="POST" enctype="multipart/form-data" class="mt-8 space-y-6">
                @csrf

                <div>
                    <label for="csv_file" class="mb-2 block text-sm font-medium text-slate-700">
                        Archivo CSV / Excel (stock y precios)
                    </label>
                    <input
                        type="file"
                        name="csv_file"
                        id="csv_file"
                        accept=".csv,.txt,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        required
                        class="block w-full cursor-pointer rounded-lg border border-emerald-200 bg-emerald-50/40 text-sm text-slate-700 file:mr-4 file:rounded-md file:border-0 file:bg-emerald-600 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-emerald-700"
                    >
                    <p class="mt-2 text-xs text-slate-400">SKU debe existir en catálogo. Filas desconocidas se omiten.</p>
                </div>

                <button
                    type="submit"
                    id="stock-price-submit"
                    class="inline-flex w-full items-center justify-center rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Actualizar precios y stock de productos
                </button>
            </form>

            @include('inventory.partials.import-progress', [
                'theme' => 'emerald',
                'progressTitle' => 'Actualización en curso',
                'showImageLogPanel' => false,
            ])

            @include('inventory.partials.import-history', ['historyTitle' => 'Actualizaciones recientes'])

            <div class="mt-8 rounded-lg bg-emerald-50 p-4 text-xs text-emerald-900/80">
                <p class="font-medium text-emerald-900">Reglas — actualización rápida</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    <li>Solo actualiza productos existentes (SKU en base de datos).</li>
                    <li>Fase 1: precio + stock Puerto Ordaz. Fase 2: stock Lechería y Caracas.</li>
                    <li>XML separado: <code class="rounded bg-white/80 px-1">stock-price-update.xml</code> (no pisa products.xml).</li>
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
            'submittingLabel' => 'Subiendo archivo…',
            'processingLabel' => 'Procesando en segundo plano…',
            'successComplete' => 'Precios y stock actualizados. XML y WordPress completados.',
            'successPartial' => 'Actualización en curso. WordPress sigue procesando…',
            'successWpActive' => 'Stock/precio listos. Sincronización WordPress en curso…',
            'showImageLog' => false,
            'activeImportId' => $activeImportId,
        ],
    ])
@endsection
