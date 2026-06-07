<?php

namespace Tests\Unit;

use App\Models\Location;
use App\Models\Product;
use App\Services\Inventory\ProductStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStockServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_all_store_pivots_registers_missing_locations_with_zero(): void
    {
        $po = Location::query()->create(['name' => 'Sede Puerto Ordaz', 'slug' => 'puerto-ordaz']);
        $lecheria = Location::query()->create(['name' => 'Sede Lechería', 'slug' => 'lecheria']);
        $caracas = Location::query()->create(['name' => 'Sede Caracas', 'slug' => 'caracas']);

        $product = Product::query()->create([
            'sku' => 'TEST-1',
            'name' => 'Producto prueba',
        ]);

        $product->locations()->attach($po->id, ['stock' => 5]);

        app(ProductStockService::class)->ensureAllStorePivots($product->fresh());

        $product->refresh()->load('locations');

        $this->assertCount(3, $product->locations);
        $this->assertSame(5, (int) $product->locations->firstWhere('id', $po->id)?->pivot->stock);
        $this->assertSame(0, (int) $product->locations->firstWhere('id', $lecheria->id)?->pivot->stock);
        $this->assertSame(0, (int) $product->locations->firstWhere('id', $caracas->id)?->pivot->stock);
    }

    public function test_principal_stock_is_sum_of_three_stores(): void
    {
        $po = Location::query()->create(['name' => 'Sede Puerto Ordaz', 'slug' => 'puerto-ordaz']);
        $lecheria = Location::query()->create(['name' => 'Sede Lechería', 'slug' => 'lecheria']);
        $caracas = Location::query()->create(['name' => 'Sede Caracas', 'slug' => 'caracas']);

        $product = Product::query()->create([
            'sku' => 'TEST-2',
            'name' => 'Producto suma',
        ]);

        $product->locations()->sync([
            $po->id => ['stock' => 2],
            $lecheria->id => ['stock' => 3],
            $caracas->id => ['stock' => 0],
        ]);

        $service = app(ProductStockService::class);
        $service->refreshPrincipalStock($product->fresh());

        $this->assertSame(5, $product->fresh()->principal_stock);
    }

    public function test_set_location_stock_overwrites_including_zero(): void
    {
        $po = Location::query()->create(['name' => 'Sede Puerto Ordaz', 'slug' => 'puerto-ordaz']);
        $lecheria = Location::query()->create(['name' => 'Sede Lechería', 'slug' => 'lecheria']);

        $product = Product::query()->create([
            'sku' => '6098',
            'name' => 'Producto',
        ]);

        $product->locations()->sync([
            $po->id => ['stock' => 5],
            $lecheria->id => ['stock' => 10],
        ]);

        $service = app(ProductStockService::class);
        $service->setLocationStock($product->fresh(), $lecheria->id, 2);
        $service->setLocationStock($product->fresh(), $lecheria->id, 0);

        $product->refresh()->load('locations');

        $this->assertSame(5, (int) $product->locations->firstWhere('id', $po->id)?->pivot->stock);
        $this->assertSame(0, (int) $product->locations->firstWhere('id', $lecheria->id)?->pivot->stock);
        $this->assertSame(5, $product->fresh()->principal_stock);
    }
}
