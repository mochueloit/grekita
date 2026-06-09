<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class WpServerCronInfoCommand extends Command
{
    protected $signature = 'inventory:wp-server-cron-info';

    protected $description = 'Muestra las líneas de cron para el servidor WordPress (tiendasgreka.com)';

    public function handle(): int
    {
        $key = (string) config('wp_all_import.import_key');
        $base = rtrim((string) config('wp_all_import.base_url'), '/');
        $import19 = (string) config('wp_all_import.import_id');
        $import20 = (string) config('wp_all_import.stock_price_import_id');
        $minutes = (int) config('wp_all_import.server_cron_interval_minutes', 5);

        $this->components->info('Cron en el servidor de WordPress (tiendasgreka.com)');
        $this->newLine();
        $this->line('  Grekita solo hace <comment>trigger</comment> al terminar el XML.');
        $this->line('  El <comment>processing</comment> cada '.$minutes.' min lo ejecuta ESTE cron en el servidor WP.');
        $this->newLine();

        $this->components->warn('Añadir en crontab del servidor WordPress (crontab -e):');
        $this->newLine();

        $this->line('  # Catálogo completo — WP All Import #'.$import19);
        $this->line($this->wgetLine($base, $key, $import19, $minutes));
        $this->newLine();

        if ($import20 !== '') {
            $this->line('  # Stock/precio — WP All Import #'.$import20);
            $this->line($this->wgetLine($base, $key, $import20, $minutes));
            $this->newLine();
        }

        $this->components->info('Opcional (mejor si WordPress está en el mismo servidor):');
        $this->line('  Sustituye la URL por http://127.0.0.1/wp-load.php?... para evitar Cloudflare.');
        $this->newLine();

        $this->components->info('Grekita (pote) — scheduler ya incluye:');
        $this->line('  inventory:wp-sync-poll cada '.config('wp_all_import.status_poll_interval_minutes', 30).' min');
        $this->line('  (requiere schedule:run cada minuto en el servidor Grekita)');

        return self::SUCCESS;
    }

    private function wgetLine(string $base, string $key, string $importId, int $minutes): string
    {
        $cron = $minutes >= 60
            ? '0 * * * *'
            : '*/'.$minutes.' * * * *';

        $url = $base.'?import_key='.$key.'&import_id='.$importId.'&action=processing';

        return '  '.$cron.' wget -q -O /dev/null -t 1 --timeout=120 "'.$url.'&rand=$RANDOM"';
    }
}
