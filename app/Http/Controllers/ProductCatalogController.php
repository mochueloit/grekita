<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Product;
use App\Services\Inventory\LocationResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductCatalogController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $stockFilter = (string) $request->query('stock_filter', 'all');
        $primaryLocation = Location::query()
            ->where('slug', LocationResolver::PRIMARY_LOCATION_SLUG)
            ->first();

        $locationQuery = $request->query('location');

        if ($locationQuery === null) {
            $locationId = $primaryLocation?->id;
            $usingDefaultLocation = true;
        } elseif ($locationQuery === '' || $locationQuery === 'all') {
            $locationId = null;
            $usingDefaultLocation = false;
        } else {
            $locationId = (int) $locationQuery;
            $usingDefaultLocation = false;
        }

        $products = Product::query()
            ->with(['locations', 'images', 'attributeDefinitions', 'categories'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('sku', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%");
                });
            })
            ->when($locationId, function (Builder $query) use ($locationId, $stockFilter): void {
                $query->whereHas('locations', function (Builder $locationQuery) use ($locationId, $stockFilter): void {
                    $locationQuery->where('locations.id', $locationId);

                    if ($stockFilter === 'in_stock') {
                        $locationQuery->where('location_product.stock', '>', 0);
                    }
                });
            })
            ->when($stockFilter === 'in_stock' && ! $locationId, function (Builder $query): void {
                $query->whereHas('locations', fn (Builder $locationQuery) => $locationQuery->where('location_product.stock', '>', 0));
            })
            ->when($stockFilter === 'different_stock', function (Builder $query): void {
                $query->whereRaw('(
                    SELECT COUNT(DISTINCT location_product.stock)
                    FROM location_product
                    WHERE location_product.product_id = products.id
                ) > 1');
            })
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        $locations = Location::query()->orderBy('name')->get();

        $hasActiveFilters = $search !== ''
            || $stockFilter !== 'all'
            || $locationQuery !== null;

        return view('products.index', [
            'products' => $products,
            'locations' => $locations,
            'primaryLocation' => $primaryLocation,
            'search' => $search,
            'locationId' => $locationId,
            'usingDefaultLocation' => $usingDefaultLocation,
            'stockFilter' => $stockFilter,
            'hasActiveFilters' => $hasActiveFilters,
            'stats' => [
                'total' => Product::count(),
                'principal_stock_units' => (int) Product::query()->sum('principal_stock'),
                'in_stock' => Product::query()->where('principal_stock', '>', 0)->count(),
                'different_stock' => Product::query()
                    ->whereRaw('(
                        SELECT COUNT(DISTINCT location_product.stock)
                        FROM location_product
                        WHERE location_product.product_id = products.id
                    ) > 1')
                    ->count(),
            ],
        ]);
    }

    public function show(Product $product): View
    {
        $product->load(['locations', 'images', 'attributeDefinitions', 'categories', 'variations']);

        return view('products.show', [
            'product' => $product,
            'primaryLocationSlug' => LocationResolver::PRIMARY_LOCATION_SLUG,
        ]);
    }

    public function updatePrice(Request $request, Product $product): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'price'          => ['nullable', 'numeric', 'min:0'],
            'price_foreign'  => ['nullable', 'numeric', 'min:0'],
            'price_currency' => ['nullable', 'string', 'max:10'],
        ]);

        $product->update([
            'price'          => $data['price']          !== null ? (string) $data['price'] : null,
            'price_foreign'  => $data['price_foreign']  !== null ? (string) $data['price_foreign'] : null,
            'price_currency' => filled($data['price_currency']) ? strtoupper(trim($data['price_currency'])) : null,
        ]);

        return redirect()->route('products.show', $product)
            ->with('success', 'Precio actualizado correctamente.');
    }
}
