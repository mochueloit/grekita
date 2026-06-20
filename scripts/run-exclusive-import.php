<?php

use App\Jobs\PostInventorySyncJob;
use App\Models\InventoryImport;
use App\Services\Inventory\ExclusiveStoreCsvImporter;
use App\Services\Inventory\InventoryImportMode;
use App\Services\Inventory\InventoryImportProgress;
use App\Services\Inventory\InventorySkippedRowExporter;
use App\Services\Inventory\InventorySkippedRowLogger;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$source = $argv[1] ?? base_path('ACTUALIZACION-MERCASIST-PAGINA-WEB-19-06-2026.xlsx');

if (! is_file($source)) {
    fwrite(STDERR, "No se encontró el archivo: {$source}\n");
    exit(1);
}

$extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
$storedPath = 'imports/mercasist_'.date('Y-m-d_His').'.'.$extension;

Storage::disk('local')->makeDirectory('imports');
Storage::disk('local')->put($storedPath, file_get_contents($source));

$import = InventoryImport::query()->create([
    'original_filename' => basename($source),
    'stored_path' => $storedPath,
    'disk' => 'local',
    'import_mode' => InventoryImportMode::EXCLUSIVE_STORE,
    'status' => InventoryImport::STATUS_PENDING,
]);

echo "Importación #{$import->id} — Exclusivos sede\n";
echo "Archivo guardado: storage/app/{$storedPath}\n\n";

$importer = app(ExclusiveStoreCsvImporter::class);
$progress = new InventoryImportProgress($import->id);

$import->update([
    'status' => InventoryImport::STATUS_PROCESSING,
    'started_at' => now(),
]);

$progress->log('Inicio manual desde script run-exclusive-import.php');
$importer->prepareQueuedImport($import->fresh());

$skip = 0;

while (true) {
    $import->refresh();
    $result = $importer->processQueuedChunk($import, $skip);

    if (! $result['has_more']) {
        break;
    }

    $skip = $result['next_skip'];
}

$import->refresh();

$skippedLogger = new InventorySkippedRowLogger($import->id);
$skippedLogger->persistCsv(app(InventorySkippedRowExporter::class));

$import->update([
    'status' => InventoryImport::STATUS_COMPLETED,
    'stats' => $import->partial_stats,
    'processed_rows' => $import->total_rows ?? $import->processed_rows,
    'current_step' => 'Completado — exclusivos sede',
    'completed_at' => now(),
]);

$stats = $import->partial_stats ?? [];
$progress->log(sprintf(
    'Completado — %d creados, %d actualizados, %d stock, %d imágenes en cola.',
    $stats['created'] ?? 0,
    $stats['updated'] ?? 0,
    $stats['stock_applied'] ?? 0,
    $stats['images_queued'] ?? 0,
));

echo "=== Inventario procesado ===\n";
echo 'Creados: '.($stats['created'] ?? 0)."\n";
echo 'Actualizados: '.($stats['updated'] ?? 0)."\n";
echo 'Stock aplicado: '.($stats['stock_applied'] ?? 0)."\n";
echo 'Imágenes en cola: '.($stats['images_queued'] ?? 0)."\n";
echo 'SKUs para XML: '.count($import->fresh()->syncedSkusForExport())."\n\n";

echo "=== Pipeline WordPress (síncrono) ===\n";

$syncJob = app(PostInventorySyncJob::class, ['importId' => $import->id]);
$attempts = 0;
$maxAttempts = 40;

while ($attempts < $maxAttempts) {
    $attempts++;
    $import->refresh();

    if (($import->checkpoint['wp_pipeline']['finished'] ?? false) === true) {
        echo "WordPress pipeline finalizado.\n";
        break;
    }

    $syncJob->handle(
        app(\App\Services\Export\ProductXmlExportService::class),
        app(\App\Services\WordPress\WpAllImportClient::class),
    );

    $import->refresh();
    $phase = $import->checkpoint['wp_pipeline']['phase'] ?? '?';
    echo "  Intento {$attempts} — fase: {$phase}\n";

    if (($import->checkpoint['wp_pipeline']['finished'] ?? false) === true) {
        break;
    }

    if ($phase === 'processing_external') {
        echo "  Import en WordPress (cron externo). Grekita no puede cerrarlo desde aquí.\n";
        break;
    }

    sleep(2);
}

$import->refresh();
$pipeline = $import->checkpoint['wp_pipeline'] ?? [];

if (isset($pipeline['xml_product_count'])) {
    echo 'XML productos: '.$pipeline['xml_product_count']."\n";
}

if (isset($pipeline['xml_latest_path'])) {
    echo 'XML ruta: '.$pipeline['xml_latest_path']."\n";
}

echo "\nVer progreso en: /inventory/import/exclusive?import={$import->id}\n";
