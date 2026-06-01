<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryCsvImporter;
use Illuminate\Console\Command;

class ImportInventoryCommand extends Command
{
    protected $signature = 'inventory:import {path=inventario.csv : Ruta al CSV o Excel de inventario}';

    protected $description = 'Importa inventario (CSV/XLS/XLSX): productos, stock, atributos e imágenes';

    public function handle(InventoryCsvImporter $importer): int
    {
        $path = $this->argument('path');

        if (! is_file($path)) {
            $this->error("Archivo no encontrado: {$path}");

            return self::FAILURE;
        }

        $this->info('Importando inventario (descarga de imágenes incluida, puede tardar varios minutos)...');

        try {
            $stats = $importer->import($path);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Métrica', 'Valor'],
            collect($stats)->map(fn ($value, $key) => [$key, $value])->values()->all(),
        );

        $this->info('Importación completada.');

        return self::SUCCESS;
    }
}
