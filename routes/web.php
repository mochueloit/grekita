<?php

use App\Http\Controllers\InventoryImportController;
use App\Http\Controllers\ProductCatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('products.index'));

Route::get('/products', [ProductCatalogController::class, 'index'])
    ->name('products.index');

Route::get('/products/{product}', [ProductCatalogController::class, 'show'])
    ->name('products.show');

Route::get('/inventory/import', [InventoryImportController::class, 'show'])
    ->name('inventory.import.show');

Route::post('/inventory/import', [InventoryImportController::class, 'store'])
    ->name('inventory.import.store');

Route::get('/inventory/import/{import}/status', [InventoryImportController::class, 'status'])
    ->name('inventory.import.status');

Route::get('/inventory/import/{import}/skipped', [InventoryImportController::class, 'skipped'])
    ->name('inventory.import.skipped');

Route::get('/inventory/import/{import}/skipped/download', [InventoryImportController::class, 'downloadSkipped'])
    ->name('inventory.import.skipped.download');

Route::get('/inventory/import/{import}/images/log', [InventoryImportController::class, 'imageLog'])
    ->name('inventory.import.images.log');

Route::get('/inventory/import/{import}/images/log/download', [InventoryImportController::class, 'downloadImageLog'])
    ->name('inventory.import.images.log.download');
