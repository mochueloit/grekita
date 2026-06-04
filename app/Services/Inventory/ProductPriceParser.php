<?php

namespace App\Services\Inventory;

class ProductPriceParser
{
    public function parse(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $normalized = preg_replace('/[^\d.,\-]/', '', $value) ?? '';

        if ($normalized === '' || $normalized === '-') {
            return null;
        }

        if (preg_match('/^[A-Za-z]{3}$/i', $value) === 1 && ! preg_match('/\d/', $normalized)) {
            return null;
        }

        $hasComma = str_contains($normalized, ',');
        $hasDot = str_contains($normalized, '.');

        if ($hasComma && $hasDot) {
            if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($hasComma) {
            $normalized = str_replace(',', '.', $normalized);
        }

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    public function parseCurrency(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(trim($value));

        return $value !== '' ? $value : null;
    }
}
