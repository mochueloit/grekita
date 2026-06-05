<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Services\Inventory\InventoryCsvImporter;
use App\Services\Inventory\InventoryImageDownloadLogger;
use App\Services\Inventory\InventoryImportPhase;
use App\Services\Inventory\InventoryImportProgress;
use App\Services\Inventory\InventorySkippedRowExporter;
use App\Services\Inventory\InventorySkippedRowLogger;
use App\Services\Inventory\ProductStockService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

class ProcessInventoryImportChunkJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public readonly int $importId,
        public readonly int $skipRows,
    ) {}

    public function handle(InventoryCsvImporter $importer): void
    {
        $import = InventoryImport::query()->findOrFail($this->importId);

        if ($import->isFinished()) {
            return;
        }

        if ($import->status === InventoryImport::STATUS_PENDING) {
            $import->update([
                'status' => InventoryImport::STATUS_PROCESSING,
                'started_at' => $import->started_at ?? now(),
            ]);
        }

        try {
            $result = $importer->processQueuedChunk($import, $this->skipRows);

            if ($result['phase_complete'] === InventoryImportPhase::CATALOG) {
                $importer->beginStockPhase($import->fresh() ?? $import);
                ProcessInventoryImportChunkJob::dispatch($this->importId, 0)->afterCommit();

                return;
            }

            if ($result['has_more']) {
                ProcessInventoryImportChunkJob::dispatch($this->importId, $result['next_skip'])->afterCommit();

                return;
            }

            $import->refresh();

            $backfilled = app(ProductStockService::class)->backfillAllProducts();

            $import->update([
                'status' => InventoryImport::STATUS_COMPLETED,
                'stats' => $import->partial_stats,
                'processed_rows' => $import->total_rows ?? $import->processed_rows,
                'current_step' => 'Completado (2 fases)',
                'completed_at' => now(),
            ]);

            $progress = new InventoryImportProgress($import->id);
            $stats = $import->partial_stats ?? [];
            $skippedLogger = new InventorySkippedRowLogger($import->id);
            $skipped = $skippedLogger->count();
            $skippedLogger->persistCsv(app(InventorySkippedRowExporter::class));

            $imageLogger = new InventoryImageDownloadLogger($import->id);
            $imageLogger->log('Importación de productos finalizada (catálogo + stock).');
            if (($stats['images_queued'] ?? 0) > 0) {
                $imageLogger->log(sprintf(
                    'Descarga de imágenes en segundo plano: %d encolada(s) para esta importación.',
                    $stats['images_queued'],
                ));
            }

            $progress->log("Stock principal y sedes (0 donde falte) sincronizados en {$backfilled} producto(s).");

            $progress->log(sprintf(
                'Importación terminada — Fase 1: %d creados, %d actualizados · Fase 2: %d stock aplicado, %d sin producto · %d omitidas · %d imágenes en cola.',
                $stats['created'] ?? 0,
                $stats['updated'] ?? 0,
                $stats['stock_applied'] ?? 0,
                $stats['stock_skipped'] ?? 0,
                $stats['skipped'] ?? $skipped,
                $stats['images_queued'] ?? 0,
            ));

            if ($skipped > 0) {
                $progress->log("Hay {$skipped} filas omitidas. CSV guardado — descárgalo desde el panel.");
            }

            if (($stats['images_queued'] ?? 0) > 0) {
                $progress->log('Las imágenes se descargan en segundo plano (cola lenta, ~3 s entre cada una).');
            }
        } catch (InvalidArgumentException $exception) {
            $this->markFailed($import, $exception->getMessage());
        } catch (Throwable $exception) {
            $this->markFailed($import, $exception->getMessage());

            throw $exception;
        }
    }

    private function markFailed(InventoryImport $import, string $message): void
    {
        $import->update([
            'status' => InventoryImport::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
