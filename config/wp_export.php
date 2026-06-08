<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disco de almacenamiento (no público)
    |--------------------------------------------------------------------------
    |
    | Por defecto usa el disco "local" → storage/app/private/
    | Los XML quedan fuera de public/ para consumirlos vía FTP/SFTP del servidor.
    |
    */

    'disk' => env('WP_EXPORT_DISK', 'local'),

    'base_path' => 'exports/wp-xml',

    'filename' => 'products.xml',

    'latest_relative_path' => 'exports/wp-xml/latest/products.xml',

    /** XML liviano solo stock/precio (modo rápido Grekita → WP All Import #20) */
    'stock_price_filename' => 'stock-price-update.xml',

    'stock_price_latest_relative_path' => 'exports/wp-xml/latest/stock-price-update.xml',

];
