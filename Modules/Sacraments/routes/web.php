<?php

use Illuminate\Support\Facades\Route;
use Modules\Sacraments\Http\Controllers\SacramentsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('sacraments', SacramentsController::class)->names('sacraments');
});
