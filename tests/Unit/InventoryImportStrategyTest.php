<?php

namespace Tests\Unit;

use App\Services\Inventory\LocationResolver;
use PHPUnit\Framework\TestCase;

class InventoryImportStrategyTest extends TestCase
{
    public function test_primary_catalog_location_is_puerto_ordaz(): void
    {
        $this->assertSame('puerto-ordaz', LocationResolver::PRIMARY_LOCATION_SLUG);
    }
}
