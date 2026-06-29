<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InventoryImportController;
use App\Http\Controllers\ProductCatalogController;
use App\Http\Controllers\VariationImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/', fn () => redirect()->route('products.index'));

    Route::get('/products', [ProductCatalogController::class, 'index'])
        ->name('products.index');

    Route::get('/products/{product}', [ProductCatalogController::class, 'show'])
        ->name('products.show');

    Route::patch('/products/{product}/price', [ProductCatalogController::class, 'updatePrice'])
        ->name('products.price.update');

    Route::get('/inventory/import', [InventoryImportController::class, 'show'])
        ->name('inventory.import.show');

    Route::get('/inventory/import/stock-price', [InventoryImportController::class, 'showStockPrice'])
        ->name('inventory.import.stock-price.show');

    Route::get('/inventory/import/exclusive', [InventoryImportController::class, 'showExclusiveStore'])
        ->name('inventory.import.exclusive.show');

    Route::post('/inventory/import', [InventoryImportController::class, 'store'])
        ->name('inventory.import.store');

    Route::post('/inventory/import/stock-price', [InventoryImportController::class, 'storeStockPrice'])
        ->name('inventory.import.stock-price.store');

    Route::post('/inventory/import/exclusive', [InventoryImportController::class, 'storeExclusiveStore'])
        ->name('inventory.import.exclusive.store');

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

    Route::get('/inventory/import/{import}/wp/log', [InventoryImportController::class, 'wpSyncLog'])
        ->name('inventory.import.wp.log');

    Route::get('/inventory/import/{import}/wp/log/download', [InventoryImportController::class, 'downloadWpSyncLog'])
        ->name('inventory.import.wp.log.download');

    Route::get('/variations/import', [VariationImportController::class, 'show'])
        ->name('variations.import.show');

    Route::post('/variations/import', [VariationImportController::class, 'store'])
        ->name('variations.import.store');

    Route::get('/variations/import/{import}/status', [VariationImportController::class, 'status'])
        ->name('variations.import.status');

    Route::get('/variations/import/{import}/report', [VariationImportController::class, 'downloadReport'])
        ->name('variations.import.report');

    Route::get('/variations/import/{import}/log', [VariationImportController::class, 'downloadLog'])
        ->name('variations.import.log');

    Route::post('/variations/sync', [VariationImportController::class, 'sync'])
        ->name('variations.sync');
});
