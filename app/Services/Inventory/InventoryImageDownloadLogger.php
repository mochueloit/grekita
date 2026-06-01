<?php

namespace App\Services\Inventory;

use App\Models\InventoryImport;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

class InventoryImageDownloadLogger
{
    public function __construct(
        private readonly int $importId,
    ) {}

    public function relativePath(): string
    {
        return "imports/logs/import_{$this->importId}_images.log";
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

        if ($import !== null && $import->image_download_log_path === null) {
            $import->update(['image_download_log_path' => $this->relativePath()]);
        }
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
     * @return array{pending: int, downloading: int, completed: int, failed: int, total: int, finished: bool}
     */
    public function stats(): array
    {
        $counts = ProductImage::query()
            ->where('inventory_import_id', $this->importId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $pending = (int) ($counts[ProductImage::STATUS_PENDING] ?? 0);
        $downloading = (int) ($counts[ProductImage::STATUS_DOWNLOADING] ?? 0);
        $completed = (int) ($counts[ProductImage::STATUS_COMPLETED] ?? 0);
        $failed = (int) ($counts[ProductImage::STATUS_FAILED] ?? 0);
        $total = $pending + $downloading + $completed + $failed;

        return [
            'pending' => $pending,
            'downloading' => $downloading,
            'completed' => $completed,
            'failed' => $failed,
            'total' => $total,
            'finished' => $total > 0 && ($pending + $downloading) === 0,
        ];
    }

    public function maybeLogCompletionSummary(): void
    {
        $stats = $this->stats();

        if ($stats['total'] === 0 || ! $stats['finished']) {
            return;
        }

        $marker = 'RESUMEN FINAL imágenes import #'.$this->importId;
        $existing = implode("\n", $this->lines());

        if (str_contains($existing, $marker)) {
            return;
        }

        $this->log(sprintf(
            '%s: %d completadas, %d fallidas, %d total.',
            $marker,
            $stats['completed'],
            $stats['failed'],
            $stats['total'],
        ), $stats['failed'] > 0 ? 'WARN' : 'INFO');
    }
}
