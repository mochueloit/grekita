<?php

namespace App\Services\WordPress;

use App\Models\InventoryImport;
use App\Services\Inventory\InventoryImportNotifier;
use App\Services\Inventory\InventoryImportProgress;
use Illuminate\Support\Collection;

class WpAllImportExternalSyncService
{
    public function __construct(
        private readonly WpAllImportClient $wpClient,
    ) {}

    /**
     * @return Collection<int, InventoryImport>
     */
    public function activeImports(): Collection
    {
        return InventoryImport::query()
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->filter(fn (InventoryImport $import): bool => $this->hasActiveWpPipeline($import))
            ->values();
    }

    public function hasActiveWpPipeline(InventoryImport $import): bool
    {
        if (! $this->wpClient->isEnabled($import)) {
            return false;
        }

        $pipeline = ($import->checkpoint ?? [])['wp_pipeline'] ?? [];

        return ! empty($pipeline['triggered'])
            && empty($pipeline['finished']);
    }

    public function poll(InventoryImport $import): void
    {
        if (! $this->hasActiveWpPipeline($import)) {
            return;
        }

        $import = $import->fresh() ?? $import;
        $checkpoint = $import->checkpoint ?? [];
        $pipeline = $checkpoint['wp_pipeline'] ?? [];
        $progress = new InventoryImportProgress($import->id);
        $wpLog = new WpAllImportSyncLogger($import->id);

        if ($this->hasExceededMaxWait($pipeline)) {
            $this->finishPipeline(
                $import,
                $pipeline,
                $checkpoint,
                sprintf(
                    'Tiempo máximo de espera (%d h) alcanzado. Revisa WP All Import manualmente.',
                    (int) config('wp_all_import.max_external_wait_hours', 4),
                ),
                'WARN',
            );

            return;
        }

        $pollNumber = (int) ($pipeline['poll_count'] ?? 0) + 1;
        $url = $this->wpClient->buildUrl('processing', $import);

        $wpLog->log(sprintf('Poll #%d — verificación estado (GET processing): %s', $pollNumber, $url));
        $import->update([
            'current_step' => 'WordPress: verificación #'.$pollNumber.' (cron servidor activo)',
        ]);

        $timeout = (int) config('wp_all_import.status_poll_http_timeout_seconds', 60);
        $response = $this->wpClient->call('processing', $import, $timeout);
        $wpLog->recordResponse($response, $pollNumber);

        $import->refresh();
        $checkpoint = $import->checkpoint ?? [];
        $pipeline = $checkpoint['wp_pipeline'] ?? [];
        $pipeline['poll_count'] = $pollNumber;
        $pipeline['last_poll_at'] = now()->toIso8601String();
        $pipeline['phase'] = 'processing_external';
        $checkpoint['wp_pipeline'] = $pipeline;
        $import->update(['checkpoint' => $checkpoint]);

        if ($this->wpClient->isStillProcessing($response)) {
            $progress->log(sprintf(
                'WP sigue en curso (poll #%d, API 200). Próxima verificación en %d min.',
                $pollNumber,
                (int) config('wp_all_import.status_poll_interval_minutes', 30),
            ));
            $wpLog->log(sprintf('Poll #%d — importación aún en curso (API 200).', $pollNumber));

            return;
        }

        if ($this->wpClient->shouldRetryAfterFailure($response)) {
            $progress->log(sprintf(
                'Poll #%d: error de red/timeout (%s). No se marca como terminado; siguiente poll en %d min.',
                $pollNumber,
                $response['message'],
                (int) config('wp_all_import.status_poll_interval_minutes', 30),
            ));
            $wpLog->log(
                sprintf('Poll #%d — error transitorio (no es fin): %s', $pollNumber, $response['message']),
                'WARN',
            );

            return;
        }

        $this->finishPipeline(
            $import,
            $pipeline,
            $checkpoint,
            sprintf(
                'Importación WP finalizada (poll #%d) — API status %s: %s',
                $pollNumber,
                (string) ($response['api_status'] ?? 'n/a'),
                $response['message'],
            ),
            'INFO',
        );
    }

    /**
     * @param  array<string, mixed>  $pipeline
     */
    private function hasExceededMaxWait(array $pipeline): bool
    {
        $maxHours = (int) config('wp_all_import.max_external_wait_hours', 4);

        if ($maxHours <= 0) {
            return false;
        }

        $triggeredAt = $pipeline['triggered_at'] ?? null;

        if ($triggeredAt === null) {
            return false;
        }

        $elapsed = now()->diffInSeconds(\Illuminate\Support\Carbon::parse($triggeredAt));

        return $elapsed >= ($maxHours * 3600);
    }

    /**
     * @param  array<string, mixed>  $pipeline
     * @param  array<string, mixed>  $checkpoint
     */
    public function finishPipeline(
        InventoryImport $import,
        array $pipeline,
        array $checkpoint,
        string $message,
        string $level,
    ): void {
        $progress = new InventoryImportProgress($import->id);
        $wpLog = new WpAllImportSyncLogger($import->id);

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
