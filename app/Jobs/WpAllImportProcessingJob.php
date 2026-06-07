<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Services\Inventory\InventoryImportNotifier;
use App\Services\Inventory\InventoryImportProgress;
use App\Services\WordPress\WpAllImportClient;
use App\Services\WordPress\WpAllImportSyncLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class WpAllImportProcessingJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(
        public readonly int $importId,
        public readonly int $attempt = 1,
    ) {}

    public function handle(WpAllImportClient $wpClient): void
    {
        if (! $wpClient->isEnabled()) {
            return;
        }

        $import = InventoryImport::query()->findOrFail($this->importId);
        $checkpoint = $import->checkpoint ?? [];
        $pipeline = $checkpoint['wp_pipeline'] ?? [];

        if (($pipeline['finished'] ?? false) === true) {
            return;
        }

        if (empty($pipeline['triggered'])) {
            return;
        }

        $maxAttempts = (int) config('wp_all_import.max_processing_attempts', 120);

        if ($this->attempt > $maxAttempts) {
            $this->finishPipeline($import, $pipeline, $checkpoint, sprintf(
                'Tope de %d llamadas a processing alcanzado.',
                $maxAttempts,
            ), 'WARN');

            return;
        }

        $progress = new InventoryImportProgress($this->importId);
        $wpLog = new WpAllImportSyncLogger($this->importId);

        $url = $wpClient->buildUrl('processing');
        $wpLog->log(sprintf('GET %s (intento #%d)', $url, $this->attempt));

        $import->update([
            'current_step' => 'WordPress processing #'.$this->attempt,
        ]);

        $response = $wpClient->call('processing');
        $wpLog->recordResponse($response, $this->attempt);

        $import->refresh();
        $checkpoint = $import->checkpoint ?? [];
        $pipeline = $checkpoint['wp_pipeline'] ?? [];
        $pipeline['processing_attempts'] = $this->attempt;
        $pipeline['phase'] = 'processing';
        $checkpoint['wp_pipeline'] = $pipeline;
        $import->update(['checkpoint' => $checkpoint]);

        $progress->log(sprintf(
            'WP processing #%d — %s',
            $this->attempt,
            $response['message'],
        ));

        if ($response['should_continue']) {
            $interval = (int) config('wp_all_import.processing_interval_seconds', 180);
            $progress->log("WordPress sigue procesando. Próximo intento en {$interval} s.");
            $wpLog->log("Continúa (API 200). Próximo processing en {$interval} s.");

            self::dispatch($this->importId, $this->attempt + 1)
                ->delay(now()->addSeconds($interval));

            return;
        }

        $this->finishPipeline(
            $import,
            $pipeline,
            $checkpoint,
            sprintf(
                'Finalizado — API status %s: %s',
                (string) ($response['api_status'] ?? 'n/a'),
                $response['message'],
            ),
            'INFO',
        );
    }

    public function failed(?Throwable $exception): void
    {
        $progress = new InventoryImportProgress($this->importId);
        $progress->log('Error en WP processing: '.($exception?->getMessage() ?? 'desconocido'));

        $wpLog = new WpAllImportSyncLogger($this->importId);
        $wpLog->log('ERROR processing: '.($exception?->getMessage() ?? 'desconocido'), 'ERROR');
    }

    /**
     * @param  array<string, mixed>  $pipeline
     * @param  array<string, mixed>  $checkpoint
     */
    private function finishPipeline(
        InventoryImport $import,
        array $pipeline,
        array $checkpoint,
        string $message,
        string $level,
    ): void {
        $progress = new InventoryImportProgress($this->importId);
        $wpLog = new WpAllImportSyncLogger($this->importId);

        $pipeline['phase'] = 'completed';
        $pipeline['finished'] = true;
        $pipeline['finished_at'] = now()->toIso8601String();
        $checkpoint['wp_pipeline'] = $pipeline;

        $import->update([
            'checkpoint' => $checkpoint,
            'current_step' => 'Completado (WordPress sincronizado)',
        ]);

        $progress->log('Sincronización WordPress finalizada. '.$message);
        $wpLog->log('FIN — '.$message, $level);

        $notifier = app(InventoryImportNotifier::class);
        $import = $import->fresh() ?? $import;
        $notifier->notifyCompleted($import, $notifier->buildCompletionSummary($import));
    }
}
