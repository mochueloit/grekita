<?php

return [
    'rows_per_job' => (int) env('INVENTORY_ROWS_PER_JOB', 25),

    'auto_start_queue_worker' => (bool) env('INVENTORY_AUTO_START_QUEUE_WORKER', true),

    'image_queue' => env('INVENTORY_IMAGE_QUEUE', 'images'),

    /** Segundos entre cada imagen encolada (evita rate limit). */
    'image_download_delay_seconds' => (int) env('INVENTORY_IMAGE_DOWNLOAD_DELAY', 3),

    /** Pausa extra tras cada descarga completada en el worker. */
    'image_download_pause_seconds' => (int) env('INVENTORY_IMAGE_DOWNLOAD_PAUSE', 1),

    'image_download_timeout' => (int) env('INVENTORY_IMAGE_DOWNLOAD_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Worker del scheduler (producción)
    |--------------------------------------------------------------------------
    | Colas: default (importación) + images (descarga lenta de imágenes).
    | El cron debe ejecutar: php artisan schedule:run
    */
    'worker_queues' => array_values(array_filter(array_map(
        trim(...),
        explode(',', env('INVENTORY_WORKER_QUEUES', 'default,images'))
    ))),

    'worker_max_time' => (int) env('INVENTORY_WORKER_MAX_TIME', 55),

    'worker_sleep' => (int) env('INVENTORY_WORKER_SLEEP', 1),

    'worker_tries' => (int) env('INVENTORY_WORKER_TRIES', 3),

    /** Timeout del proceso queue:work (segundos). Debe ser >= timeout del job más largo. */
    'worker_timeout' => (int) env('INVENTORY_WORKER_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Avisos por correo
    |--------------------------------------------------------------------------
    | INVENTORY_NOTIFY_EMAILS: lista separada por comas
    */
    'notify_enabled' => (bool) env('INVENTORY_NOTIFY_ENABLED', false),

    'notify_emails' => env('INVENTORY_NOTIFY_EMAILS', ''),

    'notify_on_start' => (bool) env('INVENTORY_NOTIFY_ON_START', true),

    'notify_on_complete' => (bool) env('INVENTORY_NOTIFY_ON_COMPLETE', true),
];
