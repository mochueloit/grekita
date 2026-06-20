<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Services\Inventory\ExclusiveStoreCsvImporter;
use App\Services\Inventory\InventoryImportNotifier;
use App\Services\Inventory\InventoryImportProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;
use Throwable;

class ProcessExclusiveStoreImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public readonly int $importId,
    ) {}

    public function handle(ExclusiveStoreCsvImporter $importer): void
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
        $progress->log('Preparando importación de productos exclusivos por sede…');

        try {
            $importer->prepareQueuedImport($import);
            $progress->log('Archivo validado. Encolando procesamiento (Lechería/Caracas, catálogo completo)…');

            app(InventoryImportNotifier::class)->notifyStarted($import->fresh() ?? $import);

            ProcessExclusiveStoreImportChunkJob::dispatch($this->importId, 0)->afterCommit();
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
