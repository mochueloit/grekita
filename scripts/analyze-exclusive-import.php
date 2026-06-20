<?php

use App\Models\InventoryImport;
use App\Models\Product;
use App\Services\Inventory\ExclusiveStoreCsvImporter;
use App\Services\Inventory\InventoryImportMode;
use App\Services\Inventory\LocationResolver;
use App\Services\Inventory\ProductStockService;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$fileArg = $argv[1] ?? null;
if ($fileArg === null || ! is_file($fileArg)) {
    fwrite(STDERR, "Usage: php scripts/analyze-exclusive-import.php <path-to-xlsx>\n");
    exit(1);
}

$runImport = in_array('--import', $argv, true);

Storage::fake('local');
$storedPath = 'imports/test-mercasist.xlsx';
Storage::disk('local')->put($storedPath, file_get_contents($fileArg));

foreach (['puerto-ordaz', 'lecheria', 'caracas'] as $slug) {
    app(LocationResolver::class)->resolveKnownStoreBySlug($slug);
}

$import = InventoryImport::query()->create([
    'disk' => 'local',
    'stored_path' => $storedPath,
    'original_filename' => basename($fileArg),
    'import_mode' => InventoryImportMode::EXCLUSIVE_STORE,
    'status' => InventoryImport::STATUS_PENDING,
]);

$importer = app(ExclusiveStoreCsvImporter::class);
$importer->prepareQueuedImport($import->fresh());
$import->refresh();

$checkpoint = $import->checkpoint ?? [];
$skusWithPo = array_keys($checkpoint['skus_with_po_row'] ?? []);

echo "=== Análisis: ".basename($fileArg)." ===\n";
echo 'Filas totales válidas: '.($import->total_rows ?? 0)."\n";
echo 'Filas Puerto Ordaz: '.($checkpoint['primary_rows'] ?? '?')."\n";
echo 'Filas Lechería/Caracas: '.($checkpoint['secondary_rows'] ?? '?')."\n";
echo 'SKU distintos con fila PO en archivo: '.count($skusWithPo)."\n\n";

if (! $runImport) {
    echo "Modo análisis solamente. Usa --import para simular importación en SQLite de prueba.\n";
    exit(0);
}

$beforeCount = Product::query()->count();
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
$stats = $import->partial_stats ?? [];

echo "=== Resultado importación (BD de prueba) ===\n";
echo 'Productos antes: '.$beforeCount."\n";
echo 'Productos después: '.Product::query()->count()."\n";
echo 'Creados: '.($stats['created'] ?? 0)."\n";
echo 'Actualizados: '.($stats['updated'] ?? 0)."\n";
echo 'Stock aplicado (filas sede): '.($stats['stock_applied'] ?? 0)."\n";
echo 'Omitidas: '.($stats['skipped'] ?? 0)."\n";
echo 'Imágenes en cola: '.($stats['images_queued'] ?? 0)."\n";
echo 'SKUs sincronizados (XML): '.count($import->syncedSkusForExport())."\n\n";

$synced = $import->syncedSkusForExport();
$sample = array_slice($synced, 0, 8);

echo "Muestra SKUs tocados: ".implode(', ', $sample).(count($synced) > 8 ? '…' : '')."\n\n";

$stockService = app(ProductStockService::class);
$exclusiveSamples = [];

foreach ($synced as $sku) {
    $product = Product::query()->where('sku', $sku)->first();
    if ($product === null) {
        continue;
    }

    $stocks = collect($stockService->stocksForProduct($product))->keyBy('slug');
    $po = $stocks->get('puerto-ordaz')['stock'] ?? 0;
    $hasPoInFile = in_array($sku, $skusWithPo, true);

    if (! $hasPoInFile && (int) $po === 0) {
        $exclusiveSamples[] = sprintf(
            '%s — L:%d C:%d PO:0',
            $sku,
            $stocks->get('lecheria')['stock'] ?? 0,
            $stocks->get('caracas')['stock'] ?? 0,
        );

        if (count($exclusiveSamples) >= 5) {
            break;
        }
    }
}

if ($exclusiveSamples !== []) {
    echo "Ejemplos exclusivos (sin PO en archivo, PO=0):\n";
    foreach ($exclusiveSamples as $line) {
        echo "  · {$line}\n";
    }
}
