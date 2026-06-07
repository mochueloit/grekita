<?php

return [

    'enabled' => (bool) env('WP_ALL_IMPORT_ENABLED', true),

    'base_url' => env('WP_ALL_IMPORT_BASE_URL', 'https://tiendasgreka.com/wp-load.php'),

    'import_key' => env('WP_ALL_IMPORT_KEY', 'c0vMPkmRL'),

    'import_id' => env('WP_ALL_IMPORT_ID', '19'),

    /** Segundos entre cada llamada a action=processing mientras API status=200 */
    'processing_interval_seconds' => (int) env('WP_ALL_IMPORT_PROCESSING_INTERVAL', 180),

    /** Espera antes del primer processing tras el trigger */
    'processing_initial_delay_seconds' => (int) env('WP_ALL_IMPORT_PROCESSING_INITIAL_DELAY', 30),

    /** Revisión de imágenes / cola antes de XML + WordPress */
    'images_poll_interval_seconds' => (int) env('WP_ALL_IMPORT_IMAGES_POLL_INTERVAL', 45),

    /** Máximo de espera por imágenes antes de continuar igual (0 = sin límite) */
    'images_max_wait_seconds' => (int) env('WP_ALL_IMPORT_IMAGES_MAX_WAIT', 0),

    /** Tope de llamadas a processing por importación */
    'max_processing_attempts' => (int) env('WP_ALL_IMPORT_MAX_PROCESSING_ATTEMPTS', 120),

    'http_timeout_seconds' => (int) env('WP_ALL_IMPORT_HTTP_TIMEOUT', 120),

];
