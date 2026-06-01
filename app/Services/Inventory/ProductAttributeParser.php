<?php

namespace App\Services\Inventory;

class ProductAttributeParser
{
    /**
     * @return array<string, string>
     */
    public function parse(string $rawAttributes): array
    {
        if (trim($rawAttributes) === '') {
            return [];
        }

        $attributes = [];

        foreach (explode(';', $rawAttributes) as $segment) {
            $segment = trim($segment);

            if ($segment === '' || ! str_contains($segment, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $segment, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key !== '') {
                $attributes[$key] = $value;
            }
        }

        return $attributes;
    }
}
