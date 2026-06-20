<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 5);
$import = App\Models\InventoryImport::query()->find($id);

if ($import === null) {
    echo "Import #{$id} no encontrada\n";
    exit(1);
}

$stats = $import->stats ?? $import->partial_stats ?? [];
$img = (new App\Services\Inventory\InventoryImageDownloadLogger($id))->stats();
$pipe = $import->checkpoint['wp_pipeline'] ?? [];

echo "Import #{$id} — {$import->status}\n";
echo 'Creados: '.($stats['created'] ?? 0)."\n";
echo 'Actualizados: '.($stats['updated'] ?? 0)."\n";
echo 'Stock: '.($stats['stock_applied'] ?? 0)."\n";
echo 'Imágenes: pend='.$img['pending'].' ok='.$img['completed'].' fail='.$img['failed']."\n";
echo 'Pipeline WP: '.($pipe['phase'] ?? 'sin iniciar')."\n";

if (isset($pipe['xml_product_count'])) {
    echo 'XML: '.$pipe['xml_product_count'].' productos → '.($pipe['xml_latest_path'] ?? '')."\n";
}

echo "\nSKU nuevos:\n";
$stock = app(App\Services\Inventory\ProductStockService::class);

foreach (['5578', '5579', '5580', '6137', '6128'] as $sku) {
    $p = App\Models\Product::query()->where('sku', $sku)->first();

    if ($p === null) {
        echo "  {$sku}: NO EXISTE\n";

        continue;
    }

    $st = collect($stock->stocksForProduct($p))->keyBy('slug');
    echo sprintf(
        "  %s — PO:%d L:%d C:%d | %s\n",
        $sku,
        $st->get('puerto-ordaz')['stock'] ?? 0,
        $st->get('lecheria')['stock'] ?? 0,
        $st->get('caracas')['stock'] ?? 0,
        $p->name,
    );
}
