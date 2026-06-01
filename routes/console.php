<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('queue:work --queue=default,'.config('inventory.image_queue', 'images').' --stop-when-empty --max-time=55 --sleep=1 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
