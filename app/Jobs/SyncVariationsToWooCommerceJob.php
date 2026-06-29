<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Models\ProductVariation;
use App\Services\Inventory\InventoryImportProgress;
use App\Services\WooCommerce\WooCommerceApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncVariationsToWooCommerceJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;
    public int $tries   = 1;

    public function __construct(
        public readonly int  $logImportId,
        public readonly ?int $importId = null,
    ) {}

    public function handle(WooCommerceApiClient $api): void
    {
        $import   = InventoryImport::findOrFail($this->logImportId);
        $progress = new InventoryImportProgress($this->logImportId);

        if (!$api->isConfigured()) {
            $progress->log('[ERROR] WooCommerce no configurado. Verifica WC_CONSUMER_KEY y WC_CONSUMER_SECRET en .env');
            $import->update([
                'status'        => InventoryImport::STATUS_FAILED,
                'error_message' => 'WooCommerce no configurado.',
                'completed_at'  => now(),
            ]);
            return;
        }

        $skusPadres = ProductVariation::where('wc_status', 'pending')
            ->when($this->importId, fn ($q) => $q->where('inventory_import_id', $this->importId))
            ->distinct()
            ->orderBy('sku_padre')
            ->pluck('sku_padre');

        if ($skusPadres->isEmpty()) {
            $progress->log('No hay variaciones pendientes de sincronizar.');
            $import->update([
                'status'       => InventoryImport::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
            return;
        }

        $total = $skusPadres->count();
        $import->update([
            'status'     => InventoryImport::STATUS_PROCESSING,
            'total_rows' => $total,
            'started_at' => $import->started_at ?? now(),
        ]);

        $progress->log("Encolando sync para {$total} productos — cada uno se procesa y reintenta de forma independiente.");

        foreach ($skusPadres as $skuPadre) {
            SyncProductVariationsJob::dispatch($skuPadre, $this->logImportId, $this->importId);
        }

        $progress->log("Jobs despachados. El log se irá actualizando conforme cada producto se procese.");
    }
}
