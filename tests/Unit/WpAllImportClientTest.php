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

    public function test_curl_timeout_should_retry_instead_of_finish(): void
    {
        $client = new WpAllImportClient;

        $this->assertTrue($client->shouldRetryAfterFailure([
            'should_continue' => false,
            'http_status' => 0,
            'api_status' => null,
            'message' => 'cURL error 28: Operation timed out after 120002 milliseconds with 0 bytes received',
        ]));
    }

    public function test_api_200_still_processing_is_not_retryable_failure(): void
    {
        $client = new WpAllImportClient;

        $this->assertFalse($client->shouldRetryAfterFailure([
            'should_continue' => true,
            'http_status' => 200,
            'api_status' => 200,
            'message' => 'Processing',
        ]));
    }

    public function test_http_504_gateway_timeout_is_retryable(): void
    {
        $client = new WpAllImportClient;

        $this->assertTrue($client->shouldRetryAfterFailure([
            'should_continue' => false,
            'http_status' => 504,
            'api_status' => null,
            'message' => '504 Gateway Time-out',
        ]));
    }

    public function test_import_finished_with_non_retryable_api_status(): void
    {
        $client = new WpAllImportClient;

        $this->assertFalse($client->shouldRetryAfterFailure([
            'should_continue' => false,
            'http_status' => 200,
            'api_status' => 403,
            'message' => 'Import complete',
        ]));
    }
}
