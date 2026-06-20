<?php

namespace App\Services\Inventory;

class InventoryImportMode
{
    public const FULL = 'full';

    public const STOCK_PRICE_XML = 'stock_price_xml';

    public const EXCLUSIVE_STORE = 'exclusive_store';

    public static function label(string $mode): string
    {
        return match ($mode) {
            self::STOCK_PRICE_XML => 'Actualizar precios y stock',
            self::EXCLUSIVE_STORE => 'Productos exclusivos por sede',
            default => 'Importar productos nuevos',
        };
    }
}
