<?php

namespace Tests\Unit;

use App\Models\InventoryImport;
use App\Models\Product;
use App\Services\Inventory\InventoryCsvImporter;
use App\Services\Inventory\InventoryImportPhase;
use App\Services\Inventory\LocationResolver;
use App\Services\Inventory\ProductStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InventoryStockImportSku6098Test extends TestCase
{
    use RefreshDatabase;

    public function test_sync_import_applies_secondary_stock_for_sku_6098(): void
    {
        $this->seedKnownLocations();

        $csv = implode("\n", [
            'SKU,Título,Cuenta ML,Cantidad,Asociaciones,Precio,Precio en divisas,Divisa',
            '6098,Producto prueba 6098,Sede Puerto Ordaz (82385465),5,Cantidad: 99,100000,25,USD',
            '6098,Producto prueba 6098,Sede Lechería (482845934),2,,100000,25,USD',
        ]);

        $path = storage_path('framework/testing/sku-6098-sync.csv');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $csv);

        $stats = app(InventoryCsvImporter::class)->import($path);

        $this->assertSame(1, $stats['stock_applied'], 'import() fase 2 debe aplicar Lechería');
        $this->assertSame(1, $stats['created']);

        $product = Product::query()->where('sku', '6098')->firstOrFail();
        $stocks = collect(app(ProductStockService::class)->stocksForProduct($product))->keyBy('slug');

        $this->assertSame(5, $stocks->get('puerto-ordaz')['stock']);
        $this->assertSame(2, $stocks->get('lecheria')['stock']);
    }

    public function test_sku_6098_stock_from_cantidad_column_per_location(): void
    {
        $this->seedKnownLocations();

        $csv = implode("\n", [
            'SKU,Título,Cuenta ML,Cantidad,Asociaciones,Precio,Precio en divisas,Divisa',
            '6098,Producto prueba 6098,Sede Puerto Ordaz (82385465),5,Cantidad: 99,100000,25,USD',
            '6098,Producto prueba 6098,Sede Lechería (482845934),2,,100000,25,USD',
        ]);

        $import = $this->runFullImport($csv);

        $this->assertSame(InventoryImport::STATUS_COMPLETED, $import->fresh()->status);
        $this->assertSame(1, $import->partial_stats['stock_applied'] ?? 0, 'Fase 2 debe aplicar stock de Lechería');

        $product = Product::query()->where('sku', '6098')->first();
        $this->assertNotNull($product);

        $stocks = collect(app(ProductStockService::class)->stocksForProduct($product))
            ->keyBy('slug');

        $this->assertSame(5, $stocks->get('puerto-ordaz')['stock'], 'PO debe usar Cantidad=5, no asociaciones');
        $this->assertSame(2, $stocks->get('lecheria')['stock']);
        $this->assertSame(0, $stocks->get('caracas')['stock'], 'Sin fila Caracas → stock 0');
        $this->assertSame(7, (int) $product->fresh()->principal_stock);
    }

    public function test_sku_6098_secondary_stocks_reset_on_reimport(): void
    {
        $this->seedKnownLocations();

        $po = app(LocationResolver::class)->resolveKnownStoreBySlug('puerto-ordaz');
        $lecheria = app(LocationResolver::class)->resolveKnownStoreBySlug('lecheria');
        $caracas = app(LocationResolver::class)->resolveKnownStoreBySlug('caracas');

        $product = Product::query()->create([
            'sku' => '6098',
            'name' => 'Producto viejo',
        ]);

        $product->locations()->sync([
            $po->id => ['stock' => 1],
            $lecheria->id => ['stock' => 10],
            $caracas->id => ['stock' => 7],
        ]);

        $csv = implode("\n", [
            'SKU,Título,Cuenta ML,Cantidad,Precio,Precio en divisas,Divisa',
            '6098,Producto prueba 6098,Sede Puerto Ordaz (82385465),5,100000,25,USD',
            '6098,Producto prueba 6098,Sede Lechería (482845934),2,100000,25,USD',
        ]);

        $this->runFullImport($csv);

        $stocks = collect(app(ProductStockService::class)->stocksForProduct($product->fresh()))
            ->keyBy('slug');

        $this->assertSame(5, $stocks->get('puerto-ordaz')['stock']);
        $this->assertSame(2, $stocks->get('lecheria')['stock']);
        $this->assertSame(0, $stocks->get('caracas')['stock'], 'Caracas sin fila debe quedar en 0 tras reset fase 2');
    }

    private function seedKnownLocations(): void
    {
        app(LocationResolver::class)->resolveKnownStoreBySlug('puerto-ordaz');
        app(LocationResolver::class)->resolveKnownStoreBySlug('lecheria');
        app(LocationResolver::class)->resolveKnownStoreBySlug('caracas');
    }

    private function runFullImport(string $csv): InventoryImport
    {
        Storage::fake('local');

        $path = 'imports/test-sku-6098.csv';
        Storage::disk('local')->put($path, $csv);

        $import = InventoryImport::query()->create([
            'disk' => 'local',
            'stored_path' => $path,
            'original_filename' => 'test-sku-6098.csv',
            'status' => InventoryImport::STATUS_PENDING,
        ]);

        $importer = app(InventoryCsvImporter::class);
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

        $import->refresh();
        $import->update([
            'status' => InventoryImport::STATUS_COMPLETED,
            'stats' => $import->partial_stats,
            'completed_at' => now(),
        ]);

        return $import->fresh();
    }
}
