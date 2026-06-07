<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InventoryImport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'original_filename',
        'stored_path',
        'header_map',
        'disk',
        'status',
        'total_rows',
        'processed_rows',
        'current_step',
        'stats',
        'partial_stats',
        'checkpoint',
        'skipped_rows_path',
        'skipped_rows_csv_path',
        'image_download_log_path',
        'wp_sync_log_path',
        'log_entries',
        'error_message',
        'started_at',
        'completed_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'header_map' => 'array',
            'stats' => 'array',
            'partial_stats' => 'array',
            'checkpoint' => 'array',
            'log_entries' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'En cola',
            self::STATUS_PROCESSING => 'Procesando',
            self::STATUS_COMPLETED => 'Completado',
            self::STATUS_FAILED => 'Fallido',
            default => $this->status,
        };
    }

    public function progressPercent(): ?int
    {
        if ($this->total_rows === null || $this->total_rows <= 0) {
            return null;
        }

        return min(100, (int) round(($this->processed_rows / $this->total_rows) * 100));
    }

    public function liveStats(): ?array
    {
        return $this->partial_stats ?? $this->stats;
    }

    public function queuedJobsCount(): int
    {
        return (int) DB::table('jobs')
            ->where(function ($query): void {
                $query->where('payload', 'like', '%"importId";i:'.$this->id.';%')
                    ->orWhere('payload', 'like', '%"importId":'.$this->id.',%')
                    ->orWhere('payload', 'like', '%"importId": '.$this->id.',%');
            })
            ->count();
    }

    public function isStale(int $seconds = 180): bool
    {
        if ($this->isFinished()) {
            return false;
        }

        if ($this->status === self::STATUS_PENDING) {
            return $this->queuedJobsCount() > 0
                && $this->created_at->lt(now()->subMinutes(3));
        }

        if ($this->last_activity_at === null) {
            return $this->started_at?->lt(now()->subSeconds($seconds)) ?? false;
        }

        return $this->last_activity_at->lt(now()->subSeconds($seconds));
    }

    public function skippedRowsCount(): int
    {
        return (int) (($this->stats ?? $this->partial_stats)['skipped'] ?? 0);
    }

    public function importPhase(): ?string
    {
        $phase = ($this->checkpoint ?? [])['phase'] ?? null;

        return is_string($phase) ? $phase : null;
    }

    public function importPhaseLabel(): ?string
    {
        $phase = $this->importPhase();

        return $phase !== null
            ? \App\Services\Inventory\InventoryImportPhase::label($phase)
            : null;
    }

    public function workerHint(): ?string
    {
        if ($this->isFinished()) {
            return null;
        }

        if ($this->status === self::STATUS_PENDING
            && $this->queuedJobsCount() > 0
            && $this->created_at->lt(now()->subSeconds(45))) {
            return 'Hay lotes en cola. El worker debería iniciarse automáticamente al subir el archivo.';
        }

        if ($this->isStale()) {
            return 'Sin actividad reciente. Ejecuta: php artisan queue:listen --queue=default,images --tries=3 --timeout=120';
        }

        return null;
    }
}
