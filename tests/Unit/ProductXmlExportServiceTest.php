<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Export\ProductXmlExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductXmlExportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_generates_xml_with_dated_folder_and_latest_copy(): void
    {
        $product = Product::query()->create([
            'sku' => 'XML-1',
            'name' => 'Producto XML',
            'principal_stock' => 0,
        ]);

        $width = \App\Models\AttributeDefinition::query()->create([
            'code' => 'WIDTH',
            'label_es' => 'Ancho',
            'slug' => 'width',
        ]);

        $product->attributeDefinitions()->attach($width->id, ['value' => '12 cm']);

        $result = app(ProductXmlExportService::class)->generate('test');

        $this->assertSame(1, $result['product_count']);
        $this->assertTrue(Storage::disk('local')->exists($result['relative_path']));
        $this->assertTrue(Storage::disk('local')->exists($result['latest_relative_path']));
        $this->assertTrue(Storage::disk('local')->exists($result['manifest_relative_path']));

        $xml = Storage::disk('local')->get($result['relative_path']);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<sku>XML-1</sku>', $xml);
        $this->assertStringContainsString('<width>12 cm</width>', $xml);
        $this->assertStringContainsString('<product_count>1</product_count>', $xml);
        $this->assertStringNotContainsString('<categories>', $xml);
    }
}
