<?php

use Illuminate\Support\Facades\Route;
use Modules\Sacraments\Http\Controllers\SacramentController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your Sacraments module
|
*/

Route::middleware(['auth:api'])->prefix('sacraments')->name('sacraments.')->group(function () {
    // Sacrament Types (reference data)
    Route::get('/types', [SacramentController::class, 'getSacramentTypes'])->name('types');
    
    // Sacrament Records CRUD
    Route::get('/', [SacramentController::class, 'index'])->name('index');
    Route::post('/', [SacramentController::class, 'store'])->name('store');
    Route::get('/{id}', [SacramentController::class, 'show'])->name('show');
    Route::put('/{id}', [SacramentController::class, 'update'])->name('update');
    Route::delete('/{id}', [SacramentController::class, 'destroy'])->name('destroy');
});
