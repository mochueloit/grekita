<?php

namespace App\Services\Inventory;

class InventoryHeaderResolver
{
    /** @var array<string, int> normalized header label => column index */
    private array $indexByNormalized = [];

    /** @var array<string, int> canonical field => column index */
    private array $canonicalIndex = [];

    /**
     * @param  array<int, string|null>  $headerRow
     */
    public function __construct(array $headerRow)
    {
        foreach ($headerRow as $index => $column) {
            if ($column === null) {
                continue;
            }

            $label = trim((string) $column);

            if ($label === '') {
                continue;
            }

            $this->indexByNormalized[$this->normalize($label)] = (int) $index;
        }

        $this->resolveCanonicalIndices();
    }

    /**
     * @param  array<string, int>  $headerMap  Etiqueta original => índice
     */
    public static function fromHeaderMap(array $headerMap): self
    {
        $headerRow = [];

        foreach ($headerMap as $label => $index) {
            $headerRow[(int) $index] = $label;
        }

        ksort($headerRow);

        return new self(array_values($headerRow));
    }

    /**
     * @return list<string>
     */
    public function detectedHeaders(): array
    {
        return array_keys($this->indexByNormalized);
    }

    /**
     * @param  array<int, string|null>  $row
     */
    public function value(array $row, string $canonicalKey): ?string
    {
        if (isset($this->canonicalIndex[$canonicalKey])) {
            return $this->cellValue($row, $this->canonicalIndex[$canonicalKey]);
        }

        foreach ($this->aliasesFor($canonicalKey) as $alias) {
            $index = $this->indexByNormalized[$this->normalize($alias)] ?? null;

            if ($index !== null) {
                return $this->cellValue($row, $index);
            }
        }

        return null;
    }

    public function has(string $canonicalKey): bool
    {
        return isset($this->canonicalIndex[$canonicalKey]);
    }

    private function resolveCanonicalIndices(): void
    {
        foreach ($this->indexByNormalized as $headerNorm => $index) {
            $this->assignCanonical('precio_divisas', $headerNorm, $index, fn (string $h): bool => str_contains($h, 'precio')
                && (str_contains($h, 'divis') || str_contains($h, 'usd') || str_contains($h, 'moneda extranjera')));

            $this->assignCanonical('precio', $headerNorm, $index, fn (string $h): bool => $h === 'precio'
                || (str_contains($h, 'precio') && ! str_contains($h, 'divis') && ! str_contains($h, 'usd') && ! str_contains($h, 'moneda extranjera')));

            $this->assignCanonical('divisa', $headerNorm, $index, fn (string $h): bool => $h === 'divisa'
                || $h === 'moneda'
                || str_contains($h, 'codigo divisa'));

            $this->assignCanonical('garantia', $headerNorm, $index, fn (string $h): bool => str_contains($h, 'garant'));

            $this->assignCanonical('categoria', $headerNorm, $index, fn (string $h): bool => str_contains($h, 'categor'));

            $this->assignCanonical('sku', $headerNorm, $index, fn (string $h): bool => $h === 'sku' || $h === 'codigo');

            $this->assignCanonical('titulo', $headerNorm, $index, fn (string $h): bool => $h === 'titulo' || $h === 'título' || $h === 'title');

            $this->assignCanonical('cuenta_ml', $headerNorm, $index, fn (string $h): bool => str_contains($h, 'cuenta') && str_contains($h, 'ml'));

            $this->assignCanonical('descripcion', $headerNorm, $index, fn (string $h): bool => str_contains($h, 'descripcion') || str_contains($h, 'description'));

            $this->assignCanonical('asociaciones', $headerNorm, $index, fn (string $h): bool => str_contains($h, 'asociaciones'));

            $this->assignCanonical('cantidad', $headerNorm, $index, fn (string $h): bool => $h === 'cantidad' || $h === 'qty');

            $this->assignCanonical('atributos', $headerNorm, $index, fn (string $h): bool => str_contains($h, 'atributos'));

            $this->assignCanonical('imagenes', $headerNorm, $index, fn (string $h): bool => str_contains($h, 'imagen') || str_contains($h, 'fotos'));
        }
    }

    private function assignCanonical(string $canonical, string $headerNorm, int $index, callable $matcher): void
    {
        if (isset($this->canonicalIndex[$canonical])) {
            return;
        }

        if ($matcher($headerNorm)) {
            $this->canonicalIndex[$canonical] = $index;
        }
    }

    /**
     * @return list<string>
     */
    private function aliasesFor(string $canonicalKey): array
    {
        return match ($canonicalKey) {
            'sku' => ['sku', 'codigo', 'código'],
            'titulo' => ['titulo', 'título', 'title', 'nombre'],
            'cuenta_ml' => ['cuenta ml', 'cuenta mercadolibre'],
            'descripcion' => ['descripcion', 'descripción', 'description'],
            'asociaciones' => ['asociaciones inventario', 'asociaciones'],
            'cantidad' => ['cantidad', 'qty', 'stock'],
            'atributos' => ['atributos', 'attributes'],
            'imagenes' => ['imágenes', 'imagenes', 'images', 'fotos'],
            'precio' => ['precio', 'price'],
            'precio_divisas' => [
                'precio en divisa',
                'precio en divisas',
                'precio divisas',
                'precio divisa',
            ],
            'divisa' => ['divisa', 'moneda', 'currency'],
            'garantia' => ['garantia', 'garantía', 'warranty'],
            'categoria' => ['categoria', 'categoría', 'category'],
            default => [],
        };
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function cellValue(array $row, int $index): ?string
    {
        if (! isset($row[$index])) {
            return null;
        }

        $value = trim((string) $row[$index]);

        return $value !== '' ? $value : null;
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü', 'à', 'è', 'ì', 'ò', 'ù'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'u', 'a', 'e', 'i', 'o', 'u'],
            $value,
        );
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }
}
