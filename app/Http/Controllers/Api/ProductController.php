<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $products = Product::query()
            ->with(['locations', 'images', 'attributeDefinitions', 'categories'])
            ->orderBy('sku')
            ->paginate(20);

        return ProductResource::collection($products);
    }
}
