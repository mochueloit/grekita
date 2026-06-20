<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 5);
$import = App\Models\InventoryImport::query()->findOrFail($id);

$checkpoint = $import->checkpoint ?? [];
$checkpoint['skip_image_wait'] = true;
$import->update(['checkpoint' => $checkpoint]);

$export = app(App\Services\Export\ProductXmlExportService::class);
$wp = app(App\Services\WordPress\WpAllImportClient::class);
$job = app(App\Jobs\PostInventorySyncJob::class, ['importId' => $id]);

echo "Generando XML sin esperar imágenes restantes…\n";

for ($i = 1; $i <= 10; $i++) {
    $import->refresh();

    if (($import->checkpoint['wp_pipeline']['finished'] ?? false) === true) {
        break;
    }

    $job->handle($export, $wp);
    $import->refresh();
    $pipe = $import->checkpoint['wp_pipeline'] ?? [];
    echo "  [{$i}] ".($pipe['phase'] ?? '?');

    if (isset($pipe['xml_product_count'])) {
        echo " — XML {$pipe['xml_product_count']} SKU";
    }

    echo "\n";

    if (($pipe['triggered'] ?? false) === true || ($pipe['finished'] ?? false) === true) {
        break;
    }
}

$import->refresh();
$pipe = $import->checkpoint['wp_pipeline'] ?? [];

echo "\n";
echo 'XML: '.($pipe['xml_latest_path'] ?? 'pendiente')."\n";
echo 'Productos XML: '.($pipe['xml_product_count'] ?? '?')."\n";
echo 'WP trigger: '.((($pipe['triggered'] ?? false) === true) ? 'sí' : 'no')."\n";
echo 'Fase: '.($pipe['phase'] ?? '?')."\n";

if ($wp->isEnabled($import)) {
    echo "WP All Import habilitado — revisa log en /inventory/import/exclusive?import={$id}\n";
} else {
    echo "WP All Import deshabilitado en .env local — solo se generó el XML.\n";
}
