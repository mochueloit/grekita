<?php

namespace App\Services\Inventory;

class InventoryHeaderResolver
{
    /** @var array<string, int> */
    private array $indexByNormalized = [];

    /** @var array<string, list<string>> */
    private const CANONICAL_ALIASES = [
        'sku' => ['sku', 'codigo', 'código'],
        'titulo' => ['titulo', 'título', 'title', 'nombre'],
        'cuenta_ml' => ['cuenta ml', 'cuenta mercadolibre', 'cuenta'],
        'descripcion' => ['descripcion', 'descripción', 'description'],
        'asociaciones' => ['asociaciones inventario', 'asociaciones'],
        'cantidad' => ['cantidad', 'qty', 'stock'],
        'atributos' => ['atributos', 'attributes'],
        'imagenes' => ['imágenes', 'imagenes', 'images', 'fotos'],
        'precio' => ['precio', 'price', 'precio local', 'precio venta'],
        'precio_divisas' => [
            'precio en divisa',
            'precio en divisas',
            'precio en divisa extranjera',
            'precio divisas',
            'precio divisa',
            'precio en moneda extranjera',
            'precio usd',
            'precio en usd',
        ],
        'divisa' => ['divisa', 'moneda', 'currency', 'codigo divisa', 'código divisa'],
        'garantia' => ['garantia', 'garantía', 'warranty'],
        'categoria' => ['categoria', 'categoría', 'category', 'categorias', 'categorías'],
    ];

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
    }

    /**
     * @param  array<int, string|null>  $headerMap  label => index (desde import guardado)
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
     * @param  array<int, string|null>  $row
     */
    public function value(array $row, string $canonicalKey): ?string
    {
        foreach (self::CANONICAL_ALIASES[$canonicalKey] ?? [] as $alias) {
            $index = $this->indexByNormalized[$this->normalize($alias)] ?? null;

            if ($index !== null) {
                return $this->cellValue($row, $index);
            }
        }

        return $this->valueByFuzzy($row, $canonicalKey);
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function valueByFuzzy(array $row, string $canonicalKey): ?string
    {
        foreach ($this->indexByNormalized as $headerNorm => $index) {
            $match = match ($canonicalKey) {
                'precio_divisas' => str_contains($headerNorm, 'precio')
                    && (str_contains($headerNorm, 'divis') || str_contains($headerNorm, 'usd') || str_contains($headerNorm, 'moneda extranjera')),
                'precio' => $headerNorm === 'precio'
                    || (str_contains($headerNorm, 'precio') && ! str_contains($headerNorm, 'divis') && ! str_contains($headerNorm, 'usd')),
                'divisa' => str_contains($headerNorm, 'divisa') || $headerNorm === 'moneda',
                'garantia' => str_contains($headerNorm, 'garant'),
                'categoria' => str_contains($headerNorm, 'categor'),
                default => false,
            };

            if ($match) {
                return $this->cellValue($row, $index);
            }
        }

        return null;
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
