<?php

namespace App\Services\Export;

use App\Models\Product;
use App\Services\Inventory\ProductStockService;
use Illuminate\Support\Facades\Storage;
use XMLWriter;

class ProductXmlExportService
{
    public function __construct(
        private readonly ProductXmlFlatFields $flatFields,
        private readonly ProductStockService $stockService,
    ) {}

    /**
     * @return array{
     *     generated_at: string,
     *     product_count: int,
     *     database_product_count: int,
     *     relative_path: string,
     *     absolute_path: string,
     *     manifest_relative_path: string,
     *     latest_relative_path: string,
     *     latest_absolute_path: string,
     *     trigger: string,
     *     disk: string,
     *     checksum_sha256: ?string
     * }
     */
    public function generate(string $trigger = 'manual'): array
    {
        $disk = (string) config('wp_export.disk', 'local');
        $basePath = trim((string) config('wp_export.base_path', 'exports/wp-xml'), '/');
        $filename = (string) config('wp_export.filename', 'products.xml');
        $latestRelativePath = (string) config('wp_export.latest_relative_path', 'exports/wp-xml/latest/products.xml');

        $generatedAt = now();
        $folder = $generatedAt->format('Y-m-d').'/'.$generatedAt->format('H-i-s');
        $relativeDir = $basePath.'/'.$folder;
        $relativePath = $relativeDir.'/'.$filename;
        $manifestRelativePath = $relativeDir.'/manifest.json';

        Storage::disk($disk)->makeDirectory($relativeDir);

        $databaseProductCount = Product::query()->count();
        $productCount = $this->writeXmlFile($disk, $relativePath, $generatedAt);

        if ($productCount !== $databaseProductCount) {
            throw new \RuntimeException(sprintf(
                'El XML exportó %d productos pero en la base hay %d. Regenera el archivo; si persiste, revisa productos con datos corruptos.',
                $productCount,
                $databaseProductCount,
            ));
        }

        Storage::disk($disk)->makeDirectory(dirname($latestRelativePath));
        Storage::disk($disk)->put($latestRelativePath, Storage::disk($disk)->get($relativePath));

        $absolutePath = Storage::disk($disk)->path($relativePath);
        $checksum = hash_file('sha256', $absolutePath) ?: null;

        $manifest = [
            'generated_at' => $generatedAt->toIso8601String(),
            'product_count' => $productCount,
            'database_product_count' => $databaseProductCount,
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'latest_relative_path' => $latestRelativePath,
            'latest_absolute_path' => Storage::disk($disk)->path($latestRelativePath),
            'trigger' => $trigger,
            'disk' => $disk,
            'checksum_sha256' => $checksum,
        ];

        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        Storage::disk($disk)->put($manifestRelativePath, $manifestJson);
        Storage::disk($disk)->put(dirname($latestRelativePath).'/manifest.json', $manifestJson);

        return [
            'generated_at' => $manifest['generated_at'],
            'product_count' => $productCount,
            'database_product_count' => $databaseProductCount,
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'manifest_relative_path' => $manifestRelativePath,
            'latest_relative_path' => $latestRelativePath,
            'latest_absolute_path' => $manifest['latest_absolute_path'],
            'trigger' => $trigger,
            'disk' => $disk,
            'checksum_sha256' => $checksum,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestManifest(): ?array
    {
        $disk = (string) config('wp_export.disk', 'local');
        $latestRelativePath = (string) config('wp_export.latest_relative_path', 'exports/wp-xml/latest/products.xml');

        if (! Storage::disk($disk)->exists($latestRelativePath)) {
            return null;
        }

        $latestManifestPath = dirname($latestRelativePath).'/manifest.json';

        if (Storage::disk($disk)->exists($latestManifestPath)) {
            $decoded = json_decode(Storage::disk($disk)->get($latestManifestPath), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [
            'generated_at' => null,
            'product_count' => null,
            'relative_path' => null,
            'absolute_path' => null,
            'latest_relative_path' => $latestRelativePath,
            'latest_absolute_path' => Storage::disk($disk)->path($latestRelativePath),
            'trigger' => null,
            'disk' => $disk,
            'file_size_bytes' => Storage::disk($disk)->size($latestRelativePath),
            'file_modified_at' => date('c', Storage::disk($disk)->lastModified($latestRelativePath)),
        ];
    }

    private function writeXmlFile(string $disk, string $relativePath, \Illuminate\Support\Carbon $generatedAt): int
    {
        $absolutePath = Storage::disk($disk)->path($relativePath);

        $writer = new XMLWriter;
        $writer->openUri($absolutePath);
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        $writer->startElement('catalog');
        $writer->writeAttribute('generated_at', $generatedAt->toIso8601String());
        $writer->writeAttribute('generator', 'grekita-wp-export');
        $writer->writeAttribute('version', '2');

        $writer->startElement('products');

        $count = 0;

        foreach (
            Product::query()
                ->with(['locations', 'images', 'attributeDefinitions', 'categories'])
                ->orderBy('sku')
                ->cursor() as $product
        ) {
            $this->writeProductNode($writer, $product);
            $count++;
        }

        $writer->endElement();

        $this->writeRequiredScalar($writer, 'product_count', $count);
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();

        return $count;
    }

    private function writeProductNode(XMLWriter $writer, Product $product): void
    {
        $attributes = $product->formattedAttributes();
        $dimensions = $this->flatFields->dimensions($attributes);
        $imageUrls = $this->flatFields->localImageUrls($product);
        $stockRows = $this->stockService->stocksForProduct($product);

        $writer->startElement('product');

        $this->writeRequiredScalar($writer, 'id', $product->id);
        $this->writeRequiredScalar($writer, 'sku', $product->sku);
        $this->writeRequiredScalar($writer, 'name', $product->name);
        $this->writeRequiredScalar($writer, 'brand', $this->flatFields->brandValue($product, $attributes));
        $this->writeRequiredScalar($writer, 'price', $product->price ?? 0);
        $this->writeRequiredScalar($writer, 'price_foreign', $product->price_foreign ?? 0);
        $this->writeRequiredScalar($writer, 'price_currency', $product->price_currency);
        $this->writeRequiredScalar($writer, 'price_formatted', $product->formattedPrice());
        $this->writeRequiredScalar($writer, 'warranty', $product->warranty);
        $this->writeRequiredScalar($writer, 'principal_stock', $product->principalStockTotal());

        $this->writeRequiredScalar($writer, 'categories', $this->flatFields->categoriesTextFromProduct($product));
        $this->writeRequiredScalar($writer, 'width', $dimensions['width']);
        $this->writeRequiredScalar($writer, 'height', $dimensions['height']);
        $this->writeRequiredScalar($writer, 'length', $dimensions['length']);
        $this->writeRequiredScalar($writer, 'weight', $dimensions['weight']);

        $this->writeRequiredCData($writer, 'short_description', $product->short_description);
        $this->writeRequiredCData($writer, 'long_description', $product->long_description);
        $this->writeRequiredCData($writer, 'long_description_html', $product->long_description_html);

        $this->writeRequiredScalar($writer, 'images_urls', $this->flatFields->localImageUrlsText($product));
        $this->writeImages($writer, $imageUrls);

        $this->writeStockLocations($writer, $stockRows);
        $this->writeAttributes($writer, $this->flatFields->attributesForXml($attributes));

        $writer->endElement();
    }

    /**
     * @param  list<string>  $imageUrls
     */
    private function writeImages(XMLWriter $writer, array $imageUrls): void
    {
        $writer->startElement('images');

        if ($imageUrls === []) {
            $this->writeRequiredScalar($writer, 'image', '0');
        } else {
            foreach ($imageUrls as $index => $url) {
                $writer->startElement('image');
                $this->writeRequiredScalar($writer, 'sort_order', $index);
                $this->writeRequiredScalar($writer, 'url', $url);
                $this->writeRequiredScalar($writer, 'is_primary', $index === 0 ? 1 : 0);
                $writer->endElement();
            }
        }

        $writer->endElement();
    }

    /**
     * @param  list<array{id: int, slug: string, name: string, stock: int, in_stock: bool, registered: bool}>  $stockRows
     */
    private function writeStockLocations(XMLWriter $writer, array $stockRows): void
    {
        $writer->startElement('stock');

        $principal = array_sum(array_column($stockRows, 'stock'));
        $this->writeRequiredScalar($writer, 'principal', $principal);

        $writer->startElement('locations');
        foreach ($stockRows as $location) {
            $writer->startElement('location');
            $this->writeRequiredScalar($writer, 'slug', $location['slug']);
            $this->writeRequiredScalar($writer, 'name', $location['name']);
            $this->writeRequiredScalar($writer, 'stock', $location['stock']);
            $this->writeRequiredScalar($writer, 'in_stock', $location['in_stock'] ? 1 : 0);
            $writer->endElement();
        }
        $writer->endElement();

        $writer->endElement();
    }

    /**
     * @param  list<array{code: string, label: string, value: string}>  $attributes
     */
    private function writeAttributes(XMLWriter $writer, array $attributes): void
    {
        $writer->startElement('attributes');

        if ($attributes === []) {
            $this->writeRequiredScalar($writer, 'attribute', '0');
        } else {
            foreach ($attributes as $attribute) {
                $writer->startElement('attribute');
                $this->writeRequiredScalar($writer, 'code', $attribute['code'] ?? '0');
                $this->writeRequiredScalar($writer, 'label', $attribute['label'] ?? '0');
                $this->writeRequiredCData($writer, 'value', $attribute['value'] ?? '0');
                $writer->endElement();
            }
        }

        $writer->endElement();
    }

    private function writeRequiredScalar(XMLWriter $writer, string $name, mixed $value): void
    {
        if ($value === null || $value === '') {
            $writer->writeElement($name, '0');

            return;
        }

        if (is_bool($value)) {
            $writer->writeElement($name, $value ? '1' : '0');

            return;
        }

        $writer->writeElement($name, htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
    }

    private function writeRequiredCData(XMLWriter $writer, string $name, mixed $value): void
    {
        $writer->startElement($name);

        if ($value === null || $value === '') {
            $writer->writeCData('0');
        } else {
            $writer->writeCData((string) $value);
        }

        $writer->endElement();
    }
}
