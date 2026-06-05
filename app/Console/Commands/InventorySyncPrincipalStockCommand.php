<?php

namespace App\Console\Commands;

use App\Services\Inventory\ProductStockService;
use Illuminate\Console\Command;

class InventorySyncPrincipalStockCommand extends Command
{
    protected $signature = 'inventory:sync-principal-stock';

    protected $description = 'Registra las 3 sedes en cada producto (stock 0 si falta) y recalcula stock principal';

    public function handle(ProductStockService $stockService): int
    {
        $this->info('Sincronizando sedes y stock principal…');

        $count = $stockService->backfillAllProducts();

        $this->info("Listo: {$count} producto(s) actualizados.");

        return self::SUCCESS;
    }
}
