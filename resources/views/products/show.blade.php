@extends('layouts.app')

@section('title', $product->name . ' — Grekita')
@section('heading', 'Detalle del producto')

@section('content')
    <div class="mb-6">
        <a
            href="{{ route('products.index') }}"
            class="inline-flex items-center gap-2 text-sm font-medium text-indigo-600 hover:text-indigo-800"
        >
            ← Volver al catálogo
        </a>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                        SKU {{ $product->sku }}
                    </span>
                    @if ($product->brand)
                        <span class="rounded-full bg-violet-100 px-3 py-1 text-xs font-medium text-violet-800">
                            Marca: {{ $product->brand }}
                        </span>
                    @endif
                    @if ($product->hasVariableStock())
                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800">
                            Stock distinto entre sedes
                        </span>
                    @endif
                </div>

                <h2 class="mt-4 text-2xl font-semibold text-slate-900">{{ $product->name }}</h2>

                @if ($product->formattedPrice())
                    <p class="mt-3 text-lg font-semibold text-emerald-700">{{ $product->formattedPrice() }}</p>
                @endif

                @if ($product->warranty)
                    <p class="mt-2 text-sm text-slate-600">
                        <span class="font-medium text-slate-700">Garantía:</span> {{ $product->warranty }}
                    </p>
                @endif

                @if ($product->short_description)
                    <p class="mt-4 text-sm leading-relaxed text-slate-600">{{ $product->short_description }}</p>
                @endif
            </section>

            @if ($product->categories->isNotEmpty())
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Categorías</h3>
                    <div class="mt-4 space-y-3">
                        @foreach ($product->categories as $category)
                            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                                <p class="text-sm font-medium text-slate-800">{{ $category->full_path }}</p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach ($category->segmentNames() as $segment)
                                        <span class="rounded-full bg-white px-2.5 py-1 text-xs text-slate-600 ring-1 ring-slate-200">
                                            {{ $segment }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Descripción para WordPress</h3>
                    @if ($product->long_description_html)
                        <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700">HTML listo</span>
                    @endif
                </div>

                @if ($product->long_description_html)
                    <div class="product-description mt-5 rounded-xl border border-slate-100 bg-slate-50/70 p-6">
                        {!! $product->long_description_html !!}
                    </div>
                @elseif ($product->long_description)
                    <div class="product-description mt-5 rounded-xl border border-slate-100 bg-slate-50/70 p-6">
                        <p>{{ $product->long_description }}</p>
                    </div>
                @else
                    <p class="mt-4 text-sm text-slate-400">Sin descripción disponible.</p>
                @endif
            </section>

            @if ($product->images->isNotEmpty())
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Imágenes</h3>
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
                        @foreach ($product->images as $image)
                            <a href="{{ $image->publicUrl() }}" target="_blank" rel="noopener" class="group block overflow-hidden rounded-xl border border-slate-200">
                                <img
                                    src="{{ $image->publicUrl() }}"
                                    alt="{{ $product->name }}"
                                    class="aspect-square w-full object-cover transition group-hover:scale-105"
                                    loading="lazy"
                                >
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-4">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-400">
                        Atributos del producto
                    </h3>
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-500">
                        {{ $product->attributeDefinitions->count() }} atributos
                    </span>
                </div>

                @if ($product->attributeDefinitions->isNotEmpty())
                    <div class="mt-4 overflow-hidden rounded-xl border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-600">Atributo</th>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-600">Valor</th>
                                    <th class="px-4 py-3 text-left font-semibold text-slate-600">Código ML</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($product->attributeDefinitions as $attribute)
                                    <tr @class(['bg-violet-50/60' => $attribute->code === 'BRAND'])>
                                        <td class="px-4 py-3 font-medium text-slate-700">{{ $attribute->label_es }}</td>
                                        <td class="px-4 py-3 text-slate-600">{{ $attribute->pivot->value }}</td>
                                        <td class="px-4 py-3 text-xs text-slate-400">{{ $attribute->code }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="mt-4 text-sm text-slate-400">Este producto no tiene atributos registrados.</p>
                @endif
            </section>
        </div>

        <div class="space-y-6">
            @if ($product->primaryImage())
                <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <img
                        src="{{ $product->primaryImage()->publicUrl() }}"
                        alt="{{ $product->name }}"
                        class="w-full rounded-xl object-cover"
                    >
                </section>
            @endif

            <section class="rounded-2xl border border-indigo-200 bg-indigo-50 p-6 shadow-sm">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-indigo-700">Stock principal</h3>
                <p class="mt-2 text-3xl font-bold text-indigo-900">{{ $product->principalStockTotal() }}</p>
                <p class="mt-1 text-xs text-indigo-600">Suma de las 3 sedes (Puerto Ordaz + Lechería + Caracas)</p>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Stock por sede</h3>
                <p class="mt-1 text-xs text-slate-500">Todas las sedes se registran en BD; si no hay fila en el inventario, el stock queda en 0 hasta la próxima importación.</p>

                <div class="mt-4 space-y-3">
                    @foreach ($product->knownStoreStocks() as $store)
                        @php($isPrimary = $store['slug'] === $primaryLocationSlug)
                        <div @class([
                            'flex items-center justify-between rounded-xl px-4 py-3',
                            'bg-indigo-50 ring-2 ring-indigo-200' => $isPrimary,
                            'bg-emerald-50 ring-1 ring-emerald-200' => ! $isPrimary && $store['stock'] > 0,
                            'bg-slate-50 ring-1 ring-slate-200' => ! $isPrimary && $store['stock'] <= 0,
                        ])>
                            <div>
                                <p @class([
                                    'text-sm font-medium',
                                    'text-indigo-900' => $isPrimary,
                                    'text-emerald-900' => ! $isPrimary && $store['stock'] > 0,
                                    'text-slate-600' => ! $isPrimary && $store['stock'] <= 0,
                                ])>
                                    {{ $store['name'] }}
                                    @if ($isPrimary)
                                        <span class="ml-1 text-xs font-normal text-indigo-600">(catálogo maestro)</span>
                                    @endif
                                </p>
                                <p class="text-xs text-slate-500">{{ $store['slug'] }}</p>
                            </div>
                            <div class="text-right">
                                <p @class([
                                    'text-xl font-semibold',
                                    'text-emerald-800' => $store['stock'] > 0,
                                    'text-slate-400' => $store['stock'] <= 0,
                                ])>{{ $store['stock'] }}</p>
                                <p class="text-xs {{ $store['stock'] > 0 ? 'text-emerald-700' : 'text-slate-400' }}">
                                    {{ $store['stock'] > 0 ? 'Con stock' : 'Registrado (0)' }}
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-400">Metadatos</h3>
                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-400">ID interno</dt>
                        <dd class="font-medium text-slate-700">{{ $product->id }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Marca extraída</dt>
                        <dd class="font-medium text-slate-700">{{ $product->brand ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Creado</dt>
                        <dd class="font-medium text-slate-700">{{ $product->created_at?->format('d/m/Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-400">Actualizado</dt>
                        <dd class="font-medium text-slate-700">{{ $product->updated_at?->format('d/m/Y H:i') }}</dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
@endsection
