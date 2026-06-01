<?php

namespace App\Services\Inventory;

use App\Models\InventoryImport;
use Illuminate\Support\Facades\File;

class InventoryImportProgress
{
    private const MAX_LOG_ENTRIES = 120;

    public function __construct(
        private readonly int $importId,
    ) {}

    public function log(string $message): void
    {
        $import = InventoryImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $entries = $import->log_entries ?? [];
        $entries[] = [
            'at' => now()->format('H:i:s'),
            'message' => $message,
        ];

        if (count($entries) > self::MAX_LOG_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_LOG_ENTRIES);
        }

        $import->update([
            'log_entries' => $entries,
            'last_activity_at' => now(),
        ]);

        $logDir = storage_path('logs/imports');

        if (! File::isDirectory($logDir)) {
            File::makeDirectory($logDir, 0755, true);
        }

        File::append(
            $logDir.DIRECTORY_SEPARATOR."import_{$this->importId}.log",
            '['.now()->format('Y-m-d H:i:s').'] '.$message.PHP_EOL,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function sync(array $data): void
    {
        InventoryImport::query()
            ->whereKey($this->importId)
            ->update(array_merge($data, [
                'last_activity_at' => now(),
            ]));
    }
}
