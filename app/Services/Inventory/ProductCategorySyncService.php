<?php

namespace App\Services\Inventory;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Str;

class ProductCategorySyncService
{
    public function __construct(
        private readonly ProductCategoryParser $parser,
    ) {}

    /**
     * @param  list<string>|string|null  $pathsOrRaw  Rutas normalizadas o texto del CSV (Categoría)
     */
    public function sync(Product $product, array|string|null $pathsOrRaw): int
    {
        $paths = is_string($pathsOrRaw)
            ? $this->parser->parse($pathsOrRaw)
            : ($pathsOrRaw ?? []);

        if ($paths === []) {
            $product->categories()->detach();

            return 0;
        }

        $syncData = [];

        foreach ($paths as $order => $path) {
            $segments = $this->parser->segments($path);

            if ($segments === []) {
                continue;
            }

            $leaf = $this->resolvePath($segments);

            if ($leaf !== null) {
                $syncData[$leaf->id] = ['sort_order' => $order];
            }
        }

        $product->categories()->sync($syncData);

        return count($syncData);
    }

    /**
     * @param  list<string>  $segments
     */
    private function resolvePath(array $segments): ?Category
    {
        $parentId = null;
        $category = null;
        $pathParts = [];

        foreach ($segments as $depth => $name) {
            $pathParts[] = $name;
            $fullPath = implode(' > ', $pathParts);
            $slug = Str::slug($name) ?: 'categoria';

            $category = Category::query()->firstOrCreate(
                ['parent_id' => $parentId, 'name' => $name],
                [
                    'slug' => $slug,
                    'full_path' => $fullPath,
                    'depth' => $depth,
                    'is_leaf' => false,
                ],
            );

            if ($category->full_path !== $fullPath || $category->depth !== $depth) {
                $category->update([
                    'full_path' => $fullPath,
                    'depth' => $depth,
                    'is_leaf' => false,
                ]);
            }

            $parentId = $category->id;
        }

        if ($category !== null) {
            $category->update(['is_leaf' => true]);
        }

        return $category;
    }
}
