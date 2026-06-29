<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Http\UploadedFile;

class ImportHeaderValidator
{
    /**
     * Required canonical headers per import type.
     * Keys are normalized (lowercase, no accents, trimmed).
     */
    private const REQUIRED = [
        'full' => [
            'sku'       => ['sku', 'codigo', 'código'],
            'titulo'    => ['titulo', 'título', 'title', 'nombre'],
            'cuenta_ml' => ['cuenta ml', 'cuenta mercadolibre'],
        ],
        'stock_price' => [
            'sku'      => ['sku', 'codigo', 'código'],
            'precio'   => ['precio', 'price'],
            'cantidad' => ['cantidad', 'qty', 'stock'],
        ],
        'exclusive' => [
            'sku'       => ['sku', 'codigo', 'código'],
            'cuenta_ml' => ['cuenta ml', 'cuenta mercadolibre'],
        ],
        'variations' => [
            'sku_padre'     => ['sku publicacion', 'sku publicación'],
            'sku_variacion' => ['sku variacion', 'sku variación'],
            'variacion'     => ['variacion', 'variación'],
            'cuenta_ml'     => ['cuenta ml'],
        ],
    ];

    /**
     * Validate that the uploaded file contains the required headers for the given import type.
     * Returns an error message string, or null if valid.
     */
    public function validate(UploadedFile $file, string $importType): ?string
    {
        $required = self::REQUIRED[$importType] ?? null;

        if ($required === null) {
            return null;
        }

        try {
            $headers = $this->readFirstRow($file);
        } catch (\Throwable) {
            return 'No se pudo leer el archivo. Verifica que sea un Excel o CSV válido.';
        }

        if (empty($headers)) {
            return 'El archivo está vacío o no tiene encabezados.';
        }

        $normalized = array_map(fn ($h) => $this->normalize((string) $h), $headers);
        $missing    = [];

        foreach ($required as $field => $aliases) {
            $found = false;
            foreach ($aliases as $alias) {
                if (in_array($this->normalize($alias), $normalized, true)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $aliases[0]; // show the first alias as expected name
            }
        }

        if (!empty($missing)) {
            return 'El archivo no corresponde a este módulo. Columnas esperadas no encontradas: ' . implode(', ', $missing) . '.';
        }

        return null;
    }

    private function readFirstRow(UploadedFile $file): array
    {
        $ext     = strtolower($file->getClientOriginalExtension());
        $content = $file->getContent();

        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $tmp = tempnam(sys_get_temp_dir(), 'grk_import_');
            file_put_contents($tmp, $content);

            try {
                $spreadsheet = IOFactory::load($tmp);
                $row = $spreadsheet->getActiveSheet()->toArray(null, true, true, false)[0] ?? [];
            } finally {
                @unlink($tmp);
            }

            return array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== '');
        }

        // CSV / TXT
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $row = fgetcsv($handle) ?: [];
        fclose($handle);

        return array_filter($row, fn ($v) => trim((string) $v) !== '');
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
            $value
        );
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
