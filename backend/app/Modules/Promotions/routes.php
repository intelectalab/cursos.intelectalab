<?php

use App\Modules\Promotions\Http\Controllers\CheckoutController;
use App\Modules\Promotions\Http\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['tenant', 'auth:sanctum', 'tenant.user'])->group(function () {
    // Maestro §7.2: el comprador prueba su cupón antes de confirmar. Limitado para que no sirva para adivinar códigos.
    Route::post('/checkout/validate-coupon', [CheckoutController::class, 'validateCoupon'])
        ->middleware(['permission:checkout.validate', 'throttle:20,1']);

    Route::middleware('permission:promotions.read')->group(function () {
        Route::get('/promotions', [PromotionController::class, 'index']);
        Route::get('/promotions/options', [PromotionController::class, 'options']);
        Route::get('/promotions/{promotion}', [PromotionController::class, 'show'])->whereUuid('promotion');
        Route::get('/promotions/{promotion}/redemptions', [PromotionController::class, 'redemptions'])->whereUuid('promotion');
    });

    Route::middleware('permission:promotions.manage')->group(function () {
        Route::post('/promotions', [PromotionController::class, 'store']);
        Route::put('/promotions/{promotion}', [PromotionController::class, 'update'])->whereUuid('promotion');
        Route::post('/promotions/{promotion}/coupons', [PromotionController::class, 'storeCoupon'])->whereUuid('promotion');
        Route::put('/coupons/{coupon}', [PromotionController::class, 'updateCoupon'])->whereUuid('coupon');
    });
});
