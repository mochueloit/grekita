<?php

namespace App\Services\Export;

use App\Models\Product;

class ProductXmlFlatFields
{
    private const DIMENSION_CODES = [
        'width' => ['WIDTH', 'TOTAL_WIDTH'],
        'height' => ['HEIGHT', 'TOTAL_HEIGHT'],
        'length' => ['LENGTH', 'DEPTH'],
        'weight' => ['WEIGHT'],
    ];

    /** @var list<string> */
    private const EXCLUDED_FROM_ATTRIBUTES_XML = [
        'WIDTH',
        'TOTAL_WIDTH',
        'HEIGHT',
        'TOTAL_HEIGHT',
        'LENGTH',
        'DEPTH',
        'WEIGHT',
        'BRAND',
    ];

    public function categoriesTextFromProduct(Product $product): string
    {
        if (! $product->relationLoaded('categories')) {
            $product->load('categories');
        }

        $paths = $product->categories
            ->sortBy(fn ($category) => (int) ($category->pivot->sort_order ?? 0))
            ->map(static function ($category): string {
                $path = trim((string) ($category->full_path ?? ''));

                if ($path !== '') {
                    return $path;
                }

                return trim((string) ($category->name ?? ''));
            })
            ->filter(static fn (string $path): bool => $path !== '')
            ->unique()
            ->values()
            ->all();

        return $paths === [] ? '0' : implode(', ', $paths);
    }

    /**
     * @return list<string>
     */
    public function localImageUrls(Product $product): array
    {
        if (! $product->relationLoaded('images')) {
            $product->load('images');
        }

        return $product->images
            ->sortBy(fn ($image) => (int) ($image->sort_order ?? 0))
            ->map(static fn ($image) => $image->storedPublicUrl())
            ->filter(static fn (?string $url): bool => $url !== null && $url !== '')
            ->values()
            ->all();
    }

    public function localImageUrlsText(Product $product): string
    {
        $urls = $this->localImageUrls($product);

        return $urls === [] ? '0' : implode(', ', $urls);
    }

    /**
     * @param  list<array{code: string, label: string, value: string}>  $attributes
     * @return array{width: string, height: string, length: string, weight: string}
     */
    public function dimensions(array $attributes): array
    {
        $byCode = $this->indexAttributesByCode($attributes);

        return [
            'width' => $this->firstMatchingValue($byCode, self::DIMENSION_CODES['width']) ?? '0',
            'height' => $this->firstMatchingValue($byCode, self::DIMENSION_CODES['height']) ?? '0',
            'length' => $this->firstMatchingValue($byCode, self::DIMENSION_CODES['length']) ?? '0',
            'weight' => $this->firstMatchingValue($byCode, self::DIMENSION_CODES['weight']) ?? '0',
        ];
    }

    /**
     * @param  list<array{code: string, label: string, value: string}>  $attributes
     */
    public function brandValue(Product $product, array $attributes): string
    {
        $brand = trim((string) ($product->brand ?? ''));

        if ($brand !== '') {
            return $brand;
        }

        $byCode = $this->indexAttributesByCode($attributes);
        $fromAttribute = trim((string) ($byCode['BRAND'] ?? ''));

        return $fromAttribute !== '' ? $fromAttribute : '0';
    }

    /**
     * @param  list<array{code: string, label: string, value: string}>  $attributes
     * @return list<array{code: string, label: string, value: string}>
     */
    public function attributesForXml(array $attributes): array
    {
        return array_values(array_filter(
            $attributes,
            fn (array $attribute): bool => ! in_array(
                strtoupper((string) ($attribute['code'] ?? '')),
                self::EXCLUDED_FROM_ATTRIBUTES_XML,
                true,
            ),
        ));
    }

    /**
     * @param  list<array{code: string, label: string, value: string}>  $attributes
     * @return array<string, string>
     */
    private function indexAttributesByCode(array $attributes): array
    {
        $indexed = [];

        foreach ($attributes as $attribute) {
            $code = strtoupper(trim((string) ($attribute['code'] ?? '')));
            $value = trim((string) ($attribute['value'] ?? ''));

            if ($code === '' || $value === '') {
                continue;
            }

            $indexed[$code] = $value;
        }

        return $indexed;
    }

    /**
     * @param  array<string, string>  $byCode
     * @param  list<string>  $codes
     */
    private function firstMatchingValue(array $byCode, array $codes): ?string
    {
        foreach ($codes as $code) {
            $value = $byCode[strtoupper($code)] ?? null;

            if ($value === null || $this->isEmptyDimensionValue($value)) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function isEmptyDimensionValue(string $value): bool
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');

        return $normalized === ''
            || $normalized === 'no aplica'
            || $normalized === 'n/a'
            || $normalized === '-';
    }
}
