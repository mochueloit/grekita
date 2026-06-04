<?php

namespace Tests\Unit;

use App\Services\Inventory\BrandExtractor;
use App\Services\Inventory\InventoryAssociationParser;
use App\Services\Inventory\InventoryHeaderResolver;
use App\Services\Inventory\InventoryProductRowParser;
use App\Services\Inventory\LocationResolver;
use App\Services\Inventory\ProductAttributeParser;
use App\Services\Inventory\ProductCategoryParser;
use App\Services\Inventory\ProductDescriptionCleaner;
use App\Services\Inventory\ProductDescriptionFormatter;
use App\Services\Inventory\ProductImageParser;
use App\Services\Inventory\ProductPriceParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryProductRowParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_primary_row_only_returns_stock_fields(): void
    {
        $headers = ['SKU', 'Título', 'Cuenta ML', 'Cantidad', 'Precio', 'Precio en divisas'];
        $resolver = new InventoryHeaderResolver($headers);
        $parser = $this->makeParser($resolver);

        $row = ['SKU-1', 'Nombre ML', 'Sede Lechería (482845934)', '2', '100', '25'];

        $parsed = $parser->parse($row);

        $this->assertNotNull($parsed);
        $this->assertFalse($parsed['is_primary_catalog']);
        $this->assertSame('SKU-1', $parsed['sku']);
        $this->assertArrayNotHasKey('price', $parsed);
        $this->assertArrayNotHasKey('name', $parsed);
    }

    public function test_primary_row_includes_catalog_and_prices(): void
    {
        $headers = ['SKU', 'Título', 'Cuenta ML', 'Cantidad', 'Precio', 'Precio en divisas', 'Divisa'];
        $resolver = new InventoryHeaderResolver($headers);
        $parser = $this->makeParser($resolver);

        $row = ['SKU-2', 'Producto PO', 'Sede Puerto Ordaz (82385465)', '1', '150000', '40', 'USD'];

        $parsed = $parser->parse($row);

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed['is_primary_catalog']);
        $this->assertSame('Producto PO', $parsed['name']);
        $this->assertSame(150000.0, $parsed['price']);
        $this->assertSame(40.0, $parsed['price_foreign']);
        $this->assertSame('USD', $parsed['price_currency']);
    }

    private function makeParser(InventoryHeaderResolver $resolver): InventoryProductRowParser
    {
        return new InventoryProductRowParser(
            $resolver,
            app(LocationResolver::class),
            new InventoryAssociationParser,
            new ProductAttributeParser,
            new ProductDescriptionCleaner,
            new ProductDescriptionFormatter,
            new BrandExtractor,
            new ProductImageParser,
            new ProductPriceParser,
            new ProductCategoryParser,
        );
    }
}
