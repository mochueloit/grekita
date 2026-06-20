<?php

use App\Services\Inventory\InventoryFileReader;
use App\Services\Inventory\InventoryHeaderResolver;
use App\Services\Inventory\InventoryProductRowParser;
use App\Services\Inventory\InventoryAssociationParser;
use App\Services\Inventory\ProductAttributeParser;
use App\Services\Inventory\ProductDescriptionCleaner;
use App\Services\Inventory\ProductDescriptionFormatter;
use App\Services\Inventory\BrandExtractor;
use App\Services\Inventory\ProductImageParser;
use App\Services\Inventory\ProductPriceParser;
use App\Services\Inventory\ProductCategoryParser;
use App\Services\Inventory\LocationResolver;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$file = $argv[1] ?? '';
if (! is_file($file)) {
    fwrite(STDERR, "File not found: {$file}\n");
    exit(1);
}

$reader = app(InventoryFileReader::class);
$rows = iterator_to_array($reader->rows($file));
$header = $rows[0] ?? [];
$map = [];
foreach ($header as $i => $col) {
    if ($col !== null && trim((string) $col) !== '') {
        $map[trim((string) $col)] = (int) $i;
    }
}

$resolver = InventoryHeaderResolver::fromHeaderMap($map);
$parser = new InventoryProductRowParser(
    $resolver,
    app(LocationResolver::class),
    new InventoryAssociationParser,
    new ProductAttributeParser,
    new ProductDescriptionCleaner,
    new ProductDescriptionFormatter,
    new BrandExtractor,
    new ProductImageParser,
    new ProductPriceParser,
    new ProductCategoryParser,
);

$bySku = [];
$invalid = 0;

for ($i = 1, $c = count($rows); $i < $c; $i++) {
    $parsed = $parser->parseWithCatalog($rows[$i]);
    if ($parsed === null) {
        $invalid++;

        continue;
    }

    $sku = $parsed['sku'];
    $bySku[$sku] ??= ['po' => 0, 'secondary' => 0, 'title' => $parsed['name'] ?? $sku];
    if ($parsed['is_primary_catalog']) {
        $bySku[$sku]['po']++;
    } else {
        $bySku[$sku]['secondary']++;
    }
}

$withPo = 0;
$exclusiveOnly = 0;
$mixed = 0;

foreach ($bySku as $sku => $info) {
    if ($info['po'] > 0 && $info['secondary'] > 0) {
        $mixed++;
    } elseif ($info['po'] > 0) {
        $withPo++;
    } else {
        $exclusiveOnly++;
    }
}

echo "=== Detalle SKU: ".basename($file)." ===\n";
echo 'SKU distintos en archivo: '.count($bySku)."\n";
echo "  · Solo Puerto Ordaz: {$withPo}\n";
echo "  · Solo Lechería/Caracas (exclusivos): {$exclusiveOnly}\n";
echo "  · Mixtos (PO + otra sede): {$mixed}\n";
echo "Filas no parseables: {$invalid}\n\n";

echo "Ejemplos exclusivos (sin fila PO):\n";
$n = 0;
foreach ($bySku as $sku => $info) {
    if ($info['po'] === 0) {
        echo sprintf("  · %s — %s (%d fila(s) secundaria(s))\n", $sku, $info['title'], $info['secondary']);
        if (++$n >= 8) {
            break;
        }
    }
}

echo "\nEjemplos mixtos:\n";
$n = 0;
foreach ($bySku as $sku => $info) {
    if ($info['po'] > 0 && $info['secondary'] > 0) {
        echo sprintf("  · %s — PO:%d + sec:%d\n", $sku, $info['po'], $info['secondary']);
        if (++$n >= 5) {
            break;
        }
    }
}

if (class_exists(\App\Models\Product::class)) {
    $allSkus = array_keys($bySku);
    $existing = \App\Models\Product::query()->whereIn('sku', $allSkus)->pluck('sku')->all();
    $newSkus = array_diff($allSkus, $existing);

    echo "\n=== Vs base Grekita ===\n";
    echo 'SKU en archivo: '.count($allSkus)."\n";
    echo 'Ya existen: '.count($existing)."\n";
    echo 'Serían nuevos: '.count($newSkus)."\n";

    $newExclusive = 0;
    foreach ($newSkus as $sku) {
        if (($bySku[$sku]['po'] ?? 0) === 0) {
            $newExclusive++;
        }
    }

    echo "Nuevos exclusivos (sin PO en archivo): {$newExclusive}\n";

    if ($newSkus !== []) {
        echo "\nSKU nuevos en este archivo:\n";
        foreach ($newSkus as $sku) {
            $info = $bySku[$sku];
            $tipo = $info['po'] > 0 ? 'PO' : 'exclusivo';
            echo sprintf("  · %s (%s) — %s\n", $sku, $tipo, $info['title']);
        }
    }
}
