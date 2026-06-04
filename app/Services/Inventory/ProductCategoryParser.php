<?php

namespace App\Services\Inventory;

class ProductCategoryParser
{
    /**
     * Varias rutas separadas por coma; cada ruta usa " > " entre niveles.
     *
     * @return list<string>
     */
    public function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $paths = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $normalized = [];

        foreach ($paths as $path) {
            $path = $this->normalizePath($path);

            if ($path !== '') {
                $normalized[] = $path;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return list<string>
     */
    public function segments(string $path): array
    {
        $parts = preg_split('/\s*>\s*/', $path) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
    }

    private function normalizePath(string $path): string
    {
        $segments = $this->segments($path);

        return implode(' > ', $segments);
    }
}
