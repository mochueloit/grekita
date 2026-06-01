<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\Product;
use App\Services\Inventory\ProductDescriptionFormatter;
use Illuminate\Console\Command;

class RefreshProductDescriptionsCommand extends Command
{
    protected $signature = 'inventory:refresh-descriptions';

    protected $description = 'Regenera el HTML de descripciones y normaliza la sede Caracas';

    public function handle(ProductDescriptionFormatter $formatter): int
    {
        Location::query()
            ->where('slug', 'tiendagrekaccs')
            ->update(['name' => 'Sede Caracas', 'slug' => 'caracas']);

        $updated = 0;

        Product::query()
            ->whereNotNull('long_description')
            ->chunkById(100, function ($products) use ($formatter, &$updated): void {
                foreach ($products as $product) {
                    $product->update([
                        'long_description_html' => $formatter->toHtml($product->long_description ?? ''),
                    ]);
                    $updated++;
                }
            });

        $this->info("Descripciones HTML actualizadas: {$updated}");

        return self::SUCCESS;
    }
}
