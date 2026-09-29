<?php

use Illuminate\Support\Facades\Route;

// Global CORS preflight handler for all API routes.
Route::options('/{any}', function () {
    return response()->noContent();
})->where('any', '.*');
