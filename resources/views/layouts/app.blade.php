<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Grekita Inventario')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-indigo-600">Grekita</p>
                <h1 class="text-lg font-semibold text-slate-900">@yield('heading', 'Inventario')</h1>
            </div>
            <nav class="flex items-center gap-2">
                <a
                    href="{{ route('products.index') }}"
                    @class([
                        'rounded-lg px-3 py-2 text-sm font-medium transition',
                        'bg-indigo-600 text-white' => request()->routeIs('products.*'),
                        'text-slate-600 hover:bg-slate-100' => ! request()->routeIs('products.*'),
                    ])
                >
                    Productos
                </a>
                <a
                    href="{{ route('inventory.import.show') }}"
                    @class([
                        'rounded-lg px-3 py-2 text-sm font-medium transition',
                        'bg-indigo-600 text-white' => request()->routeIs('inventory.import.show'),
                        'text-slate-600 hover:bg-slate-100' => ! request()->routeIs('inventory.import.show'),
                    ])
                >
                    Importar productos
                </a>
                <a
                    href="{{ route('inventory.import.stock-price.show') }}"
                    @class([
                        'rounded-lg px-3 py-2 text-sm font-medium transition',
                        'bg-emerald-600 text-white' => request()->routeIs('inventory.import.stock-price.*'),
                        'text-slate-600 hover:bg-slate-100' => ! request()->routeIs('inventory.import.stock-price.*'),
                    ])
                >
                    Precios y stock
                </a>
                @auth
                    <span class="hidden text-xs text-slate-500 sm:inline">{{ auth()->user()->email }}</span>
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button
                            type="submit"
                            class="rounded-lg px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100"
                        >
                            Salir
                        </button>
                    </form>
                @endauth
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        @yield('content')
    </main>
</body>
</html>
