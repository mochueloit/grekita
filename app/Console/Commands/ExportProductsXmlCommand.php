<?php

namespace App\Console\Commands;

use App\Services\Export\ProductXmlExportService;
use Illuminate\Console\Command;

class ExportProductsXmlCommand extends Command
{
    protected $signature = 'products:export-xml {--trigger=cli}';

    protected $description = 'Genera el XML de productos para WP All Import (ruta interna, no pública)';

    public function handle(ProductXmlExportService $exportService): int
    {
        $this->info('Generando XML de productos…');

        $result = $exportService->generate((string) $this->option('trigger'));

        $this->table(
            ['Campo', 'Valor'],
            [
                ['Productos en XML', (string) $result['product_count']],
                ['Productos en BD', (string) ($result['database_product_count'] ?? $result['product_count'])],
                ['Generado', $result['generated_at']],
                ['Ruta relativa', $result['relative_path']],
                ['Ruta absoluta', $result['absolute_path']],
                ['Latest (FTP)', $result['latest_relative_path']],
            ],
        );

        return self::SUCCESS;
    }
}
