<?php

namespace App\Services\Inventory;

use App\Models\Location;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductStockService
{
    /** @var list<string> */
    public const STORE_SLUG_ORDER = [
        LocationResolver::PRIMARY_LOCATION_SLUG,
        'lecheria',
        'caracas',
    ];

    public function __construct(
        private readonly LocationResolver $locationResolver,
    ) {}

    /**
     * @return Collection<int, Location>
     */
    public function knownLocations(): Collection
    {
        $locations = collect();

        foreach (self::STORE_SLUG_ORDER as $slug) {
            $locations->push($this->locationResolver->resolveKnownStoreBySlug($slug));
        }

        return $locations->unique('id')->values();
    }

    /**
     * Crea pivotes en las 3 sedes con stock 0 si aún no existen.
     */
    public function ensureAllStorePivots(Product $product): void
    {
        $sync = [];

        foreach ($this->knownLocations() as $location) {
            $attached = $product->locations()
                ->where('locations.id', $location->id)
                ->exists();

            if (! $attached) {
                $sync[$location->id] = ['stock' => 0];
            }
        }

        if ($sync !== []) {
            $product->locations()->syncWithoutDetaching($sync);
            $product->unsetRelation('locations');
        }
    }

    /**
     * Suma stock de las 3 sedes conocidas y guarda en products.principal_stock.
     */
    public function refreshPrincipalStock(Product $product): void
    {
        $locationIds = $this->knownLocations()->pluck('id')->all();

        $sum = (int) $product->locations()
            ->whereIn('locations.id', $locationIds)
            ->sum('location_product.stock');

        if ((int) $product->principal_stock !== $sum) {
            $product->forceFill(['principal_stock' => $sum])->saveQuietly();
        }
    }

    /**
     * Registra sedes faltantes, asigna stock absoluto (incluye 0) y recalcula stock principal.
     */
    public function setLocationStock(Product $product, int $locationId, int $stock): void
    {
        $this->ensureAllStorePivots($product);

        $product->locations()->syncWithoutDetaching([
            $locationId => ['stock' => max(0, $stock)],
        ]);

        $product->unsetRelation('locations');
        $this->refreshPrincipalStock($product);
    }

    /**
     * Al iniciar fase 2: Lechería y Caracas en 0; Puerto Ordaz conserva su stock de fase 1.
     */
    public function resetSecondaryStoreStocks(): int
    {
        $secondaryIds = $this->knownLocations()
            ->reject(fn (Location $location): bool => $location->slug === LocationResolver::PRIMARY_LOCATION_SLUG)
            ->pluck('id')
            ->all();

        if ($secondaryIds === []) {
            return 0;
        }

        $affected = DB::table('location_product')
            ->whereIn('location_id', $secondaryIds)
            ->update(['stock' => 0]);

        Product::query()->orderBy('id')->chunkById(100, function ($products): void {
            foreach ($products as $product) {
                $this->refreshPrincipalStock($product);
            }
        });

        return $affected;
    }

    /**
     * @return list<array{id: int, slug: string, name: string, stock: int, in_stock: bool, registered: bool}>
     */
    public function stocksForProduct(Product $product): array
    {
        if (! $product->relationLoaded('locations')) {
            $product->load('locations');
        }

        $byId = $product->locations->keyBy('id');

        return $this->knownLocations()
            ->map(static function (Location $location) use ($byId): array {
                $attached = $byId->get($location->id);

                return [
                    'id' => $location->id,
                    'slug' => $location->slug,
                    'name' => $location->name,
                    'stock' => $attached !== null ? (int) $attached->pivot->stock : 0,
                    'in_stock' => $attached !== null && (int) $attached->pivot->stock > 0,
                    'registered' => $attached !== null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{principal: int, by_location: list<array{slug: string, name: string, stock: int, in_stock: bool}>}
     */
    public function apiStockPayload(Product $product): array
    {
        $rows = $this->stocksForProduct($product);

        return [
            'principal' => (int) ($product->principal_stock ?? array_sum(array_column($rows, 'stock'))),
            'by_location' => array_map(static fn (array $row): array => [
                'slug' => $row['slug'],
                'name' => $row['name'],
                'stock' => $row['stock'],
                'in_stock' => $row['in_stock'],
            ], $rows),
        ];
    }

    public function backfillAllProducts(): int
    {
        $count = 0;

        Product::query()->orderBy('id')->chunkById(100, function ($products) use (&$count): void {
            foreach ($products as $product) {
                $this->ensureAllStorePivots($product);
                $this->refreshPrincipalStock($product);
                $count++;
            }
        });

        return $count;
    }
}
