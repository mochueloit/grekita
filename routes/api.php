<?php

use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductXmlExportController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products/export-xml', [ProductXmlExportController::class, 'store']);
    Route::get('/products/export-xml/latest', [ProductXmlExportController::class, 'latest']);
});
