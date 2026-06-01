<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Services\Inventory\InventoryCsvImporter;
use App\Services\Inventory\InventoryImportProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

class ProcessInventoryImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public readonly int $importId,
    ) {}

    public function handle(InventoryCsvImporter $importer): void
    {
        $import = InventoryImport::query()->findOrFail($this->importId);

        if ($import->isFinished()) {
            return;
        }

        $import->update([
            'status' => InventoryImport::STATUS_PROCESSING,
            'started_at' => now(),
            'error_message' => null,
        ]);

        $progress = new InventoryImportProgress($import->id);
        $progress->log('Preparando importación…');

        try {
            $importer->prepareQueuedImport($import);
            $progress->log('Archivo validado. Encolando lotes de procesamiento…');
            ProcessInventoryImportChunkJob::dispatch($this->importId, 0)->afterCommit();
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
