<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Services\Inventory\ExclusiveStoreCsvImporter;
use App\Services\Inventory\InventoryImageDownloadLogger;
use App\Services\Inventory\InventoryImportProgress;
use App\Services\Inventory\InventorySkippedRowExporter;
use App\Services\Inventory\InventorySkippedRowLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

class ProcessExclusiveStoreImportChunkJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public readonly int $importId,
        public readonly int $skipRows,
    ) {}

    public function handle(ExclusiveStoreCsvImporter $importer): void
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

            if ($result['has_more']) {
                self::dispatch($this->importId, $result['next_skip'])->afterCommit();

                return;
            }

            $import->refresh();

            $import->update([
                'status' => InventoryImport::STATUS_COMPLETED,
                'stats' => $import->partial_stats,
                'processed_rows' => $import->total_rows ?? $import->processed_rows,
                'current_step' => 'Completado — productos exclusivos',
                'completed_at' => now(),
            ]);

            $progress = new InventoryImportProgress($import->id);
            $stats = $import->partial_stats ?? [];
            $skippedLogger = new InventorySkippedRowLogger($import->id);
            $skipped = $skippedLogger->count();
            $skippedLogger->persistCsv(app(InventorySkippedRowExporter::class));

            $imageLogger = new InventoryImageDownloadLogger($import->id);
            $imageLogger->log('Importación exclusiva por sede finalizada.');
            if (($stats['images_queued'] ?? 0) > 0) {
                $imageLogger->log(sprintf(
                    'Descarga de imágenes en segundo plano: %d encolada(s).',
                    $stats['images_queued'],
                ));
            }

            $progress->log(sprintf(
                'Exclusivos terminados — %d creados, %d actualizados, %d stock aplicado, %d omitidas, %d imágenes en cola.',
                $stats['created'] ?? 0,
                $stats['updated'] ?? 0,
                $stats['stock_applied'] ?? 0,
                $stats['skipped'] ?? $skipped,
                $stats['images_queued'] ?? 0,
            ));

            if ($skipped > 0) {
                $progress->log("Hay {$skipped} filas omitidas (p. ej. Puerto Ordaz). Descarga el CSV desde el panel.");
            }

            PostInventorySyncJob::dispatch($import->id)->afterCommit();
            $progress->log('Pipeline WordPress encolado: XML parcial products.xml + WP All Import #19.');
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
