<?php

namespace App\Services\WooCommerce;

use Illuminate\Support\Str;

class VariationExcelValidator
{
    // Columnas esperadas en el Excel
    private const COL_ID_ML         = 'Id ML';
    private const COL_SKU_PADRE     = 'SKU Publicación';
    private const COL_VARIACION     = 'Variación';
    private const COL_SKU_VARIACION = 'SKU Variación';
    private const COL_CANTIDAD      = 'Cantidad';
    private const COL_CUENTA_ML     = 'Cuenta ML';
    // private const COL_PRECIO     = 'Precio Variación'; // Ignorado por ahora — precio se copia del padre

    /**
     * Procesa las filas del Excel y devuelve:
     * - groups: productos agrupados por SKU padre listos para convertir
     * - errors: filas con problemas para el reporte de errores
     */
    public function validate(array $rows): array
    {
        $groups = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $lineNumber   = $index + 2; // +2 porque la fila 1 es header
            $skuPadre     = trim((string) ($row[self::COL_SKU_PADRE] ?? ''));
            $skuVariacion = trim((string) ($row[self::COL_SKU_VARIACION] ?? ''));
            $variacion    = trim((string) ($row[self::COL_VARIACION] ?? ''));
            $cantidad     = (int) ($row[self::COL_CANTIDAD] ?? 0);
            $cuentaML     = trim((string) ($row[self::COL_CUENTA_ML] ?? ''));

            // Padre vacío → saltar fila
            if ($skuPadre === '') {
                continue;
            }

            // SKU Variación inválido
            $skuError = $this->validateSkuVariacion($skuPadre, $skuVariacion);
            if ($skuError !== null) {
                $errors[] = $this->buildError($lineNumber, $row, $skuError);
                continue;
            }

            // Atributos inválidos o redundantes
            $atributosResult = $this->parseAndValidateAtributos($variacion);
            if (!$atributosResult['valid']) {
                $errors[] = $this->buildError($lineNumber, $row, $atributosResult['error']);
                continue;
            }

            // Extraer ID de sede desde Cuenta ML
            $sedeId = $this->extractSedeId($cuentaML);
            if ($sedeId === null) {
                $errors[] = $this->buildError($lineNumber, $row, "No se pudo extraer ID de sede de: {$cuentaML}");
                continue;
            }

            // Agrupar por SKU padre → SKU variación
            $groups[$skuPadre][$skuVariacion]['atributos'] = $atributosResult['atributos'];
            $groups[$skuPadre][$skuVariacion]['stock_sedes'][$sedeId] = $cantidad;
        }

        return [
            'groups' => $groups,
            'errors' => $errors,
        ];
    }

    /**
     * Valida que el SKU de variación sea formato SKUPadre+Letra (ej: 2295A).
     * Devuelve null si es válido, o string con el error.
     */
    private function validateSkuVariacion(string $skuPadre, string $skuVariacion): ?string
    {
        if ($skuVariacion === '') {
            return 'SKU Variación vacío.';
        }

        if ($skuVariacion === $skuPadre) {
            return "SKU Variación igual al padre ({$skuVariacion}).";
        }

        // Debe ser skuPadre seguido de una o más letras
        $pattern = '/^' . preg_quote($skuPadre, '/') . '[A-Za-z]+$/';
        if (!preg_match($pattern, $skuVariacion)) {
            return "SKU Variación '{$skuVariacion}' no sigue el formato SKUPadre+Letra.";
        }

        return null;
    }

    /**
     * Parsea y valida el campo Variación.
     * Formato esperado: Atributo:Valor o Atributo:Valor;Atributo2:Valor2
     * Devuelve atributos normalizados o error.
     */
    private function parseAndValidateAtributos(string $variacion): array
    {
        if ($variacion === '') {
            return ['valid' => false, 'error' => 'Campo Variación vacío.', 'atributos' => []];
        }

        $pares     = explode(';', $variacion);
        $atributos = [];

        foreach ($pares as $par) {
            $partes = explode(':', $par, 2);

            if (count($partes) !== 2) {
                return [
                    'valid'     => false,
                    'error'     => "Formato inválido en variación: '{$par}'. Se esperaba Atributo:Valor.",
                    'atributos' => [],
                ];
            }

            $nombre = trim($partes[0]);
            $valor  = trim($partes[1]);

            if ($nombre === '' || $valor === '') {
                return [
                    'valid'     => false,
                    'error'     => "Nombre o valor vacío en variación: '{$par}'.",
                    'atributos' => [],
                ];
            }

            // Detectar redundancia: TALLA:TALLA XL → el valor repite el nombre
            $nombreLower = strtolower($nombre);
            $valorLower  = strtolower($valor);
            if (Str::startsWith($valorLower, $nombreLower)) {
                return [
                    'valid'     => false,
                    'error'     => "Valor redundante en variación: '{$par}'. El valor repite el nombre del atributo.",
                    'atributos' => [],
                ];
            }

            // Normalizar: primera letra mayúscula en nombre, valor como viene
            $atributos[] = [
                'nombre' => ucfirst(strtolower($nombre)),
                'valor'  => $valor,
            ];
        }

        return ['valid' => true, 'error' => null, 'atributos' => $atributos];
    }

    /**
     * Extrae el ID de sede del campo Cuenta ML.
     * Ejemplo: "TIENDASGREKALECHERIA (482845934)" → "482845934"
     */
    private function extractSedeId(string $cuentaML): ?string
    {
        if (preg_match('/\((\d+)\)/', $cuentaML, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function buildError(int $line, array $row, string $reason): array
    {
        return [
            'linea'          => $line,
            'sku_padre'      => $row[self::COL_SKU_PADRE] ?? '',
            'sku_variacion'  => $row[self::COL_SKU_VARIACION] ?? '',
            'variacion'      => $row[self::COL_VARIACION] ?? '',
            'cuenta_ml'      => $row[self::COL_CUENTA_ML] ?? '',
            'motivo'         => $reason,
        ];
    }
}
