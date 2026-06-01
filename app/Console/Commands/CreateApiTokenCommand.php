<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateApiTokenCommand extends Command
{
    protected $signature = 'inventory:create-token {email} {--name=grekita-api}';

    protected $description = 'Genera un token de API Sanctum para el usuario indicado';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('Usuario no encontrado.');

            return self::FAILURE;
        }

        $token = $user->createToken($this->option('name'));

        $this->info('Token creado correctamente. Guárdalo ahora; no se mostrará de nuevo:');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
