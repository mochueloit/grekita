<?php

namespace App\Services\Inventory;

class ProductCatalogPayload
{
    /**
     * Campos de producto para create/update sin pisar con nulls vacíos.
     *
     * @param  array<string, mixed>  $catalog  Datos parseados de fila Puerto Ordaz
     * @return array<string, mixed>
     */
    public static function productAttributes(array $catalog, string $sku): array
    {
        $payload = [
            'name' => $catalog['name'] ?? $sku,
            'brand' => $catalog['brand'] ?? null,
            'price' => $catalog['price'] ?? null,
            'price_foreign' => $catalog['price_foreign'] ?? null,
            'price_currency' => $catalog['price_currency'] ?? null,
            'warranty' => $catalog['warranty'] ?? null,
            'short_description' => $catalog['short_description'] ?? null,
            'long_description' => $catalog['long_description'] ?? null,
            'long_description_html' => $catalog['long_description_html'] ?? null,
        ];

        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array{product: array<string, mixed>, attributes: array<string, string>, image_urls: list<string>, category_paths: list<string>, category_raw: ?string}
     */
    public static function metadata(array $catalog): array
    {
        return [
            'product' => self::productAttributes($catalog, (string) ($catalog['sku'] ?? '')),
            'attributes' => $catalog['attributes'] ?? [],
            'image_urls' => $catalog['image_urls'] ?? [],
            'category_paths' => $catalog['category_paths'] ?? [],
            'category_raw' => $catalog['category_raw'] ?? null,
        ];
    }
}
