<?php

namespace App\Services\Inventory;

class BrandExtractor
{
    /**
     * Replica REGEXEXTRACT(W; "BRAND:\s*([^;]+)")
     */
    public function extract(array $attributes, string $rawAttributes = ''): ?string
    {
        foreach (['BRAND', 'Brand', 'brand', 'Marca', 'MARCA'] as $key) {
            if (! empty($attributes[$key])) {
                $brand = trim((string) $attributes[$key]);

                if ($brand !== '' && ! in_array(mb_strtolower($brand), ['no aplica', 'genérica', 'generica', 'sin marca'], true)) {
                    return $brand;
                }
            }
        }

        if ($rawAttributes !== '' && preg_match('/BRAND:\s*([^;]+)/i', $rawAttributes, $matches)) {
            $brand = trim($matches[1]);

            if ($brand !== '' && ! in_array(mb_strtolower($brand), ['no aplica', 'genérica', 'generica', 'sin marca'], true)) {
                return $brand;
            }
        }

        return null;
    }
}
