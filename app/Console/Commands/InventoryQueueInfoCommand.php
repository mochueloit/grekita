<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class InventoryQueueInfoCommand extends Command
{
    protected $signature = 'inventory:queue-info';

    protected $description = 'Muestra colas, jobs y la línea de cron para producción';

    public function handle(): int
    {
        $queues = implode(',', config('inventory.worker_queues', ['default', 'images']));
        $projectPath = base_path();

        $this->components->info('Grekita — colas y cron de producción');
        $this->newLine();

        $this->line('  <fg=cyan>QUEUE_CONNECTION</>     '.config('queue.default'));
        $this->line('  <fg=cyan>Colas del worker</>     '.$queues);
        $this->line('  <fg=cyan>Cola de imágenes</>    '.config('inventory.image_queue', 'images'));
        $this->newLine();

        $this->components->twoColumnDetail('Jobs en cola <comment>default</comment>', 'Importación CSV/Excel');
        $this->line('    • ProcessInventoryImportJob — prepara archivo y encadena lotes');
        $this->line('    • ProcessInventoryImportChunkJob — fase 1 (PO catálogo) luego fase 2 (stock otras sedes), ~'.config('inventory.rows_per_job', 25).' filas/job');
        $this->newLine();

        $this->components->twoColumnDetail('Jobs en cola <comment>images</comment>', 'Descarga de imágenes (lenta)');
        $this->line('    • DownloadProductImageJob — 1 imagen por job, delay ~'.config('inventory.image_download_delay_seconds', 3).'s');
        $this->newLine();

        $this->components->warn('Cron cPanel / producción — UNA sola línea (cada minuto):');
        $this->line('  * * * * * cd '.$projectPath.' && /usr/local/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1');
        $this->newLine();
        $this->line('  <fg=gray>Reemplaza /usr/local/bin/php por la ruta real (Terminal: which php).</>');
        $this->line('  <fg=red>NO</> agregues un segundo cron con queue:listen — en cPanel no funciona.');
        $this->newLine();

        $this->components->info('Alternativa directa (sin schedule:run, también una sola línea):');
        $workerCommand = sprintf(
            'queue:work --queue=%s --stop-when-empty --max-time=%d --sleep=%d --tries=%d --timeout=%d',
            $queues,
            config('inventory.worker_max_time', 55),
            config('inventory.worker_sleep', 1),
            config('inventory.worker_tries', 3),
            config('inventory.worker_timeout', 120),
        );
        $this->line('  * * * * * cd '.$projectPath.' && /usr/local/bin/php artisan '.$workerCommand.' >> storage/logs/queue-cron.log 2>&1');
        $this->newLine();

        $this->components->info('Comando que corre el scheduler (referencia):');
        $this->line('  php artisan '.$workerCommand);
        $this->newLine();

        $pendingJobs = \Illuminate\Support\Facades\DB::table('jobs')->count();
        $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
        $this->line('  Jobs pendientes en BD: <fg=yellow>'.$pendingJobs.'</>');
        $this->line('  Jobs fallidos:         <fg=yellow>'.$failedJobs.'</>');
        if (config('queue.default') === 'sync') {
            $this->components->error('QUEUE_CONNECTION=sync — los jobs NO van a cola. Pon QUEUE_CONNECTION=database en .env');
        }
        $this->newLine();

        $this->components->info('Desarrollo local (terminal abierta):');
        $this->line('  php artisan queue:listen --queue='.$queues.' --tries=3 --timeout=120');
        $this->newLine();

        $this->line('  Ver jobs pendientes:  <fg=gray>php artisan queue:monitor '.$queues.'</>');
        $this->line('  Ver jobs fallidos:    <fg=gray>php artisan queue:failed</>');
        $this->line('  Reintentar fallidos:  <fg=gray>php artisan queue:retry all</>');

        return self::SUCCESS;
    }
}
