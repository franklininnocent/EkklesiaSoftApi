<?php

use Illuminate\Support\Facades\Route;
use Modules\RolesAndPermissions\Http\Controllers\RolesAndPermissionsController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('rolesandpermissions', RolesAndPermissionsController::class)->names('rolesandpermissions');
});
