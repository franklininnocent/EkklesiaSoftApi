<?php

use Illuminate\Support\Facades\Route;
use Modules\Family\app\Http\Controllers\FamilyController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('families', FamilyController::class)->names('family');
});
