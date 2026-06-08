<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Services\Inventory\InventoryImportPhase;
use App\Services\Inventory\InventoryImportProgress;
use App\Services\Inventory\InventorySkippedRowExporter;
use App\Services\Inventory\InventorySkippedRowLogger;
use App\Services\Inventory\StockPriceCsvImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

class ProcessStockPriceImportChunkJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public readonly int $importId,
        public readonly int $skipRows,
    ) {}

    public function handle(StockPriceCsvImporter $importer): void
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
                self::dispatch($this->importId, 0)->afterCommit();

                return;
            }

            if ($result['has_more']) {
                self::dispatch($this->importId, $result['next_skip'])->afterCommit();

                return;
            }

            $import->refresh();

            $import->update([
                'status' => InventoryImport::STATUS_COMPLETED,
                'stats' => $import->partial_stats,
                'processed_rows' => $import->total_rows ?? $import->processed_rows,
                'current_step' => 'Completado — generando XML',
                'completed_at' => now(),
            ]);

            $progress = new InventoryImportProgress($import->id);
            $stats = $import->partial_stats ?? [];
            $skippedLogger = new InventorySkippedRowLogger($import->id);
            $skipped = $skippedLogger->count();
            $skippedLogger->persistCsv(app(InventorySkippedRowExporter::class));

            $progress->log(sprintf(
                'Actualización rápida terminada — %d productos · %d precios · %d stock fase 2 · %d omitidas.',
                $stats['updated'] ?? 0,
                $stats['prices_updated'] ?? 0,
                $stats['stock_applied'] ?? 0,
                $stats['skipped'] ?? $skipped,
            ));

            if ($skipped > 0) {
                $progress->log("Hay {$skipped} filas omitidas (SKU sin producto en catálogo).");
            }

            $progress->log('Encolando XML completo + WordPress (sin esperar imágenes).');
            PostInventorySyncJob::dispatch($import->id)->afterCommit();
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
