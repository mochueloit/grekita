<?php

namespace App\Jobs;

use App\Models\InventoryImport;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Services\Inventory\InventoryImportProgress;
use App\Services\WooCommerce\VariationExcelValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class ProcessVariationImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries   = 3;

    public function __construct(
        public readonly int $variationImportId,
    ) {}

    public function handle(VariationExcelValidator $validator): void
    {
        $import   = InventoryImport::findOrFail($this->variationImportId);
        $progress = new InventoryImportProgress($import->id);

        if ($import->isFinished()) {
            return;
        }

        $import->update([
            'status'     => InventoryImport::STATUS_PROCESSING,
            'started_at' => $import->started_at ?? now(),
        ]);

        $progress->log('Leyendo archivo Excel...');

        try {
            // 1. Leer Excel
            $path = Storage::disk($import->disk)->path($import->stored_path);
            $rows = $this->readExcel($path);

            $import->update(['total_rows' => count($rows)]);
            $progress->log('Archivo leído: ' . count($rows) . ' filas.');

            // 2. Validar y agrupar
            $progress->log('Validando filas...');
            $result = $validator->validate($rows);
            $groups = $result['groups'];
            $errors = $result['errors'];

            $progress->log(
                'Validación completa: ' . count($groups) . ' productos válidos, ' . count($errors) . ' errores.'
            );

            // 3. Guardar variaciones válidas en BD local
            $progress->log('Guardando variaciones en base de datos local...');

            $guardadas = 0;
            $fallidas  = 0;
            $processed = 0;

            $totalGrupos = count($groups);
            $progress->log("Guardando {$totalGrupos} productos en base de datos local...");
            $progress->log(str_repeat('-', 50));

            foreach ($groups as $skuPadre => $variaciones) {
                $progress->log(">>> Producto [{$processed}/{$totalGrupos}] SKU padre: {$skuPadre} (" . count($variaciones) . " variaciones)");

                $okEnGrupo   = 0;
                $failEnGrupo = 0;

                foreach ($variaciones as $skuVar => $data) {
                    try {
                        $atributos  = $data['atributos'];
                        $stockSedes = $data['stock_sedes'] ?? [];
                        $letra      = str_replace($skuPadre, '', $skuVar);

                        $stock482845934 = (int) ($stockSedes['482845934'] ?? 0);
                        $stock7196119   = (int) ($stockSedes['7196119']   ?? 0);
                        $stock82385465  = (int) ($stockSedes['82385465']  ?? 0);
                        $stockTotal     = $stock482845934 + $stock7196119 + $stock82385465;

                        // Descripción de atributos en una línea
                        $attrStr = implode(', ', array_map(
                            fn ($a) => $a['nombre'] . ':' . $a['valor'],
                            $atributos
                        ));

                        $wasNew = !ProductVariation::where('sku', $skuVar)->exists();

                        ProductVariation::updateOrCreate(
                            ['sku' => (string) $skuVar],
                            [
                                'sku_padre'           => (string) $skuPadre,
                                'letra'               => $letra,
                                'atributos'           => $atributos,
                                'stock_482845934'     => $stock482845934,
                                'stock_7196119'       => $stock7196119,
                                'stock_82385465'      => $stock82385465,
                                'stock_total'         => $stockTotal,
                                'inventory_import_id' => $import->id,
                                'wc_status'           => 'pending',
                                'wc_variation_id'     => null,
                                'wc_error'            => null,
                                'wc_synced_at'        => null,
                            ]
                        );

                        $accion = $wasNew ? 'NUEVA' : 'ACTUALIZADA';
                        $progress->log(
                            "    [{$accion}] {$skuVar} | {$attrStr} | Lech:{$stock482845934} Ccs:{$stock7196119} PO:{$stock82385465} Total:{$stockTotal}"
                        );

                        $guardadas++;
                        $okEnGrupo++;
                    } catch (Throwable $e) {
                        $fallidas++;
                        $failEnGrupo++;
                        $progress->log("    [ERROR] {$skuVar}: " . $e->getMessage());
                        $errors[] = [
                            'linea'         => '-',
                            'sku_padre'     => $skuPadre,
                            'sku_variacion' => $skuVar,
                            'variacion'     => '-',
                            'cuenta_ml'     => '-',
                            'motivo'        => 'Error al guardar: ' . $e->getMessage(),
                        ];
                    }
                }

                $processed++;
                $import->update([
                    'processed_rows' => $processed,
                    'partial_stats'  => [
                        'successful' => $guardadas,
                        'failed'     => $fallidas,
                        'skipped'    => count($errors),
                    ],
                ]);

                // Actualizar principal_stock del producto padre como suma de todas sus variaciones
                $skuPadreStr = (string) $skuPadre;
                $stockSumado = ProductVariation::where('sku_padre', $skuPadreStr)->sum('stock_total');
                $updated = Product::where('sku', $skuPadreStr)->update(['principal_stock' => $stockSumado]);

                $resumen = "    Resumen {$skuPadre}: {$okEnGrupo} ok";
                if ($failEnGrupo > 0) {
                    $resumen .= ", {$failEnGrupo} con error";
                }
                if ($updated) {
                    $resumen .= " | Stock principal actualizado: {$stockSumado}";
                } else {
                    $resumen .= " | (producto padre no encontrado en catalogo local)";
                }
                $progress->log($resumen);
                $progress->log(str_repeat('-', 50));
            }

            $progress->log("BD local: {$guardadas} variaciones guardadas, {$fallidas} fallidas.");

            // 4. Guardar reporte de errores de validación
            $errorReportPath = null;
            if (!empty($errors)) {
                $errorReportPath = $this->saveErrorReport($errors, $import);
                $progress->log(count($errors) . ' filas con errores. Reporte generado.');
            }

            // 5. Finalizar
            $checkpoint = $import->checkpoint ?? [];
            if ($errorReportPath) {
                $checkpoint['error_report'] = $errorReportPath;
            }

            $import->update([
                'status'       => InventoryImport::STATUS_COMPLETED,
                'completed_at' => now(),
                'checkpoint'   => $checkpoint,
                'stats'        => [
                    'successful' => $guardadas,
                    'failed'     => $fallidas,
                    'skipped'    => count($errors),
                ],
            ]);

            $progress->log(
                "Importación local completada. Variaciones guardadas: {$guardadas} | Errores: " . count($errors)
            );
            $progress->log('Listo para sincronizar con WooCommerce desde el panel.');

        } catch (Throwable $e) {
            $import->update([
                'status'        => InventoryImport::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            $progress->log('Error: ' . $e->getMessage());

            throw $e;
        }
    }

    private function readExcel(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $data        = $sheet->toArray(null, true, true, false);

        if (empty($data)) {
            return [];
        }

        $headers = array_map('trim', (array) array_shift($data));
        $rows    = [];

        foreach ($data as $row) {
            $row    = array_map(fn ($v) => $v === null ? '' : trim((string) $v), $row);
            $rows[] = array_combine($headers, $row);
        }

        return array_filter($rows, fn ($row) => array_filter($row) !== []);
    }

    private function saveErrorReport(array $errors, InventoryImport $import): string
    {
        $filename = 'variation-errors-import-' . $import->id . '-' . now()->format('Ymd-His') . '.csv';
        $path     = 'variation-imports/reports/' . $filename;

        $csv = implode(',', ['Línea', 'SKU Padre', 'SKU Variación', 'Variación', 'Cuenta ML', 'Motivo']) . "\n";
        foreach ($errors as $error) {
            $csv .= implode(',', array_map(
                fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"',
                $error
            )) . "\n";
        }

        Storage::disk($import->disk)->put($path, $csv);

        return $path;
    }
}
