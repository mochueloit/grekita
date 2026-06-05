<?php

namespace App\Jobs;

use App\Services\Export\ProductXmlExportService;
use App\Services\Inventory\InventoryImportProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExportProductsXmlJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(
        public readonly string $trigger = 'queued',
        public readonly ?int $importId = null,
    ) {}

    public function handle(ProductXmlExportService $exportService): void
    {
        $result = $exportService->generate($this->trigger);

        if ($this->importId === null) {
            return;
        }

        $progress = new InventoryImportProgress($this->importId);
        $progress->log(sprintf(
            'XML WP All Import generado (%d productos) → %s',
            $result['product_count'],
            $result['relative_path'],
        ));
        $progress->log('Copia fija para FTP: '.$result['latest_relative_path']);
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->importId === null) {
            return;
        }

        $progress = new InventoryImportProgress($this->importId);
        $progress->log('Error al generar XML WP All Import: '.($exception?->getMessage() ?? 'desconocido'));
    }
}
