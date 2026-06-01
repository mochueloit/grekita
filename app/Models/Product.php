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
        'short_description',
        'long_description',
        'long_description_html',
    ];

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
