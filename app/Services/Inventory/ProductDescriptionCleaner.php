<?php

namespace App\Services\Inventory;

class ProductDescriptionCleaner
{
    /**
     * Replica la lógica de Google Sheets:
     * ESPACIOS(REGEXREPLACE(K; "(?s)\*.*"; ""))
     */
    public function clean(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $cleaned = preg_replace('/(?s)\*.*$/', '', $text) ?? '';
        $cleaned = trim($cleaned);

        if ($cleaned === '' && str_contains($text, '*')) {
            $cleaned = preg_replace('/^\*+/m', '', $text) ?? '';
            $cleaned = preg_replace('/(?s)\n?\*{5,}[\s\S]*$/', '', $cleaned) ?? '';
            $cleaned = trim(preg_replace('/\*+/m', '', $cleaned) ?? '');
        }

        $cleaned = preg_replace('/(?s)\n?\*{5,}\s*UBICACIÓN[\s\S]*$/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/(?s)\nNOTA:\s*Para compras mayores[\s\S]*$/iu', '', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }

    public function toShort(string $cleaned, int $limit = 255): ?string
    {
        if ($cleaned === '') {
            return null;
        }

        $singleLine = trim(preg_replace('/\s+/', ' ', $cleaned) ?? '');

        if ($singleLine === '') {
            return null;
        }

        return mb_strlen($singleLine) > $limit
            ? mb_substr($singleLine, 0, $limit - 3).'...'
            : $singleLine;
    }
}
