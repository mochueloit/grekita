<?php

namespace App\Console\Commands;

use App\Models\VariationImport;
use Illuminate\Console\Command;

class VariationImportStatusCommand extends Command
{
    protected $signature = 'variations:import-status {id : ID de la importación}';

    protected $description = 'Muestra el estado y log de una importación de variaciones';

    public function handle(): int
    {
        $import = VariationImport::find($this->argument('id'));

        if (!$import) {
            $this->error('Importación no encontrada.');
            return self::FAILURE;
        }

        $this->table(
            ['Campo', 'Valor'],
            [
                ['ID',                  $import->id],
                ['Archivo',             $import->original_filename],
                ['Estado',              $import->statusLabel()],
                ['Progreso',            ($import->progressPercent() ?? 0) . '%'],
                ['Filas totales',       $import->total_rows],
                ['Filas procesadas',    $import->processed_rows],
                ['Exitosos',            $import->successful_products],
                ['Fallidos',            $import->failed_products],
                ['Errores validación',  $import->skipped_products],
                ['Reporte errores',     $import->error_report_path ?? 'Sin errores'],
                ['Inicio',              $import->started_at?->format('Y-m-d H:i:s') ?? '-'],
                ['Fin',                 $import->completed_at?->format('Y-m-d H:i:s') ?? '-'],
            ]
        );

        if (!empty($import->log_entries)) {
            $this->line('');
            $this->info('--- LOG ---');
            foreach ($import->log_entries as $entry) {
                $icon = match ($entry['level']) {
                    'error'   => '<fg=red>✗</>',
                    'warning' => '<fg=yellow>⚠</>',
                    default   => '<fg=green>✓</>',
                };
                $this->line("{$icon} [{$entry['time']}] {$entry['message']}");
            }
        }

        return self::SUCCESS;
    }
}
