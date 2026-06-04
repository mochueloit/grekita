<?php

namespace App\Services\Inventory;

class InventoryImportPhase
{
    public const CATALOG = 'catalog';

    public const STOCK = 'stock';

    public static function label(string $phase): string
    {
        return match ($phase) {
            self::CATALOG => 'Fase 1 — Catálogo Puerto Ordaz',
            self::STOCK => 'Fase 2 — Stock otras sedes',
            default => $phase,
        };
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    public static function acceptsRow(array $parsed, string $phase): bool
    {
        $isPrimary = (bool) ($parsed['is_primary_catalog'] ?? false);

        return $phase === self::CATALOG ? $isPrimary : ! $isPrimary;
    }
}
