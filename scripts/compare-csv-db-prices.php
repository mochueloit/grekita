<?php

use App\Models\Product;
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

$file = $argv[1] ?? 'ACTUALIZACION-MERCASIST-PAGINA-WEB-19-06-2026.xlsx';
$reader = app(InventoryFileReader::class);
$rows = iterator_to_array($reader->rows($file));
$header = $rows[0];
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
for ($i = 1, $c = count($rows); $i < $c; $i++) {
    $p = $parser->parseWithCatalog($rows[$i]);
    if ($p === null) {
        continue;
    }
    $sku = $p['sku'];
    $bySku[$sku] ??= ['rows' => [], 'po' => null, 'first' => null];
    $bySku[$sku]['rows'][] = $p;
    if ($p['is_primary_catalog']) {
        $bySku[$sku]['po'] = $p;
    }
    if ($bySku[$sku]['first'] === null) {
        $bySku[$sku]['first'] = $p;
    }
}

$mismatches = [];
$noPoExisting = 0;
foreach ($bySku as $sku => $info) {
    $catalogRow = $info['po'] ?? $info['first'];
    $csvPrice = $catalogRow['price'] ?? null;
    $csvForeign = $catalogRow['price_foreign'] ?? null;
    $product = Product::query()->where('sku', $sku)->first();
    if ($product === null) {
        continue;
    }

    if ($info['po'] === null) {
        $noPoExisting++;
    }

    $dbPrice = $product->price !== null ? (float) $product->price : null;
    $dbForeign = $product->price_foreign !== null ? (float) $product->price_foreign : null;
    $priceMatch = ($csvPrice === null && $dbPrice === null)
        || ($csvPrice !== null && $dbPrice !== null && abs($csvPrice - $dbPrice) < 0.01);
    $foreignMatch = ($csvForeign === null && $dbForeign === null)
        || ($csvForeign !== null && $dbForeign !== null && abs($csvForeign - $dbForeign) < 0.01);

    if (! $priceMatch || ! $foreignMatch) {
        $mismatches[] = [
            'sku' => $sku,
            'csvPrice' => $csvPrice,
            'dbPrice' => $dbPrice,
            'csvForeign' => $csvForeign,
            'dbForeign' => $dbForeign,
            'hasPo' => $info['po'] !== null,
        ];
    }
}

echo "Archivo: {$file}\n";
echo 'SKUs en archivo: '.count($bySku)."\n";
echo 'Existentes en BD con fila sin PO: '.$noPoExisting."\n";
echo 'Desajustes precio: '.count($mismatches)."\n\n";

foreach (array_slice($mismatches, 0, 20) as $m) {
    echo sprintf(
        "SKU %s | CSV %s / %s | DB %s / %s | PO=%s\n",
        $m['sku'],
        $m['csvPrice'] ?? 'null',
        $m['csvForeign'] ?? 'null',
        $m['dbPrice'] ?? 'null',
        $m['dbForeign'] ?? 'null',
        $m['hasPo'] ? 'yes' : 'no',
    );
}
