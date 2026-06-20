<?php

namespace App\Services\Inventory;

use App\Models\InventoryImport;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

class ExclusiveStoreCsvImporter
{
    private const CHUNK_SIZE = 100;

    /** @var array<string, int> */
    private array $headerMap = [];

    /** @var array<string, true> */
    private array $countedUpdatedSkus = [];

    /** @var array<string, true> */
    private array $syncedSkus = [];

    /** @var array<string, true> */
    private array $stockInitializedSkus = [];

    private ?InventoryHeaderResolver $headerResolver = null;

    private ?InventoryProductRowParser $rowParser = null;

    private bool $lastParseWasHardSkip = false;

    private bool $lastParseWasSkipped = false;

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

        $this->headerMap = $headerMap;
        $this->bootParsers();

        $rowCounts = $this->analyzeFileRows($filePath);

        $import->update([
            'total_rows' => $rowCounts['total'],
            'processed_rows' => 0,
            'header_map' => $headerMap,
            'checkpoint' => [
                'synced_skus' => [],
                'stock_initialized_skus' => [],
                'skus_with_po_row' => $rowCounts['skus_with_po_row'],
                'secondary_rows' => $rowCounts['secondary'],
                'primary_rows' => $rowCounts['primary'],
            ],
            'partial_stats' => $this->emptyStats(),
            'current_step' => 'Importación multisede por SKU',
        ]);

        $progress->log(sprintf(
            'Plan: %d filas · %d Puerto Ordaz · %d Lechería/Caracas · %d SKU con fila PO.',
            $rowCounts['total'],
            $rowCounts['primary'],
            $rowCounts['secondary'],
            count($rowCounts['skus_with_po_row']),
        ));
        $progress->log('Regla: un SKU = un producto. No existe → se crea. Existe → stock por sede; precios desde el archivo (PO prioriza catálogo).');
        $progress->log('Sedes sin fila para ese SKU en el archivo quedan en stock 0. Filas sin SKU van a omitidas.');
        $progress->log('XML parcial products.xml + WordPress #19 al terminar.');
    }

    /**
     * @return array{has_more: bool, next_skip: int, rows_scanned: int, phase_complete: null}
     */
    public function processQueuedChunk(InventoryImport $import, int $skipRows): array
    {
        $filePath = Storage::disk($import->disk)->path($import->stored_path);
        $progress = new InventoryImportProgress($import->id);
        $limit = config('inventory.rows_per_job', 25);

        $this->headerMap = $import->header_map ?? [];
        $this->bootParsers();

        $checkpoint = $import->checkpoint ?? [];
        $this->countedUpdatedSkus = $checkpoint['counted_updated_skus'] ?? [];
        $this->syncedSkus = $checkpoint['synced_skus'] ?? [];
        $this->stockInitializedSkus = $checkpoint['stock_initialized_skus'] ?? [];

        $stats = array_merge($this->emptyStats(), $import->partial_stats ?? []);
        $chunk = [];
        $rowsInBatch = 0;
        $rowsMatched = 0;
        $skippedLogger = new InventorySkippedRowLogger($import->id);

        foreach ($this->fileReader->dataRows($filePath, $skipRows, $limit) as $row) {
            $rowsInBatch++;
            $dataRowNumber = $skipRows + $rowsInBatch;
            $parsed = $this->tryParseRow($row, $dataRowNumber, $skippedLogger);

            if ($parsed === null) {
                if ($this->lastParseWasHardSkip || $this->lastParseWasSkipped) {
                    $stats['skipped']++;
                }

                $this->lastParseWasSkipped = false;

                continue;
            }

            $chunk[] = $parsed;
            $rowsMatched++;
            $stats['processed']++;
        }

        $rowsScanned = $skipRows + $rowsInBatch;
        $totalRows = $import->total_rows ?? $rowsScanned;

        if ($chunk !== []) {
            $chunkStats = $this->processExclusiveChunk($chunk, $progress, $import->id, $stats);

            foreach (['created', 'updated', 'attributes_synced', 'images_queued', 'images_failed', 'skipped', 'stock_applied'] as $key) {
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
                'stock_initialized_skus' => $this->stockInitializedSkus,
            ]),
            'current_step' => $hasMore
                ? 'Exclusivos — hasta fila '.$rowsScanned
                : 'Finalizando productos exclusivos',
        ]);

        $percent = ($totalRows > 0) ? min(100, (int) round(($rowsScanned / $totalRows) * 100)) : 0;

        $progress->log(sprintf(
            'Exclusivos — %d/%d (%d%%) · %d filas sede en lote · %d creados · %d actualizados · %d stock',
            $rowsScanned,
            $totalRows,
            $percent,
            $rowsMatched,
            $stats['created'],
            $stats['updated'],
            $stats['stock_applied'],
        ));

        return [
            'has_more' => $hasMore,
            'next_skip' => $rowsScanned,
            'rows_scanned' => $rowsScanned,
            'phase_complete' => null,
        ];
    }

    /**
     * @return array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int, stock_applied: int}
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
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunk
     * @param  array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int, stock_applied: int}  $baseStats
     * @return array{created: int, updated: int, attributes_synced: int, images_queued: int, images_failed: int, stock_applied: int}
     */
    private function processExclusiveChunk(
        array $chunk,
        InventoryImportProgress $progress,
        int $importId,
        array $baseStats,
    ): array {
        $created = 0;
        $updated = 0;
        $attributesSynced = 0;
        $imagesQueued = 0;
        $imagesFailed = 0;
        $stockApplied = 0;

        /** @var array<string, list<array<string, mixed>>> $grouped */
        $grouped = [];

        foreach ($chunk as $row) {
            $sku = $row['sku'];
            $grouped[$sku] ??= [];
            $grouped[$sku][] = $row;
        }

        foreach ($grouped as $sku => $rows) {
            $poRow = $this->firstPrimaryRow($rows);
            $catalogRow = $this->resolveCatalogRow($rows, $sku);
            $product = Product::query()->where('sku', $sku)->first();
            $catalogStats = ['attributes' => 0, 'images_queued' => 0, 'images_failed' => 0];

            if ($product === null) {
                $metadata = ProductCatalogPayload::metadata($catalogRow);

                $product = Product::query()->create([
                    'sku' => $sku,
                    ...$metadata['product'],
                ]);
                $created++;
                $this->stockService->ensureAllStorePivots($product);
                $catalogStats = $this->syncCatalog($product, $metadata, $importId);
            } elseif ($poRow !== null) {
                $metadata = ProductCatalogPayload::metadata($catalogRow);

                if ($metadata['product'] !== []) {
                    $product->update($metadata['product']);
                }

                if (! isset($this->countedUpdatedSkus[$sku])) {
                    $updated++;
                    $this->countedUpdatedSkus[$sku] = true;
                }

                $catalogStats = $this->syncCatalog($product, $metadata, $importId);
            } else {
                $pricePayload = $this->priceAttributesFromCatalog($catalogRow);

                if ($pricePayload !== []) {
                    $product->update($pricePayload);

                    if (! isset($this->countedUpdatedSkus[$sku])) {
                        $updated++;
                        $this->countedUpdatedSkus[$sku] = true;
                    }
                }
            }

            $attributesSynced += $catalogStats['attributes'];
            $imagesQueued += $catalogStats['images_queued'];
            $imagesFailed += $catalogStats['images_failed'];

            $this->resetStockBaselineForSku($product, $sku);

            foreach ($this->aggregateStockByLocation($rows) as $entry) {
                $location = $this->locationResolver->resolveFromCuentaMl((string) $entry['cuenta_ml']);
                $this->stockService->setLocationStock($product, $location->id, $entry['stock']);
                $stockApplied++;
            }

            $this->markSyncedSku($sku);

            $progress->sync([
                'partial_stats' => [
                    'processed' => $baseStats['processed'],
                    'created' => $baseStats['created'] + $created,
                    'updated' => $baseStats['updated'] + $updated,
                    'skipped' => $baseStats['skipped'],
                    'attributes_synced' => $baseStats['attributes_synced'] + $attributesSynced,
                    'images_queued' => $baseStats['images_queued'] + $imagesQueued,
                    'images_failed' => $baseStats['images_failed'] + $imagesFailed,
                    'stock_applied' => $baseStats['stock_applied'] + $stockApplied,
                ],
                'current_step' => 'Exclusivo — '.$sku,
            ]);
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'attributes_synced' => $attributesSynced,
            'images_queued' => $imagesQueued,
            'images_failed' => $imagesFailed,
            'stock_applied' => $stockApplied,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function firstPrimaryRow(array $rows): ?array
    {
        foreach ($rows as $row) {
            if (($row['is_primary_catalog'] ?? false) === true) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Fila base PO; si faltan precios u otros campos, se completan desde cualquier fila del SKU.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function resolveCatalogRow(array $rows, string $sku): array
    {
        $base = $this->firstPrimaryRow($rows) ?? $rows[0];
        $merged = array_merge($base, ['sku' => $sku]);

        $catalogKeys = [
            'name',
            'brand',
            'price',
            'price_foreign',
            'price_currency',
            'warranty',
            'short_description',
            'long_description',
            'long_description_html',
            'attributes',
            'image_urls',
            'category_paths',
            'category_raw',
        ];

        foreach ($catalogKeys as $key) {
            if ($this->catalogFieldIsEmpty($merged[$key] ?? null)) {
                foreach ($rows as $row) {
                    $value = $row[$key] ?? null;

                    if (! $this->catalogFieldIsEmpty($value)) {
                        $merged[$key] = $value;
                        break;
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, float|string>
     */
    private function priceAttributesFromCatalog(array $catalog): array
    {
        return array_filter(
            [
                'price' => $catalog['price'] ?? null,
                'price_foreign' => $catalog['price_foreign'] ?? null,
                'price_currency' => $catalog['price_currency'] ?? null,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    private function catalogFieldIsEmpty(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_array($value) && $value === [];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{cuenta_ml: string, stock: int}>
     */
    private function aggregateStockByLocation(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $key = (string) $row['cuenta_ml'];

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'cuenta_ml' => $key,
                    'stock' => 0,
                ];
            }

            $grouped[$key]['stock'] += (int) $row['stock'];
        }

        return array_values($grouped);
    }

    private function resetStockBaselineForSku(Product $product, string $sku): void
    {
        if (isset($this->stockInitializedSkus[$sku])) {
            return;
        }

        $this->stockService->ensureAllStorePivots($product);

        foreach ($this->stockService->knownLocations() as $location) {
            $this->stockService->setLocationStock($product, $location->id, 0);
        }

        $this->stockInitializedSkus[$sku] = true;
    }

    private function markSyncedSku(string $sku): void
    {
        $this->syncedSkus[$sku] = true;
    }

    /**
     * @param  array{product: array<string, mixed>, attributes: array<string, string>, image_urls: list<string>, category_paths: list<string>, category_raw: ?string}  $metadata
     * @return array{attributes: int, categories: int, images_queued: int, images_failed: int}
     */
    private function syncCatalog(Product $product, array $metadata, int $importId): array
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
     * @param  array<int, string|null>  $row
     * @return array<string, mixed>|null
     */
    private function tryParseRow(
        array $row,
        int $dataRowNumber,
        ?InventorySkippedRowLogger $skipped,
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

        $parsed = $this->rowParser?->parseWithCatalog($row);

        if ($parsed === null) {
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
     * @return array{total: int, secondary: int, primary: int, skus_with_po_row: array<string, true>}
     */
    private function analyzeFileRows(string $filePath): array
    {
        $total = 0;
        $secondary = 0;
        $primary = 0;
        $skusWithPoRow = [];

        $this->headerMap = $this->buildHeaderMap($this->readHeader($filePath));
        $this->bootParsers();

        foreach ($this->fileReader->rows($filePath) as $index => $row) {
            if ($index === 0) {
                continue;
            }

            $parsed = $this->rowParser?->parseWithCatalog($row);

            if ($parsed === null) {
                continue;
            }

            $total++;

            if ($parsed['is_primary_catalog']) {
                $primary++;
                $skusWithPoRow[$parsed['sku']] = true;
            } else {
                $secondary++;
            }
        }

        return [
            'total' => $total,
            'secondary' => $secondary,
            'primary' => $primary,
            'skus_with_po_row' => $skusWithPoRow,
        ];
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
