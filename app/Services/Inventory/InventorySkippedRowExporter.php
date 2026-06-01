<?php

namespace App\Services\Inventory;

use Symfony\Component\HttpFoundation\StreamedResponse;

class InventorySkippedRowExporter
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function writeToDisk(array $rows, string $relativePath): void
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return;
        }

        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($handle, ['Fila', 'SKU', 'Título', 'Cuenta ML', 'Código', 'Motivo'], ';');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['row'] ?? '',
                $row['sku'] ?? '',
                $row['title'] ?? '',
                $row['cuenta_ml'] ?? '',
                $row['reason_code'] ?? '',
                $row['reason'] ?? '',
            ], ';');
        }

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        \Illuminate\Support\Facades\Storage::disk('local')->put($relativePath, $content);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function toCsvResponse(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, ['Fila', 'SKU', 'Título', 'Cuenta ML', 'Código', 'Motivo'], ';');

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['row'] ?? '',
                    $row['sku'] ?? '',
                    $row['title'] ?? '',
                    $row['cuenta_ml'] ?? '',
                    $row['reason_code'] ?? '',
                    $row['reason'] ?? '',
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
