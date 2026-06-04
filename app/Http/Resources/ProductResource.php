<?php

namespace App\Http\Resources;

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
        $locations = [];

        foreach ($this->locations as $location) {
            $stock = (int) $location->pivot->stock;
            $locations[$location->name] = [
                'stock' => $stock,
                'in_stock' => $stock > 0,
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
