<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\Inventory\InventoryImportProgress;
use App\Services\WooCommerce\WooCommerceApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncProductVariationsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;
    public int $tries   = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly string $skuPadre,
        public readonly int    $logImportId,
        public readonly ?int   $filterImportId = null,
    ) {}

    public function handle(WooCommerceApiClient $api): void
    {
        $progress = new InventoryImportProgress($this->logImportId);

        $varsGrupo = ProductVariation::where('sku_padre', $this->skuPadre)
            ->where('wc_status', 'pending')
            ->when($this->filterImportId, fn ($q) => $q->where('inventory_import_id', $this->filterImportId))
            ->get();

        if ($varsGrupo->isEmpty()) {
            $this->checkAndCloseImport($progress);
            return;
        }

        $progress->log(">>> SKU {$this->skuPadre} (" . $varsGrupo->count() . " variaciones) intento #{$this->attempts()}");

        // Buscar producto en WC
        $wcPadre = $api->findProductBySku($this->skuPadre);
        if ($wcPadre === null) {
            $progress->log("    [ERROR] {$this->skuPadre}: no encontrado en WooCommerce.");
            $varsGrupo->each(fn ($v) => $v->update([
                'wc_status' => 'failed',
                'wc_error'  => 'Producto padre no encontrado en WooCommerce.',
            ]));
            $this->checkAndCloseImport($progress);
            return;
        }

        $productoId = (int) $wcPadre['id'];
        $tipo       = $wcPadre['type'] ?? 'simple';

        // Si el producto es simple en WC, tiene precio directo — lo leemos y guardamos en BD local
        // Si ya es variable (convertido en sync anterior), los precios vienen de BD local
        $precioRegular = '';
        $precioDivisa  = '';
        $divisaMoneda  = '';

        if ($tipo === 'simple') {
            $precioRegular = (string) ($wcPadre['regular_price'] ?? $wcPadre['price'] ?? '');
            foreach ($wcPadre['meta_data'] ?? [] as $meta) {
                if ($meta['key'] === '_precio_divisa') $precioDivisa = (string) $meta['value'];
                if ($meta['key'] === '_moneda_divisa')  $divisaMoneda  = (string) $meta['value'];
            }
            // Persistir en BD local para syncs futuros cuando ya sea variable
            if ($precioRegular !== '' || $precioDivisa !== '') {
                Product::where('sku', $this->skuPadre)->update([
                    'price'          => $precioRegular ?: null,
                    'price_foreign'  => $precioDivisa  ?: null,
                    'price_currency' => $divisaMoneda  ?: null,
                ]);
                $progress->log("    Precio simple WC guardado en BD local: regular={$precioRegular} divisa={$precioDivisa} {$divisaMoneda}");
            }
        } else {
            // Ya es variable — leer precio desde BD local (guardado en sync anterior cuando era simple)
            $localProduct  = Product::where('sku', $this->skuPadre)->first();
            $precioRegular = (string) ($localProduct?->price ?? '');
            $precioDivisa  = (string) ($localProduct?->price_foreign ?? '');
            $divisaMoneda  = (string) ($localProduct?->price_currency ?? '');
            // Además leer _precio_divisa desde meta del padre en WC (suele persistir tras conversión)
            foreach ($wcPadre['meta_data'] ?? [] as $meta) {
                if ($meta['key'] === '_precio_divisa' && $precioDivisa === '') $precioDivisa = (string) $meta['value'];
                if ($meta['key'] === '_moneda_divisa'  && $divisaMoneda === '')  $divisaMoneda  = (string) $meta['value'];
            }
        }

        $progress->log(
            '    Precio (' . $tipo . ') — regular: ' . ($precioRegular ?: '(vacío)') .
            ' | _precio_divisa: ' . ($precioDivisa ?: '(vacío)') .
            ' | _moneda_divisa: ' . ($divisaMoneda ?: '(vacío)')
        );

        // Borrador
        $draft = $api->setDraft($productoId);
        if (!$draft['success']) {
            // Forzar reintento lanzando excepción
            throw new \RuntimeException("No se pudo pasar a borrador: " . $draft['error']);
        }

        // Atributos del padre
        $atributosMap = $this->buildAttributesMap($varsGrupo);
        $atributosWc  = $this->buildWcAttributes($atributosMap, $wcPadre);

        if ($tipo === 'simple') {
            $convert = $api->convertToVariable($productoId, $atributosWc);
            if (!$convert['success']) {
                throw new \RuntimeException("Error al convertir a variable: " . $convert['error']);
            }
            $progress->log("    {$this->skuPadre}: convertido a variable OK");
        } else {
            $api->put("products/{$productoId}", ['attributes' => $atributosWc]);
        }

        // Variaciones existentes en WC
        $existentes = [];
        foreach ($api->getVariations($productoId) as $v) {
            if (($v['sku'] ?? '') !== '') {
                $existentes[$v['sku']] = (int) $v['id'];
            }
        }

        $imagenPadre = !empty($wcPadre['images'])
            ? ['id' => $wcPadre['images'][0]['id'], 'src' => $wcPadre['images'][0]['src']]
            : null;

        $okCount   = 0;
        $failCount = 0;

        foreach ($varsGrupo as $variation) {
            $skuVar  = $variation->sku;
            $attrStr = implode(', ', array_map(fn ($a) => $a['nombre'] . ':' . $a['valor'], $variation->atributos ?? []));

            $metaData = $this->buildStockMeta($variation);
            if ($precioDivisa !== '') {
                $metaData[] = ['key' => '_precio_divisa', 'value' => $precioDivisa];
                $metaData[] = ['key' => '_moneda_divisa', 'value' => $divisaMoneda];
            }

            $payload = [
                'sku'            => $skuVar,
                'status'         => 'publish',
                'manage_stock'   => true,
                'stock_quantity' => $variation->stock_total,
                'attributes'     => array_map(fn ($a) => ['name' => $a['nombre'], 'option' => $a['valor']], $variation->atributos ?? []),
                'meta_data'      => $metaData,
            ];
            if ($precioRegular !== '') $payload['regular_price'] = $precioRegular;
            if ($imagenPadre)          $payload['image']         = $imagenPadre;

            $progress->log("      {$skuVar}: price={$precioRegular} divisa={$precioDivisa} stock_metas=" . count($this->buildStockMeta($variation)));

            if (isset($existentes[$skuVar])) {
                $result      = $api->updateVariation($productoId, $existentes[$skuVar], $payload);
                $accion      = 'ACTUALIZADA';
                $variacionId = $existentes[$skuVar];
            } else {
                $result      = $api->createVariation($productoId, $payload);
                $variacionId = (int) ($result['data']['id'] ?? 0);
                $accion      = 'CREADA';
            }

            if ($result['success']) {
                $variation->update([
                    'wc_status'       => 'synced',
                    'wc_variation_id' => $variacionId,
                    'wc_error'        => null,
                    'wc_synced_at'    => now(),
                ]);
                $progress->log("    [{$accion}] {$skuVar} | {$attrStr} | stock:{$variation->stock_total} OK");
                $okCount++;
            } else {
                $variation->update(['wc_status' => 'failed', 'wc_error' => $result['error']]);
                $progress->log("    [ERROR] {$skuVar}: " . $result['error']);
                $failCount++;
            }
        }

        // Stock por sede en el padre
        $sedes     = config('woocommerce.sedes', []);
        $metaPadre = [];
        foreach ($sedes as $sedeId => $nombreSede) {
            $col       = 'stock_' . $sedeId;
            $metaPadre[] = ['key' => '_stock_sede_' . $sedeId, 'value' => (string) $varsGrupo->sum($col)];
        }
        $api->put("products/{$productoId}", [
            'manage_stock'   => true,
            'stock_quantity' => $varsGrupo->sum('stock_total'),
            'meta_data'      => $metaPadre,
        ]);

        // Publicar si todo OK
        if ($failCount === 0 && $okCount > 0) {
            $publish = $api->setPublish($productoId);
            $progress->log("    {$this->skuPadre}: " . ($publish['success'] ? 'Publicado OK' : 'WARN: ' . $publish['error']));
        } else {
            $progress->log("    {$this->skuPadre}: queda en borrador ({$failCount} errores).");
        }

        $progress->log("    Resumen {$this->skuPadre}: {$okCount} OK | {$failCount} fallidas");

        // Incremento atómico — varios jobs corren en paralelo, sin race condition
        \Illuminate\Support\Facades\DB::table('inventory_imports')
            ->where('id', $this->logImportId)
            ->increment('processed_rows');

        $this->checkAndCloseImport($progress);
    }

    private function checkAndCloseImport(InventoryImportProgress $progress): void
    {
        $import = InventoryImport::find($this->logImportId);
        if (!$import || $import->isFinished()) {
            return;
        }

        $pendientes = ProductVariation::where('wc_status', 'pending')
            ->when($this->filterImportId, fn ($q) => $q->where('inventory_import_id', $this->filterImportId))
            ->count();

        if ($pendientes === 0) {
            $synced = ProductVariation::where('wc_status', 'synced')
                ->when($this->filterImportId, fn ($q) => $q->where('inventory_import_id', $this->filterImportId))
                ->count();
            $failed = ProductVariation::where('wc_status', 'failed')
                ->when($this->filterImportId, fn ($q) => $q->where('inventory_import_id', $this->filterImportId))
                ->count();

            $progress->log("Sync completado. Sincronizadas: {$synced} | Fallidas: {$failed}");
            $import->update([
                'status'       => InventoryImport::STATUS_COMPLETED,
                'completed_at' => now(),
                'stats'        => ['successful' => $synced, 'failed' => $failed, 'skipped' => 0],
            ]);
        }
    }

    private function buildAttributesMap(\Illuminate\Support\Collection $varsGrupo): array
    {
        $map = [];
        foreach ($varsGrupo as $variation) {
            foreach ($variation->atributos ?? [] as $attr) {
                $map[$attr['nombre']][] = $attr['valor'];
                $map[$attr['nombre']] = array_unique($map[$attr['nombre']]);
            }
        }
        return $map;
    }

    private function buildWcAttributes(array $atributosMap, array $padre): array
    {
        $existentes = [];
        foreach ($padre['attributes'] ?? [] as $attr) {
            $existentes[strtolower($attr['name'])] = $attr;
        }
        $result = [];
        foreach ($atributosMap as $nombre => $valores) {
            $key = strtolower($nombre);
            if (isset($existentes[$key])) {
                $existentesLower = array_map('strtolower', $existentes[$key]['options'] ?? []);
                $nuevos = array_values(array_filter($valores, fn ($v) => !in_array(strtolower($v), $existentesLower, true)));
                $result[] = [
                    'id' => $existentes[$key]['id'], 'name' => $existentes[$key]['name'],
                    'visible' => true, 'variation' => true,
                    'options' => array_merge($existentes[$key]['options'] ?? [], $nuevos),
                ];
            } else {
                $result[] = ['name' => $nombre, 'visible' => true, 'variation' => true, 'options' => array_values($valores)];
            }
        }
        return $result;
    }

    private function buildStockMeta(ProductVariation $variation): array
    {
        $meta = [];
        foreach (config('woocommerce.sedes', []) as $sedeId => $nombre) {
            $col    = 'stock_' . $sedeId;
            $meta[] = ['key' => '_stock_sede_' . $sedeId, 'value' => (string) ($variation->{$col} ?? 0)];
        }
        return $meta;
    }
}
