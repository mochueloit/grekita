<?php

namespace App\Services\Inventory;

use App\Models\InventoryImport;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

class InventoryCsvImporter
{
    private const CHUNK_SIZE = 100;

    /** @var array<string, int> */
    private array $headerMap = [];

    private ?InventoryHeaderResolver $headerResolver = null;

    private ?InventoryProductRowParser $rowParser = null;

    /** @var array<string, true> */
    private array $countedUpdatedSkus = [];

    public function __construct(
        private readonly InventoryFileReader $fileReader,
        private readonly LocationResolver $locationResolver,
        private readonly ProductAttributeSyncService $attributeSync,
        private readonly ProductImageDownloader $imageDownloader,
        private readonly ProductCategorySyncService $categorySync,
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

        $progress->log('Analizando filas por sede (una lectura del archivo)…');
        $rowCounts = $this->analyzeFileRows($filePath, $headerMap);
        $totalRows = $rowCounts['total'];

        $import->update([
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'header_map' => $headerMap,
            'checkpoint' => [
                'phase' => InventoryImportPhase::CATALOG,
                'counted_updated_skus' => [],
                'catalog_rows' => $rowCounts['catalog'],
                'stock_rows' => $rowCounts['stock'],
            ],
            'partial_stats' => $this->emptyStats(),
            'current_step' => InventoryImportPhase::label(InventoryImportPhase::CATALOG),
        ]);

        $progress->log(sprintf(
            'Plan de importación: %d filas totales · %d Puerto Ordaz (catálogo + imágenes) · %d otras sedes (solo stock, 2.ª lectura).',
            $totalRows,
            $rowCounts['catalog'],
            $rowCounts['stock'],
        ));
        $progress->log('Fase 1: se filtra el archivo por cuenta Puerto Ordaz y se registran productos.');
        $progress->log('Fase 2: se vuelve a leer el archivo completo y solo se importa stock de Lechería y Caracas.');
        $progress->log('Lotes de '.config('inventory.rows_per_job', 25).' filas leídas por pasada.');
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
                ? $this->processCatalogChunk($chunk, $progress, $rowsScanned, $totalRows, $stats, $import->id)
                : $this->processStockChunk($chunk, $skippedLogger);

            foreach (['created', 'updated', 'attributes_synced', 'images_queued', 'images_failed', 'skipped', 'stock_applied', 'stock_skipped'] as $key) {
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
            ]),
            'current_step' => $hasMore
                ? InventoryImportPhase::label($phase).' — hasta fila '.$rowsScanned
                : ($phase === InventoryImportPhase::CATALOG
                    ? 'Finalizando fase 1'
                    : 'Finalizando fase 2'),
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

        $import->update([
            'processed_rows' => 0,
            'checkpoint' => array_merge($checkpoint, [
                'phase' => InventoryImportPhase::STOCK,
                'counted_updated_skus' => [],
            ]),
            'partial_stats' => $stats,
            'current_step' => InventoryImportPhase::label(InventoryImportPhase::STOCK),
        ]);

        $resetCount = $this->stockService->resetSecondaryStoreStocks();

        $progress = new InventoryImportProgress($import->id);
        $catalogRows = $checkpoint['catalog_rows'] ?? '?';
        $stockRows = $checkpoint['stock_rows'] ?? '?';

        $progress->log(sprintf(
            'Fase 2: stock de Lechería y Caracas reiniciado a 0 en %d registro(s) antes de aplicar el archivo.',
            $resetCount,
        ));

        $progress->log(sprintf(
            'Fase 1 terminada: %d productos creados, %d actualizados, %d imágenes en cola.',
            $stats['created'] ?? 0,
            $stats['updated'] ?? 0,
            $stats['images_queued'] ?? 0,
        ));
        $progress->log(sprintf(
            'Iniciando fase 2 — segunda lectura del archivo (%s filas de otras sedes, solo stock).',
            (string) $stockRows,
        ));
        $progress->log('Se omiten filas de Puerto Ordaz; el producto debe existir desde la fase 1.');
    }

    /**
     * @return array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int, stock_applied: int, stock_skipped: int}
     */
    private function emptyStats(): array
    {
        return [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'attributes_synced' => 0,
            'images_queued' => 0,
            'images_failed' => 0,
            'stock_applied' => 0,
            'stock_skipped' => 0,
        ];
    }

    /**
     * @return array{total: int, catalog: int, stock: int}
     */
    private function analyzeFileRows(string $filePath, array $headerMap): array
    {
        $this->headerMap = $headerMap;
        $this->bootParsers();

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
     * @return array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int, stock_applied: int, stock_skipped: int}
     */
    public function import(string $filePath, ?InventoryImport $import = null): array
    {
        $progress = $import !== null ? new InventoryImportProgress($import->id) : null;

        if ($progress !== null) {
            $progress->log('Importación en dos fases (catálogo PO → stock otras sedes)…');
        }

        $stats = $this->emptyStats();

        foreach ([InventoryImportPhase::CATALOG, InventoryImportPhase::STOCK] as $phase) {
            if ($progress !== null) {
                $progress->log(InventoryImportPhase::label($phase));
            }

            if ($phase === InventoryImportPhase::STOCK) {
                $resetCount = $this->stockService->resetSecondaryStoreStocks();

                if ($progress !== null) {
                    $progress->log(sprintf(
                        'Fase 2: stock de Lechería y Caracas reiniciado a 0 en %d registro(s).',
                        $resetCount,
                    ));
                }
            }

            $phaseStats = $this->importPhaseFromFile($filePath, $phase, $progress, $import);

            foreach ($phaseStats as $key => $value) {
                $stats[$key] += $value;
            }
        }

        if ($progress !== null) {
            $progress->log(sprintf(
                'Importación terminada: %d filas válidas, %d omitidas, %d stock aplicado, %d imágenes en cola.',
                $stats['processed'],
                $stats['skipped'],
                $stats['stock_applied'],
                $stats['images_queued'],
            ));
        }

        return $stats;
    }

    /**
     * @return array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int, stock_applied: int, stock_skipped: int}
     */
    private function importPhaseFromFile(
        string $filePath,
        string $phase,
        ?InventoryImportProgress $progress,
        ?InventoryImport $import,
    ): array {
        $rowIterator = $this->fileReader->rows($filePath);
        $header = null;

        foreach ($rowIterator as $row) {
            $header = $row;

            break;
        }

        if ($header === null || $this->isEmptyHeader($header)) {
            throw new \InvalidArgumentException('El archivo está vacío o no tiene encabezados.');
        }

        $this->headerMap = $this->buildHeaderMap($header);
        $this->bootParsers();
        $this->countedUpdatedSkus = [];

        $stats = $this->emptyStats();
        $chunk = [];
        $rowsScanned = 0;
        $chunkNumber = 0;
        $skippedLogger = $import !== null ? new InventorySkippedRowLogger($import->id) : null;

        foreach ($rowIterator as $row) {
            $rowsScanned++;
            $parsed = $this->tryParseRow($row, $rowsScanned, $skippedLogger, $phase);

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
            $stats['processed']++;

            if (count($chunk) >= self::CHUNK_SIZE) {
                $chunkNumber++;
                $this->mergeChunkStats($stats, $phase, $chunk, $progress, $rowsScanned, $skippedLogger, $import?->id);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $this->mergeChunkStats($stats, $phase, $chunk, $progress, $rowsScanned, $skippedLogger, $import?->id);
        }

        return $stats;
    }

    /**
     * @param  array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int, stock_applied: int, stock_skipped: int}  $stats
     * @param  array<int, array<string, mixed>>  $chunk
     */
    private function mergeChunkStats(
        array &$stats,
        string $phase,
        array $chunk,
        ?InventoryImportProgress $progress,
        int $rowsScanned,
        ?InventorySkippedRowLogger $skipped,
        ?int $importId,
    ): void {
        $chunkStats = $phase === InventoryImportPhase::CATALOG
            ? $this->processCatalogChunk($chunk, $progress, $rowsScanned, null, $stats, $importId)
            : $this->processStockChunk($chunk, $skipped);

        foreach (['created', 'updated', 'attributes_synced', 'images_queued', 'images_failed', 'skipped', 'stock_applied', 'stock_skipped'] as $key) {
            if (array_key_exists($key, $chunkStats)) {
                $stats[$key] += $chunkStats[$key];
            }
        }
    }

    private bool $lastParseWasHardSkip = false;

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
            $this->recordSkip($skipped, $dataRowNumber, null, $title ?: null, $cuentaMl ?: null, 'empty_sku', 'SKU vacío: la fila no tiene identificador de producto.');

            return null;
        }

        if ($cuentaMl === '') {
            $this->lastParseWasHardSkip = true;
            $this->recordSkip($skipped, $dataRowNumber, $sku, $title ?: null, null, 'empty_cuenta_ml', 'Cuenta ML vacía: no se puede asignar a ninguna sede.');

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
     * @param  array<int, array<string, mixed>>  $chunk
     * @param  array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int, stock_applied: int, stock_skipped: int}  $baseStats
     * @return array{created: int, updated: int, attributes_synced: int, images_queued: int, images_failed: int, skipped: int}
     */
    private function processCatalogChunk(
        array $chunk,
        ?InventoryImportProgress $progress,
        int $rowsScanned,
        ?int $totalRows,
        array $baseStats,
        ?int $importId = null,
    ): array {
        $created = 0;
        $updated = 0;
        $attributesSynced = 0;
        $imagesQueued = 0;
        $imagesFailed = 0;

        $grouped = $this->groupCatalogBySku($chunk);

        foreach ($grouped as $group) {
            $metadata = ProductCatalogPayload::metadata(
                array_merge($group['catalog'], ['sku' => $group['sku']]),
            );

            $product = Product::query()->where('sku', $group['sku'])->first();
            $catalogStats = ['attributes' => 0, 'images_queued' => 0, 'images_failed' => 0];

            if ($product === null) {
                $product = Product::query()->create([
                    'sku' => $group['sku'],
                    ...$metadata['product'],
                ]);
                $created++;

                if ($progress !== null) {
                    $progress->sync(['current_step' => 'Fase 1 — encolando imágenes '.$group['sku']]);
                }

                $this->stockService->ensureAllStorePivots($product);

                $catalogStats = $this->syncCatalog($product, $metadata, $importId);
            } else {
                if ($metadata['product'] !== []) {
                    $product->update($metadata['product']);

                    if (! isset($this->countedUpdatedSkus[$group['sku']])) {
                        $updated++;
                        $this->countedUpdatedSkus[$group['sku']] = true;
                    }
                }

                if ($progress !== null) {
                    $progress->sync(['current_step' => 'Fase 1 — SKU '.$group['sku']]);
                }

                $this->stockService->ensureAllStorePivots($product);

                $catalogStats = $this->syncCatalog($product, $metadata, $importId);
            }

            $attributesSynced += $catalogStats['attributes'];
            $imagesQueued += $catalogStats['images_queued'];
            $imagesFailed += $catalogStats['images_failed'];

            $location = $this->locationResolver->resolveFromCuentaMl($group['catalog']['cuenta_ml']);
            $this->stockService->setLocationStock($product, $location->id, (int) $group['catalog']['stock']);

            if ($progress !== null) {
                $progress->sync([
                    'processed_rows' => $rowsScanned,
                    'partial_stats' => [
                        'processed' => $baseStats['processed'],
                        'created' => $baseStats['created'] + $created,
                        'updated' => $baseStats['updated'] + $updated,
                        'skipped' => $baseStats['skipped'],
                        'attributes_synced' => $baseStats['attributes_synced'] + $attributesSynced,
                        'images_queued' => $baseStats['images_queued'] + $imagesQueued,
                        'images_failed' => $baseStats['images_failed'] + $imagesFailed,
                        'stock_applied' => $baseStats['stock_applied'],
                        'stock_skipped' => $baseStats['stock_skipped'],
                    ],
                    'current_step' => 'Fase 1 — '.$group['sku'],
                ]);

                if ($catalogStats['images_queued'] > 0) {
                    $progress->log(sprintf('SKU %s — %d imagen(es) en cola', $group['sku'], $catalogStats['images_queued']));
                }
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'attributes_synced' => $attributesSynced,
            'images_queued' => $imagesQueued,
            'images_failed' => $imagesFailed,
            'skipped' => 0,
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
                    'Fase 2: el producto no existe (falta fila de Puerto Ordaz en fase 1).',
                );

                continue;
            }

            $location = $this->locationResolver->resolveFromCuentaMl($group['cuenta_ml']);
            $this->stockService->setLocationStock($product, $location->id, $group['stock']);
            $stockApplied++;
        }

        return [
            'stock_applied' => $stockApplied,
            'stock_skipped' => $stockSkipped,
            'skipped' => $skippedCount,
        ];
    }

    /**
     * @param  array{product: array<string, mixed>, attributes: array<string, string>, image_urls: list<string>, category_paths: list<string>, category_raw: ?string}  $metadata
     * @return array{attributes: int, categories: int, images_queued: int, images_failed: int}
     */
    private function syncCatalog(Product $product, array $metadata, ?int $importId = null): array
    {
        $attributes = $this->attributeSync->sync($product, $metadata['attributes'] ?? []);
        $categories = $this->categorySync->sync(
            $product,
            $metadata['category_paths'] ?? $metadata['category_raw'] ?? [],
        );

        $imageStats = ['queued' => 0, 'failed' => 0];

        if (($metadata['image_urls'] ?? []) !== []) {
            $imageStats = $this->imageDownloader->queueFromUrls($product, $metadata['image_urls'], $importId);
        }

        return [
            'attributes' => $attributes,
            'categories' => $categories,
            'images_queued' => $imageStats['queued'],
            'images_failed' => $imageStats['failed'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunk
     * @return array<string, array{sku: string, catalog: array<string, mixed>}>
     */
    private function groupCatalogBySku(array $chunk): array
    {
        $grouped = [];

        foreach ($chunk as $row) {
            $sku = $row['sku'];

            if (! isset($grouped[$sku])) {
                $grouped[$sku] = [
                    'sku' => $sku,
                    'catalog' => $row,
                ];
            }
        }

        return $grouped;
    }

    /**
     * @param  array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int, stock_applied: int, stock_skipped: int}  $stats
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
                'Fase 1 — %d/%d filas leídas (%d%%) · %d filas PO en este lote · %d creados · %d actualizados · %d imágenes en cola',
                $rowsScanned,
                $totalRows,
                $percent,
                $rowsMatchedPhase,
                $stats['created'],
                $stats['updated'],
                $stats['images_queued'],
            ));

            return;
        }

        $progress->log(sprintf(
            'Fase 2 — %d/%d filas leídas (%d%%) · %d filas otras sedes en lote · %d stock aplicado · %d sin producto',
            $rowsScanned,
            $totalRows,
            $percent,
            $rowsMatchedPhase,
            $stats['stock_applied'],
            $stats['stock_skipped'],
        ));
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

    /**
     * @param  array<int, string|null>  $header
     */
    private function isEmptyHeader(array $header): bool
    {
        foreach ($header as $column) {
            if (trim((string) ($column ?? '')) !== '') {
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

    private function logDetectedColumns(InventoryImportProgress $progress, InventoryHeaderResolver $resolver): void
    {
        $checks = [
            'precio' => 'Precio',
            'precio_divisas' => 'Precio en divisas',
            'divisa' => 'Divisa/Moneda',
            'garantia' => 'Garantía',
            'categoria' => 'Categoría',
        ];

        foreach ($checks as $key => $label) {
            if ($resolver->has($key)) {
                $progress->log("Columna detectada: {$label}");
            } else {
                $progress->log("AVISO: no se detectó columna «{$label}» en el archivo.");
            }
        }
    }
}
