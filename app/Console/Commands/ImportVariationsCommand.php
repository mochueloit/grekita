<?php

namespace App\Console\Commands;

use App\Jobs\ProcessVariationImportJob;
use App\Models\InventoryImport;
use App\Services\Inventory\InventoryImportMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImportVariationsCommand extends Command
{
    protected $signature = 'variations:import
        {path : Ruta al Excel de variaciones (.xlsx)}';

    protected $description = 'Importa variaciones desde Excel y las guarda en BD local. El sync a WooCommerce se lanza automáticamente.';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (!is_file($path)) {
            $this->error("Archivo no encontrado: {$path}");
            return self::FAILURE;
        }

        $filename   = 'variation-import-' . now()->format('Ymd-His') . '-' . basename($path);
        $storedPath = 'variation-imports/' . $filename;

        Storage::disk('local')->put($storedPath, file_get_contents($path));
        $this->info("Archivo guardado: {$storedPath}");

        $import = InventoryImport::create([
            'original_filename' => basename($path),
            'stored_path'       => $storedPath,
            'disk'              => 'local',
            'import_mode'       => InventoryImportMode::VARIATIONS,
            'status'            => InventoryImport::STATUS_PENDING,
        ]);

        $this->info("Importación creada con ID: {$import->id}");

        ProcessVariationImportJob::dispatch($import->id);

        $this->info('Job encolado. Asegúrate de tener el worker corriendo:');
        $this->line('  php artisan queue:listen --timeout=600');

        return self::SUCCESS;
    }
}
