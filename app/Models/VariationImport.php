<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class VariationImport extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_VALIDATING = 'validating';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';

    protected $fillable = [
        'original_filename',
        'stored_path',
        'disk',
        'status',
        'total_rows',
        'processed_rows',
        'successful_products',
        'failed_products',
        'skipped_products',
        'log_entries',
        'error_report_path',
        'success_report_path',
        'checkpoint',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'log_entries' => 'array',
            'checkpoint'  => 'array',
            'started_at'  => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_VALIDATING, self::STATUS_PROCESSING], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING    => 'En cola',
            self::STATUS_VALIDATING => 'Validando',
            self::STATUS_PROCESSING => 'Procesando',
            self::STATUS_COMPLETED  => 'Completado',
            self::STATUS_FAILED     => 'Fallido',
            default                 => $this->status,
        };
    }

    public function progressPercent(): ?int
    {
        if (!$this->total_rows || $this->total_rows <= 0) {
            return null;
        }

        return min(100, (int) round(($this->processed_rows / $this->total_rows) * 100));
    }

    public function addLog(string $message, string $level = 'info'): void
    {
        $entries   = $this->log_entries ?? [];
        $entries[] = [
            'time'    => now()->toDateTimeString(),
            'level'   => $level,
            'message' => $message,
        ];

        $this->update(['log_entries' => $entries]);
    }

    public function queuedJobsCount(): int
    {
        return (int) DB::table('jobs')
            ->where(function ($query): void {
                $query->where('payload', 'like', '%"variationImportId";i:'.$this->id.';%')
                    ->orWhere('payload', 'like', '%"variationImportId":'.$this->id.',%');
            })
            ->count();
    }
}
