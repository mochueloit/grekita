<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Services\Inventory\InventoryCsvImporter;
use App\Services\Inventory\InventoryImageDownloadLogger;
use App\Services\Inventory\InventoryImportProgress;
use App\Services\Inventory\InventorySkippedRowExporter;
use App\Services\Inventory\InventorySkippedRowLogger;
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

            if ($result['has_more']) {
                ProcessInventoryImportChunkJob::dispatch($this->importId, $result['next_skip'])->afterCommit();

                return;
            }

            $import->refresh();

            $import->update([
                'status' => InventoryImport::STATUS_COMPLETED,
                'stats' => $import->partial_stats,
                'processed_rows' => $import->total_rows ?? $import->processed_rows,
                'current_step' => 'Completado',
                'completed_at' => now(),
            ]);

            $progress = new InventoryImportProgress($import->id);
            $stats = $import->partial_stats ?? [];
            $skippedLogger = new InventorySkippedRowLogger($import->id);
            $skipped = $skippedLogger->count();
            $skippedLogger->persistCsv(app(InventorySkippedRowExporter::class));

            $imageLogger = new InventoryImageDownloadLogger($import->id);
            $imageLogger->log('Importación de productos finalizada.');
            if (($stats['images_queued'] ?? 0) > 0) {
                $imageLogger->log(sprintf(
                    'Descarga de imágenes en segundo plano: %d encolada(s) para esta importación.',
                    $stats['images_queued'],
                ));
            }

            $progress->log(sprintf(
                'Importación terminada: %d filas válidas, %d omitidas, %d imágenes en cola de descarga.',
                $stats['processed'] ?? 0,
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
