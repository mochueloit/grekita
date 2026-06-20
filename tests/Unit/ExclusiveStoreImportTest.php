<?php

namespace Tests\Unit;

use App\Models\InventoryImport;
use App\Models\Product;
use App\Services\Inventory\ExclusiveStoreCsvImporter;
use App\Services\Inventory\InventoryImportMode;
use App\Services\Inventory\LocationResolver;
use App\Services\Inventory\ProductStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExclusiveStoreImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_full_product_with_secondary_stock_and_zero_puerto_ordaz(): void
    {
        $this->seedKnownLocations();

        $csv = implode("\n", [
            'SKU,Título,Cuenta ML,Cantidad,Precio,Precio en divisas,Divisa',
            'COL-1,Collar exclusivo Lechería,Sede Lechería (482845934),3,50000,12,USD',
            'COL-1,Collar exclusivo Lechería,Sede Caracas (7196119),1,50000,12,USD',
        ]);

        $import = $this->runExclusiveImport($csv);

        $this->assertSame(InventoryImport::STATUS_COMPLETED, $import->fresh()->status);
        $this->assertSame(1, $import->partial_stats['created'] ?? 0);
        $this->assertSame(2, $import->partial_stats['stock_applied'] ?? 0);

        $product = Product::query()->where('sku', 'COL-1')->firstOrFail();
        $this->assertSame('Collar exclusivo Lechería', $product->name);
        $this->assertEquals(50000, (float) $product->price);

        $stocks = collect(app(ProductStockService::class)->stocksForProduct($product))->keyBy('slug');

        $this->assertSame(0, $stocks->get('puerto-ordaz')['stock']);
        $this->assertSame(3, $stocks->get('lecheria')['stock']);
        $this->assertSame(1, $stocks->get('caracas')['stock']);
        $this->assertSame(4, (int) $product->fresh()->principal_stock);
        $this->assertContains('COL-1', $import->fresh()->syncedSkusForExport());
    }

    public function test_creates_product_from_puerto_ordaz_row_when_present(): void
    {
        $this->seedKnownLocations();

        $csv = implode("\n", [
            'SKU,Título,Cuenta ML,Cantidad,Precio,Precio en divisas,Divisa',
            'PO-1,Producto PO,Sede Puerto Ordaz (82385465),5,100000,25,USD',
        ]);

        $import = $this->runExclusiveImport($csv);

        $this->assertSame(1, $import->partial_stats['created'] ?? 0);

        $product = Product::query()->where('sku', 'PO-1')->firstOrFail();
        $stocks = collect(app(ProductStockService::class)->stocksForProduct($product))->keyBy('slug');

        $this->assertSame(5, $stocks->get('puerto-ordaz')['stock']);
    }

    public function test_existing_sku_resets_missing_sedes_to_zero_and_applies_file_stock(): void
    {
        $this->seedKnownLocations();

        $po = app(LocationResolver::class)->resolveKnownStoreBySlug('puerto-ordaz');
        $lecheria = app(LocationResolver::class)->resolveKnownStoreBySlug('lecheria');
        $caracas = app(LocationResolver::class)->resolveKnownStoreBySlug('caracas');

        $product = Product::query()->create([
            'sku' => '6099',
            'name' => 'Ya en PO',
            'price' => 50000,
            'principal_stock' => 8,
        ]);

        $product->locations()->sync([
            $po->id => ['stock' => 5],
            $lecheria->id => ['stock' => 2],
            $caracas->id => ['stock' => 1],
        ]);

        $csv = implode("\n", [
            'SKU,Título,Cuenta ML,Cantidad,Precio,Precio en divisas,Divisa',
            '6099,Ya en PO,Sede Lechería (482845934),7,100000,25,USD',
        ]);

        $import = $this->runExclusiveImport($csv);

        $this->assertSame(0, $import->partial_stats['created'] ?? 0);
        $this->assertSame(1, $import->partial_stats['updated'] ?? 0);

        $product->refresh();
        $this->assertEquals(100000, (float) $product->price);
        $this->assertEquals(25, (float) $product->price_foreign);
        $this->assertSame('USD', $product->price_currency);

        $stocks = collect(app(ProductStockService::class)->stocksForProduct($product))->keyBy('slug');

        $this->assertSame(0, $stocks->get('puerto-ordaz')['stock']);
        $this->assertSame(7, $stocks->get('lecheria')['stock']);
        $this->assertSame(0, $stocks->get('caracas')['stock']);
    }

    public function test_existing_sku_with_po_row_uses_secondary_price_when_po_price_empty(): void
    {
        $this->seedKnownLocations();

        $po = app(LocationResolver::class)->resolveKnownStoreBySlug('puerto-ordaz');
        $lecheria = app(LocationResolver::class)->resolveKnownStoreBySlug('lecheria');

        $product = Product::query()->create([
            'sku' => 'MIX-1',
            'name' => 'Mixto',
            'price' => 1,
            'price_foreign' => 1,
            'price_currency' => 'USD',
        ]);

        $product->locations()->sync([
            $po->id => ['stock' => 2],
            $lecheria->id => ['stock' => 1],
        ]);

        $csv = implode("\n", [
            'SKU,Título,Cuenta ML,Cantidad,Precio,Precio en divisas,Divisa',
            'MIX-1,Mixto PO,Sede Puerto Ordaz (82385465),0,,,',
            'MIX-1,Mixto Lechería,Sede Lechería (482845934),4,88000,22,USD',
        ]);

        $import = $this->runExclusiveImport($csv);

        $product->refresh();

        $this->assertEquals(88000, (float) $product->price);
        $this->assertEquals(22, (float) $product->price_foreign);
        $this->assertSame('USD', $product->price_currency);
        $this->assertSame(1, $import->partial_stats['updated'] ?? 0);
    }

    private function seedKnownLocations(): void
    {
        app(LocationResolver::class)->resolveKnownStoreBySlug('puerto-ordaz');
        app(LocationResolver::class)->resolveKnownStoreBySlug('lecheria');
        app(LocationResolver::class)->resolveKnownStoreBySlug('caracas');
    }

    private function runExclusiveImport(string $csv): InventoryImport
    {
        Storage::fake('local');

        $path = 'imports/test-exclusive.csv';
        Storage::disk('local')->put($path, $csv);

        $import = InventoryImport::query()->create([
            'disk' => 'local',
            'stored_path' => $path,
            'original_filename' => 'test-exclusive.csv',
            'import_mode' => InventoryImportMode::EXCLUSIVE_STORE,
            'status' => InventoryImport::STATUS_PENDING,
        ]);

        $importer = app(ExclusiveStoreCsvImporter::class);
        $importer->prepareQueuedImport($import->fresh());

        $skip = 0;

        while (true) {
            $import->refresh();
            $result = $importer->processQueuedChunk($import, $skip);

            if (! $result['has_more']) {
                break;
            }

            $skip = $result['next_skip'];
        }

        $import->refresh();
        $import->update([
            'status' => InventoryImport::STATUS_COMPLETED,
            'stats' => $import->partial_stats,
            'completed_at' => now(),
        ]);

        return $import->fresh();
    }
}
