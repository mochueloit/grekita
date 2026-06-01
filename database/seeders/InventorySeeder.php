<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@grekita.test'],
            [
                'name' => 'Administrador Grekita',
                'password' => Hash::make('password'),
            ],
        );

        Location::query()->firstOrCreate(
            ['slug' => 'lecheria'],
            ['name' => 'Sede Lechería'],
        );

        Location::query()->firstOrCreate(
            ['slug' => 'puerto-ordaz'],
            ['name' => 'Sede Puerto Ordaz'],
        );

        Location::query()->firstOrCreate(
            ['slug' => 'caracas'],
            ['name' => 'Sede Caracas'],
        );

        Location::query()
            ->where('slug', 'tiendagrekaccs')
            ->update(['name' => 'Sede Caracas', 'slug' => 'caracas']);
    }
}
