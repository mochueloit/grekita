@extends('layouts.app')

@section('title', 'Catálogo de Productos — Grekita')
@section('heading', 'Catálogo de productos')

@section('content')
    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Total productos</p>
            <p class="mt-1 text-3xl font-semibold text-slate-900">{{ number_format($stats['total']) }}</p>
        </div>
        <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-5 shadow-sm">
            <p class="text-sm text-indigo-700">Unidades stock principal</p>
            <p class="mt-1 text-3xl font-semibold text-indigo-900">{{ number_format($stats['principal_stock_units']) }}</p>
            <p class="mt-1 text-xs text-indigo-600">Suma PO + Lechería + Caracas</p>
        </div>
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
            <p class="text-sm text-emerald-700">Con stock principal &gt; 0</p>
            <p class="mt-1 text-3xl font-semibold text-emerald-900">{{ number_format($stats['in_stock']) }}</p>
        </div>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
            <p class="text-sm text-amber-700">Stock distinto entre sedes</p>
            <p class="mt-1 text-3xl font-semibold text-amber-900">{{ number_format($stats['different_stock']) }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Sedes activas</p>
            <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $locations->count() }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('products.index') }}" class="mb-8 space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid gap-3 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="q" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Buscar</label>
                <input
                    type="search"
                    name="q"
                    id="q"
                    value="{{ $search }}"
                    placeholder="SKU, nombre o marca..."
                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                >
            </div>
            <div>
                <label for="location" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Sede</label>
                <select
                    name="location"
                    id="location"
                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                >
                    <option value="">Todas las sedes</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected($locationId === $location->id)>
                            {{ $location->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="stock_filter" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-400">Stock</label>
                <select
                    name="stock_filter"
                    id="stock_filter"
                    class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
                >
                    <option value="all" @selected($stockFilter === 'all')>Ver todos</option>
                    <option value="in_stock" @selected($stockFilter === 'in_stock')>Con stock en sede</option>
                    <option value="different_stock" @selected($stockFilter === 'different_stock')>Stock distinto entre sedes</option>
                </select>
            </div>
        </div>
        <div class="flex flex-wrap gap-3">
            <button
                type="submit"
                class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700"
            >
                Aplicar filtros
            </button>
            @if ($hasActiveFilters)
                <a
                    href="{{ route('products.index') }}"
                    class="rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                >
                    Limpiar filtros
                </a>
            @endif
        </div>
        @if ($usingDefaultLocation && $primaryLocation)
            <p class="text-xs text-slate-500">
                Vista por defecto: catálogo <strong>{{ $primaryLocation->name }}</strong>. Cada tarjeta muestra stock de todas las sedes.
            </p>
        @endif
    </form>

    @if ($products->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center">
            <p class="text-lg font-medium text-slate-700">No hay productos para mostrar</p>
            <p class="mt-2 text-sm text-slate-500">
                @if ($search !== '')
                    No encontramos resultados para "{{ $search }}".
                @else
                    Sube el inventario CSV para empezar a ver productos aquí.
                @endif
            </p>
            <a
                href="{{ route('inventory.import.show') }}"
                class="mt-6 inline-flex rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
            >
                Ir a carga CSV
            </a>
        </div>
    @else
        <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($products as $product)
                <article class="flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:shadow-md">
                    <a href="{{ route('products.show', $product) }}" class="block overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                        @if ($product->primaryImage())
                            <img
                                src="{{ $product->primaryImage()->publicUrl() }}"
                                alt="{{ $product->name }}"
                                class="aspect-[4/3] w-full object-cover"
                                loading="lazy"
                            >
                        @else
                            <div class="flex aspect-[4/3] w-full items-center justify-center text-sm text-slate-400">
                                Sin imagen
                            </div>
                        @endif
                    </a>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                            SKU {{ $product->sku }}
                        </span>
                        @if ($product->brand)
                            <span class="inline-flex rounded-full bg-violet-100 px-2.5 py-1 text-xs font-medium text-violet-800">
                                {{ $product->brand }}
                            </span>
                        @endif
                        @if ($product->hasVariableStock())
                            <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800">
                                Stock variable
                            </span>
                        @endif
                    </div>

                    <h2 class="mt-3 text-base font-semibold leading-snug text-slate-900">
                        <a href="{{ route('products.show', $product) }}" class="hover:text-indigo-700">
                            {{ $product->name }}
                        </a>
                    </h2>

                    @if ($product->formattedPrice())
                        <p class="mt-2 text-sm font-semibold text-emerald-700">{{ $product->formattedPrice() }}</p>
                    @endif

                    @if ($product->warranty)
                        <p class="mt-1 text-xs text-slate-500">Garantía: {{ $product->warranty }}</p>
                    @endif

                    @if ($product->categories->isNotEmpty())
                        <div class="mt-3">
                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Categorías</p>
                            <div class="flex flex-col gap-1">
                                @foreach ($product->categories as $category)
                                    <span class="text-xs text-slate-600" title="{{ $category->full_path }}">
                                        {{ $category->full_path }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if ($product->short_description)
                        <p class="mt-2 line-clamp-2 text-sm text-slate-500">
                            {{ $product->short_description }}
                        </p>
                    @endif

                    <div class="mt-4 flex-1">
                        <div class="mb-2 flex flex-wrap items-center gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Stock principal</p>
                            <span @class([
                                'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                                'bg-indigo-600 text-white' => $product->principalStockTotal() > 0,
                                'bg-slate-200 text-slate-600' => $product->principalStockTotal() <= 0,
                            ])>
                                {{ $product->principalStockTotal() }} uds
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($product->knownStoreStocks() as $store)
                                @php($isPrimary = $store['slug'] === 'puerto-ordaz')
                                @php($isFilterHighlight = $locationId && (int) $store['id'] === (int) $locationId)
                                <span @class([
                                    'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium',
                                    'bg-indigo-50 text-indigo-900 ring-2 ring-indigo-300' => $isPrimary && $store['stock'] > 0,
                                    'bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200' => $isPrimary && $store['stock'] <= 0,
                                    'bg-emerald-50 text-emerald-800 ring-2 ring-emerald-400' => ! $isPrimary && $store['stock'] > 0 && $isFilterHighlight,
                                    'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200' => ! $isPrimary && $store['stock'] > 0 && ! $isFilterHighlight,
                                    'bg-slate-100 text-slate-500 ring-2 ring-slate-400' => ! $isPrimary && $store['stock'] <= 0 && $isFilterHighlight,
                                    'bg-slate-100 text-slate-500 ring-1 ring-slate-200' => ! $isPrimary && $store['stock'] <= 0 && ! $isFilterHighlight,
                                ])>
                                    {{ $store['name'] }}:
                                    <strong>{{ $store['stock'] }}</strong>
                                </span>
                            @endforeach
                        </div>
                    </div>

                    @if ($product->attributeDefinitions->isNotEmpty())
                        <div class="mt-5 border-t border-slate-100 pt-4">
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Atributos</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($product->attributeDefinitions->take(4) as $attribute)
                                    <span class="rounded-lg bg-indigo-50 px-2.5 py-1 text-xs text-indigo-800">
                                        <span class="font-medium">{{ $attribute->label_es }}:</span>
                                        {{ Str::limit($attribute->pivot->value, 40) }}
                                    </span>
                                @endforeach
                                @if ($product->attributeDefinitions->count() > 4)
                                    <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs text-slate-500">
                                        +{{ $product->attributeDefinitions->count() - 4 }} más
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="mt-5 border-t border-slate-100 pt-4">
                        <a
                            href="{{ route('products.show', $product) }}"
                            class="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                        >
                            Ver detalle completo →
                        </a>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="mt-8">
            {{ $products->links() }}
        </div>
    @endif
@endsection
