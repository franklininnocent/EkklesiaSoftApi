<?php

use Illuminate\Support\Facades\Route;
use Modules\Family\Http\Controllers\FamilyController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('families', FamilyController::class)->names('family');
});
