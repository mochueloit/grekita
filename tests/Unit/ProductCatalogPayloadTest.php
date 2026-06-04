<?php

namespace Tests\Unit;

use App\Services\Inventory\ProductCatalogPayload;
use PHPUnit\Framework\TestCase;

class ProductCatalogPayloadTest extends TestCase
{
    public function test_update_payload_omits_null_prices(): void
    {
        $attrs = ProductCatalogPayload::productAttributes([
            'sku' => 'ABC-1',
            'name' => 'Producto',
            'price' => null,
            'price_foreign' => 12.5,
            'price_currency' => 'USD',
        ], 'ABC-1');

        $this->assertArrayNotHasKey('price', $attrs);
        $this->assertSame(12.5, $attrs['price_foreign']);
        $this->assertSame('USD', $attrs['price_currency']);
    }

    public function test_update_payload_keeps_zero_price(): void
    {
        $attrs = ProductCatalogPayload::productAttributes([
            'sku' => 'ABC-1',
            'name' => 'Producto',
            'price' => 0,
        ], 'ABC-1');

        $this->assertArrayHasKey('price', $attrs);
        $this->assertSame(0, $attrs['price']);
    }
}
