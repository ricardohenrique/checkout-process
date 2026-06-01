<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\PaymentCallbackController;
use Illuminate\Support\Facades\Route;

Route::post('/checkout', [CheckoutController::class, 'store']);
Route::get('/orders/{id}', [CheckoutController::class, 'show']);

// Webhook — no auth middleware (provider can't authenticate as a user)
Route::post('/payments/callback', [PaymentCallbackController::class, 'handle']);
