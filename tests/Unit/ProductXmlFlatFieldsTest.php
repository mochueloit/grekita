<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Export\ProductXmlFlatFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductXmlFlatFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_from_database_joined_by_comma(): void
    {
        $product = Product::query()->create(['sku' => 'C-1', 'name' => 'Cat test']);

        $catA = Category::query()->create([
            'name' => 'Cerraduras',
            'slug' => 'cerraduras',
            'full_path' => 'Hogar > Cerraduras',
            'depth' => 2,
            'is_leaf' => true,
        ]);

        $catB = Category::query()->create([
            'name' => 'Digitales',
            'slug' => 'digitales',
            'full_path' => 'Hogar > Digitales',
            'depth' => 2,
            'is_leaf' => true,
        ]);

        $product->categories()->attach([$catA->id => ['sort_order' => 0], $catB->id => ['sort_order' => 1]]);

        $text = (new ProductXmlFlatFields)->categoriesTextFromProduct($product->fresh());

        $this->assertSame('Hogar > Cerraduras, Hogar > Digitales', $text);
    }

    public function test_dimensions_default_to_zero(): void
    {
        $dimensions = (new ProductXmlFlatFields)->dimensions([]);

        $this->assertSame('0', $dimensions['width']);
        $this->assertSame('0', $dimensions['length']);
    }

    public function test_local_image_urls_only_from_public_storage(): void
    {
        Storage::fake('public');

        $product = Product::query()->create(['sku' => 'IMG-1', 'name' => 'Img test']);

        Storage::disk('public')->put('products/a.jpg', 'binary');

        ProductImage::query()->create([
            'product_id' => $product->id,
            'source_url' => 'https://remote.example/a.jpg',
            'path' => 'products/a.jpg',
            'sort_order' => 0,
            'status' => ProductImage::STATUS_COMPLETED,
        ]);

        ProductImage::query()->create([
            'product_id' => $product->id,
            'source_url' => 'https://remote.example/b.jpg',
            'path' => null,
            'sort_order' => 1,
            'status' => ProductImage::STATUS_PENDING,
        ]);

        $urls = (new ProductXmlFlatFields)->localImageUrls($product->fresh());

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('/storage/products/a.jpg', $urls[0]);
    }
}
