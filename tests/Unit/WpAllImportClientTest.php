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

    public function test_curl_timeout_is_transient_not_finished(): void
    {
        $client = new WpAllImportClient;

        $response = [
            'should_continue' => false,
            'http_status' => 0,
            'api_status' => null,
            'message' => 'cURL error 28: Operation timed out after 120002 milliseconds with 0 bytes received',
        ];

        $this->assertTrue($client->shouldRetryAfterFailure($response));
        $this->assertFalse($client->isImportFinished($response));
    }

    public function test_cloudflare_524_in_message_is_transient(): void
    {
        $client = new WpAllImportClient;

        $response = [
            'should_continue' => false,
            'http_status' => 200,
            'api_status' => null,
            'message' => 'error code: 524',
        ];

        $this->assertTrue($client->shouldRetryAfterFailure($response));
        $this->assertFalse($client->isImportFinished($response));
    }

    public function test_api_200_means_still_processing(): void
    {
        $client = new WpAllImportClient;

        $response = [
            'should_continue' => true,
            'http_status' => 200,
            'api_status' => 200,
            'message' => 'Processing',
        ];

        $this->assertTrue($client->isStillProcessing($response));
        $this->assertFalse($client->isImportFinished($response));
    }

    public function test_non_200_non_transient_means_finished(): void
    {
        $client = new WpAllImportClient;

        $response = [
            'should_continue' => false,
            'http_status' => 200,
            'api_status' => 403,
            'message' => 'Import complete',
        ];

        $this->assertFalse($client->shouldRetryAfterFailure($response));
        $this->assertTrue($client->isImportFinished($response));
    }
}
