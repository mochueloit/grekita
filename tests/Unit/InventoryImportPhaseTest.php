<?php

namespace Tests\Unit;

use App\Services\Inventory\InventoryImportPhase;
use PHPUnit\Framework\TestCase;

class InventoryImportPhaseTest extends TestCase
{
    public function test_catalog_phase_only_accepts_primary_rows(): void
    {
        $row = ['is_primary_catalog' => true, 'sku' => 'A'];

        $this->assertTrue(InventoryImportPhase::acceptsRow($row, InventoryImportPhase::CATALOG));
        $this->assertFalse(InventoryImportPhase::acceptsRow(['is_primary_catalog' => false], InventoryImportPhase::CATALOG));
    }

    public function test_stock_phase_only_accepts_secondary_rows(): void
    {
        $row = ['is_primary_catalog' => false, 'sku' => 'A'];

        $this->assertTrue(InventoryImportPhase::acceptsRow($row, InventoryImportPhase::STOCK));
        $this->assertFalse(InventoryImportPhase::acceptsRow(['is_primary_catalog' => true], InventoryImportPhase::STOCK));
    }
}
