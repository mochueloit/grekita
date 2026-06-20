<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int) ($argv[1] ?? 5);

App\Jobs\PostInventorySyncJob::dispatch($id);
echo "PostInventorySyncJob encolado para import #{$id}\n";
