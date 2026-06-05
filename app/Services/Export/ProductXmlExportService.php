<?php

namespace App\Services\Export;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use XMLWriter;

class ProductXmlExportService
{
    public function __construct(
        private readonly ProductXmlFlatFields $flatFields,
    ) {}
    /**
     * @return array{
     *     generated_at: string,
     *     product_count: int,
     *     relative_path: string,
     *     absolute_path: string,
     *     manifest_relative_path: string,
     *     latest_relative_path: string,
     *     latest_absolute_path: string,
     *     trigger: string,
     *     disk: string
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
        $writer->writeAttribute('version', '1');

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

        $writer->endElement(); // products

        $this->writeScalar($writer, 'product_count', $count);
        $writer->endElement(); // catalog
        $writer->endDocument();
        $writer->flush();

        return $count;
    }

    private function writeProductNode(XMLWriter $writer, Product $product): void
    {
        /** @var array<string, mixed> $payload */
        $payload = ProductResource::make($product)->resolve();

        $writer->startElement('product');

        $this->writeScalar($writer, 'id', $payload['id'] ?? null);
        $this->writeScalar($writer, 'sku', $payload['sku'] ?? null);
        $this->writeScalar($writer, 'name', $payload['name'] ?? null);
        $this->writeScalar($writer, 'brand', $payload['brand'] ?? null);
        $this->writeScalar($writer, 'price', $payload['price'] ?? null);
        $this->writeScalar($writer, 'price_foreign', $payload['price_foreign'] ?? null);
        $this->writeScalar($writer, 'price_currency', $payload['price_currency'] ?? null);
        $this->writeScalar($writer, 'price_formatted', $payload['price_formatted'] ?? null);
        $this->writeScalar($writer, 'warranty', $payload['warranty'] ?? null);
        $this->writeScalar($writer, 'principal_stock', $payload['principal_stock'] ?? 0);

        $categories = $payload['categories'] ?? [];
        $attributes = $payload['attributes'] ?? [];
        $dimensions = $this->flatFields->dimensions($attributes);

        $this->writeScalar($writer, 'categories', $this->flatFields->categoriesText($categories));
        $this->writeScalar($writer, 'width', $dimensions['width']);
        $this->writeScalar($writer, 'height', $dimensions['height']);
        $this->writeScalar($writer, 'weight', $dimensions['weight']);

        $this->writeCDataElement($writer, 'short_description', $payload['short_description'] ?? null);
        $this->writeCDataElement($writer, 'long_description', $payload['long_description'] ?? null);
        $this->writeCDataElement($writer, 'long_description_html', $payload['long_description_html'] ?? null);

        $this->writeAttributes($writer, $this->flatFields->attributesForXml($attributes));
        $this->writeImages($writer, $payload['images'] ?? []);
        $this->writeStock($writer, $payload['stock'] ?? []);
        $this->writeLocations($writer, $payload['locations'] ?? []);

        $writer->endElement();
    }

    /**
     * @param  list<array{code: string, label: string, value: string}>  $attributes
     */
    private function writeAttributes(XMLWriter $writer, array $attributes): void
    {
        $writer->startElement('attributes');

        foreach ($attributes as $attribute) {
            $writer->startElement('attribute');
            $this->writeScalar($writer, 'code', $attribute['code'] ?? null);
            $this->writeScalar($writer, 'label', $attribute['label'] ?? null);
            $this->writeCDataElement($writer, 'value', $attribute['value'] ?? null);
            $writer->endElement();
        }

        $writer->endElement();
    }

    /**
     * @param  list<array{url: ?string, path: ?string, source_url: ?string, is_primary: bool}>  $images
     */
    private function writeImages(XMLWriter $writer, array $images): void
    {
        $writer->startElement('images');

        foreach ($images as $index => $image) {
            $writer->startElement('image');
            $this->writeScalar($writer, 'sort_order', $index);
            $this->writeScalar($writer, 'url', $image['url'] ?? null);
            $this->writeScalar($writer, 'path', $image['path'] ?? null);
            $this->writeScalar($writer, 'source_url', $image['source_url'] ?? null);
            $this->writeScalar($writer, 'is_primary', ! empty($image['is_primary']) ? 1 : 0);
            $writer->endElement();
        }

        $writer->endElement();
    }

    /**
     * @param  array{principal?: int, by_location?: list<array{slug: string, name: string, stock: int, in_stock: bool}>}  $stock
     */
    private function writeStock(XMLWriter $writer, array $stock): void
    {
        $writer->startElement('stock');
        $this->writeScalar($writer, 'principal', $stock['principal'] ?? 0);

        $writer->startElement('locations');
        foreach ($stock['by_location'] ?? [] as $location) {
            $writer->startElement('location');
            $this->writeScalar($writer, 'slug', $location['slug'] ?? null);
            $this->writeScalar($writer, 'name', $location['name'] ?? null);
            $this->writeScalar($writer, 'stock', $location['stock'] ?? 0);
            $this->writeScalar($writer, 'in_stock', ! empty($location['in_stock']) ? 1 : 0);
            $writer->endElement();
        }
        $writer->endElement();

        $writer->endElement();
    }

    /**
     * @param  array<string, array{slug: string, stock: int, in_stock: bool}>  $locations
     */
    private function writeLocations(XMLWriter $writer, array $locations): void
    {
        $writer->startElement('locations_legacy');

        foreach ($locations as $name => $location) {
            $writer->startElement('location');
            $this->writeScalar($writer, 'name', $name);
            $this->writeScalar($writer, 'slug', $location['slug'] ?? null);
            $this->writeScalar($writer, 'stock', $location['stock'] ?? 0);
            $this->writeScalar($writer, 'in_stock', ! empty($location['in_stock']) ? 1 : 0);
            $writer->endElement();
        }

        $writer->endElement();
    }

    private function writeScalar(XMLWriter $writer, string $name, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (is_bool($value)) {
            $writer->writeElement($name, $value ? '1' : '0');

            return;
        }

        $writer->writeElement($name, htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8'));
    }

    private function writeCDataElement(XMLWriter $writer, string $name, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $writer->startElement($name);
        $writer->writeCData((string) $value);
        $writer->endElement();
    }
}
