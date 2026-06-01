<?php

namespace App\Services\Inventory;

use App\Models\AttributeDefinition;
use App\Models\Product;

class ProductAttributeSyncService
{
    public function __construct(
        private readonly AttributeLabelResolver $labelResolver,
    ) {}

    /**
     * @param  array<string, string>  $attributes
     */
    public function sync(Product $product, array $attributes): int
    {
        if ($attributes === []) {
            $product->attributeDefinitions()->detach();

            return 0;
        }

        $syncData = [];

        foreach ($attributes as $code => $value) {
            $code = trim((string) $code);
            $value = trim((string) $value);

            if ($code === '' || $value === '') {
                continue;
            }

            $definition = AttributeDefinition::query()->firstOrCreate(
                ['code' => strtoupper($code)],
                [
                    'label_es' => $this->labelResolver->resolve($code),
                    'slug' => $this->labelResolver->slug($code),
                ],
            );

            if ($definition->label_es !== $this->labelResolver->resolve($code)
                && isset(config('ml_attribute_labels')[strtoupper($code)])) {
                $definition->update([
                    'label_es' => $this->labelResolver->resolve($code),
                ]);
            }

            $syncData[$definition->id] = ['value' => $value];
        }

        $product->attributeDefinitions()->sync($syncData);

        return count($syncData);
    }
}
