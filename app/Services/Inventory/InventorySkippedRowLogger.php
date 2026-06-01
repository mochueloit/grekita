<?php

namespace App\Services\Inventory;

use App\Models\InventoryImport;
use Illuminate\Support\Facades\Storage;

class InventorySkippedRowLogger
{
    public function __construct(
        private readonly int $importId,
    ) {}

    /**
     * @param  array{row: int, sku: ?string, title: ?string, cuenta_ml: ?string, reason_code: string, reason: string}  $entry
     */
    public function record(array $entry): void
    {
        $line = json_encode([
            'row' => $entry['row'],
            'sku' => $entry['sku'],
            'title' => $entry['title'],
            'cuenta_ml' => $entry['cuenta_ml'],
            'reason_code' => $entry['reason_code'],
            'reason' => $entry['reason'],
            'recorded_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE);

        Storage::disk('local')->append($this->relativePath(), $line.PHP_EOL);

        $import = InventoryImport::query()->find($this->importId);

        if ($import !== null && $import->skipped_rows_path === null) {
            $import->update(['skipped_rows_path' => $this->relativePath()]);
        }
    }

    public function relativePath(): string
    {
        return "imports/skipped/import_{$this->importId}.jsonl";
    }

    /**
     * @return list<array{row: int, sku: ?string, title: ?string, cuenta_ml: ?string, reason_code: string, reason: string, recorded_at: ?string}>
     */
    public function all(): array
    {
        $path = $this->relativePath();

        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        $rows = [];
        $content = Storage::disk('local')->get($path);
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    public function count(): int
    {
        return count($this->all());
    }

    public function exists(): bool
    {
        return Storage::disk('local')->exists($this->relativePath());
    }

    public function csvRelativePath(): string
    {
        return "imports/skipped/import_{$this->importId}.csv";
    }

    public function persistCsv(InventorySkippedRowExporter $exporter): ?string
    {
        $rows = $this->all();

        if ($rows === []) {
            return null;
        }

        $path = $this->csvRelativePath();
        $exporter->writeToDisk($rows, $path);

        $import = InventoryImport::query()->find($this->importId);

        if ($import !== null) {
            $import->update(['skipped_rows_csv_path' => $path]);
        }

        return $path;
    }
}
