<?php

namespace Tests\Unit;

use App\Models\InventoryImport;
use App\Services\Inventory\InventoryImportMode;
use App\Services\WordPress\WpAllImportClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WpAllImportClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_price_mode_uses_dedicated_import_id(): void
    {
        config([
            'wp_all_import.enabled' => true,
            'wp_all_import.import_key' => 'test-key',
            'wp_all_import.import_id' => '19',
            'wp_all_import.stock_price_import_id' => '20',
        ]);

        $import = InventoryImport::query()->create([
            'original_filename' => 'quick.csv',
            'stored_path' => 'imports/x.csv',
            'disk' => 'local',
            'import_mode' => InventoryImportMode::STOCK_PRICE_XML,
            'status' => InventoryImport::STATUS_PENDING,
        ]);

        $client = new WpAllImportClient;

        $this->assertSame('20', $client->resolveImportId($import));
        $this->assertStringContainsString('import_id=20', $client->buildUrl('trigger', $import));
    }

    public function test_full_import_uses_primary_import_id(): void
    {
        config([
            'wp_all_import.enabled' => true,
            'wp_all_import.import_key' => 'test-key',
            'wp_all_import.import_id' => '19',
            'wp_all_import.stock_price_import_id' => '20',
        ]);

        $import = InventoryImport::query()->create([
            'original_filename' => 'full.csv',
            'stored_path' => 'imports/y.csv',
            'disk' => 'local',
            'import_mode' => InventoryImportMode::FULL,
            'status' => InventoryImport::STATUS_PENDING,
        ]);

        $client = new WpAllImportClient;

        $this->assertSame('19', $client->resolveImportId($import));
    }
}
