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
];
