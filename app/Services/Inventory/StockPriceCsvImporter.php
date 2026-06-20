<?php

namespace App\Services\Inventory;

use App\Models\InventoryImport;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

class StockPriceCsvImporter
{
    private ?InventoryHeaderResolver $headerResolver = null;

    private ?InventoryProductRowParser $rowParser = null;

    /** @var array<string, int> */
    private array $headerMap = [];

    /** @var array<string, true> */
    private array $countedUpdatedSkus = [];

    /** @var array<string, true> */
    private array $syncedSkus = [];

    private bool $lastParseWasHardSkip = false;

    public function __construct(
        private readonly InventoryFileReader $fileReader,
        private readonly LocationResolver $locationResolver,
        private readonly ProductStockService $stockService,
    ) {}

    public function prepareQueuedImport(InventoryImport $import): void
    {
        $filePath = Storage::disk($import->disk)->path($import->stored_path);
        $progress = new InventoryImportProgress($import->id);

        $header = $this->readHeader($filePath);
        $headerMap = $this->buildHeaderMap($header);
        $resolver = InventoryHeaderResolver::fromHeaderMap($headerMap);
        $this->logDetectedColumns($progress, $resolver);

        $this->headerMap = $headerMap;
        $this->bootParsers();

        $progress->log('Modo rápido: solo stock por sede y precios (sin imágenes ni catálogo).');
        $rowCounts = $this->analyzeFileRows($filePath);

        $import->update([
            'total_rows' => $rowCounts['total'],
            'processed_rows' => 0,
            'header_map' => $headerMap,
            'checkpoint' => [
                'phase' => InventoryImportPhase::CATALOG,
                'counted_updated_skus' => [],
                'synced_skus' => [],
                'catalog_rows' => $rowCounts['catalog'],
                'stock_rows' => $rowCounts['stock'],
                'skip_image_wait' => true,
            ],
            'partial_stats' => $this->emptyStats(),
            'current_step' => 'Fase 1 — Precio y stock Puerto Ordaz',
        ]);

        $progress->log(sprintf(
            'Plan: %d filas · %d Puerto Ordaz (precio + stock) · %d otras sedes (stock). Luego XML parcial y WordPress.',
            $rowCounts['total'],
            $rowCounts['catalog'],
            $rowCounts['stock'],
        ));
    }

    /**
     * @return array{has_more: bool, next_skip: int, rows_scanned: int, phase_complete: ?string}
     */
    public function processQueuedChunk(InventoryImport $import, int $skipRows): array
    {
        $filePath = Storage::disk($import->disk)->path($import->stored_path);
        $progress = new InventoryImportProgress($import->id);
        $limit = config('inventory.rows_per_job', 25);

        $this->headerMap = $import->header_map ?? [];
        $this->bootParsers();

        $checkpoint = $import->checkpoint ?? [];
        $phase = $checkpoint['phase'] ?? InventoryImportPhase::CATALOG;
        $this->countedUpdatedSkus = $checkpoint['counted_updated_skus'] ?? [];
        $this->syncedSkus = $checkpoint['synced_skus'] ?? [];

        $stats = array_merge($this->emptyStats(), $import->partial_stats ?? []);
        $chunk = [];
        $rowsInBatch = 0;
        $rowsMatchedPhase = 0;
        $skippedLogger = new InventorySkippedRowLogger($import->id);

        foreach ($this->fileReader->dataRows($filePath, $skipRows, $limit) as $row) {
            $rowsInBatch++;
            $dataRowNumber = $skipRows + $rowsInBatch;
            $parsed = $this->tryParseRow($row, $dataRowNumber, $skippedLogger, $phase);

            if ($parsed === null) {
                if ($this->lastParseWasHardSkip) {
                    $stats['skipped']++;
                }

                continue;
            }

            if (! InventoryImportPhase::acceptsRow($parsed, $phase)) {
                continue;
            }

            $chunk[] = $parsed;
            $rowsMatchedPhase++;
            $stats['processed']++;
        }

        $rowsScanned = $skipRows + $rowsInBatch;
        $totalRows = $import->total_rows ?? $rowsScanned;

        if ($chunk !== []) {
            $chunkStats = $phase === InventoryImportPhase::CATALOG
                ? $this->processPrimaryChunk($chunk, $skippedLogger)
                : $this->processStockChunk($chunk, $skippedLogger);

            foreach (['updated', 'prices_updated', 'skipped', 'stock_applied', 'stock_skipped'] as $key) {
                if (array_key_exists($key, $chunkStats)) {
                    $stats[$key] += $chunkStats[$key];
                }
            }
        }

        $hasMore = $rowsInBatch > 0 && $rowsInBatch >= $limit;

        $import->update([
            'processed_rows' => $rowsScanned,
            'partial_stats' => $stats,
            'checkpoint' => array_merge($checkpoint, [
                'counted_updated_skus' => $this->countedUpdatedSkus,
                'synced_skus' => $this->syncedSkus,
                'skip_image_wait' => true,
            ]),
            'current_step' => $hasMore
                ? ($phase === InventoryImportPhase::CATALOG
                    ? 'Fase 1 — hasta fila '.$rowsScanned
                    : 'Fase 2 — hasta fila '.$rowsScanned)
                : ($phase === InventoryImportPhase::CATALOG ? 'Finalizando fase 1' : 'Finalizando fase 2'),
        ]);

        $percent = ($totalRows > 0) ? min(100, (int) round(($rowsScanned / $totalRows) * 100)) : 0;
        $this->logChunkProgress($progress, $phase, $rowsScanned, $totalRows, $percent, $rowsMatchedPhase, $stats);

        $phaseComplete = null;

        if (! $hasMore && $phase === InventoryImportPhase::CATALOG) {
            $phaseComplete = InventoryImportPhase::CATALOG;
        }

        return [
            'has_more' => $hasMore,
            'next_skip' => $rowsScanned,
            'rows_scanned' => $rowsScanned,
            'phase_complete' => $phaseComplete,
        ];
    }

    public function beginStockPhase(InventoryImport $import): void
    {
        $checkpoint = $import->checkpoint ?? [];
        $stats = array_merge($this->emptyStats(), $import->partial_stats ?? []);

        $resetCount = $this->stockService->resetSecondaryStoreStocks();

        $import->update([
            'processed_rows' => 0,
            'checkpoint' => array_merge($checkpoint, [
                'phase' => InventoryImportPhase::STOCK,
                'counted_updated_skus' => [],
                'skip_image_wait' => true,
            ]),
            'partial_stats' => $stats,
            'current_step' => InventoryImportPhase::label(InventoryImportPhase::STOCK),
        ]);

        $progress = new InventoryImportProgress($import->id);
        $stockRows = $checkpoint['stock_rows'] ?? '?';

        $progress->log(sprintf(
            'Fase 2: Lechería y Caracas reiniciados a 0 en %d registro(s).',
            $resetCount,
        ));
        $progress->log(sprintf(
            'Segunda lectura del archivo (%s filas otras sedes, solo stock).',
            (string) $stockRows,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array{updated: int, prices_updated: int, skipped: int}
     */
    private function processPrimaryChunk(array $chunk, ?InventorySkippedRowLogger $skipped): array
    {
        $updated = 0;
        $pricesUpdated = 0;
        $skippedCount = 0;

        foreach ($this->groupBySku($chunk) as $group) {
            $row = $group['row'];
            $sku = $group['sku'];
            $product = Product::query()->where('sku', $sku)->first();

            if ($product === null) {
                $skippedCount++;
                $this->recordSkip(
                    $skipped,
                    (int) ($row['_data_row'] ?? 0),
                    $sku,
                    $row['_title'] ?? null,
                    $row['cuenta_ml'] ?? null,
                    'product_not_found',
                    'Modo rápido: el producto no existe en catálogo (importación completa previa requerida).',
                );

                continue;
            }

            $pricePayload = $this->pricePayload($row);

            if ($pricePayload !== []) {
                $product->update($pricePayload);
                $pricesUpdated++;
            }

            if (! isset($this->countedUpdatedSkus[$sku])) {
                $updated++;
                $this->countedUpdatedSkus[$sku] = true;
            }

            $this->stockService->ensureAllStorePivots($product);
            $location = $this->locationResolver->resolveFromCuentaMl((string) $row['cuenta_ml']);
            $this->stockService->setLocationStock($product, $location->id, (int) $row['stock']);
            $this->markSyncedSku($sku);
        }

        return [
            'updated' => $updated,
            'prices_updated' => $pricesUpdated,
            'skipped' => $skippedCount,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array{stock_applied: int, stock_skipped: int, skipped: int}
     */
    private function processStockChunk(array $chunk, ?InventorySkippedRowLogger $skipped): array
    {
        $stockApplied = 0;
        $stockSkipped = 0;
        $skippedCount = 0;
        $grouped = [];

        foreach ($chunk as $row) {
            $key = $row['sku'].'|'.$row['cuenta_ml'];

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'sku' => $row['sku'],
                    'cuenta_ml' => $row['cuenta_ml'],
                    'stock' => 0,
                    'data_row' => $row['_data_row'] ?? 0,
                    'title' => $row['_title'] ?? null,
                ];
            }

            $grouped[$key]['stock'] += (int) $row['stock'];
        }

        foreach ($grouped as $group) {
            $product = Product::query()->where('sku', $group['sku'])->first();

            if ($product === null) {
                $stockSkipped++;
                $skippedCount++;
                $this->recordSkip(
                    $skipped,
                    (int) $group['data_row'],
                    $group['sku'],
                    $group['title'],
                    $group['cuenta_ml'],
                    'product_not_found',
                    'Fase 2: el producto no existe en catálogo.',
                );

                continue;
            }

            $location = $this->locationResolver->resolveFromCuentaMl($group['cuenta_ml']);
            $this->stockService->setLocationStock($product, $location->id, $group['stock']);
            $this->markSyncedSku($group['sku']);
            $stockApplied++;
        }

        return [
            'stock_applied' => $stockApplied,
            'stock_skipped' => $stockSkipped,
            'skipped' => $skippedCount,
        ];
    }

    private function markSyncedSku(string $sku): void
    {
        $this->syncedSkus[$sku] = true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function pricePayload(array $row): array
    {
        $payload = [];

        if (array_key_exists('price', $row) && $row['price'] !== null) {
            $payload['price'] = $row['price'];
        }

        if (array_key_exists('price_foreign', $row) && $row['price_foreign'] !== null) {
            $payload['price_foreign'] = $row['price_foreign'];
        }

        if (array_key_exists('price_currency', $row) && $row['price_currency'] !== null && $row['price_currency'] !== '') {
            $payload['price_currency'] = $row['price_currency'];
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunk
     * @return list<array{sku: string, row: array<string, mixed>}>
     */
    private function groupBySku(array $chunk): array
    {
        $grouped = [];

        foreach ($chunk as $row) {
            $sku = $row['sku'];

            if (! isset($grouped[$sku])) {
                $grouped[$sku] = ['sku' => $sku, 'row' => $row];
            }
        }

        return array_values($grouped);
    }

    /**
     * @return array{total: int, catalog: int, stock: int}
     */
    private function analyzeFileRows(string $filePath): array
    {
        $total = 0;
        $catalog = 0;
        $stock = 0;

        foreach ($this->fileReader->dataRows($filePath) as $row) {
            $total++;
            $parsed = $this->rowParser?->parse($row);

            if ($parsed === null) {
                continue;
            }

            if ($parsed['is_primary_catalog']) {
                $catalog++;
            } else {
                $stock++;
            }
        }

        return ['total' => $total, 'catalog' => $catalog, 'stock' => $stock];
    }

    /**
     * @param  array<int, string|null>  $row
     * @return array<string, mixed>|null
     */
    private function tryParseRow(
        array $row,
        int $dataRowNumber,
        ?InventorySkippedRowLogger $skipped,
        string $phase,
    ): ?array {
        $this->lastParseWasHardSkip = false;

        $sku = trim($this->headerResolver?->value($row, 'sku') ?? '');
        $title = trim($this->headerResolver?->value($row, 'titulo') ?? '');
        $cuentaMl = trim($this->headerResolver?->value($row, 'cuenta_ml') ?? '');

        if ($sku === '') {
            $this->lastParseWasHardSkip = true;
            $this->recordSkip($skipped, $dataRowNumber, null, $title ?: null, $cuentaMl ?: null, 'empty_sku', 'SKU vacío.');

            return null;
        }

        if ($cuentaMl === '') {
            $this->lastParseWasHardSkip = true;
            $this->recordSkip($skipped, $dataRowNumber, $sku, $title ?: null, null, 'empty_cuenta_ml', 'Cuenta ML vacía.');

            return null;
        }

        $parsed = $this->rowParser?->parse($row);

        if ($parsed === null) {
            return null;
        }

        if ($phase === InventoryImportPhase::STOCK && $parsed['is_primary_catalog']) {
            return null;
        }

        $parsed['_data_row'] = $dataRowNumber;
        $parsed['_title'] = $title;

        return $parsed;
    }

    private function recordSkip(
        ?InventorySkippedRowLogger $skipped,
        int $dataRowNumber,
        ?string $sku,
        ?string $title,
        ?string $cuentaMl,
        string $reasonCode,
        string $reason,
    ): void {
        if ($skipped === null) {
            return;
        }

        $skipped->record([
            'row' => $dataRowNumber,
            'sku' => $sku,
            'title' => $title,
            'cuenta_ml' => $cuentaMl,
            'reason_code' => $reasonCode,
            'reason' => $reason,
        ]);
    }

    /**
     * @return array{processed: int, updated: int, prices_updated: int, skipped: int, stock_applied: int, stock_skipped: int}
     */
    private function emptyStats(): array
    {
        return [
            'processed' => 0,
            'updated' => 0,
            'prices_updated' => 0,
            'skipped' => 0,
            'stock_applied' => 0,
            'stock_skipped' => 0,
        ];
    }

    /**
     * @param  array{processed: int, updated: int, prices_updated: int, skipped: int, stock_applied: int, stock_skipped: int}  $stats
     */
    private function logChunkProgress(
        InventoryImportProgress $progress,
        string $phase,
        int $rowsScanned,
        int $totalRows,
        int $percent,
        int $rowsMatchedPhase,
        array $stats,
    ): void {
        if ($phase === InventoryImportPhase::CATALOG) {
            $progress->log(sprintf(
                'Fase 1 — %d/%d (%d%%) · %d PO · %d productos · %d precios',
                $rowsScanned,
                $totalRows,
                $percent,
                $rowsMatchedPhase,
                $stats['updated'],
                $stats['prices_updated'],
            ));

            return;
        }

        $progress->log(sprintf(
            'Fase 2 — %d/%d (%d%%) · %d otras sedes · %d stock · %d sin producto',
            $rowsScanned,
            $totalRows,
            $percent,
            $rowsMatchedPhase,
            $stats['stock_applied'],
            $stats['stock_skipped'],
        ));
    }

    /**
     * @return array<int, string|null>
     */
    private function readHeader(string $filePath): array
    {
        foreach ($this->fileReader->rows($filePath) as $row) {
            if ($this->isEmptyHeader($row)) {
                throw new \InvalidArgumentException('El archivo está vacío o no tiene encabezados.');
            }

            return $row;
        }

        throw new \InvalidArgumentException('El archivo está vacío o no tiene encabezados.');
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function isEmptyHeader(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string|null>  $header
     * @return array<string, int>
     */
    private function buildHeaderMap(array $header): array
    {
        $map = [];

        foreach ($header as $index => $column) {
            if ($column !== null && trim((string) $column) !== '') {
                $map[trim((string) $column)] = (int) $index;
            }
        }

        return $map;
    }

    private function bootParsers(): void
    {
        $this->headerResolver = $this->headerMap !== []
            ? InventoryHeaderResolver::fromHeaderMap($this->headerMap)
            : null;

        if ($this->headerResolver === null) {
            $this->rowParser = null;

            return;
        }

        $this->rowParser = new InventoryProductRowParser(
            $this->headerResolver,
            $this->locationResolver,
            new InventoryAssociationParser,
            new ProductAttributeParser,
            new ProductDescriptionCleaner,
            new ProductDescriptionFormatter,
            new BrandExtractor,
            new ProductImageParser,
            new ProductPriceParser,
            new ProductCategoryParser,
        );
    }

    private function logDetectedColumns(InventoryImportProgress $progress, InventoryHeaderResolver $resolver): void
    {
        foreach (['sku' => 'SKU', 'cuenta_ml' => 'Cuenta ML', 'cantidad' => 'Cantidad', 'precio' => 'Precio'] as $key => $label) {
            if ($resolver->has($key)) {
                $progress->log("Columna detectada: {$label}");
            }
        }
    }
}
