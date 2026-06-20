<?php

namespace Tests\Unit;

use App\Models\AttributeDefinition;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductImage;
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
        Storage::fake('public');
    }

    public function test_generates_xml_with_required_flat_fields(): void
    {
        $product = Product::query()->create([
            'sku' => 'XML-1',
            'name' => 'Producto XML',
            'brand' => 'Greka',
            'principal_stock' => 0,
        ]);

        $width = AttributeDefinition::query()->create([
            'code' => 'WIDTH',
            'label_es' => 'Ancho',
            'slug' => 'width',
        ]);

        $product->attributeDefinitions()->attach($width->id, ['value' => '12 cm']);

        $category = Category::query()->create([
            'name' => 'Rubik',
            'slug' => 'rubik',
            'full_path' => 'Juguetes > Rubik',
            'depth' => 2,
            'is_leaf' => true,
        ]);

        $product->categories()->attach($category->id, ['sort_order' => 0]);

        Storage::disk('public')->put('products/x.jpg', 'img');
        ProductImage::query()->create([
            'product_id' => $product->id,
            'source_url' => 'https://ml.example/x.jpg',
            'path' => 'products/x.jpg',
            'sort_order' => 0,
            'is_primary' => true,
            'status' => ProductImage::STATUS_COMPLETED,
        ]);

        $result = app(ProductXmlExportService::class)->generate('test');

        $xml = Storage::disk('local')->get($result['relative_path']);

        $this->assertStringContainsString('<brand>Greka</brand>', $xml);
        $this->assertStringContainsString('<categories><![CDATA[Juguetes > Rubik]]></categories>', $xml);
        $this->assertStringContainsString('<width>12 cm</width>', $xml);
        $this->assertStringContainsString('<height>0</height>', $xml);
        $this->assertStringContainsString('<length>0</length>', $xml);
        $this->assertStringContainsString('<images_urls>', $xml);
        $this->assertStringContainsString('/storage/products/x.jpg', $xml);
        $this->assertStringNotContainsString('ml.example', $xml);
    }

    public function test_stock_general_is_sum_of_three_locations(): void
    {
        $po = Location::query()->create(['name' => 'Sede Puerto Ordaz', 'slug' => 'puerto-ordaz']);
        $lecheria = Location::query()->create(['name' => 'Sede Lechería', 'slug' => 'lecheria']);
        $caracas = Location::query()->create(['name' => 'Sede Caracas', 'slug' => 'caracas']);

        $product = Product::query()->create([
            'sku' => '6098',
            'name' => 'Producto stock',
            'principal_stock' => 7,
        ]);

        $product->locations()->sync([
            $po->id => ['stock' => 5],
            $lecheria->id => ['stock' => 2],
            $caracas->id => ['stock' => 0],
        ]);

        $result = app(ProductXmlExportService::class)->generate('test');
        $xml = Storage::disk('local')->get($result['relative_path']);

        $this->assertStringContainsString('<stock_general>7</stock_general>', $xml);
    }

    public function test_generates_separate_stock_price_xml_without_catalog_fields(): void
    {
        $po = Location::query()->create(['name' => 'Sede Puerto Ordaz', 'slug' => 'puerto-ordaz']);

        $product = Product::query()->create([
            'sku' => '6098',
            'name' => 'Producto',
            'price' => 100000,
            'principal_stock' => 5,
        ]);

        $product->locations()->attach($po->id, ['stock' => 5]);

        $result = app(ProductXmlExportService::class)->generateStockPriceUpdate('test');

        $this->assertSame('stock_price', $result['export_type']);
        $this->assertStringContainsString('stock-price-update.xml', $result['latest_relative_path']);

        $xml = Storage::disk('local')->get($result['relative_path']);

        $this->assertStringContainsString('<sku>6098</sku>', $xml);
        $this->assertStringContainsString('<stock_general>5</stock_general>', $xml);
        $this->assertStringNotContainsString('<images>', $xml);
        $this->assertStringNotContainsString('<categories>', $xml);

        $this->assertFalse(Storage::disk('local')->exists('exports/wp-xml/latest/products.xml'));
    }

    public function test_stock_general_is_zero_when_all_locations_empty(): void
    {
        Location::query()->create(['name' => 'Sede Puerto Ordaz', 'slug' => 'puerto-ordaz']);
        Location::query()->create(['name' => 'Sede Lechería', 'slug' => 'lecheria']);
        Location::query()->create(['name' => 'Sede Caracas', 'slug' => 'caracas']);

        Product::query()->create([
            'sku' => 'SIN-STOCK',
            'name' => 'Sin stock',
            'principal_stock' => 0,
        ]);

        $result = app(ProductXmlExportService::class)->generate('test');
        $xml = Storage::disk('local')->get($result['relative_path']);

        $this->assertStringContainsString('<stock_general>0</stock_general>', $xml);
    }

    public function test_partial_export_only_includes_requested_skus(): void
    {
        Product::query()->create(['sku' => 'A-1', 'name' => 'Producto A', 'principal_stock' => 1]);
        Product::query()->create(['sku' => 'B-2', 'name' => 'Producto B', 'principal_stock' => 2]);
        Product::query()->create(['sku' => 'C-3', 'name' => 'Producto C', 'principal_stock' => 3]);

        $result = app(ProductXmlExportService::class)->generate('test', ['A-1', 'C-3']);

        $this->assertSame('partial', $result['export_type']);
        $this->assertSame('partial', $result['export_scope']);
        $this->assertSame(2, $result['product_count']);

        $xml = Storage::disk('local')->get($result['relative_path']);

        $this->assertStringContainsString('export_scope="partial"', $xml);
        $this->assertStringContainsString('<sku>A-1</sku>', $xml);
        $this->assertStringContainsString('<sku>C-3</sku>', $xml);
        $this->assertStringNotContainsString('<sku>B-2</sku>', $xml);
        $this->assertStringContainsString('<product_count>2</product_count>', $xml);
    }
}
