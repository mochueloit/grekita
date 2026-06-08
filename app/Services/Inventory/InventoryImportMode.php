<?php

namespace App\Services\Inventory;

class InventoryImportMode
{
    public const FULL = 'full';

    public const STOCK_PRICE_XML = 'stock_price_xml';

    public static function label(string $mode): string
    {
        return match ($mode) {
            self::STOCK_PRICE_XML => 'Rápida — stock, precio y XML',
            default => 'Completa — catálogo e imágenes',
        };
    }
}
