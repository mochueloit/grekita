<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Services\Export\ProductXmlExportService;
use App\Services\Inventory\InventoryImageDownloadLogger;
use App\Services\Inventory\InventoryImportProgress;
use App\Services\WordPress\WpAllImportClient;
use App\Services\WordPress\WpAllImportSyncLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PostInventorySyncJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly int $importId,
    ) {}

    public function handle(
        ProductXmlExportService $exportService,
        WpAllImportClient $wpClient,
    ): void {
        $import = InventoryImport::query()->findOrFail($this->importId);
        $progress = new InventoryImportProgress($this->importId);
        $wpLog = new WpAllImportSyncLogger($this->importId);
        $checkpoint = $import->checkpoint ?? [];
        $pipeline = $checkpoint['wp_pipeline'] ?? [];

        if (($pipeline['finished'] ?? false) === true) {
            return;
        }

        if (! isset($pipeline['started_at'])) {
            $pipeline['started_at'] = now()->toIso8601String();
            $pipeline['phase'] = 'waiting';
            $checkpoint['wp_pipeline'] = $pipeline;
            $import->update(['checkpoint' => $checkpoint]);
            $wpLog->log('Iniciando pipeline post-importación (imágenes → XML → WordPress).');
        }

        if ($import->queuedJobsCount() > 0) {
            $this->reschedule($progress, $wpLog, 'Esperando lotes de inventario en cola…');

            return;
        }

        $imageStats = (new InventoryImageDownloadLogger($this->importId))->stats();

        if (! $imageStats['finished']) {
            $startedAt = isset($pipeline['started_at']) ? strtotime((string) $pipeline['started_at']) : time();
            $elapsed = max(0, time() - $startedAt);
            $maxWait = (int) config('wp_all_import.images_max_wait_seconds', 0);

            if ($maxWait > 0 && $elapsed >= $maxWait) {
                $wpLog->log('Timeout esperando imágenes; continuando con XML y WordPress.', 'WARN');
                $progress->log('AVISO: tiempo máximo de espera de imágenes alcanzado; continuando con WordPress.');
            } else {
                $pending = $imageStats['pending'] + $imageStats['downloading'];
                $pipeline['phase'] = 'waiting_images';
                $checkpoint['wp_pipeline'] = $pipeline;
                $import->update([
                    'checkpoint' => $checkpoint,
                    'current_step' => "Esperando imágenes ({$pending} pendientes)",
                ]);
                $progress->log("Esperando descarga de imágenes ({$pending} pendientes) antes de XML y WordPress…");
                $wpLog->log("Esperando imágenes — {$pending} pendiente(s).");
                $this->reschedule($progress, $wpLog);

                return;
            }
        }

        if (empty($pipeline['xml_generated'])) {
            $pipeline['phase'] = 'exporting_xml';
            $checkpoint['wp_pipeline'] = $pipeline;
            $import->update([
                'checkpoint' => $checkpoint,
                'current_step' => 'Generando XML para WordPress',
            ]);

            $progress->log('Generando XML de productos para WP All Import…');
            $wpLog->log('Generando XML de productos…');

            $result = $exportService->generate('inventory_import:'.$import->id);

            $pipeline['xml_generated'] = true;
            $pipeline['xml_generated_at'] = now()->toIso8601String();
            $pipeline['xml_product_count'] = $result['product_count'];
            $checkpoint['wp_pipeline'] = $pipeline;
            $import->update(['checkpoint' => $checkpoint]);

            $progress->log(sprintf(
                'XML generado (%d productos) → %s',
                $result['product_count'],
                $result['latest_relative_path'],
            ));
            $wpLog->log('XML listo: '.$result['latest_relative_path']);
        }

        if (! $wpClient->isEnabled()) {
            $pipeline['phase'] = 'completed';
            $pipeline['finished'] = true;
            $pipeline['finished_at'] = now()->toIso8601String();
            $checkpoint['wp_pipeline'] = $pipeline;
            $import->update([
                'checkpoint' => $checkpoint,
                'current_step' => 'Completado (WP All Import deshabilitado)',
            ]);
            $progress->log('WP All Import deshabilitado en configuración; solo se generó el XML.');
            $wpLog->log('WP All Import deshabilitado — pipeline finalizado tras XML.');

            return;
        }

        if (empty($pipeline['triggered'])) {
            $pipeline['phase'] = 'triggering';
            $checkpoint['wp_pipeline'] = $pipeline;
            $import->update([
                'checkpoint' => $checkpoint,
                'current_step' => 'Activando importación en WordPress',
            ]);

            $url = $wpClient->buildUrl('trigger');
            $progress->log('Ejecutando WP All Import trigger (una sola vez)…');
            $wpLog->log('GET '.$url);

            $response = $wpClient->call('trigger');
            $wpLog->recordResponse($response);

            $import->refresh();
            $checkpoint = $import->checkpoint ?? [];
            $pipeline = $checkpoint['wp_pipeline'] ?? [];
            $pipeline['triggered'] = true;
            $pipeline['triggered_at'] = now()->toIso8601String();
            $pipeline['phase'] = 'processing';
            $checkpoint['wp_pipeline'] = $pipeline;
            $import->update(['checkpoint' => $checkpoint]);

            $progress->log('WP trigger — '.$response['message']);

            $initialDelay = (int) config('wp_all_import.processing_initial_delay_seconds', 30);
            WpAllImportProcessingJob::dispatch($this->importId, 1)
                ->delay(now()->addSeconds($initialDelay));

            $progress->log("Procesamiento WP encolado (primer intento en {$initialDelay} s, luego cada ".config('wp_all_import.processing_interval_seconds', 180).' s mientras API status=200).');
            $wpLog->log("Encolado processing — primer intento en {$initialDelay} s.");

            return;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $progress = new InventoryImportProgress($this->importId);
        $progress->log('Error en pipeline WordPress: '.($exception?->getMessage() ?? 'desconocido'));

        $wpLog = new WpAllImportSyncLogger($this->importId);
        $wpLog->log('ERROR pipeline: '.($exception?->getMessage() ?? 'desconocido'), 'ERROR');
    }

    private function reschedule(
        InventoryImportProgress $progress,
        WpAllImportSyncLogger $wpLog,
        ?string $reason = null,
    ): void {
        $delay = (int) config('wp_all_import.images_poll_interval_seconds', 45);

        if ($reason !== null) {
            $progress->log($reason." Reintento en {$delay} s.");
            $wpLog->log($reason." Reintento en {$delay} s.");
        }

        self::dispatch($this->importId)->delay(now()->addSeconds($delay));
    }
}
