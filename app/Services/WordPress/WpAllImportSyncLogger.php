<?php

namespace App\Services\WordPress;

use App\Models\InventoryImport;
use Illuminate\Support\Facades\Storage;

class WpAllImportSyncLogger
{
    private const MAX_HISTORY_ENTRIES = 150;

    public function __construct(
        private readonly int $importId,
    ) {}

    public function relativePath(): string
    {
        return "imports/logs/import_{$this->importId}_wp_sync.log";
    }

    public function log(string $message, string $level = 'INFO'): void
    {
        $line = sprintf(
            '[%s] [%s] %s',
            now()->format('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
        );

        Storage::disk('local')->append($this->relativePath(), $line.PHP_EOL);

        $import = InventoryImport::query()->find($this->importId);

        if ($import !== null && $import->wp_sync_log_path === null) {
            try {
                $import->update(['wp_sync_log_path' => $this->relativePath()]);
            } catch (\Throwable) {
                // Columna wp_sync_log_path puede faltar si no se ha migrado aún.
            }
        }
    }

    /**
     * Guarda en log + historial BD (checkpoint) cada respuesta JSON de WP All Import.
     *
     * @param  array{url: string, action: string, http_status: int, api_status: int|null, message: string, body: string, should_continue: bool}  $response
     */
    public function recordResponse(array $response, ?int $attempt = null): void
    {
        $this->logResponse($response, $attempt);
        $this->appendHistory($response, $attempt);
    }

    /**
     * @param  array{url: string, action: string, http_status: int, api_status: int|null, message: string, body: string, should_continue: bool}  $response
     */
    public function logResponse(array $response, ?int $attempt = null): void
    {
        $apiStatus = $response['api_status'] ?? 'n/a';
        $attemptLabel = $attempt !== null ? " · intento #{$attempt}" : '';
        $level = ($response['api_status'] ?? 0) === 200 ? 'INFO' : 'WARN';

        $this->log(sprintf(
            'WP %s%s — API status %s — %s',
            $response['action'],
            $attemptLabel,
            (string) $apiStatus,
            $response['message'],
        ), $level);
    }

    /**
     * @param  array{url: string, action: string, http_status: int, api_status: int|null, message: string, body: string, should_continue: bool}  $response
     */
    private function appendHistory(array $response, ?int $attempt): void
    {
        $import = InventoryImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $checkpoint = $import->checkpoint ?? [];
        $pipeline = $checkpoint['wp_pipeline'] ?? [];
        $history = $pipeline['history'] ?? [];

        $history[] = [
            'at' => now()->format('H:i:s'),
            'at_iso' => now()->toIso8601String(),
            'action' => $response['action'],
            'attempt' => $attempt,
            'http_status' => $response['http_status'],
            'api_status' => $response['api_status'],
            'message' => $response['message'],
            'continues' => $response['should_continue'],
        ];

        if (count($history) > self::MAX_HISTORY_ENTRIES) {
            $history = array_slice($history, -self::MAX_HISTORY_ENTRIES);
        }

        $pipeline['history'] = $history;
        $pipeline['last_api_status'] = $response['api_status'];
        $pipeline['last_message'] = $response['message'];
        $pipeline['last_http_status'] = $response['http_status'];
        $checkpoint['wp_pipeline'] = $pipeline;

        $import->update([
            'checkpoint' => $checkpoint,
            'last_activity_at' => now(),
        ]);
    }

    public function exists(): bool
    {
        return Storage::disk('local')->exists($this->relativePath());
    }

    /**
     * @return list<string>
     */
    public function lines(?int $tail = null): array
    {
        if (! $this->exists()) {
            return [];
        }

        $content = Storage::disk('local')->get($this->relativePath());
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line): bool => $line !== ''));

        if ($tail !== null && $tail > 0 && count($lines) > $tail) {
            return array_slice($lines, -$tail);
        }

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(): array
    {
        $import = InventoryImport::query()->find($this->importId);
        $pipeline = ($import?->checkpoint ?? [])['wp_pipeline'] ?? [];

        return $pipeline['history'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        $import = InventoryImport::query()->find($this->importId);
        $pipeline = ($import?->checkpoint ?? [])['wp_pipeline'] ?? [];
        $history = $pipeline['history'] ?? [];

        return [
            'enabled' => $import !== null
                ? (new WpAllImportClient)->isEnabled($import)
                : (new WpAllImportClient)->isEnabled(),
            'wp_import_id' => $pipeline['wp_import_id'] ?? null,
            'phase' => $pipeline['phase'] ?? 'idle',
            'finished' => (bool) ($pipeline['finished'] ?? false),
            'last_api_status' => $pipeline['last_api_status'] ?? null,
            'last_http_status' => $pipeline['last_http_status'] ?? null,
            'last_message' => $pipeline['last_message'] ?? null,
            'processing_attempts' => (int) ($pipeline['processing_attempts'] ?? 0),
            'history_count' => count($history),
            'triggered_at' => $pipeline['triggered_at'] ?? null,
            'finished_at' => $pipeline['finished_at'] ?? null,
        ];
    }
}
