<?php

namespace App\Services\Inventory;

use Symfony\Component\Process\Process;

class QueueWorkerStarter
{
    public function ensureRunning(): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        if (! config('inventory.auto_start_queue_worker', true)) {
            return;
        }

        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $command = sprintf(
            '"%s" "%s" queue:work --queue=default,%s --max-time=7200 --sleep=1 --tries=3',
            $php,
            $artisan,
            config('inventory.image_queue', 'images'),
        );

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B "" '.$command, 'r'));

            return;
        }

        $process = Process::fromShellCommandline($command, base_path());
        $process->disableOutput();
        $process->start();
    }
}
