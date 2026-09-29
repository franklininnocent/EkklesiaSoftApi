<?php

use Illuminate\Support\Facades\Route;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Services\Certificates\SacramentCertificateService;

Route::get('/verify/certificate/{token}', function (string $token) {
    try {
        $data = app(SacramentCertificateService::class)->publicVerify($token);
    } catch (SacramentBusinessRuleException $e) {
        abort($e->httpStatus(), $e->getMessage());
    }

    return response()->view('sacraments::certificates.verify', ['data' => $data]);
})->where('token', '[A-Za-z0-9]+')->name('sacrament-certificates.verify');
