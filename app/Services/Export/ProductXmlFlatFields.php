<?php

namespace App\Services\Export;

class ProductXmlFlatFields
{
    private const DIMENSION_CODES = [
        'width' => ['WIDTH', 'TOTAL_WIDTH'],
        'height' => ['HEIGHT', 'TOTAL_HEIGHT'],
        'weight' => ['WEIGHT'],
    ];

    /** @var list<string> */
    private const EXCLUDED_FROM_ATTRIBUTES_XML = [
        'WIDTH',
        'TOTAL_WIDTH',
        'HEIGHT',
        'TOTAL_HEIGHT',
        'WEIGHT',
    ];

    /**
     * @param  list<array{path: string, segments: list<string>, depth: int}>  $categories
     */
    public function categoriesText(array $categories): ?string
    {
        $paths = [];

        foreach ($categories as $category) {
            $path = trim((string) ($category['path'] ?? ''));

            if ($path !== '') {
                $paths[] = $path;
            }
        }

        if ($paths === []) {
            return null;
        }

        return implode(', ', $paths);
    }

    /**
     * @param  list<array{code: string, label: string, value: string}>  $attributes
     * @return array{width: ?string, height: ?string, weight: ?string}
     */
    public function dimensions(array $attributes): array
    {
        $byCode = $this->indexAttributesByCode($attributes);

        return [
            'width' => $this->firstMatchingValue($byCode, self::DIMENSION_CODES['width']),
            'height' => $this->firstMatchingValue($byCode, self::DIMENSION_CODES['height']),
            'weight' => $this->firstMatchingValue($byCode, self::DIMENSION_CODES['weight']),
        ];
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
