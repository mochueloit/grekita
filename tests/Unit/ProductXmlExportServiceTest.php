<?php

namespace Tests\Unit;

use App\Models\AttributeDefinition;
use App\Models\Category;
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
        $this->assertStringContainsString('<categories>Juguetes &gt; Rubik</categories>', $xml);
        $this->assertStringContainsString('<width>12 cm</width>', $xml);
        $this->assertStringContainsString('<height>0</height>', $xml);
        $this->assertStringContainsString('<length>0</length>', $xml);
        $this->assertStringContainsString('<images_urls>', $xml);
        $this->assertStringContainsString('/storage/products/x.jpg', $xml);
        $this->assertStringNotContainsString('ml.example', $xml);
    }
}
