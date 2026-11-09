<?php

use Illuminate\Support\Facades\Route;
use Trianity\Vpos\Controllers\PaymentController;

Route::middleware(config('vpos.routes.middleware', ['web']))->group(function (): void {
    Route::controller(PaymentController::class)->group(function (): void {
        Route::post('/vpos/payment/init', 'paymentInit')->name('vpos.paymentinit');
    });

    Route::get('/vpos/payment/error', function () {
        return view('vpos::error');
    })->name('vpos.error');

    Route::get('/vpos/api/error', function () {
        return view('vpos::apierror');
    })->name('vpos.apierror');
});
