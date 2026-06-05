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

];
