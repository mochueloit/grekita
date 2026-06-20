<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 5);
$import = App\Models\InventoryImport::query()->findOrFail($id);

$export = app(App\Services\Export\ProductXmlExportService::class);
$wp = app(App\Services\WordPress\WpAllImportClient::class);
$job = app(App\Jobs\PostInventorySyncJob::class, ['importId' => $id]);

echo "Continuando WordPress import #{$id}…\n";

for ($i = 1; $i <= 60; $i++) {
    $import->refresh();
    $pipe = $import->checkpoint['wp_pipeline'] ?? [];

    if (($pipe['finished'] ?? false) === true) {
        echo "Pipeline finalizado.\n";
        break;
    }

    $job->handle($export, $wp);
    $import->refresh();
    $pipe = $import->checkpoint['wp_pipeline'] ?? [];
    $phase = $pipe['phase'] ?? '?';

    echo "  [{$i}] fase={$phase}";

    if (isset($pipe['xml_product_count'])) {
        echo " xml={$pipe['xml_product_count']}";
    }

    echo "\n";

    if (($pipe['finished'] ?? false) === true || $phase === 'processing_external') {
        break;
    }

    sleep(3);
}

$import->refresh();
$pipe = $import->checkpoint['wp_pipeline'] ?? [];

if (isset($pipe['xml_latest_path'])) {
    echo "\nXML: {$pipe['xml_latest_path']}\n";
}

if (isset($pipe['xml_product_count'])) {
    echo "Productos en XML: {$pipe['xml_product_count']}\n";
}

if (($pipe['triggered'] ?? false) === true) {
    echo "WP All Import trigger enviado.\n";
}
