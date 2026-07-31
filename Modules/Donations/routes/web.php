<?php

use Illuminate\Support\Facades\Route;
use Modules\Donations\Http\Controllers\DonationsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('donations', DonationsController::class)->names('donations');
});
