<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Grekita — Scheduler (producción / cPanel)
|--------------------------------------------------------------------------
|
| UN SOLO cron en el servidor, cada minuto (usa ruta completa a php):
|
|   * * * * * cd /home/potetiendasgreka/grekita && /usr/local/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
|
| NO uses queue:listen en cron. NO dupliques con un segundo cron de colas.
| NO uses runInBackground() — en cPanel/hosting compartido no arranca procesos hijo.
|
| Alternativa sin schedule:run (también válida, un solo cron):
|
|   * * * * * cd /home/potetiendasgreka/grekita && /usr/local/bin/php artisan queue:work --queue=default,images --stop-when-empty --max-time=55 --sleep=1 --tries=3 --timeout=120 >> storage/logs/queue-cron.log 2>&1
|
| Ver: php artisan inventory:queue-info
|
*/

$inventoryQueues = implode(',', config('inventory.worker_queues', ['default', 'images']));

Schedule::command(sprintf(
    'queue:work --queue=%s --stop-when-empty --max-time=%d --sleep=%d --tries=%d --timeout=%d',
    $inventoryQueues,
    config('inventory.worker_max_time', 55),
    config('inventory.worker_sleep', 1),
    config('inventory.worker_tries', 3),
    config('inventory.worker_timeout', 120),
))
    ->name('grekita-queue-worker')
    ->everyMinute()
    ->withoutOverlapping(3)
    ->appendOutputTo(storage_path('logs/queue-worker.log'));

Schedule::command('queue:prune-failed --hours=168')
    ->name('grekita-prune-failed-jobs')
    ->weekly()
    ->mondays()
    ->at('03:00');

$wpPollMinutes = max(5, min(60, (int) config('wp_all_import.status_poll_interval_minutes', 30)));

Schedule::command('inventory:wp-sync-poll')
    ->name('grekita-wp-sync-poll')
    ->cron(sprintf('*/%d * * * *', $wpPollMinutes))
    ->withoutOverlapping(15)
    ->appendOutputTo(storage_path('logs/wp-sync-poll.log'));
