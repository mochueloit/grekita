@extends('layouts.app')

@section('title', 'Productos exclusivos por sede — Grekita')
@section('heading', 'Productos exclusivos por sede')

@section('content')
    <div class="mx-auto max-w-3xl">
        <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-slate-200 pb-4 text-sm">
            <a href="{{ route('inventory.import.show') }}" class="font-medium text-slate-600 underline decoration-slate-300 underline-offset-2 hover:text-slate-900">Importar productos nuevos</a>
            <span class="text-slate-300">·</span>
            <a href="{{ route('inventory.import.stock-price.show') }}" class="font-medium text-slate-600 underline decoration-slate-300 underline-offset-2 hover:text-slate-900">Precios y stock</a>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm text-slate-600">
                Importación <strong>parcial por SKU</strong>: un mismo código en todas las sedes, sin duplicar productos.
                Sirve para catálogo mixto (Puerto Ordaz + otras sedes) y productos que solo existen fuera de PO.
            </p>
            <p class="mt-2 text-sm text-slate-500">
                Solo se procesan filas con SKU. El resto va a omitidas.
            </p>

            <div id="import-alert" class="mt-5 hidden rounded-lg border px-4 py-3 text-sm"></div>

            <form id="exclusive-form" action="{{ route('inventory.import.exclusive.store') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-5">
                @csrf

                <div>
                    <label for="csv_file" class="mb-2 block text-sm font-medium text-slate-800">
                        Archivo CSV / Excel (Lechería y/o Caracas)
                    </label>
                    <input
                        type="file"
                        name="csv_file"
                        id="csv_file"
                        accept=".csv,.txt,.xlsx,.xls,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                        required
                        class="block w-full cursor-pointer rounded-lg border border-slate-300 bg-white text-sm text-slate-700 file:mr-4 file:rounded file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-slate-800"
                    >
                    <p class="mt-2 text-xs text-slate-400">Mismo formato MercadoLibre. Puede incluir filas de las 3 sedes.</p>
                </div>

                <button
                    type="submit"
                    id="exclusive-submit"
                    class="inline-flex w-full items-center justify-center rounded-lg border border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Importar productos exclusivos
                </button>
            </form>

            @include('inventory.partials.import-progress', [
                'progressTitle' => 'Importación exclusiva en curso',
                'showImageLogPanel' => true,
            ])

            @include('inventory.partials.import-history', ['historyTitle' => 'Importaciones exclusivas recientes'])

            <div class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
                <p class="font-medium text-slate-800">Reglas — multisede por SKU</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    <li><strong>Un SKU = un producto</strong> (nunca se duplica).</li>
                    <li>SKU <strong>no existe</strong> → se crea con ficha completa (desde cualquier sede del archivo).</li>
                    <li>SKU <strong>ya existe</strong> → solo se actualiza stock; catálogo se actualiza si hay fila de Puerto Ordaz.</li>
                    <li>Por cada SKU en el archivo: sedes <strong>con fila</strong> toman <strong>Cantidad</strong>; sedes <strong>sin fila</strong> quedan en <strong>0</strong>.</li>
                    <li>Filas <strong>sin SKU</strong> → omitidas (descargables).</li>
                    <li>XML parcial + WordPress #19. Luego «Precios y stock» para mantenimiento.</li>
                </ul>
            </div>
        </div>
    </div>

    @include('inventory.partials.import-monitor-script', [
        'monitorConfig' => [
            'importMode' => 'exclusive_store',
            'formId' => 'exclusive-form',
            'submitId' => 'exclusive-submit',
            'submitLabel' => 'Importar productos exclusivos',
            'submittingLabel' => 'Subiendo archivo...',
            'processingLabel' => 'Procesando en segundo plano...',
            'successComplete' => 'Productos exclusivos creados y WordPress sincronizado.',
            'successPartial' => 'Productos importados. Imágenes y/o WordPress siguen en segundo plano.',
            'successWpActive' => 'Inventario listo. Sincronización WordPress en curso...',
            'showImageLog' => true,
            'activeImportId' => $activeImportId,
        ],
    ])
@endsection
