<?php

return [

    'enabled' => (bool) env('WP_ALL_IMPORT_ENABLED', true),

    'base_url' => env('WP_ALL_IMPORT_BASE_URL', 'https://tiendasgreka.com/wp-load.php'),

    'import_key' => env('WP_ALL_IMPORT_KEY', 'c0vMPkmRL'),

    /** Catálogo completo (products.xml) */
    'import_id' => env('WP_ALL_IMPORT_ID', '19'),

    /** Actualización stock/precio (stock-price-update.xml) */
    'stock_price_import_id' => env('WP_ALL_IMPORT_STOCK_PRICE_ID', ''),

    /** Timeout HTTP para trigger (una vez al generar XML) */
    'http_timeout_seconds' => (int) env('WP_ALL_IMPORT_HTTP_TIMEOUT', 180),

    /** Timeout HTTP para poll de estado (más corto; el trabajo lo hace el cron WP) */
    'status_poll_http_timeout_seconds' => (int) env('WP_ALL_IMPORT_STATUS_POLL_HTTP_TIMEOUT', 60),

    /**
     * Grekita consulta processing cada N minutos para saber si WP terminó.
     * El cron del servidor WordPress ejecuta processing cada server_cron_interval_minutes.
     */
    'status_poll_interval_minutes' => (int) env('WP_ALL_IMPORT_STATUS_POLL_MINUTES', 30),

    /** Intervalo recomendado del cron wget en tiendasgreka.com (solo documentación / inventory:wp-server-cron-info) */
    'server_cron_interval_minutes' => (int) env('WP_ALL_IMPORT_SERVER_CRON_MINUTES', 5),

    /** Tras trigger, si no hay confirmación de fin en N horas → aviso y cierre con WARN */
    'max_external_wait_hours' => (int) env('WP_ALL_IMPORT_MAX_EXTERNAL_WAIT_HOURS', 4),

    /** Revisión de imágenes / cola antes de XML + WordPress (solo importación completa) */
    'images_poll_interval_seconds' => (int) env('WP_ALL_IMPORT_IMAGES_POLL_INTERVAL', 45),

    /** Máximo de espera por imágenes antes de continuar igual (0 = sin límite) */
    'images_max_wait_seconds' => (int) env('WP_ALL_IMPORT_IMAGES_MAX_WAIT', 0),

];
