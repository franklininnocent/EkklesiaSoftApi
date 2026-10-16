<?php

use Illuminate\Support\Facades\Route;
use Modules\EcclesiasticalData\Http\Controllers\EcclesiasticalDataController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('ecclesiasticaldatas', EcclesiasticalDataController::class)->names('ecclesiasticaldata');
});
