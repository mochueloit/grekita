<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'sku',
        'name',
        'brand',
        'price',
        'price_foreign',
        'price_currency',
        'warranty',
        'principal_stock',
        'short_description',
        'long_description',
        'long_description_html',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'price_foreign' => 'decimal:2',
            'principal_stock' => 'integer',
        ];
    }

    /**
     * Stock total en las 3 sedes (Puerto Ordaz + Lechería + Caracas).
     */
    public function principalStockTotal(): int
    {
        return (int) ($this->principal_stock ?? 0);
    }

    /**
     * @return list<array{id: int, slug: string, name: string, stock: int, in_stock: bool, registered: bool}>
     */
    public function knownStoreStocks(): array
    {
        return app(\App\Services\Inventory\ProductStockService::class)->stocksForProduct($this);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderBy('category_product.sort_order');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)
            ->withPivot('stock')
            ->withTimestamps();
    }

    public function attributeDefinitions(): BelongsToMany
    {
        return $this->belongsToMany(AttributeDefinition::class, 'attribute_product', 'product_id', 'attribute_id')
            ->withPivot('value')
            ->withTimestamps()
            ->orderBy('attributes.label_es');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function primaryImage(): ?ProductImage
    {
        return $this->images->firstWhere('is_primary', true) ?? $this->images->first();
    }

    /**
     * @return list<array{path: string, segments: list<string>, depth: int}>
     */
    public function formattedCategories(): array
    {
        return $this->categories
            ->map(static fn (Category $category): array => [
                'path' => $category->full_path,
                'segments' => $category->segmentNames(),
                'depth' => $category->depth,
            ])
            ->values()
            ->all();
    }

    public function formattedPrice(): ?string
    {
        $parts = [];

        if ($this->price !== null) {
            $parts[] = number_format((float) $this->price, 2, ',', '.');
        }

        if ($this->price_foreign !== null) {
            $foreign = number_format((float) $this->price_foreign, 2, ',', '.');
            $parts[] = $this->price_currency
                ? $foreign.' '.$this->price_currency
                : $foreign.' (divisa)';
        }

        return $parts !== [] ? implode(' · ', $parts) : null;
    }

    /**
     * @return list<array{code: string, label: string, value: string}>
     */
    public function formattedAttributes(): array
    {
        return $this->attributeDefinitions
            ->map(static fn (AttributeDefinition $attribute): array => [
                'code' => $attribute->code,
                'label' => $attribute->label_es,
                'value' => (string) $attribute->pivot->value,
            ])
            ->values()
            ->all();
    }

    public function isInStockAt(Location $location): bool
    {
        $pivot = $this->locations->firstWhere('id', $location->id);

        return $pivot !== null && (int) $pivot->pivot->stock > 0;
    }

    public function hasVariableStock(): bool
    {
        if (! $this->relationLoaded('locations')) {
            $this->load('locations');
        }

        return $this->locations->pluck('pivot.stock')->unique()->count() > 1;
    }
}
