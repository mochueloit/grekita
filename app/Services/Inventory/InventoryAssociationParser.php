<?php

namespace App\Services\Inventory;

class InventoryAssociationParser
{
    public function parseStock(string $associations, int $fallbackQuantity = 0): int
    {
        if ($associations === '') {
            return max(0, $fallbackQuantity);
        }

        preg_match_all('/Cantidad:\s*(\d+)/i', $associations, $matches);

        if ($matches[1] === []) {
            return max(0, $fallbackQuantity);
        }

        return array_sum(array_map('intval', $matches[1]));
    }
}
