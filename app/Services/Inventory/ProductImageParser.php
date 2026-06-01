<?php

namespace App\Services\Inventory;

class ProductImageParser
{
    /**
     * @return list<string>
     */
    public function parse(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $urls = preg_split('/\s*;\s*/', $raw) ?: [];

        return array_values(array_unique(array_filter(array_map('trim', $urls), static fn (string $url): bool => str_starts_with($url, 'http'))));
    }
}
