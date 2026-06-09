<?php

namespace App\Console\Commands;

use App\Services\WordPress\WpAllImportExternalSyncService;
use Illuminate\Console\Command;

class PollWpAllImportStatusCommand extends Command
{
    protected $signature = 'inventory:wp-sync-poll';

    protected $description = 'Verifica imports WP All Import activos (processing como ping; no ejecuta el cron del servidor)';

    public function handle(WpAllImportExternalSyncService $sync): int
    {
        $active = $sync->activeImports();

        if ($active->isEmpty()) {
            $this->line('Sin importaciones WP pendientes de verificación.');

            return self::SUCCESS;
        }

        foreach ($active as $import) {
            $this->line("Poll import Grekita #{$import->id} ({$import->original_filename})…");
            $sync->poll($import);
        }

        return self::SUCCESS;
    }
}
