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

    /** @var array<string, true> */
    private array $countedUpdatedSkus = [];

    public function __construct(
        private readonly InventoryFileReader $fileReader,
        private readonly LocationResolver $locationResolver,
        private readonly InventoryAssociationParser $associationParser,
        private readonly ProductAttributeParser $attributeParser,
        private readonly ProductDescriptionCleaner $descriptionCleaner,
        private readonly ProductDescriptionFormatter $descriptionFormatter,
        private readonly BrandExtractor $brandExtractor,
        private readonly ProductImageParser $imageParser,
        private readonly ProductAttributeSyncService $attributeSync,
        private readonly ProductImageDownloader $imageDownloader,
        private readonly ProductPriceParser $priceParser,
        private readonly ProductCategoryParser $categoryParser,
        private readonly ProductCategorySyncService $categorySync,
    ) {}

    public function prepareQueuedImport(InventoryImport $import): void
    {
        $filePath = Storage::disk($import->disk)->path($import->stored_path);
        $progress = new InventoryImportProgress($import->id);

        $progress->log('Contando filas del archivo…');
        $totalRows = $this->fileReader->countDataRows($filePath);

        $header = $this->readHeader($filePath);
        $headerMap = $this->buildHeaderMap($header);

        $import->update([
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'header_map' => $headerMap,
            'checkpoint' => [
                'counted_updated_skus' => [],
            ],
            'partial_stats' => $this->emptyStats(),
            'current_step' => 'En cola para procesamiento',
        ]);

        $progress->log("Listo: {$totalRows} filas. Se procesarán en lotes de ".config('inventory.rows_per_job', 25).'.');
    }

    /**
     * @return array{has_more: bool, next_skip: int, rows_scanned: int}
     */
    public function processQueuedChunk(InventoryImport $import, int $skipRows): array
    {
        $filePath = Storage::disk($import->disk)->path($import->stored_path);
        $progress = new InventoryImportProgress($import->id);
        $limit = config('inventory.rows_per_job', 25);

        $this->headerMap = $import->header_map ?? [];
        $this->bootHeaderResolver();
        $checkpoint = $import->checkpoint ?? ['counted_updated_skus' => []];
        $this->countedUpdatedSkus = $checkpoint['counted_updated_skus'] ?? [];

        $stats = array_merge($this->emptyStats(), $import->partial_stats ?? []);
        $chunk = [];
        $rowsInBatch = 0;
        $skippedLogger = new InventorySkippedRowLogger($import->id);

        foreach ($this->fileReader->dataRows($filePath, $skipRows, $limit) as $row) {
            $rowsInBatch++;
            $dataRowNumber = $skipRows + $rowsInBatch;
            $parsed = $this->tryParseRow($row, $dataRowNumber, $skippedLogger);

            if ($parsed === null) {
                $stats['skipped']++;

                continue;
            }

            $chunk[] = $parsed;
            $stats['processed']++;
        }

        $rowsScanned = $skipRows + $rowsInBatch;
        $totalRows = $import->total_rows ?? $rowsScanned;

        if ($chunk !== []) {
            $chunkStats = $this->processChunk($chunk, $progress, $rowsScanned, $totalRows, $stats, $skippedLogger, $import->id);
            foreach (['created', 'updated', 'attributes_synced', 'images_queued', 'images_failed', 'skipped'] as $key) {
                $stats[$key] += $chunkStats[$key];
            }
            if ($chunkStats['skipped'] > 0) {
                $stats['processed'] = max(0, $stats['processed'] - $chunkStats['skipped']);
            }
        }

        $hasMore = $rowsInBatch > 0 && $rowsInBatch >= $limit;

        $import->update([
            'processed_rows' => $rowsScanned,
            'partial_stats' => $stats,
            'checkpoint' => [
                'counted_updated_skus' => $this->countedUpdatedSkus,
            ],
            'current_step' => $hasMore ? 'Lote hasta fila '.$rowsScanned : 'Finalizando',
        ]);

        $percent = ($import->total_rows ?? 0) > 0
            ? min(100, (int) round(($rowsScanned / $import->total_rows) * 100))
            : 0;

        $progress->log(sprintf(
            'Lote completado — %d/%d filas (%d%%) · %d creados · %d actualizados · %d imágenes en cola',
            $rowsScanned,
            $import->total_rows ?? $rowsScanned,
            $percent,
            $stats['created'],
            $stats['updated'],
            $stats['images_queued'],
        ));

        return [
            'has_more' => $hasMore,
            'next_skip' => $rowsScanned,
            'rows_scanned' => $rowsScanned,
        ];
    }

    /**
     * @return array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int}
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

    /**
     * @return array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int}
     */
    public function import(string $filePath, ?InventoryImport $import = null): array
    {
        $progress = $import !== null ? new InventoryImportProgress($import->id) : null;

        if ($progress !== null) {
            $progress->log('Contando filas del archivo…');
            $totalRows = $this->fileReader->countDataRows($filePath);
            $progress->sync([
                'total_rows' => $totalRows,
                'processed_rows' => 0,
                'current_step' => 'Preparando importación',
            ]);
            $progress->log("Archivo listo: {$totalRows} filas de datos detectadas.");
        } else {
            $totalRows = null;
        }

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
        $this->bootHeaderResolver();
        $this->countedUpdatedSkus = [];

        $stats = $this->emptyStats();

        $chunk = [];
        $rowsScanned = 0;
        $chunkNumber = 0;

        if ($progress !== null) {
            $progress->sync(['current_step' => 'Procesando filas']);
            $progress->log('Iniciando procesamiento por lotes…');
        }

        $skippedLogger = $import !== null ? new InventorySkippedRowLogger($import->id) : null;

        foreach ($rowIterator as $row) {
            $rowsScanned++;
            $parsed = $this->tryParseRow($row, $rowsScanned, $skippedLogger);

            if ($parsed === null) {
                $stats['skipped']++;

                continue;
            }

            $chunk[] = $parsed;
            $stats['processed']++;

            if (count($chunk) >= self::CHUNK_SIZE) {
                $chunkNumber++;
                $chunkStats = $this->processChunk($chunk, $progress, $rowsScanned, $totalRows, $stats, $skippedLogger, $import->id);
                foreach (['created', 'updated', 'attributes_synced', 'images_queued', 'images_failed', 'skipped'] as $key) {
                    $stats[$key] += $chunkStats[$key];
                }
                if ($chunkStats['skipped'] > 0) {
                    $stats['processed'] = max(0, $stats['processed'] - $chunkStats['skipped']);
                }
                $this->reportProgress($progress, $rowsScanned, $totalRows, $stats, $chunkNumber);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $chunkNumber++;
            $chunkStats = $this->processChunk($chunk, $progress, $rowsScanned, $totalRows, $stats, $skippedLogger, $import->id);
            foreach (['created', 'updated', 'attributes_synced', 'images_queued', 'images_failed', 'skipped'] as $key) {
                $stats[$key] += $chunkStats[$key];
            }
            if ($chunkStats['skipped'] > 0) {
                $stats['processed'] = max(0, $stats['processed'] - $chunkStats['skipped']);
            }
            $this->reportProgress($progress, $rowsScanned, $totalRows, $stats, $chunkNumber);
        }

        if ($progress !== null) {
            $progress->sync([
                'processed_rows' => $rowsScanned,
                'partial_stats' => $stats,
                'current_step' => 'Finalizando',
            ]);
            $progress->log(sprintf(
                'Importación terminada: %d filas válidas, %d omitidas, %d imágenes descargadas.',
                $stats['processed'],
                $stats['skipped'],
                $stats['images_queued'],
            ));
        }

        return $stats;
    }

    /**
     * @param  array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int}  $stats
     */
    private function reportProgress(
        ?InventoryImportProgress $progress,
        int $rowsScanned,
        ?int $totalRows,
        array $stats,
        int $chunkNumber,
    ): void {
        if ($progress === null) {
            return;
        }

        $progress->sync([
            'processed_rows' => $rowsScanned,
            'partial_stats' => $stats,
            'current_step' => 'Procesando filas',
        ]);

        $totalLabel = $totalRows !== null ? (string) $totalRows : '?';
        $percent = ($totalRows !== null && $totalRows > 0)
            ? min(100, (int) round(($rowsScanned / $totalRows) * 100))
            : null;

        $percentLabel = $percent !== null ? " ({$percent}%)" : '';

        $progress->log(sprintf(
            'Lote #%d — %d/%s filas%s · %d creados · %d actualizados · %d imágenes en cola · %d omitidas',
            $chunkNumber,
            $rowsScanned,
            $totalLabel,
            $percentLabel,
            $stats['created'],
            $stats['updated'],
            $stats['images_queued'],
            $stats['skipped'],
        ));
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
            if ($column !== null && $column !== '') {
                $map[trim($column)] = $index;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, string|null>  $row
     * @return array<string, mixed>|null
     */
    private function tryParseRow(array $row, int $dataRowNumber, ?InventorySkippedRowLogger $skipped = null): ?array
    {
        $sku = trim($this->headerValue($row, 'sku') ?? '');
        $title = trim($this->headerValue($row, 'titulo') ?? '');
        $cuentaMl = trim($this->headerValue($row, 'cuenta_ml') ?? '');

        if ($sku === '') {
            $this->recordSkip($skipped, $dataRowNumber, null, $title ?: null, $cuentaMl ?: null, 'empty_sku', 'SKU vacío: la fila no tiene identificador de producto.');

            return null;
        }

        if ($cuentaMl === '') {
            $this->recordSkip($skipped, $dataRowNumber, $sku, $title ?: null, null, 'empty_cuenta_ml', 'Cuenta ML vacía: no se puede asignar stock a ninguna sede.');

            return null;
        }

        $description = trim($this->headerValue($row, 'descripcion') ?? '');
        $associations = trim($this->headerValue($row, 'asociaciones') ?? '');
        $quantity = (int) ($this->headerValue($row, 'cantidad') ?? 0);
        $rawAttributes = $this->headerValue($row, 'atributos') ?? '';
        $attributes = $this->attributeParser->parse($rawAttributes);
        $cleanDescription = $this->descriptionCleaner->clean($description);
        $location = $this->locationResolver->resolveFromCuentaMl($cuentaMl);
        $isPrimaryCatalog = $location->slug === LocationResolver::PRIMARY_LOCATION_SLUG;
        $categoryRaw = $this->headerValue($row, 'categoria');

        $foreignRaw = null;
        $price = null;
        $priceForeign = null;
        $priceCurrency = null;

        if ($isPrimaryCatalog) {
            $foreignRaw = $this->headerValue($row, 'precio_divisas');
            $priceForeign = $this->priceParser->parse($foreignRaw);
            $price = $this->priceParser->parse($this->headerValue($row, 'precio'));
            $priceCurrency = $this->priceParser->parseCurrency($this->headerValue($row, 'divisa'));

            if ($priceCurrency === null && $priceForeign === null) {
                $priceCurrency = $this->inferCurrencyCode($foreignRaw);
            }
        }

        return [
            'sku' => $sku,
            'name' => $title !== '' ? $title : $sku,
            'brand' => $this->brandExtractor->extract($attributes, $rawAttributes),
            'price' => $price,
            'price_foreign' => $priceForeign,
            'price_currency' => $priceCurrency,
            'warranty' => trim($this->headerValue($row, 'garantia') ?? '') ?: null,
            'category_paths' => $this->categoryParser->parse($categoryRaw),
            'category_raw' => $categoryRaw,
            'short_description' => $this->descriptionCleaner->toShort($cleanDescription),
            'long_description' => $cleanDescription,
            'long_description_html' => $this->descriptionFormatter->toHtml($cleanDescription),
            'attributes' => $attributes,
            'image_urls' => $this->imageParser->parse($this->headerValue($row, 'imagenes') ?? ''),
            'cuenta_ml' => $cuentaMl,
            'location_slug' => $location->slug,
            'is_primary_catalog' => $isPrimaryCatalog,
            'stock' => $this->associationParser->parseStock($associations, $quantity),
            '_data_row' => $dataRowNumber,
        ];
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
     * @param  array{processed: int, created: int, updated: int, skipped: int, attributes_synced: int, images_queued: int, images_failed: int}  $baseStats
     * @return array{created: int, updated: int, attributes_synced: int, images_queued: int, images_failed: int, skipped: int}
     */
    private function processChunk(
        array $chunk,
        ?InventoryImportProgress $progress,
        int $rowsScanned,
        ?int $totalRows,
        array $baseStats,
        ?InventorySkippedRowLogger $skipped = null,
        ?int $importId = null,
    ): array {
        $created = 0;
        $updated = 0;
        $attributesSynced = 0;
        $imagesQueued = 0;
        $imagesFailed = 0;
        $skippedInChunk = 0;

        $grouped = $this->groupBySkuAndLocation($chunk);

        foreach ($grouped as $group) {
            $product = Product::query()->where('sku', $group['sku'])->first();
            $metadataForCreate = $group['primary_metadata'] ?? $group['fallback_metadata'];
            $catalogStats = ['attributes' => 0, 'images_queued' => 0, 'images_failed' => 0];

            if ($product === null) {
                if ($metadataForCreate === null) {
                    $skippedInChunk++;
                    $this->recordSkip(
                        $skipped,
                        (int) ($group['first_row'] ?? 0),
                        $group['sku'],
                        $group['title'] ?? null,
                        $group['cuenta_ml'] ?? null,
                        'no_catalog_source',
                        'El SKU no existe y esta fila no aporta ficha de catálogo (solo Puerto Ordaz puede crear productos nuevos).',
                    );

                    continue;
                }

                $product = Product::query()->create([
                    'sku' => $group['sku'],
                    ...$metadataForCreate['product'],
                ]);
                $created++;

                if ($progress !== null) {
                    $progress->sync(['current_step' => 'Encolando imágenes SKU '.$group['sku']]);
                }

                $catalogStats = $this->syncCatalog($product, $metadataForCreate, $importId);
                $attributesSynced += $catalogStats['attributes'];
                $imagesQueued += $catalogStats['images_queued'];
                $imagesFailed += $catalogStats['images_failed'];
            } elseif ($group['primary_metadata'] !== null) {
                $product->update($group['primary_metadata']['product']);

                if (! isset($this->countedUpdatedSkus[$group['sku']])) {
                    $updated++;
                    $this->countedUpdatedSkus[$group['sku']] = true;
                }

                if ($progress !== null) {
                    $progress->sync(['current_step' => 'Encolando imágenes SKU '.$group['sku']]);
                }

                $catalogStats = $this->syncCatalog($product, $group['primary_metadata'], $importId);
                $attributesSynced += $catalogStats['attributes'];
                $imagesQueued += $catalogStats['images_queued'];
                $imagesFailed += $catalogStats['images_failed'];
            }

            if ($product === null) {
                continue;
            }

            foreach ($group['locations'] as $locationData) {
                $location = $this->locationResolver->resolveFromCuentaMl($locationData['cuenta_ml']);
                $this->applyStock($product, $location->id, $locationData['stock']);
            }

            if ($progress !== null) {
                $partial = [
                    'processed' => $baseStats['processed'],
                    'created' => $baseStats['created'] + $created,
                    'updated' => $baseStats['updated'] + $updated,
                    'skipped' => $baseStats['skipped'],
                    'attributes_synced' => $baseStats['attributes_synced'] + $attributesSynced,
                    'images_queued' => $baseStats['images_queued'] + $imagesQueued,
                    'images_failed' => $baseStats['images_failed'] + $imagesFailed,
                ];

                $progress->sync([
                    'processed_rows' => $rowsScanned,
                    'partial_stats' => $partial,
                    'current_step' => 'SKU '.$group['sku'],
                ]);

                if ($catalogStats['images_queued'] > 0) {
                    $progress->log(sprintf(
                        'SKU %s — %d imágenes en cola de descarga',
                        $group['sku'],
                        $catalogStats['images_queued'],
                    ));
                }
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'attributes_synced' => $attributesSynced,
            'images_queued' => $imagesQueued,
            'images_failed' => $imagesFailed,
            'skipped' => $skippedInChunk,
        ];
    }

    private function applyStock(Product $product, int $locationId, int $addedStock): void
    {
        $existing = $product->locations()->where('locations.id', $locationId)->first();
        $current = $existing !== null ? (int) $existing->pivot->stock : 0;

        $product->locations()->syncWithoutDetaching([
            $locationId => ['stock' => $current + $addedStock],
        ]);
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
     * @return array<string, array<string, mixed>>
     */
    private function groupBySkuAndLocation(array $chunk): array
    {
        $grouped = [];

        foreach ($chunk as $row) {
            $sku = $row['sku'];
            $locationKey = $row['cuenta_ml'];
            $metadata = $this->extractMetadata($row);

            if (! isset($grouped[$sku])) {
                $grouped[$sku] = [
                    'sku' => $sku,
                    'primary_metadata' => null,
                    'fallback_metadata' => null,
                    'locations' => [],
                    'first_row' => $row['_data_row'] ?? 0,
                    'title' => $row['name'] ?? null,
                    'cuenta_ml' => $row['cuenta_ml'] ?? null,
                ];
            }

            if ($row['is_primary_catalog']) {
                $grouped[$sku]['primary_metadata'] = $metadata;
            } elseif ($grouped[$sku]['fallback_metadata'] === null) {
                $grouped[$sku]['fallback_metadata'] = $metadata;
            }

            if (! isset($grouped[$sku]['locations'][$locationKey])) {
                $grouped[$sku]['locations'][$locationKey] = [
                    'cuenta_ml' => $locationKey,
                    'stock' => 0,
                ];
            }

            $grouped[$sku]['locations'][$locationKey]['stock'] += $row['stock'];
        }

        foreach ($grouped as &$group) {
            $group['locations'] = array_values($group['locations']);
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{product: array<string, mixed>, attributes: array<string, string>, image_urls: list<string>, category_paths: list<string>, category_raw: ?string}
     */
    private function extractMetadata(array $row): array
    {
        return [
            'product' => [
                'name' => $row['name'],
                'brand' => $row['brand'],
                'price' => $row['price'],
                'price_foreign' => $row['price_foreign'],
                'price_currency' => $row['price_currency'],
                'warranty' => $row['warranty'],
                'short_description' => $row['short_description'],
                'long_description' => $row['long_description'],
                'long_description_html' => $row['long_description_html'],
            ],
            'attributes' => $row['attributes'],
            'image_urls' => $row['image_urls'],
            'category_paths' => $row['category_paths'] ?? [],
            'category_raw' => $row['category_raw'] ?? null,
        ];
    }

    private function bootHeaderResolver(): void
    {
        $this->headerResolver = $this->headerMap !== []
            ? InventoryHeaderResolver::fromHeaderMap($this->headerMap)
            : null;
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function headerValue(array $row, string $canonicalKey): ?string
    {
        if ($this->headerResolver !== null) {
            return $this->headerResolver->value($row, $canonicalKey);
        }

        return null;
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function value(array $row, string $column): ?string
    {
        if (! isset($this->headerMap[$column])) {
            return null;
        }

        $index = $this->headerMap[$column];

        return isset($row[$index]) ? trim((string) $row[$index]) : null;
    }

    private function inferCurrencyCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if (preg_match('/^[A-Za-z]{3}$/', $trimmed) === 1) {
            return strtoupper($trimmed);
        }

        return null;
    }
}
