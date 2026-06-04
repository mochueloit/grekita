<?php

namespace App\Services\Inventory;

use App\Models\Location;

class InventoryProductRowParser
{
    public function __construct(
        private readonly InventoryHeaderResolver $headers,
        private readonly LocationResolver $locationResolver,
        private readonly InventoryAssociationParser $associationParser,
        private readonly ProductAttributeParser $attributeParser,
        private readonly ProductDescriptionCleaner $descriptionCleaner,
        private readonly ProductDescriptionFormatter $descriptionFormatter,
        private readonly BrandExtractor $brandExtractor,
        private readonly ProductImageParser $imageParser,
        private readonly ProductPriceParser $priceParser,
        private readonly ProductCategoryParser $categoryParser,
    ) {}

    /**
     * @param  array<int, string|null>  $row
     * @return array<string, mixed>|null
     */
    public function parse(array $row): ?array
    {
        $sku = trim($this->headers->value($row, 'sku') ?? '');

        if ($sku === '') {
            return null;
        }

        $cuentaMl = trim($this->headers->value($row, 'cuenta_ml') ?? '');

        if ($cuentaMl === '') {
            return null;
        }

        $location = $this->locationResolver->resolveFromCuentaMl($cuentaMl);
        $isPrimaryCatalog = $location->slug === LocationResolver::PRIMARY_LOCATION_SLUG;

        $base = [
            'sku' => $sku,
            'cuenta_ml' => $cuentaMl,
            'location_slug' => $location->slug,
            'is_primary_catalog' => $isPrimaryCatalog,
            'stock' => $this->parseStock($row),
        ];

        if (! $isPrimaryCatalog) {
            return $base;
        }

        return array_merge($base, $this->parseCatalogFields($row));
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function parseStock(array $row): int
    {
        $associations = trim($this->headers->value($row, 'asociaciones') ?? '');
        $quantity = (int) ($this->headers->value($row, 'cantidad') ?? 0);

        return $this->associationParser->parseStock($associations, $quantity);
    }

    /**
     * Solo filas de Puerto Ordaz (catálogo maestro).
     *
     * @param  array<int, string|null>  $row
     * @return array<string, mixed>
     */
    private function parseCatalogFields(array $row): array
    {
        $title = trim($this->headers->value($row, 'titulo') ?? '');
        $description = trim($this->headers->value($row, 'descripcion') ?? '');
        $rawAttributes = $this->headers->value($row, 'atributos') ?? '';
        $attributes = $this->attributeParser->parse($rawAttributes);
        $cleanDescription = $this->descriptionCleaner->clean($description);
        $categoryRaw = $this->headers->value($row, 'categoria');

        $foreignRaw = $this->headers->value($row, 'precio_divisas');
        $priceForeign = $this->priceParser->parse($foreignRaw);
        $price = $this->priceParser->parse($this->headers->value($row, 'precio'));
        $priceCurrency = $this->priceParser->parseCurrency($this->headers->value($row, 'divisa'));

        if ($priceCurrency === null) {
            $priceCurrency = $this->inferCurrencyCode($foreignRaw);
        }

        return [
            'name' => $title !== '' ? $title : null,
            'brand' => $this->brandExtractor->extract($attributes, $rawAttributes) ?: null,
            'price' => $price,
            'price_foreign' => $priceForeign,
            'price_currency' => $priceCurrency,
            'warranty' => trim($this->headers->value($row, 'garantia') ?? '') ?: null,
            'category_paths' => $this->categoryParser->parse($categoryRaw),
            'category_raw' => $categoryRaw,
            'short_description' => $this->descriptionCleaner->toShort($cleanDescription) ?: null,
            'long_description' => $cleanDescription !== '' ? $cleanDescription : null,
            'long_description_html' => $cleanDescription !== '' ? $this->descriptionFormatter->toHtml($cleanDescription) : null,
            'attributes' => $attributes,
            'image_urls' => $this->imageParser->parse($this->headers->value($row, 'imagenes') ?? ''),
        ];
    }

    private function inferCurrencyCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if (preg_match('/^[A-Za-z]{3}$/', $trimmed) === 1) {
            return strtoupper($trimmed);
        }

        return null;
    }
}
