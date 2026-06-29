<?php

return [

    'api_url' => env('WC_API_URL', 'https://tiendasgreka.com/wp-json/wc/v3'),

    'consumer_key' => env('WC_CONSUMER_KEY', ''),

    'consumer_secret' => env('WC_CONSUMER_SECRET', ''),

    /** Timeout HTTP para llamadas a la API de WooCommerce */
    'http_timeout_seconds' => (int) env('WC_HTTP_TIMEOUT', 30),

    /** Máximo de reintentos en caso de error 429 o 5xx */
    'max_retries' => (int) env('WC_MAX_RETRIES', 3),

    /** Segundos de espera entre reintentos */
    'retry_delay_seconds' => (int) env('WC_RETRY_DELAY', 2),

    /** Productos por lote en el job de conversión */
    'batch_size' => (int) env('WC_BATCH_SIZE', 10),

    /** Sedes registradas: slug del Excel → meta_key de WordPress */
    'sedes' => [
        '482845934' => 'Lechería',
        '7196119'   => 'Caracas',
        '82385465'  => 'Puerto Ordaz',
    ],

];
