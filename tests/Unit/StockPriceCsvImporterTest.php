<?php

namespace Tests\Unit;

use App\Models\InventoryImport;
use App\Models\Product;
use App\Services\Inventory\InventoryImportMode;
use App\Services\Inventory\InventoryImportPhase;
use App\Services\Inventory\LocationResolver;
use App\Services\Inventory\ProductStockService;
use App\Services\Inventory\StockPriceCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StockPriceCsvImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_existing_product_stock_price_and_skips_unknown_sku(): void
    {
        $this->seedLocations();

        $po = app(LocationResolver::class)->resolveKnownStoreBySlug('puerto-ordaz');
        $lecheria = app(LocationResolver::class)->resolveKnownStoreBySlug('lecheria');
        $caracas = app(LocationResolver::class)->resolveKnownStoreBySlug('caracas');

        $product = Product::query()->create([
            'sku' => '6098',
            'name' => 'Producto existente',
            'price' => 100000,
        ]);

        $product->locations()->sync([
            $po->id => ['stock' => 1],
            $lecheria->id => ['stock' => 0],
            $caracas->id => ['stock' => 0],
        ]);

        $csv = implode("\n", [
            'SKU,Título,Cuenta ML,Cantidad,Precio,Precio en divisas,Divisa',
            '6098,Producto,Sede Puerto Ordaz (82385465),5,150000,40,USD',
            '6098,Producto,Sede Lechería (482845934),2,150000,40,USD',
            'NO-EXISTE,Fantasma,Sede Puerto Ordaz (82385465),1,100,10,USD',
        ]);

        $import = $this->runImport($csv);

        $this->assertSame(InventoryImportMode::STOCK_PRICE_XML, $import->importMode());
        $this->assertSame(1, $import->partial_stats['updated']);
        $this->assertSame(1, $import->partial_stats['prices_updated']);
        $this->assertSame(1, $import->partial_stats['stock_applied']);

        $product->refresh();
        $this->assertSame('150000.00', $product->price);
        $this->assertSame('40.00', $product->price_foreign);
        $this->assertSame('USD', $product->price_currency);

        $stocks = collect(app(ProductStockService::class)->stocksForProduct($product))->keyBy('slug');
        $this->assertSame(5, $stocks->get('puerto-ordaz')['stock']);
        $this->assertSame(2, $stocks->get('lecheria')['stock']);
        $this->assertSame(0, $stocks->get('caracas')['stock']);
        $this->assertNull(Product::query()->where('sku', 'NO-EXISTE')->first());
    }

    private function seedLocations(): void
    {
        app(LocationResolver::class)->resolveKnownStoreBySlug('puerto-ordaz');
        app(LocationResolver::class)->resolveKnownStoreBySlug('lecheria');
        app(LocationResolver::class)->resolveKnownStoreBySlug('caracas');
    }

    private function runImport(string $csv): InventoryImport
    {
        Storage::fake('local');

        $path = 'imports/test-stock-price.csv';
        Storage::disk('local')->put($path, $csv);

        $import = InventoryImport::query()->create([
            'disk' => 'local',
            'stored_path' => $path,
            'original_filename' => 'test-stock-price.csv',
            'import_mode' => InventoryImportMode::STOCK_PRICE_XML,
            'status' => InventoryImport::STATUS_PENDING,
        ]);

        $importer = app(StockPriceCsvImporter::class);
        $importer->prepareQueuedImport($import->fresh());

        $skip = 0;

        while (true) {
            $import->refresh();
            $result = $importer->processQueuedChunk($import, $skip);

            if ($result['phase_complete'] === InventoryImportPhase::CATALOG) {
                $importer->beginStockPhase($import->fresh());
                $skip = 0;

                continue;
            }

            if (! $result['has_more']) {
                break;
            }

            $skip = $result['next_skip'];
        }

        return $import->fresh();
    }
}
