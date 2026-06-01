<?php

namespace App\Jobs;

use App\Models\ProductImage;
use App\Services\Inventory\InventoryImageDownloadLogger;
use App\Services\Inventory\ProductImageDownloader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DownloadProductImageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 45;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $productImageId,
    ) {
        $this->onQueue(config('inventory.image_queue', 'images'));
    }

    public function handle(ProductImageDownloader $downloader): void
    {
        $image = ProductImage::query()->with('product')->find($this->productImageId);

        if ($image === null || $image->status === ProductImage::STATUS_COMPLETED) {
            return;
        }

        $downloader->downloadRecord($image);
        $image = $image->fresh();

        $importId = $image?->inventory_import_id;

        if ($importId !== null && $image !== null) {
            $logger = new InventoryImageDownloadLogger($importId);
            $sku = $image->product?->sku ?? '?';

            if ($image->status === ProductImage::STATUS_COMPLETED) {
                $logger->log(sprintf('OK — SKU %s — imagen #%d guardada en %s', $sku, $image->sort_order + 1, $image->path));
            } else {
                $logger->log(sprintf(
                    'FALLO — SKU %s — imagen #%d — %s',
                    $sku,
                    $image->sort_order + 1,
                    $image->error_message ?? 'Error desconocido',
                ), 'ERROR');
            }

            $logger->maybeLogCompletionSummary();
        }

        $pause = (int) config('inventory.image_download_pause_seconds', 1);

        if ($pause > 0) {
            sleep($pause);
        }
    }
}
