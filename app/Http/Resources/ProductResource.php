<?php

namespace App\Http\Resources;

use App\Services\Inventory\ProductStockService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Product */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stockService = app(ProductStockService::class);
        $stockPayload = $stockService->apiStockPayload($this->resource);

        $locations = [];

        foreach ($stockPayload['by_location'] as $row) {
            $locations[$row['name']] = [
                'slug' => $row['slug'],
                'stock' => $row['stock'],
                'in_stock' => $row['in_stock'],
            ];
        }

        $images = $this->images->map(static fn ($image): array => [
            'url' => $image->publicUrl(),
            'path' => $image->path,
            'source_url' => $image->source_url,
            'is_primary' => $image->is_primary,
        ])->values()->all();

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'brand' => $this->brand,
            'price' => $this->price !== null ? (float) $this->price : null,
            'price_foreign' => $this->price_foreign !== null ? (float) $this->price_foreign : null,
            'price_currency' => $this->price_currency,
            'price_formatted' => $this->formattedPrice(),
            'warranty' => $this->warranty,
            'principal_stock' => $stockPayload['principal'],
            'stock' => $stockPayload,
            'categories' => $this->formattedCategories(),
            'short_description' => $this->short_description,
            'long_description' => $this->long_description,
            'long_description_html' => $this->long_description_html,
            'attributes' => $this->formattedAttributes(),
            'images' => $images,
            'locations' => $locations,
        ];
    }
}
