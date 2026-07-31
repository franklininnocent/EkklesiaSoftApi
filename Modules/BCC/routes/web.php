<?php

use Illuminate\Support\Facades\Route;
use Modules\BCC\Http\Controllers\BCCController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('bccs', BCCController::class)->names('bcc');
});
