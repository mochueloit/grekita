<?php

namespace App\Services\Inventory;

use App\Jobs\DownloadProductImageJob;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageDownloader
{
    private const MAX_IMAGES = 10;

    public function __construct(
        private readonly ProductImageParser $imageParser,
    ) {}

    /**
     * Registra URLs y encola descargas lentas (no bloquea la importación).
     *
     * @param  list<string>  $urls
     * @return array{queued: int, failed: int}
     */
    public function queueFromUrls(Product $product, array $urls, ?int $importId = null): array
    {
        $urls = array_slice($urls, 0, self::MAX_IMAGES);
        $product->images()->delete();

        if ($urls === []) {
            return ['queued' => 0, 'failed' => 0];
        }

        $imageLogger = $importId !== null ? new InventoryImageDownloadLogger($importId) : null;
        $queued = 0;
        $delaySeconds = max(1, (int) config('inventory.image_download_delay_seconds', 3));
        $pendingOffset = ProductImage::query()->where('status', ProductImage::STATUS_PENDING)->count();

        foreach ($urls as $index => $url) {
            $image = ProductImage::query()->create([
                'product_id' => $product->id,
                'inventory_import_id' => $importId,
                'source_url' => $url,
                'path' => null,
                'sort_order' => $index,
                'is_primary' => $index === 0,
                'status' => ProductImage::STATUS_PENDING,
                'queued_at' => now(),
            ]);

            DownloadProductImageJob::dispatch($image->id)
                ->delay(now()->addSeconds(($pendingOffset + $index) * $delaySeconds));

            $imageLogger?->log(sprintf(
                'Encolada imagen %d/%d — SKU %s — %s',
                $index + 1,
                count($urls),
                $product->sku,
                $url,
            ));

            $queued++;
        }

        if ($imageLogger !== null && $queued > 0) {
            $imageLogger->log(sprintf(
                'SKU %s: %d imagen(es) en cola de descarga para esta importación.',
                $product->sku,
                $queued,
            ));
        }

        return ['queued' => $queued, 'failed' => 0];
    }

    public function downloadRecord(ProductImage $image): bool
    {
        $image->update([
            'status' => ProductImage::STATUS_DOWNLOADING,
            'attempts' => $image->attempts + 1,
            'error_message' => null,
        ]);

        $product = $image->product;

        if ($product === null) {
            $image->update([
                'status' => ProductImage::STATUS_FAILED,
                'error_message' => 'Producto no encontrado.',
            ]);

            return false;
        }

        $path = $this->download($product->sku, $image->source_url, $image->sort_order);

        if ($path === null) {
            $image->update([
                'status' => ProductImage::STATUS_FAILED,
                'error_message' => 'No se pudo descargar la imagen.',
            ]);

            return false;
        }

        $image->update([
            'path' => $path,
            'status' => ProductImage::STATUS_COMPLETED,
            'downloaded_at' => now(),
        ]);

        return true;
    }

    /**
     * @param  list<string>  $urls
     * @return array{stored: int, failed: int}
     */
    public function syncFromUrls(Product $product, array $urls, ?int $importId = null): array
    {
        $result = $this->queueFromUrls($product, $urls, $importId);

        return [
            'stored' => $result['queued'],
            'failed' => $result['failed'],
        ];
    }

    private function download(string $sku, string $url, int $index): ?string
    {
        try {
            $response = Http::timeout((int) config('inventory.image_download_timeout', 20))
                ->retry(2, 1000)
                ->get($url);

            if ($response->status() === 429) {
                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            $extension = $this->guessExtension($url, $response->header('Content-Type'));
            $directory = 'products/'.Str::slug($sku);
            $filename = sprintf('%s-%d.%s', Str::slug($sku), $index + 1, $extension);
            $path = "{$directory}/{$filename}";

            Storage::disk('public')->put($path, $response->body());

            return $path;
        } catch (\Throwable) {
            return null;
        }
    }

    private function guessExtension(string $url, ?string $contentType): string
    {
        $pathExtension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        if (in_array($pathExtension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return $pathExtension === 'jpeg' ? 'jpg' : $pathExtension;
        }

        return match (true) {
            str_contains((string) $contentType, 'webp') => 'webp',
            str_contains((string) $contentType, 'png') => 'png',
            str_contains((string) $contentType, 'gif') => 'gif',
            default => 'jpg',
        };
    }
}
