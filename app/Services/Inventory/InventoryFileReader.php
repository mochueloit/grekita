<?php

namespace App\Services\Inventory;

use Generator;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SplFileObject;

class InventoryFileReader
{
    /**
     * Cuenta filas de datos (sin encabezado).
     */
    public function countDataRows(string $filePath): int
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $spreadsheet = IOFactory::load($filePath);
            $highestRow = $spreadsheet->getActiveSheet()->getHighestDataRow();

            return max(0, $highestRow - 1);
        }

        $count = 0;
        $isHeader = true;

        foreach ($this->rows($filePath) as $row) {
            if ($isHeader) {
                $isHeader = false;

                continue;
            }

            if (! $this->isEmptyRow($row)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return Generator<int, array<int, string|null>>
     */
    public function dataRows(string $filePath, int $skip = 0, ?int $limit = null): Generator
    {
        $skipped = 0;
        $yielded = 0;
        $isHeader = true;

        foreach ($this->rows($filePath) as $row) {
            if ($isHeader) {
                $isHeader = false;

                continue;
            }

            if ($this->isEmptyRow($row)) {
                continue;
            }

            if ($skipped < $skip) {
                $skipped++;

                continue;
            }

            if ($limit !== null && $yielded >= $limit) {
                break;
            }

            yield $row;
            $yielded++;
        }
    }

    /**
     * @return Generator<int, array<int, string|null>>
     */
    public function rows(string $filePath): Generator
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match (true) {
            in_array($extension, ['xlsx', 'xls'], true) => $this->rowsFromSpreadsheet($filePath),
            in_array($extension, ['csv', 'txt'], true) => $this->rowsFromCsv($filePath),
            default => throw new InvalidArgumentException('Formato no soportado. Use CSV, XLS o XLSX.'),
        };
    }

    /**
     * @return Generator<int, array<int, string|null>>
     */
    private function rowsFromCsv(string $filePath): Generator
    {
        $file = new SplFileObject($filePath, 'r');
        $file->setCsvControl(',', '"', '\\');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        while (! $file->eof()) {
            $row = $file->fgetcsv();

            if ($row === false || $row === [null]) {
                continue;
            }

            yield $this->normalizeRow($row);
        }
    }

    /**
     * @return Generator<int, array<int, string|null>>
     */
    private function rowsFromSpreadsheet(string $filePath): Generator
    {
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        foreach ($worksheet->toArray(null, true, true, false) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeRow($row);

            if ($this->isEmptyRow($normalized)) {
                continue;
            }

            yield $normalized;
        }
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<int, string|null>
     */
    private function normalizeRow(array $row): array
    {
        return array_map(function (mixed $cell): ?string {
            if ($cell === null || $cell === '') {
                return null;
            }

            if (is_string($cell)) {
                return $cell;
            }

            if (is_int($cell) || is_float($cell)) {
                if (is_float($cell) && floor($cell) === $cell) {
                    return (string) (int) $cell;
                }

                return rtrim(rtrim(sprintf('%.10F', (float) $cell), '0'), '.');
            }

            if ($cell instanceof \DateTimeInterface) {
                return $cell->format('Y-m-d H:i:s');
            }

            return (string) $cell;
        }, array_values($row));
    }

    /**
     * @param  array<int, string|null>  $row
     */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
