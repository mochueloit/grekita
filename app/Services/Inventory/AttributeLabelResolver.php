<?php

namespace App\Services\Inventory;

use Illuminate\Support\Str;

class AttributeLabelResolver
{
    /**
     * @param  array<string, string>  $labels
     */
    public function __construct(
        private array $labels = [],
    ) {
        $this->labels = config('ml_attribute_labels', []);
    }

    public function resolve(string $code): string
    {
        $normalized = strtoupper(trim($code));

        if (isset($this->labels[$normalized])) {
            return $this->labels[$normalized];
        }

        return $this->humanize($normalized);
    }

    private function humanize(string $code): string
    {
        $words = explode('_', strtolower($code));
        $words = array_map(static fn (string $word): string => match ($word) {
            'gtin' => 'GTIN',
            'sku' => 'SKU',
            'uv' => 'UV',
            'led' => 'LED',
            'usb' => 'USB',
            'id' => 'ID',
            default => ucfirst($word),
        }, $words);

        return implode(' ', $words);
    }

    public function slug(string $code): string
    {
        return Str::slug(strtolower(trim($code)));
    }
}
